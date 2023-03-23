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
		<style>
			.woocommerce-page #payment button#place_order {
				display: none;
			}
		</style>
		<script>
			window.addEventListener("message", ({data}) => {
				if (data.action && data.action === "wcpos-process-payment") {
					// submit checkout form
					const button = document.getElementById('place_order');
					if (button) {
						button.click();
					}
				}
			}, false);
		</script>
	</head>

<body <?php body_class(); ?>>
<div class="woocommerce">
<?php woocommerce_output_all_notices(); ?>

<?php wc_get_template( 'checkout/form-pay.php', array( 'order' => $order, 'available_gateways' => $available_gateways ) ); ?>

</div>

<?php wp_footer(); ?>

</body>
</html>

