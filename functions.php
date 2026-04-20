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
require $dir . '/includes/pmpro.php';
require $dir . '/includes/pmpro-dynamic-pricing.php';
require $dir . '/includes/pmpro-mycred-rewards-manager.php';
require $dir . '/includes/email-template-manager.php';
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
 * Remove subscription delay for logged-in current or past members. 
 * EXCEPTION: Leaves the delay active for Level 15.
 */
function my_pmpro_one_time_sub_delay( $checkout_level ) {

    // Logged-out users should always get the trial/delay.
    if ( ! is_user_logged_in() ) {
        return $checkout_level;
    }

    // --- LEVEL 15 EXCEPTION ---
    // If the user currently HAS Level 15, OR they are PURCHASING Level 15,
    // bail out immediately so the Subscription Delay remains fully active.
    if ( pmpro_hasMembershipLevel( 15 ) || $checkout_level->id == 15 ) {
        return $checkout_level;
    }

    $order     = new MemberOrder();
    $lastorder = $order->getLastMemberOrder( null, array( 'success', 'cancelled' ) );
    $has_delay = get_option( 'pmpro_subscription_delay_' . $checkout_level->id, '' );

    // If user currently has a membership level or previously had a membership level, remove subscription delay.
    if ( ( pmpro_hasMembershipLevel() || ! empty( $lastorder ) ) && ! empty( $has_delay ) ) {

        // Remove subscription delay filters and actions (standard).
        remove_filter( 'pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10, 2 );
        remove_action( 'pmpro_after_checkout', 'pmprosd_pmpro_after_checkout' );
        remove_filter( 'pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3 );
        remove_filter( 'pmpro_level_cost_text', 'pmprosd_level_cost_text', 10, 2 );
        remove_action( 'pmpro_save_discount_code_level', 'pmprosd_pmpro_save_discount_code_level', 10, 2 );

        // Remove the updated filter added in PMPro Subscription Delays 3.4+.
        remove_filter( 'pmpro_checkout_level', 'pmprosd_pmpro_checkout_level', 10, 2 );

        // Set the initial amount to match the billing amount.
        if ( $checkout_level->billing_amount > 0 ) {
            $checkout_level->initial_payment = $checkout_level->billing_amount;
        }
    } 
    return $checkout_level;
}
add_filter( 'pmpro_checkout_level', 'my_pmpro_one_time_sub_delay' );

/**
 * Helper function to force correct start date for different billing periods
 */
function dd_force_new_billing_cycle_start_date( $startdate, $order ) {
    global $dd_new_cycle_number, $dd_new_cycle_period;
    
    if ( ! empty( $dd_new_cycle_number ) && ! empty( $dd_new_cycle_period ) ) {
        // Calculate the exact future date based on the new plan's cycle
        return date( 'Y-m-d\TH:i:s', current_time( 'timestamp' ) + strtotime( "+ {$dd_new_cycle_number} {$dd_new_cycle_period}", 0 ) );
    }
    return $startdate;
}

/**
 * Swap in our custom prorating function.
 */
function init_custom_prorating_rules() {
    remove_filter( 'pmpro_checkout_level', 'pmprorate_pmpro_checkout_level', 10, 1 );
    add_filter( 'pmpro_checkout_level', 'pmpro_checkout_level_custom_prorating_rules', 10, 1 );
}
add_action( 'init', 'init_custom_prorating_rules');

/**
 * Our custom prorating function
 */
function pmpro_checkout_level_custom_prorating_rules( $level ) {
    // Can only prorate if they already have a level
    if ( pmpro_hasMembershipLevel() ) {
        global $current_user;
        $clevel = $current_user->membership_level;
        $morder = new MemberOrder();
        $morder->getLastMemberOrder( $current_user->ID, array( 'success', '', 'cancelled' ) );
        
        // No prorating needed if they don't have an order
        if ( empty( $morder->timestamp ) ) {
            return $level;
        }
        
        // Safely determine the base cost for the new level
        $base_new_level_cost = ( $level->initial_payment > 0 ) ? $level->initial_payment : $level->billing_amount;

        // Do not rely on Level IDs. Check the actual cycle text (e.g. "Month" vs "Year")
        $is_same_period = ( $clevel->cycle_number == $level->cycle_number && $clevel->cycle_period == $level->cycle_period );

        // DOWNGRADE LOGIC
        if ( pmprorate_isDowngrade( $clevel->id, $level->id ) ) {
            
            $level->initial_payment = 0;
            global $pmpro_checkout_old_level;
            $pmpro_checkout_old_level = $clevel;            
            
            add_filter( 'pmpro_profile_start_date', 'pmprorate_set_startdate_to_next_payment_date', 10, 2 );
            
        // UPGRADE LOGIC (SAME BILLING PERIOD)
        } elseif( $is_same_period ) { 
            
            $payment_date = pmprorate_trim_timestamp( $morder->timestamp );
            $next_payment_date = pmprorate_trim_timestamp( pmpro_next_payment( $current_user->ID ) );
            $today = pmprorate_trim_timestamp( current_time( 'timestamp' ) );
            $days_in_period = ceil( ( $next_payment_date - $payment_date ) / 3600 / 24 );
            
            if ( $days_in_period <= 0 ) return $level;
            
            $days_passed = ceil( ( $today - $payment_date ) / 3600 / 24 );
            $per_passed = $days_passed / $days_in_period;        
            $per_left   = 1 - $per_passed;
            
            $new_level_cost = $level->billing_amount * $per_left;
            $old_level_cost = $clevel->billing_amount * $per_passed;
            
            // HOPSCOTCH FIX: Prevent $0 subtotals from breaking Same-Period math
            $subtotal_to_use = ( $morder->subtotal > 0 ) ? $morder->subtotal : $clevel->billing_amount;
            
            $level->initial_payment = min( $base_new_level_cost, round( $new_level_cost + $old_level_cost - $subtotal_to_use, 2 ) );
            
            if ( $level->initial_payment < 0 ) {
                $level->initial_payment = 0;
            }
            
            add_filter( 'pmpro_profile_start_date', 'pmprorate_set_startdate_to_next_payment_date', 10, 2 );            
            
        // UPGRADE / DOWNGRADE LOGIC (DIFFERENT BILLING PERIODS - e.g., Monthly <-> Annual)
        } else {
            
            $payment_date = pmprorate_trim_timestamp( $morder->timestamp );
            $next_payment_date = pmprorate_trim_timestamp( pmpro_next_payment( $current_user->ID ) );
            $today = pmprorate_trim_timestamp( current_time( 'timestamp' ) );
            
            $days_left = ceil( ( $next_payment_date - $today ) / 3600 / 24 );
            
            if ( $days_left <= 0 ) return $level;

            // THE HOPSCOTCH FIX: Accurately calculate credit even if the last order was $0
            if ( $morder->subtotal > 0 ) {
                $days_in_period = ceil( ( $next_payment_date - $payment_date ) / 3600 / 24 );
                $per_passed = ( $days_in_period - $days_left ) / $days_in_period;        
                $per_left   = 1 - $per_passed;
                $credit = $morder->subtotal * $per_left; 
            } else {
                // Last checkout was $0. Calculate the true value of their banked days.
                $cycle_days = 30; // Default to Monthly
                if ( $clevel->cycle_period == 'Year' ) $cycle_days = 365 * $clevel->cycle_number;
                elseif ( $clevel->cycle_period == 'Week' ) $cycle_days = 7 * $clevel->cycle_number;
                elseif ( $clevel->cycle_period == 'Day' ) $cycle_days = $clevel->cycle_number;

                $daily_rate = $clevel->billing_amount / $cycle_days;

                // Fallback to new plan's daily rate if old plan was completely free
                if ( $daily_rate <= 0 ) {
                    $new_cycle_days = 30;
                    if ( $level->cycle_period == 'Year' ) $new_cycle_days = 365 * $level->cycle_number;
                    elseif ( $level->cycle_period == 'Week' ) $new_cycle_days = 7 * $level->cycle_number;
                    elseif ( $level->cycle_period == 'Day' ) $new_cycle_days = $level->cycle_number;

                    $daily_rate = $base_new_level_cost / $new_cycle_days;
                }

                $credit = $days_left * $daily_rate;
            }
            
            $level->initial_payment = round( $base_new_level_cost - $credit, 2 );
            
            // Unhook any lingering Same-Period filters from PMPro core just to be safe
            remove_filter( 'pmpro_profile_start_date', 'pmprorate_set_startdate_to_next_payment_date', 10 );

            // Check if they have surplus credit (e.g. Annual -> Monthly OR Hopscotching)
            if ( $level->initial_payment <= 0 ) {
                
                // Zero out the cost today
                $level->initial_payment = 0;
                
                // Force the start date to map exactly to their EXISTING expiration date so they keep their banked time!
                add_filter( 'pmpro_profile_start_date', 'pmprorate_set_startdate_to_next_payment_date', 99, 2 );
                
            } else {
                
                // They owe money today. Force the start date to map exactly to the NEW billing cycle!
                global $dd_new_cycle_number, $dd_new_cycle_period;
                $dd_new_cycle_number = $level->cycle_number; // e.g., 1
                $dd_new_cycle_period = $level->cycle_period; // e.g., 'Year'
                
                add_filter( 'pmpro_profile_start_date', 'dd_force_new_billing_cycle_start_date', 99, 2 );
            }
        }       
    }
    return $level;
}