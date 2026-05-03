<?php
/**
 * Tests for Tax_Id_Types.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WP_UnitTestCase;

/**
 * Test_Tax_Id_Types class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Types extends WP_UnitTestCase {
	/**
	 * Test that all_types() returns every type constant declared on the class.
	 */
	public function test_all_types_includes_known_types(): void {
		$all = Tax_Id_Types::all_types();

		$this->assertContains( Tax_Id_Types::TYPE_EU_VAT, $all );
		$this->assertContains( Tax_Id_Types::TYPE_GB_VAT, $all );
		$this->assertContains( Tax_Id_Types::TYPE_AU_ABN, $all );
		$this->assertContains( Tax_Id_Types::TYPE_BR_CPF, $all );
		$this->assertContains( Tax_Id_Types::TYPE_BR_CNPJ, $all );
		$this->assertContains( Tax_Id_Types::TYPE_IN_GST, $all );
		$this->assertContains( Tax_Id_Types::TYPE_IT_CF, $all );
		$this->assertContains( Tax_Id_Types::TYPE_IT_PIVA, $all );
		$this->assertContains( Tax_Id_Types::TYPE_ES_NIF, $all );
		$this->assertContains( Tax_Id_Types::TYPE_AR_CUIT, $all );
		$this->assertContains( Tax_Id_Types::TYPE_SA_VAT, $all );
		$this->assertContains( Tax_Id_Types::TYPE_CA_GST_HST, $all );
		$this->assertContains( Tax_Id_Types::TYPE_US_EIN, $all );
		$this->assertContains( Tax_Id_Types::TYPE_OTHER, $all );
	}

	/**
	 * Test is_valid_type for known and unknown types.
	 */
	public function test_is_valid_type(): void {
		$this->assertTrue( Tax_Id_Types::is_valid_type( 'eu_vat' ) );
		$this->assertTrue( Tax_Id_Types::is_valid_type( 'br_cpf' ) );
		$this->assertTrue( Tax_Id_Types::is_valid_type( 'other' ) );
		$this->assertFalse( Tax_Id_Types::is_valid_type( '' ) );
		$this->assertFalse( Tax_Id_Types::is_valid_type( 'EU_VAT' ) );
		$this->assertFalse( Tax_Id_Types::is_valid_type( 'unknown_type' ) );
	}

	/**
	 * Test country_for_type derives ISO alpha-2 codes for single-country types
	 * and returns null for multi-country (eu_vat) and unspecified (other).
	 */
	public function test_country_for_type(): void {
		$this->assertSame( 'GB', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_GB_VAT ) );
		$this->assertSame( 'AU', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_AU_ABN ) );
		$this->assertSame( 'BR', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_BR_CPF ) );
		$this->assertSame( 'BR', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_BR_CNPJ ) );
		$this->assertSame( 'IN', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_IN_GST ) );
		$this->assertSame( 'IT', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_IT_CF ) );
		$this->assertSame( 'IT', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_IT_PIVA ) );
		$this->assertSame( 'ES', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_ES_NIF ) );
		$this->assertSame( 'AR', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_AR_CUIT ) );
		$this->assertSame( 'SA', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_SA_VAT ) );
		$this->assertSame( 'CA', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_CA_GST_HST ) );
		$this->assertSame( 'US', Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_US_EIN ) );

		$this->assertNull( Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_EU_VAT ) );
		$this->assertNull( Tax_Id_Types::country_for_type( Tax_Id_Types::TYPE_OTHER ) );
	}

	/**
	 * Test default_label returns a non-empty translatable string for every type.
	 */
	public function test_default_label_returns_non_empty_for_every_type(): void {
		foreach ( Tax_Id_Types::all_types() as $type ) {
			$label = Tax_Id_Types::default_label( $type );
			$this->assertIsString( $label );
			$this->assertNotEmpty( $label );
		}
	}

	/**
	 * Test default_label returns the country/type-specific labels we expect.
	 */
	public function test_default_label_specific_values(): void {
		$this->assertSame( 'VAT Number', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_EU_VAT ) );
		$this->assertSame( 'VAT Number', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_GB_VAT ) );
		$this->assertSame( 'VAT Number', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_SA_VAT ) );
		$this->assertSame( 'ABN', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_AU_ABN ) );
		$this->assertSame( 'CPF', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_BR_CPF ) );
		$this->assertSame( 'CNPJ', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_BR_CNPJ ) );
		$this->assertSame( 'GSTIN', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_IN_GST ) );
		$this->assertSame( 'Codice Fiscale', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_IT_CF ) );
		$this->assertSame( 'Partita IVA', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_IT_PIVA ) );
		$this->assertSame( 'NIF', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_ES_NIF ) );
		$this->assertSame( 'CUIT', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_AR_CUIT ) );
		$this->assertSame( 'GST/HST', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_CA_GST_HST ) );
		$this->assertSame( 'EIN', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_US_EIN ) );
		$this->assertSame( 'Tax ID', Tax_Id_Types::default_label( Tax_Id_Types::TYPE_OTHER ) );
	}

	/**
	 * Test that all 8 business-register types are present in all_types() and pass is_valid_type().
	 */
	public function test_business_register_types_are_in_all_types(): void {
		$all = Tax_Id_Types::all_types();
		foreach ( array(
			'de_ust_id',
			'de_steuernummer',
			'de_hrb',
			'nl_kvk',
			'fr_siret',
			'fr_siren',
			'gb_company',
			'ch_uid',
		) as $type ) {
			$this->assertContains( $type, $all, "Missing business-register type: {$type}" );
			$this->assertTrue( Tax_Id_Types::is_valid_type( $type ), "Type rejected: {$type}" );
		}
	}

	/**
	 * Test that business-register types resolve to the correct ISO 3166-1 alpha-2 country code.
	 */
	public function test_business_register_types_resolve_to_country(): void {
		$this->assertSame( 'DE', Tax_Id_Types::country_for_type( 'de_ust_id' ) );
		$this->assertSame( 'DE', Tax_Id_Types::country_for_type( 'de_steuernummer' ) );
		$this->assertSame( 'DE', Tax_Id_Types::country_for_type( 'de_hrb' ) );
		$this->assertSame( 'NL', Tax_Id_Types::country_for_type( 'nl_kvk' ) );
		$this->assertSame( 'FR', Tax_Id_Types::country_for_type( 'fr_siret' ) );
		$this->assertSame( 'FR', Tax_Id_Types::country_for_type( 'fr_siren' ) );
		$this->assertSame( 'GB', Tax_Id_Types::country_for_type( 'gb_company' ) );
		$this->assertSame( 'CH', Tax_Id_Types::country_for_type( 'ch_uid' ) );
	}

	/**
	 * Test that business-register types return their expected default labels.
	 */
	public function test_business_register_types_have_default_labels(): void {
		$this->assertSame( 'USt-IdNr.',    Tax_Id_Types::default_label( 'de_ust_id' ) );
		$this->assertSame( 'Steuernummer', Tax_Id_Types::default_label( 'de_steuernummer' ) );
		$this->assertSame( 'HRB',          Tax_Id_Types::default_label( 'de_hrb' ) );
		$this->assertSame( 'KVK',          Tax_Id_Types::default_label( 'nl_kvk' ) );
		$this->assertSame( 'SIRET',        Tax_Id_Types::default_label( 'fr_siret' ) );
		$this->assertSame( 'SIREN',        Tax_Id_Types::default_label( 'fr_siren' ) );
		$this->assertSame( 'Company No.',  Tax_Id_Types::default_label( 'gb_company' ) );
		$this->assertSame( 'UID',          Tax_Id_Types::default_label( 'ch_uid' ) );
	}

	/**
	 * Every business-register type appears in `all_types()`.
	 */
	public function test_business_register_types_subset_of_all_types(): void {
		$all       = Tax_Id_Types::all_types();
		$business  = Tax_Id_Types::business_register_types();
		$this->assertNotEmpty( $business );
		foreach ( $business as $type ) {
			$this->assertContains( $type, $all, "Business-register type {$type} should appear in all_types()" );
		}
	}

	/**
	 * `customer_applicable_types()` excludes every store-only business-register type.
	 */
	public function test_customer_applicable_types_excludes_business_registers(): void {
		$customer = Tax_Id_Types::customer_applicable_types();
		$business = Tax_Id_Types::business_register_types();
		foreach ( $business as $type ) {
			$this->assertNotContains( $type, $customer, "Business-register type {$type} must not appear in customer_applicable_types()" );
		}
	}

	/**
	 * `customer_applicable_types()` and `business_register_types()` are a strict partition of `all_types()`.
	 */
	public function test_customer_applicable_plus_business_register_equals_all(): void {
		$all      = Tax_Id_Types::all_types();
		$customer = Tax_Id_Types::customer_applicable_types();
		$business = Tax_Id_Types::business_register_types();
		$this->assertSame(
			count( $all ),
			count( $customer ) + count( $business ),
			'customer_applicable_types() + business_register_types() should partition all_types()'
		);
	}

	/**
	 * Test is_eu_vat_country recognises EU member states (note the EL/GR quirk).
	 */
	public function test_is_eu_vat_country(): void {
		$this->assertTrue( Tax_Id_Types::is_eu_vat_country( 'DE' ) );
		$this->assertTrue( Tax_Id_Types::is_eu_vat_country( 'IT' ) );
		$this->assertTrue( Tax_Id_Types::is_eu_vat_country( 'FR' ) );
		// Greece uses EL not GR for VAT.
		$this->assertTrue( Tax_Id_Types::is_eu_vat_country( 'EL' ) );
		$this->assertFalse( Tax_Id_Types::is_eu_vat_country( 'GR' ) );
		// Case-insensitive.
		$this->assertTrue( Tax_Id_Types::is_eu_vat_country( 'de' ) );

		$this->assertFalse( Tax_Id_Types::is_eu_vat_country( 'GB' ) );
		$this->assertFalse( Tax_Id_Types::is_eu_vat_country( 'US' ) );
		$this->assertFalse( Tax_Id_Types::is_eu_vat_country( 'AU' ) );
		$this->assertFalse( Tax_Id_Types::is_eu_vat_country( '' ) );
	}
}
