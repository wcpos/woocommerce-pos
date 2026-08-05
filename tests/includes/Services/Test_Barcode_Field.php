<?php
/**
 * Tests for the Barcode_Field module.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Barcode_Field;
use WP_UnitTestCase;

/**
 * Barcode_Field tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Barcode_Field extends WP_UnitTestCase {
	/**
	 * Remove the settings option written by the per-test arrangements.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * With nothing configured the module reports the default GTIN key.
	 */
	public function test_meta_key_unconfigured_returns_default(): void {
		$this->assertSame( '_global_unique_id', Barcode_Field::DEFAULT_FIELD );
		$this->assertSame( '_global_unique_id', Barcode_Field::meta_key() );
	}

	/**
	 * A configured native key is reported verbatim.
	 */
	public function test_meta_key_configured_sku_returns_sku(): void {
		$this->set_barcode_field( '_sku' );

		$this->assertSame( '_sku', Barcode_Field::meta_key() );
	}

	/**
	 * A configured custom meta key is reported verbatim.
	 */
	public function test_meta_key_configured_custom_key_returns_custom_key(): void {
		$this->set_barcode_field( '_alg_ean' );

		$this->assertSame( '_alg_ean', Barcode_Field::meta_key() );
	}

	/**
	 * DELIBERATE BEHAVIOR CHANGE. The setting's validator accepts any string, so a
	 * blank value can be persisted. It now resolves to the default instead of
	 * being handed on as an empty meta key.
	 */
	public function test_meta_key_blank_setting_coerces_to_default(): void {
		$this->set_barcode_field( '' );

		$this->assertSame( '_global_unique_id', Barcode_Field::meta_key() );
	}

	/**
	 * Whitespace-only is blank for this purpose.
	 */
	public function test_meta_key_whitespace_only_setting_coerces_to_default(): void {
		$this->set_barcode_field( "  \t " );

		$this->assertSame( '_global_unique_id', Barcode_Field::meta_key() );
	}

	/**
	 * `_sku` reads through the WooCommerce SKU property, not postmeta.
	 */
	public function test_read_sku_field_returns_product_sku(): void {
		$this->set_barcode_field( '_sku' );
		$product = ProductHelper::create_simple_product();
		$product->set_sku( 'SKU-READ-1' );
		$product->save();

		$this->assertSame( 'SKU-READ-1', Barcode_Field::read( $product ) );
	}

	/**
	 * `_global_unique_id` reads through the GTIN accessor on WC 9.1+.
	 */
	public function test_read_gtin_field_returns_global_unique_id(): void {
		$this->set_barcode_field( '_global_unique_id' );
		$product = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '4006381333931' );
		$product->save();

		$this->assertSame( '4006381333931', Barcode_Field::read( $product ) );
	}

	/**
	 * Any other key reads raw postmeta.
	 */
	public function test_read_custom_field_returns_meta_value(): void {
		$this->set_barcode_field( '_alg_ean' );
		$product = ProductHelper::create_simple_product();
		$product->update_meta_data( '_alg_ean', 'CUSTOM-READ-1' );
		$product->save_meta_data();

		$this->assertSame( 'CUSTOM-READ-1', Barcode_Field::read( $product ) );
	}

	/**
	 * An unset custom key reads as an empty string, never null.
	 */
	public function test_read_custom_field_without_value_returns_empty_string(): void {
		$this->set_barcode_field( '_alg_ean' );
		$product = ProductHelper::create_simple_product();

		$this->assertSame( '', Barcode_Field::read( $product ) );
	}

	/**
	 * A custom meta key can hold anything another plugin put there. An array is
	 * not a barcode: it reads as an empty string rather than the literal "Array"
	 * a cast would produce (plus a PHP warning).
	 */
	public function test_read_custom_field_with_array_value_returns_empty_string(): void {
		$this->set_barcode_field( '_alg_ean' );
		$product = ProductHelper::create_simple_product();
		$product->update_meta_data( '_alg_ean', array( 'not', 'a', 'barcode' ) );
		$product->save_meta_data();

		$this->assertSame( '', Barcode_Field::read( $product ) );
	}

	/**
	 * WC 9.1 GUARD (read). Without get_global_unique_id() the GTIN key falls back
	 * to the raw postmeta WooCommerce itself uses.
	 */
	public function test_read_gtin_field_falls_back_to_raw_meta_without_accessor(): void {
		$this->set_barcode_field( '_global_unique_id' );
		$stub = new Legacy_Product_Stub();
		$stub->update_meta_data( '_global_unique_id', '9780201379624' );

		$this->assertSame( '9780201379624', Barcode_Field::read( $stub ) );
	}

	/**
	 * A blank setting reads the GTIN, matching the coerced key.
	 */
	public function test_read_blank_setting_reads_global_unique_id(): void {
		$this->set_barcode_field( '' );
		$product = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '4006381333931' );
		$product->save();

		$this->assertSame( '4006381333931', Barcode_Field::read( $product ) );
	}

	/**
	 * `_sku` writes the SKU property and takes a full save.
	 */
	public function test_write_sku_field_sets_product_sku(): void {
		$this->set_barcode_field( '_sku' );
		$product = ProductHelper::create_simple_product();

		Barcode_Field::write( $product, 'SKU-WRITE-1' );

		$this->assertSame( 'SKU-WRITE-1', wc_get_product( $product->get_id() )->get_sku() );
	}

	/**
	 * `_global_unique_id` writes through the GTIN setter on WC 9.1+.
	 */
	public function test_write_gtin_field_sets_global_unique_id(): void {
		$this->set_barcode_field( '_global_unique_id' );
		$product = ProductHelper::create_simple_product();

		Barcode_Field::write( $product, '4006381333931' );

		$this->assertSame( '4006381333931', wc_get_product( $product->get_id() )->get_global_unique_id() );
	}

	/**
	 * A custom key writes postmeta.
	 */
	public function test_write_custom_field_writes_postmeta(): void {
		$this->set_barcode_field( '_alg_ean' );
		$product = ProductHelper::create_simple_product();

		Barcode_Field::write( $product, 'CUSTOM-WRITE-1' );

		$this->assertSame( 'CUSTOM-WRITE-1', get_post_meta( $product->get_id(), '_alg_ean', true ) );
	}

	/**
	 * WC 9.1 GUARD (write). Without set_global_unique_id() the GTIN path writes
	 * the same postmeta key, and still takes a FULL save so update hooks fire.
	 */
	public function test_write_gtin_field_falls_back_to_postmeta_without_setter(): void {
		$this->set_barcode_field( '_global_unique_id' );
		$stub = new Legacy_Product_Stub();

		Barcode_Field::write( $stub, '9780201379624' );

		$this->assertSame( array( '_global_unique_id' => '9780201379624' ), $stub->meta );
		$this->assertEquals( 1, $stub->saves );
		$this->assertEquals( 0, $stub->meta_saves );
	}

	/**
	 * SAVE-PATH SPLIT (preserved). The custom-meta path persists with
	 * save_meta_data(), NOT a full save.
	 */
	public function test_write_custom_field_uses_meta_save_only(): void {
		$this->set_barcode_field( '_alg_ean' );
		$stub = new Legacy_Product_Stub();

		Barcode_Field::write( $stub, 'CUSTOM-WRITE-2' );

		$this->assertSame( array( '_alg_ean' => 'CUSTOM-WRITE-2' ), $stub->meta );
		$this->assertEquals( 0, $stub->saves );
		$this->assertEquals( 1, $stub->meta_saves );
	}

	/**
	 * SAVE-PATH SPLIT (preserved). The SKU path takes a full save.
	 */
	public function test_write_sku_field_uses_full_save(): void {
		$this->set_barcode_field( '_sku' );
		$stub = new Legacy_Product_Stub();

		Barcode_Field::write( $stub, 'SKU-WRITE-2' );

		$this->assertSame( 'SKU-WRITE-2', $stub->sku );
		$this->assertEquals( 1, $stub->saves );
		$this->assertEquals( 0, $stub->meta_saves );
	}

	/**
	 * REGRESSION PIN for the deliberate blank-setting fix: a blank barcode field
	 * used to write postmeta under an EMPTY meta key. It now writes the GTIN.
	 */
	public function test_write_blank_setting_writes_gtin_not_empty_meta_key(): void {
		$this->set_barcode_field( '' );
		$product = ProductHelper::create_simple_product();

		Barcode_Field::write( $product, '4006381333931' );

		global $wpdb;
		$empty_key_rows = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = ''", $product->get_id() )
		);

		$stored = wc_get_product( $product->get_id() );
		$this->assertSame( '4006381333931', $stored->get_global_unique_id() );
		$this->assertEquals( 0, $empty_key_rows );
	}

	/**
	 * Search always covers the SKU, and adds the barcode key when it differs.
	 */
	public function test_search_keys_cover_sku_and_the_active_field(): void {
		$this->set_barcode_field( '_sku' );
		$this->assertSame( array( '_sku' ), Barcode_Field::search_keys() );

		$this->set_barcode_field( '_global_unique_id' );
		$this->assertSame( array( '_sku', '_global_unique_id' ), Barcode_Field::search_keys() );

		$this->set_barcode_field( '_alg_ean' );
		$this->assertSame( array( '_sku', '_alg_ean' ), Barcode_Field::search_keys() );

		$this->set_barcode_field( '' );
		$this->assertSame( array( '_sku', '_global_unique_id' ), Barcode_Field::search_keys() );
	}

	/**
	 * The orderby=barcode sort uses the active field in every mode.
	 */
	public function test_orderby_key_is_the_active_field(): void {
		$this->set_barcode_field( '_sku' );
		$this->assertSame( '_sku', Barcode_Field::orderby_key() );

		$this->set_barcode_field( '_global_unique_id' );
		$this->assertSame( '_global_unique_id', Barcode_Field::orderby_key() );

		$this->set_barcode_field( '_alg_ean' );
		$this->assertSame( '_alg_ean', Barcode_Field::orderby_key() );

		$this->set_barcode_field( '' );
		$this->assertSame( '_global_unique_id', Barcode_Field::orderby_key() );
	}

	/**
	 * Store the barcode field setting.
	 *
	 * @param string $value The barcode field key.
	 */
	private function set_barcode_field( string $value ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => $value ) );
	}
}
