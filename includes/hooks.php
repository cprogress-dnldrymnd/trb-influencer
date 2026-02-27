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
    global $current_membership_level, $is_free_trial, $number_of_searches, $search_results_page_id, $search_page_id, $dashboard_page_id;

    $number_of_searches = number_of_searches();
    $search_results_page_id = 1949;
    $search_page_id = 2149;
    $dashboard_page_id = 1565;
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
        echo ".outreach-form-trigger{ display: none !important}";
        echo ".filtered-search{ display: flex !important }";
        echo "#pmpro_level_group-1 {display: none !important} ";
    }

    $current_balance = get_current_user_remaining_mycred_balance();

    $recently_viewed = get_recent_influencer_ids_array(5);
    $current_user_id = get_current_user_id();
    $ranked_niches = get_user_niche_ranking($current_user_id, 3);
    $recently_viewed_stats = true;
    $ranked_niches_stats = true;

    if (!$recently_viewed || count($recently_viewed) === 0) {
        echo '#dashboard-activity-recently-viewed-influencer { display: none !important; }';
        $recently_viewed_stats = false;
    }

    if (count($ranked_niches) === 0) {
        echo '#dashboard-activity-most-engage-niches { display: none !important; }';
        $ranked_niches_stats = false;
    }
    if ($ranked_niches_stats == false && $recently_viewed_stats == false) {
        echo '#starts-a-search { display: flex !important; }';
        echo '#dashboard-activity { display: none !important; }';
    } else {
        echo '#starts-a-search { display: none !important; }';
    }




    echo '</style>';
?>
    <div class="notice-wrap">
        <div class="notice-item-wrapper">
            <div class="notice-item succes" style="opacity: 0.71997;">
                <div class="notice-item-close">×</div>
                <p>Points gained by joining Essential Membership</p>
                <div class="mycred-points">20 points</div>
            </div>
        </div>
    </div>
<?php
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
    if (! is_user_logged_in() && $is_restricted || ! is_user_logged_in() && is_single() && get_post_type() == 'influencer') {

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
 * Redirects the influencer discovery page to the search page conditionally.
 * Hooks into 'template_redirect' to process the redirect before headers are sent.
 * Validates the existence of required globals, checks if the current query
 * matches the discovery page, and triggers a redirect. The redirect executes 
 * if a search is inactive, OR if the user is on an active free trial and 
 * has met or exceeded their query limit (>= 3).
 *
 * @global int  $search_results_page_id The ID of the page triggering the redirect.
 * @global int  $search_page_id         The ID of the target destination page.
 * @global bool $is_free_trial          Flag indicating if the user is on a free trial.
 * @global int  $number_of_searches     The total number of searches executed by the user.
 * @return void
 */
function dd_execute_conditional_page_redirect()
{
    // Access the defined global variables in the current scope.
    global $search_results_page_id, $search_page_id, $is_free_trial, $number_of_searches;

    // Terminate early if the global variables are undefined or evaluate to empty.
    if (empty($search_results_page_id) || empty($search_page_id)) {
        return;
    }

    // Evaluate if the currently requested page matches the target ID.
    if (is_page($search_results_page_id)) {

        // Check if the 'search_active' query parameter is explicitly set to 'true'.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_search_active = isset($_GET['search_active']) && sanitize_text_field(wp_unslash($_GET['search_active'])) === 'true';

        // Evaluate if the user has exhausted their free trial search limits.
        // strict type checking and isset() prevent PHP warnings from uninitialized globals.
        $trial_limit_reached = (isset($is_free_trial) && $is_free_trial === true && isset($number_of_searches) && (int) $number_of_searches >= 3);

        // Allow the page to load ONLY if a search is active AND the trial limit has not been reached.
        if ($is_search_active && !$trial_limit_reached) {
            return; // Halt execution and allow the current page to load.
        }

        // Retrieve the fully qualified URL for the destination page.
        $destination_url = get_permalink($search_page_id);

        // Proceed only if a valid permalink was successfully returned.
        if ($destination_url) {

            // Execute the redirect. A 301 (permanent) status is used here for SEO, 
            // but can be changed to 302 (temporary) if the routing is dynamic/temporary.
            wp_safe_redirect($destination_url, 301);

            // Always invoke exit after a redirect header to halt further script execution.
            exit;
        }
    }
}
add_action('template_redirect', 'dd_execute_conditional_page_redirect');
