<?php
/**
 * Sales Receipt Template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/receipt.php.
 * HOWEVER, this is not recommended — don't be surprised if your POS breaks.
 *
 * Available variables (always set before this file is included):
 *   $receipt_data — Array with all receipt information (store, meta, lines, totals, etc.)
 *   $order        — WC_Abstract_Order object (may be null in preview mode)
 *
 * See the WCPOS documentation for the full $receipt_data structure.
 *
 * @package WCPOS\WooCommercePOS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template files use short variable names by convention.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- $tax is a local loop variable, not the WP global.

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

$currency_args = array( 'currency' => $receipt_data['meta']['currency'] ?? get_woocommerce_currency() );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		html, body { margin: 0; padding: 0; font-family: sans-serif; font-size: 14px; color: #000; }
		body { padding: 20px; max-width: 680px; margin: 0 auto; }
		h1 { font-size: 22px; margin: 0 0 4px; }
		h2 { font-size: 16px; margin: 16px 0 8px; }
		h3 { font-size: 14px; margin: 12px 0 6px; }
		p { margin: 0 0 4px; }

		.store-header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #000; padding-bottom: 12px; }
		.store-header img { max-width: 200px; max-height: 100px; height: auto; display: block; margin: 0 auto 8px; }

		.meta { margin-bottom: 12px; font-size: 13px; }
		.meta table { width: 100%; border-collapse: collapse; }
		.meta td { padding: 2px 4px; vertical-align: top; }
		.meta td:last-child { text-align: right; }

		table.items { width: 100%; border-collapse: collapse; margin: 12px 0; }
		table.items th { border-bottom: 1px solid #000; padding: 4px; text-align: left; font-weight: bold; }
		table.items td { border-bottom: 1px solid #ddd; padding: 4px; vertical-align: top; }
		table.items th:last-child, table.items td:last-child { text-align: right; }
		table.items .item-meta { margin: 2px 0 0; font-size: 12px; color: #555; }

		table.totals { width: 100%; border-collapse: collapse; margin: 8px 0; }
		table.totals td { padding: 3px 4px; }
		table.totals td:last-child { text-align: right; font-weight: bold; }
		.grand-total td { font-size: 16px; font-weight: bold; border-top: 2px solid #000; padding-top: 6px; }

		.payments { margin-top: 8px; font-size: 13px; }
		.payments table { width: 100%; border-collapse: collapse; }
		.payments td { padding: 2px 4px; }
		.payments td:last-child { text-align: right; }

		.addresses { display: flex; gap: 20px; margin: 12px 0; font-size: 13px; }
		.addresses > div { flex: 1; }
		.notes { margin-top: 12px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 13px; }
		.footer { margin-top: 16px; border-top: 1px solid #ddd; padding-top: 8px; text-align: center; font-size: 12px; color: #555; }

		del { color: #999; }

		@media print { body { padding: 0; } }
	</style>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body>

<!-- Store Header -->
<div class="store-header">
	<?php if ( ! empty( $receipt_data['store']['logo'] ) ) : ?>
		<img src="<?php echo esc_url( $receipt_data['store']['logo'] ); ?>" alt="<?php echo esc_attr( $receipt_data['store']['name'] ?? '' ); ?>">
	<?php endif; ?>

	<h1><?php echo esc_html( $receipt_data['store']['name'] ?? get_bloginfo( 'name' ) ); ?></h1>

	<?php if ( ! empty( $receipt_data['store']['address_lines'] ) ) : ?>
		<p><?php echo esc_html( implode( ', ', $receipt_data['store']['address_lines'] ) ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $receipt_data['store']['phone'] ) ) : ?>
		<p><?php echo esc_html( $receipt_data['store']['phone'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $receipt_data['store']['email'] ) ) : ?>
		<p><?php echo esc_html( $receipt_data['store']['email'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $receipt_data['store']['tax_id'] ) ) : ?>
		<p><?php printf( /* translators: %s: tax ID */ esc_html__( 'Tax ID: %s', 'woocommerce-pos' ), esc_html( $receipt_data['store']['tax_id'] ) ); ?></p>
	<?php endif; ?>

	<?php // Pro feature: opening_hours is available when using the WCPOS Pro Stores add-on. ?>
	<?php if ( ! empty( $receipt_data['store']['opening_hours'] ) ) : ?>
		<p><?php echo esc_html( $receipt_data['store']['opening_hours'] ); ?></p>
	<?php endif; ?>
</div>

<!-- Order Meta -->
<div class="meta">
	<table>
		<tr>
			<td><?php esc_html_e( 'Receipt #', 'woocommerce-pos' ); ?></td>
			<td><?php echo esc_html( $receipt_data['meta']['order_number'] ?? '' ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Date', 'woocommerce-pos' ); ?></td>
			<?php
			$receipt_date_str = $receipt_data['meta']['created_at_local'] ?? $receipt_data['meta']['created_at_gmt'] ?? '';
			$receipt_ts       = strtotime( $receipt_date_str );
			?>
			<td><?php echo esc_html( false !== $receipt_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $receipt_ts ) : $receipt_date_str ); ?></td>
		</tr>
		<?php if ( ! empty( $receipt_data['cashier']['name'] ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Cashier', 'woocommerce-pos' ); ?></td>
				<td><?php echo esc_html( $receipt_data['cashier']['name'] ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( ! empty( $receipt_data['customer']['name'] ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Customer', 'woocommerce-pos' ); ?></td>
				<td><?php echo esc_html( $receipt_data['customer']['name'] ); ?></td>
			</tr>
		<?php endif; ?>
	</table>
</div>

<!-- Line Items -->
<table class="items">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Qty', 'woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $receipt_data['lines'] as $line ) : ?>
			<?php $has_discount = ( $line['discounts'] ?? 0 ) > 0; ?>
			<tr>
				<td>
					<?php echo esc_html( $line['name'] ?? '' ); ?>
					<?php if ( ! empty( $line['meta'] ) ) : ?>
						<?php foreach ( $line['meta'] as $meta_entry ) : ?>
							<div class="item-meta"><?php echo esc_html( $meta_entry['key'] ); ?>: <?php echo esc_html( $meta_entry['value'] ); ?></div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ! empty( $line['sku'] ) ) : ?>
						<div class="item-meta"><?php echo esc_html( $line['sku'] ); ?></div>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $has_discount ) : ?>
						<?php echo esc_html( $line['qty'] ?? 0 ); ?> &times;
						<del><?php echo wp_kses_post( wc_price( $line['unit_subtotal'] ?? 0, $currency_args ) ); ?></del>
						<?php echo wp_kses_post( wc_price( $line['unit_price'] ?? 0, $currency_args ) ); ?>
					<?php else : ?>
						<?php echo esc_html( $line['qty'] ?? 0 ); ?> &times;
						<?php echo wp_kses_post( wc_price( $line['unit_price'] ?? 0, $currency_args ) ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo wp_kses_post( wc_price( $line['line_total'] ?? 0, $currency_args ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<!-- Totals -->
<table class="totals">
	<tr>
		<td><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></td>
		<td><?php echo wp_kses_post( wc_price( $receipt_data['totals']['subtotal'] ?? 0, $currency_args ) ); ?></td>
	</tr>

	<?php foreach ( $receipt_data['discounts'] as $discount ) : ?>
		<tr>
			<td>
				<?php echo esc_html( $discount['label'] ?? __( 'Discount', 'woocommerce' ) ); ?>
				<?php if ( ! empty( $discount['codes'] ) ) : ?>
					(<?php echo esc_html( $discount['codes'] ); ?>)
				<?php endif; ?>
			</td>
			<td>-<?php echo wp_kses_post( wc_price( $discount['total'] ?? 0, $currency_args ) ); ?></td>
		</tr>
	<?php endforeach; ?>

	<?php foreach ( $receipt_data['shipping'] as $ship ) : ?>
		<tr>
			<td><?php echo esc_html( $ship['label'] ?? __( 'Shipping', 'woocommerce' ) ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $ship['total'] ?? 0, $currency_args ) ); ?></td>
		</tr>
	<?php endforeach; ?>

	<?php foreach ( $receipt_data['fees'] as $fee ) : ?>
		<tr>
			<td><?php echo esc_html( $fee['label'] ?? __( 'Fee', 'woocommerce' ) ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $fee['total'] ?? 0, $currency_args ) ); ?></td>
		</tr>
	<?php endforeach; ?>

	<?php
	$display_tax = $receipt_data['presentation_hints']['display_tax'] ?? '';
	$tax_total   = $receipt_data['totals']['tax_total'] ?? 0;
	?>
	<?php if ( 'hidden' !== $display_tax && ! empty( $tax_total ) ) : ?>
		<?php foreach ( $receipt_data['tax_summary'] as $tax ) : ?>
			<tr>
				<td>
					<?php echo esc_html( $tax['label'] ?? '' ); ?>
					<?php if ( ! empty( $tax['rate'] ) ) : ?>
						(<?php echo esc_html( $tax['rate'] ); ?>%)
					<?php endif; ?>
				</td>
				<td><?php echo wp_kses_post( wc_price( $tax['tax_amount'] ?? 0, $currency_args ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>

	<tr class="grand-total">
		<td><?php esc_html_e( 'Total', 'woocommerce' ); ?></td>
		<td><?php echo wp_kses_post( wc_price( $receipt_data['totals']['grand_total'] ?? 0, $currency_args ) ); ?></td>
	</tr>
</table>

<!-- Payments -->
<?php if ( ! empty( $receipt_data['payments'] ) ) : ?>
	<div class="payments">
		<table>
			<?php foreach ( $receipt_data['payments'] as $payment ) : ?>
				<tr>
					<td><?php echo esc_html( $payment['method_title'] ?? '' ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $payment['amount'] ?? 0, $currency_args ) ); ?></td>
				</tr>
				<?php if ( ! empty( $payment['tendered'] ) && (float) $payment['tendered'] > 0 ) : ?>
					<tr>
						<td>&nbsp;&nbsp;<?php esc_html_e( 'Tendered', 'woocommerce-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $payment['tendered'], $currency_args ) ); ?></td>
					</tr>
					<tr>
						<td>&nbsp;&nbsp;<?php esc_html_e( 'Change', 'woocommerce-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $payment['change'] ?? 0, $currency_args ) ); ?></td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</table>
	</div>
<?php endif; ?>

<!-- Addresses -->
<?php
$billing_addr  = $receipt_data['customer']['billing_address'] ?? array();
$shipping_addr = $receipt_data['customer']['shipping_address'] ?? array();
$has_billing   = ! empty( array_filter( $billing_addr ) );
$has_shipping  = ! empty( array_filter( $shipping_addr ) );
?>
<?php if ( $has_billing || $has_shipping ) : ?>
	<div class="addresses">
		<?php if ( $has_billing ) : ?>
			<div>
				<h3><?php esc_html_e( 'Billing Address', 'woocommerce' ); ?></h3>
				<address>
					<?php
					$parts = array_filter(
						array(
							trim( ( $billing_addr['first_name'] ?? '' ) . ' ' . ( $billing_addr['last_name'] ?? '' ) ),
							$billing_addr['company'] ?? '',
							$billing_addr['address_1'] ?? '',
							$billing_addr['address_2'] ?? '',
							trim( ( $billing_addr['city'] ?? '' ) . ', ' . ( $billing_addr['state'] ?? '' ) . ' ' . ( $billing_addr['postcode'] ?? '' ), ' ,' ),
							$billing_addr['country'] ?? '',
						)
					);
					echo implode( '<br>', array_map( 'esc_html', $parts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</address>
				<?php if ( ! empty( $billing_addr['phone'] ) ) : ?>
					<p><?php echo esc_html( $billing_addr['phone'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $billing_addr['email'] ) ) : ?>
					<p><?php echo esc_html( $billing_addr['email'] ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $has_shipping ) : ?>
			<div>
				<h3><?php esc_html_e( 'Shipping Address', 'woocommerce' ); ?></h3>
				<address>
					<?php
					$parts = array_filter(
						array(
							trim( ( $shipping_addr['first_name'] ?? '' ) . ' ' . ( $shipping_addr['last_name'] ?? '' ) ),
							$shipping_addr['company'] ?? '',
							$shipping_addr['address_1'] ?? '',
							$shipping_addr['address_2'] ?? '',
							trim( ( $shipping_addr['city'] ?? '' ) . ', ' . ( $shipping_addr['state'] ?? '' ) . ' ' . ( $shipping_addr['postcode'] ?? '' ), ' ,' ),
							$shipping_addr['country'] ?? '',
						)
					);
					echo implode( '<br>', array_map( 'esc_html', $parts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</address>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<!-- Notes -->
<?php if ( ! empty( $receipt_data['meta']['customer_note'] ) ) : ?>
	<div class="notes">
		<h3><?php esc_html_e( 'Customer Notes', 'woocommerce' ); ?></h3>
		<p><?php echo wp_kses_post( nl2br( $receipt_data['meta']['customer_note'] ) ); ?></p>
	</div>
<?php endif; ?>

<!-- Footer -->
<?php // Pro feature: personal_notes, policies_and_conditions, and footer_imprint are available when using the WCPOS Pro Stores add-on. ?>
<?php if ( ! empty( $receipt_data['store']['personal_notes'] ) ) : ?>
	<div class="footer">
		<p><?php echo wp_kses_post( nl2br( $receipt_data['store']['personal_notes'] ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $receipt_data['store']['policies_and_conditions'] ) ) : ?>
	<div class="footer">
		<p><?php echo wp_kses_post( nl2br( $receipt_data['store']['policies_and_conditions'] ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $receipt_data['store']['footer_imprint'] ) ) : ?>
	<div class="footer">
		<p><?php echo wp_kses_post( nl2br( $receipt_data['store']['footer_imprint'] ) ); ?></p>
	</div>
<?php endif; ?>

</body>
</html>
