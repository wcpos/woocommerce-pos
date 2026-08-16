<?php
/**
 * Tests for WooCommerce meta entry access.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WP_UnitTestCase;

/**
 * Meta entry adapter tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Meta_Entry
 */
class Test_Meta_Entry extends WP_UnitTestCase {
	/**
	 * Array entries expose their key and value.
	 */
	public function test_array_entry_returns_key_and_value(): void {
		$entry = array(
			'key'   => '_sku',
			'value' => 'POS-123',
		);

		$this->assertSame( '_sku', Meta_Entry::key( $entry ) );
		$this->assertSame( 'POS-123', Meta_Entry::value( $entry ) );
	}

	/**
	 * Object entries expose their key and value.
	 */
	public function test_object_entry_returns_key_and_value(): void {
		$entry = (object) array(
			'key'   => '_sku',
			'value' => 'POS-123',
		);

		$this->assertSame( '_sku', Meta_Entry::key( $entry ) );
		$this->assertSame( 'POS-123', Meta_Entry::value( $entry ) );
	}

	/**
	 * An entry without a key returns null for the key.
	 */
	public function test_entry_with_missing_key_returns_null(): void {
		$entry = array( 'value' => 'POS-123' );

		$this->assertNull( Meta_Entry::key( $entry ) );
		$this->assertSame( 'POS-123', Meta_Entry::value( $entry ) );
	}

	/**
	 * Null input returns null.
	 */
	public function test_null_input_returns_null(): void {
		$this->assertNull( Meta_Entry::key( null ) );
		$this->assertNull( Meta_Entry::value( null ) );
	}

	/**
	 * A malformed non-scalar key is returned raw rather than fataling.
	 *
	 * Clients have sent meta_data entries whose key is an array. The adapter
	 * must hand that back uncoerced so each caller's own tolerance runs —
	 * Pos_Order_Audit gates on is_scalar(), the comparison sites use a strict
	 * === against a string. A ?string return type here fatals before any of
	 * those guards, which is what test_order_audit_tolerates_a_malformed_meta_key
	 * _without_fataling and test_sanitize_create_meta_tolerates_malformed_keys
	 * exist to prevent.
	 */
	public function test_malformed_array_key_is_returned_without_coercion(): void {
		$entry = array(
			'key'   => array( 'unexpected' ),
			'value' => 'POS-123',
		);

		$this->assertSame( array( 'unexpected' ), Meta_Entry::key( $entry ) );
		$this->assertSame( 'POS-123', Meta_Entry::value( $entry ) );
	}
}
