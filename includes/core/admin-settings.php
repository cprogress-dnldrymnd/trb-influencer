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

function dd_admin_all_pages()
{
    static $cache = null;
    if ($cache === null) {
        $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
        $cache = [];
        foreach ($pages as $p) {
            $cache[$p->ID] = $p->post_title;
        }
    }
    return $cache;
}

function dd_admin_all_templates()
{
    static $cache = null;
    if ($cache === null) {
        $posts = get_posts([
            'post_type'      => 'elementor_library',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $cache = [];
        foreach ($posts as $t) {
            $cache[$t->ID] = $t->post_title . ' (#' . $t->ID . ')';
        }
    }
    return $cache;
}

function dd_render_post_search_select($name, $current_id, $options, $description)
{
    $uid = esc_attr($name);
    echo '<div class="dd-search-select-wrap">';
    echo '<input type="text" class="dd-filter-input regular-text" placeholder="Type to filter..." autocomplete="off" data-for="' . $uid . '">';
    echo '<select name="' . $uid . '" id="' . $uid . '" class="dd-filterable-select" size="7">';
    echo '<option value="0">— None —</option>';
    foreach ($options as $id => $label) {
        echo '<option value="' . esc_attr($id) . '"' . selected($current_id, $id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    if ($description) {
        echo '<p class="description">' . esc_html($description) . '</p>';
    }
    echo '</div>';
}

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
                dd_render_post_search_select($key, dd_get_page_id($key, $default), dd_admin_all_pages(), $description);
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
                dd_render_post_search_select($key, dd_get_template_id($key, $default), dd_admin_all_templates(), $desc);
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
// Enqueue filter UI only on the settings page
// ---------------------------------------------------------------------------
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (! $screen || $screen->id !== 'settings_page_dd-theme-settings') {
        return;
    }
    ?>
    <style>
        .dd-search-select-wrap { max-width: 340px; margin-bottom: 4px; }
        .dd-filter-input { width: 100%; margin-bottom: 4px; box-sizing: border-box; }
        .dd-filterable-select { width: 100%; font-size: 13px; }
    </style>
    <script>
        jQuery(function ($) {
            $(document).on('input', '.dd-filter-input', function () {
                var q = $(this).val().toLowerCase();
                var $sel = $('#' + $(this).data('for'));
                $sel.find('option').each(function () {
                    var match = !q || $(this).val() === '0' || $(this).text().toLowerCase().indexOf(q) !== -1;
                    $(this).prop('hidden', !match);
                });
            });
        });
    </script>
    <?php
});
