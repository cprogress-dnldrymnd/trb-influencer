<?php
/**
 * Plugin Name: buyCRED Inline Gateway Switcher
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Deploys a global MutationObserver to inject a dynamic gateway reset mechanism into the myCred inline AJAX checkout form.
 * Version:     1.0.1
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-inline-switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly to prevent unauthorized execution.
}

/**
 * Class DD_BuyCred_Inline_Switcher
 * * Handles the logic for observing buyCRED AJAX DOM mutations at the body level
 * and injecting a UI mechanism to reset the gateway selection state.
 */
class DD_BuyCred_Inline_Switcher {

	/**
	 * Constructor function.
	 * * Initializes necessary WordPress hooks. We hook into wp_footer to ensure
	 * our vanilla JavaScript is loaded after the DOM is fully constructed.
	 */
	public function __construct() {
		add_action( 'wp_footer', array( $this, 'inject_global_observer_script' ), 99 );
	}

	/**
	 * Injects the global JavaScript MutationObserver logic into the footer.
	 * * This script monitors the entire document body for DOM node insertions.
	 * When the `#buycred-checkout-page` UI is detected (injected via AJAX), 
	 * it prepends a reset action button to clear the state.
	 * * @return void
	 */
	public function inject_global_observer_script() {
		// Verify if we are on a page containing the specific shortcode to prevent unnecessary JS execution.
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'mycred_buy_form' ) ) {
			return;
		}
		?>
		<script type="text/javascript">
			/**
			 * Event listener for DOMContentLoaded to ensure the script runs after the initial HTML document has been completely loaded and parsed.
			 */
			document.addEventListener('DOMContentLoaded', function() {
				
				// Attach the observer to the document body to catch all AJAX replacements, 
				// preventing issues where the target node itself is destroyed by myCred.
				const targetNode = document.body;
				
				// Configure the observer to watch for deep child modifications across the entire body.
				const config = { childList: true, subtree: true };

				/**
				 * Callback function executed when DOM mutations are observed.
				 * * @param {MutationRecord[]} mutationsList - Array of MutationRecord objects.
				 * @param {MutationObserver} observer - The MutationObserver instance.
				 */
				const callback = function( mutationsList, observer ) {
					// We rely on the known ID from your template file override: 'buycred-checkout-page'
					const checkoutPage = document.getElementById('buycred-checkout-page');
					
					if ( checkoutPage ) {
						// Check if our reset button is already injected to prevent infinite loop duplication.
						const switcherExists = document.getElementById('dd-gateway-reset-btn');
						
						if ( ! switcherExists ) {
							// Target the inner wrapper where the form elements reside.
							const checkoutOrderDiv = checkoutPage.querySelector('.checkout-order');
							
							if ( checkoutOrderDiv ) {
								// Construct the reset UI element.
								const resetBtn = document.createElement('a');
								resetBtn.id = "dd-gateway-reset-btn";
								resetBtn.href = "javascript:void(0);";
								resetBtn.innerHTML = "&larr; Change Payment Method";
								
								// Apply inline styling to match the UI environment.
								resetBtn.style.cssText = "display: inline-block; margin-bottom: 20px; padding: 10px 15px; font-weight: 600; color: #fff; background-color: #d9534f; border-radius: 4px; text-decoration: none; cursor: pointer; transition: background-color 0.2s ease;";
								
								// Add hover effects dynamically.
								resetBtn.onmouseover = function() { this.style.backgroundColor = '#c9302c'; };
								resetBtn.onmouseout = function() { this.style.backgroundColor = '#d9534f'; };
								
								/**
								 * Action: Force a page reload to clear the temporary AJAX transaction state
								 * and return the user to the initial gateway selection UI.
								 */
								resetBtn.onclick = function() {
									window.location.reload();
								};

								// Inject the button directly at the top of the checkout order section.
								checkoutOrderDiv.insertBefore(resetBtn, checkoutOrderDiv.firstChild);
							}
						}
					}
				};

				// Instantiate and execute the global observer.
				const observer = new MutationObserver( callback );
				observer.observe( targetNode, config );
			});
		</script>
		<?php
	}
}

// Instantiate the architecture.
new DD_BuyCred_Inline_Switcher();