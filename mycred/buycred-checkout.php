<?php 
/**
 * buyCRED Checkout Template Override
 * Custom implementation to inject the DD Gateway Switcher cleanly.
 */

get_header(); 
?>

<div id="buycred-checkout-page">
	<div class="checkout-header">

		<?php buycred_checkout_title(); ?>

	</div>
	<div class="checkout-order xx2">

		<?php 
		/**
		 * INJECTION POINT: Gateway Switcher
		 * Placed outside the main checkout form to prevent HTML5 nested <form> validation errors.
		 * Placing it here positions the dropdown immediately above the order summary.
		 */
		echo do_shortcode( '[dd_buycred_gateway_switcher]' ); 
		?>

		<form method="post" action="" id="buycred-checkout-form">

			<?php buycred_checkout_body(); ?>

		</form>

		<?php
		/**
		 * ALTERNATIVE INJECTION POINT:
		 * If you prefer the dropdown to appear below the "Continue" button, 
		 * move the echo do_shortcode() line here instead.
		 */
		?>

	</div>
	<div class="checkout-footer">

		<?php buycred_checkout_footer(); ?>

	</div>
</div>

<?php get_footer(); ?>