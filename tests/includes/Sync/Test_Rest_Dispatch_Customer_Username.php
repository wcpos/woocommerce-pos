<?php
/**
 * Route-dispatch pins for the v2 customer-create username/password contract.
 *
 * The till never collects a username or password. WooCommerce ≥ 4.7 generates
 * both in wc_create_new_customer() when they are empty, but the store options
 * woocommerce_registration_generate_username / _password still mark the
 * wc/v3 route's args as required, and WordPress refuses a missing required
 * arg before the controller runs (POS-2QW). Customer_Writer lifts those flags
 * for the till's own forward only. These tests pin that contract on the v2
 * push lane, with the route re-registered after the options change.
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

	private function push_customer_create( string $email, string $record_id, string $mutation_id, ?string $username = null, array $extra = array() ) {
		$payload = array_merge(
			$extra,
			array(
				'email'     => $email,
				'meta_data' => array(
					array(
						'key'   => '_woocommerce_pos_uuid',
						'value' => $record_id,
					),
				),
			)
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

	/**
	 * Re-register wc/v3 customers so its create args reflect the store options as set now.
	 *
	 * WooCommerce computes the create route's `required` flags for `username` and
	 * `password` from the store options when the route registers on rest_api_init.
	 * On a live site those options are set long before the request, so the flags are
	 * already `true` when the push forwards. Changing the options after setUp alone
	 * leaves the flags captured from the defaults, which is why the tests above never
	 * reach WordPress's required-parameter check.
	 */
	private function reregister_wc_customers_route(): void {
		global $wp_rest_server;
		$server         = $wp_rest_server;
		$wp_rest_server = new \WP_Test_Spy_REST_Server();
		try {
			( new \WC_REST_Customers_Controller() )->register_routes();
			$endpoints = $wp_rest_server->get_raw_endpoint_data();
		} finally {
			$wp_rest_server = $server;
		}
		$server->register_route( 'wc/v3', '/wc/v3/customers', $endpoints['/wc/v3/customers'], true );
	}

	/**
	 * Set the POS "generate username" setting, keeping the rest of the section.
	 */
	private function set_pos_generate_username( bool $enabled ): void {
		$general                      = get_option( 'woocommerce_pos_settings_general', array() );
		$general                      = \is_array( $general ) ? $general : array();
		$general['generate_username'] = $enabled;
		update_option( 'woocommerce_pos_settings_general', $general );
	}

	/**
	 * Assert a dispatch was refused by WordPress's required-parameter check, naming the given params.
	 *
	 * @param string[] $params The missing parameters, in route-arg order.
	 */
	private function assert_missing_params( array $params, \WP_REST_Response $response ): void {
		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( 'rest_missing_callback_param', $data['code'] ?? null );
		$this->assertSame( $params, $data['data']['params'] ?? null );
	}

	/**
	 * Sentry WOOCOMMERCE-POS-2QW: a 2.0.0 till pushes a customer create without a
	 * username and gets 400 rest_missing_callback_param "Missing parameter(s): username"
	 * on stores whose online-checkout setting requires a username, although the POS
	 * setting says the POS generates one.
	 */
	public function test_create_customer_without_username_when_store_requires_it_but_pos_generates_it_returns_created(): void {
		// Arrange: the store requires a username at checkout; the POS generates one.
		update_option( 'woocommerce_registration_generate_username', 'no' );
		update_option( 'woocommerce_registration_generate_password', 'yes' );
		$this->set_pos_generate_username( true );
		$this->reregister_wc_customers_route();

		// Act: the till's create carries an email and names, never a username.
		$response = $this->push_customer_create(
			'pos2qw.username@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c74',
			'a1b2c3d4-4444-4222-8333-000000000074',
			null,
			array(
				'first_name' => 'Jane',
				'last_name'  => 'Smith',
			)
		);

		// Assert: created, with a generated login and password.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$user = get_user_by( 'email', 'pos2qw.username@example.com' );
		$this->assertNotFalse( $user );
		$this->assertStringStartsWith( 'pos2qw', $user->user_login );
		$this->assertSame( '1', get_user_option( 'default_password_nag', $user->ID ), 'WooCommerce generated the password' );
	}

	/**
	 * The password twin of POS-2QW: the till never sends a password, and the v1
	 * controller always forced generation, so a store that turned password
	 * generation off at checkout must not refuse POS customer creates.
	 */
	public function test_create_customer_without_password_when_store_requires_it_returns_created(): void {
		// Arrange: the store requires a password at checkout; usernames are generated.
		update_option( 'woocommerce_registration_generate_username', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'no' );
		$this->set_pos_generate_username( true );
		$this->reregister_wc_customers_route();

		// Act: the till's create carries an email and names, never a password.
		$response = $this->push_customer_create(
			'pos2qw.password@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c75',
			'a1b2c3d4-4444-4222-8333-000000000075',
			null,
			array(
				'first_name' => 'John',
				'last_name'  => 'Doe',
			)
		);

		// Assert: created, with a generated login and password.
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$user = get_user_by( 'email', 'pos2qw.password@example.com' );
		$this->assertNotFalse( $user );
		$this->assertNotSame( '', $user->user_login );
		$this->assertSame( '1', get_user_option( 'default_password_nag', $user->ID ), 'WooCommerce generated the password' );
	}

	/**
	 * With the POS setting off the POS does not generate a username, so a store that
	 * requires one still refuses the till's create: the app must then send one.
	 */
	public function test_create_customer_without_username_when_store_requires_it_and_pos_setting_off_returns_missing_username(): void {
		// Arrange: the store requires a username at checkout; the POS does not generate one.
		update_option( 'woocommerce_registration_generate_username', 'no' );
		update_option( 'woocommerce_registration_generate_password', 'yes' );
		$this->set_pos_generate_username( false );
		$this->reregister_wc_customers_route();

		// Act.
		$response = $this->push_customer_create(
			'pos2qw.off@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c76',
			'a1b2c3d4-4444-4222-8333-000000000076'
		);

		// Assert: refused naming username; no user created.
		$this->assert_missing_params( array( 'username' ), $response );
		$this->assertFalse( get_user_by( 'email', 'pos2qw.off@example.com' ) );
	}

	/**
	 * The relaxation lives only for the till's own forward: a direct wc/v3 create from
	 * any other client, after the till's create, is still held to the store's options.
	 */
	public function test_direct_wc_v3_customer_create_after_pos_create_still_requires_username_and_password(): void {
		// Arrange: the store requires both at checkout; the POS generates usernames.
		update_option( 'woocommerce_registration_generate_username', 'no' );
		update_option( 'woocommerce_registration_generate_password', 'no' );
		$this->set_pos_generate_username( true );
		$this->reregister_wc_customers_route();
		$pos_response = $this->push_customer_create(
			'pos2qw.till@example.com',
			'5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c77',
			'a1b2c3d4-4444-4222-8333-000000000077'
		);
		$this->assertSame( 201, $pos_response->get_status(), (string) wp_json_encode( $pos_response->get_data() ) );

		// Act: another client creates through wc/v3 directly, without username or password.
		$request = new \WP_REST_Request( 'POST', '/wc/v3/customers' );
		$request->set_body_params( array( 'email' => 'pos2qw.direct@example.com' ) );
		$response = $this->server->dispatch( $request );

		// Assert: refused naming both, as before the fix.
		$this->assert_missing_params( array( 'username', 'password' ), $response );
		$this->assertFalse( get_user_by( 'email', 'pos2qw.direct@example.com' ) );
	}
}
