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
        <div class="dd-ajax-field">
            <div class="dd-ajax-input-wrap">
                <span class="dd-search-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </span>
                <input
                    type="text"
                    class="dd-ajax-search"
                    value="<?php echo esc_attr($current_title); ?>"
                    placeholder="Search…"
                    autocomplete="off">
                <button type="button" class="dd-ajax-clear" <?php echo $current_id > 0 ? '' : 'hidden'; ?> title="Clear">&times;</button>
            </div>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current_id ?: 0); ?>">
            <ul class="dd-ajax-results" hidden></ul>
        </div>
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

    wp_send_json_success(array_map(fn($p) => ['id' => $p->ID, 'text' => $p->post_title], $posts));
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

    add_settings_section('dd_page_ids_section',     '', '__return_false', 'dd-theme-settings');
    add_settings_section('dd_template_ids_section', '', '__return_false', 'dd-theme-settings-templates');

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
// Admin menu page — both panels rendered; JS handles tab switching
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
        <div class="wrap dd-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="dd-tab-nav">
                <button type="button" class="dd-tab-btn dd-tab-active" data-panel="pages">Page Assignments</button>
                <button type="button" class="dd-tab-btn" data-panel="templates">Elementor Templates</button>
            </div>

            <div class="dd-tab-body">
                <form method="post" action="options.php">
                    <?php settings_fields('dd_theme_page_ids'); ?>

                    <div class="dd-panel" id="dd-panel-pages">
                        <p class="dd-tab-desc">Select which WordPress pages serve each platform role.</p>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields('dd-theme-settings', 'dd_page_ids_section'); ?>
                        </table>
                    </div>

                    <div class="dd-panel" id="dd-panel-templates" hidden>
                        <p class="dd-tab-desc">Elementor templates used by the theme. Find these under <strong>Elementor → My Templates</strong>.</p>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields('dd-theme-settings-templates', 'dd_template_ids_section'); ?>
                        </table>
                    </div>

                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>
        </div>
    <?php
        }
    );
});

// ---------------------------------------------------------------------------
// Admin bar — quick links to open theme pages/templates in the Elementor editor
// ---------------------------------------------------------------------------
function dd_admin_bar_item_title($title, $badge)
{
    return sprintf(
        '<span class="elementor-edit-link-title">%s</span><span class="elementor-edit-link-type">%s</span>',
        esc_html($title),
        esc_html($badge)
    );
}

add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (! current_user_can('manage_options')) {
        return;
    }

    $site_icon  = get_site_icon_url(32);
    $icon_html  = $site_icon ? sprintf('<img src="%s" alt="" class="dd-ab-favicon" />', esc_url($site_icon)) : '';

    $wp_admin_bar->add_node([
        'id'    => 'dd-theme-editor',
        'title' => $icon_html . 'Theme Editor',
        'href'  => admin_url('options-general.php?page=dd-theme-settings'),
    ]);

    $page_keys = [
        'dd_search_results_page_id' => ['Search Results Page',   1949],
        'dd_search_page_id'         => ['Search Form Page',      2149],
        'dd_dashboard_page_id'      => ['Dashboard Page',        1565],
        'dd_login_redirect_page_id' => ['Login / Redirect Page', 4144],
    ];
    foreach ($page_keys as $key => [$role, $default]) {
        $id   = dd_get_page_id($key, $default);
        $post = $id > 0 ? get_post($id) : null;
        if ($post) {
            $wp_admin_bar->add_node([
                'id'     => 'dd-theme-editor-' . $key,
                'parent' => 'dd-theme-editor',
                'title'  => dd_admin_bar_item_title(get_the_title($post), $role),
                'href'   => admin_url('post.php?post=' . $id . '&action=elementor'),
                'meta'   => ['target' => '_blank', 'class' => 'elementor-general-section'],
            ]);
        }
    }

    $template_keys = [
        'dd_tpl_header_nav'           => ['Header Navigation',             1571],
        'dd_tpl_dashboard_content'    => ['Dashboard Content (Members)',   1640],
        'dd_tpl_dashboard_no_access'  => ['Dashboard Content (No Access)', 14403],
        'dd_tpl_single_influencer'    => ['Single Influencer Content',     1868],
        'dd_tpl_search_card'          => ['Search Result Card',            1839],
        'dd_tpl_saves_empty'          => ['Saved Groups Empty State',      27501],
        'dd_tpl_group_influencer_row' => ['Group Influencer Row',          14897],
        'dd_tpl_no_data_fallback'     => ['No Data Fallback',              27230],
    ];

    $wp_admin_bar->add_node([
        'id'     => 'dd-theme-editor-templates',
        'parent' => 'dd-theme-editor',
        'title'  => 'Elementor Templates',
    ]);
    foreach ($template_keys as $key => [$role, $default]) {
        $id   = dd_get_template_id($key, $default);
        $post = $id > 0 ? get_post($id) : null;
        if ($post) {
            $wp_admin_bar->add_node([
                'id'     => 'dd-theme-editor-' . $key,
                'parent' => 'dd-theme-editor-templates',
                'title'  => dd_admin_bar_item_title(get_the_title($post), $role),
                'href'   => admin_url('post.php?post=' . $id . '&action=elementor'),
                'meta'   => ['target' => '_blank', 'class' => 'elementor-general-section'],
            ]);
        }
    }
}, 100);

add_action('wp_before_admin_bar_render', function () {
    if (! current_user_can('manage_options')) {
        return;
    }
    ?>
    <style>
        #wpadminbar #wp-admin-bar-dd-theme-editor>.ab-item {
            display: flex;
            width: 200px;
        }

        #wpadminbar #wp-admin-bar-dd-theme-editor>.ab-item .dd-ab-favicon {
            width: 16px;
            height: 16px;
            margin: 0 6px 0 2px;
            vertical-align: middle;
            border-radius: 2px;
            position: relative;
            top: -1px;
        }

        /* Elementor scopes its edit-link styling to #wp-admin-bar-elementor_edit_page —
           mirror those rules here so our menu matches it visually. */
        #wp-admin-bar-dd-theme-editor .elementor-edit-link-type {
            background: #3f444b;
            border-radius: 3px;
            font-size: 11px;
            line-height: 9px;
            margin-block-start: 6px;
            padding: 4px 8px;
        }

        #wp-admin-bar-dd-theme-editor .elementor-edit-link-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%;
        }
    </style>
<?php
});

// ---------------------------------------------------------------------------
// Styles + JS (settings page only)
// ---------------------------------------------------------------------------
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (! $screen || $screen->id !== 'settings_page_dd-theme-settings') {
        return;
    }
    $nonce = wp_create_nonce('dd_admin_search');
?>
    <style>
        /* ── Tab nav ── */
        .dd-settings-wrap h1 {
            margin-bottom: 12px;
        }

        .dd-tab-nav {
            display: flex;
            gap: 0;
            margin: 0;
        }

        .dd-tab-btn {
            background: #f0f0f1;
            border: 1px solid #c3c4c7;
            border-bottom-color: #c3c4c7;
            border-radius: 3px 3px 0 0;
            margin-right: 4px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 400;
            color: #50575e;
            cursor: pointer;
            position: relative;
            top: 1px;
            z-index: 1;
            transition: background .1s, color .1s;
        }

        .dd-tab-btn:hover:not(.dd-tab-active) {
            background: #fff;
            color: #1d2327;
        }

        .dd-tab-btn.dd-tab-active {
            background: #fff;
            border-bottom-color: #fff;
            color: #1d2327;
            font-weight: 600;
        }

        /* ── Tab body ── */
        .dd-tab-body {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 0 3px 3px 3px;
            padding: 24px 28px 12px;
            position: relative;
            z-index: 0;
        }

        .dd-tab-desc {
            color: #50575e;
            margin: 0 0 18px;
        }

        /* ── Autocomplete input ── */
        .dd-ajax-select {
            max-width: 340px;
        }

        .dd-ajax-field {
            position: relative;
        }

        .dd-ajax-input-wrap {
            display: flex;
            align-items: center;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            background: #fff;
            padding-right: 8px;
            transition: border-color .15s, border-radius .1s;
        }

        .dd-ajax-input-wrap:focus-within {
            border-color: #50575e;
        }

        /* When dropdown is open — flatten bottom corners to connect with list */
        .dd-ajax-select.dd-open .dd-ajax-input-wrap {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom-color: #e0e0e0;
        }

        .dd-search-icon {
            display: flex;
            align-items: center;
            padding: 0 8px;
            color: #a7aaad;
            flex-shrink: 0;
            pointer-events: none;
        }

        .dd-ajax-search {
            flex: 1 1 0;
            min-width: 0;
            border: none !important;
            box-shadow: none !important;
            outline: none !important;
            padding: 7px 4px !important;
            background: transparent !important;
            font-size: 13px !important;
        }

        .dd-ajax-clear {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 17px;
            line-height: 1;
            color: #a7aaad;
            padding: 0;
            flex-shrink: 0;
        }

        .dd-ajax-clear:hover {
            color: #d63638;
        }

        /* ── Dropdown ── */
        .dd-ajax-results {
            position: absolute;
            top: 100%;
            /* flush — no gap */
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #8c8f94;
            border-top: 1px solid #e0e0e0;
            border-radius: 0 0 4px 4px;
            max-height: 220px;
            overflow-y: auto;
            z-index: 9999;
            margin: 0;
            padding: 4px 0;
            list-style: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .08);
        }

        .dd-ajax-results li {
            padding: 8px 12px;
            font-size: 13px;
            cursor: pointer;
            color: #1d2327;
        }

        .dd-ajax-results li:hover {
            background: #f6f7f7;
            color: #1d2327;
        }

        .dd-ajax-results li.dd-no-results,
        .dd-ajax-results li.dd-loading {
            color: #a7aaad;
            cursor: default;
            font-style: italic;
        }

        .dd-ajax-results li.dd-loading {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .dd-spinner {
            width: 11px;
            height: 11px;
            flex-shrink: 0;
            border: 2px solid #ddd;
            border-top-color: #2271b1;
            border-radius: 50%;
            animation: dd-spin .55s linear infinite;
        }

        @keyframes dd-spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <script>
        jQuery(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';
            var timers = {};

            /* ── Tab switching ── */
            var LS_KEY = 'dd_settings_tab';

            function activateTab(panel) {
                $('.dd-tab-btn').removeClass('dd-tab-active')
                    .filter('[data-panel="' + panel + '"]').addClass('dd-tab-active');
                $('.dd-panel').prop('hidden', true);
                $('#dd-panel-' + panel).prop('hidden', false);
                try {
                    localStorage.setItem(LS_KEY, panel);
                } catch (e) {}
            }

            // Restore last active tab
            try {
                var saved = localStorage.getItem(LS_KEY);
                if (saved) activateTab(saved);
            } catch (e) {}

            $(document).on('click', '.dd-tab-btn', function() {
                activateTab($(this).data('panel'));
            });

            /* ── Autocomplete ── */
            function openResults($wrap) {
                $wrap.addClass('dd-open');
                $wrap.find('.dd-ajax-results').prop('hidden', false);
            }

            function closeResults($wrap) {
                $wrap.removeClass('dd-open');
                $wrap.find('.dd-ajax-results').prop('hidden', true).empty();
            }

            function doSearch($wrap, q) {
                var $results = $wrap.find('.dd-ajax-results');
                $results.html('<li class="dd-loading"><span class="dd-spinner"></span>Searching…');
                openResults($wrap);

                $.get(ajaxurl, {
                    action: 'dd_admin_post_search',
                    nonce: nonce,
                    type: $wrap.data('type'),
                    q: q,
                }).done(function(resp) {
                    $results.empty();
                    if (!resp.success || !resp.data.length) {
                        $results.append('<li class="dd-no-results">No results found</li>');
                    } else {
                        resp.data.forEach(function(item) {
                            $results.append($('<li>').attr('data-id', item.id).text(item.text));
                        });
                    }
                });
            }

            $(document).on('focus input', '.dd-ajax-search', function() {
                var $wrap = $(this).closest('.dd-ajax-select');
                var key = $wrap.find('input[type="hidden"]').attr('name');
                clearTimeout(timers[key]);
                var q = $(this).val();
                timers[key] = setTimeout(function() {
                    doSearch($wrap, q);
                }, 220);
            });

            // mousedown fires before blur — selection registers before dropdown closes
            $(document).on('mousedown', '.dd-ajax-results li[data-id]', function(e) {
                e.preventDefault();
                var $wrap = $(this).closest('.dd-ajax-select');
                $wrap.find('.dd-ajax-search').val($(this).text());
                $wrap.find('input[type="hidden"]').val($(this).data('id'));
                $wrap.find('.dd-ajax-clear').prop('hidden', false);
                closeResults($wrap);
            });

            $(document).on('blur', '.dd-ajax-search', function() {
                var $wrap = $(this).closest('.dd-ajax-select');
                setTimeout(function() {
                    closeResults($wrap);
                }, 150);
            });

            $(document).on('click', '.dd-ajax-clear', function() {
                var $wrap = $(this).closest('.dd-ajax-select');
                $wrap.find('.dd-ajax-search').val('').trigger('focus');
                $wrap.find('input[type="hidden"]').val('0');
                $(this).prop('hidden', true);
            });
        });
    </script>
<?php
});
