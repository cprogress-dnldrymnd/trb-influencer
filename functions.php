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
 * 1. THE EVICTION: Kill all forced delays from ALL add-ons for existing members.
 */
add_action( 'init', 'influencer_collective_kill_all_delays', 999 );
function influencer_collective_kill_all_delays() {
    if ( ! is_user_logged_in() || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) return;

    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    if ( ! empty( $current_level ) ) {
        // Kill Standalone Subscription Delays Add-on
        remove_filter( 'pmpro_checkout_level', 'pmpro_sd_pmpro_checkout_level', 10 );
        remove_filter( 'pmpro_profile_start_date', 'pmpro_sd_pmpro_profile_start_date', 10 );
        remove_filter( 'pmpro_level_cost_text', 'pmpro_sd_pmpro_level_cost_text', 10 );

        // Kill Built-in Pay by Check / Offline Gateway Delays
        remove_filter( 'pmpro_checkout_level', 'pmpro_pay_by_check_pmpro_checkout_level', 10 );
        remove_filter( 'pmpro_profile_start_date', 'pmpro_pay_by_check_pmpro_profile_start_date', 10 );
        remove_filter( 'pmpro_level_cost_text', 'pmpro_pay_by_check_pmpro_level_cost_text', 10 );
        
        // Failsafe for older PMPro offline plugin prefixes
        remove_filter( 'pmpro_checkout_level', 'pmprooff_pmpro_checkout_level', 10 );
        remove_filter( 'pmpro_profile_start_date', 'pmprooff_pmpro_profile_start_date', 10 );
        remove_filter( 'pmpro_level_cost_text', 'pmprooff_pmpro_level_cost_text', 10 );
    }
}

/**
 * 2. THE LEVEL OVERRIDE: Stack time for manual gateways without breaking Stripe.
 */
add_filter( 'pmpro_checkout_level', 'influencer_collective_manual_gateway_stacking', 999 );
function influencer_collective_manual_gateway_stacking( $level ) {
    if ( ! is_user_logged_in() || empty( $level ) ) return $level;

    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );
    
    // Catch the gateway even during an AJAX radio button toggle
    $gateway = isset( $_REQUEST['gateway'] ) ? sanitize_text_field( $_REQUEST['gateway'] ) : pmpro_getOption('gateway');
    $manual_gateways = array( 'check', 'bank_transfer', 'manual', 'offline' );

    // If manual gateway AND existing member
    if ( ! empty( $current_level ) && in_array( $gateway, $manual_gateways ) ) {
        $next_payment = pmpro_next_payment( $user_id );
        
        if ( empty( $next_payment ) && ! empty( $current_level->enddate ) ) {
            $next_payment = strtotime( $current_level->enddate, current_time( 'timestamp' ) );
        }
        
        if ( ! empty( $next_payment ) && $next_payment > current_time( 'timestamp' ) ) {
            $level->initial_payment = 0; 
            // CRITICAL FIX: We MUST zero out the trials so PMPro core doesn't auto-generate a ghost trial!
            $level->custom_trial = 0;
            $level->trial_amount = 0;
            $level->trial_limit  = 0;
        }
    }
    
    return $level;
}

/**
 * 3. THE START DATE: Push billing cycle to banked expiration date for manual gateways.
 */
add_filter( 'pmpro_profile_start_date', 'influencer_collective_manual_gateway_date', 999, 2 );
function influencer_collective_manual_gateway_date( $startdate, $order ) {
    if ( ! is_user_logged_in() || empty( $order ) ) return $startdate;

    $gateway = $order->gateway;
    $manual_gateways = array( 'check', 'bank_transfer', 'manual', 'offline' );

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

/**
 * 4. THE UI TEXT FIX: Strip the trial text out forcefully for Bank Transfers.
 */
add_filter( 'pmpro_level_cost_text', 'influencer_collective_force_ajax_text_fix', 999, 4 );
function influencer_collective_force_ajax_text_fix( $text, $level, $tags, $short ) {
    if ( ! is_user_logged_in() ) return $text;
    
    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    $gateway = isset( $_REQUEST['gateway'] ) ? sanitize_text_field( $_REQUEST['gateway'] ) : pmpro_getOption('gateway');
    $manual_gateways = array( 'check', 'bank_transfer', 'manual', 'offline' );

    if ( ! empty( $current_level ) && in_array( $gateway, $manual_gateways ) ) {
        $next_payment = pmpro_next_payment( $user_id );
        
        if ( empty( $next_payment ) && ! empty( $current_level->enddate ) ) {
            $next_payment = strtotime( $current_level->enddate, current_time( 'timestamp' ) );
        }

        if ( ! empty( $next_payment ) && $next_payment > current_time( 'timestamp' ) ) {
            $formatted_date = date_i18n( get_option( 'date_format' ), $next_payment );
            // Aggressively scrub any trial wording and inject the proper banked start date
            $text = preg_replace( '/after your.*?trial\.?/i', 'starting on ' . $formatted_date . '.', $text );
        }
    }
    
    return $text;
}