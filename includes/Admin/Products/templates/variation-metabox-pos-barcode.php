<?php
/**
 * Admin View: Variation Metabox.
 *
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<div>
	<p class="form-row form-row-full">
		<label for="variable_pos_barcode[<?php echo esc_attr( $variation->ID ); ?>]">
			<?php esc_html_e( 'POS Barcode', 'woocommerce-pos' ); ?>
			<?php echo wc_help_tip( __( 'Product barcode used at the point of sale', 'woocommerce-pos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() returns escaped HTML. ?></a>
		</label>
		<input type="text" name="variable_pos_barcode[<?php echo esc_attr( $variation->ID ); ?>]" value="<?php echo esc_attr( $value ); ?>">
	</p>
</div>
