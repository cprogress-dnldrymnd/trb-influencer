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
 * 1. THE EVICTION: Successfully remove the Subscription Delays Add-on for all upgrades.
 * CRITICAL FIX: Corrected function names to include underscores (pmpro_sd_)
 */
add_action( 'init', 'influencer_collective_kill_delays_for_upgrades', 99 );
function influencer_collective_kill_delays_for_upgrades() {
    if ( ! is_user_logged_in() || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) return;

    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    // If the user is an existing member, completely disable the 3-day delay Add-on
    if ( ! empty( $current_level ) ) {
        remove_filter( 'pmpro_checkout_level', 'pmpro_sd_pmpro_checkout_level', 10 );
        remove_filter( 'pmpro_profile_start_date', 'pmpro_sd_pmpro_profile_start_date', 10 );
        remove_filter( 'pmpro_level_cost_text', 'pmpro_sd_pmpro_level_cost_text', 10 );
    }
}

/**
 * 2. TIME-STACKING FOR BANK TRANSFER ONLY:
 * Proration works natively for Stripe now that the delay is gone.
 * We ONLY apply $0 time-stacking to manual gateways to prevent double-billing.
 */
add_filter( 'pmpro_checkout_level', 'influencer_collective_bank_transfer_stacking', 999 );
function influencer_collective_bank_transfer_stacking( $level ) {
    if ( ! is_user_logged_in() || empty( $level ) ) return $level;

    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );
    
    // Detect the currently selected gateway
    $gateway = isset( $_REQUEST['gateway'] ) ? sanitize_text_field( $_REQUEST['gateway'] ) : pmpro_getOption('gateway');
    
    // PMPro's default offline slug is 'check', but we cover all bases
    $manual_gateways = array( 'check', 'bank_transfer', 'manual' );

    // Only apply the $0 override if they are using an offline/manual gateway
    if ( ! empty( $current_level ) && in_array( $gateway, $manual_gateways ) ) {
        $next_payment = pmpro_next_payment( $user_id );
        if ( empty( $next_payment ) && ! empty( $current_level->enddate ) ) {
            $next_payment = strtotime( $current_level->enddate, current_time( 'timestamp' ) );
        }
        
        // If they have banked time, set today's charge to $0
        if ( ! empty( $next_payment ) && $next_payment > current_time( 'timestamp' ) ) {
            $level->initial_payment = 0; 
        }
    }
    
    return $level;
}

/**
 * 3. PUSH START DATE FOR BANK TRANSFER ONLY
 * Force the offline gateway to delay the new billing cycle until banked time expires.
 */
add_filter( 'pmpro_profile_start_date', 'influencer_collective_bank_transfer_date', 999, 2 );
function influencer_collective_bank_transfer_date( $startdate, $order ) {
    if ( ! is_user_logged_in() || empty( $order ) ) return $startdate;

    $gateway = $order->gateway;
    $manual_gateways = array( 'check', 'bank_transfer', 'manual' );

    // Only manipulate the start date if it is an offline gateway
    if ( in_array( $gateway, $manual_gateways ) ) {
        $user_id = get_current_user_id();
        $current_level = pmpro_getMembershipLevelForUser( $user_id );

        if ( ! empty( $current_level ) ) {
            $next_payment = pmpro_next_payment( $user_id );
            if ( empty( $next_payment ) && ! empty( $current_level->enddate ) ) {
                $next_payment = strtotime( $current_level->enddate, current_time( 'timestamp' ) );
            }

            if ( ! empty( $next_payment ) && $next_payment > current_time( 'timestamp' ) ) {
                $startdate = date( "Y-m-d\TH:i:s", $next_payment );
            }
        }
    }
    
    return $startdate;
}