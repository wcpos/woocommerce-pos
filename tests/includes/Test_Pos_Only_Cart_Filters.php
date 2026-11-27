<?php
/**
 * Tests for POS Only cart filters outside the Store API.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_UnitTestCase;

/**
 * POS Only cart filters outside the Store API.
 *
 * @covers \WCPOS\WooCommercePOS\Products
 */
class Test_Pos_Only_Cart_Filters extends WP_UnitTestCase {
	/**
	 * Visible simple product ID.
	 *
	 * @var int
	 */
	private int $visible;

	/**
	 * POS Only simple product ID.
	 *
	 * @var int
	 */
	private int $pos_only;

	/**
	 * User ID with permission to edit the products.
	 *
	 * @var int
	 */
	private int $shop_manager;

	/**
	 * Set up cart visibility fixtures and start as a guest shopper.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->reset_sections_for_testing();
		delete_option( Pos_Visibility::OPTION );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		new Products();

		$this->visible  = ProductHelper::create_simple_product( array( 'name' => 'Cart Visible Product' ) )->get_id();
		$this->pos_only = ProductHelper::create_simple_product( array( 'name' => 'Cart Secret Simple' ) )->get_id();
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'pos_only',
				'ids'        => array( $this->pos_only ),
				'scope'      => 'default',
			)
		);
		$this->shop_manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Restore the visibility settings.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		parent::tearDown();
	}

	/**
	 * Outside the Store API, the cart item data filter leaves data alone.
	 */
	public function test_cart_item_data_filter_outside_store_api_returns_data_unchanged(): void {
		$data = apply_filters( 'woocommerce_add_cart_item_data', array( 'note' => 'kept' ), $this->pos_only, 0, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		$this->assertSame( array( 'note' => 'kept' ), $data );
	}

	/**
	 * Classic AJAX errors do not disclose a hidden product's permalink.
	 */
	public function test_ajax_add_to_cart_error_url_for_pos_only_product_is_false(): void {
		$this->assertFalse( apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $this->pos_only ), $this->pos_only ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
	}

	/**
	 * Visible products and products the user can edit keep their error URL.
	 */
	public function test_ajax_add_to_cart_error_url_kept_for_visible_product_and_editor(): void {
		$visible_url = get_permalink( $this->visible );
		$this->assertSame( $visible_url, apply_filters( 'woocommerce_cart_redirect_after_error', $visible_url, $this->visible ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.

		wp_set_current_user( $this->shop_manager );
		$pos_only_url = get_permalink( $this->pos_only );
		$this->assertSame( $pos_only_url, apply_filters( 'woocommerce_cart_redirect_after_error', $pos_only_url, $this->pos_only ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
	}
}
