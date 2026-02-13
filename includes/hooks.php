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
    if (isset($_GET['search-brief']) && $_GET['search-brief'] != '') {
        $search_type = 'fullbrief';
    } else {
        $search_type = 'filtered';
    }
    echo '<style id="custom--css">';
    if ($header_text_colour || $header_accent_colour) {
        if ($header_accent_colour) {
            echo ".header.header.header.header.header .header--accent-color .elementor-heading-title { color: var($header_accent_colour) }";
        }
        if ($header_text_colour) {
            echo ".header.header.header.header.header .header--text-color * { color: var($header_text_colour) }";
            echo ".header.header.header.header.header .logo-box svg { color: var($header_text_colour) }";
            echo ".header.header.header.header.header .logo-box svg { fill: var($header_text_colour) }";
        }
        if ($search_type == 'fullbrief') {
            echo "#filter-col{ display: none; }";
            echo "#results-col{ --width: 100% !important; }";
        }
    }
    echo '</style>';
}

add_action('wp_head', 'action_wp_head');


/**
 * Restrict access to specific pages based on ACF 'members_only' field.
 * Redirects non-logged-in users to a specified page.
 *
 * @return void
 */
function dd_restrict_dashboard_template_access()
{
    // specific template check (if needed in future) can go here.

    // 1. Get the current Object ID (Page/Post ID) to ensure correct context outside the loop.
    $object_id = get_queried_object_id();

    // 2. Check the ACF 'members_only' field. 
    // We check if function_exists to prevent fatal errors if ACF is deactivated.
    $is_restricted = function_exists('get_field') ? get_field('members_only', $object_id) : false;

    // 3. Condition: User is NOT logged in AND the page is restricted.
    if (! is_user_logged_in() && $is_restricted) {

        // Check if the current page is using the specific template file.
        // Note: This path is relative to the active theme's root directory.

        // Execute the redirect to the home URL (using ID 4144).
        wp_redirect(get_the_permalink(4144));

        // Always exit after a redirect to stop further script execution.
        exit;
    }
}
add_action('template_redirect', 'dd_restrict_dashboard_template_access');
