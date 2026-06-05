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
 */
function dd_render_post_search_select($name, $current_id, $type, $description)
{
    $current_title = '';
    if ($current_id > 0) {
        $post = get_post($current_id);
        if ($post) {
            $current_title = $post->post_title;
        }
    }
    ?>
    <div class="dd-ajax-select" data-type="<?php echo esc_attr($type); ?>">
        <div class="dd-ajax-input-wrap">
            <span class="dd-search-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input
                type="text"
                class="dd-ajax-search"
                value="<?php echo esc_attr($current_title); ?>"
                placeholder="Search…"
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

    $results = array_map(fn($p) => ['id' => $p->ID, 'text' => $p->post_title], $posts);

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

    // Page Assignments section
    add_settings_section('dd_page_ids_section', '', '__return_false', 'dd-theme-settings');

    $page_fields = [
        'dd_search_results_page_id' => ['Search Results Page',   1949, 'Where influencer search results are displayed.'],
        'dd_search_page_id'         => ['Search Form Page',      2149, 'Page that contains the influencer search form.'],
        'dd_dashboard_page_id'      => ['Dashboard Page',        1565, 'User dashboard — also the post-login redirect target.'],
        'dd_login_redirect_page_id' => ['Login / Redirect Page', 4144, 'Non-logged-in users are redirected here.'],
    ];
    foreach ($page_fields as $key => [$label, $default, $description]) {
        add_settings_field($key, $label, function () use ($key, $default, $description) {
            dd_render_post_search_select($key, dd_get_page_id($key, $default), 'page', $description);
        }, 'dd-theme-settings', 'dd_page_ids_section');
    }

    // Elementor Templates section
    add_settings_section('dd_template_ids_section', '', '__return_false', 'dd-theme-settings-templates');

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
        add_settings_field($key, $label, function () use ($key, $default, $desc) {
            dd_render_post_search_select($key, dd_get_template_id($key, $default), 'elementor_template', $desc);
        }, 'dd-theme-settings-templates', 'dd_template_ids_section');
    }
});

// ---------------------------------------------------------------------------
// Admin menu page
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

            $tab      = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pages';
            $base_url = admin_url('options-general.php?page=dd-theme-settings');
            ?>
            <div class="wrap dd-settings-wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

                <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:0;">
                    <a href="<?php echo esc_url($base_url . '&tab=pages'); ?>"
                       class="nav-tab <?php echo $tab === 'pages' ? 'nav-tab-active' : ''; ?>">
                        Page Assignments
                    </a>
                    <a href="<?php echo esc_url($base_url . '&tab=templates'); ?>"
                       class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>">
                        Elementor Templates
                    </a>
                </nav>

                <div class="dd-tab-panel">
                    <form method="post" action="options.php">
                        <?php settings_fields('dd_theme_page_ids'); ?>

                        <?php if ($tab === 'pages'): ?>
                            <p class="dd-tab-desc">Select which WordPress pages serve each platform role. Changes take effect immediately after saving.</p>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('dd-theme-settings', 'dd_page_ids_section'); ?>
                            </table>
                        <?php else: ?>
                            <p class="dd-tab-desc">Elementor templates used by the theme. Find these under <strong>Elementor → My Templates</strong>.</p>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('dd-theme-settings-templates', 'dd_template_ids_section'); ?>
                            </table>
                        <?php endif; ?>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>
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
        /* Tab panel */
        .dd-settings-wrap .nav-tab-wrapper { border-bottom: 1px solid #c3c4c7; }
        .dd-tab-panel {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-top: none;
            border-radius: 0 0 3px 3px;
            padding: 24px 28px 8px;
        }
        .dd-tab-desc { color: #50575e; margin: 0 0 20px; }

        /* Autocomplete field */
        .dd-ajax-select { max-width: 340px; position: relative; }
        .dd-ajax-input-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            background: #fff;
            padding: 0 10px 0 0;
            transition: border-color .15s, box-shadow .15s;
        }
        .dd-ajax-input-wrap:focus-within {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }
        .dd-search-icon {
            display: flex; align-items: center;
            padding: 0 8px; color: #8c8f94; flex-shrink: 0;
            pointer-events: none;
        }
        .dd-ajax-search {
            flex: 1; border: none !important; box-shadow: none !important;
            padding: 7px 4px !important; background: transparent !important;
            font-size: 13px !important; min-width: 0;
            outline: none;
        }
        .dd-ajax-clear {
            background: none; border: none; cursor: pointer;
            font-size: 16px; line-height: 1; color: #a7aaad;
            padding: 0; flex-shrink: 0; display: flex; align-items: center;
        }
        .dd-ajax-clear:hover { color: #d63638; }

        /* Dropdown */
        .dd-ajax-results {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            max-height: 240px; overflow-y: auto;
            z-index: 9999; margin: 0; padding: 4px 0; list-style: none;
            box-shadow: 0 4px 12px rgba(0,0,0,.10);
        }
        .dd-ajax-results li {
            padding: 8px 12px; font-size: 13px; cursor: pointer;
            border-radius: 2px; margin: 0 4px; color: #1d2327;
        }
        .dd-ajax-results li:hover { background: #f0f6fc; color: #2271b1; }
        .dd-ajax-results li.dd-active { background: #2271b1; color: #fff; }
        .dd-ajax-results li.dd-no-results,
        .dd-ajax-results li.dd-loading {
            color: #8c8f94; cursor: default; font-style: italic;
        }
        .dd-ajax-results li.dd-loading { display: flex; align-items: center; gap: 6px; }
        .dd-spinner {
            display: inline-block; width: 12px; height: 12px;
            border: 2px solid #c3c4c7; border-top-color: #2271b1;
            border-radius: 50%;
            animation: dd-spin .6s linear infinite;
        }
        @keyframes dd-spin { to { transform: rotate(360deg); } }
    </style>
    <script>
        jQuery(function ($) {
            var nonce = '<?php echo esc_js($nonce); ?>';
            var timers = {};

            function doSearch($wrap, q) {
                var type = $wrap.data('type');
                var $results = $wrap.find('.dd-ajax-results');
                $results.html('<li class="dd-loading"><span class="dd-spinner"></span> Searching…</li>').prop('hidden', false);

                $.get(ajaxurl, { action: 'dd_admin_post_search', nonce: nonce, type: type, q: q })
                    .done(function (resp) {
                        $results.empty();
                        if (!resp.success || !resp.data.length) {
                            $results.append('<li class="dd-no-results">No results found</li>');
                        } else {
                            resp.data.forEach(function (item) {
                                $results.append($('<li>').attr('data-id', item.id).text(item.text));
                            });
                        }
                    });
            }

            $(document).on('focus input', '.dd-ajax-search', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                var key = $wrap.find('input[type="hidden"]').attr('name');
                clearTimeout(timers[key]);
                var q = $(this).val();
                timers[key] = setTimeout(function () { doSearch($wrap, q); }, 250);
            });

            $(document).on('mousedown', '.dd-ajax-results li[data-id]', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                $wrap.find('.dd-ajax-search').val($(this).text());
                $wrap.find('input[type="hidden"]').val($(this).data('id'));
                $wrap.find('.dd-ajax-results').prop('hidden', true).empty();
                $wrap.find('.dd-ajax-clear').prop('hidden', false);
            });

            $(document).on('blur', '.dd-ajax-search', function () {
                var $wrap = $(this).closest('.dd-ajax-select');
                setTimeout(function () {
                    $wrap.find('.dd-ajax-results').prop('hidden', true).empty();
                }, 200);
            });

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
