<?php
/**
 * Sales Receipt Template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/receipt.php.
 * HOWEVER, this is not recommended — don't be surprised if your POS breaks.
 *
 * Available variables (always set before this file is included):
 *   $receipt_data — Array with all receipt information (store, meta, lines, totals, etc.)
 *   $order        — WC_Abstract_Order object for this receipt
 *
 * See the WCPOS documentation for the full $receipt_data structure.
 *
 * @package WCPOS\WooCommercePOS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template files use short variable names by convention.

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

$currency_args = array( 'currency' => $receipt_data['order']['currency'] ?? $receipt_data['meta']['currency'] ?? get_woocommerce_currency() );

$i18n = $receipt_data['i18n'] ?? array();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		html, body { margin: 0; padding: 0; background: #fff; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
			font-size: 13px;
			line-height: 1.45;
			color: #1f2937;
		}
		.receipt {
			box-sizing: border-box;
			padding: 32px 36px;
		}

		/* Header */
		.receipt-header {
			display: flex;
			align-items: flex-start;
			gap: 22px;
			padding-bottom: 18px;
			border-bottom: 2px solid #111827;
		}
		.receipt-header .logo { flex: 0 0 auto; }
		.receipt-header .logo img { width: 88px; height: auto; display: block; }
		.receipt-header .store { flex: 1 1 auto; min-width: 0; }
		.receipt-header .store-name { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; }
		.receipt-header .store-info { color: #6b7280; font-size: 12px; margin-top: 2px; }
		.receipt-header .meta { flex: 0 0 auto; text-align: right; }
		.status-pill {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 4px 10px;
			background: #f3f4f6;
			color: #1f2937;
			border-radius: 999px;
			font-weight: 700;
			font-size: 10px;
			letter-spacing: 0.10em;
			text-transform: uppercase;
			margin-bottom: 8px;
		}
		.status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: #6b7280; }
		.meta-label { font-size: 11px; letter-spacing: 0.16em; color: #6b7280; text-transform: uppercase; }
		.meta-number { font-size: 22px; font-weight: 700; margin-top: 2px; }
		.meta-line { color: #6b7280; font-size: 12px; }
		.meta-line.spaced { margin-top: 6px; }

		/* Items */
		.items { width: 100%; border-collapse: collapse; margin-top: 22px; font-size: 13px; }
		.items th {
			text-align: left;
			font-weight: 600;
			color: #6b7280;
			padding: 8px 6px;
			border-bottom: 1px solid #e5e7eb;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}
		.items th.num, .items td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
		.items td { padding: 10px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
		.items .name { font-weight: 600; }
		.items .total-final { font-weight: 600; }
		.items .total-strike { font-size: 11px; color: #9ca3af; }
		.items tr.discount-row { color: #15803d; }

		/* Totals */
		.totals { margin-top: 20px; max-width: 280px; margin-left: auto; font-size: 13px; }
		.totals-row { display: flex; justify-content: space-between; padding: 2px 0; }
		.totals-row .label { color: #6b7280; }
		.totals-row .amount { font-variant-numeric: tabular-nums; }
		.totals-divider { border-top: 2px solid #111827; margin: 8px 0; }
		.totals-row.grand { padding: 4px 0; font-size: 18px; font-weight: 700; }
		.totals-row.grand .label { color: #1f2937; }
		.totals-row.refund { font-size: 12px; color: #b91c1c; }
		.totals-row.net { padding: 4px 0; font-size: 13px; font-weight: 600; border-top: 1px solid #e5e7eb; }

		/* Cards */
		.card { margin-top: 20px; padding: 14px 16px; border-radius: 8px; font-size: 12px; line-height: 1.5; }
		.card-label { font-size: 10px; letter-spacing: 0.12em; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; font-weight: 700; }
		.card.refunds { background: #fff1f2; border: 1px solid #fecdd3; }
		.card.refunds .row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; }
		.card.refunds .row .amount { font-variant-numeric: tabular-nums; font-weight: 700; }
		.card.refunds .reason { color: #6b7280; font-size: 11px; }
		.card.note { background: #fffbeb; border: 1px solid #fde68a; }
		.card.note .body { color: #92400e; white-space: pre-wrap; }

		/* Payments */
		.payments { margin-top: 24px; padding-top: 18px; border-top: 1px solid #e5e7eb; }
		.payments-label { font-size: 10px; letter-spacing: 0.12em; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; font-weight: 700; }
		.payment-row { display: flex; justify-content: space-between; padding: 2px 0; }
		.payment-row .amount { font-variant-numeric: tabular-nums; }
		.payment-sub { font-size: 11px; color: #6b7280; padding-left: 4px; display: flex; justify-content: space-between; }
		.payment-sub .amount { font-variant-numeric: tabular-nums; }

		/* Footer */
		.footer { margin-top: 24px; padding-top: 14px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px; font-style: italic; }
		.footer-imprint { margin-top: 10px; text-align: center; font-size: 11px; color: #6b7280; }

		del { color: #9ca3af; }

		@media print {
			body { background: #fff; }
		}
	</style>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body>

<div class="receipt">

	<!-- Header -->
	<header class="receipt-header">
		<?php if ( ! empty( $receipt_data['store']['logo'] ) ) : ?>
			<div class="logo">
				<img src="<?php echo esc_url( $receipt_data['store']['logo'] ); ?>" alt="<?php echo esc_attr( $receipt_data['store']['name'] ?? '' ); ?>">
			</div>
		<?php endif; ?>

		<div class="store">
			<div class="store-name"><?php echo esc_html( $receipt_data['store']['name'] ?? get_bloginfo( 'name' ) ); ?></div>
			<?php if ( ! empty( $receipt_data['store']['address_lines'] ) ) : ?>
				<?php foreach ( $receipt_data['store']['address_lines'] as $address_line ) : ?>
					<div class="store-info"><?php echo esc_html( $address_line ); ?></div>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( ! empty( $receipt_data['store']['phone'] ) ) : ?>
				<div class="store-info"><?php echo esc_html( $receipt_data['store']['phone'] ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $receipt_data['store']['tax_ids'] ) ) : ?>
				<?php foreach ( $receipt_data['store']['tax_ids'] as $tax_id ) : ?>
					<?php
					$tax_label = $tax_id['label'] ?? '';
					$tax_value = $tax_id['value'] ?? '';
					$tax_text  = '' !== $tax_label ? $tax_label . ' ' . $tax_value : $tax_value;
					?>
					<div class="store-info"><?php echo esc_html( $tax_text ); ?></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="meta">
			<?php
			$status_label = $receipt_data['order']['status_label'] ?? '';
			$wc_status    = $receipt_data['order']['wc_status'] ?? '';
			$status_text  = '' !== $status_label ? $status_label : $wc_status;
			?>
			<?php if ( '' !== $status_text ) : ?>
				<div class="status-pill"><span class="dot"></span><?php echo esc_html( $status_text ); ?></div>
			<?php endif; ?>

			<?php
			$doc_label = $receipt_data['fiscal']['document_label'] ?? '';
			if ( '' === $doc_label ) {
				$doc_label = $i18n['receipt'] ?? __( 'Receipt', 'woocommerce-pos' );
			}
			?>
			<div class="meta-label"><?php echo esc_html( $doc_label ); ?></div>

			<?php $order_number = $receipt_data['order']['number'] ?? $receipt_data['meta']['order_number'] ?? ''; ?>
			<div class="meta-number">#<?php echo esc_html( ltrim( (string) $order_number, '#' ) ); ?></div>

			<?php $created_at = $receipt_data['order']['created']['datetime'] ?? $receipt_data['meta']['created_at_local'] ?? $receipt_data['meta']['created_at_gmt'] ?? ''; ?>
			<?php if ( '' !== $created_at ) : ?>
				<div class="meta-line spaced"><?php echo esc_html( $created_at ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $receipt_data['cashier']['name'] ) ) : ?>
				<div class="meta-line"><?php echo esc_html( $i18n['cashier'] ?? __( 'Cashier', 'woocommerce-pos' ) ); ?>: <?php echo esc_html( $receipt_data['cashier']['name'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $receipt_data['customer']['name'] ) ) : ?>
				<div class="meta-line"><?php echo esc_html( $i18n['customer'] ?? __( 'Customer', 'woocommerce-pos' ) ); ?>: <?php echo esc_html( $receipt_data['customer']['name'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $receipt_data['customer']['tax_ids'] ) ) : ?>
				<?php foreach ( $receipt_data['customer']['tax_ids'] as $tax_id ) : ?>
					<?php
					$tax_label = $tax_id['label'] ?? '';
					$tax_value = $tax_id['value'] ?? '';
					$tax_text  = '' !== $tax_label ? $tax_label . ' ' . $tax_value : $tax_value;
					?>
					<div class="meta-line"><?php echo esc_html( $tax_text ); ?></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</header>

	<!-- Items -->
	<table class="items">
		<thead>
			<tr>
				<th><?php echo esc_html( $i18n['item_short'] ?? __( 'Item', 'woocommerce-pos' ) ); ?></th>
				<th class="num"><?php echo esc_html( $i18n['qty_short'] ?? __( 'Qty', 'woocommerce-pos' ) ); ?></th>
				<th class="num"><?php echo esc_html( $i18n['total'] ?? __( 'Total', 'woocommerce-pos' ) ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $receipt_data['lines'] as $line ) : ?>
				<?php $has_discount = ( $line['discounts'] ?? 0 ) > 0; ?>
				<tr>
					<td>
						<div class="name"><?php echo esc_html( $line['name'] ?? '' ); ?></div>
					</td>
					<td class="num"><?php echo esc_html( $line['qty'] ?? 0 ); ?></td>
					<td class="num">
						<?php if ( $has_discount ) : ?>
							<div class="total-strike"><del><?php echo wp_kses_post( wc_price( $line['line_subtotal'] ?? 0, $currency_args ) ); ?></del></div>
						<?php endif; ?>
						<div class="total-final"><?php echo wp_kses_post( wc_price( $line['line_total'] ?? 0, $currency_args ) ); ?></div>
					</td>
				</tr>
			<?php endforeach; ?>

			<?php foreach ( $receipt_data['fees'] as $fee ) : ?>
				<tr>
					<td><div class="name"><?php echo esc_html( $fee['label'] ?? __( 'Fee', 'woocommerce' ) ); ?></div></td>
					<td class="num">1</td>
					<td class="num"><div class="total-final"><?php echo wp_kses_post( wc_price( $fee['total'] ?? 0, $currency_args ) ); ?></div></td>
				</tr>
			<?php endforeach; ?>

			<?php foreach ( $receipt_data['shipping'] as $ship ) : ?>
				<tr>
					<td><div class="name"><?php echo esc_html( $ship['label'] ?? __( 'Shipping', 'woocommerce' ) ); ?></div></td>
					<td class="num">1</td>
					<td class="num"><div class="total-final"><?php echo wp_kses_post( wc_price( $ship['total'] ?? 0, $currency_args ) ); ?></div></td>
				</tr>
			<?php endforeach; ?>

			<?php foreach ( $receipt_data['discounts'] as $discount ) : ?>
				<tr class="discount-row">
					<td>
						<div class="name">
							<?php echo esc_html( $i18n['discount'] ?? __( 'Discount', 'woocommerce' ) ); ?>
							<?php
							if ( ! empty( $discount['label'] ) ) :
								?>
								&middot; <?php echo esc_html( $discount['label'] ); ?><?php endif; ?>
							<?php
							if ( ! empty( $discount['code'] ) ) :
								?>
								(<?php echo esc_html( $discount['code'] ); ?>)<?php endif; ?>
						</div>
					</td>
					<td class="num">&mdash;</td>
					<td class="num"><div class="total-final">-<?php echo wp_kses_post( wc_price( $discount['total'] ?? 0, $currency_args ) ); ?></div></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<!-- Totals -->
	<div class="totals">
		<div class="totals-row">
			<span class="label"><?php echo esc_html( $i18n['subtotal'] ?? __( 'Subtotal', 'woocommerce' ) ); ?></span>
			<span class="amount"><?php echo wp_kses_post( wc_price( $receipt_data['totals']['subtotal'] ?? 0, $currency_args ) ); ?></span>
		</div>

		<?php $tax_total = $receipt_data['totals']['tax_total'] ?? 0; ?>
		<?php if ( ! empty( $tax_total ) ) : ?>
			<div class="totals-row">
				<span class="label"><?php echo esc_html( $i18n['total_tax'] ?? __( 'Total Tax', 'woocommerce' ) ); ?></span>
				<span class="amount"><?php echo wp_kses_post( wc_price( $tax_total, $currency_args ) ); ?></span>
			</div>
		<?php endif; ?>

		<div class="totals-divider"></div>

		<div class="totals-row grand">
			<span class="label"><?php echo esc_html( $i18n['total'] ?? __( 'Total', 'woocommerce' ) ); ?></span>
			<span class="amount"><?php echo wp_kses_post( wc_price( $receipt_data['totals']['total'] ?? 0, $currency_args ) ); ?></span>
		</div>

		<?php $refund_total = $receipt_data['totals']['refund_total'] ?? 0; ?>
		<?php if ( ! empty( $refund_total ) ) : ?>
			<div class="totals-row refund">
				<span><?php echo esc_html( $i18n['refunded'] ?? __( 'Refunded', 'woocommerce' ) ); ?></span>
				<span class="amount">-<?php echo wp_kses_post( wc_price( $refund_total, $currency_args ) ); ?></span>
			</div>
			<div class="totals-row net">
				<span><?php echo esc_html( $i18n['net_total'] ?? __( 'Net Total', 'woocommerce-pos' ) ); ?></span>
				<span class="amount"><?php echo wp_kses_post( wc_price( $receipt_data['totals']['net_total'] ?? 0, $currency_args ) ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<!-- Refunds -->
	<?php if ( ! empty( $receipt_data['refunds'] ) ) : ?>
		<div class="card refunds">
			<div class="card-label"><?php echo esc_html( $i18n['returned_items'] ?? __( 'Returned Items', 'woocommerce-pos' ) ); ?></div>
			<?php foreach ( $receipt_data['refunds'] as $refund ) : ?>
				<div class="row">
					<div>
						<strong>#<?php echo esc_html( (string) ( $refund['id'] ?? '' ) ); ?></strong>
						<?php if ( ! empty( $refund['date']['datetime'] ) ) : ?>
							<span class="reason"> &middot; <?php echo esc_html( $refund['date']['datetime'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $refund['reason'] ) ) : ?>
							<div class="reason"><?php echo esc_html( $refund['reason'] ); ?></div>
						<?php endif; ?>
					</div>
					<div class="amount"><?php echo wp_kses_post( wc_price( $refund['amount'] ?? 0, $currency_args ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- Customer note -->
	<?php $customer_note = ! empty( $receipt_data['order']['customer_note'] ) ? $receipt_data['order']['customer_note'] : ( $receipt_data['meta']['customer_note'] ?? '' ); ?>
	<?php if ( '' !== $customer_note ) : ?>
		<div class="card note">
			<div class="card-label"><?php echo esc_html( $i18n['customer_note'] ?? __( 'Customer Note', 'woocommerce' ) ); ?></div>
			<div class="body"><?php echo wp_kses_post( nl2br( $customer_note ) ); ?></div>
		</div>
	<?php endif; ?>

	<!-- Payments -->
	<?php if ( ! empty( $receipt_data['payments'] ) ) : ?>
		<div class="payments">
			<div class="payments-label"><?php echo esc_html( $i18n['paid'] ?? __( 'Paid', 'woocommerce-pos' ) ); ?></div>
			<?php foreach ( $receipt_data['payments'] as $payment ) : ?>
				<div class="payment-row">
					<span><strong><?php echo esc_html( $payment['method_title'] ?? '' ); ?></strong></span>
					<span class="amount"><?php echo wp_kses_post( wc_price( $payment['amount'] ?? 0, $currency_args ) ); ?></span>
				</div>
				<?php if ( ! empty( $payment['tendered'] ) && (float) $payment['tendered'] > 0 ) : ?>
					<div class="payment-sub">
						<span><?php echo esc_html( $i18n['tendered'] ?? __( 'Tendered', 'woocommerce-pos' ) ); ?></span>
						<span class="amount"><?php echo wp_kses_post( wc_price( $payment['tendered'], $currency_args ) ); ?></span>
					</div>
					<div class="payment-sub">
						<span><?php echo esc_html( $i18n['change'] ?? __( 'Change', 'woocommerce-pos' ) ); ?></span>
						<span class="amount"><?php echo wp_kses_post( wc_price( $payment['change'] ?? 0, $currency_args ) ); ?></span>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- Footer -->
	<?php
	// Pro feature: store.personal_notes and store.footer_imprint are available when using the WCPOS Pro Stores add-on.
	$personal_notes = $receipt_data['store']['personal_notes'] ?? '';
	$thank_you      = $i18n['thank_you_purchase'] ?? __( 'Thank you for your purchase!', 'woocommerce-pos' );
	$footer_text    = '' !== $personal_notes ? $personal_notes : $thank_you;
	?>
	<div class="footer"><?php echo wp_kses_post( nl2br( $footer_text ) ); ?></div>

	<?php if ( ! empty( $receipt_data['store']['footer_imprint'] ) ) : ?>
		<div class="footer-imprint"><?php echo wp_kses_post( nl2br( $receipt_data['store']['footer_imprint'] ) ); ?></div>
	<?php endif; ?>

</div>

</body>
</html>
