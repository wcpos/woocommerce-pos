<html>
<head>
	<meta charset="utf-8">
	<title><?php _e( 'Receipt', 'woocommerce-pos' ); ?></title>
	<style>
		/* Reset */
		* {
			background: transparent !important;
			color: #000 !important;
			box-shadow: none !important;
			text-shadow: none !important;
		}

		body, table {
			font-family: 'Arial', sans-serif;
			line-height: 1.4;
			font-size: 14px;
		}

		h1, h2, h3, h4, h5, h6 {
			margin: 0;
		}

		table {
			border-collapse: collapse;
			border-spacing: 0;
		}

		/* Spacing */
		.order-branding, .order-addresses, .order-info, .order-items, .order-notes, .order-thanks {
			margin-bottom: 40px;
		}

		/* Branding */
		.order-branding h1 {
			font-size: 2em;
			font-weight: bold;
		}

		/* Addresses */
		.order-addresses {
			display: table;
			width: 100%;
		}

		.billing-address, .shipping-address {
			display: table-cell;
		}

		/* Order */
		table {
			width: 100%;
		}

		table tr {
			border-bottom: 1px solid #dddddd;
		}

		table th, table td {
			padding: 6px 12px;
		}

		table.order-info {
			border-top: 3px solid #000;
		}

		table.order-info th {
			text-align: left;
			width: 30%;
		}

		table.order-items {
			border-bottom: 3px solid #000;
		}

		table.order-items thead tr {
			border-bottom: 3px solid #000;
		}

		table.order-items tbody tr:last-of-type {
			border-bottom: 1px solid #000;
		}

		.product {
			text-align: left;
		}

		.product dl {
			margin: 0;
		}

		.product dt {
			font-weight: 600;
			padding-right: 6px;
			float: left;
			clear: left;
		}

		.product dd {
			float: left;
			margin: 0;
		}

		.price {
			text-align: right;
		}

		.qty {
			text-align: center;
		}

		tfoot {
			text-align: right;
		}

		tfoot th {
			width: 70%;
		}

		tfoot tr.order-total {
			font-weight: bold;
		}

		tfoot tr.pos_cash-tendered th, tfoot tr.pos_cash-tendered td {
			border-top: 1px solid #000;
		}
	</style>
	<script>
		window.addEventListener("message", ({data}) => {
			if (data.action && data.action === "wcpos-print-receipt") {
				window.print();
			}
		}, false);
	</script>
</head>

<body>
<div class="order-branding">
	<h1><?php bloginfo( 'name' ); ?></h1>
</div>
<div class="order-addresses">
	<div class="billing-address">
		<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?>
	</div>
	<div class="shipping-address">
		<?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?>
	</div>
</div>
<table class="order-info">
	<tr>
		<th><?php _e( 'Order Number', 'woocommerce-pos' ); ?></th>
		<td><?php echo $order->get_order_number(); ?></td>
	</tr>
	<tr>
		<th><?php _e( 'Order Date', 'woocommerce-pos' ); ?></th>
		<td><?php echo wc_format_datetime( $order->get_date_paid() ); ?></td>
	</tr>
	<?php if ( $order->get_billing_email() ) { ?>
		<tr>
			<th>
            <?php
            // translators: woocommerce
				_e( 'Email', 'woocommerce' );
			?>
                </th>
			<td><?php echo $order->get_billing_email(); ?></td>
		</tr>
	<?php } ?>
	<?php if ( $order->get_billing_phone() ) { ?>
		<tr>
			<th>
            <?php
            // translators: woocommerce
				_e( 'Telephone', 'woocommerce' );
			?>
                </th>
			<td><?php echo $order->get_billing_phone(); ?></td>
		</tr>
	<?php } ?>
</table>
<table class="order-items">
	<thead>
	<tr>
		<th class="product">
        <?php
        // translators: woocommerce
			_e( 'Product', 'woocommerce' );
		?>
            </th>
		<th class="qty"><?php _ex( 'Qty', 'Abbreviation of Quantity', 'woocommerce-pos' ); ?></th>
		<th class="price">
        <?php
        // translators: woocommerce
			_e( 'Price', 'woocommerce' );
		?>
            </th>
	</tr>
	</thead>
	<tbody>
	<?php
    $items = $order->get_items( 'line_item' );
	if ( $items ) {
		foreach ( $items as $item ) {
			?>
		<tr>
			<td class="product">
				<?php echo $item->get_name(); ?>
				<?php wc_display_item_meta( $item ); ?>
			</td>
			<td class="qty">
				<?php echo $item->get_quantity(); ?>
			</td>
			<td class="price">
				<?php echo $order->get_formatted_line_subtotal( $item ); ?>
			</td>
		</tr>
			<?php
		}
	}
	?>
	</tbody>
	<tfoot>
	<?php
	foreach ( $order->get_order_item_totals() as $key => $total ) {
		?>
		<tr class="order-total">
			<th colspan="2"><?php echo esc_html( $total['label'] ); ?></th>
			<td colspan="1"><?php echo ( 'payment_method' === $key ) ? esc_html( $total['value'] ) : wp_kses_post( $total['value'] ); ?></td>
		</tr>
	<?php } ?>
	</tfoot>
</table>
<div class="order-notes"><?php $order->get_customer_note(); ?></div>
</body>
</html>
