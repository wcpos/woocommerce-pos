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
		<label
			for="variable_pos_visibility[<?php echo esc_attr( $variation->ID ); ?>]"><?php esc_html_e( 'POS visibility', 'woocommerce-pos' ); ?></label>
		<select name="variable_pos_visibility[<?php echo esc_attr( $variation->ID ); ?>]">
			<?php
			foreach ( $this->options as $wcpos_value => $wcpos_label ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables.
				$wcpos_select = $wcpos_value == $selected ? 'selected="selected"' : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variable.
				?>
				<option value="<?php echo esc_attr( $wcpos_value ); ?>" <?php echo esc_attr( $wcpos_select ); ?>> <?php echo esc_html( $wcpos_label ); ?> </option>
			<?php } ?>
		</select>
	</p>
</div>
