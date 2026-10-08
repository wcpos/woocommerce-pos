<?php
/**
 * Tests for Tax_Id_Detector.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Tax_Id_Detector;
use WCPOS\WooCommercePOS\Services\Tax_Id_Settings;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WP_UnitTestCase;

/**
 * Test_Tax_Id_Detector class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Detector extends WP_UnitTestCase {
	/**
	 * Reset general settings option after each test.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * Defaults are returned when nothing is detected and no overrides exist.
	 */
	public function test_compose_with_only_defaults(): void {
		$defaults = Tax_Id_Settings::default_write_map();
		$map      = Tax_Id_Detector::compose_write_map( $defaults, array(), array(), array() );

		$this->assertSame( '_billing_vat_number', $map[ Tax_Id_Types::TYPE_EU_VAT ] );
		$this->assertSame( '_billing_cpf', $map[ Tax_Id_Types::TYPE_BR_CPF ] );
		$this->assertSame( '_billing_cnpj', $map[ Tax_Id_Types::TYPE_BR_CNPJ ] );
		$this->assertSame( '_billing_gstin', $map[ Tax_Id_Types::TYPE_IN_GST ] );
	}

	/**
	 * Inferred entries override defaults when present.
	 */
	public function test_compose_with_inferred_overrides(): void {
		$defaults = Tax_Id_Settings::default_write_map();
		$inferred = array( Tax_Id_Types::TYPE_EU_VAT => '_billing_eu_vat_number' );

		$map = Tax_Id_Detector::compose_write_map( $defaults, $inferred, array(), array() );

		$this->assertSame( '_billing_eu_vat_number', $map[ Tax_Id_Types::TYPE_EU_VAT ] );
		// Other types unaffected.
		$this->assertSame( '_billing_cpf', $map[ Tax_Id_Types::TYPE_BR_CPF ] );
	}

	/**
	 * Active plugin claims override inferred entries.
	 */
	public function test_compose_active_plugin_overrides_inferred(): void {
		$defaults = Tax_Id_Settings::default_write_map();
		$inferred = array( Tax_Id_Types::TYPE_EU_VAT => '_billing_eu_vat_number' );
		$active   = array( 'aelia_eu_vat' );

		$map = Tax_Id_Detector::compose_write_map( $defaults, $inferred, $active, array() );

		$this->assertSame( '_eu_vat_data', $map[ Tax_Id_Types::TYPE_EU_VAT ] );
		$this->assertSame( '_eu_vat_data', $map[ Tax_Id_Types::TYPE_GB_VAT ] );
	}

	/**
	 * Brazilian Market plugin claims set CPF and CNPJ keys.
	 */
	public function test_compose_brazilian_market_plugin(): void {
		$defaults = Tax_Id_Settings::default_write_map();
		$active   = array( 'br_market' );

		$map = Tax_Id_Detector::compose_write_map( $defaults, array(), $active, array() );

		$this->assertSame( '_billing_cpf', $map[ Tax_Id_Types::TYPE_BR_CPF ] );
		$this->assertSame( '_billing_cnpj', $map[ Tax_Id_Types::TYPE_BR_CNPJ ] );
	}

	/**
	 * User overrides take precedence over everything else.
	 */
	public function test_compose_user_overrides_win(): void {
		$defaults  = Tax_Id_Settings::default_write_map();
		$inferred  = array( Tax_Id_Types::TYPE_EU_VAT => '_billing_eu_vat_number' );
		$active    = array( 'aelia_eu_vat' );
		$overrides = array( Tax_Id_Types::TYPE_EU_VAT => '_my_custom_vat_key' );

		$map = Tax_Id_Detector::compose_write_map( $defaults, $inferred, $active, $overrides );

		$this->assertSame( '_my_custom_vat_key', $map[ Tax_Id_Types::TYPE_EU_VAT ] );
	}

	/**
	 * Invalid types in overrides are silently dropped.
	 */
	public function test_compose_drops_invalid_override_types(): void {
		$defaults  = Tax_Id_Settings::default_write_map();
		$overrides = array(
			'not_a_real_type'           => '_some_key',
			Tax_Id_Types::TYPE_BR_CPF   => '_my_cpf_key',
		);

		$map = Tax_Id_Detector::compose_write_map( $defaults, array(), array(), $overrides );

		$this->assertArrayNotHasKey( 'not_a_real_type', $map );
		$this->assertSame( '_my_cpf_key', $map[ Tax_Id_Types::TYPE_BR_CPF ] );
	}

	/**
	 * Empty-string override values are ignored (defaults preserved).
	 */
	public function test_compose_ignores_empty_override_values(): void {
		$defaults  = Tax_Id_Settings::default_write_map();
		$overrides = array( Tax_Id_Types::TYPE_BR_CPF => '' );

		$map = Tax_Id_Detector::compose_write_map( $defaults, array(), array(), $overrides );

		$this->assertSame( '_billing_cpf', $map[ Tax_Id_Types::TYPE_BR_CPF ] );
	}

	/**
	 * A tax-ID meta key filled in on recent orders is inferred for its type.
	 *
	 * Runs on posts storage here and on HPOS storage in Test_Tax_Id_Detector_HPOS.
	 */
	public function test_infer_from_recent_orders_with_populated_key_returns_that_key(): void {
		// Arrange.
		$order = wc_create_order();
		$order->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$order->save();

		// Act.
		$inferred = Tax_Id_Detector::infer_from_recent_orders();

		// Assert.
		$this->assertSame( '_billing_eu_vat_number', $inferred[ Tax_Id_Types::TYPE_EU_VAT ] ?? null );
	}

	/**
	 * Inference reads meta rows, never order objects.
	 *
	 * Hydrating the 200 most recent orders with their full meta on every POS
	 * order write exhausted a 128 MB request on a legacy-storage store with
	 * 5,500 orders (the checkout 500 reported on 2026-10-08).
	 */
	public function test_infer_from_recent_orders_does_not_hydrate_order_objects(): void {
		// Arrange.
		foreach ( array( 'DE123456789', '', 'DE987654321' ) as $vat ) {
			$order = wc_create_order();
			if ( '' !== $vat ) {
				$order->update_meta_data( '_billing_eu_vat_number', $vat );
			}
			$order->save();
		}
		$hydrated = 0;
		$count    = static function ( $classname ) use ( &$hydrated ) {
			++$hydrated;
			return $classname;
		};
		add_filter( 'woocommerce_order_class', $count );

		// Act.
		try {
			$inferred = Tax_Id_Detector::infer_from_recent_orders();
		} finally {
			remove_filter( 'woocommerce_order_class', $count );
		}

		// Assert.
		$this->assertSame( 0, $hydrated, 'inference must not instantiate order objects' );
		$this->assertSame( '_billing_eu_vat_number', $inferred[ Tax_Id_Types::TYPE_EU_VAT ] ?? null );
	}

	/**
	 * A key a plugin initialises to an empty array (stored serialized as
	 * `a:0:{}`) is not populated, however many orders carry it. The object path
	 * decoded the value and skipped `array()`; the count must agree.
	 */
	public function test_infer_from_recent_orders_ignores_serialized_empty_values(): void {
		// Arrange: two orders with an empty-array placeholder, one with a real value on another key.
		foreach ( array( 1, 2 ) as $_ ) {
			$order = wc_create_order();
			$order->update_meta_data( '_billing_vat_number', array() );
			$order->save();
		}
		$order = wc_create_order();
		$order->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$order->save();

		// Act.
		$inferred = Tax_Id_Detector::infer_from_recent_orders();

		// Assert.
		$this->assertSame( '_billing_eu_vat_number', $inferred[ Tax_Id_Types::TYPE_EU_VAT ] ?? null );
	}

	/**
	 * Only the newest `$limit` orders are inspected.
	 */
	public function test_infer_from_recent_orders_honours_the_limit(): void {
		// Arrange: an older order carries the key, the newest does not.
		$older = wc_create_order();
		$older->set_date_created( time() - DAY_IN_SECONDS );
		$older->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$older->save();
		$newest = wc_create_order();
		$newest->save();

		// Act.
		$inferred = Tax_Id_Detector::infer_from_recent_orders( 1 );

		// Assert.
		$this->assertArrayNotHasKey( Tax_Id_Types::TYPE_EU_VAT, $inferred );
	}

	/**
	 * `summary()` integrates settings and inference; with no plugins active and
	 * no orders, falls back to defaults.
	 */
	public function test_summary_falls_back_to_defaults(): void {
		delete_option( 'woocommerce_pos_settings_general' );

		$detector = new Tax_Id_Detector();
		$summary  = $detector->summary();

		$this->assertArrayHasKey( 'plugins', $summary );
		$this->assertArrayHasKey( 'write_map', $summary );
		$this->assertSame( '_billing_vat_number', $summary['write_map'][ Tax_Id_Types::TYPE_EU_VAT ] );
	}
}
