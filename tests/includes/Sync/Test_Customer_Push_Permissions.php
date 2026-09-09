<?php
/**
 * Permission tests for customer mutations through legacy and v2 sync routes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * Exercise customer permission boundaries through real REST dispatch.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 * @covers \WCPOS\WooCommercePOS\API\V1\Customers_Controller
 * @covers \WCPOS\WooCommercePOS\Services\Customer_Account_Guard
 */
class Test_Customer_Push_Permissions extends Sync_REST_Store_Test_Case {
	/**
	 * Capabilities expected for the normal cashier customer surface.
	 *
	 * @var string[]
	 */
	private $cashier_caps = array(
		'read',
		'list_users',
		'create_customers',
		'edit_users',
		'access_woocommerce_pos',
	);

	/**
	 * Shop manager ID.
	 *
	 * @var int
	 */
	private $shop_manager;
	/**
	 * Cashier ID.
	 *
	 * @var int
	 */
	private $cashier;
	/**
	 * Subscriber ID.
	 *
	 * @var int
	 */
	private $subscriber;


	/** Set up actors without mutating shared roles. */
	public function setUp(): void {
		parent::setUp();
		$this->shop_manager = $this->factory->user->create( array( 'role' => 'shop_manager' ) );
		get_user_by( 'id', $this->shop_manager )->add_cap( 'access_woocommerce_pos' );
		$this->cashier    = $this->create_cashier_without( array() );
		$this->subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}



	/**
	 * A customer create must not inherit the scoped catalog mutation grant.
	 */
	public function test_cashier_without_create_customers_cannot_push_customer_create(): void {
		$cashier_id = $this->create_cashier_without( array( 'create_customers' ) );
		$email      = 'v2-no-create-' . wp_generate_uuid4() . '@example.com';
		wp_set_current_user( $cashier_id );

		$response = $this->server->dispatch(
			$this->customer_push_request(
				'create',
				wp_generate_uuid4(),
				null,
				array(
					'email'      => $email,
					'first_name' => 'Blocked',
				)
			)
		);

		$this->assertEquals( 403, $response->get_status() );
		$this->assertFalse( email_exists( $email ), 'A denied customer create must not persist a user.' );
	}

	/**
	 * A customer update must still require the cashier's granular edit cap.
	 */
	public function test_cashier_without_edit_users_cannot_push_customer_update(): void {
		$customer           = CustomerHelper::create_customer();
		$original_first_name = $customer->get_first_name();
		$record_id          = Pos_Uuid::ensure_uuid( $customer );
		$cashier_id         = $this->create_cashier_without( array( 'edit_users' ) );
		wp_set_current_user( $cashier_id );
		// Read as the pusher: the client must send back the revision served under
		// the pusher's permissions.
		$revision = $this->customer_revision( $customer->get_id() );

		$response = $this->server->dispatch(
			$this->customer_push_request(
				'update',
				$record_id,
				$revision,
				array( 'first_name' => 'Blocked' )
			)
		);

		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( $original_first_name, ( new \WC_Customer( $customer->get_id() ) )->get_first_name() );
	}

	/**
	 * Cashiers cannot delete customers through a v2 delete envelope.
	 */
	public function test_cashier_cannot_push_customer_delete(): void {
		$customer   = CustomerHelper::create_customer();
		$record_id  = Pos_Uuid::ensure_uuid( $customer );
		$cashier_id = $this->create_cashier_without( array() );
		wp_set_current_user( $cashier_id );
		$revision   = $this->customer_revision( $customer->get_id() );

		$response = $this->server->dispatch(
			$this->customer_push_request( 'delete', $record_id, $revision )
		);

		$this->assertEquals( 403, $response->get_status() );
		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'id', $customer->get_id() ),
			'A denied customer delete must leave the user intact.'
		);
	}

	/**
	 * A cashier cannot push a staff first_name change.
	 */
	public function test_cashier_cannot_push_update_to_administrator(): void {
		$target_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$original   = get_user_by( 'id', $target_id )->first_name;
		$record_id  = Pos_Uuid::ensure_uuid( new \WC_Customer( $target_id ) );
		$cashier_id = $this->create_cashier_without( array() );
		wp_set_current_user( $cashier_id );
		$revision = $this->customer_revision( $target_id );

		$response = $this->server->dispatch(
			$this->customer_push_request( 'update', $record_id, $revision, array( 'first_name' => 'Blocked' ) )
		);

		$this->assertSame( 403, $response->get_status() );
		clean_user_cache( $target_id );
		$this->assertSame( $original, get_user_by( 'id', $target_id )->first_name );
		wp_delete_user( $target_id );
		wp_delete_user( $cashier_id );
	}

	/**
	 * A cashier cannot push a staff email change.
	 */
	public function test_cashier_cannot_push_email_change_to_administrator(): void {
		$target_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$original   = get_user_by( 'id', $target_id )->user_email;
		$record_id  = Pos_Uuid::ensure_uuid( new \WC_Customer( $target_id ) );
		$cashier_id = $this->create_cashier_without( array() );
		wp_set_current_user( $cashier_id );
		$revision = $this->customer_revision( $target_id );

		$response = $this->server->dispatch(
			$this->customer_push_request( 'update', $record_id, $revision, array( 'email' => 'blocked-' . wp_generate_uuid4() . '@example.com' ) )
		);

		$this->assertSame( 403, $response->get_status() );
		clean_user_cache( $target_id );
		$this->assertSame( $original, get_user_by( 'id', $target_id )->user_email );
		wp_delete_user( $target_id );
		wp_delete_user( $cashier_id );
	}

	/**
	 * Each dataset is a separate test so REST hooks cannot leak between lanes.
	 *
	 * @return array
	 */
	public function customer_lanes(): array {
		return array(
			'legacy' => array( 'v1' ),
			'current' => array( 'v2' ),
		);
	}

	/**
	 * A cashier must not take over an administrator's credentials.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_with_edit_users_cannot_change_administrator_email_or_password_on_both_lanes( string $lane ): void {
		$target_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$original  = get_user_by( 'id', $target_id );
		wp_set_current_user( $this->cashier );

		$response = $this->mutate_customer(
			$lane,
			'update',
			$target_id,
			array(
				'email'    => 'blocked-' . wp_generate_uuid4() . '@example.com',
				'password' => 'Blocked-password-123!',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_edit', $response->get_data()['code'] );
		clean_user_cache( $target_id );
		$actual = get_user_by( 'id', $target_id );
		$this->assertSame( $original->user_email, $actual->user_email );
		$this->assertSame( $original->user_pass, $actual->user_pass );
		wp_delete_user( $target_id );
	}

	/**
	 * Staff profile fields are protected, not only credentials.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_with_edit_users_cannot_edit_shop_manager_profile_on_both_lanes( string $lane ): void {
		$original = get_user_by( 'id', $this->shop_manager )->first_name;
		wp_set_current_user( $this->cashier );

		$response = $this->mutate_customer( $lane, 'update', $this->shop_manager, array( 'first_name' => 'Blocked' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_edit', $response->get_data()['code'] );
		clean_user_cache( $this->shop_manager );
		$this->assertSame( $original, get_user_by( 'id', $this->shop_manager )->first_name );
	}

	/**
	 * Another cashier is staff because the role holds edit_users.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_cannot_edit_another_cashier_on_both_lanes( string $lane ): void {
		$target_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $this->cashier );

		$response = $this->mutate_customer( $lane, 'update', $target_id, array( 'first_name' => 'Blocked' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_edit', $response->get_data()['code'] );
		wp_delete_user( $target_id );
	}

	/**
	 * Administrators retain WooCommerce's staff profile editing permission.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_administrator_can_update_shop_manager_on_both_lanes( string $lane ): void {
		wp_set_current_user( $this->user );

		$response = $this->mutate_customer( $lane, 'update', $this->shop_manager, array( 'first_name' => 'Updated' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Granting delete_users must not permit deleting an administrator.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_with_delete_users_cannot_delete_administrator_on_both_lanes( string $lane ): void {
		$target_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$cashier_id = $this->create_cashier_without( array() );
		get_user_by( 'id', $cashier_id )->add_cap( 'delete_users' );
		wp_set_current_user( $cashier_id );

		$response = $this->mutate_customer( $lane, 'delete', $target_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_delete', $response->get_data()['code'] );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $target_id ) );
		wp_delete_user( $target_id );
		wp_delete_user( $cashier_id );
	}

	/**
	 * Sites can narrow protection without bypassing WooCommerce's checks.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_protected_capabilities_filter_narrows_the_guard_on_both_lanes( string $lane ): void {
		$filter = static function () {
			return array( 'manage_options' );
		};
		add_filter( 'woocommerce_pos_protected_account_capabilities', $filter );
		wp_set_current_user( $this->cashier );

		try {
			$response = $this->mutate_customer( $lane, 'update', $this->shop_manager, array( 'first_name' => 'Updated' ) );

			$this->assertSame( 200, $response->get_status() );
		} finally {
			remove_filter( 'woocommerce_pos_protected_account_capabilities', $filter );
		}
	}


	/**
	 * WooCommerce reads only the first role, so a capability test must gate deletes.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_with_delete_users_cannot_delete_a_customer_role_account_holding_staff_capabilities_on_both_lanes( string $lane ): void {
		$target_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $target_id )->add_cap( 'manage_woocommerce' );
		$cashier_id = $this->create_cashier_without( array() );
		get_user_by( 'id', $cashier_id )->add_cap( 'delete_users' );
		wp_set_current_user( $cashier_id );

		$response = $this->mutate_customer( $lane, 'delete', $target_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_delete', $response->get_data()['code'] );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $target_id ) );
		wp_delete_user( $target_id );
		wp_delete_user( $cashier_id );
	}

	/**
	 * An administrator who also holds the customer role must not be deletable.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_with_delete_users_cannot_delete_a_multi_role_administrator_on_both_lanes( string $lane ): void {
		$target_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $target_id )->add_role( 'administrator' );
		$cashier_id = $this->create_cashier_without( array() );
		get_user_by( 'id', $cashier_id )->add_cap( 'delete_users' );
		wp_set_current_user( $cashier_id );

		$response = $this->mutate_customer( $lane, 'delete', $target_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_delete', $response->get_data()['code'] );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $target_id ) );
		wp_delete_user( $target_id );
		wp_delete_user( $cashier_id );
	}

	/**
	 * A cleared target is judged by capability on both lanes, not by WooCommerce's role name.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_shop_manager_can_update_a_subscriber_on_both_lanes( string $lane ): void {
		wp_set_current_user( $this->shop_manager );

		$response = $this->mutate_customer( $lane, 'update', $this->subscriber, array( 'first_name' => 'Updated' ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		clean_user_cache( $this->subscriber );
		$this->assertSame( 'Updated', get_user_by( 'id', $this->subscriber )->first_name );
	}

	/**
	 * An author who also holds the customer role passes WooCommerce's first-role
	 * credential fence, so the guard must stop the takeover by capability.
	 *
	 * @dataProvider customer_lanes
	 * @param string $lane REST lane.
	 */
	public function test_cashier_cannot_take_over_a_customer_first_author_account_on_both_lanes( string $lane ): void {
		$target_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $target_id )->add_role( 'author' );
		$original = get_user_by( 'id', $target_id );
		wp_set_current_user( $this->cashier );

		$response = $this->mutate_customer(
			$lane,
			'update',
			$target_id,
			array(
				'email'    => 'blocked-' . wp_generate_uuid4() . '@example.com',
				'password' => 'Blocked-password-123!',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_edit', $response->get_data()['code'] );
		clean_user_cache( $target_id );
		$actual = get_user_by( 'id', $target_id );
		$this->assertSame( $original->user_email, $actual->user_email );
		$this->assertSame( $original->user_pass, $actual->user_pass );
		wp_delete_user( $target_id );
	}

	/**
	 * Deleting content staff must not become possible by adding a customer role.
	 *
	 * @dataProvider content_staff_deletes
	 * @param string $lane           REST lane.
	 * @param string $role           Content staff role.
	 * @param bool   $customer_first Whether customer is the first role.
	 */
	public function test_cashier_delete_content_staff_is_denied( string $lane, string $role, bool $customer_first ): void {
		$target_id = $this->factory->user->create( array( 'role' => $customer_first ? 'customer' : $role ) );
		if ( $customer_first ) {
			get_user_by( 'id', $target_id )->add_role( $role );
		}
		get_user_by( 'id', $this->cashier )->add_cap( 'delete_users' );
		wp_set_current_user( $this->cashier );

		$response = $this->mutate_customer( $lane, 'delete', $target_id );

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'v1' === $lane ? 'woocommerce_pos_rest_cannot_edit_staff_account' : 'woocommerce_rest_cannot_delete', $response->get_data()['code'] );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $target_id ) );
		wp_delete_user( $target_id );
	}

	/**
	 * Cover native roles and WooCommerce's customer-first role bypass on each lane.
	 *
	 * @return array
	 */
	public function content_staff_deletes(): array {
		$cases = array();
		foreach ( array( 'v1', 'v2' ) as $lane ) {
			foreach ( array( 'editor', 'author' ) as $role ) {
				$cases[ "$lane-$role" ]          = array( $lane, $role, false );
				$cases[ "$lane-customer-$role" ] = array( $lane, $role, true );
			}
		}
		return $cases;
	}

	/**
	 * Dispatch a real mutation using the selected client protocol.
	 *
	 * @param string $lane      REST lane.
	 * @param string $operation Mutation operation.
	 * @param int    $target_id User ID.
	 * @param array  $payload   Updated fields.
	 * @return \WP_REST_Response
	 */
	private function mutate_customer( string $lane, string $operation, int $target_id, array $payload = array() ): \WP_REST_Response {
		if ( 'v1' === $lane ) {
			$request = $this->wp_rest_get_request( '/wcpos/v1/customers/' . $target_id );
			$request->set_method( 'delete' === $operation ? 'DELETE' : 'PATCH' );
			$request->set_body_params(
				'delete' === $operation ? array(
					'force' => true,
					'reassign' => 0,
				) : $payload
			);
		} else {
			$record_id = Pos_Uuid::ensure_uuid( new \WC_Customer( $target_id ) );
			$revision  = $this->customer_revision( $target_id );
			$request   = $this->customer_push_request( $operation, $record_id, $revision, 'delete' === $operation ? null : $payload );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Create a cashier-like user without selected granular capabilities.
	 *
	 * @param array $excluded Excluded capabilities.
	 */
	private function create_cashier_without( array $excluded ): int {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		foreach ( $this->cashier_caps as $cap ) {
			if ( ! \in_array( $cap, $excluded, true ) ) {
				$user->add_cap( $cap );
			}
		}

		return $user_id;
	}

	/**
	 * Build the same canonical JSON envelope sent by the v2 client.
	 *
	 * @param string      $operation     Mutation operation.
	 * @param string      $record_id     Record UUID.
	 * @param string|null $base_revision Client revision.
	 * @param array|null  $payload       Updated fields.
	 */
	private function customer_push_request(
		string $operation,
		string $record_id,
		?string $base_revision,
		?array $payload = null
	): WP_REST_Request {
		$mutation_id = wp_generate_uuid4();
		$envelope    = array(
			'mutationId'   => $mutation_id,
			'operation'    => $operation,
			'collection'   => 'customers',
			'recordId'     => $record_id,
			'baseRevision' => $base_revision,
		);
		if ( null !== $payload ) {
			$envelope['payload'] = $payload;
		}

		$request = $this->wp_rest_post_request( '/wcpos/v2/push/customers' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $mutation_id );
		if ( null !== $base_revision ) {
			$request->set_header( 'If-Match', '"' . $base_revision . '"' );
		}
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $request;
	}

	/**
	 * Read the client-visible revision from the v2 customer lane.
	 *
	 * @param int $customer_id User ID.
	 */
	private function customer_revision( int $customer_id ): string {
		// The proxy revision stamper is opt-in for tests — register it for this
		// read so we take the CLIENT-visible revision, not a recomputation.
		Revision::register_proxy_stamps();
		try {
			$request  = $this->wp_rest_get_request( '/wcpos/v2/customers' );
			$request->set_param( 'include', array( $customer_id ) );
			$response = $this->server->dispatch( $request );
		} finally {
			Revision::unregister_proxy_stamps();
		}
		$rows = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertArrayHasKey( '_rxdb_revision', $rows[0] );

		return $rows[0]['_rxdb_revision'];
	}
}
