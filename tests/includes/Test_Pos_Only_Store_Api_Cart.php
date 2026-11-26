<?php
/**
 * Tests for POS Only cart error responses.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Store API add-to-cart answers a POS Only product like a missing product.
 *
 * @covers \WCPOS\WooCommercePOS\Products
 */
class Test_Pos_Only_Store_Api_Cart extends WCPOS_REST_Unit_Test_Case {
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
	 * POS Only variable product ID.
	 *
	 * @var int
	 */
	private int $variable;

	/**
	 * Variation ID hidden by its POS Only parent.
	 *
	 * @var int
	 */
	private int $pos_only_child;

	/**
	 * POS Only variation ID with a visible parent.
	 *
	 * @var int
	 */
	private int $pos_only_variation;

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
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$this->visible  = ProductHelper::create_simple_product( array( 'name' => 'Cart Visible Product' ) )->get_id();
		$this->pos_only = ProductHelper::create_simple_product( array( 'name' => 'Cart Secret Simple' ) )->get_id();

		$variable = ProductHelper::create_variation_product();
		$variable->set_name( 'Cart Secret Variable' );
		$variable->save();
		$this->variable       = $variable->get_id();
		$this->pos_only_child = $variable->get_children()[0];

		$open = ProductHelper::create_variation_product();
		$open->set_name( 'Cart Open Variable' );
		$open->save();
		$this->pos_only_variation = $open->get_children()[0];

		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'pos_only',
				'ids'        => array( $this->pos_only, $this->variable ),
				'scope'      => 'default',
			)
		);
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'variations',
				'visibility' => 'pos_only',
				'ids'        => array( $this->pos_only_variation ),
				'scope'      => 'default',
			)
		);
		$this->shop_manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Clear cart contents and restore the nonce and visibility settings.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		parent::tearDown();
	}

	/**
	 * Dispatch a JSON request without adding a POS header.
	 *
	 * @param string $route REST route.
	 * @param array  $body  Request body.
	 * @return WP_REST_Response
	 */
	private function post( string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Compare the hidden product's status, code and message to a missing product's.
	 *
	 * @param string $route Cart route.
	 * @param int    $id    Hidden product or variation ID.
	 */
	private function assert_matches_missing_product( string $route, int $id ): void {
		$hidden  = $this->post(
			$route,
			array(
				'id'       => $id,
				'quantity' => 1,
			)
		);
		$missing = $this->post(
			$route,
			array(
				'id'       => 999999,
				'quantity' => 1,
			)
		);
		$this->assertSame( 400, $hidden->get_status() );
		$this->assertSame( 400, $missing->get_status() );

		$hidden_body  = $hidden->get_data();
		$missing_body = $missing->get_data();
		$this->assertSame( 'woocommerce_rest_cart_invalid_product', $missing_body['code'] );
		$this->assertSame( $missing_body['code'], $hidden_body['code'] );
		$this->assertSame( str_replace( '999999', (string) $id, $missing_body['message'] ), $hidden_body['message'] );
		$this->assertStringNotContainsString( 'Cart Secret', $hidden_body['message'] );
		$this->assertStringNotContainsString( 'Cart Open', $hidden_body['message'] );
	}

	/**
	 * A guest can still add a visible product.
	 */
	public function test_store_api_add_item_visible_product_is_added(): void {
		$response = $this->post(
			'/wc/store/v1/cart/add-item',
			array(
				'id'       => $this->visible,
				'quantity' => 1,
			)
		);
		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * The add-item route hides a POS Only simple product.
	 */
	public function test_store_api_add_item_pos_only_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/add-item', $this->pos_only );
	}

	/**
	 * The cart items route hides a POS Only simple product.
	 */
	public function test_store_api_cart_items_pos_only_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/items', $this->pos_only );
	}

	/**
	 * The unversioned add-item route hides a POS Only simple product.
	 */
	public function test_store_api_unversioned_add_item_pos_only_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/cart/add-item', $this->pos_only );
	}

	/**
	 * The hidden variable parent is refused before variation attributes are required.
	 */
	public function test_store_api_add_item_pos_only_variable_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/add-item', $this->variable );
	}

	/**
	 * The add-item route also hides a variation of a POS Only parent.
	 */
	public function test_store_api_add_item_variation_of_pos_only_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/add-item', $this->pos_only_child );
	}

	/**
	 * The cart items route also hides a variation of a POS Only parent.
	 */
	public function test_store_api_cart_items_variation_of_pos_only_product_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/items', $this->pos_only_child );
	}

	/**
	 * A listed variation stays hidden even with an open parent.
	 */
	public function test_store_api_add_item_pos_only_variation_matches_missing_product(): void {
		$this->assert_matches_missing_product( '/wc/store/v1/cart/add-item', $this->pos_only_variation );
	}

	/**
	 * A batch sub-request returns the same missing-product error as a direct request.
	 */
	public function test_store_api_batch_add_item_pos_only_product_matches_missing_product(): void {
		$results = array();
		foreach ( array( $this->pos_only, 999999 ) as $id ) {
			$response = $this->post(
				'/wc/store/v1/batch',
				array(
					'requests' => array(
						array(
							'method' => 'POST',
							'path'   => '/wc/store/v1/cart/add-item',
							'body'   => array(
								'id'       => $id,
								'quantity' => 1,
							),
						),
					),
				)
			);
			$results[ $id ] = $response->get_data()['responses'][0];
		}
		$hidden  = $results[ $this->pos_only ];
		$missing = $results[999999];
		$this->assertSame( 400, $hidden['status'] );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( 'woocommerce_rest_cart_invalid_product', $missing['body']['code'] );
		$this->assertSame( $missing['body']['code'], $hidden['body']['code'] );
		$this->assertSame( str_replace( '999999', (string) $this->pos_only, $missing['body']['message'] ), $hidden['body']['message'] );
	}

	/**
	 * Editors still receive WooCommerce's not-purchasable response.
	 */
	public function test_store_api_add_item_pos_only_product_for_editor_keeps_not_purchasable_answer(): void {
		wp_set_current_user( $this->shop_manager );
		$response = $this->post(
			'/wc/store/v1/cart/add-item',
			array(
				'id'       => $this->pos_only,
				'quantity' => 1,
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_product_not_purchasable', $response->get_data()['code'] );
	}

	/**
	 * A POS header does not reveal a hidden product to a guest.
	 */
	public function test_store_api_add_item_pos_only_product_with_pos_header_matches_missing_product(): void {
		$_SERVER['HTTP_X_WCPOS'] = '1';
		try {
			$this->assert_matches_missing_product( '/wc/store/v1/cart/add-item', $this->pos_only );
		} finally {
			unset( $_SERVER['HTTP_X_WCPOS'] );
		}
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
