<?php

/**
 * Plugin Name: myCRED Custom Bank Transfer Gateway
 * Description: Programmatically registers and configures an advanced Bank Transfer gateway for the buyCRED add-on.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registers the custom Bank Transfer gateway into the buyCRED system.
 *
 * @param array $installed Array of currently installed buyCRED gateways.
 * @return array Modified array of gateways including the custom Bank Transfer.
 */
function dd_register_custom_bank_transfer_gateway($installed)
{
    $installed['custom_bank_transfer'] = array(
        'title'    => __('Bank Transfer (Advanced)', 'mycred'),
        'callback' => array('myCRED_Custom_Bank_Transfer')
    );
    return $installed;
}
add_filter('mycred_setup_gateways', 'dd_register_custom_bank_transfer_gateway');

/**
 * Ensures the custom gateway class is loaded when myCRED is ready.
 * * Hooking into 'mycred_pre_init' guarantees the parent myCRED classes are available
 * before we attempt to extend them.
 *
 * @return void
 */
function dd_init_custom_bank_transfer_class()
{
    if (! class_exists('myCRED_Payment_Gateway')) {
        return;
    }

    /**
     * Class myCRED_Custom_Bank_Transfer
     *
     * Handles the custom Bank Transfer payment gateway logic, including checkout display,
     * request processing, and transaction logging.
     */
    class myCRED_Custom_Bank_Transfer extends myCRED_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         *
         * Initializes gateway configuration, title, and default settings including
         * the initial bank instruction payload.
         *
         * @param array $gateway_prefs Gateway preferences.
         */
        public function __construct($gateway_prefs)
        {
            $types            = mycred_get_types();
            $default_exchange = array();

            foreach ($types as $type => $label) {
                $default_exchange[$type] = 1;
            }

            parent::__construct(array(
                'id'               => 'custom_bank_transfer',
                'label'            => 'Advanced Bank Transfer',
                'gateway_logo_url' => '',
                'defaults'         => array(
                    'bank_details'  => "Please transfer the funds to:\nBank: XYZ Bank\nAccount: 12345678\nRouting: 987654321",
                    'currency'      => 'USD',
                    'exchange'      => $default_exchange,
                    'item_name'     => 'Points Purchase'
                )
            ), $gateway_prefs);
        }

        /**
         * Renders the gateway preferences in the myCRED wp-admin settings area.
         * * This allows administrators to update routing/account numbers without altering the codebase.
         *
         * @return void
         */
        public function preferences()
        {
            $prefs = $this->prefs;
?>
            <div class="row">
                <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
                    <h3><?php _e('Bank Details & Instructions', 'mycred'); ?></h3>
                    <p>
                        <label for="<?php echo esc_attr($this->field_id('bank_details')); ?>"><?php _e('Instructions shown to user:', 'mycred'); ?></label><br />
                        <textarea name="<?php echo esc_attr($this->field_name('bank_details')); ?>" id="<?php echo esc_attr($this->field_id('bank_details')); ?>" rows="5" class="large-text"><?php echo esc_textarea($prefs['bank_details']); ?></textarea>
                    </p>
                </div>
            </div>
<?php
        }

        /**
         * Sanitizes the gateway preferences before saving them to the database.
         *
         * @param array $post Array of submitted preference data from the admin settings.
         * @return array Sanitized preference data.
         */
        public function sanitise_preferences($post)
        {
            $new_data = array();
            if (isset($post['bank_details'])) {
                $new_data['bank_details'] = sanitize_textarea_field($post['bank_details']);
            }
            return $new_data;
        }

        /**
         * Renders the checkout form or instructions for the user when purchasing points.
         * * Displays the transaction instructions on the front-end prior to finalizing the order.
         *
         * @return void
         */
        public function buy()
        {
            if (! $this->core->can_edit_creds()) {
                wp_die(__('You do not have permission to use this gateway.', 'mycred'));
            }

            echo '<div class="mycred-bank-transfer-instructions">';
            echo '<h3>' . esc_html__('Bank Transfer Instructions', 'mycred') . '</h3>';
            echo '<p>' . nl2br(esc_html($this->prefs['bank_details'])) . '</p>';
            echo '<p><strong>' . esc_html__('Note: Your points will be credited manually by an administrator once funds clear.', 'mycred') . '</strong></p>';
            echo '</div>';
        }

        /**
         * Processes incoming IPN requests from the gateway.
         *
         * Manual bank transfers lack automated IPN webhooks, but this method fulfills 
         * the abstract requirement. Complex bank API logic would be injected here.
         *
         * @return void
         */
        public function process()
        {
            // Left intentionally blank for manual processing.
        }

        /**
         * Handles users returning to the site after a transaction attempt.
         *
         * @return void
         */
        public function returning()
        {
            // Hook for returning user redirects.
        }
    }
}
add_action('mycred_pre_init', 'dd_init_custom_bank_transfer_class', 100);
