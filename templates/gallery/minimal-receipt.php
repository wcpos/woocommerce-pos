<?php
/**
 * Minimal Receipt — Example Template.
 *
 * A clean, data-driven receipt that uses the $receipt_data payload
 * instead of calling WooCommerce functions directly.
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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template files use short variable names by convention.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- $tax is a local loop variable, not the WP global.

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

$store    = $receipt_data['store'];
$meta     = $receipt_data['meta'];
$cashier  = $receipt_data['cashier'];
$lines    = $receipt_data['lines'];
$totals   = $receipt_data['totals'];
$payments = $receipt_data['payments'];
$hints    = $receipt_data['presentation_hints'];
$currency = $meta['currency'];

/**
 * Helper — format a number as currency using the order's currency.
 *
 * @param float $amount Amount to format.
 * @return string
 */
$money = function ( float $amount ) use ( $currency ): string {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ) );
};
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			font-size: 13px;
			color: #333;
			padding: 20px;
			max-width: 400px;
			margin: 0 auto;
		}
		.store-name { font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 4px; }
		.store-address { text-align: center; color: #666; font-size: 12px; margin-bottom: 16px; }
		.divider { border: none; border-top: 1px dashed #ccc; margin: 12px 0; }
		.meta-row { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px; color: #555; }
		table { width: 100%; border-collapse: collapse; margin: 8px 0; }
		th { text-align: left; font-weight: 600; font-size: 12px; border-bottom: 1px solid #ddd; padding: 6px 4px; }
		th:last-child, td:last-child { text-align: right; }
		td { padding: 6px 4px; border-bottom: 1px solid #eee; font-size: 12px; }
		.sku { color: #999; font-size: 11px; }
		.totals td { border-bottom: none; padding: 3px 4px; }
		.totals .grand-total td { font-weight: 700; font-size: 14px; border-top: 2px solid #333; padding-top: 8px; }
		.payment { text-align: center; margin-top: 12px; font-size: 12px; color: #555; }
		.footer { text-align: center; margin-top: 20px; font-size: 11px; color: #999; }
	</style>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body>

	<div class="store-name"><?php echo esc_html( $store['name'] ); ?></div>

	<?php if ( ! empty( $store['address_lines'] ) ) : ?>
		<div class="store-address"><?php echo esc_html( implode( ', ', $store['address_lines'] ) ); ?></div>
	<?php endif; ?>

	<hr class="divider">

	<div class="meta-row">
		<span><?php esc_html_e( 'Order', 'woocommerce-pos' ); ?></span>
		<span>#<?php echo esc_html( $meta['order_number'] ); ?></span>
	</div>
	<div class="meta-row">
		<span><?php esc_html_e( 'Date', 'woocommerce-pos' ); ?></span>
		<span><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $meta['created_at_local'] ?? $meta['created_at_gmt'] ) ) ); ?></span>
	</div>
	<?php if ( ! empty( $cashier['name'] ) ) : ?>
		<div class="meta-row">
			<span><?php esc_html_e( 'Cashier', 'woocommerce-pos' ); ?></span>
			<span><?php echo esc_html( $cashier['name'] ); ?></span>
		</div>
	<?php endif; ?>

	<hr class="divider">

	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Item', 'woocommerce-pos' ); ?></th>
				<th><?php esc_html_e( 'Qty', 'woocommerce-pos' ); ?></th>
				<th><?php esc_html_e( 'Total', 'woocommerce-pos' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lines as $line ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $line['name'] ); ?>
						<?php if ( ! empty( $line['sku'] ) ) : ?>
							<br><span class="sku"><?php echo esc_html( $line['sku'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $line['qty'] ); ?></td>
					<td><?php echo esc_html( $money( $line['line_total_incl'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<table class="totals">
		<tr>
			<td><?php esc_html_e( 'Subtotal', 'woocommerce-pos' ); ?></td>
			<td><?php echo esc_html( $money( $totals['subtotal_incl'] ) ); ?></td>
		</tr>

		<?php if ( $totals['discount_total_incl'] > 0 ) : ?>
			<tr>
				<td><?php esc_html_e( 'Discount', 'woocommerce-pos' ); ?></td>
				<td>-<?php echo esc_html( $money( $totals['discount_total_incl'] ) ); ?></td>
			</tr>
		<?php endif; ?>

		<?php if ( 'hidden' !== $hints['display_tax'] && $totals['tax_total'] > 0 ) : ?>
			<?php foreach ( $receipt_data['tax_summary'] as $tax ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $tax['label'] ); ?>
						<?php if ( ! empty( $tax['rate'] ) ) : ?>
							(<?php echo esc_html( $tax['rate'] ); ?>%)
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $money( $tax['tax_amount'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>

		<tr class="grand-total">
			<td><?php esc_html_e( 'Total', 'woocommerce-pos' ); ?></td>
			<td><?php echo esc_html( $money( $totals['grand_total_incl'] ) ); ?></td>
		</tr>
	</table>

	<?php foreach ( $payments as $payment ) : ?>
		<div class="payment">
			<?php echo esc_html( $payment['method_title'] ); ?>
			<?php if ( $payment['tendered'] > 0 ) : ?>
				&mdash;
				<?php
				printf(
					/* translators: 1: amount tendered 2: change given */
					esc_html__( 'Tendered %1$s / Change %2$s', 'woocommerce-pos' ),
					esc_html( $money( $payment['tendered'] ) ),
					esc_html( $money( $payment['change'] ) )
				);
				?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<div class="footer">
		<?php esc_html_e( 'Thank you for your purchase!', 'woocommerce-pos' ); ?>
	</div>

</body>
</html>
