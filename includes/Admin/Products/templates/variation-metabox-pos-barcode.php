<?php
/**
 * Admin View: Variation Metabox.
 */
if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<div>
    <p class="form-row form-row-full">
        <label for="variable_pos_barcode[<?php echo $variation->ID; ?>]">
			<?php _e( 'POS Barcode', 'woocommerce-pos-pro' ); ?>
			<?php echo wc_help_tip( __( 'Product barcode used at the point of sale', 'woocommerce-pos' ) ); ?></a>
        </label>
        <input type="text" name="variable_pos_barcode[<?php echo $variation->ID; ?>]" value="<?php echo $value; ?>">
    </p>
</div>

