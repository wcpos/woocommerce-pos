<?php
/**
 * POS order audit-meta authority tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WP_UnitTestCase;

/**
 * Pos_Order_Audit_Test class.
 */
class Pos_Order_Audit_Test extends WP_UnitTestCase {
	/**
	 * Server-derived keys are removed on create; other meta passes through.
	 */
	public function test_sanitize_create_meta_strips_server_derived_keys(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => '_pos_user',
				'value' => '999',
			),
			array(
				'key'   => '_woocommerce_pos_version',
				'value' => '0.0.1',
			),
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => 'abc-123',
			),
		);

		// Act.
		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		// Assert.
		$this->assertEquals(
			array(
				array(
					'key' => '_woocommerce_pos_uuid',
					'value' => 'abc-123',
				),
			),
			$result
		);
	}

	/**
	 * Valid till values survive create; non-numeric cash amounts and empty
	 * values are dropped.
	 */
	public function test_sanitize_create_meta_validates_till_values(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => '_pos_store',
				'value' => 'store-7',
			),
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '20.00',
			),
			array(
				'key'   => '_pos_cash_change',
				'value' => 'not-a-number',
			),
			array(
				'key'   => '_pos_card_cashback',
				'value' => '',
			),
		);

		// Act.
		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		// Assert.
		$this->assertEquals(
			array(
				array(
					'key'   => '_pos_store',
					'value' => 'store-7',
				),
				array(
					'key'   => '_pos_cash_amount_tendered',
					'value' => '20.00',
				),
			),
			$result
		);
	}

	/**
	 * Object-shaped entries (json_decode without assoc) are handled the same
	 * as arrays.
	 */
	public function test_sanitize_create_meta_handles_object_entries(): void {
		// Arrange.
		$meta = array(
			(object) array(
				'key'   => '_pos_user',
				'value' => '999',
			),
			(object) array(
				'key'   => 'custom',
				'value' => 'kept',
			),
			(object) array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '20.00',
			),
		);

		// Act.
		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		// Assert.
		$this->assertCount( 2, $result );
		$this->assertEquals( 'custom', $result[0]->key );
		$this->assertSame(
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '20.00',
			),
			$result[1]
		);
	}

	/**
	 * A malformed entry whose key is an array must not fatal ("Illegal offset
	 * type") and is passed through as unrecognized meta.
	 */
	public function test_sanitize_create_meta_tolerates_malformed_keys(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => array( 'nested' ),
				'value' => 'x',
			),
		);

		// Act.
		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		// Assert.
		$this->assertCount( 1, $result );
	}

	/**
	 * Updates remove every audit key — server-derived and till-sourced alike.
	 */
	public function test_strip_audit_meta_removes_all_audit_keys(): void {
		// Arrange.
		$meta = array();
		foreach ( Pos_Order_Audit::audit_meta_keys() as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => '1',
			);
		}
		$meta[] = array(
			'key'   => 'unrelated',
			'value' => 'kept',
		);

		// Act.
		$result = Pos_Order_Audit::strip_audit_meta( $meta );

		// Assert.
		$this->assertEquals(
			array(
				array(
					'key' => 'unrelated',
					'value' => 'kept',
				),
			),
			$result
		);
	}

	/**
	 * An id-addressed entry targeting a protected audit row is dropped even
	 * when its key looks harmless (WooCommerce resolves id before key).
	 */
	public function test_strip_audit_meta_drops_entries_targeting_protected_meta_ids(): void {
		// Arrange.
		$meta = array(
			array(
				'id'    => 25,
				'key'   => '_x',
				'value' => '1',
			),
			array(
				'id'    => 30,
				'key'   => 'safe',
				'value' => 'kept',
			),
		);

		// Act.
		$result = Pos_Order_Audit::strip_audit_meta( $meta, array( 25 ) );

		// Assert.
		$this->assertEquals(
			array(
				array(
					'id' => 30,
					'key' => 'safe',
					'value' => 'kept',
				),
			),
			$result
		);
	}

	/**
	 * The till parser returns only valid till values; the last entry wins for
	 * a repeated key.
	 */
	public function test_till_meta_from_payload_validates_and_last_entry_wins(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '10.00',
			),
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '20.00',
			),
			array(
				'key'   => '_pos_cash_change',
				'value' => 'not-a-number',
			),
			array(
				'key'   => '_pos_user',
				'value' => '999',
			),
		);

		// Act.
		$result = Pos_Order_Audit::till_meta_from_payload( $meta );

		// Assert.
		$this->assertEquals( array( '_pos_cash_amount_tendered' => '20.00' ), $result );
	}

	/**
	 * Both create surfaces use last-entry-wins semantics, including when the
	 * last duplicate is invalid and therefore removes the till key.
	 */
	public function test_duplicate_till_key_with_invalid_last_value_is_dropped_by_both_create_paths(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => '20.00',
			),
			array(
				'key'   => '_pos_cash_amount_tendered',
				'value' => 'not-a-number',
			),
		);

		// Act.
		$v1_meta = Pos_Order_Audit::sanitize_create_meta( $meta );
		$v2_meta = Pos_Order_Audit::till_meta_from_payload( $meta );

		// Assert.
		$this->assertSame( array(), $v1_meta );
		$this->assertSame( array(), $v2_meta );
	}

	/**
	 * Cash amounts must be unsigned plain decimals; the store id is any
	 * non-empty scalar.
	 */
	public function test_is_valid_till_value_rules(): void {
		$this->assertTrue( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '0.00' ) );
		$this->assertTrue( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '10.50' ) );
		$this->assertTrue( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', 10 ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '-10.50' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '-9999' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '+10.50' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '1e3' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '1e10' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', ' 10.50' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '10.50 ' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_amount_tendered', '10,50' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_cash_change', array( '1' ) ) );
		$this->assertTrue( Pos_Order_Audit::is_valid_till_value( '_pos_store', 'uuid-or-slug' ) );
		$this->assertFalse( Pos_Order_Audit::is_valid_till_value( '_pos_store', '' ) );
	}
}
