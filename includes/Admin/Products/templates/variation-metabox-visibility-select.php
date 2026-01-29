<?php
/**
 * Admin View: Variation Metabox.
 *
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// phpcs:disable Squiz.Commenting.FileComment.Missing -- Template file.
/**
 * Variation metabox visibility select template.
 *
 * @var \WP_Post $variation
 * @var \WCPOS\WooCommercePOS\Admin\Products\Single_Product $this
 * @var string $selected
 */
?>

<div>
	<p class="form-row form-row-full">
		<label
			for="variable_pos_visibility[<?php echo esc_attr( (string) $variation->ID ); ?>]"><?php esc_html_e( 'POS visibility', 'woocommerce-pos' ); ?></label>
		<select name="variable_pos_visibility[<?php echo esc_attr( (string) $variation->ID ); ?>]">
			<?php
			foreach ( $this->options as $wcpos_value => $wcpos_label ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables. @phpstan-ignore-line
				$wcpos_select = $wcpos_value == $selected ? 'selected="selected"' : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variable.
				?>
				<option value="<?php echo esc_attr( $wcpos_value ); ?>" <?php echo esc_attr( $wcpos_select ); ?>> <?php echo esc_html( $wcpos_label ); ?> </option>
			<?php } ?>
		</select>
	</p>
</div>
