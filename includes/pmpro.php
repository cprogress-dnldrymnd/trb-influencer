<?php
/**
 * Shortcode to display current user's PMPro membership level name.
 * Usage: [current_membership_level]
 */
add_shortcode( 'current_membership_level', 'get_pmpro_membership_level_shortcode' );

function get_pmpro_membership_level_shortcode() {
    // Ensure PMPro is active
    if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
        return '';
    }

    $current_user_id = get_current_user_id();
    
    // If user is not logged in, return early
    if ( empty( $current_user_id ) ) {
        return 'Guest';
    }

    $membership_level = pmpro_getMembershipLevelForUser( $current_user_id );

    if ( ! empty( $membership_level ) ) {
        return esc_html( $membership_level->name );
    }

    return 'No Active Membership';
}

/**
 * Description: Automatically cancels all other active membership levels when a user obtains a new level, regardless of group association.
 */

if ( ! function_exists( 'dd_pmpro_enforce_single_membership_global' ) ) {

	/**
	 * Cancels all old membership levels when a user is assigned a new one.
	 *
	 * This hooks into 'pmpro_after_change_membership_level' which runs after
	 * a level has been successfully changed/added.
	 *
	 * @param int $level_id     The ID of the new level being assigned (0 if cancelling).
	 * @param int $user_id      The ID of the user.
	 * @param int $cancel_level The ID of the level being cancelled (if applicable).
	 */
	function dd_pmpro_enforce_single_membership_global( $level_id, $user_id, $cancel_level ) {

		// 1. Safety Check: If $level_id is 0, it means a cancellation is happening.
		// We must exit to prevent an infinite loop of cancellations triggering this hook.
		if ( 0 === (int) $level_id ) {
			return;
		}

		// 2. Retrieve all active membership levels for the user.
		// pmpro_getMembershipLevelsForUser returns an array of level objects, irrespective of groups.
		$user_levels = pmpro_getMembershipLevelsForUser( $user_id );

		// 3. Iterate through active levels and cancel any that are not the new level.
		if ( ! empty( $user_levels ) ) {
			foreach ( $user_levels as $level ) {
				
				// Compare IDs to ensure we don't cancel the level specifically just added.
				if ( (int) $level->id !== (int) $level_id ) {
					
					// Cancel the old level.
					// 'cancelled' uses the 'old_level_status' enum to mark it as cancelled in history.
					pmpro_cancelMembershipLevel( $level->id, $user_id, 'cancelled' );
					
					// Optional: Log this action for debugging if needed.
					// error_log( "PMPro Global Enforce: Cancelled Level ID {$level->id} for User ID {$user_id} in favor of Level ID {$level_id}" );
				}
			}
		}
	}

	// Priority 10 is standard; this ensures it runs during the checkout/assignment flow.
	add_action( 'pmpro_after_change_membership_level', 'dd_pmpro_enforce_single_membership_global', 10, 3 );
}


/**
 * Plugin Name: PMPro Checkout Button - Force Update
 * Description: Uses MutationObserver to forcefully rename the checkout button on the membership checkout page, overriding payment gateways.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function dd_pmpro_force_checkout_text_observer() {
    // 1. Target the specific checkout page URL provided
    // We check if we are on the 'membership-checkout' page.
    if ( ! is_page( 1551 ) ) {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            
            // CONFIGURATION: Set your desired button text here
            const newText = "Submit"; 
            const targetButtonId = "pmpro_btn-submit";

            const $btn = $('#' + targetButtonId);

            if ($btn.length === 0) return;

            // Function to apply the text change
            const forceText = () => {
                if ($btn.is('input')) {
                    // For <input type="submit">
                    if ($btn.val() !== newText) {
                        $btn.val(newText);
                    }
                } else {
                    // For <button> elements
                    if ($btn.text() !== newText) {
                        $btn.text(newText);
                    }
                }
            };

            // 1. Apply immediately
            forceText();

            // 2. Set up a MutationObserver to watch for changes
            // This detects if Stripe/PayPal/Theme tries to overwrite our text
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    forceText();
                });
            });

            // Start observing the button for attribute changes (like 'value' or 'disabled') or child list changes (text inside <button>)
            observer.observe($btn[0], {
                attributes: true,
                childList: true,
                subtree: true
            });
        });
    </script>
    <?php
}
add_action( 'wp_footer', 'dd_pmpro_force_checkout_text_observer', 99 );