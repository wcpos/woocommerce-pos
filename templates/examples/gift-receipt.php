<?php
/**
 * Gift Receipt — Example Template.
 *
 * Shows item names and quantities without prices or totals.
 * Includes the customer note as a gift message.
 *
 * Available variables:
 *   $order        — WC_Abstract_Order object (full WooCommerce API)
 *   $receipt_data — Canonical receipt payload (array)
 *   $template     — Template metadata (array)
 *
 * @see https://wcpos.com/docs/templates/receipt-data
 *
 * @package WCPOS\WooCommercePOS\Templates\Examples
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

$store = $receipt_data['store'];
$meta  = $receipt_data['meta'];
$lines = $receipt_data['lines'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			font-family: Georgia, "Times New Roman", serif;
			font-size: 14px;
			color: #333;
			padding: 30px;
			max-width: 420px;
			margin: 0 auto;
		}
		.header {
			text-align: center;
			margin-bottom: 24px;
			padding-bottom: 16px;
			border-bottom: 2px solid #e8e0d4;
		}
		.gift-label {
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 3px;
			color: #999;
			margin-bottom: 6px;
		}
		.store-name {
			font-size: 22px;
			font-weight: 400;
			font-style: italic;
		}
		.items {
			list-style: none;
			margin: 0 0 20px 0;
			padding: 0;
		}
		.items li {
			display: flex;
			justify-content: space-between;
			padding: 8px 0;
			border-bottom: 1px dotted #ddd;
		}
		.items li:last-child { border-bottom: none; }
		.item-name { flex: 1; }
		.item-qty { color: #888; font-size: 13px; flex-shrink: 0; margin-left: 12px; }
		.gift-message {
			margin: 20px 0;
			padding: 16px;
			background: #faf8f5;
			border: 1px solid #e8e0d4;
			border-radius: 4px;
			text-align: center;
			font-style: italic;
			line-height: 1.6;
		}
		.gift-message-label {
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 2px;
			color: #999;
			font-style: normal;
			margin-bottom: 8px;
		}
		.meta {
			text-align: center;
			font-size: 11px;
			color: #aaa;
			margin-top: 20px;
		}
	</style>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body>

	<div class="header">
		<div class="gift-label"><?php esc_html_e( 'Gift Receipt', 'woocommerce-pos' ); ?></div>
		<div class="store-name"><?php echo esc_html( $store['name'] ); ?></div>
	</div>

	<ul class="items">
		<?php foreach ( $lines as $line ) : ?>
			<li>
				<span class="item-name"><?php echo esc_html( $line['name'] ); ?></span>
				<?php if ( $line['qty'] > 1 ) : ?>
					<span class="item-qty">&times;<?php echo esc_html( $line['qty'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $order->get_customer_note() ) : ?>
		<div class="gift-message">
			<div class="gift-message-label"><?php esc_html_e( 'A message for you', 'woocommerce-pos' ); ?></div>
			<?php echo esc_html( $order->get_customer_note() ); ?>
		</div>
	<?php endif; ?>

	<div class="meta">
		<?php echo esc_html( wp_date( 'F j, Y', strtotime( $meta['created_at_gmt'] ) ) ); ?>
		&middot;
		#<?php echo esc_html( $meta['order_number'] ); ?>
	</div>

</body>
</html>
