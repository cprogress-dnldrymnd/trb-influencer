<?php
/**
 * Plugin Name: buyCRED Checkout Gateway Switcher
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Injects a dynamic payment gateway selection dropdown directly into the native buyCRED checkout screen.
 * Version:     1.0.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-buycred-switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly to prevent unauthorized execution.
}

/**
 * Class DD_BuyCred_Gateway_Switcher
 * * Manages the functionality to switch payment gateways dynamically on the buyCRED checkout page.
 * It handles both the UI rendering via shortcode and the backend data mutation.
 */
class DD_BuyCred_Gateway_Switcher {

	/**
	 * Constructor function.
	 * * Initializes all necessary WordPress hooks for the plugin. Registers the 
	 * shortcode for UI placement and the template_redirect action for processing state changes.
	 */
	public function __construct() {
		add_shortcode( 'dd_buycred_gateway_switcher', array( $this, 'render_switcher' ) );
		add_action( 'template_redirect', array( $this, 'process_gateway_switch' ), 9 );
	}

	/**
	 * Renders the gateway selection dropdown UI.
	 * * This function retrieves the active buyCRED gateways, identifies the current
	 * pending payment ID from the request, checks its active gateway state, and 
	 * generates an HTML form to allow the user to select an alternative.
	 * * @return string HTML output containing the switcher form, or an empty string on failure.
	 */
	public function render_switcher() {
		// Ensure core buyCRED dependencies are loaded before execution.
		if ( ! function_exists( 'mycred_get_buycred_gateways' ) ) {
			return '';
		}

		// Retrieve and sanitize the pending payment ID standard to buyCRED checkouts.
		$payment_id = isset( $_REQUEST['payment_id'] ) ? absint( $_REQUEST['payment_id'] ) : 0;
		if ( ! $payment_id ) {
			return '';
		}

		// Fetch all globally active buyCRED gateways.
		$gateways = mycred_get_buycred_gateways();
		if ( empty( $gateways ) ) {
			return '';
		}

		// Retrieve the currently active gateway bound to this specific transaction.
		$current_gateway = get_post_meta( $payment_id, 'gateway', true );

		ob_start();
		?>
		<div class="dd-gateway-switcher" style="margin: 20px 0; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc;">
			<form method="post" action="">
				<?php wp_nonce_field( 'dd_switch_gateway_nonce', 'dd_gateway_nonce' ); ?>
				<input type="hidden" name="dd_payment_id" value="<?php echo esc_attr( $payment_id ); ?>" />
				
				<label for="dd_new_gateway" style="display: block; margin-bottom: 8px; font-weight: 600; color: #0f172a;">
					<?php esc_html_e( 'Change Payment Method:', 'dd-buycred-switcher' ); ?>
				</label>
				
				<select name="dd_new_gateway" id="dd_new_gateway" onchange="this.form.submit()" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px;">
					<option value=""><?php esc_html_e( '-- Select Gateway --', 'dd-buycred-switcher' ); ?></option>
					<?php foreach ( $gateways as $gateway_id => $gateway_title ) : ?>
						<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php selected( $current_gateway, $gateway_id ); ?>>
							<?php echo esc_html( $gateway_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Processes the gateway switch request and updates the database.
	 * * This function hooks into `template_redirect` to listen for the form submission.
	 * It verifies the security nonce, sanitizes the new gateway input, mutates the 
	 * 'gateway' post meta for the pending payment CPT, and triggers a page reload 
	 * to instantiate the new payment processor's UI environment.
	 * * @return void
	 */
	public function process_gateway_switch() {
		// Listen for our specific POST payload.
		if ( isset( $_POST['dd_new_gateway'], $_POST['dd_payment_id'], $_POST['dd_gateway_nonce'] ) ) {
			
			// Validate security nonce to prevent CSRF attacks.
			if ( ! wp_verify_nonce( $_POST['dd_gateway_nonce'], 'dd_switch_gateway_nonce' ) ) {
				wp_die( esc_html__( 'Security check failed. Unauthorized request.', 'dd-buycred-switcher' ) );
			}

			$payment_id  = absint( $_POST['dd_payment_id'] );
			$new_gateway = sanitize_text_field( $_POST['dd_new_gateway'] );

			// Execute the database mutation if variables are valid.
			if ( $payment_id && ! empty( $new_gateway ) ) {
				update_post_meta( $payment_id, 'gateway', $new_gateway );

				// Strip POST data and refresh the view to construct the new gateway.
				wp_safe_redirect( remove_query_arg( 'dd_new_gateway' ) );
				exit;
			}
		}
	}
}

// Instantiate the architecture.
new DD_BuyCred_Gateway_Switcher();