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
			html, body, ul, li, fieldset {
				font-family: sans-serif;
				font-size: 14px;
				margin: 0 !important;
				padding: 0 !important;
			}

			/* Style for Order Details Table */
			table.shop_table {
				border-collapse: collapse;
				width: 100%;
				margin-bottom: 20px;
			}
			table.shop_table thead tr th,
			table.shop_table tbody tr td,
			table.shop_table tfoot tr td {
				border: 1px solid #ddd;
				padding: 10px;
				text-align: left;
			}
			table.shop_table thead tr th {
				background-color: #f7f7f7;
				font-weight: bold;
			}
			table.shop_table tbody tr:nth-child(even) {
				background-color: #f7f7f7;
			}
			table.shop_table tfoot tr th {
				padding: 10px;
				text-align: right;
			}

			/* Style for Payment List Accordion */
			.wc_payment_methods ul {
				padding: 0;
				margin: 0;
			}
			.wc_payment_methods li {
				border: 1px solid #ddd;
				margin-bottom: 10px;
				list-style: none;
			}
			.wc_payment_methods li label {
				display: inline-block;
				padding: 10px;
				cursor: pointer;
				margin-bottom: 0;
			}
			.wc_payment_methods li div.payment_box {
				display: none;
				padding: 10px;
			}
			.wc_payment_methods li input[type="radio"] {
				display: inline-block;
				margin-right: 10px;
				vertical-align: middle;
			}
			.wc_payment_methods li input[type="radio"] + label {
				display: inline-block;
				vertical-align: middle;
			}
			.wc_payment_methods li input[type="radio"]:checked + label {
				background-color: #f7f7f7;
			}
			.wc_payment_methods li input[type="radio"]:checked + label + div.payment_box {
				display: block;
			}
			.wc_payment_methods li fieldset {
				border: none;
			}
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
						const event = new MouseEvent('click', {
							bubbles: true,
							cancelable: true,
							view: window
						});

						button.dispatchEvent(event);
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

