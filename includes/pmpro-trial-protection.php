<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_PMPro_Trial_Protection
 * Description: Implements strict email and Stripe card fingerprint tracking to prevent free trial abuse. 
 * Integrates one-time subscription delay rules, standardizes free-trial detection logic, and 
 * provides a manual opt-out mechanism for bank transfers and returning users.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (!class_exists('DD_PMPro_Trial_Protection')) {

    class DD_PMPro_Trial_Protection
    {
        // Property to trigger the opt-out UI if Stripe validation fails
        private $require_stripe_opt_out = false;

        public function __construct()
        {
            // Initialize custom database table on admin load
            add_action('admin_init', [$this, 'initialize_fingerprint_table']);

            // --- UI ADDITIONS ---
            // Renders the opt-out checkbox and Bank Transfer notice on the checkout form
            add_action('pmpro_checkout_after_tos_fields', [$this, 'render_opt_out_checkbox']);

            // --- DELAY REMOVAL / OVERRIDES ---
            // Removes subscription delay hooks EARLY
            add_filter('pmpro_checkout_level', [$this, 'remove_delay_if_opted_out'], 5, 1);
            
            // Forces the Initial Payment to equal Billing Amount LATE (Bypasses Payment Plans Add-on overrides)
            add_filter('pmpro_checkout_level', [$this, 'force_full_payment_if_opted_out'], 50, 1);
            
            // LAYER 1: Enforce account-level trial logic (Includes your custom Level 15 exception)
            add_filter('pmpro_checkout_level', [$this, 'enforce_one_time_subscription_delay'], 15, 1);

            // --- CHECKOUT VALIDATIONS ---
            // Validates that the user opted out if they chose Bank Transfer
            add_filter('pmpro_registration_checks', [$this, 'validate_bank_transfer_opt_out'], 15, 2);

            // LAYER 2: Advanced Stripe Fingerprint Validation (Processing Level)
            add_filter('pmpro_registration_checks', [$this, 'validate_stripe_fingerprint_before_checkout'], 20, 2);

            // --- LOGGING ---
            // LAYER 3: Bulletproof Fingerprint Logging (Fires when membership is officially granted)
            add_action('pmpro_after_change_membership_level', [$this, 'log_fingerprint_after_level_change'], 10, 3);
        }

        public function initialize_fingerprint_table()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';

            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
                $charset_collate = $wpdb->get_charset_collate();

                $sql = "CREATE TABLE $table_name (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) NOT NULL,
                    email varchar(100) NOT NULL,
                    fingerprint varchar(255) NOT NULL,
                    timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                    PRIMARY KEY  (id),
                    KEY fingerprint (fingerprint)
                ) $charset_collate;";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);
            }
        }

        private function get_pmpro_stripe_api_key()
        {
            $api_key = false;
            $env = get_option('pmpro_gateway_environment', 'sandbox');

            if ($env === 'sandbox') {
                $api_key = get_option('pmpro_stripe_connect_test_access_token');
                if (empty($api_key)) {
                    $api_key = get_option('pmpro_stripe_test_secretkey');
                }
            } else {
                $api_key = get_option('pmpro_stripe_connect_access_token');
                if (empty($api_key)) {
                    $api_key = get_option('pmpro_stripe_secretkey');
                }
            }

            if (empty($api_key)) {
                $candidates = [
                    'pmpro_stripe_connect_test_access_token',
                    'pmpro_stripe_test_secretkey',
                    'pmpro_stripe_connect_access_token',
                    'pmpro_stripe_secretkey'
                ];
                foreach ($candidates as $candidate) {
                    $val = get_option($candidate);
                    if (!empty($val)) {
                        $api_key = $val;
                        break;
                    }
                }
            }

            return !empty($api_key) ? trim($api_key) : false;
        }

        private function get_stripe_fingerprint($token)
        {
            if (empty($token)) {
                return false;
            }

            try {
                if (strpos($token, 'tok_') === 0) {
                    $token_obj = \Stripe\Token::retrieve($token);
                    return $token_obj->card->fingerprint ?? false;
                } elseif (strpos($token, 'pm_') === 0) {
                    $pm_obj = \Stripe\PaymentMethod::retrieve($token);
                    return $pm_obj->card->fingerprint ?? false;
                }
            } catch (Exception $e) {
                error_log('DD PMPro Stripe Fingerprint API Error: ' . $e->getMessage());
            }

            return false;
        }

        private function is_checkout_a_new_free_trial()
        {
            global $pmpro_level;

            if (empty($pmpro_level)) {
                return false;
            }

            if (isset($pmpro_level->initial_payment) && (float)$pmpro_level->initial_payment > 0) {
                return false;
            }

            if (empty($pmpro_level->billing_amount) || (float)$pmpro_level->billing_amount <= 0) {
                return false;
            }

            $user_id = get_current_user_id();
            if ($user_id) {
                global $wpdb;
                $has_recent_paid_order = $wpdb->get_var($wpdb->prepare("
                    SELECT id FROM {$wpdb->prefix}pmpro_membership_orders 
                    WHERE user_id = %d 
                    AND total > 0 
                    AND status IN ('success', 'pending', 'cancelled') 
                    AND timestamp >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                    LIMIT 1
                ", $user_id));

                if ($has_recent_paid_order) {
                    return false;
                }
            }

            return true;
        }

        /**
         * Renders the Opt-Out checkbox and dynamic Bank Transfer notice on the checkout page.
         */
        public function render_opt_out_checkbox()
        {
            // Only show this UI if the current checkout is evaluated as a free trial.
            if (!$this->is_checkout_a_new_free_trial()) {
                return;
            }

            $opt_out = isset($_REQUEST['dd_opt_out_free_trial']) ? '1' : '0';
            
            // Evaluate if Stripe validation recently failed to force the UI to remain open
            $force_stripe_ui = $this->require_stripe_opt_out ? 'true' : 'false';
            ?>
            <div id="dd_trial_opt_out_container" class="pmpro_checkout-field pmpro_checkout-field-checkbox" style="display:none; margin-top: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">
                
                <div id="dd_bank_transfer_notice" style="display:none; margin-bottom: 15px; color: #8a6d3b; background-color: #fcf8e3; padding: 12px; border: 1px solid #faebcc; border-radius: 4px;">
                    <strong>Notice:</strong> If you choose to pay by bank transfer, your order and account will be held pending until we’ve received and confirmed your payment. <strong>The free trial is not available when paying via bank transfer.</strong>
                </div>
                
                <label for="dd_opt_out_free_trial" class="pmpro_clickable" style="display: block; font-weight: bold; cursor: pointer;">
                    <input type="checkbox" id="dd_opt_out_free_trial" name="dd_opt_out_free_trial" value="1" <?php checked($opt_out, '1'); ?> />
                    I understand and agree to opt-out of the free trial to proceed.
                </label>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var forceStripeOptOut = <?php echo $force_stripe_ui; ?>;
                    
                    function dd_check_gateway() {
                        var gateway = $('input[name=gateway]:checked').val();
                        if (!gateway) {
                            gateway = $('#gateway').val();
                        }
                        
                        // 1. Manage Visibility of the Opt-Out Container
                        if (gateway === 'check') {
                            $('#dd_trial_opt_out_container').slideDown();
                            $('#dd_bank_transfer_notice').slideDown();
                        } else if (gateway === 'stripe' && forceStripeOptOut) {
                            // Show checkbox for Stripe if validation failed, but keep bank transfer notice hidden
                            $('#dd_trial_opt_out_container').slideDown();
                            $('#dd_bank_transfer_notice').slideUp();
                        } else {
                            // Hide completely for clean Stripe loads
                            $('#dd_trial_opt_out_container').slideUp();
                        }
                    }

                    // Run checks on initial page load
                    dd_check_gateway();

                    // Listen for gateway changes (Radio buttons or Dropdown)
                    $(document).on('change', 'input[name=gateway], #gateway', function() {
                        dd_check_gateway();
                    });
                });
            </script>
            <?php
        }

        /**
         * Validates that the user has checked the opt-out box if they select Bank Transfer.
         */
        public function validate_bank_transfer_opt_out($continue)
        {
            if (!$continue) return $continue;

            // Only enforce if it is a free trial checkout
            if (!$this->is_checkout_a_new_free_trial()) {
                return $continue;
            }

            // Determine active gateway in request
            $gateway = pmpro_getOption('gateway');
            if (isset($_REQUEST['gateway'])) {
                $gateway = sanitize_text_field($_REQUEST['gateway']);
            }

            if ($gateway === 'check') {
                if (!isset($_REQUEST['dd_opt_out_free_trial']) || $_REQUEST['dd_opt_out_free_trial'] !== '1') {
                    pmpro_setMessage(__('The free trial is not available when paying via bank transfer. Please check the "opt-out" box below to proceed.', 'pmpro'), 'pmpro_error');
                    return false;
                }
            }

            return $continue;
        }

        /**
         * Removes the Subscription Delay hooks entirely if the user checks the opt-out box.
         * Works universally for Bank Transfers AND Stripe opt-outs.
         */
        public function remove_delay_if_opted_out($level)
        {
            if (empty($level)) {
                return $level;
            }

            // Check if the opt-out box was ticked
            if (isset($_REQUEST['dd_opt_out_free_trial']) && $_REQUEST['dd_opt_out_free_trial'] === '1') {
                
                // Comprehensively wipe out the Subscription Delay add-on for this transaction
                remove_filter('pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10, 2);
                remove_action('pmpro_after_checkout', 'pmprosd_pmpro_after_checkout');
                remove_filter('pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3);
                remove_filter('pmpro_level_cost_text', 'pmprosd_level_cost_text', 10, 2);
                remove_action('pmpro_save_discount_code_level', 'pmprosd_pmpro_save_discount_code_level', 10, 2);
                remove_filter('pmpro_checkout_level', 'pmprosd_pmpro_checkout_level', 10, 2);
            }

            return $level;
        }

        /**
         * Forces the initial payment to equal the full billing amount if opted out.
         * Runs late (Priority 50) to ensure the Payment Plans add-on doesn't override it back to $0.
         */
        public function force_full_payment_if_opted_out($level)
        {
            if (empty($level)) {
                return $level;
            }

            if (isset($_REQUEST['dd_opt_out_free_trial']) && $_REQUEST['dd_opt_out_free_trial'] === '1') {
                
                // If there's a recurring billing amount, force the initial payment to match it
                if (isset($level->billing_amount) && (float)$level->billing_amount > 0) {
                    $level->initial_payment = $level->billing_amount;
                }

                // Wipe out any native PMPro trial settings so it bills immediately
                $level->trial_limit = 0;
                $level->trial_amount = 0;
            }

            return $level;
        }

        public function enforce_one_time_subscription_delay($checkout_level)
        {
            if (! is_user_logged_in() || empty($checkout_level)) {
                return $checkout_level;
            }

            if (pmpro_hasMembershipLevel(15) || $checkout_level->id == 15) {
                return $checkout_level;
            }

            if (!class_exists('MemberOrder')) {
                require_once(PMPRO_DIR . '/classes/class.memberorder.php');
            }

            $order     = new MemberOrder();
            $lastorder = $order->getLastMemberOrder(null, array('success', 'cancelled'));
            $has_delay = get_option('pmpro_subscription_delay_' . $checkout_level->id, '');

            if ((pmpro_hasMembershipLevel() || ! empty($lastorder)) && ! empty($has_delay)) {
                remove_filter('pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10, 2);
                remove_action('pmpro_after_checkout', 'pmprosd_pmpro_after_checkout');
                remove_filter('pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3);
                remove_filter('pmpro_level_cost_text', 'pmprosd_level_cost_text', 10, 2);
                remove_action('pmpro_save_discount_code_level', 'pmprosd_pmpro_save_discount_code_level', 10, 2);
                remove_filter('pmpro_checkout_level', 'pmprosd_pmpro_checkout_level', 10, 2);
            }

            return $checkout_level;
        }

        public function validate_stripe_fingerprint_before_checkout($continue)
        {
            if (!$continue || pmpro_getOption('gateway') !== 'stripe') {
                return $continue;
            }

            if (!$this->is_checkout_a_new_free_trial()) {
                return $continue;
            }

            // --- OPT-OUT BYPASS ---
            // If the user already ticked the opt-out box, bypass the fingerprint block entirely
            if (isset($_REQUEST['dd_opt_out_free_trial']) && $_REQUEST['dd_opt_out_free_trial'] === '1') {
                return $continue; 
            }

            $live_token = !empty($_REQUEST['payment_method_id']) ? sanitize_text_field($_REQUEST['payment_method_id']) : (!empty($_REQUEST['stripeToken']) ? sanitize_text_field($_REQUEST['stripeToken']) : '');

            if (!$live_token) {
                return $continue; 
            }

            $api_key = $this->get_pmpro_stripe_api_key();
            if (empty($api_key)) {
                error_log('DD PMPro Trial Error - Validation Check: Could not resolve a valid Stripe API Key.');
                return $continue; 
            }

            if (!class_exists('\Stripe\Stripe')) {
                require_once(PMPRO_DIR . '/includes/lib/Stripe/init.php');
            }
            \Stripe\Stripe::setApiKey($api_key);

            $fingerprint = $this->get_stripe_fingerprint($live_token);

            if ($fingerprint) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';

                $has_trial = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE fingerprint = %s", $fingerprint));

                if ($has_trial > 0) {
                    // Force the UI to remain open for the user to tick the box
                    $this->require_stripe_opt_out = true;
                    
                    // Instruct the user to check the box
                    pmpro_setMessage(__('Payment Declined: It looks like you have already used your free trial. To proceed without a trial, please check the "opt-out" box below.', 'pmpro'), 'pmpro_error');
                    return false;
                }
            }

            return $continue;
        }

        public function log_fingerprint_after_level_change($level_id, $user_id, $cancel_level)
        {
            if ($level_id == 0 || pmpro_getOption('gateway') !== 'stripe') {
                return;
            }

            $api_key = $this->get_pmpro_stripe_api_key();
            if (empty($api_key)) {
                error_log('DD PMPro Trial Error - Logging Check: Could not resolve a valid Stripe API Key.');
                return; 
            }

            if (!class_exists('\Stripe\Stripe')) {
                require_once(PMPRO_DIR . '/includes/lib/Stripe/init.php');
            }
            \Stripe\Stripe::setApiKey($api_key);

            $fingerprint = false;

            $live_token = !empty($_REQUEST['payment_method_id']) ? sanitize_text_field($_REQUEST['payment_method_id']) : (!empty($_REQUEST['stripeToken']) ? sanitize_text_field($_REQUEST['stripeToken']) : '');
            
            if ($live_token) {
                $fingerprint = $this->get_stripe_fingerprint($live_token);
            }

            if (!$fingerprint) {
                $customer_id = get_user_meta($user_id, 'pmpro_stripe_customerid', true);
                
                if ($customer_id) {
                    try {
                        $customer = \Stripe\Customer::retrieve($customer_id);
                        $payment_method_id = $customer->invoice_settings->default_payment_method ?? '';
                        
                        if (!$payment_method_id) {
                            $payment_methods = \Stripe\PaymentMethod::all([
                                'customer' => $customer_id,
                                'type'     => 'card',
                                'limit'    => 1
                            ]);
                            if (!empty($payment_methods->data)) {
                                $payment_method_id = $payment_methods->data[0]->id;
                            }
                        }

                        if ($payment_method_id) {
                            $fingerprint = $this->get_stripe_fingerprint($payment_method_id);
                        }
                    } catch (Exception $e) {
                        error_log('DD PMPro Trial Logging - Stripe Customer Query Failed: ' . $e->getMessage());
                    }
                }
            }

            if ($fingerprint) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';

                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE fingerprint = %s LIMIT 1", $fingerprint));

                if (!$exists) {
                    $user = get_userdata($user_id);
                    $wpdb->insert(
                        $table_name,
                        [
                            'user_id'     => $user_id,
                            'email'       => $user->user_email,
                            'fingerprint' => $fingerprint
                        ],
                        ['%d', '%s', '%s']
                    );
                }
            } else {
                error_log("DD PMPro Trial Logging Error: Could not locate Stripe fingerprint to log for User $user_id");
            }
        }
    }

    new DD_PMPro_Trial_Protection();
}