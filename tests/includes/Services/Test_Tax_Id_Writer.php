<?php
/**
 * Tests for Tax_Id_Writer.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Settings;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WC_REST_Unit_Test_Case;

/**
 * Test_Tax_Id_Writer class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Writer extends WC_REST_Unit_Test_Case {
	/**
	 * Normalize_input drops invalid entries and uppercases values.
	 */
	public function test_normalize_input_basic(): void {
		$input = array(
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => ' de 123456789 ',
			),
			array(
				'type' => Tax_Id_Types::TYPE_BR_CPF,
				'value' => '12345678909',
			),
			array(
				'type' => 'invalid_type',
				'value' => 'X',
			), // Coerced to TYPE_OTHER.
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => '',
			), // Dropped.
			'not-an-array', // Dropped.
		);

		$result = Tax_Id_Writer::normalize_input( $input );
		$this->assertCount( 3, $result );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
		$this->assertSame( '12345678909', $result[1]['value'] );
		$this->assertSame( Tax_Id_Types::TYPE_OTHER, $result[2]['type'] );
	}

	/**
	 * Normalize_input dedupes by (type, value).
	 */
	public function test_normalize_input_dedupes(): void {
		$input = array(
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'DE123456789',
			),
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'DE123456789',
			),
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'de123456789',
			), // Same after normalize.
		);

		$result = Tax_Id_Writer::normalize_input( $input );
		$this->assertCount( 1, $result );
	}

	/**
	 * Build_updates dispatches per-type to write_map keys.
	 */
	public function test_build_updates_per_type_dispatch(): void {
		$tax_ids = array(
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'DE123456789',
				'country' => 'DE',
			),
			array(
				'type' => Tax_Id_Types::TYPE_BR_CPF,
				'value' => '12345678909',
				'country' => 'BR',
			),
		);

		$plan = Tax_Id_Writer::build_updates( $tax_ids, Tax_Id_Settings::default_write_map() );

		$this->assertSame( 'DE123456789', $plan['updates']['_billing_vat_number'] );
		$this->assertSame( '12345678909', $plan['updates']['_billing_cpf'] );
		$this->assertContains( '_billing_vat_number', $plan['owned'] );
		$this->assertContains( '_billing_cpf', $plan['owned'] );
	}

	/**
	 * Build_updates first-seen wins when types share a meta key.
	 */
	public function test_build_updates_first_seen_wins_for_shared_keys(): void {
		$tax_ids = array(
			array(
				'type' => Tax_Id_Types::TYPE_GB_VAT,
				'value' => '987654321',
				'country' => 'GB',
			),
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'DE123456789',
				'country' => 'DE',
			),
		);

		$plan = Tax_Id_Writer::build_updates( $tax_ids, Tax_Id_Settings::default_write_map() );
		$this->assertSame( 'GB987654321', $plan['updates']['_billing_vat_number'] );
	}

	/**
	 * Build_updates uses Aelia structured array shape when key is _eu_vat_data.
	 */
	public function test_build_updates_aelia_structured_form(): void {
		$tax_ids = array(
			array(
				'type'     => Tax_Id_Types::TYPE_EU_VAT,
				'value'    => 'IT12345678901',
				'country'  => 'IT',
				'verified' => array( 'status' => 'verified' ),
			),
		);
		$write_map = array( Tax_Id_Types::TYPE_EU_VAT => '_eu_vat_data' );

		$plan = Tax_Id_Writer::build_updates( $tax_ids, $write_map );

		$this->assertIsArray( $plan['updates']['_eu_vat_data'] );
		$this->assertSame( 'IT12345678901', $plan['updates']['_eu_vat_data']['vat_number'] );
		$this->assertSame( 'IT', $plan['updates']['_eu_vat_data']['country'] );
		$this->assertTrue( $plan['updates']['_eu_vat_data']['is_valid'] );
	}

	/**
	 * Build_updates skips entries whose type has no write_map entry.
	 */
	public function test_build_updates_skips_unmapped_types(): void {
		$tax_ids   = array(
			array(
				'type' => Tax_Id_Types::TYPE_EU_VAT,
				'value' => 'DE123456789',
			),
		);
		$write_map = array(); // No mapping at all.

		$plan = Tax_Id_Writer::build_updates( $tax_ids, $write_map );
		$this->assertEmpty( $plan['updates'] );
		$this->assertEmpty( $plan['owned'] );
	}

	/**
	 * Build_updates collects verification metadata into the verified sidecar payload.
	 */
	public function test_build_updates_collects_verification(): void {
		$tax_ids = array(
			array(
				'type'     => Tax_Id_Types::TYPE_EU_VAT,
				'value'    => 'DE123456789',
				'country'  => 'DE',
				'verified' => array(
					'status' => 'verified',
					'source' => 'vies',
				),
			),
		);

		$plan = Tax_Id_Writer::build_updates( $tax_ids, Tax_Id_Settings::default_write_map() );
		$this->assertCount( 1, $plan['verified'] );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $plan['verified'][0]['type'] );
		$this->assertSame( 'verified', $plan['verified'][0]['verified']['status'] );
	}

	/**
	 * Write_for_order persists meta and ownership sidecar; round-trips through
	 * Tax_Id_Reader to confirm the write produces a readable result.
	 */
	public function test_write_for_order_round_trips(): void {
		$order = OrderHelper::create_order();
		$order->set_billing_country( 'DE' );
		$order->save();

		$writer = new Tax_Id_Writer();
		$writer->write_for_order(
			$order,
			array(
				array(
					'type' => Tax_Id_Types::TYPE_EU_VAT,
					'value' => '123456789',
					'country' => 'DE',
				),
			)
		);

		// Reload the order to flush meta cache.
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'DE123456789', $order->get_meta( '_billing_vat_number', true ) );

		$owned = (array) $order->get_meta( Tax_Id_Writer::OWNED_KEYS_META_KEY, true );
		$this->assertContains( '_billing_vat_number', $owned );

		$result = ( new Tax_Id_Reader() )->read_for_order( $order );
		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
		$this->assertSame( 'DE', $result[0]['country'] );
	}

	/**
	 * Re-writing with a different set wipes stale owned keys.
	 */
	public function test_write_for_order_wipes_stale_owned_keys(): void {
		$order = OrderHelper::create_order();
		$order->set_billing_country( 'BR' );
		$order->save();

		$writer = new Tax_Id_Writer();

		// First write: a CPF.
		$writer->write_for_order(
			$order,
			array(
				array(
					'type' => Tax_Id_Types::TYPE_BR_CPF,
					'value' => '12345678909',
				),
			)
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '12345678909', $order->get_meta( '_billing_cpf', true ) );

		// Second write: only a CNPJ — CPF key should be cleared.
		$writer->write_for_order(
			$order,
			array(
				array(
					'type' => Tax_Id_Types::TYPE_BR_CNPJ,
					'value' => '12345678000195',
				),
			)
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) $order->get_meta( '_billing_cpf', true ) );
		$this->assertSame( '12345678000195', $order->get_meta( '_billing_cnpj', true ) );
	}

	/**
	 * Write_for_user with user_id <= 0 is a no-op.
	 */
	public function test_write_for_user_invalid_user_is_noop(): void {
		$writer = new Tax_Id_Writer();
		$plan   = $writer->write_for_user(
			0,
			array(
				array(
					'type' => Tax_Id_Types::TYPE_BR_CPF,
					'value' => '12345678909',
				),
			)
		);
		$this->assertEmpty( $plan['updates'] );
	}

	/**
	 * Write_for_user persists user-meta with the un-prefixed key (WC convention).
	 */
	public function test_write_for_user_uses_unprefixed_key(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'taxid_test_' . uniqid(),
				'user_pass'  => 'pw',
				'role'       => 'customer',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->assertGreaterThan( 0, $user_id );

		( new Tax_Id_Writer() )->write_for_user(
			(int) $user_id,
			array(
				array(
					'type' => Tax_Id_Types::TYPE_BR_CPF,
					'value' => '12345678909',
				),
			)
		);

		$this->assertSame( '12345678909', get_user_meta( $user_id, 'billing_cpf', true ) );
	}

	/**
	 * Snapshot_from_user_to_order copies tax IDs from the customer onto the order.
	 */
	public function test_snapshot_from_user_to_order(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'taxid_snap_' . uniqid(),
				'user_pass'  => 'pw',
				'role'       => 'customer',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		update_user_meta( $user_id, 'billing_country', 'DE' );
		update_user_meta( $user_id, 'billing_vat_number', 'DE123456789' );

		$order = OrderHelper::create_order();
		$order->set_customer_id( (int) $user_id );
		$order->set_billing_country( 'DE' );
		$order->save();

		$writer = new Tax_Id_Writer();
		$plan   = $writer->snapshot_from_user_to_order( $order, (int) $user_id );

		$order = wc_get_order( $order->get_id() );
		$this->assertNotEmpty( $plan['updates'] );
		$this->assertSame( 'DE123456789', $order->get_meta( '_billing_vat_number', true ) );
	}
}
