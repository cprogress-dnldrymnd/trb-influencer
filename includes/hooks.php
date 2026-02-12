<?php

/**
 * Injects dynamic CSS into the site header based on ACF field values.
 *
 * This function retrieves specific color settings from Advanced Custom Fields
 * (header_text_colour and header_accent_colour) and outputs an inline style block
 * if they are present.
 * * Note: The CSS selectors use class chaining (e.g., .header.header...) to artificially 
 * boost specificity and override default Elementor or theme styles without using !important.
 *
 * @return void
 */
function action_wp_head()
{
    $header_text_colour = get_field('header_text_colour');
    $header_accent_colour = get_field('header_accent_colour');
    if ($header_text_colour || $header_accent_colour) {
?>
        <style id="page--options">
            <?php
            if ($header_accent_colour) {
                echo ".header.header.header.header.header .header--accent-color .elementor-heading-title { color: var($header_accent_colour) }";
            }
            if ($header_text_colour) {
                echo ".header.header.header.header.header .header--text-color * { color: var($header_text_colour) }";
                echo ".header.header.header.header.header .logo-box svg { color: var($header_text_colour) }";
                echo ".header.header.header.header.header .logo-box svg { fill: var($header_text_colour) }";
            }
            ?>
        </style>
<?php
    }
}

add_action('wp_head', 'action_wp_head');


/**
 * Redirects guests accessing the specific Dashboard page template.
 *
 * This function hooks into 'template_redirect' to verify authentication status.
 * It utilizes 'is_page_template()' to target the specific file path requested.
 * If the user is unauthenticated and the current page matches the dashboard template,
 * they are redirected to the homepage.
 *
 * @since 1.0.1
 * @return void
 */
function dd_restrict_dashboard_template_access()
{
    // Check if the user is NOT logged in.
    if (! is_user_logged_in() && !is_page(4144)) {

        // Check if the current page is using the specific template file.
        // Note: This path is relative to the active theme's root directory.
        if (is_page_template('templates/page-dashboard.php')) {

            // Execute the redirect to the home URL.
            wp_redirect(home_url());

            // Always exit after a redirect to stop further script execution.
            exit;
        }
    }
}
add_action('template_redirect', 'dd_restrict_dashboard_template_access');
