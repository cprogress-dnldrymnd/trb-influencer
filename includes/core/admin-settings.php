<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Returns a page ID stored in wp_options, falling back to a hardcoded default.
 *
 * @param string $key      Option name (e.g. 'dd_search_results_page_id').
 * @param int    $fallback Value to use when the option has never been saved.
 * @return int
 */
function dd_get_page_id($key, $fallback = 0)
{
    return (int) get_option($key, $fallback);
}

// ---------------------------------------------------------------------------
// Register the settings group and individual options
// ---------------------------------------------------------------------------
add_action('admin_init', function () {
    $options = [
        'dd_search_results_page_id' => 1949,
        'dd_search_page_id'         => 2149,
        'dd_dashboard_page_id'      => 1565,
        'dd_login_redirect_page_id' => 4144,
    ];

    foreach ($options as $key => $default) {
        register_setting('dd_theme_page_ids', $key, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => $default,
        ]);
    }

    add_settings_section(
        'dd_page_ids_section',
        'Page Assignments',
        function () {
            echo '<p>Select which WordPress pages serve each platform role. Changes take effect immediately after saving.</p>';
        },
        'dd-theme-settings'
    );

    $fields = [
        'dd_search_results_page_id' => ['Search Results Page', 1949, 'Where influencer search results are displayed.'],
        'dd_search_page_id'         => ['Search Form Page',    2149, 'Page that contains the influencer search form.'],
        'dd_dashboard_page_id'      => ['Dashboard Page',      1565, 'User dashboard — also the post-login redirect target.'],
        'dd_login_redirect_page_id' => ['Login / Redirect Page', 4144, 'Non-logged-in users are redirected here.'],
    ];

    foreach ($fields as $key => [$label, $default, $description]) {
        add_settings_field(
            $key,
            $label,
            function () use ($key, $default, $description) {
                $current = dd_get_page_id($key, $default);
                wp_dropdown_pages([
                    'name'             => $key,
                    'id'               => $key,
                    'selected'         => $current,
                    'show_option_none' => '— Select a page —',
                    'option_none_value' => '0',
                ]);
                echo '<p class="description">' . esc_html($description) . '</p>';
            },
            'dd-theme-settings',
            'dd_page_ids_section'
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
