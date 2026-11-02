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
	 * Every key the live order data store treats as internal is stripped: WooCommerce routes such
	 * a key to a setter, or reads its plain row back as the order's own data (ADR 0040 rule 2).
	 */
	public function test_strip_audit_meta_drops_every_internal_order_key(): void {
		// Arrange.
		$internal = \WC_Data_Store::load( 'order' )->get_internal_meta_keys();
		$meta     = array(
			array(
				'key'   => 'public',
				'value' => 'kept',
			),
		);
		foreach ( $internal as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => 'x',
			);
		}

		// Act.
		$result = Pos_Order_Audit::strip_audit_meta( $meta );

		// Assert.
		$this->assertNotEmpty( $internal );
		$this->assertContains( '_order_currency', $internal );
		$this->assertSame( array( $meta[0] ), $result );
	}

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
	 * A client cannot supply the server-owned payment assertion on create.
	 */
	public function test_sanitize_create_meta_never_accepts_a_client_payment_assertion(): void {
		$meta = array(
			array(
				'key'   => '_pos_payment_asserted',
				'value' => 'offline',
			),
		);

		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		$this->assertSame( array(), $result );
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
		);

		// Act.
		$result = Pos_Order_Audit::sanitize_create_meta( $meta );

		// Assert.
		$this->assertCount( 1, $result );
		$this->assertEquals( 'custom', $result[0]->key );
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
			(object) array(
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
	/** Till UUIDs share the register's create and strip rules. */
	public function test_till_uuid_is_validated_and_protected(): void {
		$meta = array(
			array(
				'key' => '_wcpos_till',
				'value' => wp_generate_uuid4(),
			),
		);
		$this->assertSame( $meta, Pos_Order_Audit::sanitize_create_meta( $meta ) );
		$this->assertSame( array(), Pos_Order_Audit::strip_audit_meta( $meta ) );
		$this->assertContains( '_wcpos_till', Pos_Order_Audit::provenance_meta_keys() );
		$meta[0]['value'] = 'bad';
		$this->assertSame( array(), Pos_Order_Audit::sanitize_create_meta( $meta ) );
	}

	/**
	 * The meta tables match `_WCPOS_PAYMENTS` and `_wcpos_payments ` to the ledger key, so the strip does too.
	 */
	public function test_strip_audit_meta_drops_case_and_trailing_space_variants_of_server_keys(): void {
		// Arrange.
		$meta = array(
			array(
				'key'   => '_WCPOS_PAYMENTS',
				'value' => '{}',
			),
			array(
				'key'   => '_Wcpos_Payment_Id',
				'value' => 'x',
			),
			array(
				'key'   => '_wcpos_completions ',
				'value' => '[]',
			),
			array(
				'key'   => '_POS_USER',
				'value' => '1',
			),
			array(
				'key'   => '_wcpos_payments_note',
				'value' => 'kept',
			),
		);

		// Act.
		$result = Pos_Order_Audit::strip_audit_meta( $meta );

		// Assert.
		$this->assertSame(
			array(
				array(
					'key'   => '_wcpos_payments_note',
					'value' => 'kept',
				),
			),
			$result
		);
	}

	/**
	 * A stored row under a variant spelling of a server key is protected by id like the key itself.
	 */
	public function test_audit_meta_ids_include_rows_under_a_variant_spelling(): void {
		// Arrange.
		$order = wc_create_order();
		$order->add_meta_data( '_WCPOS_PAYMENTS', '{}' );
		$order->add_meta_data( 'harmless', 'kept' );
		$order->save();
		$ids = array();
		foreach ( wc_get_order( $order->get_id() )->get_meta_data() as $meta ) {
			$ids[ $meta->key ] = (int) $meta->id;
		}

		// Act.
		$protected = Pos_Order_Audit::audit_meta_ids( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertContains( $ids['_WCPOS_PAYMENTS'], $protected );
		$this->assertNotContains( $ids['harmless'], $protected );
	}

	/**
	 * Every order meta key a money module declares is server-owned, so a new key cannot skip the list.
	 */
	public function test_every_order_meta_key_the_money_modules_declare_is_server_owned(): void {
		// Arrange.
		$classes  = array(
			\WCPOS\WooCommercePOS\Payments\Contract\Ledger::class,
			\WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp::class,
			\WCPOS\WooCommercePOS\Payments\Contract\Attempts::class,
			\WCPOS\WooCommercePOS\Payments\Contract\Command_Log::class,
			\WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers::class,
			\WCPOS\WooCommercePOS\Payments\Checkout_State_Repository::class,
		);
		$declared = array();
		foreach ( $classes as $class ) {
			foreach ( ( new \ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( 1 === preg_match( '/META_KEY$/', $name ) ) {
					$declared[] = $value;
				}
			}
		}

		// Act.
		$missing = array_values( array_diff( $declared, Pos_Order_Audit::SERVER_MONEY_META_KEYS ) );

		// Assert.
		$this->assertGreaterThanOrEqual( 11, count( $declared ) );
		$this->assertContains( '_wcpos_checkout_state', $declared );
		$this->assertSame( array(), $missing );
	}
}
