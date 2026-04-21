<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_PMPro_Trial_Protection
 * Description: Implements strict email and Stripe card fingerprint tracking to prevent free trial abuse. 
 * Integrates one-time subscription delay rules with exception handling for specific tiers.
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

            // LAYER 1: Enforce account-level trial logic (Includes your custom Level 15 exception)
            add_filter('pmpro_checkout_level', [$this, 'enforce_one_time_subscription_delay'], 15, 1);

            // LAYER 2: Advanced Stripe Fingerprint Validation (Processing Level)
            add_filter('pmpro_registration_checks', [$this, 'validate_stripe_fingerprint_before_checkout'], 20, 2);

            // Log the fingerprint after a successful trial checkout
            add_action('pmpro_after_checkout', [$this, 'log_fingerprint_after_checkout'], 10, 2);
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
         * LAYER 2: Intercepts the checkout submission, queries Stripe for the card fingerprint, 
         * and halts the checkout if the card has already been used for a trial.
         *
         * @param bool $continue Current boolean state of the PMPro registration validation.
         * @return bool True if valid, false if a duplicate fingerprint is detected.
         */
        public function validate_stripe_fingerprint_before_checkout($continue)
        {
            // Abort if upstream validation already failed or if not using Stripe
            if (!$continue || empty($_REQUEST['payment_method_id']) || pmpro_getOption('gateway') !== 'stripe') {
                return $continue;
            }

            // Ensure the Stripe PHP SDK is loaded natively via PMPro
            if (!class_exists('\Stripe\Stripe')) {
                require_once(PMPRO_DIR . '/includes/lib/Stripe/init.php');
            }

            \Stripe\Stripe::setApiKey(pmpro_getOption('stripe_secretkey'));

            $payment_method_id = sanitize_text_field($_REQUEST['payment_method_id']);

            try {
                // Call Stripe API to retrieve the payment method details mapped to the frontend token
                $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);

                if (!empty($payment_method->card->fingerprint)) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';
                    $fingerprint = $payment_method->card->fingerprint;

                    // Query our custom table to see if this fingerprint is already blacklisted
                    $has_trial = $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(id) FROM $table_name WHERE fingerprint = %s
                    ", $fingerprint));

                    if ($has_trial > 0) {
                        // Halt checkout with clear messaging to maintain payment compliance
                        pmpro_setMessage(__('This payment card has already been used to claim a free trial. Please use a different card or upgrade without a trial.', 'pmpro'), 'pmpro_error');
                        return false;
                    }
                }
            } catch (Exception $e) {
                // If Stripe API fails, fail safe and allow PMPro core to handle the gateway error down the line
                error_log('PMPro Stripe Fingerprint API Error: ' . $e->getMessage());
            }

            return $continue;
        }

       /**
         * Logs the Stripe card fingerprint into the custom database table immediately 
         * after a successful checkout. Bypasses PMPro's $morder object to ensure 
         * $0.00 initial trial checkouts are reliably captured.
         *
         * @param int    $user_id The WordPress User ID.
         * @param object $morder  The PMPro Membership Order object (often null on $0 trials).
         * @return void
         */
        public function log_fingerprint_after_checkout($user_id, $morder)
        {
            // 1. Decouple from $morder and rely on global PMPro gateway settings
            if (pmpro_getOption('gateway') !== 'stripe') {
                return;
            }

            // Ensure the Stripe PHP SDK is loaded natively
            if (!class_exists('\Stripe\Stripe')) {
                require_once(PMPRO_DIR . '/includes/lib/Stripe/init.php');
            }
            \Stripe\Stripe::setApiKey(pmpro_getOption('stripe_secretkey'));

            $payment_method_id = '';

            // TIER 1: Intercept the ID directly from the live checkout payload
            if (!empty($_REQUEST['payment_method_id'])) {
                $payment_method_id = sanitize_text_field($_REQUEST['payment_method_id']);
            }

            // TIER 2: Retrieve from PMPro User Meta (Written during gateway processing)
            if (empty($payment_method_id)) {
                $payment_method_id = get_user_meta($user_id, 'pmpro_stripe_payment_method_id', true);
            }

            // TIER 3: Stripe API Deep-Dive (Extracts default card directly from the newly created Customer profile)
            if (empty($payment_method_id)) {
                $customer_id = get_user_meta($user_id, 'pmpro_stripe_customerid', true);
                
                if (!empty($customer_id)) {
                    try {
                        $customer = \Stripe\Customer::retrieve($customer_id);
                        
                        // Check invoice default first, fallback to querying the customer's attached cards
                        if (!empty($customer->invoice_settings->default_payment_method)) {
                            $payment_method_id = $customer->invoice_settings->default_payment_method;
                        } else {
                            $payment_methods = \Stripe\PaymentMethod::all([
                                'customer' => $customer_id,
                                'type'     => 'card',
                                'limit'    => 1
                            ]);
                            if (!empty($payment_methods->data)) {
                                $payment_method_id = $payment_methods->data[0]->id;
                            }
                        }
                    } catch (Exception $e) {
                        error_log('DD PMPro Trial Error - Stripe Customer Retrieve Failed: ' . $e->getMessage());
                    }
                }
            }

            // Abort and log if all 3 tiers failed to find a valid Payment Method ID
            if (empty($payment_method_id) || strpos($payment_method_id, 'pm_') !== 0) {
                error_log('DD PMPro Trial Error - Failed to locate a valid Stripe Payment Method ID for User ID: ' . $user_id);
                return;
            }

            // Proceed to query Stripe for the specific card fingerprint
            try {
                $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);

                if (!empty($payment_method->card->fingerprint)) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'dd_stripe_fingerprints';
                    $fingerprint = $payment_method->card->fingerprint;

                    // Ensure we don't log duplicate entries for the exact same fingerprint
                    $exists = $wpdb->get_var($wpdb->prepare("
                        SELECT id FROM $table_name WHERE fingerprint = %s LIMIT 1
                    ", $fingerprint));

                    if (!$exists) {
                        $user = get_userdata($user_id);
                        $inserted = $wpdb->insert(
                            $table_name,
                            [
                                'user_id'     => $user_id,
                                'email'       => $user->user_email,
                                'fingerprint' => $fingerprint
                            ],
                            ['%d', '%s', '%s']
                        );
                        
                        if (!$inserted) {
                             error_log('DD PMPro Trial Error - Database Insert Failed: ' . $wpdb->last_error);
                        }
                    }
                } else {
                    error_log('DD PMPro Trial Error - The retrieved Stripe Payment Method object did not contain a card fingerprint.');
                }
            } catch (Exception $e) {
                error_log('DD PMPro Trial Error - Stripe API Verification Failed: ' . $e->getMessage());
            }
        }
    }

    new DD_PMPro_Trial_Protection();
}