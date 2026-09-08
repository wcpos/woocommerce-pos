<?php
/**
 * Tests for WCPOS POS Only catalog visibility.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Admin\Products\List_Products;
use WCPOS\WooCommercePOS\Admin\Products\Single_Product;
use WCPOS\WooCommercePOS\Catalog_Visibility;
use WCPOS\WooCommercePOS\Deactivator;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Catalog visibility transitions through the real option writers.
 *
 * @covers \WCPOS\WooCommercePOS\Catalog_Visibility
 */
class Test_Catalog_Visibility extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Start with empty visibility settings and the feature enabled.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->reset_sections_for_testing();
		delete_option( Pos_Visibility::OPTION );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
	}

	/**
	 * Clean form inputs before option cleanup can save products.
	 */
	public function tearDown(): void {
		unset( $_POST['_pos_visibility'], $_GET['_pos_visibility'] );
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		parent::tearDown();
	}

	/**
	 * Write one product through the settings facade.
	 *
	 * @param int    $id         Product ID.
	 * @param string $visibility POS visibility.
	 * @param string $scope      Store scope.
	 */
	private function set_visibility( int $id, string $visibility = 'pos_only', string $scope = 'default' ): void {
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => $visibility,
				'ids'        => array( $id ),
				'scope'      => $scope,
			)
		);
	}

	/**
	 * Entering records the original value, and leaving restores it.
	 *
	 * @dataProvider prior_visibility_provider
	 * @param string $prior Original catalog visibility.
	 */
	public function test_catalog_visibility_pos_only_round_trip_restores_prior( string $prior ): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$product->set_catalog_visibility( $prior );
		$product->save();
		$id = $product->get_id();

		// Act.
		$this->set_visibility( $id );

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( $prior, get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );

		// Act.
		$this->set_visibility( $id, '' );

		// Assert.
		$this->assertSame( $prior, wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * Original merchant choices, including explicitly hidden products.
	 *
	 * @return array<string, string[]>
	 */
	public function prior_visibility_provider(): array {
		return array(
			'visible' => array( 'visible' ),
			'catalog' => array( 'catalog' ),
			'hidden'  => array( 'hidden' ),
		);
	}

	/**
	 * Missing or invalid prior metadata restores the visible default.
	 *
	 * @dataProvider invalid_prior_provider
	 * @param mixed $prior Missing or invalid metadata.
	 */
	public function test_catalog_visibility_missing_or_invalid_prior_restores_visible( $prior ): void {
		// Arrange.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );
		delete_post_meta( $id, Catalog_Visibility::PRIOR_META );
		if ( null !== $prior ) {
			update_post_meta( $id, Catalog_Visibility::PRIOR_META, $prior );
		}

		// Act.
		$this->set_visibility( $id, '' );

		// Assert.
		$this->assertSame( 'visible', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * Invalid values must not reach WooCommerce's visibility setter.
	 *
	 * @return array<string, array>
	 */
	public function invalid_prior_provider(): array {
		return array(
			'missing' => array( null ),
			'invalid' => array( 'not-a-visibility' ),
			'array'   => array( array( 'catalog' ) ),
		);
	}

	/**
	 * A merchant's more recent catalog choice wins when POS Only is removed.
	 */
	public function test_catalog_visibility_changed_before_leaving_preserves_search(): void {
		// Arrange: a term write that bypasses the CRUD seam, as a taxonomy import would.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );
		wp_set_object_terms( $id, array( 'exclude-from-catalog' ), 'product_visibility' );
		clean_post_cache( $id );
		$this->assertSame( 'search', wc_get_product( $id )->get_catalog_visibility() );

		// Act.
		$this->set_visibility( $id, '' );

		// Assert.
		$this->assertSame( 'search', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * Variations are ignored even if an ID is incorrectly listed as a product.
	 */
	public function test_catalog_visibility_variation_pos_only_leaves_parent_untouched(): void {
		// Arrange.
		$parent = ProductHelper::create_variation_product();
		$id     = (int) $parent->get_children()[0];

		// Act.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'variations',
				'visibility' => 'pos_only',
				'ids'        => array( $id ),
			)
		);
		$this->set_visibility( $id );
		$this->set_visibility( $id, '' );

		// Assert.
		$this->assertSame( 'visible', wc_get_product( $parent->get_id() )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
		$this->assertFalse( metadata_exists( 'post', $parent->get_id(), Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * The feature toggle restores every configured product and re-hides them.
	 */
	public function test_catalog_visibility_feature_toggle_restores_and_rehides_configured_products(): void {
		// Arrange.
		$first  = ProductHelper::create_simple_product()->get_id();
		$second = ProductHelper::create_simple_product();
		$second->set_catalog_visibility( 'catalog' );
		$second->save();
		$this->set_visibility( $first );
		$this->set_visibility( $second->get_id() );

		// Act.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => false ) );

		// Assert.
		$this->assertSame( 'visible', wc_get_product( $first )->get_catalog_visibility() );
		$this->assertSame( 'catalog', wc_get_product( $second->get_id() )->get_catalog_visibility() );

		// Act.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $first )->get_catalog_visibility() );
		$this->assertSame( 'hidden', wc_get_product( $second->get_id() )->get_catalog_visibility() );
	}

	/**
	 * Only the default scope drives the invariant, matching
	 * Products::hide_pos_only_products(); a store-scoped list is a Pro concern.
	 */
	public function test_catalog_visibility_store_scoped_pos_only_leaves_catalog_untouched(): void {
		// Arrange.
		$id = ProductHelper::create_simple_product()->get_id();

		// Act.
		$this->set_visibility( $id, 'pos_only', 'store-2' );

		// Assert.
		$this->assertSame( 'visible', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * Deleting either source option restores the configured products.
	 *
	 * @dataProvider source_option_provider
	 * @param string $option Option to delete.
	 */
	public function test_catalog_visibility_option_deleted_restores_product( string $option ): void {
		// Arrange.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );

		// Act.
		delete_option( $option );

		// Assert.
		$this->assertSame( 'visible', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * Both settings sources can be removed by a reset.
	 *
	 * @return array<string, string[]>
	 */
	public function source_option_provider(): array {
		return array(
			'visibility' => array( 'woocommerce_pos_settings_visibility' ),
			'general'    => array( 'woocommerce_pos_settings_general' ),
		);
	}

	/**
	 * Raw option writes skip stale IDs without skipping the remaining products.
	 */
	public function test_catalog_visibility_missing_product_does_not_block_other_ids(): void {
		// Arrange.
		$id      = ProductHelper::create_simple_product()->get_id();
		$deleted = ProductHelper::create_simple_product();
		$missing = $deleted->get_id();
		$deleted->delete( true );

		// Act.
		update_option( Pos_Visibility::OPTION, array( 'products' => array( 'default' => array( 'pos_only' => array( 'ids' => array( $missing, $id ) ) ) ) ) );

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $missing, Catalog_Visibility::PRIOR_META ) );
	}

	/**
	 * The settings endpoint's write path — the POS app and the settings screen
	 * PATCH the whole section, which the controller merges and writes exactly
	 * like this — reaches the same option observer as the admin forms.
	 */
	public function test_catalog_visibility_settings_section_patch_hides_product(): void {
		// Arrange.
		$id      = ProductHelper::create_simple_product()->get_id();
		$section = Settings::instance()->sections()->get( 'visibility' );
		$payload = array( 'products' => array( 'default' => array( 'pos_only' => array( 'ids' => array( $id ) ) ) ) );

		// Act.
		$result = $section->write( $section->merge( $section->read(), $payload ) );

		// Assert.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'visible', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}

	/**
	 * Each admin form's POS Only write reaches the option observer.
	 *
	 * @dataProvider admin_writer_provider
	 * @param string $writer Admin form writer.
	 */
	public function test_catalog_visibility_admin_writer_entering_pos_only_hides_product( string $writer ): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$id      = $product->get_id();

		// Act.
		if ( 'bulk' === $writer ) {
			$_GET['_pos_visibility'] = 'pos_only';
			( new List_Products() )->bulk_edit_save( $product );
		} else {
			$_POST['_pos_visibility'] = 'pos_only';
			if ( 'quick' === $writer ) {
				List_Products::quick_edit_save( $product );
			} else {
				( new Single_Product() )->save_post( $id, get_post( $id ) );
			}
		}

		// Assert.
		$this->assertContains( $id, ( new Pos_Visibility() )->pos_only_ids() );
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'visible', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}

	/**
	 * All three forms funnel into update_visibility_settings().
	 *
	 * @return array<string, string[]>
	 */
	public function admin_writer_provider(): array {
		return array(
			'single' => array( 'single' ),
			'quick'  => array( 'quick' ),
			'bulk'   => array( 'bulk' ),
		);
	}

	/**
	 * A save that is not a transition — bulk edit with POS visibility left at
	 * "No change", a wc/v3 write, a CSV import — cannot un-hide a POS Only product.
	 */
	public function test_catalog_visibility_saving_a_pos_only_product_visible_keeps_it_hidden(): void {
		// Arrange.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );
		$product = wc_get_product( $id );

		// Act.
		$product->set_catalog_visibility( 'visible' );
		$product->save();

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'visible', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}

	/**
	 * The before-save seam records a prior for a POS Only product that has none
	 * (pre-#1862 data), without an extra write.
	 */
	public function test_catalog_visibility_saving_a_pos_only_product_without_prior_records_the_posted_value(): void {
		// Arrange.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );
		delete_post_meta( $id, Catalog_Visibility::PRIOR_META );
		$product = wc_get_product( $id );
		$saves   = 0;
		add_action(
			'woocommerce_update_product',
			function () use ( &$saves ) {
				++$saves;
			}
		);

		// Act.
		$product->set_catalog_visibility( 'catalog' );
		$product->save();

		// Assert.
		$this->assertSame( 1, $saves );
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'catalog', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}

	/**
	 * A copy of a POS Only product is born with the source's prior visibility,
	 * outside the set and without the prior meta, so it can appear online.
	 */
	public function test_catalog_visibility_duplicate_of_pos_only_product_gets_the_prior_visibility(): void {
		// Arrange.
		$source = ProductHelper::create_simple_product();
		$source->set_catalog_visibility( 'catalog' );
		$source->save();
		$this->set_visibility( $source->get_id() );
		$this->assertSame( 'hidden', wc_get_product( $source->get_id() )->get_catalog_visibility() );
		include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';

		// Act.
		$duplicate = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $source->get_id() ) );

		// Assert.
		$this->assertSame( 'catalog', wc_get_product( $duplicate->get_id() )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $duplicate->get_id(), Catalog_Visibility::PRIOR_META ) );
		$this->assertNotContains( $duplicate->get_id(), ( new Pos_Visibility() )->pos_only_ids() );
		$this->assertSame( 'hidden', wc_get_product( $source->get_id() )->get_catalog_visibility(), 'The source stays hidden.' );
	}

	/**
	 * Deactivating gives POS Only products their own visibility back, and
	 * activating (which also runs on every upgrade) re-applies the invariant.
	 */
	public function test_catalog_visibility_deactivation_restores_and_activation_reapplies(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$product->set_catalog_visibility( 'catalog' );
		$product->save();
		$id = $product->get_id();
		$this->set_visibility( $id );
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );

		// Act.
		( new Deactivator() )->single_deactivate();

		// Assert.
		$this->assertSame( 'catalog', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );

		// Act.
		( new Activator() )->single_activate( false );

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'catalog', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}

	/**
	 * Duplicates must not inherit another ID's restoration metadata.
	 */
	public function test_catalog_visibility_duplicate_excludes_prior_meta(): void {
		// Arrange.
		$excluded = array( '_another_excluded_key' );

		// Act.
		$result = apply_filters( 'woocommerce_duplicate_product_exclude_meta', $excluded ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce's duplication filter.

		// Assert.
		$this->assertContains( Catalog_Visibility::PRIOR_META, $result );
		$this->assertContains( '_another_excluded_key', $result );
	}

	/**
	 * Upgrading hides pre-existing POS Only products and can be repeated safely.
	 */
	public function test_catalog_visibility_update_routine_hides_existing_product(): void {
		// Arrange: recreate the pre-upgrade state (POS Only, stored visible, no
		// prior) with term and meta writes that bypass the CRUD seam.
		$id = ProductHelper::create_simple_product()->get_id();
		$this->set_visibility( $id );
		delete_post_meta( $id, Catalog_Visibility::PRIOR_META );
		wp_set_object_terms( $id, array(), 'product_visibility' );
		clean_post_cache( $id );
		$this->assertSame( 'visible', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertFalse( metadata_exists( 'post', $id, Catalog_Visibility::PRIOR_META ) );

		// Act.
		include __DIR__ . '/../../includes/updates/update-1.11.0.php';
		include __DIR__ . '/../../includes/updates/update-1.11.0.php';

		// Assert.
		$this->assertSame( 'hidden', wc_get_product( $id )->get_catalog_visibility() );
		$this->assertSame( 'visible', get_post_meta( $id, Catalog_Visibility::PRIOR_META, true ) );
	}
}
