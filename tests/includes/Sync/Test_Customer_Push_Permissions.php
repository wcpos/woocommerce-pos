<?php
/**
 * Permission tests for customer mutations through the v2 sync push route.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
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

	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
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
	 * Create a cashier-like user without selected granular capabilities.
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
