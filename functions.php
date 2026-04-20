<?php

/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0');

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles()
{
    global $search_results_page_id;

    $page_id = get_the_ID();

    wp_enqueue_style('influencer-style', get_stylesheet_directory_uri() . '/style.css');


    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_enqueue_script('influencer-js', get_stylesheet_directory_uri() . '/assets/js/main.js', ['jquery']);
    wp_localize_script('influencer-js', 'ajax_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'page_id' => $page_id,
        'search_results_page_id' => $search_results_page_id,
        'save_search_nonce'    => wp_create_nonce('save_search_nonce'),
        'save_influencer_nonce'    => wp_create_nonce('save_influencer_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20);

// Resolve and cache the directory path once.
// NOTE: Change to get_stylesheet_directory() if this is a child theme.
$dir = get_stylesheet_directory();

// Direct, unrolled require statements. 
// This is the fastest execution path in PHP for procedural files.
require $dir . '/includes/influencers.php';
require $dir . '/includes/hooks.php';
require $dir . '/includes/custom-functions.php';
require $dir . '/includes/brief-parser.php';
require $dir . '/includes/saves-manager.php';
require $dir . '/includes/mycred.php';
require $dir . '/includes/mycred-frontend-log.php';
#require $dir . '/includes/pmpro.php';
require $dir . '/includes/pmpro-mycred-rewards-manager.php';
require $dir . '/includes/email-template-manager.php';
#require $dir . '/includes/pmpro-dynamic-pricing.php';
require $dir . '/includes/acf.php';
require $dir . '/includes/sign-up.php';
require $dir . '/includes/elementor.php';
require $dir . '/includes/outreach.php';
require $dir . '/includes/charts.php';
require $dir . '/includes/feeds.php';
require $dir . '/includes/shortcodes.php';
require $dir . '/includes/ajax.php';

function influencers_meta()
{
    ob_start();
?>
    <pre>
    <?php var_dump(get_post_meta(get_the_ID())); ?>
</pre>
<?php
    return ob_get_clean();
}
add_shortcode('influencers_meta', 'influencers_meta');



/**
 * Remove the default WordPress shutdown buffer flush action.
 *
 * This snippet unhooks 'wp_ob_end_flush_all' from the 'shutdown' action.
 * It is primarily used to suppress "Failed to send buffer" errors in 
 * specific server configurations or when custom output buffering is required.
 *
 * @return void
 */
add_action('init', function () {
    remove_action('shutdown', 'wp_ob_end_flush_all', 1);
});


/**
 * Disable PMPro Subscription Delay on Plan Switch for Active Members
 * Forces the new plan's payments to start exactly when the current paid time expires.
 */
function ic_override_subscription_delay_on_switch( $startdate, $user_id, $level ) {
    // 1. Get the user's currently active membership level
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    // 2. Check if they have an active plan
    if ( ! empty( $current_level ) ) {
        
        // 3. Find exactly when their current paid time runs out
        // Check for a hard end date first, otherwise check the recurring next payment date
        $current_expiration = ! empty( $current_level->enddate ) ? $current_level->enddate : pmpro_next_payment( $user_id );
        
        // 4. If they have time banked in the future, override the start date
        if ( ! empty( $current_expiration ) && $current_expiration > time() ) {
            
            // Format the start date exactly how PMPro expects it (Y-m-d\TH:i:s)
            // This completely ignores the 3-day delay and uses their banked time
            $startdate = date( "Y-m-d\TH:i:s", $current_expiration );
        }
    }

    return $startdate;
}
// We use priority 20 to ensure this runs AFTER the PMPro Subscription Delays add-on tries to set its 3-day rule
add_filter( 'pmpro_profile_start_date', 'ic_override_subscription_delay_on_switch', 20, 3 );