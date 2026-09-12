<?php
/**
 * Closure / X-report filesystem default. Figures are supplied by the document.
 *
 * @package WCPOS\WooCommercePOS
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included in the renderer's local scope.
defined( 'ABSPATH' ) || exit;
$closure = $receipt_data['closure'] ?? array();
$breakdowns = $closure['breakdowns'] ?? array();
$fiscal = $receipt_data['fiscal'] ?? array();
?>
<!doctype html>
<html><head><meta charset="utf-8"><?php do_action( 'woocommerce_pos_receipt_head' ); ?><style>
body { font: 12px sans-serif; max-width: 720px; margin: auto; padding: 12px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
th, td { text-align: right; padding: 4px; border-bottom: 1px solid #ddd; }
th:first-child, td:first-child { text-align: left; } h1 { font-size: 18px; } h2 { font-size: 14px; }
</style></head><body>
<h1><?php echo esc_html( ! empty( $fiscal['is_x_report'] ) ? __( 'X-report', 'woocommerce-pos' ) : sprintf( /* translators: %s: closure number. */ __( 'Closure %s', 'woocommerce-pos' ), $closure['number'] ?? '' ) ); ?> · <?php echo esc_html( $receipt_data['register']['name'] ?? '' ); ?></h1>
<?php
foreach ( array(
	'opened' => __( 'Opened', 'woocommerce-pos' ),
	'closed' => __( 'Closed', 'woocommerce-pos' ),
) as $event => $label ) :
	?>
	<?php if ( ! empty( $closure[ $event . '_at_gmt' ] ) ) : ?>
		<p><?php echo esc_html( $label . ': ' . $closure[ $event . '_at_gmt' ] . ' UTC · ' . ( $breakdowns['labels'][ $event . '_by_name' ] ?? '' ) ); ?></p>
	<?php endif; ?>
<?php endforeach; ?>
<?php if ( ! empty( $closure['approved_by'] ) ) : ?>
<p><?php echo esc_html( __( 'Approver', 'woocommerce-pos' ) . ': ' . ( $breakdowns['labels']['approved_by_name'] ?? '' ) ); ?></p>
<?php endif; ?>
<?php
foreach ( array(
	'payment_methods' => array(
		__( 'Payment method', 'woocommerce-pos' ),
		'sales' => __( 'Sales', 'woocommerce-pos' ),
		'refunds' => __( 'Refunds', 'woocommerce-pos' ),
	),
	'tax_rates' => array(
		__( 'Tax rate', 'woocommerce-pos' ),
		'net' => __( 'Net', 'woocommerce-pos' ),
		'tax' => __( 'Tax', 'woocommerce-pos' ),
		'gross' => __( 'Gross', 'woocommerce-pos' ),
	),
) as $section => $columns ) :
	?>
	<?php if ( ! empty( $breakdowns[ $section ] ) ) : ?>
	<table><thead><tr>
		<?php
		foreach ( $columns as $label ) :
			?>
		<th><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead><tbody>
		<?php foreach ( $breakdowns[ $section ] as $key => $row ) : ?>
		<tr><td><?php echo esc_html( $row['name'] ?? $row['method'] ?? $row['rate'] ?? $key ); ?></td>
			<?php
			foreach ( array_slice( $columns, 1, null, true ) as $field => $label ) :
				?>
			<td><?php echo esc_html( $row[ $field ] ?? '' ); ?></td><?php endforeach; ?></tr>
		<?php endforeach; ?>
	</tbody></table>
	<?php endif; ?>
<?php endforeach; ?>
<?php $tenders = ( $closure['counted'] ?? array() ) + ( $closure['expected'] ?? array() ); ?>
<?php if ( $tenders ) : ?>
<table><thead><tr><th><?php esc_html_e( 'Tender', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Counted', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Expected', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Variance', 'woocommerce-pos' ); ?></th></tr></thead><tbody>
	<?php foreach ( $tenders as $method => $amount ) : ?>
<tr><td><?php echo esc_html( $method ); ?></td><td><?php echo esc_html( $closure['counted'][ $method ] ?? '' ); ?></td><td><?php echo esc_html( $closure['expected'][ $method ] ?? '' ); ?></td><td><?php echo esc_html( $closure['variance'][ $method ] ?? '' ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if ( ! empty( $breakdowns['opening_float'] ) ) : ?>
<h2><?php esc_html_e( 'Opening float', 'woocommerce-pos' ); ?></h2>
	<?php
	foreach ( array(
		'counted' => __( 'Counted', 'woocommerce-pos' ),
		'expected' => __( 'Expected', 'woocommerce-pos' ),
		'variance' => __( 'Variance', 'woocommerce-pos' ),
	) as $field => $label ) :
		?>
		<?php
		if ( isset( $breakdowns['opening_float'][ $field ] ) ) :
			?>
			<p><?php echo esc_html( $label . ': ' . $breakdowns['opening_float'][ $field ] ); ?></p><?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<?php if ( ! empty( $breakdowns['movements'] ) ) : ?>
<h2><?php esc_html_e( 'Cash movements', 'woocommerce-pos' ); ?></h2>
<table><thead><tr><th><?php esc_html_e( 'Time', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Type', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Amount', 'woocommerce-pos' ); ?></th><th><?php esc_html_e( 'Reason', 'woocommerce-pos' ); ?></th></tr></thead><tbody>
	<?php foreach ( $breakdowns['movements'] as $movement ) : ?>
<tr><td><?php echo esc_html( $movement['created_at_gmt'] ?? '' ); ?></td><td><?php echo esc_html( $movement['type'] ?? '' ); ?>
		<?php
		if ( ! empty( $movement['voided_by'] ) ) {
			esc_html_e( 'Voided', 'woocommerce-pos' ); }
		?>
</td><td><?php echo esc_html( $movement['amount'] ?? '' ); ?></td><td><?php echo esc_html( $movement['reason'] ?? '' ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php
foreach ( array(
	'transaction_count' => __( 'Transactions', 'woocommerce-pos' ),
	'refund_count' => __( 'Refunds', 'woocommerce-pos' ),
) as $field => $label ) :
	?>
	<?php
	if ( isset( $breakdowns[ $field ] ) ) :
		?>
		<p><?php echo esc_html( $label . ': ' . $breakdowns[ $field ] ); ?></p><?php endif; ?>
<?php endforeach; ?>
<?php if ( ! empty( $breakdowns['cashiers'] ) ) : ?>
<h2><?php esc_html_e( 'Cashiers', 'woocommerce-pos' ); ?></h2>
	<?php
	foreach ( $breakdowns['cashiers'] as $cashier ) :
		?>
		<p><?php echo esc_html( $cashier['name'] ); ?></p><?php endforeach; ?>
<?php endif; ?>
<?php
foreach ( array(
	'period_sales_total' => __( 'Period sales', 'woocommerce-pos' ),
	'period_refunds_total' => __( 'Period refunds', 'woocommerce-pos' ),
	'perpetual_sales_total' => __( 'Perpetual sales', 'woocommerce-pos' ),
	'perpetual_refunds_total' => __( 'Perpetual refunds', 'woocommerce-pos' ),
) as $field => $label ) :
	?>
	<?php
	if ( isset( $closure[ $field ] ) ) :
		?>
		<p><?php echo esc_html( $label . ': ' . $closure[ $field ] ); ?></p><?php endif; ?>
<?php endforeach; ?>
<?php if ( ( $closure['unsynced_count'] ?? 0 ) > 0 ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: unsynced sales count, 2: total. */ __( '%1$s sales not yet on the server · %2$s', 'woocommerce-pos' ), $closure['unsynced_count'], $closure['unsynced_total'] ) ); ?></p>
<?php endif; ?>
<footer><p><?php echo esc_html( ( $receipt_data['software']['name'] ?? 'WCPOS' ) . ' ' . ( $receipt_data['software']['plugin_version'] ?? '' ) . ' · ' . ( $receipt_data['order']['printed']['datetime'] ?? '' ) ); ?></p>
<?php
if ( ! empty( $fiscal['is_reprint'] ) ) :
	?>
	<p><?php echo esc_html( ( $receipt_data['i18n']['copy'] ?? 'COPY' ) . ' ' . $fiscal['reprint_count'] ); ?></p><?php endif; ?>
</footer></body></html>
