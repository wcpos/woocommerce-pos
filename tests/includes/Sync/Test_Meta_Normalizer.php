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
	/**
	 * Oversized entity meta is omitted without turning the remaining list into a map.
	 */
	public function test_wc_meta_oversized_array_is_dropped_and_list_reindexed(): void {
		$huge = new \WC_Meta_Data( array( 'id' => 7, 'key' => 'huge', 'value' => array_fill( 0, Meta_Normalizer::OVERSIZED_META_NODE_LIMIT + 1, 'a' ) ) );
		$sibling = new \WC_Meta_Data( array( 'id' => 8, 'key' => 'normal', 'value' => 'kept' ) );

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $huge, $sibling ) ) );

		$this->assertSame( array( $sibling ), $normalized['meta_data'] );
		$this->assertSame( array( 0 ), array_keys( $normalized['meta_data'] ) );
		$this->assertSame( '[', substr( wp_json_encode( $normalized['meta_data'] ), 0, 1 ) );
	}

	/**
	 * In-budget entity meta retains both its object identity and existing wire shape.
	 */
	public function test_wc_meta_small_nested_array_keeps_existing_wire_shape(): void {
		$entry = new \WC_Meta_Data(
			array( 'id' => 9, 'key' => 'nested', 'value' => array( 'settings' => array( 'enabled' => true, 'ids' => array( 4, 8 ) ) ) )
		);
		$expected = json_decode( wp_json_encode( $entry->get_data() ), true );

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $entry ) ) );

		$this->assertSame( $entry, $normalized['meta_data'][0] );
		$this->assertSame( $expected, json_decode( wp_json_encode( $normalized['meta_data'][0] ), true ) );
	}

	/**
	 * Array-shaped meta applies the byte budget before attempting JSON decoding.
	 */
	public function test_array_meta_string_over_byte_limit_is_dropped_and_under_limit_kept(): void {
		$huge = array( 'key' => 'huge_string', 'value' => str_repeat( 'a', Meta_Normalizer::OVERSIZED_META_BYTE_LIMIT + 1 ) );
		$sibling = array( 'key' => 'normal_string', 'value' => str_repeat( 'a', Meta_Normalizer::OVERSIZED_META_BYTE_LIMIT - 1 ) );

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $huge, $sibling ) ) );

		$this->assertCount( 1, $normalized['meta_data'] );
		$this->assertSame( array( $sibling ), $normalized['meta_data'] );
	}

	/**
	 * Nodes from separate nested arrays all contribute to the same budget.
	 */
	public function test_array_meta_nested_total_over_node_limit_is_dropped(): void {
		$half = array_fill( 0, (int) ( Meta_Normalizer::OVERSIZED_META_NODE_LIMIT / 2 ), 'a' );
		$entry = array( 'key' => 'nested_huge', 'value' => array( $half, $half ) );

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $entry ) ) );

		$this->assertCount( 0, $normalized['meta_data'] );
	}

	/**
	 * A custom class instance is expanded, not waved through.
	 *
	 * WordPress unserializes stored meta, so a value can arrive as another plugin's object.
	 * json_encode serializes its public properties regardless, so skipping the budget for
	 * anything that is not stdClass would walk straight into the encode this guards.
	 */
	public function test_custom_object_with_oversized_property_is_dropped(): void {
		$huge    = new Oversized_Meta_Fixture( array_fill( 0, Meta_Normalizer::OVERSIZED_META_NODE_LIMIT + 1, 'a' ) );
		$sibling = array( 'key' => 'normal', 'value' => 'kept' );

		$normalized = Meta_Normalizer::normalize(
			array( 'meta_data' => array( array( 'key' => 'wrapped', 'value' => $huge ), $sibling ) )
		);

		$this->assertSame( array( $sibling ), $normalized['meta_data'] );
	}

	/**
	 * A small custom object is not falsely withheld.
	 */
	public function test_small_custom_object_value_is_kept(): void {
		$entry = array( 'key' => 'wrapped', 'value' => new Oversized_Meta_Fixture( array( 'a', 'b' ) ) );

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $entry ) ) );

		$this->assertCount( 1, $normalized['meta_data'] );
		$this->assertSame( 'wrapped', $normalized['meta_data'][0]['key'] );
	}

	/**
	 * Keys count toward the byte budget: few entries, enormous keys.
	 */
	public function test_oversized_string_keys_are_counted(): void {
		$entry = array(
			'key'   => 'fat_keys',
			'value' => array(
				str_repeat( 'k', Meta_Normalizer::OVERSIZED_META_BYTE_LIMIT ) => 'a',
				str_repeat( 'j', Meta_Normalizer::OVERSIZED_META_BYTE_LIMIT ) => 'b',
			),
		);

		$normalized = Meta_Normalizer::normalize( array( 'meta_data' => array( $entry ) ) );

		$this->assertCount( 0, $normalized['meta_data'] );
	}

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

	public function test_object_display_fields_are_removed_from_typed_meta(): void {
		$document = array(
			'meta_data' => array(
				array(
					'key'           => 'settings',
					'value'         => '{"enabled":true}',
					'display_key'   => (object) array( 'rendered' => 'Settings' ),
					'display_value' => (object) array( 'enabled' => true ),
				),
			),
			'line_items' => array(
				array(
					'meta_data' => array(
						array(
							'key'           => 'options',
							'value'         => '{"size":"large"}',
							'display_key'   => (object) array( 'rendered' => 'Options' ),
							'display_value' => (object) array( 'size' => 'large' ),
						),
					),
				),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( array( 'enabled' => true ), $normalized['meta_data'][0]['value'] );
		$this->assertArrayNotHasKey( 'display_key', $normalized['meta_data'][0] );
		$this->assertArrayNotHasKey( 'display_value', $normalized['meta_data'][0] );
		$this->assertSame( array( 'size' => 'large' ), $normalized['line_items'][0]['meta_data'][0]['value'] );
		$this->assertArrayNotHasKey( 'display_key', $normalized['line_items'][0]['meta_data'][0] );
		$this->assertArrayNotHasKey( 'display_value', $normalized['line_items'][0]['meta_data'][0] );
	}

	public function test_string_display_fields_are_preserved(): void {
		$document = array(
			'meta_data' => array(
				array(
					'key'           => 'settings',
					'value'         => '{"enabled":true}',
					'display_key'   => 'Settings',
					'display_value' => 'Enabled',
				),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( 'Settings', $normalized['meta_data'][0]['display_key'] );
		$this->assertSame( 'Enabled', $normalized['meta_data'][0]['display_value'] );
	}

	public function test_object_display_fields_are_removed_when_value_is_already_typed(): void {
		$typed = array( 'source' => 'native' );
		$document = array(
			'meta_data' => array(
				array(
					'key'           => 'already_typed',
					'value'         => $typed,
					'display_key'   => array( 'rendered' => 'Already typed' ),
					'display_value' => array( 'source' => 'native' ),
				),
			),
		);

		$normalized = Meta_Normalizer::normalize( $document );

		$this->assertSame( $typed, $normalized['meta_data'][0]['value'] );
		$this->assertArrayNotHasKey( 'display_key', $normalized['meta_data'][0] );
		$this->assertArrayNotHasKey( 'display_value', $normalized['meta_data'][0] );
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
				array( 'key' => 'object', 'value' => '{}', 'display_value' => (object) array() ),
				array( 'key' => 'array', 'value' => '[1,2]', 'display_key' => 'Array' ),
			),
		);

		$once  = Meta_Normalizer::normalize( $document );
		$twice = Meta_Normalizer::normalize( $once );

		$this->assertEquals( $once, $twice );
	}
}

/**
 * Stand-in for another plugin's class arriving as an unserialized meta value.
 *
 * json_encode serializes the public property, so the budget walker must see it.
 */
class Oversized_Meta_Fixture {
	/**
	 * Whatever the other plugin stored.
	 *
	 * @var mixed
	 */
	public $payload;

	/**
	 * @param mixed $payload Stored payload.
	 */
	public function __construct( $payload ) {
		$this->payload = $payload;
	}
}
