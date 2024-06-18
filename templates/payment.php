<?php
/**
 * POS Order Pay template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/payment.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

\defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<?php
	if ( ! $this->disable_wp_head ) {
		wp_head();
	} else {
		// Manually call necessary functions when wp_head is disabled.
		if ( function_exists( 'wp_enqueue_block_template_skip_link' ) ) {
			wp_enqueue_block_template_skip_link();
		}
	}
	?>
	<style>
		html, body, ul, li, fieldset, address {
			font-family: sans-serif;
			font-size: 14px;
			margin: 0 !important;
			padding: 0 !important;
			color: #000000 !important;
			background-color: #ffffff !important;
		}
		h1, h2, h3, h4, h5, h6 {
			margin: 0;
			padding: 0;
			font-weight: 600;
			line-height: 1.3;
		}
		h1 {
			font-size: 18px;
			margin-bottom: 15px;
		}
		h2 {
			font-size: 16px;
			margin-bottom: 12px;
		}
		h3 {
			font-size: 14px;
			margin-bottom: 10px;
		}
		h4 {
			font-size: 14px;
			margin-bottom: 8px;
		}
		h5 {
			font-size: 14px;
			margin-bottom: 6px;
		}
		h6 {
			font-size: 12px;
			margin-bottom: 4px;
		}

		.woocommerce {
			color: #000000 !important;
			background-color: #ffffff !important;
		}

		.woocommerce-pos-troubleshooting h3 {
			margin: 0.5em 0;
			padding: 0;
			font-size: 1em;
			font-weight: 600;
		}

		.woocommerce-pos-troubleshooting input[type="checkbox"] {
			margin-right: 5px;
		}

		.woocommerce-pos-troubleshooting button {
			background: #007cba;
			border: none;
			color: #fff;
			padding: 10px 15px;
			border-radius: 3px;
			cursor: pointer;
		}

		.woocommerce-pos-troubleshooting button:hover {
			background: #005a87;
		}

		.troubleshooting-modal {
			display: none; /* Hidden by default */
			position: fixed; /* Stay in place */
			z-index: 1000; /* Sit on top */
			left: 0;
			top: 0;
			width: 100%; /* Full width */
			height: 100%; /* Full height */
			overflow: auto; /* Enable scroll if needed */
			background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
		}

		.troubleshooting-modal-content {
			background-color: #fefefe;
			margin: 2% auto; /* Slight margin from the top */
			padding: 20px;
			border: 1px solid #888;
			width: 90%; /* Full width with slight margin */
			max-height: 90%; /* Full height with slight margin */
			overflow: auto; /* Enable scroll for content */
			position: relative;
		}

		.close-troubleshooting-modal {
			position: absolute;
			top: -5px;
			right: 10px;
			color: #aaa;
			font-size: 28px;
			font-weight: bold;
		}

		.close-troubleshooting-modal:hover,
		.close-troubleshooting-modal:focus {
			color: black;
			text-decoration: none;
			cursor: pointer;
		}

		.woocommerce-error {
			background-color: #f8d7da;
			color: #721c24;
			border-color: #f5c6cb;
			padding: 5px;
			margin-bottom: 20px;
		}

		.woocommerce-info {
			background-color: #d1ecf1;
			color: #0c5460;
			border-color: #bee5eb;
			padding: 5px;
			margin-bottom: 20px;
		}

		.cashier {
			padding: 5px;
		}

		.cashier .cashier-name {
			font-size: 14px;
			font-weight: bold;
		}

		.current-user {
			padding: 5px;
			margin-bottom: 20px;
		}

		.current-user .user-name {
			font-size: 14px;
			font-weight: bold;
			cursor: pointer;
			color: #007acc; /* Change this color to your preferred link color */
			text-decoration: underline;
		}

		.coupons {
			text-align: right;
		}
		.coupons h3 {
			margin: 10px 0;
		}

		/* Style for Customer Details */
		.woocommerce-customer-details {
			margin-bottom: 20px;
		}
		.woocommerce-columns {
			display: flex;
			justify-content: space-between;
			margin-bottom: 10px;
		}
		.woocommerce-column {
			flex: 0 0 calc(50% - 10px);
			padding: 5px;
		}
		.woocommerce-column__title {
			font-size: 14px;
			font-weight: bold;
			margin-bottom: 5px;
		}
		address {
			font-size: 12px;
			line-height: 1.4;
		}
		address p {
			margin-bottom: 3px;
		}
		.woocommerce-customer-details--phone,
		.woocommerce-customer-details--email {
			font-size: 12px;
			margin-bottom: 3px;
		}

		/* Style for Order Details Table */
		table.shop_table {
			border-collapse: collapse !important;
			width: 100% !important;
			margin-bottom: 20px;
		}
		table.shop_table thead tr th,
		table.shop_table tbody tr td,
		table.shop_table tfoot tr td {
			border: none;
			padding: 5px;
			text-align: left;
		}
		table.shop_table thead tr th {
			font-weight: bold;
			border-bottom: 2px solid #ddd;
		}
		table.shop_table tbody tr td {
			border-bottom: 1px solid #ddd;
		}
		table.shop_table tfoot tr th {
			padding: 5px;
			text-align: right;
		}
		table.shop_table ul.wc-item-meta {
			padding: 0;
			list-style: none;
			margin-top: 5px !important;
		}
		table.shop_table ul.wc-item-meta li {
			font-size: 12px;
		}
		table.shop_table ul.wc-item-meta p {
			display: inline-block;
			margin: 0;
		}

		/* Style for Payment List Accordion */
		.wc_payment_methods ul {
			padding: 0;
			margin: 0;
		}
		.wc_payment_methods li {
			border-top: 1px solid #ddd;
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
			font-weight: bold;
		}
		.wc_payment_methods li input[type="radio"]:checked + label + div.payment_box {
			display: block;
			padding-left: 42px;
			background-color: #f7f7f7;
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
	<?php
		// Troubleshooting form section
		echo $this->get_troubleshooting_form_html();
	?>

	<?php woocommerce_output_all_notices(); ?>

	<?php
		// Cashier details section
		echo $this->get_cashier_details_html();
	?>

	<?php
		// Paying customer details section
		echo $this->get_paying_customer_details_html();
	?>

	<?php
		// Coupon form section
		echo $this->get_coupon_form_html();
	?>

	<?php
		// WooCommerce payment form
		wc_get_template(
			'checkout/form-pay.php',
			array(
				'order' => $this->order,
				'available_gateways' => $available_gateways,
				'order_button_text' => $order_button_text,
			)
		);
		?>
</div>

<?php wp_footer(); ?>

</body>
</html>
