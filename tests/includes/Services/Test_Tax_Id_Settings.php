<?php
/**
 * Tests for Tax_Id_Settings.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Tax_Id_Settings;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WP_UnitTestCase;

/**
 * Test_Tax_Id_Settings class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Settings extends WP_UnitTestCase {
	/**
	 * Reset tax_ids settings option after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_tax_ids' );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * Default_write_map provides a meta-key for every customer-applicable type.
	 *
	 * Store-only commercial-register types (Tax_Id_Types::business_register_types())
	 * are excluded — they don't write to customer/order billing meta.
	 */
	public function test_default_write_map_covers_every_type(): void {
		$map = Tax_Id_Settings::default_write_map();
		foreach ( Tax_Id_Types::customer_applicable_types() as $type ) {
			$this->assertArrayHasKey( $type, $map, "Missing default write key for type: {$type}" );
			$this->assertNotEmpty( $map[ $type ] );
		}
	}

	/**
	 * Default_write_map sends VAT-shaped types to _billing_vat_number.
	 */
	public function test_default_write_map_vat_aliases(): void {
		$map = Tax_Id_Settings::default_write_map();
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_EU_VAT ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_GB_VAT ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_SA_VAT ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_AU_ABN ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_CA_GST_HST ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_US_EIN ] );
		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_OTHER ] );
	}

	/**
	 * Get_overrides returns the values stored in tax_ids settings.
	 */
	public function test_get_overrides_reads_settings(): void {
		update_option(
			'woocommerce_pos_settings_tax_ids',
			array(
				'write_map' => array(
					Tax_Id_Types::TYPE_BR_CPF => '_my_cpf',
				),
			)
		);

		$overrides = Tax_Id_Settings::get_overrides();
		$this->assertSame( array( Tax_Id_Types::TYPE_BR_CPF => '_my_cpf' ), $overrides );
	}

	/**
	 * Get_overrides falls back to the legacy general.tax_ids write map.
	 */
	public function test_get_overrides_reads_legacy_settings(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'tax_ids' => array(
					'write_map' => array(
						Tax_Id_Types::TYPE_BR_CPF => '_legacy_cpf',
					),
				),
			)
		);

		$overrides = Tax_Id_Settings::get_overrides();
		$this->assertSame( array( Tax_Id_Types::TYPE_BR_CPF => '_legacy_cpf' ), $overrides );
	}

	/**
	 * An explicit new write_map wins over the legacy fallback, even when empty.
	 */
	public function test_get_overrides_prefers_new_write_map_over_legacy(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'tax_ids' => array(
					'write_map' => array(
						Tax_Id_Types::TYPE_BR_CPF => '_legacy_cpf',
					),
				),
			)
		);
		update_option( 'woocommerce_pos_settings_tax_ids', array( 'write_map' => array() ) );

		$this->assertSame( array(), Tax_Id_Settings::get_overrides() );
	}

	/**
	 * Get_overrides drops entries with invalid types or empty values.
	 */
	public function test_get_overrides_filters_invalid(): void {
		update_option(
			'woocommerce_pos_settings_tax_ids',
			array(
				'write_map' => array(
					'bogus_type'               => '_x',
					Tax_Id_Types::TYPE_BR_CPF  => '',
					Tax_Id_Types::TYPE_BR_CNPJ => '_my_cnpj',
				),
			)
		);

		$overrides = Tax_Id_Settings::get_overrides();
		$this->assertSame( array( Tax_Id_Types::TYPE_BR_CNPJ => '_my_cnpj' ), $overrides );
	}

	/**
	 * Get_overrides returns empty array when settings are missing/malformed.
	 */
	public function test_get_overrides_handles_missing(): void {
		delete_option( 'woocommerce_pos_settings_tax_ids' );
		$this->assertSame( array(), Tax_Id_Settings::get_overrides() );

		update_option( 'woocommerce_pos_settings_tax_ids', 'not-an-array' );
		$this->assertSame( array(), Tax_Id_Settings::get_overrides() );

		update_option( 'woocommerce_pos_settings_tax_ids', array( 'write_map' => 'not-an-array' ) );
		$this->assertSame( array(), Tax_Id_Settings::get_overrides() );
	}
}
