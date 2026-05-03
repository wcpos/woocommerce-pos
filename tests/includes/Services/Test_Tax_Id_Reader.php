<?php
/**
 * Tests for Tax_Id_Reader.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WC_REST_Unit_Test_Case;

/**
 * Test_Tax_Id_Reader class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Reader extends WC_REST_Unit_Test_Case {
	/**
	 * Reader instance.
	 *
	 * @var Tax_Id_Reader
	 */
	private $reader;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reader = new Tax_Id_Reader();
	}

	// -----------------------------------------------------------------------
	// parse_meta_map() — pure logic
	// -----------------------------------------------------------------------

	/**
	 * Empty meta map returns an empty array.
	 */
	public function test_parse_meta_map_empty(): void {
		$this->assertSame( array(), Tax_Id_Reader::parse_meta_map( array() ) );
	}

	/**
	 * Meta map with only empty values returns empty.
	 */
	public function test_parse_meta_map_only_empty_values(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_billing_vat_number' => '',
				'_billing_cpf'        => '',
			)
		);
		$this->assertSame( array(), $result );
	}

	/**
	 * Generic VAT with a country prefix in the value should be identified as EU
	 * VAT with that country, regardless of billing_country.
	 */
	public function test_generic_vat_with_country_prefix(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => 'DE123456789' ),
			'FR'  // Billing country deliberately wrong to prove prefix wins.
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
		$this->assertSame( 'DE', $result[0]['country'] );
	}

	/**
	 * GB prefix is mapped to gb_vat, not eu_vat.
	 */
	public function test_generic_vat_with_gb_prefix(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => 'GB123456789' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB', $result[0]['country'] );
	}

	/**
	 * Without a value prefix, the billing country drives the type.
	 */
	public function test_generic_vat_without_prefix_uses_billing_country(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => '12345678901' ),
			'IT'
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'IT', $result[0]['country'] );
		$this->assertSame( '12345678901', $result[0]['value'] );
	}

	/**
	 * GB billing country with no prefix → gb_vat.
	 */
	public function test_generic_vat_with_gb_billing_country(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => '123456789' ),
			'GB'
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB', $result[0]['country'] );
	}

	/**
	 * Generic VAT with no prefix and no billing country defaults to eu_vat
	 * with country=null (best-effort).
	 */
	public function test_generic_vat_without_prefix_or_country(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => '12345678901' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertNull( $result[0]['country'] );
	}

	/**
	 * Generic VAT normalises whitespace and case.
	 */
	public function test_generic_vat_normalises_value(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_vat_number' => ' de 123 456 789 ' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
		$this->assertSame( 'DE', $result[0]['country'] );
	}

	/**
	 * Aelia EU VAT Assistant `_eu_vat_data` blob is parsed into a verified
	 * eu_vat tax ID.
	 */
	public function test_aelia_eu_vat_data_verified(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_eu_vat_data' => array(
					'vat_number'   => 'IT12345678901',
					'country'      => 'IT',
					'is_valid'     => true,
					'company_name' => 'Acme S.p.A.',
					'request_date' => '2026-04-15T10:00:00Z',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'IT12345678901', $result[0]['value'] );
		$this->assertSame( 'IT', $result[0]['country'] );
		$this->assertIsArray( $result[0]['verified'] );
		$this->assertSame( 'verified', $result[0]['verified']['status'] );
		$this->assertSame( 'aelia', $result[0]['verified']['source'] );
		$this->assertSame( 'Acme S.p.A.', $result[0]['verified']['verified_name'] );
		$this->assertSame( '2026-04-15T10:00:00Z', $result[0]['verified']['verified_at'] );
	}

	/**
	 * Aelia blob with is_valid=false produces verified.status=unverified.
	 */
	public function test_aelia_eu_vat_data_unverified(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_eu_vat_data' => array(
					'vat_number' => 'IT99999999999',
					'country'    => 'IT',
					'is_valid'   => false,
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'unverified', $result[0]['verified']['status'] );
	}

	/**
	 * Aelia blob with country=GB maps to gb_vat.
	 */
	public function test_aelia_gb_country_maps_to_gb_vat(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_eu_vat_data' => array(
					'vat_number' => 'GB123456789',
					'country'    => 'GB',
					'is_valid'   => true,
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB', $result[0]['country'] );
	}

	/**
	 * Brazilian Market on WooCommerce CPF/CNPJ keys map directly.
	 */
	public function test_billing_cpf_and_cnpj(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_billing_cpf'  => '123.456.789-09',
				'_billing_cnpj' => '12.345.678/0001-90',
			),
			'BR'
		);

		$this->assertCount( 2, $result );

		$cpf = $result[0];
		$this->assertSame( Tax_Id_Types::TYPE_BR_CPF, $cpf['type'] );
		$this->assertSame( 'BR', $cpf['country'] );
		// Whitespace stripped but punctuation preserved.
		$this->assertSame( '123.456.789-09', $cpf['value'] );

		$cnpj = $result[1];
		$this->assertSame( Tax_Id_Types::TYPE_BR_CNPJ, $cnpj['type'] );
		$this->assertSame( 'BR', $cnpj['country'] );
	}

	/**
	 * GSTIN is read from `_billing_gstin`.
	 */
	public function test_billing_gstin(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_gstin' => '29ABCDE1234F1Z5' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_IN_GST, $result[0]['type'] );
		$this->assertSame( 'IN', $result[0]['country'] );
	}

	/**
	 * Italy: Codice Fiscale and Partita IVA can both appear and are read from
	 * either `_billing_cf`/`_billing_codice_fiscale` and
	 * `_billing_piva`/`_billing_partita_iva`.
	 */
	public function test_italy_cf_and_piva_from_either_key(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_billing_codice_fiscale' => 'RSSMRA80A01H501U',
				'_billing_partita_iva'    => '12345678901',
			)
		);

		$this->assertCount( 2, $result );
		$types = array_column( $result, 'type' );
		$this->assertContains( Tax_Id_Types::TYPE_IT_CF, $types );
		$this->assertContains( Tax_Id_Types::TYPE_IT_PIVA, $types );
	}

	/**
	 * Italian primary keys (`_billing_cf`/`_billing_piva`) read identically.
	 */
	public function test_italy_primary_keys(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_billing_cf'   => 'RSSMRA80A01H501U',
				'_billing_piva' => '12345678901',
			)
		);

		$this->assertCount( 2, $result );
		$types = array_column( $result, 'type' );
		$this->assertContains( Tax_Id_Types::TYPE_IT_CF, $types );
		$this->assertContains( Tax_Id_Types::TYPE_IT_PIVA, $types );
	}

	/**
	 * NIF/CIF Spain field is read from `_billing_nif`.
	 */
	public function test_spanish_nif(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_nif' => '12345678Z' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_ES_NIF, $result[0]['type'] );
		$this->assertSame( 'ES', $result[0]['country'] );
	}

	/**
	 * Argentine plugins commonly store the value in `_billing_dni` regardless
	 * of whether it is a DNI or a CUIT — we map either to ar_cuit.
	 */
	public function test_argentine_dni_maps_to_cuit(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_dni' => '20-12345678-9' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_AR_CUIT, $result[0]['type'] );
		$this->assertSame( 'AR', $result[0]['country'] );
	}

	/**
	 * `_billing_cuit` is read in addition to `_billing_dni`. When both contain
	 * the same value they dedupe.
	 */
	public function test_argentine_dni_and_cuit_dedupe(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_billing_dni'  => '20-12345678-9',
				'_billing_cuit' => '20-12345678-9',
			)
		);

		$this->assertCount( 1, $result );
	}

	/**
	 * `_billing_tax_number` is the generic catch-all and maps to type=other.
	 */
	public function test_billing_tax_number_is_other(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array( '_billing_tax_number' => 'KSA-VAT-300000000000003' )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_OTHER, $result[0]['type'] );
		$this->assertNull( $result[0]['country'] );
	}

	/**
	 * Canonical `_wcpos_tax_ids` value is parsed from a JSON string and takes
	 * priority over fallback keys (when distinct), and dedupes against them
	 * (when overlapping).
	 */
	public function test_canonical_json_seed_takes_priority_and_dedupes(): void {
		$canonical = wp_json_encode(
			array(
				array(
					'type'     => 'eu_vat',
					'value'    => 'DE123456789',
					'country'  => 'DE',
					'verified' => array(
						'status' => 'verified',
						'source' => 'vies',
					),
				),
			)
		);

		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_wcpos_tax_ids'      => $canonical,
				'_billing_vat_number' => 'DE123456789', // Duplicate — should dedupe.
				'_billing_cpf'        => '123.456.789-09', // Distinct — should appear.
			),
			'DE'
		);

		$this->assertCount( 2, $result );
		// Canonical entry comes first and retains its verified info.
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
		$this->assertIsArray( $result[0]['verified'] );
		$this->assertSame( 'verified', $result[0]['verified']['status'] );
		$this->assertSame( 'vies', $result[0]['verified']['source'] );
		// CPF still picked up from fallback.
		$this->assertSame( Tax_Id_Types::TYPE_BR_CPF, $result[1]['type'] );
	}

	/**
	 * Canonical accepts a native PHP array (for resilience post-unserialize).
	 */
	public function test_canonical_native_array(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_wcpos_tax_ids' => array(
					array(
						'type'  => 'gb_vat',
						'value' => 'GB123456789',
					),
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
	}

	/**
	 * Canonical entries with unknown types degrade to `other` rather than being dropped.
	 */
	public function test_canonical_unknown_type_degrades_to_other(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_wcpos_tax_ids' => array(
					array(
						'type'  => 'totally_made_up',
						'value' => 'XX999',
					),
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_OTHER, $result[0]['type'] );
		$this->assertSame( 'XX999', $result[0]['value'] );
	}

	/**
	 * Canonical entries with empty value are dropped.
	 */
	public function test_canonical_empty_value_dropped(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_wcpos_tax_ids' => array(
					array(
						'type'  => 'eu_vat',
						'value' => '',
					),
				),
			)
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Invalid JSON in canonical key falls through to fallback keys without crashing.
	 */
	public function test_canonical_invalid_json_falls_through(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_wcpos_tax_ids'      => '{not valid json',
				'_billing_vat_number' => 'GB123456789',
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
	}

	/**
	 * Multiple fallback keys with the same (type, value) dedupe.
	 */
	public function test_multiple_generic_vat_keys_dedupe(): void {
		$result = Tax_Id_Reader::parse_meta_map(
			array(
				'_vat_number'         => 'DE123456789',
				'_billing_vat_number' => 'DE123456789',
				'_billing_vat'        => 'de123456789',  // Case difference; normalises to same.
			)
		);

		$this->assertCount( 1, $result );
	}

	// -----------------------------------------------------------------------
	// Integration: read_for_order
	// -----------------------------------------------------------------------

	/**
	 * Read tax IDs from a freshly-created order with a generic VAT meta key.
	 */
	public function test_read_for_order_generic_vat(): void {
		$order = OrderHelper::create_order();
		$order->set_billing_country( 'DE' );
		$order->update_meta_data( '_billing_vat_number', 'DE123456789' );
		$order->save();

		$result = $this->reader->read_for_order( $order );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_EU_VAT, $result[0]['type'] );
		$this->assertSame( 'DE', $result[0]['country'] );
		$this->assertSame( 'DE123456789', $result[0]['value'] );
	}

	/**
	 * Read tax IDs from an order with no relevant meta returns empty.
	 */
	public function test_read_for_order_empty(): void {
		$order = OrderHelper::create_order();

		$this->assertSame( array(), $this->reader->read_for_order( $order ) );
	}

	/**
	 * Read tax IDs from an order with the canonical key wins over fallback.
	 */
	public function test_read_for_order_canonical_takes_priority(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data(
			Tax_Id_Reader::CANONICAL_META_KEY,
			wp_json_encode(
				array(
					array(
						'type'  => 'au_abn',
						'value' => '51824753556',
					),
				)
			)
		);
		// Add a generic VAT fallback that should dedupe against the canonical value.
		$order->update_meta_data( '_billing_vat_number', '51824753556' );
		$order->save();

		$result = $this->reader->read_for_order( $order );

		// Both should appear because (au_abn, 51824753556) and (eu_vat, 51824753556)
		// are different (type, value) pairs.
		$types = array_column( $result, 'type' );
		$this->assertContains( Tax_Id_Types::TYPE_AU_ABN, $types );
	}

	/**
	 * Malformed canonical order meta must not suppress valid owned fallback keys.
	 */
	public function test_read_for_order_malformed_canonical_keeps_owned_fallback(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( Tax_Id_Reader::CANONICAL_META_KEY, '{not valid json' );
		$order->update_meta_data( Tax_Id_Writer::OWNED_KEYS_META_KEY, array( '_billing_vat_number' ) );
		$order->update_meta_data( '_billing_vat_number', 'GB123456789' );
		$order->save();

		$result = $this->reader->read_for_order( $order );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB123456789', $result[0]['value'] );
	}

	// -----------------------------------------------------------------------
	// Integration: read_for_user
	// -----------------------------------------------------------------------

	/**
	 * Read tax IDs from a user with the underscore-stripped meta key
	 * (the WC convention for customer-side meta).
	 */
	public function test_read_for_user_uses_non_underscored_key(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'billing_country', 'BR' );
		update_user_meta( $user_id, 'billing_cpf', '123.456.789-09' );

		$result = $this->reader->read_for_user( $user_id );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_BR_CPF, $result[0]['type'] );
		$this->assertSame( 'BR', $result[0]['country'] );

		wp_delete_user( $user_id );
	}

	/**
	 * Read tax IDs from the canonical WCPOS customer meta key.
	 */
	public function test_read_for_user_canonical_meta(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta(
			$user_id,
			Tax_Id_Reader::CANONICAL_META_KEY,
			wp_json_encode(
				array(
					array(
						'type'    => Tax_Id_Types::TYPE_GB_VAT,
						'value'   => 'GB123456789',
						'country' => 'GB',
					),
				)
			)
		);

		$result = $this->reader->read_for_user( $user_id );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB123456789', $result[0]['value'] );
		$this->assertSame( 'GB', $result[0]['country'] );

		wp_delete_user( $user_id );
	}

	/**
	 * Malformed canonical user meta must not suppress valid owned fallback keys.
	 */
	public function test_read_for_user_malformed_canonical_keeps_owned_fallback(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, Tax_Id_Reader::CANONICAL_META_KEY, '{not valid json' );
		update_user_meta( $user_id, Tax_Id_Writer::OWNED_KEYS_META_KEY, array( '_billing_vat_number' ) );
		update_user_meta( $user_id, 'billing_vat_number', 'GB123456789' );

		$result = $this->reader->read_for_user( $user_id );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );
		$this->assertSame( 'GB123456789', $result[0]['value'] );

		wp_delete_user( $user_id );
	}

	/**
	 * Read tax IDs from a user with the underscore-prefixed variant (some
	 * plugins store user meta with a leading underscore).
	 */
	public function test_read_for_user_falls_back_to_underscored_key(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, '_billing_vat_number', 'GB123456789' );

		$result = $this->reader->read_for_user( $user_id );

		$this->assertCount( 1, $result );
		$this->assertSame( Tax_Id_Types::TYPE_GB_VAT, $result[0]['type'] );

		wp_delete_user( $user_id );
	}

	/**
	 * Reading for an invalid user_id returns empty without errors.
	 */
	public function test_read_for_user_invalid_id(): void {
		$this->assertSame( array(), $this->reader->read_for_user( 0 ) );
		$this->assertSame( array(), $this->reader->read_for_user( -1 ) );
	}
}
