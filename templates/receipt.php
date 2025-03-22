<?php
/**
 * Sales Receipt Template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/receipt.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
	<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
			html, body, ul, li, fieldset, address {
				font-family: sans-serif;
				font-size: 14px;
				margin: 0;
				padding: 0;
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

			.sales-receipt {
				width: 100%;
				max-width: 100%;
			}
			.header, .footer {
				text-align: center;
				margin-bottom: 20px;
			}
			.header img {
				max-width: 200px;
				height: auto;
			}
			.order-details {
				margin-bottom: 20px;
				padding: 5px;
			}
			.order-details li {
				margin-bottom: 10px;
			}

			/* Style for Order Details Table */
			table {
				border-collapse: collapse !important;
				width: 100% !important;
				margin-bottom: 20px;
			}
			table thead tr th,
			table tbody tr td,
			table tfoot tr td {
				border: none;
				padding: 5px;
				text-align: left;
			}
			table thead tr th {
				font-weight: bold;
				border-bottom: 2px solid #ddd;
			}
			table tbody tr td {
				border-bottom: 1px solid #ddd;
			}
			table tfoot tr th {
				padding: 5px;
				text-align: right;
			}
			th:last-child, td:last-child {
				text-align: right;
			}
			table ul.wc-item-meta {
				padding: 0;
				list-style: none;
				margin-top: 5px !important;
			}
			table ul.wc-item-meta li {
				font-size: 12px;
			}
			table ul.wc-item-meta p {
				display: inline-block;
				margin: 0;
			}

			/* Style for Customer Details */
			.woocommerce-customer-details {
				margin-bottom: 20px;
			}
			.woocommerce-columns {
				display: flex;
				justify-content: space-between;
				margin-bottom: 10px;
				padding: 5px;
			}
			.woocommerce-column {
				flex: 0 0 calc(50% - 10px);
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
		</style>
		<?php
		/**
		 * IMPORTANT!
		 * This hook adds the javascript to print the receipt.
		 */
		do_action( 'woocommerce_pos_receipt_head' );
		?>
	</head>
<body <?php body_class(); ?>>
<div class="sales-receipt">
	<div class="header">
		<?php
		$header_image = get_theme_mod( 'custom_logo' );
		if ( $header_image ) {
			$image_attributes = wp_get_attachment_image_src( $header_image, 'full' );
			$src              = $image_attributes[0];
			?>
			<img src="<?php echo esc_url( $src ); ?>" alt="<?php bloginfo( 'name' ); ?>">
		<?php } else { ?>
			<h1><?php bloginfo( 'name' ); ?></h1>
		<?php } ?>
		<h2><?php esc_html_e( 'Tax Receipt', 'woocommerce-pos' ); ?></h2>
	</div>

	<ul class="order-details">
		<li class="order">
			<?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
			<strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
		</li>
		<li class="date">
			<?php esc_html_e( 'Date:', 'woocommerce' ); ?>
			<strong><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'F j, Y, g:i a' ) ); ?></strong>
		</li>
		<?php
		// if order has meta value _pos_user, get the user id and display the user name
		$pos_user = $order->get_meta( '_pos_user' );
		if ( $pos_user ) {
			$user      = get_user_by( 'id', $pos_user );
			$user_name = $user->display_name;
			echo '<li class="cashier">' . esc_html__( 'Cashier', 'woocommerce-pos' ) . ':<strong> ' . esc_html( $user_name ) . '</strong></li>';
		}
		?>
		<?php if ( $order->get_payment_method_title() ) { ?>
			<li class="method">
				<?php esc_html_e( 'Payment method:', 'woocommerce' ); ?>
				<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
			</li>
		<?php } ?>
	</ul>

	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Quantity', 'woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Price', 'woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
	<?php
	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product();
		?>
		<tr>
			<td>
				<?php
					echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );

				do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );

				wc_display_item_meta( $item );

				do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
				?>
			</td>
			<td><?php echo esc_html( $item->get_quantity() ); ?></td>
			<td><?php echo \is_object( $product ) && method_exists( $product, 'get_price' ) ? wp_kses_post( wc_price( $product->get_price() ) ) : ''; ?></td>
			<td><?php echo wp_kses_post( wc_price( $item->get_total() ) ); ?></td>
		</tr>
	<?php } ?>
		</tbody>
		<tfoot>
			<tr>
				<th colspan="3"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
				<td><?php echo wp_kses_post( wc_price( $order->get_subtotal() ) ); ?></td>
			</tr>
			<?php if ( $order->get_shipping_total() > 0 ) { ?>
				<tr>
					<th colspan="3"><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></th>
					<td><?php echo wp_kses_post( wc_price( $order->get_shipping_total() ) ); ?></td>
				</tr>
			<?php } ?>
			<?php foreach ( $order->get_fees() as $fee ) { ?>
				<tr>
					<th colspan="3"><?php echo esc_html( $fee->get_name() ); ?></th>
					<td><?php echo wp_kses_post( wc_price( $fee->get_total() ) ); ?></td>
				</tr>
			<?php } ?>
			<?php if ( $order->get_total_discount() > 0 ) { ?>
				<tr>
					<th colspan="3"><?php esc_html_e( 'Discount', 'woocommerce' ); ?></th>
					<td><?php echo wp_kses_post( wc_price( $order->get_total_discount() ) ); ?></td>
				</tr>
			<?php } ?>
			<?php if ( wc_tax_enabled() ) { ?>
				<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) { ?>
					<?php foreach ( $order->get_tax_totals() as $code => $tax ) { ?>
						<tr>
							<th colspan="3"><?php echo esc_html( $tax->label ); ?></th>
							<td><?php echo wp_kses_post( wc_price( $tax->amount ) ); ?></td>
						</tr>
					<?php } ?>
				<?php } else { ?>
					<tr>
						<th colspan="3"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
						<td><?php echo wp_kses_post( wc_price( $order->get_total_tax() ) ); ?></td>
					</tr>
				<?php } ?>
			<?php } ?>
			<tr>
				<th colspan="3"><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
				<td><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></td>
			</tr>
			<?php if ( $order->get_payment_method() === 'pos_cash' ) : ?>
					<?php
						$amount_tendered = $order->get_meta( '_pos_cash_amount_tendered' );
						$change_given    = $order->get_meta( '_pos_cash_change' );
					?>
					<?php if ( ! empty( $amount_tendered ) ) : ?>
						<tr>
							<th colspan="3"><?php esc_html_e( 'Amount Tendered', 'woocommerce-pos' ); ?></th>
							<td><?php echo wp_kses_post( wc_price( $amount_tendered ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $change_given ) ) : ?>
						<tr>
							<th colspan="3"><?php esc_html_e( 'Change', 'woocommerce-pos' ); ?></th>
							<td><?php echo wp_kses_post( wc_price( $change_given ) ); ?></td>
						</tr>
					<?php endif; ?>
			<?php endif; ?>
		</tfoot>
	</table>

	<div class="address-fields">
		<section class="woocommerce-customer-details">

			<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
				<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">


					<h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

					<address>
						<?php echo wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

						<?php if ( $order->get_billing_phone() ) { ?>
							<p class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_billing_phone() ); ?></p>
						<?php } ?>

						<?php if ( $order->get_billing_email() ) { ?>
							<p class="woocommerce-customer-details--email"><?php echo esc_html( $order->get_billing_email() ); ?></p>
						<?php } ?>
					</address>

				</div><!-- /.col-1 -->

				<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
					<h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
					<address>
						<?php echo wp_kses_post( $order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

						<?php if ( $order->get_shipping_phone() ) { ?>
							<p class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_shipping_phone() ); ?></p>
						<?php } ?>
					</address>
				</div><!-- /.col-2 -->

			</section><!-- /.col2-set -->

			<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>

		</section>
	</div>

<?php if ( $order->get_customer_note() ) { ?>
	<div class="customer-notes">
		<h4 class="section-title"><?php esc_html_e( 'Customer Notes', 'woocommerce' ); ?></h4>
		<p><?php echo wp_kses_post( nl2br( $order->get_customer_note() ) ); ?></p>
	</div>
<?php } ?>

</div>

</body>
</html>
