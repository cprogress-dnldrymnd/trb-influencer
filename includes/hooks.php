<?php

/**
 * Set Global Membership Level Variable
 *
 * Hooks into WordPress initialization to define the global variable
 * once all plugins and user data are fully loaded.
 *
 * @global mixed $current_membership_level Holds the result of the PMPro shortcode function.
 * * @author Digitally Disruptive - Donald Raymundo
 * @link https://digitallydisruptive.co.uk/
 */
function dd_set_global_pmpro_variable()
{
    global $current_membership_level, $is_free_trial;

    // Verify the function exists to prevent fatal errors if PMPro is inactive
    if (function_exists('get_pmpro_membership_level_shortcode')) {
        $current_membership_level = get_pmpro_membership_level_shortcode();
        if ($current_membership_level === 'Free Trial') {
            $is_free_trial = true;
        } else {
            $is_free_trial = false;
        }
    } else {
        // Handle cases where the function is unavailable
        $current_membership_level = false;
    }
}
// 'init' is usually early enough for most logic, but late enough for plugins to be loaded.
add_action('init', 'dd_set_global_pmpro_variable');
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
    global $is_free_trial;
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
    }
    if ($search_type == 'fullbrief') {
        echo "#filter-col{ display: none; }";
        echo "#results-col{ --width: 100% !important; }";
    } else {
        echo "#match-score{  display: none;  !important;  }";
    }
    if ($is_free_trial) {
        echo ".hide-on-free-trial{ display: none; }";
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


/**
 * Updates the 'number_of_searches' user meta when specific page and parameter conditions are met.
 *
 * This function hooks into 'template_redirect' to ensure the global $post object is available
 * for the is_page() check. It now includes a cookie check to prevent the counter from 
 * incrementing on page refresh (F5).
 *
 * @author Digitally Disruptive - Donald Raymundo
 * @uri    https://digitallydisruptive.co.uk/
 *
 * @return void
 */
function dd_update_searcher_count_on_trigger()
{
    // 1. Verify we are on the specific Page ID (1949).
    if (! is_page(1949)) {
        return;
    }

    // 2. Check if the 'search_active' parameter exists and equals 'true'.
    if (! isset($_GET['search_active']) || $_GET['search_active'] !== 'true') {
        return;
    }

    // 3. Ensure the user is logged in.
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();

        // Define a unique cookie name for this specific user action.
        // We append the user ID to ensure it doesn't conflict on shared devices, 
        // though standard cookies are browser-specific anyway.
        $cookie_name = 'dd_search_counted_' . $user_id;

        // 4. Check if the cookie is already set. 
        // If it is, the user likely refreshed the page recently; abort the update.
        if (isset($_COOKIE[$cookie_name])) {
            return;
        }

        // 5. Retrieve current count using the updated meta key 'number_of_searches'.
        $meta_key = 'number_of_searches';
        $current_count = (int) get_user_meta($user_id, $meta_key, true);

        // 6. Increment and update the meta field.
        update_user_meta($user_id, $meta_key, $current_count + 1);

        // 7. Set a temporary cookie to prevent immediate re-counting on refresh.
        // This cookie expires in 10 seconds.
        setcookie($cookie_name, '1', time() + 10, COOKIEPATH, COOKIE_DOMAIN);
    }
}
add_action('template_redirect', 'dd_update_searcher_count_on_trigger');
