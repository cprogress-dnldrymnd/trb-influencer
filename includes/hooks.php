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
    global $current_membership_level, $is_free_trial, $number_of_searches;

    $number_of_searches = number_of_searches();
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
 * Updates 'number_of_searches' meta only if the search parameters have changed.
 *
 * This implementation generates a hash of the current $_GET parameters. It compares
 * this hash against a cookie storing the previous search's hash. This allows immediate
 * re-counting if the user changes filters, but blocks counting on simple page refreshes.
 *
 * @author Digitally Disruptive - Donald Raymundo
 * @uri    https://digitallydisruptive.co.uk/
 *
 * @return void
 */
/**
 * Updates 'number_of_searches' meta only if the search parameters have changed.
 *
 * This implementation generates a hash of the current $_GET parameters. It compares
 * this hash against a cookie storing the previous search's hash. This allows immediate
 * re-counting if the user changes filters, but blocks counting on simple page refreshes.
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

    // 2. Check if 'search_active' is strictly true.
    if (! isset($_GET['search_active']) || $_GET['search_active'] !== 'true') {
        return;
    }

    // 3. Ensure user is logged in.
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        influencer_number_of_searches($user_id);
    }
}
add_action('template_redirect', 'dd_update_searcher_count_on_trigger');
