<?php
/**
 * Route-dispatch pins for the v2 customer-create username/password contract.
 *
 * The till never collects a username or password. WooCommerce ≥ 4.7 generates
 * both unconditionally in wc_create_new_customer() when they are empty —
 * neither woocommerce_registration_generate_username nor
 * woocommerce_registration_generate_password gates it anymore (the v1
 * controller's pre_option filters for these predate that and are vestigial).
 * These tests pin that contract on the v2 push lane so a WooCommerce behavior
 * change or a forward regression fails loudly instead of breaking tills.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact route-dispatch regression tests.

use WCPOS\WooCommercePOS\Sync\Api;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Customer_Username extends Sync_REST_Store_Test_Case {
	/** @var mixed */
	private $previous_username_option;

	/** @var mixed */
	private $previous_password_option;

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->previous_username_option = get_option( 'woocommerce_registration_generate_username' );
		$this->previous_password_option = get_option( 'woocommerce_registration_generate_password' );
	}

	public function tearDown(): void {
		update_option( 'woocommerce_registration_generate_username', $this->previous_username_option );
		update_option( 'woocommerce_registration_generate_password', $this->previous_password_option );
		parent::tearDown();
	}

	private function push_customer_create( string $email, string $record_id, string $mutation_id, ?string $username = null ) {
		$payload = array(
			'email'     => $email,
			'meta_data' => array(
				array(
					'key'   => '_woocommerce_pos_uuid',
					'value' => $record_id,
				),
			),
		);
		if ( null !== $username ) {
			$payload['username'] = $username;
		}
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/customers' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'mutationId'   => $mutation_id,
					'operation'    => 'create',
					'collection'   => 'customers',
					'recordId'     => $record_id,
					'baseRevision' => null,
					'payload'      => $payload,
				)
			)
		);

		return $this->server->dispatch( $request );
	}

	public function test_customer_create_without_username_derives_login_from_email(): void {
		// The store option is 'no' on purpose: username generation for API
		// creates must not depend on the online-checkout setting.
		update_option( 'woocommerce_registration_generate_username', 'no' );

		$response = $this->push_customer_create(
			'zed@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c71',
			'a1b2c3d4-4444-4222-8333-000000000071'
		);

		$this->assertSame( 201, $response->get_status() );
		$user = get_user_by( 'email', 'zed@example.com' );
		$this->assertNotFalse( $user );
		$this->assertStringStartsWith( 'zed', $user->user_login );
	}

	public function test_customer_create_keeps_explicit_username(): void {
		update_option( 'woocommerce_registration_generate_username', 'yes' );

		$response = $this->push_customer_create(
			'bob.jones@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c72',
			'a1b2c3d4-4444-4222-8333-000000000072',
			'explicituser'
		);

		$this->assertSame( 201, $response->get_status() );
		$user = get_user_by( 'email', 'bob.jones@example.com' );
		$this->assertNotFalse( $user );
		$this->assertSame( 'explicituser', $user->user_login );
	}

	public function test_customer_create_succeeds_without_password_when_store_generation_disabled(): void {
		update_option( 'woocommerce_registration_generate_password', 'no' );

		$response = $this->push_customer_create(
			'nopass@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c73',
			'a1b2c3d4-4444-4222-8333-000000000073'
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotFalse( get_user_by( 'email', 'nopass@example.com' ) );
	}
}
