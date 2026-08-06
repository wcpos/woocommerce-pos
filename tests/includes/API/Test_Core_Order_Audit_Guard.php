<?php
/**
 * Tests for guarding POS audit meta on core REST routes.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Auth;
use WP_REST_Request;

/**
 * Test_Core_Order_Audit_Guard class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Core_Order_Audit_Guard extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Remove the Authorization header after each test.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		parent::tearDown();
	}

	/**
	 * Authenticate the next REST dispatch with a real WCPOS access token.
	 */
	private function authenticate_via_wcpos_jwt(): void {
		$user                          = get_user_by( 'id', $this->user );
		$token                         = Auth::instance()->generate_access_token( $user );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- force authentication.
	}

	/**
	 * JWT-authenticated creates cannot supply POS audit meta.
	 */
	public function test_audit_guard_jwt_order_create_with_audit_key_returns_forbidden(): void {
		// Arrange.
		$before = wc_get_orders(
			array(
				'limit' => -1,
				'return' => 'ids',
			)
		);
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_user',
						'value' => 'forged',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_audit_meta_forbidden', $response->get_data()['code'] );
		$this->assertCount(
			count( $before ),
			wc_get_orders(
				array(
					'limit' => -1,
					'return' => 'ids',
				)
			)
		);
	}

	/**
	 * JWT-authenticated updates cannot supply POS audit meta.
	 */
	public function test_audit_guard_jwt_order_update_with_audit_key_returns_forbidden(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_store', 'original' );
		$order->save();
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_store',
						'value' => 'forged',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'original', wc_get_order( $order->get_id() )->get_meta( '_pos_store' ) );
	}

	/**
	 * JWT-authenticated batch updates cannot supply POS audit meta.
	 */
	public function test_audit_guard_jwt_batch_update_with_audit_key_returns_forbidden(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/batch' );
		$request->set_body_params(
			array(
				'update' => array(
					array(
						'id'        => $order->get_id(),
						'meta_data' => array(
							array(
								'key' => '_pos_cash_amount_tendered',
								'value' => '100',
							),
						),
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_pos_cash_amount_tendered' ) );
	}

	/**
	 * JWT-authenticated updates cannot rename an audit row by meta ID.
	 */
	public function test_audit_guard_jwt_order_update_with_audit_meta_id_returns_forbidden(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->add_meta_data( '_pos_user', 'original' );
		$order->save();
		$meta_id = 0;
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( '_pos_user' === $data['key'] ) {
				$meta_id = (int) $data['id'];
				break;
			}
		}
		$this->assertGreaterThan( 0, $meta_id );
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'id' => $meta_id,
						'key' => 'innocent_key',
						'value' => 'x',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'original', $order->get_meta( '_pos_user' ) );
		$this->assertSame( '', $order->get_meta( 'innocent_key' ) );
	}

	/**
	 * JWT-authenticated core writes with ordinary meta remain allowed.
	 */
	public function test_audit_guard_jwt_order_create_with_ordinary_meta_succeeds(): void {
		// Arrange.
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => 'some_plugin_key',
						'value' => 'allowed',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertLessThan( 300, $response->get_status() );
	}

	/**
	 * WordPress matches REST routes case-insensitively, so the guard must too.
	 * A mixed-case route previously slipped past the order-route regex and let
	 * an id-addressed entry rename the audit row.
	 */
	public function test_audit_guard_jwt_mixed_case_route_with_audit_meta_id_returns_forbidden(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->add_meta_data( '_pos_user', 'original' );
		$order->save();
		$meta_id = 0;
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( '_pos_user' === $data['key'] ) {
				$meta_id = (int) $data['id'];
				break;
			}
		}
		$this->assertGreaterThan( 0, $meta_id );
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'PUT', '/WC/V3/ORDERS/' . $order->get_id() );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'id' => $meta_id,
						'key' => 'innocent_key',
						'value' => 'x',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'original', wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
	}

	/**
	 * The wc-analytics namespace re-registers the full order CRUD, so the
	 * key-name check must apply there too (route matching is namespace-agnostic).
	 */
	public function test_audit_guard_jwt_wc_analytics_order_update_with_audit_key_returns_forbidden(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_store', 'original' );
		$order->save();
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'PUT', '/wc-analytics/orders/' . $order->get_id() );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_store',
						'value' => 'forged',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert: 404 would mean the namespace is not registered in this
		// environment; anything else must be the guard's 403.
		if ( 404 === $response->get_status() ) {
			$this->markTestSkipped( 'wc-analytics REST routes not registered in this environment.' );
		}
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'original', wc_get_order( $order->get_id() )->get_meta( '_pos_store' ) );
	}

	/**
	 * An order batch beyond WooCommerce's limit is not scanned by the guard
	 * (bounded work) — WooCommerce itself rejects it with 413 before writing.
	 */
	public function test_audit_guard_jwt_oversized_batch_returns_entity_too_large(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$this->authenticate_via_wcpos_jwt();
		$items = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$items[] = array(
				'id'        => $order->get_id(),
				'meta_data' => array(
					array(
						'key' => '_pos_user',
						'value' => 'forged',
					),
				),
			);
		}
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/batch' );
		$request->set_body_params( array( 'update' => $items ) );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_pos_user' ) );
	}

	/**
	 * Cookie-authenticated core writes are outside the guard.
	 */
	public function test_audit_guard_cookie_order_create_with_audit_key_succeeds(): void {
		// Arrange.
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( $this->user );
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_user',
						'value' => 'cookie-write',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertLessThan( 300, $response->get_status() );
	}

	/**
	 * A same-user WCPOS token does not override cookie authentication.
	 */
	public function test_audit_guard_cookie_order_create_with_same_user_bearer_token_succeeds(): void {
		// Arrange.
		$user                          = get_user_by( 'id', $this->user );
		$token                         = Auth::instance()->generate_access_token( $user );
		$_COOKIE[ LOGGED_IN_COOKIE ]   = wp_generate_auth_cookie( $this->user, time() + HOUR_IN_SECONDS, 'logged_in' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- force cookie authentication.
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_user',
						'value' => 'cookie-write',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertLessThan( 300, $response->get_status() );
	}

	/**
	 * Protected order audit key names remain valid metadata on non-order routes.
	 */
	public function test_audit_guard_jwt_product_update_with_audit_key_succeeds(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$this->authenticate_via_wcpos_jwt();
		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $product->get_id() );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key' => '_pos_store',
						'value' => 'product-meta',
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'product-meta', wc_get_product( $product->get_id() )->get_meta( '_pos_store' ) );
	}
}
