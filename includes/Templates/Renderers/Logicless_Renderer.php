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
	 * Money field names (flipped for O(1) lookup).
	 *
	 * @var array
	 */
	private $money_fields = array();

	/**
	 * Currency code for formatting.
	 *
	 * @var string
	 */
	private $currency = 'USD';

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

		$this->currency     = $receipt_data['meta']['currency'] ?? 'USD';
		$this->money_fields = array_flip( Receipt_Data_Schema::MONEY_FIELDS );

		$formatted_data = $this->format_money_fields( $receipt_data );

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

	/**
	 * Recursively format money fields in receipt data.
	 *
	 * Walks the data structure and replaces numeric values whose terminal
	 * key name matches a known money field with a formatted currency string.
	 *
	 * @param array  $data Receipt data (or nested portion).
	 * @param string $key  Current key name (for terminal matching).
	 *
	 * @return array Formatted data.
	 */
	private function format_money_fields( array $data, string $key = '' ): array {
		$result = array();

		foreach ( $data as $k => $value ) {
			if ( \is_array( $value ) ) {
				$result[ $k ] = $this->format_money_fields( $value, (string) $k );
			} elseif ( is_numeric( $value ) && isset( $this->money_fields[ $k ] ) ) {
				$result[ $k ] = wp_strip_all_tags(
					wc_price( (float) $value, array( 'currency' => $this->currency ) )
				);
			} else {
				$result[ $k ] = $value;
			}
		}

		return $result;
	}
}
