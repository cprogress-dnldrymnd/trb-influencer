<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}
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
 * Adjusts the profile_start_date and initial_payment for the new membership level during checkout.
 * This ensures the new billing cycle appends to the existing subscription's next payment date appropriately.
 *
 * @param object $level The membership level object being processed at checkout.
 * @return object The modified membership level object.
 */
function dd_pmpro_append_billing_cycle_on_switch( $level ) {
    // Ensure the user is authenticated and the level has recurring billing configured.
    if ( ! is_user_logged_in() || empty( $level ) || empty( $level->billing_amount ) ) {
        return $level;
    }

    $user_id = get_current_user_id();
    $old_level = pmpro_getMembershipLevelForUser( $user_id );

    // Abort if the user lacks an active subscription or is attempting to checkout for the exact same level.
    if ( empty( $old_level ) || $old_level->id == $level->id ) {
        return $level;
    }

    // Retrieve the UNIX timestamp for the next scheduled payment of the current active subscription.
    $next_payment_timestamp = pmpro_next_payment( $user_id );

    // If no future payment date exists (e.g., cancelled or expired), rely on default PMPro behavior.
    if ( ! $next_payment_timestamp || $next_payment_timestamp <= current_time( 'timestamp' ) ) {
        return $level;
    }

    // Extract cycle parameters for comparison and calculation.
    $new_cycle_number = ! empty( $level->cycle_number ) ? (int) $level->cycle_number : 1;
    $new_cycle_period = ! empty( $level->cycle_period ) ? $level->cycle_period : 'Month';
    
    $old_cycle_period = ! empty( $old_level->cycle_period ) ? $old_level->cycle_period : 'Month';

    // Determine if this is a shift from Annual to Monthly (Downgrade) or Monthly to Annual (Upgrade).
    if ( $old_cycle_period === 'Year' && $new_cycle_period === 'Month' ) {
        // DOWNGRADE SCENARIO: Annual to Monthly
        // User shouldn't pay today since they already paid for the year. 
        // The new monthly billing should commence exactly when the current annual cycle ends.
        $level->initial_payment = 0;
        $level->profile_start_date = date( "Y-m-d\TH:i:s", $next_payment_timestamp );
    } else {
        // UPGRADE SCENARIO: Monthly to Annual 
        // User pays the initial fee today (covering the new annual cycle). 
        // The next recurring payment pushes out exactly one year from their CURRENT next payment date.
        // E.g., Upgrading on Mar 12. Current next payment: Apr 12 2024. New Start: Apr 12 2025.
        $strtotime_modifier = '+' . $new_cycle_number . ' ' . $new_cycle_period;
        $new_start_timestamp = strtotime( $strtotime_modifier, $next_payment_timestamp );
        
        // Format the date for PMPro gateways (requires Y-m-d\TH:i:s format).
        $level->profile_start_date = date( "Y-m-d\TH:i:s", $new_start_timestamp );
    }

    return $level;
}
add_filter( 'pmpro_checkout_level', 'dd_pmpro_append_billing_cycle_on_switch', 10, 1 );


/**
 * Displays a clarifying notice on the PMPro checkout page before redirecting to Stripe.
 * This informs the user that Stripe's mandatory UI will label their previously paid time as a 'Free Trial'.
 *
 * @global object $pmpro_level The current membership level being processed at checkout.
 * @return void Outputs HTML directly to the checkout page.
 */
/*
function dd_pmpro_checkout_stripe_trial_notice() {
    global $pmpro_level;
    
    // Ensure we are actively on the checkout page processing a level for an authenticated user.
    if ( empty( $pmpro_level ) || ! is_user_logged_in() ) {
        return;
    }

    $user_id = get_current_user_id();
    $old_level = pmpro_getMembershipLevelForUser( $user_id );

    // Abort if there is no active subscription or if the user is renewing the exact same level.
    if ( empty( $old_level ) || $old_level->id == $pmpro_level->id ) {
        return;
    }

    // Retrieve the UNIX timestamp for the existing subscription's next scheduled payment.
    $next_payment_timestamp = pmpro_next_payment( $user_id );

    // If the user has a future payment date (indicating prepaid time remains), inject the warning notice.
    if ( $next_payment_timestamp && $next_payment_timestamp > current_time( 'timestamp' ) ) {
        $formatted_date = date_i18n( get_option( 'date_format' ), $next_payment_timestamp );
        
        echo '<div class="pmpro_message pmpro_alert" style="margin-bottom: 20px; padding: 15px; border-left: 4px solid #ffba00; background-color: #fff9e6;">';
        echo '<strong>Billing Notice:</strong> On the next screen, the payment gateway may display your remaining prepaid time (valid until <strong>' . esc_html( $formatted_date ) . '</strong>) as a "Free Trial". This is a system limitation; it simply represents the time you have already paid for on your current plan. You will not be double-charged.';
        echo '</div>';
    }
}
add_action( 'pmpro_checkout_before_submit_button', 'dd_pmpro_checkout_stripe_trial_notice' );*/

/**
 * Intercepts frontend page loads to handle two specific free-tier redirections:
 * 1. Redirects users completing checkout for the Free Level (15) directly to the pricing page.
 * 2. Forcefully redirects Free members to the pricing page if they access the dashboard template,
 *    while explicitly exempting core PMPro functional pages to prevent lockouts.
 *
 * @return void
 */
function dd_force_free_members_to_upgrade() {
    // 1. Abort if Paid Memberships Pro is not active
    if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
        return;
    }

    // 2. Abort for backend, AJAX, or logged-out users
    if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return;
    }

    global $post, $pmpro_pages;

    // 3. NEW LOGIC: Intercept Free Level (15) Confirmation Page
    // We do this BEFORE the exemption check so it catches the free tier immediately.
    if ( ! empty( $pmpro_pages['confirmation'] ) && is_page( $pmpro_pages['confirmation'] ) ) {
        $level_id = isset( $_GET['pmpro_level'] ) ? intval( $_GET['pmpro_level'] ) : 0;
        
        if ( $level_id === 15 ) {
            $redirect_url = pmpro_url( 'levels' );
            if ( $redirect_url ) {
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }
    }

    // 4. DEFENSE IN DEPTH: Explicitly exempt core PMPro functional pages
    if ( ! empty( $pmpro_pages ) ) {
        // Includes 'account' and 'profile' to the exempt keys
        $exempt_keys = array( 'levels', 'checkout', 'billing', 'cancel', 'confirmation', 'account', 'profile' );
        $exempt_page_ids = array();
        
        foreach ( $exempt_keys as $key ) {
            if ( ! empty( $pmpro_pages[ $key ] ) ) {
                $exempt_page_ids[] = $pmpro_pages[ $key ];
            }
        }
        
        // Exemption A: Is this an exact match for a core PMPro page?
        if ( is_page( $exempt_page_ids ) ) {
            return;
        }

        // Exemption B: Is this a child page of the Membership Account page? (e.g., /membership-account/your-profile/)
        if ( ! empty( $pmpro_pages['account'] ) && isset( $post->post_parent ) && $post->post_parent == $pmpro_pages['account'] ) {
            return;
        }
    }

    // 5. Abort if the current page is NOT using the Dashboard template
    if ( ! is_page_template( 'templates/page-dashboard.php' ) ) {
        return;
    }

    // 6. Define the exact ID of your Free Membership Level
    $free_level_ids = array( 15 ); 

    // 7. Evaluate if the current user possesses the free level
    if ( pmpro_hasMembershipLevel( $free_level_ids ) ) {
        
        // Retrieve the dynamic URL for the PMPro Levels/Pricing page
        $redirect_url = pmpro_url( 'levels' );
        
        // Execute the redirect
        if ( $redirect_url ) {
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }
}
add_action( 'template_redirect', 'dd_force_free_members_to_upgrade' );


/**
 * Transforms the PMPro Checkout into a cleaner, Spotify-style layout.
 * Reorders DOM elements, securely hides the payment plan selector, builds a Summary block, 
 * and injects the user avatar via shortcode.
 *
 * @return void
 */
function dd_spotify_style_pmpro_checkout() {
    global $pmpro_pages;

    // Abort if we are not on the explicit PMPro checkout page
    if ( empty( $pmpro_pages['checkout'] ) || ! is_page( $pmpro_pages['checkout'] ) ) {
        return;
    }

    // Execute your custom avatar shortcode safely
    $avatar_html = do_shortcode( '[influencer_avatar]' );
    ?>
    <style>
        /* Spotify-style CSS Overrides for PMPro */
        #pmpro_form {
            max-width: 650px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .dd-spotify-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e5e5;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }

        .dd-spotify-header h2 {
            font-size: 24px !important;
            font-weight: 700 !important;
            margin: 0 !important;
            color: #000;
        }

        .dd-avatar-wrapper {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: #eee;
            border: 1px solid #ddd;
        }

        .dd-avatar-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Neutralize the heavy boxed styling of PMPro default sections */
        .pmpro_checkout-section {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin-bottom: 30px !important;
        }

        .pmpro_checkout-section h3, 
        .pmpro_checkout-section h2 {
            font-size: 20px !important;
            font-weight: 700 !important;
            border: none !important;
            margin-bottom: 15px !important;
            padding-bottom: 0 !important;
        }

        /* Style the newly injected Summary section */
        #dd-spotify-summary {
            border-top: 1px solid #e5e5e5 !important;
            padding-top: 30px !important;
            margin-top: 40px !important;
        }

        /* Submit Button (Spotify Green) */
        #pmpro_btn-submit {
            background-color: #1ed760 !important;
            color: #000 !important;
            border-radius: 500px !important;
            padding: 16px 30px !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            border: none !important;
            width: 100% !important;
            text-transform: none !important;
            transition: transform 0.2s ease, background-color 0.2s ease;
        }

        #pmpro_btn-submit:hover {
            background-color: #1fdf64 !important;
            transform: scale(1.02);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof jQuery === 'undefined') return;
            var $ = jQuery;

            // Safely pass the PHP generated shortcode HTML into JavaScript
            var avatarHtml = <?php echo wp_json_encode( $avatar_html ); ?>;

            // 1. Inject the Custom Header at the top of the form
            var headerHtml = '<div class="dd-spotify-header">' +
                             '<h2>Checkout</h2>' +
                             '<div class="dd-avatar-wrapper">' + (avatarHtml ? avatarHtml : '') + '</div>' +
                             '</div>';
            $('#pmpro_form').prepend(headerHtml);

            // 2. Hide Payment Plan Module (It stays in the DOM so the form submits correctly, but is invisible to the user)
            var $paymentPlanWrapper = $('#pmpropp_payment_plans');
            if ($paymentPlanWrapper.length) {
                $paymentPlanWrapper.hide();
                // Find the heading immediately preceding the radio buttons and hide it
                $paymentPlanWrapper.prev('h2, h3, hr').hide();
                // If it's wrapped in a standard PMPro section, hide the wrapper
                $paymentPlanWrapper.closest('.pmpro_checkout-section').hide();
            }

            // Catch any stray headings related to Payment Plans
            $('.pmpro_checkout-section h2, .pmpro_checkout-section h3').each(function() {
                if ($(this).text().indexOf('Payment Plan') !== -1) {
                    $(this).hide();
                    $(this).closest('.pmpro_checkout-section').hide();
                }
            });

            // 3. Build the Summary Section and Reorder the DOM
            var $summarySection = $('<div id="dd-spotify-summary" class="pmpro_checkout-section"><h3>Summary</h3></div>');
            
            // Locate the Payment Information block
            var $paymentFields = $('#pmpro_payment_information_fields').closest('.pmpro_checkout-section');
            if (!$paymentFields.length) $paymentFields = $('#pmpro_payment_information_fields'); // Fallback
            
            // Inject the empty Summary section directly beneath the Payment Information
            if ($paymentFields.length) {
                $paymentFields.after($summarySection);
            } else {
                $('#pmpro_form .pmpro_checkout-section').last().after($summarySection);
            }

            // Pluck the Membership Information block and drop it inside the Summary
            var $memInfo = $('#pmpro_level_cost').closest('.pmpro_checkout-section');
            if (!$memInfo.length) $memInfo = $('#pmpro_level_cost');
            if ($memInfo.length) $summarySection.append($memInfo);

            // Pluck the Account Information block and drop it inside the Summary
            var $accInfo = $('#pmpro_account').closest('.pmpro_checkout-section');
            if (!$accInfo.length) $accInfo = $('#pmpro_account');
            if (!$accInfo.length) $accInfo = $('.pmpro_checkout-section:contains("Account Information")');
            if ($accInfo.length) $summarySection.append($accInfo);
            
        });
    </script>
    <?php
}
add_action( 'wp_footer', 'dd_spotify_style_pmpro_checkout', 50 );