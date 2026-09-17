<?php
/**
 * Permission decisions and their REST-lane contracts.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\API\V2\Proxy\Coupons_Proxy_Behavior;
use WCPOS\WooCommercePOS\Services\Permission_Rules;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\Services\Permission_Rules
 */
class Test_Permission_Rules extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	private $cot_setup = false;

	/** Allow HPOS toggles with pending fixtures, as in Test_HPOS_Orders_Controller. */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
	}

	public function tearDown(): void {
		$this->uninstall_sync_read_lane();
		if ( $this->cot_setup ) {
			$this->clean_up_cot_setup();
		}
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Removing the staff fence, role re-judge, granular cap or ownership rule changes a row.
	 *
	 * @dataProvider verdict_rows
	 */
	public function test_verdict_actor_target_context_matches_rest( $collection, $context, $role, $caps, $target_role, $lane, $expected, $v2_expected, $v1_dispatch_expected = null ): void {
		// Arrange.
		$this->install_sync_read_lane();
		$this->setup_cot();
		$this->cot_setup = true;
		$this->disable_cot_sync();
		$actor = $this->factory->user->create( array( 'role' => $role ) );
		$user = get_user_by( 'id', $actor );
		foreach ( array_merge( array( 'access_woocommerce_pos', 'list_users', 'read_private_shop_orders' ), $caps ) as $cap ) {
			$user->add_cap( $cap );
		}
		$id = 0;
		if ( 'customers' === $collection && 'create' !== $context ) {
			$id = $this->factory->user->create( array( 'role' => $target_role ) );
		} elseif ( 'orders' === $collection ) {
			$order = wc_create_order();
			$id = $order->get_id();
			wp_update_post( array( 'ID' => $id, 'post_author' => 'self' === $target_role ? $actor : $this->user ) );
		}
		wp_set_current_user( $actor );
		$params = 'customers' === $collection ? array( 'first_name' => 'Permission rule' ) : array( 'customer_note' => 'Permission rule' );
		if ( 'create' === $context ) {
			$params = array( 'email' => wp_generate_uuid4() . '@example.com' );
		}

		// Act: judge without mutating, then dispatch the identical v2 operation.
		$verdict = Permission_Rules::verdict( $collection, $context, $id, $actor, $lane, $params );
		$response = 'read' === $context
			? $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/' . ( 'tax_rates' === $collection ? 'taxes' : $collection ) ) )
			: $this->push( $collection, $context, $id, $params );

		// Assert. V1 delete deliberately differs; both expected answers are explicit data.
		$this->assertSame( $expected, true === $verdict ? 200 : $verdict->get_error_data()['status'] );
		$this->assertSame( $v2_expected, $response->get_status() );
		if ( 'v1' === $lane ) {
			$request = $this->wp_rest_get_request( '/wcpos/v1/' . $collection . '/' . $id );
			$request->set_method( 'delete' === $context ? 'DELETE' : 'PATCH' );
			if ( 'delete' !== $context ) {
				$request->set_body_params( $params );
			}
			// WooCommerce's delete_item() re-checks wc_rest_check_post_permissions() inside the
			// handler, so under HPOS a v1 non-owner delete answers 403 even though the
			// permissions_check fallback grants on the flat cap — before and after this module
			// (probed on the parent commit). The row carries that dispatch answer separately.
			$this->assertSame( $v1_dispatch_expected ?? $expected, $this->server->dispatch( $request )->get_status() );
		}
	}

	public function verdict_rows(): array {
		return array(
			'customer edit' => array( 'customers', 'edit', 'subscriber', array( 'edit_users' ), 'customer', 'v2', 200, 200 ),
			'missing edit cap' => array( 'customers', 'edit', 'subscriber', array(), 'customer', 'v2', 403, 403 ),
			'staff edit' => array( 'customers', 'edit', 'subscriber', array( 'edit_users' ), 'author', 'v2', 403, 403 ),
			'staff delete' => array( 'customers', 'delete', 'subscriber', array( 'delete_users' ), 'administrator', 'v2', 403, 403 ),
			'customer delete' => array( 'customers', 'delete', 'subscriber', array( 'delete_users' ), 'customer', 'v2', 200, 200 ),
			'subscriber rejudge' => array( 'customers', 'edit', 'shop_manager', array(), 'subscriber', 'v2', 200, 200 ),
			'customer create' => array( 'customers', 'create', 'subscriber', array( 'create_customers', 'promote_users' ), '', 'v2', 200, 201 ),
			'customer create denied' => array( 'customers', 'create', 'subscriber', array(), '', 'v2', 403, 403 ),
			'own order edit' => array( 'orders', 'edit', 'subscriber', array( 'edit_shop_orders' ), 'self', 'v2', 200, 200 ),
			'other order edit' => array( 'orders', 'edit', 'subscriber', array( 'edit_shop_orders' ), 'other', 'v1', 403, 403 ),
			'other order edit granted' => array( 'orders', 'edit', 'subscriber', array( 'edit_shop_orders', 'edit_others_shop_orders' ), 'other', 'v1', 200, 200 ),
			'v1 flat delete retained' => array( 'orders', 'delete', 'subscriber', array( 'delete_shop_orders' ), 'other', 'v1', 200, 403, 403 ),
			'v2 other delete denied' => array( 'orders', 'delete', 'subscriber', array( 'delete_shop_orders' ), 'other', 'v2', 403, 403 ),
			'v2 own delete' => array( 'orders', 'delete', 'subscriber', array( 'delete_shop_orders' ), 'self', 'v2', 200, 200 ),
			'coupon read' => array( 'coupons', 'read', 'subscriber', array(), '', 'v2', 200, 200 ),
			'tax read' => array( 'tax_rates', 'read', 'subscriber', array(), '', 'v2', 200, 200 ),
			'refund read' => array( 'refunds', 'read', 'subscriber', array(), '', 'v2', 200, 200 ),
		);
	}

	/** The old v1 flat edit fallback returns 200 for this request. */
	public function test_order_edit_hpos_non_owner_returns_403_on_both_lanes(): void {
		// Arrange.
		$this->setup_cot();
		$this->cot_setup = true;
		$this->disable_cot_sync();
		$this->install_sync_read_lane();
		$order = wc_create_order();
		$actor = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user = get_user_by( 'id', $actor );
		foreach ( array( 'access_woocommerce_pos', 'edit_shop_orders', 'read_private_shop_orders' ) as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $actor );
		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_body_params( array( 'customer_note' => 'Denied' ) );

		// Act.
		$legacy = $this->server->dispatch( $request );
		$current = $this->push( 'orders', 'edit', $order->get_id(), array( 'customer_note' => 'Denied' ), '/wcpos/v2/push/orders' );

		// Assert: the current lane is the route literal above; the legacy lane is the v1 PATCH.
		$this->assertSame( 403, $legacy->get_status() );
		$this->assertSame( 403, $current->get_status() );
	}

	/**
	 * Missing post ownership must retain each lane's pre-refactor edit decision.
	 *
	 * @dataProvider missing_order_post_rows
	 */
	public function test_order_edit_missing_post_preserves_lane_verdict( $lane, $expected ): void {
		// Arrange: HPOS order exists, but its placeholder post does not.
		global $wpdb;
		$this->setup_cot();
		$this->cot_setup = true;
		$this->disable_cot_sync();
		$order = wc_create_order();
		$id    = $order->get_id();
		$wpdb->delete( $wpdb->posts, array( 'ID' => $id ), array( '%d' ) );
		clean_post_cache( $id );
		$actor = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user  = get_user_by( 'id', $actor );
		$user->add_cap( 'access_woocommerce_pos' );
		$user->add_cap( 'edit_shop_orders' );
		wp_set_current_user( $actor );
		$this->assertNull( get_post( $id ) );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $id ) );

		// Act: verdict and the permission callback used by each write lane.
		$verdict = Permission_Rules::verdict( 'orders', 'edit', $id, $actor, $lane );
		$request = $this->wp_rest_patch_request( '/wc/v3/orders/' . $id );
		$request['id'] = $id;
		$controller = 'v1' === $lane ? new \WCPOS\WooCommercePOS\API\V1\Orders_Controller() : new \WC_REST_Orders_Controller();
		Permission_Rules::install_wc_filter( 'orders', $lane );
		try {
			$permission = $controller->update_item_permissions_check( $request );
		} finally {
			Permission_Rules::uninstall_wc_filter();
		}

		// Assert.
		$this->assertSame( $expected, true === $verdict ? 200 : $verdict->get_error_data()['status'] );
		$this->assertSame( $expected, true === $permission ? 200 : $permission->get_error_data()['status'] );
	}

	public function missing_order_post_rows(): array {
		return array(
			'v1 retains flat edit grant' => array( 'v1', 200 ),
			'v2 retains missing-post denial' => array( 'v2', 403 ),
		);
	}

	/** Coupon reads must not keep granting access to a subsequent raw WC request. */
	public function test_coupon_read_request_uninstalls_permission_filter(): void {
		// Arrange.
		$coupon = new \WC_Coupon();
		$coupon->set_code( wp_generate_uuid4() );
		$coupon->save();
		$actor = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $actor )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $actor );

		// Act / Assert: both list and single handlers retain their read grant.
		foreach ( array( '/wcpos/v1/coupons', '/wcpos/v1/coupons/' . $coupon->get_id() ) as $route ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $route ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertNotEmpty( $response->get_data() );
			$this->assertFalse( has_filter( 'woocommerce_rest_check_permissions', array( Permission_Rules::class, 'wc_filter' ) ) );
		}
		$raw = $this->server->dispatch( $this->wp_rest_get_request( '/wc/v3/coupons' ) );
		$this->assertSame( 403, $raw->get_status() );
	}

	/** Unknown collections use the flat POS tier without constructing a WC class. */
	public function test_verdict_unknown_collection_uses_pos_capability(): void {
		// Arrange.
		$actor = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $actor );

		// Act / Assert.
		$this->assertWPError( Permission_Rules::verdict( 'unknown', 'read' ) );
		get_user_by( 'id', $actor )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( 0 );
		wp_set_current_user( $actor );
		$this->assertTrue( Permission_Rules::verdict( 'unknown', 'read' ) );
		$this->assertFalse( has_filter( 'woocommerce_rest_check_permissions', array( Permission_Rules::class, 'wc_filter' ) ) );
	}

	/** An unscoped callback must not replace the incoming permission. */
	public function test_filter_empty_scope_preserves_incoming_permission(): void {
		// Arrange.
		Permission_Rules::uninstall_wc_filter();

		// Act / Assert.
		$this->assertTrue( Permission_Rules::wc_filter( true, 'read', 0, 'shop_coupon' ) );
		$this->assertFalse( Permission_Rules::wc_filter( false, 'read', 0, 'shop_coupon' ) );
	}

	/** @dataProvider read_overrides */
	public function test_read_override_parent_with_scoped_filter_matches_legacy( $collection, $controller, $method, $id ): void {
		// Arrange. These six overrides cannot be deleted until installation precedes permissions.
		$user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user );
		$request = $this->wp_rest_get_request( '/wc/v3/' . $collection );
		$request['id'] = $id;
		$legacy_class = 'WCPOS\\WooCommercePOS\\API\\V1\\' . $controller;
		$parent_class = get_parent_class( $legacy_class );

		// Act.
		$legacy = ( new $legacy_class() )->$method( $request );
		Permission_Rules::install_wc_filter( $collection, 'v1' );
		try {
			$parent = ( new $parent_class() )->$method( $request );
		} finally {
			Permission_Rules::uninstall_wc_filter();
		}

		// Assert.
		$this->assertTrue( $legacy );
		$this->assertSame( $legacy, $parent );
	}

	public function read_overrides(): array {
		return array(
			array( 'coupons', 'Coupons_Controller', 'get_items_permissions_check', 0 ),
			array( 'coupons', 'Coupons_Controller', 'get_item_permissions_check', 999999 ),
			array( 'tax_rates', 'Taxes_Controller', 'get_items_permissions_check', 0 ),
			array( 'tax_rates', 'Taxes_Controller', 'get_item_permissions_check', 999999 ),
			array( 'tax_classes', 'Tax_Classes_Controller', 'get_items_permissions_check', 0 ),
			array( 'shipping_methods', 'Shipping_Methods_Controller', 'get_items_permissions_check', 0 ),
		);
	}

	/** The shared hook must not outlive a failed forward or replace an outer scope. */
	public function test_filter_nested_exception_restores_outer_scope_and_uninstalls(): void {
		// Arrange.
		$user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user );
		Permission_Rules::install_wc_filter( 'tax_rates' );

		// Act.
		try {
			try {
				( new Coupons_Proxy_Behavior() )->around( function () {
					$this->assertTrue( wc_rest_check_post_permissions( 'shop_coupon', 'read' ) );
					$this->assertFalse( wc_rest_check_manager_permissions( 'settings', 'read' ) );
					throw new \RuntimeException( 'Stopped forward' );
				} );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Stopped forward', $error->getMessage() );
			}
			// Assert: restored tax scope, not a coupon or write grant.
			$this->assertTrue( wc_rest_check_manager_permissions( 'settings', 'read' ) );
			$this->assertFalse( wc_rest_check_post_permissions( 'shop_coupon', 'read' ) );
			$this->assertFalse( wc_rest_check_post_permissions( 'shop_coupon', 'create' ) );
		} finally {
			Permission_Rules::uninstall_wc_filter();
		}
		$this->assertFalse( has_filter( 'woocommerce_rest_check_permissions', array( Permission_Rules::class, 'wc_filter' ) ) );
	}

	/** Customer denials retain the staff error, and actor/role scopes do not escape. */
	public function test_verdict_customer_staff_denial_preserves_error_and_actor(): void {
		// Arrange.
		$actor = $this->factory->user->create( array( 'role' => 'shop_manager' ) );
		$target = $this->factory->user->create( array( 'role' => 'author' ) );
		$previous = get_current_user_id();
		$roles = apply_filters( 'woocommerce_shop_manager_editable_roles', array( 'customer' ) );

		// Act.
		$result = Permission_Rules::verdict( 'customers', 'edit', $target, $actor, 'v1' );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_pos_rest_cannot_edit_staff_account', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		$this->assertSame( $previous, get_current_user_id() );
		$this->assertSame( $roles, apply_filters( 'woocommerce_shop_manager_editable_roles', array( 'customer' ) ) );
	}

	/** Plain-data request reconstruction must retain WooCommerce's credential fence. */
	public function test_verdict_admin_staff_email_change_matches_wc_denial(): void {
		// Arrange.
		$target = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$params = array( 'id' => $target, 'email' => wp_generate_uuid4() . '@example.com' );
		$request = $this->wp_rest_patch_request( '/wc/v3/customers/' . $target );
		$request->set_body_params( $params );

		// Act.
		$original = ( new \WC_REST_Customers_Controller() )->update_item_permissions_check( $request );
		$result = Permission_Rules::verdict( 'customers', 'edit', $target, 0, 'v1', $params );

		// Assert.
		$this->assertWPError( $original );
		$this->assertWPError( $result );
		$this->assertSame( $original->get_error_code(), $result->get_error_code() );
		$this->assertSame( $original->get_error_message(), $result->get_error_message() );
		$this->assertSame( $original->get_error_data(), $result->get_error_data() );
	}

	private function push( string $collection, string $context, int $id, array $payload, string $route = '' ) {
		$revision = null;
		$uuid = wp_generate_uuid4();
		if ( $id ) {
			$object = 'orders' === $collection ? wc_get_order( $id ) : new \WC_Customer( $id );
			$uuid = Pos_Uuid::ensure_uuid( $object );
			$request = $this->wp_rest_get_request( '/wcpos/v2/' . $collection );
			$request->set_param( 'include', array( $id ) );
			$read = $this->server->dispatch( $request );
			$this->assertSame( 200, $read->get_status() );
			$rows = $read->get_data();
			$this->assertCount( 1, $rows );
			$revision = $rows[0]['_rxdb_revision'];
		}
		$mutation = wp_generate_uuid4();
		$request = $this->wp_rest_post_request( '' !== $route ? $route : '/wcpos/v2/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $mutation );
		if ( null !== $revision ) {
			$request->set_header( 'If-Match', '"' . $revision . '"' );
		}
		$envelope = array(
			'mutationId'   => $mutation,
			'operation'    => 'edit' === $context ? 'update' : $context,
			'collection'   => $collection,
			'recordId'     => $uuid,
			'baseRevision' => $revision,
		);
		if ( 'delete' !== $context ) {
			$envelope['payload'] = $payload;
		}
		$request->set_body( wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}
}
