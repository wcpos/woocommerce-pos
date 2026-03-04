<?php
/**
 * Thermal Printer Receipt — Example Template.
 *
 * Designed for 80mm / 58mm thermal receipt printers.
 * Uses a narrow, single-column layout with no background colors
 * or images that waste ink/heat.
 *
 * Available variables:
 *   $order        — WC_Abstract_Order object (full WooCommerce API)
 *   $receipt_data — Canonical receipt payload (array)
 *   $template     — Template metadata (array)
 *
 * @see https://docs.wcpos.com/templates/receipt-data-reference
 *
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

$store    = $receipt_data['store'];
$meta     = $receipt_data['meta'];
$cashier  = $receipt_data['cashier'];
$customer = $receipt_data['customer'];
$lines    = $receipt_data['lines'];
$totals   = $receipt_data['totals'];
$payments = $receipt_data['payments'];
$hints    = $receipt_data['presentation_hints'];
$currency = $meta['currency'];

$money = function ( float $amount ) use ( $currency ): string {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ) );
};

$separator = str_repeat( '-', 42 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		@page { margin: 0; }
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			font-family: "Courier New", Courier, monospace;
			font-size: 12px;
			line-height: 1.4;
			width: 72mm;
			padding: 4mm;
			color: #000;
		}
		.center { text-align: center; }
		.bold { font-weight: 700; }
		.sep { text-align: center; color: #888; margin: 4px 0; letter-spacing: -1px; overflow: hidden; }
		.row { display: flex; justify-content: space-between; }
		.row .label { flex: 1; }
		.row .value { flex-shrink: 0; text-align: right; }
		.item-name { font-weight: 600; }
		.item-detail { display: flex; justify-content: space-between; color: #444; font-size: 11px; padding-left: 8px; }
		.total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 14px; border-top: 2px solid #000; margin-top: 4px; padding-top: 4px; }
		.small { font-size: 10px; color: #555; }
		.mb { margin-bottom: 6px; }
		@media print {
			body { width: 72mm; }
		}
	</style>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body>

	<div class="center bold mb" style="font-size: 16px;">
		<?php echo esc_html( $store['name'] ); ?>
	</div>

	<?php if ( ! empty( $store['address_lines'] ) ) : ?>
		<div class="center small mb">
			<?php echo esc_html( implode( ', ', $store['address_lines'] ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $store['phone'] ) ) : ?>
		<div class="center small"><?php echo esc_html( $store['phone'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $store['tax_id'] ) ) : ?>
		<div class="center small">
			<?php
			printf(
				/* translators: %s: tax identification number */
				esc_html__( 'Tax ID: %s', 'woocommerce-pos' ),
				esc_html( $store['tax_id'] )
			);
			?>
		</div>
	<?php endif; ?>

	<div class="sep"><?php echo esc_html( $separator ); ?></div>

	<div class="center bold mb"><?php esc_html_e( 'SALES RECEIPT', 'woocommerce-pos' ); ?></div>

	<div class="row"><span class="label"><?php esc_html_e( 'Receipt #', 'woocommerce-pos' ); ?></span><span class="value"><?php echo esc_html( $meta['order_number'] ); ?></span></div>
	<div class="row"><span class="label"><?php esc_html_e( 'Date', 'woocommerce-pos' ); ?></span><span class="value"><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $meta['created_at_gmt'] ) ) ); ?></span></div>

	<?php if ( ! empty( $cashier['name'] ) ) : ?>
		<div class="row"><span class="label"><?php esc_html_e( 'Cashier', 'woocommerce-pos' ); ?></span><span class="value"><?php echo esc_html( $cashier['name'] ); ?></span></div>
	<?php endif; ?>

	<?php if ( ! empty( $customer['name'] ) ) : ?>
		<div class="row"><span class="label"><?php esc_html_e( 'Customer', 'woocommerce-pos' ); ?></span><span class="value"><?php echo esc_html( $customer['name'] ); ?></span></div>
	<?php endif; ?>

	<div class="sep"><?php echo esc_html( $separator ); ?></div>

	<?php foreach ( $lines as $line ) : ?>
		<div class="item-name"><?php echo esc_html( $line['name'] ); ?></div>
		<div class="item-detail">
			<span>
				<?php echo esc_html( $line['qty'] ); ?> x <?php echo esc_html( $money( $line['unit_price_incl'] ) ); ?>
				<?php if ( ! empty( $line['sku'] ) ) : ?>
					(<?php echo esc_html( $line['sku'] ); ?>)
				<?php endif; ?>
			</span>
			<span><?php echo esc_html( $money( $line['line_total_incl'] ) ); ?></span>
		</div>
	<?php endforeach; ?>

	<div class="sep"><?php echo esc_html( $separator ); ?></div>

	<div class="row">
		<span class="label"><?php esc_html_e( 'Subtotal', 'woocommerce-pos' ); ?></span>
		<span class="value"><?php echo esc_html( $money( $totals['subtotal_incl'] ) ); ?></span>
	</div>

	<?php if ( $totals['discount_total_incl'] > 0 ) : ?>
		<div class="row">
			<span class="label"><?php esc_html_e( 'Discount', 'woocommerce-pos' ); ?></span>
			<span class="value">-<?php echo esc_html( $money( $totals['discount_total_incl'] ) ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( 'hidden' !== $hints['display_tax'] && $totals['tax_total'] > 0 ) : ?>
		<?php foreach ( $receipt_data['tax_summary'] as $tax ) : ?>
			<div class="row">
				<span class="label">
					<?php echo esc_html( $tax['label'] ); ?>
					<?php if ( $tax['rate'] ) : ?>
						(<?php echo esc_html( $tax['rate'] ); ?>%)
					<?php endif; ?>
				</span>
				<span class="value"><?php echo esc_html( $money( $tax['tax_amount'] ) ); ?></span>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<div class="total-row">
		<span><?php esc_html_e( 'TOTAL', 'woocommerce-pos' ); ?></span>
		<span><?php echo esc_html( $money( $totals['grand_total_incl'] ) ); ?></span>
	</div>

	<div class="sep"><?php echo esc_html( $separator ); ?></div>

	<?php foreach ( $payments as $payment ) : ?>
		<div class="row">
			<span class="label"><?php echo esc_html( $payment['method_title'] ); ?></span>
			<span class="value"><?php echo esc_html( $money( $payment['amount'] ) ); ?></span>
		</div>
		<?php if ( $payment['tendered'] > 0 ) : ?>
			<div class="row small">
				<span class="label">&nbsp;&nbsp;<?php esc_html_e( 'Tendered', 'woocommerce-pos' ); ?></span>
				<span class="value"><?php echo esc_html( $money( $payment['tendered'] ) ); ?></span>
			</div>
			<div class="row small">
				<span class="label">&nbsp;&nbsp;<?php esc_html_e( 'Change', 'woocommerce-pos' ); ?></span>
				<span class="value"><?php echo esc_html( $money( $payment['change'] ) ); ?></span>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( $order->get_customer_note() ) : ?>
		<div class="sep"><?php echo esc_html( $separator ); ?></div>
		<div class="small">
			<span class="bold"><?php esc_html_e( 'Note:', 'woocommerce-pos' ); ?></span>
			<?php echo esc_html( $order->get_customer_note() ); ?>
		</div>
	<?php endif; ?>

	<div class="sep"><?php echo esc_html( $separator ); ?></div>

	<div class="center small mb">
		<?php esc_html_e( 'Thank you for your purchase!', 'woocommerce-pos' ); ?>
	</div>

</body>
</html>
