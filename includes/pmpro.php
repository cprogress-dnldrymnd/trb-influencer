<?php

/**
 * Shortcode to display current user's PMPro membership level name.
 * Usage: [current_membership_level]
 */
add_shortcode('current_membership_level', 'get_pmpro_membership_level_shortcode');

function get_pmpro_membership_level_shortcode()
{
    // Ensure PMPro is active
    if (! function_exists('pmpro_getMembershipLevelForUser')) {
        return '';
    }

    $current_user_id = get_current_user_id();

    // If user is not logged in, return early
    if (empty($current_user_id)) {
        return 'Guest';
    }

    $membership_level = pmpro_getMembershipLevelForUser($current_user_id);

    if (! empty($membership_level)) {
        return esc_html($membership_level->name);
    }

    return 'No Active Membership';
}

/**
 * Description: Automatically cancels all other active membership levels when a user obtains a new level, regardless of group association.
 */

if (! function_exists('dd_pmpro_enforce_single_membership_global')) {

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
    function dd_pmpro_enforce_single_membership_global($level_id, $user_id, $cancel_level)
    {

        // 1. Safety Check: If $level_id is 0, it means a cancellation is happening.
        // We must exit to prevent an infinite loop of cancellations triggering this hook.
        if (0 === (int) $level_id) {
            return;
        }

        // 2. Retrieve all active membership levels for the user.
        // pmpro_getMembershipLevelsForUser returns an array of level objects, irrespective of groups.
        $user_levels = pmpro_getMembershipLevelsForUser($user_id);

        // 3. Iterate through active levels and cancel any that are not the new level.
        if (! empty($user_levels)) {
            foreach ($user_levels as $level) {

                // Compare IDs to ensure we don't cancel the level specifically just added.
                if ((int) $level->id !== (int) $level_id) {

                    // Cancel the old level.
                    // 'cancelled' uses the 'old_level_status' enum to mark it as cancelled in history.
                    pmpro_cancelMembershipLevel($level->id, $user_id, 'cancelled');

                    // Optional: Log this action for debugging if needed.
                    // error_log( "PMPro Global Enforce: Cancelled Level ID {$level->id} for User ID {$user_id} in favor of Level ID {$level_id}" );
                }
            }
        }
    }

    // Priority 10 is standard; this ensures it runs during the checkout/assignment flow.
    add_action('pmpro_after_change_membership_level', 'dd_pmpro_enforce_single_membership_global', 10, 3);
}


/**
 * Plugin Name: PMPro Checkout Button - Force Update
 * Description: Uses MutationObserver to forcefully rename the checkout button on the membership checkout page, overriding payment gateways.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.1.0
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function dd_pmpro_force_checkout_text_observer()
{
    // 1. Target the specific checkout page URL provided
    // We check if we are on the 'membership-checkout' page.
    if (! is_page(1551)) {
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
add_action('wp_footer', 'dd_pmpro_force_checkout_text_observer', 99);

/**
 * Retrieve the file URL for a specific user and PMPro field.
 *
 * @param int    $user_id   The ID of the user.
 * @param string $field_key The meta key used when registering the field (e.g., 'resume_upload').
 * @return string|false     The URL of the file or false if not found.
 */
function get_pmpro_file_field_url(int $user_id, string $field_key)
{
    // Retrieve the raw meta value.
    $meta_value = get_user_meta($user_id, $field_key, true);

    // Case A: The field stored a direct string URL.
    if (is_string($meta_value) && ! empty($meta_value)) {
        return $meta_value;
    }

    // Case B: The field stored an array (common in newer Register Helper versions).
    // The array typically looks like: ['original_filename' => '...', 'fullpath' => '...']
    if (is_array($meta_value) && ! empty($meta_value['fullpath'])) {
        return $meta_value['fullpath'];
    }

    // Case C: Sometimes only the Attachment ID is stored (rare, but possible with custom implementations).
    if (is_numeric($meta_value)) {
        return wp_get_attachment_url($meta_value);
    }

    return false;
}

/**
 * Converts an absolute server path to a web-accessible URL.
 * * This is specifically designed to handle PMPro Register Helper fields
 * that store the full /home/user/path instead of the URL.
 *
 * @param string $path_or_url The raw value retrieved from get_user_meta.
 * @return string The converted URL, or the original string if no conversion was needed.
 */
function convert_pmpro_path_to_url( $path_or_url ) {
    // 1. If the input is an array (sometimes returned by PMPro), extract the fullpath.
    if ( is_array( $path_or_url ) && isset( $path_or_url['fullpath'] ) ) {
        $path_or_url = $path_or_url['fullpath'];
    }

    // 2. If it's empty or not a string, return early.
    if ( empty( $path_or_url ) || ! is_string( $path_or_url ) ) {
        return '';
    }

    // 3. Check if the string contains the local server path (ABSPATH).
    // ABSPATH is a WP constant, e.g., /home/influencerdd2/public_html/
    if ( strpos( $path_or_url, ABSPATH ) !== false ) {
        // Replace the server path with the site URL.
        // We use site_url('/') to ensure we get the root web address.
        $url = str_replace( ABSPATH, site_url( '/' ), $path_or_url );
        
        // Fix any potential double slashes that might occur during replacement
        // (excluding the http:// or https:// protocol slashes).
        $url = str_replace( '://', '___PROTOCOL___', $url );
        $url = str_replace( '//', '/', $url );
        $url = str_replace( '___PROTOCOL___', '://', $url );

        return $url;
    }

    // 4. Fallback: If ABSPATH didn't match, try matching against the Uploads Directory specifically.
    // This is useful if the server structure varies slightly (e.g., symlinks).
    $upload_dir = wp_upload_dir();
    if ( strpos( $path_or_url, $upload_dir['basedir'] ) !== false ) {
        return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $path_or_url );
    }

    // Return original if no match found (it might already be a URL).
    return $path_or_url;
}

// --- Usage Example ---

// 1. Get the raw meta (the path you pasted).
$raw_file_path = get_user_meta( get_current_user_id(), 'my_file_field_key', true );

// 2. Convert it.
$file_url = convert_pmpro_path_to_url( $raw_file_path );

// 3. Output.
if ( $file_url ) {
    echo '<a href="' . esc_url( $file_url ) . '" target="_blank">View File</a>';
}


/**
 * Intercepts and modifies localization strings for Paid Memberships Pro.
 * * This function hooks into the WordPress 'gettext' translation system. It checks 
 * if the text domain is PMPro and if the original string matches known lost 
 * password variants, returning a custom string if a match is found.
 *
 * @param string $translated_text The translated text generated by WordPress.
 * @param string $text            The original unmodified string.
 * @param string $domain          The text domain associated with the string.
 * @return string                 The newly defined text or the original translated text.
 */
function dd_pmpro_custom_lost_password_text( $translated_text, $text, $domain ) {
    // Strictly target the PMPro text domain to prevent global overrides.
    if ( 'paid-memberships-pro' === $domain ) {
        
        // Match common PMPro lost password strings. 
        // Note: Exact string matching is required for gettext to work.
        if ( 'Forgot Password?' === $text || 'Lost Password?' === $text || 'Lost your password?' === $text ) {
            
            // Define the new custom text here.
            $translated_text = esc_html__( 'Reset password', 'paid-memberships-pro' ); 
        }
    }

    return $translated_text;
}
add_filter( 'gettext', 'dd_pmpro_custom_lost_password_text', 20, 3 );

/**
 * Appends the current user's membership level slug to the body class array.
 * * This function hooks into 'body_class' to inject custom CSS classes based on 
 * the current user's authentication status and membership tier. It checks for 
 * Paid Memberships Pro, WooCommerce Memberships, and falls back to standard 
 * WP roles to ensure a CSS-friendly slug is always generated.
 *
 * @param array $classes An array of existing body classes generated by WordPress.
 * @return array The filtered array of body classes with membership slugs appended.
 */
function dd_append_membership_level_body_class( $classes ) {
    
    // Handle unauthenticated users immediately to reduce processing overhead
    if ( ! is_user_logged_in() ) {
        $classes[] = 'membership-guest';
        return $classes;
    }

    $user_id = get_current_user_id();
    $membership_slugs = array();

    // 1. Paid Memberships Pro (PMPro) Integration
    if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
        $level = pmpro_getMembershipLevelForUser( $user_id );
        if ( ! empty( $level ) && isset( $level->name ) ) {
            // Converts the string (e.g., "Gold Tier") to a slug (e.g., "pmpro-level-gold-tier")
            $membership_slugs[] = 'pmpro-level-' . sanitize_title( $level->name );
            $membership_slugs[] = 'pmpro-level-' . sanitize_title( $level->id );
        }
    }
 

    // 2. Fallback: Standard WordPress User Role
    // If no membership plugin data is found, utilize the assigned WP roles
    if ( empty( $membership_slugs ) ) {
        $user = get_userdata( $user_id );
        if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
            foreach ( $user->roles as $role ) {
                $membership_slugs[] = 'role-' . sanitize_title( $role );
            }
        }
    }

    // Merge new classes with existing ones, ensuring no duplicates are added
    if ( ! empty( $membership_slugs ) ) {
        $classes = array_merge( $classes, array_unique( $membership_slugs ) );
    }

    return $classes;
}
add_filter( 'body_class', 'dd_append_membership_level_body_class' );



/**
 * Overrides the default logout redirect destination with the PMPro login page URL.
 * Includes a validation check to ensure PMPro is active before attempting to retrieve its routing.
 *
 * @param string  $redirect_to           The default redirect destination URL.
 * @param string  $requested_redirect_to The requested redirect destination URL passed as a parameter.
 * @param WP_User $user                  The WP_User object for the user that is logging out.
 * @return string                        The customized PMPro login URL or the fallback default URL.
 */
function dd_custom_pmpro_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
    
    // Validate PMPro core functionality exists to prevent fatal errors if the plugin is deactivated
    if ( function_exists( 'pmpro_url' ) ) {
        
        // Retrieve the designated PMPro login page URL via the core API
        $pmpro_login_url = pmpro_url( 'login' );
        
        // Ensure a valid URL was returned before applying the redirect override
        if ( ! empty( $pmpro_login_url ) ) {
            return $pmpro_login_url;
        }
    }

    // Fall back to default WordPress routing if PMPro is unavailable or the URL is empty
    return $redirect_to;
}

// Hook into the logout_redirect filter with a standard priority of 10, accepting 3 arguments
add_filter( 'logout_redirect', 'dd_custom_pmpro_logout_redirect', 10, 3 );




/**
 * Class DD_Pricing_Table_Manager
 * * Handles the registration of the ACF options page and the corresponding field groups
 * required to manage dynamic pricing tables with standard and custom plans.
 */
class DD_Pricing_Table_Manager {

	/**
	 * Constructor.
	 * * Initializes the class and hooks into ACF actions to setup the options page
	 * and custom fields early in the lifecycle.
	 */
	public function __construct() {
		add_action( 'acf/init', [ $this, 'register_options_page' ] );
		add_action( 'acf/init', [ $this, 'register_field_groups' ] );
	}

	/**
	 * Registers the ACF Options Page for Pricing Table configurations.
	 * * Creates a dedicated top-level menu item for managing both the PMPro mapped
	 * levels and any statically defined custom plans.
	 * * @return void
	 */
	public function register_options_page() {
		if ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_page( [
				'page_title'      => 'Pricing Table Settings',
				'menu_title'      => 'Pricing Settings',
				'menu_slug'       => 'dd-pricing-settings',
				'capability'      => 'manage_options',
				'icon_url'        => 'dashicons-tag',
				'redirect'        => false,
				'update_button'   => 'Save Pricing Data',
			] );
		}
	}

	/**
	 * Registers the ACF Field Groups for the Pricing Table.
	 * * Implements a tabbed interface separating 'Standard PMPro Plans' from 'Custom Plans'.
	 * Incorporates a repeater field for custom plans, ensuring duplication, reordering,
	 * collapsing, and deletion capabilities are natively supported.
	 * * @return void
	 */
	public function register_field_groups() {
		if ( function_exists( 'acf_add_local_field_group' ) ) {

			acf_add_local_field_group( [
				'key'                   => 'group_dd_pricing_table',
				'title'                 => 'Pricing Table Configuration',
				'fields'                => [
					// Tab 1: Standard PMPro Plans
					[
						'key'               => 'field_dd_tab_standard',
						'label'             => 'Standard PMPro Plans',
						'name'              => '',
						'type'              => 'tab',
						'placement'         => 'top',
					],
					[
						'key'               => 'field_dd_monthly_group_id',
						'label'             => 'Monthly PMPro Group ID',
						'name'              => 'dd_monthly_group_id',
						'type'              => 'number',
						'instructions'      => 'Map the PMPro Group ID for Monthly plans (e.g., 2 from your setup).',
						'required'          => 0,
					],
					[
						'key'               => 'field_dd_annual_group_id',
						'label'             => 'Annual PMPro Group ID',
						'name'              => 'dd_annual_group_id',
						'type'              => 'number',
						'instructions'      => 'Map the PMPro Group ID for Annual plans (e.g., 3 from your setup).',
						'required'          => 0,
					],

					// Tab 2: Custom Plans (Scale)
					[
						'key'               => 'field_dd_tab_custom',
						'label'             => 'Custom Plans (e.g., Scale)',
						'name'              => '',
						'type'              => 'tab',
						'placement'         => 'top',
					],
					[
						'key'               => 'field_dd_custom_plans',
						'label'             => 'Custom Plan Columns',
						'name'              => 'dd_custom_plans',
						'type'              => 'repeater',
						'instructions'      => 'Add custom plans that do not map directly to PMPro levels. Drag to reorder. Use the right-side icons to duplicate or delete.',
						'layout'            => 'block',
						'collapsed'         => 'field_dd_custom_heading', // Enables component collapsing based on the heading text.
						'button_label'      => 'Add Custom Plan',
						'sub_fields'        => [
							[
								'key'           => 'field_dd_custom_heading',
								'label'         => 'Plan Heading',
								'name'          => 'heading',
								'type'          => 'text',
								'required'      => 1,
							],
							[
								'key'           => 'field_dd_custom_description',
								'label'         => 'Plan Description',
								'name'          => 'description',
								'type'          => 'textarea',
								'rows'          => 3,
								'required'      => 1,
							],
							[
								'key'           => 'field_dd_custom_btn_text',
								'label'         => 'Button Text',
								'name'          => 'button_text',
								'type'          => 'text',
								'default_value' => 'ENQUIRE NOW',
								'required'      => 1,
							],
							[
								'key'           => 'field_dd_custom_btn_url',
								'label'         => 'Button URL',
								'name'          => 'button_url',
								'type'          => 'url',
								'required'      => 1,
							],
						],
					],
				],
				'location'              => [
					[
						[
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'dd-pricing-settings',
						],
					],
				],
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'seamless',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
			] );
		}
	}
}

// Instantiate the manager class to boot the plugin routines.
new DD_Pricing_Table_Manager();