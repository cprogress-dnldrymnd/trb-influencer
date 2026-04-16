<?php
/**
 * Plugin Name: buyCRED PMPro-Style Gateway Switcher
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Implements an inline, radio-button style payment gateway switcher directly on the checkout screen using resilient DOM traversal.
 * Version:     3.0.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly to prevent unauthorized execution.
}

/**
 * Class DD_BuyCred_PMPro_Switcher
 * Core architecture for replacing standard myCred flow with an inline 
 * PMPro-style radio button gateway selection.
 */
class DD_BuyCred_PMPro_Switcher {

	/**
	 * Constructor function.
	 * Initializes all necessary hooks. Binds the JS injector to the footer 
	 * and registers the asynchronous AJAX endpoints.
	 */
	public function __construct() {
		add_action( 'wp_footer', array( $this, 'inject_switcher_script' ), 99 );
		add_action( 'wp_ajax_dd_switch_gateway', array( $this, 'handle_ajax_switch' ) );
		add_action( 'wp_ajax_nopriv_dd_switch_gateway', array( $this, 'handle_ajax_switch' ) );
	}

	/**
	 * Injects the JavaScript application into the footer.
	 * Utilizes a MutationObserver to detect the injected checkout DOM via hidden inputs, 
	 * renders the PMPro-style radio UI, and binds the AJAX mutation events.
	 *
	 * @return void
	 */
	public function inject_switcher_script() {
		global $post;
		
		// Abort execution if the necessary shortcode architecture is absent.
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'mycred_buy_form' ) ) {
			return;
		}

		$gateways = mycred_get_buycred_gateways();
		if ( empty( $gateways ) ) {
			return;
		}

		// Attempt to extract the state context from an HTTP GET/POST payload if present.
		$request_payment_id = isset( $_REQUEST['payment_id'] ) ? absint( $_REQUEST['payment_id'] ) : 0;
		$current_gateway    = $request_payment_id ? get_post_meta( $request_payment_id, 'gateway', true ) : '';

		?>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				// Localize PHP variables for JS scope.
				const ddGateways = <?php echo json_encode( $gateways ); ?>;
				const ddAjaxUrl  = "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>";
				const ddNonce    = "<?php echo esc_js( wp_create_nonce( 'dd_gateway_nonce' ) ); ?>";
				let ddCurrentGateway = "<?php echo esc_js( $current_gateway ); ?>";

				/**
				 * Event Listener to capture the selected dropdown value *before* the AJAX request fires.
				 * This ensures we know which gateway to pre-check in the radio array.
				 */
				document.body.addEventListener('click', function(e) {
					// Target clicks on standard form submission buttons
					const btn = e.target.closest('button[type="submit"], input[type="submit"]');
					if (btn) {
						const form = btn.closest('form');
						if (form) {
							const gatewaySelect = form.querySelector('select[name="gateway"]');
							if (gatewaySelect) {
								ddCurrentGateway = gatewaySelect.value;
							}
						}
					}
				});

				/**
				 * Observer callback to manipulate the DOM once myCred renders the checkout.
				 */
				const observer = new MutationObserver(function(mutations) {
					// UNIVERSAL TARGET: Hunt for the transaction ID input required by all myCred gateways.
					const paymentIdInput = document.querySelector('input[name="payment_id"]');
					
					// Ensure we found the input and haven't already injected the UI.
					if (paymentIdInput && !document.getElementById('dd-pmpro-gateways-wrapper')) {

						const ddPaymentId = paymentIdInput.value;
						if (!ddPaymentId) return; // Halt if context cannot be established.

						// Traverse up to find the active gateway form container.
						const gatewayForm = paymentIdInput.closest('form');
						if (!gatewayForm) return;

						// Construct the primary UI wrapper.
						const wrapper = document.createElement('div');
						wrapper.id = 'dd-pmpro-gateways-wrapper';
						wrapper.style.cssText = 'margin-bottom: 30px; padding: 25px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc;';

						// Construct Header.
						const title = document.createElement('h3');
						title.innerText = 'Choose Your Payment Method';
						title.style.cssText = 'margin-top: 0; margin-bottom: 20px; font-size: 1.3em; color: #1e293b; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;';
						wrapper.appendChild(title);

						// Construct loading state UI.
						const loader = document.createElement('div');
						loader.id = 'dd-gateway-loader';
						loader.style.cssText = 'display: none; color: #0f766e; font-weight: 500; font-style: italic; margin-top: 15px; padding: 10px; background: #ccfbf1; border-radius: 4px;';
						loader.innerText = 'Updating payment method... Please wait.';

						// Render Radio Buttons dynamically.
						for (const [gId, gTitle] of Object.entries(ddGateways)) {
							const label = document.createElement('label');
							label.style.cssText = 'display: flex; align-items: center; margin-bottom: 12px; cursor: pointer; font-size: 16px; font-weight: 500; color: #334155;';

							const radio = document.createElement('input');
							radio.type = 'radio';
							radio.name = 'dd_selected_gateway';
							radio.value = gId;
							radio.style.cssText = 'margin-right: 12px; width: 18px; height: 18px; cursor: pointer;';

							// Validate against current state to set the active selection.
							if (ddCurrentGateway === gId) {
								radio.checked = true;
							}

							/**
							 * Bind the change event to handle asynchronous DB updates.
							 */
							radio.addEventListener('change', function() {
								if (this.checked) {
									// Trigger UI loading state and lock inputs to prevent spam clicks.
									loader.style.display = 'block';
									document.querySelectorAll('input[name="dd_selected_gateway"]').forEach(r => r.disabled = true);

									// Construct XHR payload.
									const formData = new FormData();
									formData.append('action', 'dd_switch_gateway');
									formData.append('security', ddNonce);
									formData.append('payment_id', ddPaymentId);
									formData.append('gateway', this.value);
									
									// Capture base URL, stripping queries to prevent POST/GET loops.
									formData.append('current_url', window.location.href.split('?')[0]); 

									// Execute AJAX request.
									fetch(ddAjaxUrl, {
										method: 'POST',
										body: formData
									})
									.then(response => response.json())
									.then(data => {
										if (data.success && data.data.redirect) {
											// Perform a clean GET redirect to load the new gateway.
											window.location.href = data.data.redirect;
										} else {
											alert('Validation error. Please refresh the page.');
											window.location.reload();
										}
									})
									.catch(err => {
										console.error('AJAX Failure:', err);
										alert('Server communication error. Please refresh.');
										window.location.reload();
									});
								}
							});

							label.appendChild(radio);
							label.appendChild(document.createTextNode(gTitle));
							wrapper.appendChild(label);
						}

						wrapper.appendChild(loader);

						// Inject the constructed interface sequentially above the gateway form.
						gatewayForm.parentNode.insertBefore(wrapper, gatewayForm);
					}
				});

				// Initialize a global observer to catch AJAX modifications.
				observer.observe(document.body, { childList: true, subtree: true });
			});
		</script>
		<?php
	}

	/**
	 * Headless AJAX handler to execute the database mutation.
	 * Validates the security nonce, authenticates the payment ID, updates 
	 * the corresponding CPT meta to the new gateway, and constructs a clean 
	 * GET redirect URL for the frontend to process.
	 *
	 * @return void Outputs JSON response and terminates execution.
	 */
	public function handle_ajax_switch() {
		check_ajax_referer( 'dd_gateway_nonce', 'security' );

		// Sanitize variables passed from JS application.
		$payment_id = isset( $_POST['payment_id'] ) ? absint( $_POST['payment_id'] ) : 0;
		$gateway    = isset( $_POST['gateway'] ) ? sanitize_text_field( $_POST['gateway'] ) : '';
		$base_url   = isset( $_POST['current_url'] ) ? esc_url_raw( $_POST['current_url'] ) : '';

		if ( ! $payment_id || empty( $gateway ) || empty( $base_url ) ) {
			wp_send_json_error( 'Invalid payload.' );
		}

		// Verify the targeted transaction is a legitimate buyCRED pending payment.
		if ( get_post_type( $payment_id ) !== 'buycred_payment' ) {
			wp_send_json_error( 'Invalid transaction architecture.' );
		}

		// Execute state mutation in the database.
		update_post_meta( $payment_id, 'gateway', $gateway );

		// Construct a clean GET URL that bypasses browser POST loops.
		$clean_redirect = add_query_arg( 'payment_id', $payment_id, $base_url );

		wp_send_json_success( array(
			'redirect' => $clean_redirect
		) );
	}
}

// Instantiate the architecture.
new DD_BuyCred_PMPro_Switcher();