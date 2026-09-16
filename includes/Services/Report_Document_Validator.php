<?php
/**
 * Validate the tabular report document contract.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_Error;

/** Report schema and column-order validation. */
class Report_Document_Validator {
	/**
	 * Validate a producer's document without reformatting its cells.
	 *
	 * @param array $document Report document.
	 * @return true|WP_Error
	 */
	public static function validate( array $document ) {
		$schema = Receipt_Data_Schema::get_json_schema( 'report' );
		// The schema uses draft-4-compatible types, enums, required and anyOf.
		$schema['$schema'] = 'http://json-schema.org/draft-04/schema#';
		unset( $schema['$id'] );
		$result = rest_validate_value_from_schema( $document, $schema, 'document' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::validate_cells( $document['report'], array_column( $document['report']['columns'], 'key' ), 'report' );
	}

	/**
	 * Check every row, subtotal and total against the column order.
	 *
	 * @param array  $node    Report subtree.
	 * @param array  $columns Ordered column keys.
	 * @param string $path    Document path for errors.
	 * @return true|WP_Error
	 */
	private static function validate_cells( array $node, array $columns, string $path ) {
		foreach ( $node as $key => $value ) {
			if ( 'cells' === $key && array_column( $value, 'key' ) !== $columns ) {
				/* translators: %s: path of the invalid cells array. */
				return new WP_Error( 'wcpos_report_invalid_cells', sprintf( __( '%s must match the column keys in order.', 'woocommerce-pos' ), $path . '.cells' ) );
			}
			if ( \is_array( $value ) ) {
				$result = self::validate_cells( $value, $columns, $path . '.' . $key );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	}
}
