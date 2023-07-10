<?php
/**
 * POS Order Received template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/received.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<?php wp_head(); ?>
	<style>
		* {
			padding: 0;
			margin: 0;
		}

		.wrapper {
			height: 100vh;
			display: flex;
			justify-content: center;
			align-items: center;
			background-color: #fff;
		}

		.checkmark__circle {
			stroke-dasharray: 166;
			stroke-dashoffset: 166;
			stroke-width: 2;
			stroke-miterlimit: 10;
			stroke: #0C6B58;
			fill: none;
			animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
		}

		.checkmark {
			width: 56px;
			height: 56px;
			border-radius: 50%;
			display: block;
			stroke-width: 2;
			stroke: #fff;
			stroke-miterlimit: 10;
			margin: 10% auto;
			box-shadow: inset 0px 0px 0px #0C6B58;
			animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both;
		}

		.checkmark__check {
			transform-origin: 50% 50%;
			stroke-dasharray: 48;
			stroke-dashoffset: 48;
			animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
		}

		@keyframes stroke {
			100% {
				stroke-dashoffset: 0
			}
		}

		@keyframes scale {
			0%, 100% {
				transform: none
			}
			50% {
				transform: scale3d(1.1, 1.1, 1)
			}
		}

		@keyframes fill {
			100% {
				box-shadow: inset 0px 0px 0px 30px #0C6B58
			}
		}
	</style>
	<?php if ( $order_complete ) : ?>
	<script>
		(function() {
			// Parse the order JSON from PHP
			var order = <?php echo $order_json; ?>;

			// Check if postMessage function exists for window.top
			if (typeof window.top.postMessage === 'function') {
				window.top.postMessage({
					action: 'wcpos-payment-received',
					payload: order
				}, '*');
			}

			// Check if ReactNativeWebView object and postMessage function exists
			if (typeof window.ReactNativeWebView !== 'undefined' && typeof window.ReactNativeWebView.postMessage === 'function') {
				window.ReactNativeWebView.postMessage(JSON.stringify({
					action: 'wcpos-payment-received',
					payload: order
				}));
			}
		})();
	</script>
	<?php endif; ?>
</head>

<body <?php body_class(); ?>>
<div class="woocommerce">
	<?php if ( $order_complete ) : ?>
		<div class="wrapper">
			<svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
				<circle class="checkmark__circle" cx="26" cy="26" r="25" fill="none"/>
				<path class="checkmark__check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
			</svg>
		</div>
	<?php else : ?>
		<?php woocommerce_output_all_notices(); ?>
		<?php wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) ); ?>
	<?php endif; ?>
</div>

<?php wp_footer(); ?>

</body>
</html>

