<?php
/**
 * Tests for POS endpoint permissions through WCPOS Bearer token auth.
 *
 * These tests exercise the endpoints touched during normal POS operation with
 * default administrator, shop_manager, and cashier roles. They intentionally
 * dispatch requests through JWT access tokens instead of only calling
 * wp_set_current_user() so permission failures can be distinguished from
 * token-auth failures.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * Test_POS_Endpoint_Permissions_Matrix class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_POS_Endpoint_Permissions_Matrix extends WCPOS_REST_Unit_Test_Case {
	/**
	 * @var AuthService
	 */
	private $auth_service;

	/**
	 * @var array<string, WP_User>
	 */
	private $role_users = array();

	/**
	 * Original cashier role capabilities, saved before setUp mutations.
	 *
	 * @var array<string, bool>
	 */
	private $original_cashier_caps_snapshot = array();

	/**
	 * Capabilities expected for the default POS cashier role.
	 *
	 * @var string[]
	 */
	private $cashier_caps = array(
		'read',
		'read_private_products',
		'read_private_shop_orders',
		'publish_shop_orders',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'list_users',
		'create_customers',
		'edit_users',
		'read_private_shop_coupons',
		'manage_product_terms',
		'access_woocommerce_pos',
	);

	public function setUp(): void {
		parent::setUp();

		$this->auth_service = AuthService::instance();
		$this->ensure_cashier_role_caps();

		$admin_user = get_user_by( 'id', $this->user );
		$admin_user->add_cap( 'manage_woocommerce_pos' );
		$admin_user->add_cap( 'access_woocommerce_pos' );

		$shop_manager = $this->factory->user->create_and_get( array( 'role' => 'shop_manager' ) );
		$shop_manager->add_cap( 'manage_woocommerce_pos' );
		$shop_manager->add_cap( 'access_woocommerce_pos' );

		$cashier = $this->factory->user->create_and_get( array( 'role' => 'cashier' ) );

		$this->role_users = array(
			'administrator' => $admin_user,
			'shop_manager'  => $shop_manager,
			'cashier'       => $cashier,
		);
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$this->restore_cashier_role_caps();

		foreach ( array( 'shop_manager', 'cashier' ) as $role ) {
			if ( isset( $this->role_users[ $role ] ) ) {
				wp_delete_user( $this->role_users[ $role ]->ID );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test default roles against POS endpoints using valid WCPOS access tokens.
	 */
	public function test_default_roles_can_access_expected_pos_endpoints_with_valid_access_tokens(): void {
		foreach ( $this->get_pos_endpoint_matrix() as $endpoint ) {
			foreach ( $this->role_users as $role => $user ) {
				$expected_allowed = $endpoint['roles'][ $role ];
				$response         = $this->dispatch_endpoint_as_user( $endpoint, $user );
				$status           = $response->get_status();
				$data             = $response->get_data();
				$code             = \is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';

				if ( $expected_allowed ) {
					$this->assertNotContains(
						$status,
						array( 401, 403 ),
						\sprintf(
							'%s should access %s %s with a valid access token. Got %d %s.',
							$role,
							$endpoint['method'],
							$this->resolve_endpoint_path( $endpoint, $user ),
							$status,
							$code
						)
					);
				} else {
					$this->assertEquals(
						403,
						$status,
						\sprintf(
							'%s should be denied for %s %s. Got %d %s.',
							$role,
							$endpoint['method'],
							$this->resolve_endpoint_path( $endpoint, $user ),
							$status,
							$code
						)
					);
				}
			}
		}
	}

	/**
	 * Test expired access tokens fail as auth failures, then refresh restores access.
	 */
	public function test_expired_access_token_denies_then_refresh_restores_endpoint_access_for_default_roles(): void {
		foreach ( $this->role_users as $role => $user ) {
			$tokens = $this->auth_service->generate_token_pair( $user );
			$this->assertIsArray( $tokens );

			$expire_access_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10, 2 );
			$expired_access_token = $this->auth_service->generate_access_token( $user );
			remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire_access_token, 10 );

			$this->assertIsString( $expired_access_token );

			$expired_response = $this->dispatch_path_as_access_token( '/wcpos/v1/products', $expired_access_token );
			$expired_data     = $expired_response->get_data();

			$this->assertEquals( 403, $expired_response->get_status(), $role . ' expired access token should be denied.' );
			$this->assertEquals(
				'woocommerce_pos_rest_forbidden',
				\is_array( $expired_data ) && isset( $expired_data['code'] ) ? $expired_data['code'] : '',
				$role . ' expired access token should fail at the POS auth gate, not as an endpoint-specific permission issue.'
			);
			$this->assertEquals( 0, get_current_user_id(), $role . ' expired access token must not authenticate a user.' );

			$refreshed = $this->auth_service->refresh_access_token( $tokens['refresh_token'] );
			$this->assertIsArray( $refreshed, $role . ' valid refresh token should produce a new access token.' );

			$refreshed_response = $this->dispatch_path_as_access_token( '/wcpos/v1/products', $refreshed['access_token'] );
			$this->assertNotContains(
				$refreshed_response->get_status(),
				array( 401, 403 ),
				$role . ' refreshed access token should restore access to products.'
			);
		}
	}

	/**
	 * Test expired refresh tokens cannot mint new access tokens for default roles.
	 */
	public function test_expired_refresh_token_cannot_restore_endpoint_access_for_default_roles(): void {
		foreach ( $this->role_users as $role => $user ) {
			$expire_refresh_token = function ( $expire, $issued_at ) {
				return $issued_at - 1;
			};

			add_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10, 2 );
			$expired_refresh_token = $this->auth_service->generate_refresh_token( $user );
			remove_filter( 'woocommerce_pos_jwt_refresh_token_expire', $expire_refresh_token, 10 );

			$this->assertIsString( $expired_refresh_token );

			$refresh_result = $this->auth_service->refresh_access_token( $expired_refresh_token );
			$this->assertInstanceOf( WP_Error::class, $refresh_result, $role . ' expired refresh token should not mint access tokens.' );
			$this->assertEquals( 'woocommmerce_pos_auth_invalid_token', $refresh_result->get_error_code() );
		}
	}

	/**
	 * Ensure the cashier role has the capabilities needed for normal POS operation.
	 */
	private function ensure_cashier_role_caps(): void {
		$role = get_role( 'cashier' );
		if ( ! $role ) {
			return;
		}

		$this->original_cashier_caps_snapshot = $role->capabilities;
		foreach ( $this->cashier_caps as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Restore cashier role caps after mutation in setUp().
	 */
	private function restore_cashier_role_caps(): void {
		$role = get_role( 'cashier' );
		if ( ! $role ) {
			return;
		}

		foreach ( $role->capabilities as $cap => $granted ) {
			if ( ! array_key_exists( $cap, $this->original_cashier_caps_snapshot ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Build endpoints touched during normal POS operation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_pos_endpoint_matrix(): array {
		ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$order    = OrderHelper::create_order();
		$customer = CustomerHelper::create_customer();

		$all_roles        = array( 'administrator' => true, 'shop_manager' => true, 'cashier' => true );
		$management_roles = array( 'administrator' => true, 'shop_manager' => true, 'cashier' => false );

		return array(
			array( 'id' => 'settings index', 'method' => 'GET', 'path' => '/wcpos/v1/settings', 'roles' => $all_roles ),
			array( 'id' => 'stores index', 'method' => 'GET', 'path' => '/wcpos/v1/stores', 'roles' => $all_roles ),
			array( 'id' => 'cashier profile', 'method' => 'GET', 'path' => function ( WP_User $user ) { return '/wcpos/v1/cashier/' . $user->ID; }, 'roles' => $all_roles ),
			array( 'id' => 'cashier stores', 'method' => 'GET', 'path' => function ( WP_User $user ) { return '/wcpos/v1/cashier/' . $user->ID . '/stores'; }, 'roles' => $all_roles ),

			array( 'id' => 'products index', 'method' => 'GET', 'path' => '/wcpos/v1/products', 'roles' => $all_roles ),
			array( 'id' => 'product variations index', 'method' => 'GET', 'path' => '/wcpos/v1/products/variations', 'roles' => $all_roles ),
			array( 'id' => 'product categories index', 'method' => 'GET', 'path' => '/wcpos/v1/products/categories', 'roles' => $all_roles ),
			array( 'id' => 'product tags index', 'method' => 'GET', 'path' => '/wcpos/v1/products/tags', 'roles' => $all_roles ),
			array( 'id' => 'product brands index', 'method' => 'GET', 'path' => '/wcpos/v1/products/brands', 'roles' => $all_roles ),
			array( 'id' => 'coupons index', 'method' => 'GET', 'path' => '/wcpos/v1/coupons', 'roles' => $all_roles ),

			array( 'id' => 'orders index', 'method' => 'GET', 'path' => '/wcpos/v1/orders', 'roles' => $all_roles ),
			array( 'id' => 'orders create', 'method' => 'POST', 'path' => '/wcpos/v1/orders', 'body' => array( 'status' => 'pending' ), 'roles' => $all_roles ),
			array( 'id' => 'orders update', 'method' => 'PATCH', 'path' => '/wcpos/v1/orders/' . $order->get_id(), 'body' => array( 'status' => 'completed' ), 'roles' => $all_roles ),
			array( 'id' => 'order checkout read', 'method' => 'GET', 'path' => '/wcpos/v1/orders/' . $order->get_id() . '/checkout', 'roles' => $all_roles ),
			array( 'id' => 'order checkout create', 'method' => 'POST', 'path' => '/wcpos/v1/orders/' . $order->get_id() . '/checkout', 'body' => array( 'gateway_id' => 'pos_cash' ), 'roles' => $all_roles ),
			array( 'id' => 'receipt live read', 'method' => 'GET', 'path' => '/wcpos/v1/receipts/' . $order->get_id(), 'query' => array( 'mode' => 'live' ), 'roles' => $all_roles ),
			array( 'id' => 'order statuses', 'method' => 'GET', 'path' => '/wcpos/v1/data/order_statuses', 'roles' => $all_roles ),

			array( 'id' => 'customers index', 'method' => 'GET', 'path' => '/wcpos/v1/customers', 'roles' => $all_roles ),
			array( 'id' => 'customers create', 'method' => 'POST', 'path' => '/wcpos/v1/customers', 'body' => function ( WP_User $user ) { return array( 'email' => 'matrix-' . $user->ID . '-' . wp_generate_uuid4() . '@example.com', 'first_name' => 'Matrix', 'last_name' => 'Customer' ); }, 'roles' => $all_roles ),
			array( 'id' => 'customers update', 'method' => 'PATCH', 'path' => '/wcpos/v1/customers/' . $customer->get_id(), 'body' => array( 'first_name' => 'Updated' ), 'roles' => $all_roles ),

			array( 'id' => 'taxes index', 'method' => 'GET', 'path' => '/wcpos/v1/taxes', 'roles' => $all_roles ),
			array( 'id' => 'tax classes index', 'method' => 'GET', 'path' => '/wcpos/v1/taxes/classes', 'roles' => $all_roles ),
			array( 'id' => 'shipping methods index', 'method' => 'GET', 'path' => '/wcpos/v1/shipping_methods', 'roles' => $all_roles ),
			array( 'id' => 'payment gateways index', 'method' => 'GET', 'path' => '/wcpos/v1/payment-gateways', 'roles' => $all_roles ),
			array( 'id' => 'payment gateway bootstrap', 'method' => 'POST', 'path' => '/wcpos/v1/payment-gateways/pos_cash/bootstrap', 'body' => array( 'context' => array() ), 'roles' => $all_roles ),

			array( 'id' => 'templates index', 'method' => 'GET', 'path' => '/wcpos/v1/templates', 'roles' => $all_roles ),
			array( 'id' => 'active template', 'method' => 'GET', 'path' => '/wcpos/v1/templates/active', 'roles' => $all_roles ),
			array( 'id' => 'template gallery', 'method' => 'GET', 'path' => '/wcpos/v1/templates/gallery', 'roles' => $all_roles ),

			array( 'id' => 'settings general', 'method' => 'GET', 'path' => '/wcpos/v1/settings/general', 'roles' => $management_roles ),
			array( 'id' => 'settings checkout', 'method' => 'GET', 'path' => '/wcpos/v1/settings/checkout', 'roles' => $management_roles ),
			array( 'id' => 'settings tax ids', 'method' => 'GET', 'path' => '/wcpos/v1/settings/tax_ids', 'roles' => $management_roles ),
			array( 'id' => 'settings payment gateways', 'method' => 'GET', 'path' => '/wcpos/v1/settings/payment-gateways', 'roles' => $management_roles ),
			array( 'id' => 'extensions index', 'method' => 'GET', 'path' => '/wcpos/v1/extensions', 'roles' => $management_roles ),
			array( 'id' => 'logs index', 'method' => 'GET', 'path' => '/wcpos/v1/logs', 'roles' => $management_roles ),
		);
	}

	/**
	 * Dispatch a matrix endpoint as a specific user via a WCPOS access token.
	 *
	 * @param array<string, mixed> $endpoint Endpoint spec.
	 */
	private function dispatch_endpoint_as_user( array $endpoint, WP_User $user ) {
		$token = $this->auth_service->generate_access_token( $user );
		$this->assertIsString( $token );

		$path = $this->resolve_endpoint_path( $endpoint, $user );
		$body = $this->resolve_endpoint_body( $endpoint, $user );

		return $this->dispatch_path_as_access_token( $path, $token, $endpoint['method'], $body, $endpoint['query'] ?? array() );
	}

	/**
	 * Dispatch a path using a raw access token.
	 *
	 * @param array<string, mixed> $body Body params.
	 * @param array<string, mixed> $query Query params.
	 */
	private function dispatch_path_as_access_token( string $path, string $access_token, string $method = 'GET', array $body = array(), array $query = array() ) {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$request = $this->create_request( $method, $path );
		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}

		$response = $this->server->dispatch( $request );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		return $response;
	}

	/**
	 * Create a WCPOS REST request for the given method and route.
	 */
	private function create_request( string $method, string $path ): WP_REST_Request {
		if ( 'PATCH' === $method ) {
			$request = $this->wp_rest_patch_request( $path );
		} else {
			$request = new WP_REST_Request( $method, $path );
			$request->set_header( 'X-WCPOS', '1' );
		}

		return $request;
	}

	/**
	 * Resolve endpoint path callbacks.
	 *
	 * @param array<string, mixed> $endpoint Endpoint spec.
	 */
	private function resolve_endpoint_path( array $endpoint, WP_User $user ): string {
		return \is_callable( $endpoint['path'] ) ? (string) $endpoint['path']( $user ) : (string) $endpoint['path'];
	}

	/**
	 * Resolve endpoint body callbacks.
	 *
	 * @param array<string, mixed> $endpoint Endpoint spec.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_endpoint_body( array $endpoint, WP_User $user ): array {
		if ( ! isset( $endpoint['body'] ) ) {
			return array();
		}

		return \is_callable( $endpoint['body'] ) ? (array) $endpoint['body']( $user ) : (array) $endpoint['body'];
	}
}
