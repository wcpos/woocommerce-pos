<?php
/**
 * POS Order Pay template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/pay.php.
 *
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

\defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<?php wp_head(); ?>
	<script>
		// @TODO - I should only send message if order is complete
		window.top.postMessage({
			action: 'wcpos-payment-received',
			payload: <?php echo $order_json; ?>
		}, '*');

		window.ReactNativeWebView.postMessage(JSON.stringify({
			action: 'wcpos-payment-received',
			payload: <?php echo $order_json; ?>
		}));
	</script>
</head>

<body <?php body_class(); ?>>
<div class="woocommerce">
	<?php woocommerce_output_all_notices(); ?>

	<?php wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) ); ?>

</div>

<?php wp_footer(); ?>

</body>
</html>

