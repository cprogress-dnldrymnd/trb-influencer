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
 * Transforms the myCred Buy Credits checkout into the clean, influencer-style layout.
 * Parses the dynamic credits/cost table, securely hides it, builds the Summary block ABOVE 
 * the payment info, injects the user avatar, and neutralizes all default myCred CSS bloat.
 * Updated to support both Stripe and standard Bank Transfer/Manual gateways.
 *
 * @return void
 */
function dd_influencer_style_mycred_checkout()
{
    // 1.Check if user is logged in
    if (!is_page(4191)) {
        return;
    }
    // Execute your custom avatar shortcode safely
    $avatar_html = do_shortcode('[user_avatar]');
?>
    <style>
        #buycred-checkout-wrapper.open #checkout-box h2.gateway-title,
        #buycred-checkout-page h2.gateway-title {
            font-size: 1.5rem;
            border-bottom: 1px solid #e5e5e5;
            padding-bottom: 10px;
            margin-left: 10px;
            margin-right: 10px;
            font-family: 'Inter';
        }

        #buycred-checkout-step1 hr {
            display: none;
        }

        #checkout-action-button {
            color: #fff !important;
            border-color: var( --e-global-color-primary ) !important;
        }
        /* Broadened selector to catch non-Stripe gateways */
        .mycred-stripe-payment-main.mycred-stripe-payment-main * {
            font-family: Inter !important;
            color: inherit !important;
            text-align: left;
            font-size: inherit !important;
        }

        .mycred-stripe-payment-main+hr,
        #buycred-checkout-form+hr {
            display: none !important;
        }

        /* Nuke all myCred default boxed styling, including standard checkout bodies */
        #buycred-checkout-page,
        #buycred-checkout-page .checkout-body,
        .mycred-stripe-payment-main,
        .mycred-stripe-payment-wrapper,
        .mycred_buy_form_stripe,
        .mycred-stripe-payment-form,
        #buycred-checkout-form {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 auto !important;
            max-width: 600px !important;
        }

        /* Hide the default table, warnings, close buttons, old headers, and the Personal Info Section */
        #buycred-checkout-form table,
        #buycred-checkout-form .warning,
        #buycred-checkout-form .checkout-header,
        .mycred-stripe-payment-form-header,
        .mycred_stripe_close_btn,
        #buycred-checkout-form .cancel,
        .mycred_buy_section_1 {
            display: none !important;
        }

        /* Format myCred's Payment Info section to match PMPro */
        .mycred_buy_section_2,
        .mycred-bank-transfer-instructions {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            margin-top: 30px !important;
            text-align: left !important;
        }

        .mycred_buy_section_2.mycred_buy_section_2.mycred_buy_section_2 h2,
        #buycred-checkout-form h2 {
            font-size: 20px !important;
            font-weight: 700 !important;
            border: none !important;
            margin-bottom: 15px !important;
            padding-bottom: 0 !important;
            color: #000;
            text-align: left !important;
        }

        /* Base Form Font setup */
        #buycred-checkout-form {
            max-width: 600px;
            margin: 0 auto;
            font-family: Inter;
            color: #000;
        }

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
            font-family: Inter;
        }

        .checkout-order {
            padding: 20px 10px;
        }

        .mycred-stripe-payment-form p,
        #buycred-checkout-form p {
            text-align: left !important;
        }

        .dd-checkout-title-row a {
            color: var(--e-global-color-accent);
            text-decoration: underline;
            font-weight: 500;
            font-size: 14px;
            transition: color 0.2s ease;
        }

        .dd-checkout-title-row a:hover {
            color: #000;
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

        body {
            background-color: #fff;
        }

        /* influencer Card Styles */
        #dd-influencer-summary {
            margin-top: 20px !important;
            margin-bottom: 20px !important;
            border-bottom: 1px solid #e5e5e5 !important;
            padding-bottom: 20px !important;
        }

        .infl-summary-card.infl-summary-card {
            background: transparent;
            font-size: 1rem !important;
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
            justify-content: flex-end;
            margin-right: 15px;
            color: #fff;
        }

        .infl-icon img {
            width: 30px;
            height: 30px;
        }

        .infl-plan-info {
            flex-grow: 1;
        }

        .infl-plan-info h4 {
            margin: 0 0 2px 0 !important;
            font-size: 16px !important;
            font-weight: 700 !important;
        }

        .infl-plan-info.infl-plan-info.infl-plan-info span {
            font-size: 14px !important;
            color: #b3b3b3 !important;
        }

        .infl-price-info.infl-price-info.infl-price-info {
            text-align: right;
        }

        .infl-price-info h4 {
            margin: 0 0 2px 0 !important;
            font-size: 16px !important;
            font-weight: 700 !important;
        }

        .infl-price-info span {
            font-size: 14px !important;
            color: #b3b3b3 !important;
        }

        /* Timeline Styles */
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
            z-index: 2;
        }

        .infl-content p {
            margin: 0 0 2px 0 !important;
            font-size: 15px;
            font-weight: 500;
        }

        .infl-content.infl-content.infl-content span {
            font-size: 14px !important;
            color: #b3b3b3 !important;
        }

        /* Bullet Points */
        .infl-bullets.infl-bullets.infl-bullets {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 13px !important;
            color: #6a6a6a !important;
            line-height: 1.5 !important;
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
            color: #6a6a6a;
        }

        /* Submit Button Overlay (Expanded for generic inputs) */
        .mycred-stripe-buy-button.mycred-stripe-buy-button.mycred-stripe-buy-button,
        #buycred-checkout-form input[type="submit"] {
            background-color: var(--e-global-color-primary) !important;
            color: #fff !important;
            border-radius: 5px 5px 5px 5px !important;
            padding: 16px 30px !important;
            font-size: 16px !important;
            font-weight: 500 !important;
            border: none !important;
            width: 100% !important;
            text-transform: none !important;
            transition: transform 0.2s ease, background-color 0.2s ease;
            margin-top: 20px !important;
            cursor: pointer;
            text-align: center !important;
            height: auto !important;
            min-height: 65px;
            display: block !important;
        }

        .mycred-stripe-buy-button:hover,
        #buycred-checkout-form input[type="submit"]:hover {
            background-color: #1fdf64 !important;
            transform: scale(1.02);
        }

        .mycred-stripe-payment-main section,
        #buycred-checkout-form section {
            margin-top: 0 !important;
        }

        .mycred_buy_section_2 h2 {
            font-size: 24px !important;
            text-transform: capitalize !important;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof jQuery === 'undefined') return;
            var $ = jQuery;

            // Use an interval to wait for the myCred form to finish loading in the DOM
            var pollDOM = setInterval(function() {
                var $form = $('#buycred-checkout-form');

                // Only proceed once the core wrapper exists (Removed strict Stripe requirement)
                if ($form.length > 0) {
                    clearInterval(pollDOM);

                    // 1. Clear out any existing custom headers to prevent duplication
                    $form.find('.dd-influencer-header, .dd-checkout-title-row, #dd-influencer-summary').remove();

                    // 2. Safely grab myCred's Cancel URL so the "Change amount" link actually drops the pending order
                    var cancelUrl = $form.find('.cancel a').attr('href') || '/buy-credit/';

                    var avatarHtml = <?php echo wp_json_encode($avatar_html); ?>;

                    // 3. Inject the clean Top Header
                    var headerHtml = '<div class="dd-influencer-header">' +
                        '<div class="dd-influencer-logo"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="134.712" height="68.251" viewBox="0 0 134.712 68.251"><defs><clipPath id="clip-path"><rect id="Rectangle_9" data-name="Rectangle 9" width="134.712" height="68.251" fill="currentColor"/></clipPath></defs><g id="Group_9" data-name="Group 9" transform="translate(0 0)"><g id="Group_8" data-name="Group 8" transform="translate(0 0)" clip-path="url(#clip-path)"><path id="Path_6" data-name="Path 6" d="M7.342,45.71H6.154V54.9H2.659V33.234H7.2c4.893,0,8.108,2.306,8.108,6.116a5.3,5.3,0,0,1-3.7,5.067c2.866,1.083,4.753,7.758,8.807,7.758l-.7,2.936c-6.92,0-7.164-9.4-12.372-9.4m.21-9.75h-1.4v7.059h1.5c2.481,0,4.194-1.294,4.194-3.6,0-2.2-1.782-3.459-4.3-3.459" transform="translate(-1.191 -14.885)" fill="currentColor"/><path id="Path_7" data-name="Path 7" d="M76.659,54.929H71.522V33.3h4.264c5,0,8.387,1.572,8.387,5.452A3.966,3.966,0,0,1,81.1,42.8c3.075.489,4.962,2.271,4.962,5.731,0,4.683-3.6,6.4-9.4,6.4M76.17,35.988H75.017v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422H75.017v7.653h2.551c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-32.034 -14.914)" fill="currentColor"/><path id="Path_8" data-name="Path 8" d="M118.811,54.929h-5.137V33.3h4.264c5,0,8.387,1.572,8.387,5.452A3.966,3.966,0,0,1,123.25,42.8c3.075.489,4.963,2.271,4.963,5.731,0,4.683-3.6,6.4-9.4,6.4m-.489-18.941h-1.153v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422h-2.551v7.653h2.551c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-50.914 -14.914)" fill="currentColor"/><path id="Path_9" data-name="Path 9" d="M165.111,54.926c-6.221,0-11.182-4.055-11.182-11.149,0-7.059,4.961-11.113,11.182-11.113S176.3,36.718,176.3,43.812c0,7.059-4.963,11.114-11.184,11.114m0-19.361c-4.158,0-7.583,3.04-7.583,8.213,0,5.278,3.425,8.213,7.583,8.213s7.584-2.936,7.584-8.178c0-5.207-3.425-8.247-7.584-8.247" transform="translate(-68.944 -14.63)" fill="currentColor"/><path id="Path_10" data-name="Path 10" d="M213.754,39.84V54.448h-3.5V32.222h.489l14.643,15.132V32.781h3.494V54.9H228.4Z" transform="translate(-94.174 -14.432)" fill="currentColor"/><path id="Path_11" data-name="Path 11" d="M7.8,105.564H2.659V83.932H6.923c5,0,8.387,1.572,8.387,5.452a3.966,3.966,0,0,1-3.075,4.054c3.075.489,4.962,2.271,4.962,5.731,0,4.683-3.6,6.4-9.4,6.4M7.307,86.623H6.154v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422H6.154V102.7H8.705c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-1.191 -37.593)" fill="currentColor"/><path id="Path_12" data-name="Path 12" d="M54.1,105.56c-6.221,0-11.183-4.054-11.183-11.148,0-7.059,4.962-11.113,11.183-11.113s11.183,4.054,11.183,11.148c0,7.059-4.962,11.113-11.183,11.113m0-19.361c-4.158,0-7.583,3.04-7.583,8.213,0,5.278,3.425,8.213,7.583,8.213s7.583-2.936,7.583-8.178c0-5.207-3.424-8.247-7.583-8.247" transform="translate(-19.22 -37.309)" fill="currentColor"/><path id="Path_13" data-name="Path 13" d="M97.3,105.536H93.421l7.933-12.686-5.7-8.982h4.019l3.53,6.326,3.53-6.326h4.019l-5.661,8.982,7.9,12.686h-3.88l-5.905-10.169Z" transform="translate(-41.843 -37.564)" fill="currentColor"/><path id="Path_14" data-name="Path 14" d="M141.863,120.176a2.048,2.048,0,0,1-2.237-2.062,2,2,0,0,1,2.237-2.027,2.051,2.051,0,0,1,2.306,2.062,2.082,2.082,0,0,1-2.306,2.027" transform="translate(-62.538 -51.995)" fill="currentColor"/><path id="Path_15" data-name="Path 15" d="M4.717,1.362,2.708,10.83H.978L2.97,1.362H0L.612,0h7.2L7.529,1.362Z" transform="translate(0 0)" fill="currentColor"/><path id="Path_16" data-name="Path 16" d="M24.528,10.83l1.118-5.275h-5.66L18.868,10.83H17.121L19.41,0h1.747l-.891,4.192h5.66L26.816,0h1.765L26.275,10.83Z" transform="translate(-7.669 0)" fill="currentColor"/><path id="Path_17" data-name="Path 17" d="M46.163,1.362l-.611,2.9h3.371l-.279,1.362H45.255l-.8,3.826H50.3l-.612,1.38H42.408L44.7,0h6.166l-.3,1.362Z" transform="translate(-18.994 0)" fill="currentColor"/><path id="Path_18" data-name="Path 18" d="M47.471,37.22V54.977h3.495V33.4Z" transform="translate(-21.262 -14.961)" fill="currentColor"/></g></g></svg></div>' +
                        '<div class="dd-avatar-wrapper">' + (avatarHtml ? avatarHtml : '') + '</div>' +
                        '</div>' +
                        '<div class="dd-checkout-title-row">' +
                        '<h2>Checkout</h2>' +
                        '<a href="' + cancelUrl + '">Change amount</a>' +
                        '</div>';

                    $form.prepend(headerHtml);

                    // 4. Extract Dynamic Data from myCred Table
                    var creditsAmount = "0";
                    var costAmount = "$0.00";

                    var $credCol = $form.find('td.item:contains("Credits")').next('td');
                    if ($credCol.length) {
                        creditsAmount = $credCol.text().trim();
                    }

                    var $costCol = $form.find('tr.total td.cost');
                    if ($costCol.length) {
                        costAmount = $costCol.text().trim();
                    }

                    // 5. Build myCred Summary HTML
                    var summaryHtml = `
                    <div id="dd-influencer-summary">
                        <div class="infl-summary-card">
                            <div class="infl-header-row">
                                <div class="infl-icon">
                                   <img src="/wp-content/uploads/2026/02/cropped-cropped-cropped-favicon-192x192-1.png" alt="Credits Icon">
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
                                    <div class="infl-dot filled"></div>
                                    <div class="infl-content">
                                        <p><strong>Total:</strong> ${costAmount}</p>
                                        <span>${creditsAmount} Credits</span>
                                    </div>
                                </div>
                            </div>
                            <ul class="infl-bullets">
                                <li>Instantly add ${creditsAmount} credits to your account balance.</li>
                                <li>Use credits to unlock premium outreach tools and campaigns.</li>
                                <li>All purchases are secure, non-recurring, and final.</li>
                            </ul>
                        </div>
                    </div>`;

                    // 6. Prevent Validation Errors & Inject Summary Block securely
                    var $section1 = $form.find('.mycred_buy_section_1');
                    if ($section1.length > 0) {
                        $section1.find('input').removeAttr('required');
                        $section1.before(summaryHtml);
                    } else {
                        // Gateway-agnostic fallback: Append right after the newly injected header
                        $form.find('.dd-checkout-title-row').after(summaryHtml);
                    }

                    // 7. Update Button Text for both Button tags (Stripe) and Input tags (Bank Transfer)
                    var $btn = $form.find('.mycred-stripe-buy-button, input[type="submit"]');
                    if ($btn.length) {
                        var btnText = 'Pay ' + costAmount;
                        // Input elements use .val(), generic buttons use .text()
                        if ($btn.is('input')) {
                            $btn.val(btnText);
                        } else {
                            $btn.text(btnText);
                        }
                    }
                }
            }, 100);
        });
    </script>
<?php
}
add_action('wp_footer', 'dd_influencer_style_mycred_checkout', 55);
