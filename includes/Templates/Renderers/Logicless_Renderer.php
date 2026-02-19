<?php
/**
 * Logicless receipt renderer.
 *
 * @package WCPOS\WooCommercePOS\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Templates\Renderers;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
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

		$flat = $this->flatten_data( $receipt_data );

		$output = preg_replace_callback(
			'/\\{\\{\\s*([\\w\\.\\-]+)\\s*\\}\\}/',
			function ( $matches ) use ( $flat ) {
				$key = $matches[1];
				return isset( $flat[ $key ] ) ? (string) $flat[ $key ] : '';
			},
			$content
		);

		echo wp_kses_post( $output );
	}

	/**
	 * Flatten nested arrays into dot-path keys.
	 *
	 * @param array  $data   Nested array.
	 * @param string $prefix Key prefix.
	 *
	 * @return array
	 */
	private function flatten_data( array $data, string $prefix = '' ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( \is_array( $value ) ) {
				if ( $this->is_associative( $value ) ) {
					$result = array_merge( $result, $this->flatten_data( $value, $path ) );
				} elseif ( isset( $value[0] ) && ! \is_array( $value[0] ) ) {
					$result[ $path ] = implode( ', ', array_map( 'strval', $value ) );
				}
				continue;
			}

			$result[ $path ] = $value;
		}

		return $result;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array $array Array value.
	 *
	 * @return bool
	 */
	private function is_associative( array $array ): bool {
		if ( array() === $array ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
