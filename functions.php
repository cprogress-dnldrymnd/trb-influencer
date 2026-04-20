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
#require $dir . '/includes/pmpro-dynamic-pricing.php';
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
 * Swap in our custom prorating function.
 */
function init_custom_prorating_rules() {
	remove_filter( 'pmpro_checkout_level', 'pmprorate_pmpro_checkout_level', 10, 1 );
	add_filter( 'pmpro_checkout_level', 'pmpro_checkout_level_custom_prorating_rules', 10, 1 );
}
add_action( 'init', 'init_custom_prorating_rules');

/**
 * Our custom prorating function
 * Edit this function to prorate per your needs.
 * There are 3 main sections below in the if,elseif,else check
 * Change those to set the rules for downgrading, upgrading with same billing period,
 * or upgrading with different billing periods.
 * Generally, you should be setting the initial_payment value on the $level object
 * and potentially setting up a hook to update the profile start date.
 * See the proration add on code for help.
 */
function pmpro_checkout_level_custom_prorating_rules( $level ) {
	// can only prorate if they already have a level
	if ( pmpro_hasMembershipLevel() ) {
		global $current_user;
		$clevel = $current_user->membership_level;
		$morder = new MemberOrder();
		$morder->getLastMemberOrder( $current_user->ID, array( 'success', '', 'cancelled' ) );
		// no prorating needed if they don't have an order (were given the level by an admin/etc)
		if ( empty( $morder->timestamp ) ) {
			return $level;
		}
		
		// different prorating rules if they are downgrading, upgrading with same billing period, or upgrading with a different billing period
		if ( pmprorate_isDowngrade( $clevel->id, $level->id ) ) {
			// below if the default code from the proration add on as of version .3
			// you can change this section to change the downgrade logic
			/*
				Downgrade rule in a nutshell:
				1. Charge $0 now.
				2. Allow their current membership to expire on their next payment date.
				3. Setup new subscription to start billing on that date.
				4. Other code in this plugin handles changing the user's level on the future date.
			*/
			$level->initial_payment = 0;
			global $pmpro_checkout_old_level;
			$pmpro_checkout_old_level = $clevel;			
		} elseif( pmprorate_have_same_payment_period( $clevel->id, $level->id ) ) {
			// below if the default code from the proration add on as of version .3
			// you can change this section to change the logic when upgrading between levels
			// with the same billing period
			/*
				Upgrade with same billing period in a nutshell:
				1. Calculate the initial payment to cover the remaining time in the current pay period.
				2. Setup subscription to start on next payment date at the new rate.
			*/
			$payment_date = pmprorate_trim_timestamp( $morder->timestamp );
			$next_payment_date = pmprorate_trim_timestamp( pmpro_next_payment( $current_user->ID ) );
			$today = pmprorate_trim_timestamp( current_time( 'timestamp' ) );
			$days_in_period = ceil( ( $next_payment_date - $payment_date ) / 3600 / 24 );
			//if no days in period (next payment should have happened already) return level with no change to avoid divide by 0
			if ( $days_in_period <= 0 ) {
				return $level;
			}
			
			$days_passed = ceil( ( $today - $payment_date ) / 3600 / 24 );
			$per_passed = $days_passed / $days_in_period;        //as a % (decimal)
			$per_left   = 1 - $per_passed;
			
			/*
				Now figure out how to adjust the price.
				(a) What they should pay for new level = $level->billing_amount * $per_left.
				(b) What they should have paid for current level = $clevel->billing_amount * $per_passed.
				What they need to pay = (a) + (b) - (what they already paid)
				
				If the number is negative, this would technically require a credit be given to the customer,
				but we don't currently have an easy way to do that across all gateways so we just 0 out the cost.
				
				This is the method used in the code below.
				
				An alternative calculation that comes up with the same number (but may be easier to understand) is:
				(a) What they should pay for new level = $level->billing_amount * $per_left.
				(b) Their credit for cancelling early = $clevel->billing_amount * $per_left.
				What they need to pay = (a) - (b)
			*/
			$new_level_cost = $level->billing_amount * $per_left;
			$old_level_cost = $clevel->billing_amount * $per_passed;
			$level->initial_payment = min( $level->initial_payment, round( $new_level_cost + $old_level_cost - $morder->subtotal, 2 ) );
			//just in case we have a negative payment
			if ( $level->initial_payment < 0 ) {
				$level->initial_payment = 0;
			}
			
			//make sure payment date stays the same
			add_filter( 'pmpro_profile_start_date', 'pmprorate_set_startdate_to_next_payment_date', 10, 2 );			
		} else {
			// below if the default code from the proration add on as of version .3
			// you can change this section to change the logic when upgrading between levels
			// with different billing periods
			/*
				Upgrade with different payment periods in a nutshell:
				1. Apply a credit to the initial payment based on the partial period of their old level.
				2. New subscription starts today with the initial payment and will renew one period from now based on the new level.
			*/
			$payment_date = pmprorate_trim_timestamp( $morder->timestamp );
			$next_payment_date = pmprorate_trim_timestamp( pmpro_next_payment( $current_user->ID ) );
			$today = pmprorate_trim_timestamp( current_time( 'timestamp' ) );
			$days_in_period = ceil( ( $next_payment_date - $payment_date ) / 3600 / 24 );
			//if no days in period (next payment should have happened already) return level with no change to avoid divide by 0
			if ( $days_in_period <= 0 ) {
				return $level;
			}
			
			$days_passed = ceil( ( $today - $payment_date ) / 3600 / 24 );
			$per_passed = $days_passed / $days_in_period;        //as a % (decimal)			
			$per_left   = 1 - $per_passed;
			$credit = $morder->subtotal * $per_left;			
			
			$level->initial_payment = round( $level->initial_payment - $credit, 2 );
			//just in case we have a negative payment
			if ( $level->initial_payment < 0 ) {
				$level->initial_payment = 0;
			}
		}		
	}
	return $level;
}