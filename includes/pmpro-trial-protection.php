<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_PMPro_Trial_Protection
 * Description: Implements strict email and Stripe card fingerprint tracking to prevent free trial abuse. 
 * Integrates one-time subscription delay rules and standardizes free-trial detection logic.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (!class_exists('DD_PMPro_Trial_Protection')) {

    class DD_PMPro_Trial_Protection
    {
        /**
         * Constructor.
         * Initializes hooks for database creation, checkout validation, and post-checkout fingerprint logging.
         */
        public function __construct()
        {
            // Initialize custom database table on admin load
            add_action('admin_init', [$this, 'initialize_fingerprint_table']);

            add_filter('pmpro_checkout_level', [$this, 'disable_subscription_delay_for_checks'], 5, 1);

            // LAYER 1: Enforce account-level trial logic (Includes your custom Level 15 exception)
            add_filter('pmpro_checkout_level', [$this, 'enforce_one_time_subscription_delay'], 15, 1);

            // LAYER 2: Advanced Stripe Fingerprint Validation (Processing Level)
            add_filter('pmpro_registration_checks', [$this, 'validate_stripe_fingerprint_before_checkout'], 20, 2);

            // LAYER 3: Bulletproof Fingerprint Logging (Fires when membership is officially granted)
            add_action('pmpro_after_change_membership_level', [$this, 'log_fingerprint_after_level_change'], 10, 3);
        }

        /**
         * Initializes the custom database table required to store Stripe card fingerprints.
         * Executes safely using WordPress dbDelta.
         *
         * @return void
         */
        public function initialize_fingerprint_table()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';

            // Check if table exists to prevent unnecessary dbDelta calls
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

        /**
         * Resolves the correct Stripe API key dynamically by directly querying the WordPress options table.
         * Aggressively scans all PMPro Stripe key permutations to support Connect OAuth and Manual keys.
         *
         * @return string|false Returns the resolved API key/token or false if unavailable.
         */
        private function get_pmpro_stripe_api_key()
        {
            $api_key = false;
            $env = get_option('pmpro_gateway_environment', 'sandbox');

            // Route 1: Target the specific environment first
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

            // Route 2: Universal Fallback. If still empty, scan all possible DB columns
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

        /**
         * Resolves the Stripe Card Fingerprint from either a Payment Method ID or a Legacy Token.
         *
         * @param string $token The Stripe token (starts with 'pm_' or 'tok_').
         * @return string|false Returns the fingerprint string, or false if extraction fails.
         */
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

        /**
         * Standardizes the evaluation of a Free Trial checkout.
         * Mirrors the 365-day historical query standard established in the PMPro Dynamic Pricing plugin
         * to prevent prorated upgrades/switches from being incorrectly flagged as free trials.
         *
         * @return bool True if the current checkout is a genuine new free trial, false otherwise.
         */
        private function is_checkout_a_new_free_trial()
        {
            global $pmpro_level;

            if (empty($pmpro_level)) {
                return false;
            }

            // 1. If they are paying money today, it is not a free trial.
            if (isset($pmpro_level->initial_payment) && (float)$pmpro_level->initial_payment > 0) {
                return false;
            }

            // 2. If the plan has no recurring cost, it's a completely free tier, not a trial.
            if (empty($pmpro_level->billing_amount) || (float)$pmpro_level->billing_amount <= 0) {
                return false;
            }

            // 3. Evaluate user history using the 365-day standard from the dynamic pricing plugin
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

                // If they have a recent paid order, this $0 checkout is a prorated switch/banked time, NOT a new trial.
                if ($has_recent_paid_order) {
                    return false;
                }
            }

            // If they owe $0 today, have a recurring amount, and no recent paid history, it IS a new free trial.
            return true;
        }

        /**
         * Disable PMPro Subscription Delays for Bank Transfers (Check Gateway).
         * Intercepts the checkout level evaluation and thoroughly removes the 
         * Subscription Delays hooks if the user has opted to pay via Bank Transfer / Check.
         *
         * @param object $level The PMPro membership level object at checkout.
         * @return object The modified PMPro membership level object.
         */
        public function disable_subscription_delay_for_checks($level)
        {
            if (empty($level)) {
                return $level;
            }

            // Check if the payload indicates they are paying via the 'check' gateway
            if (isset($_REQUEST['gateway']) && $_REQUEST['gateway'] === 'check') {
                
                // Comprehensively remove all Subscription Delay filters and actions
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
         * LAYER 1: Remove subscription delay for logged-in current or past members. 
         * EXCEPTION: Leaves the delay active for Level 15.
         *
         * @param object $checkout_level The PMPro membership level object at checkout.
         * @return object The modified PMPro membership level object.
         */
        public function enforce_one_time_subscription_delay($checkout_level)
        {
            // Logged-out users should always get the trial/delay.
            if (! is_user_logged_in() || empty($checkout_level)) {
                return $checkout_level;
            }

            // --- LEVEL 15 EXCEPTION ---
            // If the user currently HAS Level 15, OR they are PURCHASING Level 15,
            // bail out immediately so the Subscription Delay remains fully active.
            if (pmpro_hasMembershipLevel(15) || $checkout_level->id == 15) {
                return $checkout_level;
            }

            // Ensure the PMPro MemberOrder class is available
            if (!class_exists('MemberOrder')) {
                require_once(PMPRO_DIR . '/classes/class.memberorder.php');
            }

            $order     = new MemberOrder();
            $lastorder = $order->getLastMemberOrder(null, array('success', 'cancelled'));
            $has_delay = get_option('pmpro_subscription_delay_' . $checkout_level->id, '');

            // If user currently has a membership level or previously had a membership level, remove subscription delay.
            if ((pmpro_hasMembershipLevel() || ! empty($lastorder)) && ! empty($has_delay)) {

                // Remove subscription delay filters and actions (standard).
                remove_filter('pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10, 2);
                remove_action('pmpro_after_checkout', 'pmprosd_pmpro_after_checkout');
                remove_filter('pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3);
                remove_filter('pmpro_level_cost_text', 'pmprosd_level_cost_text', 10, 2);
                remove_action('pmpro_save_discount_code_level', 'pmprosd_pmpro_save_discount_code_level', 10, 2);

                // Remove the updated filter added in PMPro Subscription Delays 3.4+.
                remove_filter('pmpro_checkout_level', 'pmprosd_pmpro_checkout_level', 10, 2);
            }

            return $checkout_level;
        }

        /**
         * LAYER 2: Intercepts the checkout submission, parses the token/payment_method payload, 
         * and halts the checkout if the card has already been used for a trial.
         *
         * @param bool $continue Current boolean state of the PMPro registration validation.
         * @return bool True if valid, false if a duplicate fingerprint is detected.
         */
        public function validate_stripe_fingerprint_before_checkout($continue)
        {
            if (!$continue || pmpro_getOption('gateway') !== 'stripe') {
                return $continue;
            }

            // --- GUARDRAIL: Only block if the checkout is actively claiming a new Free Trial ---
            if (!$this->is_checkout_a_new_free_trial()) {
                return $continue;
            }

            // Extract token from standard Payment Method field or Legacy Token field
            $live_token = !empty($_REQUEST['payment_method_id']) ? sanitize_text_field($_REQUEST['payment_method_id']) : (!empty($_REQUEST['stripeToken']) ? sanitize_text_field($_REQUEST['stripeToken']) : '');

            if (!$live_token) {
                return $continue; // Abort silently if no token is passed in the payload
            }

            // Resolve the dynamic Stripe API key required for authentication
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

                // Query custom table to check for existing fingerprint blocks
                $has_trial = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE fingerprint = %s", $fingerprint));

                if ($has_trial > 0) {
                    pmpro_setMessage(__('Payment Declined. Please use another card.', 'pmpro'), 'pmpro_error');
                    return false;
                }
            }

            return $continue;
        }

        /**
         * LAYER 3: Logs the fingerprint upon successful assignment of the membership level.
         * Uses a 3-tier fallback system to bypass the missing $morder object during $0 initial checkouts.
         *
         * @param int $level_id     The ID of the new level being assigned.
         * @param int $user_id      The ID of the user.
         * @param int $cancel_level The ID of the level being cancelled.
         * @return void
         */
        public function log_fingerprint_after_level_change($level_id, $user_id, $cancel_level)
        {
            // Abort if cancelling a level or not using Stripe
            if ($level_id == 0 || pmpro_getOption('gateway') !== 'stripe') {
                return;
            }

            // Resolve the dynamic Stripe API key required for authentication
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

            // TIER 1: Try to grab the live payload token first (supports pm_ and tok_)
            $live_token = !empty($_REQUEST['payment_method_id']) ? sanitize_text_field($_REQUEST['payment_method_id']) : (!empty($_REQUEST['stripeToken']) ? sanitize_text_field($_REQUEST['stripeToken']) : '');
            
            if ($live_token) {
                $fingerprint = $this->get_stripe_fingerprint($live_token);
            }

            // TIER 2 & 3: Fallback Customer Query if live token is missing (Webhooks/Delayed execution)
            if (!$fingerprint) {
                $customer_id = get_user_meta($user_id, 'pmpro_stripe_customerid', true);
                
                if ($customer_id) {
                    try {
                        $customer = \Stripe\Customer::retrieve($customer_id);
                        $payment_method_id = $customer->invoice_settings->default_payment_method ?? '';
                        
                        // Deep query if invoice default is not explicitly set
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

            // Execute the DB insert strictly if a valid fingerprint was resolved
            if ($fingerprint) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';

                // Prevent duplicate logging of the exact same fingerprint
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