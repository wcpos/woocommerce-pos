<?php
/**
 * Logicless receipt renderer.
 *
 * Uses Mustache.php to render templates with section blocks:
 *   {{#key}}...{{/key}}  — iterate arrays or show block for truthy values
 *   {{^key}}...{{/key}}  — show block when value is empty/falsy
 *   {{.}}                — current value (for arrays of scalars)
 *   {{key.path}}         — dot-path placeholder substitution
 *
 * Money fields are pre-formatted as currency before rendering.
 *
 * @package WCPOS\WooCommercePOS\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Templates\Renderers;

use Mustache\Engine as Mustache_Engine;
use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WC_Abstract_Order;

/**
 * Logicless_Renderer class.
 */
class Logicless_Renderer implements Receipt_Renderer_Interface {

	/**
	 * Render logicless template output.
	 *
	 * @param array             $template     Template metadata/content.
	 * @param WC_Abstract_Order $order        Order object.
	 * @param array             $receipt_data Canonical receipt payload.
	 */
	public function render( array $template, WC_Abstract_Order $order, array $receipt_data ): void {
		$content = isset( $template['content'] ) && \is_string( $template['content'] ) ? $template['content'] : '';

		if ( '' === $content ) {
			echo '<!-- Empty logicless receipt template -->';
			return;
		}

		$currency       = $receipt_data['meta']['currency'] ?? 'USD';
		$formatted_data = Receipt_Data_Schema::format_money_fields( $receipt_data, $currency );

		// Add boolean helpers for array sections so templates can gate wrappers.
		$formatted_data['has_tax_summary'] = ! empty( $formatted_data['tax_summary'] );

		// Strip HTML comments — wp_kses_post removes the delimiters but leaves the text.
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		$flags    = ENT_QUOTES | ENT_SUBSTITUTE;
		$mustache = new Mustache_Engine(
			array(
				'entity_flags' => $flags,
				'escape'       => function ( $value ) use ( $flags ) {
					if ( \is_array( $value ) ) {
						return '';
					}

					return htmlspecialchars( (string) $value, $flags, 'UTF-8' );
				},
			)
		);

		$output = $mustache->render( $content, $formatted_data );

		echo wp_kses_post( $output );
	}
}
