<?php
/**
 * Tests for typed sync meta normalization.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Meta_Normalizer
 */
class Test_Meta_Normalizer extends WP_UnitTestCase {
	public function test_object_json_string_is_normalized_to_a_typed_value(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'settings', 'value' => '{"enabled":true,"mode":"pos"}' ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$value = $normalized['meta_data'][0]['value'];
		// Assoc-keyed objects decode to arrays (wire-neutral); shape-critical
		// objects ({} / numeric-keyed) stay stdClass — see preserve_json_object_shape.
		$this->assertSame( array( 'enabled' => true, 'mode' => 'pos' ), $value );
		$this->assertSame( '{"enabled":true,"mode":"pos"}', wp_json_encode( $value ) );
	}

	public function test_array_json_string_is_normalized_to_an_array(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'tax_ids', 'value' => '["vat","gst"]' ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( array( 'vat', 'gst' ), $normalized['meta_data'][0]['value'] );
	}

	public function test_empty_json_object_preserves_object_shape_when_serialized(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'settings', 'value' => ' {} ' ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertInstanceOf( \stdClass::class, $normalized['meta_data'][0]['value'] );
		$this->assertSame( '{}', wp_json_encode( $normalized['meta_data'][0]['value'] ) );
	}

	public function test_json_object_shapes_are_preserved_when_serialized(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'numeric_keys', 'value' => '{"0":"first","1":"second"}' ),
				array( 'key' => 'nested', 'value' => '{"nested":{}}' ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( '{"0":"first","1":"second"}', wp_json_encode( $normalized['meta_data'][0]['value'] ) );
		$this->assertSame( '{"nested":{}}', wp_json_encode( $normalized['meta_data'][1]['value'] ) );
	}

	public function test_scalar_strings_numbers_and_invalid_json_are_untouched(): void {
		$values = array( '123', 'true', 'null', 'ordinary string', 123, '{"partial":', '[1,2' );
		$document = array(
			'meta_data' => array_map(
				static function ( $value ): array {
					return array( 'key' => 'fixture', 'value' => $value );
				},
				$values
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( $values, array_column( $normalized['meta_data'], 'value' ) );
	}

	public function test_nested_order_line_item_meta_is_normalized(): void {
		$document = array(
			'id' => 10,
			'line_items' => array(
				array(
					'id' => 20,
					'meta_data' => array(
						array( 'key' => '_woocommerce_pos_data', 'value' => '{"note":"typed"}' ),
					),
				),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$value = $normalized['line_items'][0]['meta_data'][0]['value'];
		$this->assertSame( array( 'note' => 'typed' ), $value );
		$this->assertSame( '{"note":"typed"}', wp_json_encode( $value ) );
	}

	public function test_hydrated_php_serialized_array_passes_through_unchanged(): void {
		$typed = array( 'source' => 'php-serialized', 'ids' => array( 4, 8 ) );
		$document = array(
			'meta_data' => array(
				array( 'key' => 'already_typed', 'value' => $typed ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( $typed, $normalized['meta_data'][0]['value'] );
	}

	public function test_nested_object_shapes_survive_reserialization_exactly(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'config', 'value' => '{"config":{},"list":[],"map":{"0":"a"}}' ),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame(
			'{"config":{},"list":[],"map":{"0":"a"}}',
			wp_json_encode( $normalized['meta_data'][0]['value'] )
		);
	}

	public function test_decode_to_array_reads_string_array_and_object_forms(): void {
		$this->assertSame( array( 'price' => '10' ), Meta_Normalizer::decode_to_array( '{"price":"10"}' ) );
		$this->assertSame( array( 'price' => '10' ), Meta_Normalizer::decode_to_array( array( 'price' => '10' ) ) );
		$this->assertSame( array( 'price' => '10' ), Meta_Normalizer::decode_to_array( (object) array( 'price' => '10' ) ) );
		$this->assertNull( Meta_Normalizer::decode_to_array( '' ) );
		$this->assertNull( Meta_Normalizer::decode_to_array( 'not json' ) );
		$this->assertNull( Meta_Normalizer::decode_to_array( 123 ) );
		$this->assertNull( Meta_Normalizer::decode_to_array( null ) );
	}

	public function test_normalization_is_idempotent(): void {
		$document = array(
			'meta_data' => array(
				array( 'key' => 'object', 'value' => '{}' ),
				array( 'key' => 'array', 'value' => '[1,2]' ),
			),
		);

		$once  = Meta_Normalizer::normalize( $document );
		$twice = Meta_Normalizer::normalize( $once );

		$this->assertEquals( $once, $twice );
	}
}
