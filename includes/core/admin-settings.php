<?php
if (! defined('ABSPATH')) {
    exit;
}

function dd_get_page_id($key, $fallback = 0)
{
    return (int) get_option($key, $fallback);
}

function dd_get_template_id($key, $fallback = 0)
{
    return (int) get_option($key, $fallback);
}

/**
 * Renders an AJAX-powered search field.
 * The hidden input carries the real post ID; the text input is display-only.
 *
 * @param string $name        Option/input name.
 * @param int    $current_id  Currently saved post ID.
 * @param string $type        'page' or 'elementor_template'.
 * @param string $description Help text below the field.
 */
function dd_render_post_search_select($name, $current_id, $type, $description)
{
    $current_title = '';
    if ($current_id > 0) {
        $post = get_post($current_id);
        if ($post) {
            $current_title = $post->post_title;
            if ($type === 'elementor_template') {
                $current_title .= ' (#' . $current_id . ')';
            }
        }
    }
    ?>
    <div class="dd-ajax-select" data-type="<?php echo esc_attr($type); ?>">
        <div class="dd-ajax-input-wrap">
            <input
                type="text"
                class="dd-ajax-search regular-text"
                value="<?php echo esc_attr($current_title); ?>"
                placeholder="Search..."
                autocomplete="off"
            >
            <button type="button" class="dd-ajax-clear" <?php echo $current_id > 0 ? '' : 'hidden'; ?> title="Clear">&times;</button>
        </div>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current_id ?: 0); ?>">
        <ul class="dd-ajax-results" hidden></ul>
        <?php if ($description): ?>
            <p class="description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// AJAX search handler (admin-only)
// ---------------------------------------------------------------------------
add_action('wp_ajax_dd_admin_post_search', function () {
    check_ajax_referer('dd_admin_search', 'nonce');

    $type = sanitize_text_field($_GET['type'] ?? 'page');
    $q    = sanitize_text_field($_GET['q']    ?? '');

    $post_type = $type === 'elementor_template' ? 'elementor_library' : 'page';

    $posts = get_posts([
        's'              => $q,
        'post_type'      => $post_type,
        'posts_per_page' => 15,
        'post_status'    => 'publish',
        'orderby'        => $q ? 'relevance' : 'title',
        'order'          => 'ASC',
    ]);

    $results = array_map(function ($p) use ($type) {
        $text = $p->post_title;
        if ($type === 'elementor_template') {
            $text .= ' (#' . $p->ID . ')';
        }
        return ['id' => $p->ID, 'text' => $text];
    }, $posts);

    wp_send_json_success($results);
});

// ---------------------------------------------------------------------------
// Register the settings group and individual options
// ---------------------------------------------------------------------------
add_action('admin_init', function () {
    $page_keys = [
        'dd_search_results_page_id' => 1949,
        'dd_search_page_id'         => 2149,
        'dd_dashboard_page_id'      => 1565,
        'dd_login_redirect_page_id' => 4144,
    ];

    foreach ($page_keys as $key => $default) {
        register_setting('dd_theme_page_ids', $key, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => $default,
        ]);
    }

    $template_keys = [
        'dd_tpl_header_nav'           => 1571,
        'dd_tpl_dashboard_content'    => 1640,
        'dd_tpl_dashboard_no_access'  => 14403,
        'dd_tpl_single_influencer'    => 1868,
        'dd_tpl_search_card'          => 1839,
        'dd_tpl_saves_empty'          => 27501,
        'dd_tpl_group_influencer_row' => 14897,
        'dd_tpl_no_data_fallback'     => 27230,
    ];
    foreach ($template_keys as $key => $default) {
        register_setting('dd_theme_page_ids', $key, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => $default,
        ]);
    }

    // ------------------------------------------------------------------
    // Page Assignments section
    // ------------------------------------------------------------------
    add_settings_section(
        'dd_page_ids_section',
        'Page Assignments',
        function () {
            echo '<p>Select which WordPress pages serve each platform role. Changes take effect immediately after saving.</p>';
        },
        'dd-theme-settings'
    );

    $page_fields = [
        'dd_search_results_page_id' => ['Search Results Page',   1949, 'Where influencer search results are displayed.'],
        'dd_search_page_id'         => ['Search Form Page',      2149, 'Page that contains the influencer search form.'],
        'dd_dashboard_page_id'      => ['Dashboard Page',        1565, 'User dashboard — also the post-login redirect target.'],
        'dd_login_redirect_page_id' => ['Login / Redirect Page', 4144, 'Non-logged-in users are redirected here.'],
    ];

    foreach ($page_fields as $key => [$label, $default, $description]) {
        add_settings_field(
            $key,
            $label,
            function () use ($key, $default, $description) {
                dd_render_post_search_select($key, dd_get_page_id($key, $default), 'page', $description);
            },
            'dd-theme-settings',
            'dd_page_ids_section'
        );
    }

    // ------------------------------------------------------------------
    // Elementor Template IDs section
    // ------------------------------------------------------------------
    add_settings_section(
        'dd_template_ids_section',
        'Elementor Template IDs',
        function () {
            echo '<p>Elementor template IDs used by the theme. Find these under <strong>Elementor → My Templates</strong>.</p>';
        },
        'dd-theme-settings'
    );

    $template_fields = [
        'dd_tpl_header_nav'           => ['Header Navigation',             1571,  'Sidebar/header nav rendered on the dashboard and influencer profile pages.'],
        'dd_tpl_dashboard_content'    => ['Dashboard Content (Members)',   1640,  'Main dashboard content for logged-in members.'],
        'dd_tpl_dashboard_no_access'  => ['Dashboard Content (No Access)', 14403, 'Dashboard content shown to users without an active membership.'],
        'dd_tpl_single_influencer'    => ['Single Influencer Content',     1868,  'Content area on individual influencer profile pages.'],
        'dd_tpl_search_card'          => ['Search Result Card',            1839,  'Card template rendered for each result in the influencer search loop.'],
        'dd_tpl_saves_empty'          => ['Saved Groups Empty State',      27501, 'Shown when a user has no saved groups yet.'],
        'dd_tpl_group_influencer_row' => ['Group Influencer Row',          14897, 'Row template for each influencer inside the group viewer modal.'],
        'dd_tpl_no_data_fallback'     => ['No Data Fallback',              27230, 'Shown when feeds or charts have no data to display.'],
    ];

    foreach ($template_fields as $key => [$label, $default, $desc]) {
        add_settings_field(
            $key,
            $label,
            function () use ($key, $default, $desc) {
                dd_render_post_search_select($key, dd_get_template_id($key, $default), 'elementor_template', $desc);
            },
            'dd-theme-settings',
            'dd_template_ids_section'
        );
    }
});

// ---------------------------------------------------------------------------
// Register the admin menu page under Settings
// ---------------------------------------------------------------------------
add_action('admin_menu', function () {
    add_options_page(
        'Influencer Theme Settings',
        'Influencer Theme',
        'manage_options',
        'dd-theme-settings',
        function () {
            if (! current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('dd_theme_page_ids');
                    do_settings_sections('dd-theme-settings');
                    submit_button('Save Settings');
                    ?>
                </form>
            </div>
            <?php
        }
    );
});

// ---------------------------------------------------------------------------
// Styles + AJAX autocomplete JS (settings page only)
// ---------------------------------------------------------------------------
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (! $screen || $screen->id !== 'settings_page_dd-theme-settings') {
        return;
    }
    $nonce = wp_create_nonce('dd_admin_search');
    ?>
    <style>
        .dd-ajax-select { max-width: 320px; position: relative; margin-bottom: 4px; }
        .dd-ajax-input-wrap { display: flex; align-items: center; gap: 6px; }
        .dd-ajax-search { flex: 1; }
        .dd-ajax-clear {
            background: none; border: none; cursor: pointer;
            font-size: 18px; line-height: 1; color: #999; padding: 0 4px;
        }
        .dd-ajax-clear:hover { color: #d63638; }
        .dd-ajax-results {
            position: absolute; top: calc(100% + 2px); left: 0; right: 0;
            background: #fff; border: 1px solid #8c8f94;
            border-radius: 3px; max-height: 220px; overflow-y: auto;
            z-index: 9999; margin: 0; padding: 0; list-style: none;
            box-shadow: 0 3px 8px rgba(0,0,0,.12);
        }
        .dd-ajax-results li {
            padding: 7px 10px; font-size: 13px; cursor: pointer;
        }
        .dd-ajax-results li:hover,
        .dd-ajax-results li.dd-active { background: #2271b1; color: #fff; }
        .dd-ajax-results li.dd-no-results { color: #666; cursor: default; font-style: italic; }
        .dd-ajax-loading { color: #666; font-style: italic; font-size: 13px; padding: 6px 10px; }
    </style>
    <script>
        jQuery(function ($) {
            var nonce = '<?php echo esc_js($nonce); ?>';
            var timers = {};

            function doSearch($wrap, q) {
                var type = $wrap.data('type');
                var $results = $wrap.find('.dd-ajax-results');
                $results.html('<li class="dd-ajax-loading">Searching…</li>').prop('hidden', false);

                $.get(ajaxurl, { action: 'dd_admin_post_search', nonce: nonce, type: type, q: q })
                    .done(function (resp) {
                        $results.empty();
                        if (!resp.success || !resp.data.length) {
                            $results.append('<li class="dd-no-results">No results found</li>');
                        } else {
                            resp.data.forEach(function (item) {
                                $results.append(
                                    $('<li>').attr('data-id', item.id).text(item.text)
                                );
                            });
                        }
                    });
            }

            // Trigger search on focus and keystroke
            $(document).on('focus input', '.dd-ajax-search', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                var id    = $wrap.data('type');
                var q     = $(this).val();
                clearTimeout(timers[id]);
                timers[id] = setTimeout(function () { doSearch($wrap, q); }, 250);
            });

            // Pick a result
            $(document).on('mousedown', '.dd-ajax-results li[data-id]', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                $wrap.find('.dd-ajax-search').val($(this).text());
                $wrap.find('input[type="hidden"]').val($(this).data('id'));
                $wrap.find('.dd-ajax-results').prop('hidden', true).empty();
                $wrap.find('.dd-ajax-clear').prop('hidden', false);
            });

            // Close on blur (mousedown on results fires before blur, so the pick runs first)
            $(document).on('blur', '.dd-ajax-search', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                setTimeout(function () {
                    $wrap.find('.dd-ajax-results').prop('hidden', true).empty();
                }, 200);
            });

            // Clear selection
            $(document).on('click', '.dd-ajax-clear', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                $wrap.find('.dd-ajax-search').val('').trigger('focus');
                $wrap.find('input[type="hidden"]').val('0');
                $(this).prop('hidden', true);
            });
        });
    </script>
    <?php
});
