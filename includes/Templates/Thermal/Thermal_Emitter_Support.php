<?php
/**
 * Shared AST walking and byte-emitter text support for WCPOS.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Private emitter helpers; encoding and printer state stay with each emitter.
 */
trait Thermal_Emitter_Support {

	/**
	 * Walk a list of AST nodes.
	 *
	 * @param array $nodes The AST nodes.
	 *
	 * @return void
	 */
	private function walk_nodes( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( \is_array( $node ) ) {
				$this->walk_node( $node );
			}
		}
	}

	/**
	 * Insert an auto drawer node before the first trailing cut when enabled.
	 *
	 * @param array $nodes AST nodes.
	 *
	 * @return array
	 */
	private function nodes_with_auto_drawer( array $nodes ): array {
		if ( empty( $this->options['auto_open_drawer'] ) || $this->nodes_contain_drawer( $nodes ) ) {
			return $nodes;
		}

		$drawer = array(
			'type'      => 'drawer',
			'connector' => \WCPOS\WooCommercePOS\Services\Print_Job_Service::normalize_drawer_connector( (string) ( $this->options['drawer_connector'] ?? 'pin2' ) ),
		);

		for ( $i = count( $nodes ) - 1; $i >= 0; $i-- ) {
			$type = isset( $nodes[ $i ]['type'] ) ? (string) $nodes[ $i ]['type'] : '';
			if ( 'cut' === $type ) {
				array_splice( $nodes, $i, 0, array( $drawer ) );
				return $nodes;
			}
			if ( in_array( $type, array( 'feed' ), true ) ) {
				continue;
			}
			break;
		}

		$nodes[] = $drawer;
		return $nodes;
	}

	/**
	 * Whether a node list contains an explicit drawer node.
	 *
	 * @param array $nodes AST nodes.
	 *
	 * @return bool
	 */
	private function nodes_contain_drawer( array $nodes ): bool {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( 'drawer' === ( $node['type'] ?? '' ) ) {
				return true;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) && $this->nodes_contain_drawer( $node['children'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format a value as centered plain text.
	 *
	 * Mirrors the rescue in Html_Thermal_Emitter::render_barcode_fallback(): when
	 * the symbol cannot be produced, the value itself is still readable.
	 *
	 * Control bytes are folded to spaces first. This is the one path that routes
	 * a barcode value into the text stream, and a barcode value is exactly where
	 * a stray tab, LF or CR turns up — Code 128 validation rejects them on the
	 * ESC/POS lane precisely because code set B cannot encode them, which sends
	 * them here. Emitted raw they would break the line the rescue is centering.
	 *
	 * @param string $value   The value to print.
	 * @param int    $columns The paper width in character columns.
	 *
	 * @return string The padded text, without a newline.
	 */
	private function centered_text( string $value, int $columns ): string {
		$text = Thermal_Text_Layout::normalize_text( $this->strip_control_bytes( $value ) );
		// Preserve the barcode rescue's existing unscaled centering.
		$pad = (int) floor( max( 0, $columns - Thermal_Text_Layout::display_width( $text ) ) / 2 );

		return str_repeat( ' ', $pad ) . $text;
	}

	/**
	 * Replace control bytes with spaces so they cannot reach the print stream.
	 *
	 * @param string $value The value to clean.
	 *
	 * @return string The value with control bytes folded to spaces.
	 */
	private function strip_control_bytes( string $value ): string {
		$cleaned = preg_replace( '/[\x00-\x1f\x7f]/', ' ', $value );

		return null === $cleaned ? $value : $cleaned;
	}
}
