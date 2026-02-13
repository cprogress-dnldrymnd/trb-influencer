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

/**
 * Plugin Name: PMPro Custom Profile Image
 * Description: Adds a profile image upload field to PMPro checkout and user profiles, with frontend display via shortcode.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: pmpro-custom-profile-image
 */


/**
 * 1. Add 'enctype' to the Checkout Form
 * Explicitly required for file uploads to function within the PMPro checkout form.
 */
function pmpro_cpi_add_enctype_to_form() {
	// JavaScript is the most reliable way to add this attribute to the PMPro form tag without editing templates
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('form.pmpro_form').attr('enctype', 'multipart/form-data');
		});
	</script>
	<?php
}
add_action( 'pmpro_checkout_before_form', 'pmpro_cpi_add_enctype_to_form' );

/**
 * 2. Add File Input to Checkout Boxes
 * Renders the file input field in the checkout area.
 */
function pmpro_cpi_add_checkout_field() {
	?>
	<div class="pmpro_checkout-field pmpro_checkout-field-profile-image">
		<label for="pmpro_profile_image">Profile Image</label>
		<input id="pmpro_profile_image" name="pmpro_profile_image" type="file" accept="image/*">
		<p class="pmpro_small">Upload a profile picture (JPG, PNG, GIF).</p>
	</div>
	<?php
}
add_action( 'pmpro_checkout_boxes', 'pmpro_cpi_add_checkout_field' );

/**
 * 3. Handle File Upload on Checkout
 * Processes the uploaded file, validates it, and saves it to user meta.
 *
 * @param int $user_id The ID of the user checking out.
 */
function pmpro_cpi_handle_checkout_upload( $user_id ) {
	// Check if a file was actually uploaded without errors
	if ( isset( $_FILES['pmpro_profile_image'] ) && $_FILES['pmpro_profile_image']['error'] === 0 ) {
		
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		// Use wp_handle_upload to ensure security checks (file type, size, etc.)
		$attachment_id = media_handle_upload( 'pmpro_profile_image', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			// In a real-world scenario, you might want to log this error or add a notice
			// error_log( 'PMPro Image Upload Error: ' . $attachment_id->get_error_message() );
		} else {
			// Save the attachment ID to the user meta
			update_user_meta( $user_id, 'pmpro_profile_image_id', $attachment_id );
		}
	}
}
add_action( 'pmpro_after_checkout', 'pmpro_cpi_handle_checkout_upload' );

/**
 * 4. Add Field to WordPress User Profile (Admin/Edit Profile)
 * Allows administrators and users to view or change the image in the backend.
 *
 * @param WP_User $user The user object.
 */
function pmpro_cpi_add_user_profile_field( $user ) {
	$image_id = get_user_meta( $user->ID, 'pmpro_profile_image_id', true );
	?>
	<h3>Profile Image</h3>
	<table class="form-table">
		<tr>
			<th><label for="pmpro_profile_image">Current Image</label></th>
			<td>
				<?php if ( $image_id ) : ?>
					<?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'style' => 'max-width: 150px; height: auto; border-radius: 50%;' ) ); ?><br>
				<?php endif; ?>
				<input type="file" name="pmpro_profile_image" id="pmpro_profile_image" accept="image/*"><br>
				<span class="description">Upload a new image to replace the current one.</span>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'pmpro_cpi_add_user_profile_field' );
add_action( 'edit_user_profile', 'pmpro_cpi_add_user_profile_field' );

/**
 * 5. Save Field from WordPress User Profile
 * Handles the upload when saving from the WP Admin profile page.
 *
 * @param int $user_id The user ID.
 */
function pmpro_cpi_save_user_profile_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	if ( isset( $_FILES['pmpro_profile_image'] ) && $_FILES['pmpro_profile_image']['error'] === 0 ) {
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		$attachment_id = media_handle_upload( 'pmpro_profile_image', 0 );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_user_meta( $user_id, 'pmpro_profile_image_id', $attachment_id );
		}
	}
}
add_action( 'personal_options_update', 'pmpro_cpi_save_user_profile_field' );
add_action( 'edit_user_profile_update', 'pmpro_cpi_save_user_profile_field' );

/**
 * 6. Shortcode for Frontend Display
 * Usage: [pmpro_profile_image user_id="123" size="thumbnail" class="my-avatar"]
 * If user_id is omitted, it defaults to the current logged-in user.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output of the image.
 */
function pmpro_cpi_display_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'user_id' => get_current_user_id(),
		'size'    => 'thumbnail', // thumbnail, medium, large, full
		'class'   => 'pmpro-profile-image',
	), $atts );

	if ( ! $atts['user_id'] ) {
		return '';
	}

	$image_id = get_user_meta( $atts['user_id'], 'pmpro_profile_image_id', true );

	if ( $image_id ) {
		return wp_get_attachment_image( $image_id, $atts['size'], false, array( 'class' => $atts['class'] ) );
	} else {
		// Fallback to Gravatar if no custom image is set
		return get_avatar( $atts['user_id'], 96, '', '', array( 'class' => $atts['class'] ) );
	}
}
add_shortcode( 'pmpro_profile_image', 'pmpro_cpi_display_shortcode' );