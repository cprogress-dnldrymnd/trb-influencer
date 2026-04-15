<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Triggers a custom myCred notification popup for a specific user.
 *
 * This function utilizes the built-in mycred_add_new_notice() function to inject 
 * a custom message into the user's notification transient queue. The myCred 
 * Notifications add-on will render this message on the subsequent page load.
 *
 * @param int    $user_id The ID of the user to receive the notification.
 * @param string $message The HTML/text content of the notification.
 * @param int    $life    The lifespan of the transient in days. Default is 1.
 * @return bool           True on success, false on failure or if the dependency is missing.
 */
function dd_trigger_mycred_notice($user_id, $message, $life = 1)
{
    // Validate required parameters and ensure the myCred Notifications module is active.
    if (empty($user_id) || empty($message) || ! function_exists('mycred_add_new_notice')) {
        return false;
    }

    // Construct the notice array structure expected by myCred.
    $notice = array(
        'user_id' => absint($user_id),
        'message' => wp_kses_post($message),
    );

    // Push the notice to the transient queue.
    mycred_add_new_notice($notice, $life);

    return true;
}

/**
 * Deducts myCRED points from the currently logged-in user.
 *
 * @param float|int $points    The amount of points to deduct.
 * @param string    $log_entry The log entry description visible in the user's history.
 * @param string    $reference A unique reference slug for this transaction type.
 * @return bool|string         Returns true/string on success, or false if it fails (e.g., user logged out).
 */
function deduct_points_from_current_user($points, $log_entry = 'Points deducted', $reference = 'custom_deduction')
{

    // Check if the myCRED plugin API is loaded
    if (! function_exists('mycred_subtract')) {
        return false;
    }

    // Retrieve the ID of the current logged-in user
    $user_id = get_current_user_id();

    // Abort if no user is logged in
    if (! $user_id) {
        return false;
    }

    // Ensure the points value is a positive number (mycred_subtract requires positive inputs)
    $amount_to_deduct = abs((float) $points);

    // Execute the deduction
    $success = mycred_subtract(
        $reference,         // The reference ID 
        $user_id,           // The user ID
        $amount_to_deduct,  // The amount to deduct
        $log_entry          // The log template
    );

    return $success;
}

/**
 * Retrieves the remaining (current) myCred points balance for the currently logged-in user.
 *
 * This function utilizes standard WordPress core functions to verify the user state
 * and the myCred API to fetch the active balance. It specifically targets the 
 * remaining points available for use, rather than the historical total.
 * * @param string $point_type_key (Optional) The specific point type key to query in a multi-point setup. 
 * Defaults to 'mycred_default' (the standard point type).
 * @return float|int|bool The user's current remaining balance as a raw number. 
 * Returns false if the user is not logged in, has an invalid ID, 
 * or is explicitly excluded from using myCred.
 */
function get_current_user_remaining_mycred_balance($point_type_key = 'mycred_default')
{
    // Prevent processing overhead if the session does not belong to an authenticated user
    if (! is_user_logged_in()) {
        return false;
    }

    // Retrieve the active user's WordPress ID
    $user_id = get_current_user_id();

    // Query the myCred API for the current unformatted balance (remaining points)
    $remaining_balance = mycred_get_users_balance($user_id, $point_type_key);

    return $remaining_balance;
}


/**
 * Transforms the myCred Buy Credits checkout into a clean influencer-style layout.
 * Supports Stripe and Bank Transfer gateways.
 *
 * @return void
 */
function dd_influencer_style_mycred_checkout()
{
    // Limit to the specific Buy Credit page
    if (!is_page(4191)) {
        return;
    }

    $avatar_html = do_shortcode('[user_avatar]');
?>
    <style>
        /* Base Form and Layout setup */
        #buycred-checkout-page,
        .mycred-stripe-payment-main,
        .buycred-gateway-bank-transfer {
            font-family: Inter !important;
            color: #000 !important;
            text-align: left;
        }

        /* Nuke all myCred default boxed styling and backgrounds */
        #buycred-checkout-page,
        #buycred-checkout-page .checkout-body,
        .mycred-stripe-payment-main,
        .mycred-stripe-payment-wrapper,
        .mycred_buy_form_stripe,
        .mycred-stripe-payment-form,
        .buycred-gateway-bank-transfer {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 auto !important;
            max-width: 600px !important;
        }

        /* Generic cleanup for elements common across gateways */
        #buycred-checkout-form table,
        #buycred-checkout-form .warning,
        #buycred-checkout-form .checkout-header,
        .mycred-stripe-payment-form-header,
        .mycred_stripe_close_btn,
        #buycred-checkout-form .cancel,
        .mycred_buy_section_1,
        .mycred-stripe-payment-main+hr,
        #buycred-checkout-page h1 {
            display: none !important;
        }

        /* Section 2 (Payment Details) Header styling */
        .mycred_buy_section_2,
        .buycred-gateway-bank-transfer {
            margin-top: 30px !important;
        }

        .mycred_buy_section_2 h2,
        .buycred-gateway-bank-transfer h2 {
            font-size: 24px !important;
            font-weight: 700 !important;
            border: none !important;
            margin-bottom: 15px !important;
            padding-bottom: 0 !important;
            color: #000 !important;
            text-transform: capitalize !important;
        }

        /* Custom Header Components */
        .dd-influencer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 15px;
        }

        .dd-checkout-title-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 20px;
            border-bottom: 1px solid #e5e5e5;
            padding-bottom: 20px;
        }

        .dd-checkout-title-row h2 {
            font-size: clamp(20px, 1.5vw, 32px) !important;
            font-weight: 700 !important;
            margin: 0 !important;
            color: #000;
            letter-spacing: -0.5px;
        }

        .dd-checkout-title-row a {
            color: var(--e-global-color-accent);
            text-decoration: underline;
            font-weight: 500;
            font-size: 14px;
        }

        .dd-avatar-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            background: #eee;
            border: 2px solid #ddd;
        }

        .dd-avatar-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Summary Card Styling */
        #dd-influencer-summary {
            margin-bottom: 20px !important;
            border-bottom: 1px solid #e5e5e5 !important;
            padding-bottom: 20px !important;
        }

        .infl-header-row {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .infl-icon {
            width: 50px;
            height: 50px;
            background: var(--e-global-color-secondary);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .infl-icon img {
            width: 30px;
            height: 30px;
        }

        .infl-plan-info h4,
        .infl-price-info h4 {
            margin: 0 !important;
            font-size: 16px !important;
            font-weight: 700 !important;
        }

        .infl-plan-info span,
        .infl-price-info span {
            font-size: 14px !important;
            color: #b3b3b3 !important;
        }

        .infl-price-info {
            text-align: right;
            flex-grow: 1;
        }

        /* Timeline & Bullets */
        .infl-timeline {
            position: relative;
            padding-left: 15px;
            margin-bottom: 20px;
        }

        .infl-timeline::before {
            content: '';
            position: absolute;
            left: 19px;
            top: 10px;
            bottom: 10px;
            width: 1px;
            background: #000;
        }

        .infl-timeline-item {
            position: relative;
            padding-left: 20px;
            margin-bottom: 20px;
        }

        .infl-dot {
            position: absolute;
            left: 0;
            top: 6px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #000;
        }

        .infl-bullets {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 13px;
            color: #6a6a6a;
        }

        .infl-bullets li {
            position: relative;
            padding-left: 15px;
            margin-bottom: 6px;
        }

        .infl-bullets li::before {
            content: '•';
            position: absolute;
            left: 0;
            top: 0;
        }

        /* Global Button Styling for Gateways */
        .mycred-stripe-buy-button,
        #buycred-checkout-form input[type="submit"],
        .buycred-gateway-bank-transfer input[type="submit"] {
            background-color: var(--e-global-color-primary) !important;
            color: #fff !important;
            border-radius: 5px !important;
            padding: 16px 30px !important;
            font-size: 16px !important;
            font-weight: 500 !important;
            border: none !important;
            width: 100% !important;
            transition: all 0.2s ease;
            margin-top: 20px !important;
            cursor: pointer;
            min-height: 65px;
        }

        .mycred-stripe-buy-button:hover,
        #buycred-checkout-form input[type="submit"]:hover {
            background-color: #1fdf64 !important;
            transform: scale(1.01);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof jQuery === 'undefined') return;
            var $ = jQuery;

            var pollDOM = setInterval(function() {
                var $form = $('#buycred-checkout-form');
                // Target any active gateway container
                var $gatewayContainer = $('.mycred-stripe-payment-form, .buycred-gateway-bank-transfer');

                if ($form.length > 0) {
                    clearInterval(pollDOM);

                    $form.find('.dd-influencer-header, .dd-checkout-title-row, #dd-influencer-summary').remove();

                    var cancelUrl = $form.find('.cancel a').attr('href') || '/buy-credit/';
                    var avatarHtml = <?php echo wp_json_encode($avatar_html); ?>;

                    var headerHtml = `
                        <div class="dd-influencer-header">
                            <div class="dd-influencer-logo">
                                <svg width="135" height="68" viewBox="0 0 135 68">
                                    <path d="M7.342,45.71H6.154V54.9H2.659V33.234H7.2c4.893,0,8.108,2.306,8.108,6.116..." fill="currentColor"/>
                                </svg>
                            </div>
                            <div class="dd-avatar-wrapper">${avatarHtml ? avatarHtml : ''}</div>
                        </div>
                        <div class="dd-checkout-title-row">
                            <h2>Checkout</h2>
                            <a href="${cancelUrl}">Change amount</a>
                        </div>`;

                    $form.prepend(headerHtml);

                    // Extract Data
                    var creditsAmount = $form.find('td.item:contains("Credits")').next('td').text().trim() || "0";
                    var costAmount = $form.find('tr.total td.cost').text().trim() || "$0.00";

                    var summaryHtml = `
                    <div id="dd-influencer-summary">
                        <div class="infl-header-row">
                            <div class="infl-icon">
                               <img src="/wp-content/uploads/2026/02/cropped-cropped-cropped-favicon-192x192-1.png">
                            </div>
                            <div class="infl-plan-info">
                                <h4>Credits Package</h4>
                                <span>${creditsAmount} Credits</span>
                            </div>
                            <div class="infl-price-info">
                                <h4>${costAmount}</h4>
                                <span>One-time</span>
                            </div>
                        </div>
                        <div class="infl-timeline">
                            <div class="infl-timeline-item">
                                <div class="infl-dot"></div>
                                <div class="infl-content">
                                    <p><strong>Total:</strong> ${costAmount}</p>
                                    <span>${creditsAmount} Credits</span>
                                </div>
                            </div>
                        </div>
                        <ul class="infl-bullets">
                            <li>Instantly add ${creditsAmount} credits to your account balance.</li>
                            <li>Use credits to unlock premium outreach tools.</li>
                            <li>All purchases are secure and final.</li>
                        </ul>
                    </div>`;

                    // Injection Logic
                    var $section1 = $form.find('.mycred_buy_section_1');
                    if ($section1.length > 0) {
                        $section1.find('input').removeAttr('required');
                        $section1.before(summaryHtml);
                    } else {
                        // For Bank Transfer or Gateways without section_1
                        if ($gatewayContainer.length) {
                            $gatewayContainer.before(summaryHtml);
                        } else {
                            $form.find('h2:first').before(summaryHtml);
                        }
                    }

                    // Update Button Text
                    var $btn = $form.find('input[type="submit"], .mycred-stripe-buy-button');
                    if ($btn.length && $btn.val() !== 'Pay ' + costAmount) {
                        $btn.is('input') ? $btn.val('Pay ' + costAmount) : $btn.text('Pay ' + costAmount);
                    }
                }
            }, 100);
        });
    </script>
<?php
}
add_action('wp_footer', 'dd_influencer_style_mycred_checkout', 55);