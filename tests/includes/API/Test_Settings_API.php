<?php
/**
 * Tests for the Settings API endpoint.
 *
 * Every assertion here goes through $this->server->dispatch() so the route
 * table, the permission callbacks, the endpoint args and the response bodies
 * are all under test — not just the handler methods.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Settings\Section_Registry;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Tests\Helpers\Fixture_Settings_Section;
use WP_Error;

/**
 * Test_Settings_API class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Settings_API extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Route-registration warnings captured during setUp().
	 *
	 * @var string[]
	 */
	private $route_warnings = array();

	/**
	 * The legacy per-section routes and the HTTP verbs they shipped with.
	 * These paths and verbs are frozen public interface.
	 *
	 * @var array<string, string[]>
	 */
	private const LEGACY_SECTION_ROUTES = array(
		'/settings/general'          => array( 'GET', 'POST' ),
		'/settings/checkout'         => array( 'GET', 'POST' ),
		'/settings/payment-gateways' => array( 'GET', 'POST' ),
		'/settings/tax_ids'          => array( 'GET', 'POST' ),
		'/settings/access'           => array( 'GET', 'POST' ),
		'/settings/tools'            => array( 'GET', 'POST' ),
		'/settings/license'          => array( 'GET' ),
		'/settings/cloud-print'      => array( 'GET', 'POST' ),
	);

	/**
	 * The WCPOS REST namespaces the settings service is published under.
	 *
	 * @var string[]
	 */
	private const NAMESPACES = array( '/wcpos/v1', '/wcpos/v2' );

	/**
	 * Set up test fixtures.
	 *
	 * The fixture section is registered before parent::setUp() because REST
	 * routes are projected from the registry during rest_api_init.
	 */
	public function setUp(): void {
		Fixture_Settings_Section::register();

		if ( 'test_section_with_an_unsafe_id_gets_no_route' === $this->getName() ) {
			add_action( 'woocommerce_pos_register_settings_sections', array( $this, 'register_unsafe_section' ) );
		}
		if ( 'test_sections_with_colliding_route_slugs_are_rejected' === $this->getName() ) {
			add_action( 'woocommerce_pos_register_settings_sections', array( $this, 'register_colliding_sections' ) );
			add_filter( 'woocommerce_pos_logging', array( $this, 'capture_route_warning' ), 10, 2 );
		}

		SettingsService::instance()->reset_sections_for_testing();

		parent::setUp();
	}

	/**
	 * Register a section whose id would otherwise be compiled into a route
	 * regex. Public because it is used as an action callback.
	 *
	 * @param Section_Registry $registry The Section Registry.
	 */
	public function register_unsafe_section( Section_Registry $registry ): void {
		$registry->register(
			new class() implements Settings_Section_Interface {
				public function id(): string {
					return '(?P<hijack>.*)';
				}

				public function defaults(): array {
					return array();
				}

				public function read(): array {
					return array();
				}

				public function write( array $settings ) {
					return $settings;
				}

				public function merge( array $existing, array $patch ): array {
					return $patch;
				}

				public function endpoint_args(): array {
					return array();
				}
			}
		);
	}

	/**
	 * Register two sections whose ids normalize to the same route slug.
	 *
	 * @param Section_Registry $registry The Section Registry.
	 */
	public function register_colliding_sections( Section_Registry $registry ): void {
		$registry->register(
			new class() extends Fixture_Settings_Section {
				public function id(): string {
					return 'collision_id';
				}
			}
		);
		$registry->register(
			new class() extends Fixture_Settings_Section {
				public function id(): string {
					return 'collision-id';
				}
			}
		);
	}

	/**
	 * Capture route-registration warnings without writing a test log.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $message Log message.
	 */
	public function capture_route_warning( bool $enabled, string $message ): bool {
		$this->route_warnings[] = $message;

		return false;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'woocommerce_pos_register_settings_sections', array( $this, 'register_unsafe_section' ) );
		remove_action( 'woocommerce_pos_register_settings_sections', array( $this, 'register_colliding_sections' ) );
		remove_filter( 'woocommerce_pos_logging', array( $this, 'capture_route_warning' ), 10 );
		Fixture_Settings_Section::unregister();
		Fixture_Settings_Section::reset();
		SettingsService::instance()->reset_sections_for_testing();

		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		delete_option( 'woocommerce_pos_settings_tax_ids' );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		delete_option( 'woocommerce_pos_settings_visibility' );

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// Route table
	// ──────────────────────────────────────────────

	/**
	 * Every legacy section route still exists, on both namespaces, with at
	 * least the verbs it shipped with.
	 */
	public function test_legacy_section_routes_are_registered_with_their_verbs(): void {
		$routes = $this->server->get_routes();

		foreach ( self::NAMESPACES as $namespace ) {
			foreach ( self::LEGACY_SECTION_ROUTES as $path => $verbs ) {
				$full = $namespace . $path;
				$this->assertArrayHasKey( $full, $routes, $full . ' should be registered' );

				$allowed = $this->allowed_methods( $routes[ $full ] );
				foreach ( $verbs as $verb ) {
					$this->assertContains( $verb, $allowed, $full . ' should allow ' . $verb );
				}
			}
		}
	}

	/**
	 * The section-adjacent read-only lookups are untouched, and the dead
	 * checkout/order-statuses route is gone: its callback pointed at
	 * get_order_statuses(), a method that moved to Services\Settings in
	 * Dec 2022, so every request since answered 500 rest_invalid_handler.
	 * No client calls it — the POS app uses /data/order_statuses.
	 */
	public function test_section_adjacent_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		foreach ( self::NAMESPACES as $namespace ) {
			$this->assertArrayHasKey( $namespace . '/settings', $routes );
			$this->assertArrayHasKey( $namespace . '/settings/tax_ids/detection', $routes );
			$this->assertArrayNotHasKey( $namespace . '/settings/checkout/order-statuses', $routes );
		}
	}

	/**
	 * A request to the removed checkout/order-statuses route 404s with
	 * rest_no_route instead of the old 500 rest_invalid_handler.
	 */
	public function test_removed_order_statuses_route_returns_404(): void {
		wp_set_current_user( $this->user );

		foreach ( self::NAMESPACES as $namespace ) {
			$response = $this->server->dispatch(
				$this->wp_rest_get_request( $namespace . '/settings/checkout/order-statuses' )
			);

			$this->assertEquals( 404, $response->get_status() );
			$this->assertEquals( 'rest_no_route', $response->get_data()['code'] );
		}
	}

	/**
	 * Projection is additive where the hand-written table had gaps: the
	 * visibility section had no HTTP surface at all, and license was read-only
	 * even though the section can write. Both are deliberate.
	 */
	public function test_projection_covers_sections_the_legacy_table_missed(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/settings/visibility', $routes );
		$this->assertEquals(
			array( 'GET', 'POST', 'PUT', 'PATCH' ),
			$this->allowed_methods( $routes['/wcpos/v1/settings/visibility'] )
		);
		$this->assertContains( 'POST', $this->allowed_methods( $routes['/wcpos/v1/settings/license'] ) );

		wp_set_current_user( $this->user );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/visibility' ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'products', $response->get_data() );
	}

	/**
	 * A section id becomes part of a route regex, so an id outside the
	 * documented alphabet is refused instead of compiled into the route table.
	 */
	public function test_section_with_an_unsafe_id_gets_no_route(): void {
		$this->assertTrue(
			SettingsService::instance()->sections()->has( '(?P<hijack>.*)' ),
			'the unsafe section should still be in the registry'
		);

		foreach ( array_keys( $this->server->get_routes() ) as $route ) {
			$this->assertStringNotContainsString( 'hijack', $route );
		}

		// The settings namespace is not swallowed by a catch-all.
		wp_set_current_user( $this->user );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/general' ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'barcode_field', $response->get_data() );
	}

	/**
	 * Distinct section ids may not silently register the same normalized route.
	 */
	public function test_sections_with_colliding_route_slugs_are_rejected(): void {
		$this->assertNotEmpty( $this->route_warnings );
		$this->assertStringContainsString( 'collision-id', implode( '\n', $this->route_warnings ) );
	}

	// ──────────────────────────────────────────────
	// Permission parity (HIGH stakes)
	// ──────────────────────────────────────────────

	/**
	 * Unauthenticated requests are rejected on every section route, on both
	 * namespaces, for both verbs.
	 */
	public function test_unauthenticated_requests_are_rejected_on_every_section_route(): void {
		wp_set_current_user( 0 );

		foreach ( self::NAMESPACES as $namespace ) {
			foreach ( array_keys( self::LEGACY_SECTION_ROUTES ) as $path ) {
				$get = $this->server->dispatch( $this->wp_rest_get_request( $namespace . $path ) );
				$this->assertEquals( 401, $get->get_status(), 'GET ' . $namespace . $path );
				$this->assertEquals( 'woocommerce_pos_rest_unauthorized', $get->get_data()['code'] );

				$post = $this->server->dispatch( $this->wp_rest_post_request( $namespace . $path ) );
				$this->assertEquals( 401, $post->get_status(), 'POST ' . $namespace . $path );
				$this->assertEquals( 'woocommerce_pos_rest_unauthorized', $post->get_data()['code'] );
			}
		}
	}

	/**
	 * A POS user without manage_woocommerce_pos may not read or write settings
	 * — except the server-owned Cloud Printer targets, which POS clients read.
	 */
	public function test_pos_user_without_management_capability_is_rejected(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		foreach ( self::NAMESPACES as $namespace ) {
			foreach ( array_keys( self::LEGACY_SECTION_ROUTES ) as $path ) {
				$expected_read = '/settings/cloud-print' === $path ? 200 : 403;

				$get = $this->server->dispatch( $this->wp_rest_get_request( $namespace . $path ) );
				$this->assertEquals( $expected_read, $get->get_status(), 'GET ' . $namespace . $path );

				$post = $this->server->dispatch( $this->wp_rest_post_request( $namespace . $path ) );
				$this->assertEquals( 403, $post->get_status(), 'POST ' . $namespace . $path );
			}
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Access writes mutate WordPress role capabilities, so manage_woocommerce_pos
	 * is not enough — edit_users AND promote_users are both required.
	 */
	public function test_access_update_requires_user_management_capabilities(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		$user->add_cap( 'manage_woocommerce_pos' );

		// wp_set_current_user() short-circuits for the id it already holds, so
		// the cached capability list has to be dropped after every cap change.
		$refresh = static function () use ( $user_id ): void {
			wp_set_current_user( 0 );
			wp_set_current_user( $user_id );
		};
		$refresh();

		foreach ( self::NAMESPACES as $namespace ) {
			// Reads are allowed at the settings-management level.
			$read = $this->server->dispatch( $this->wp_rest_get_request( $namespace . '/settings/access' ) );
			$this->assertEquals( 200, $read->get_status(), 'GET ' . $namespace . '/settings/access' );

			// Writes are not.
			$write = $this->server->dispatch( $this->wp_rest_post_request( $namespace . '/settings/access' ) );
			$this->assertEquals( 403, $write->get_status(), 'POST ' . $namespace . '/settings/access' );

			$user->add_cap( 'edit_users' );
			$refresh();
			$write = $this->server->dispatch( $this->wp_rest_post_request( $namespace . '/settings/access' ) );
			$this->assertEquals( 403, $write->get_status(), 'edit_users alone must not be enough' );

			$user->add_cap( 'promote_users' );
			$refresh();
			$write = $this->server->dispatch( $this->wp_rest_post_request( $namespace . '/settings/access' ) );
			$this->assertEquals( 200, $write->get_status(), 'edit_users + promote_users must be accepted' );

			$user->remove_cap( 'edit_users' );
			$user->remove_cap( 'promote_users' );
			$refresh();
		}

		wp_delete_user( $user_id );
	}

	/**
	 * An administrator can read every section route on both namespaces.
	 */
	public function test_administrator_can_read_every_section_route(): void {
		wp_set_current_user( $this->user );

		foreach ( self::NAMESPACES as $namespace ) {
			foreach ( array_keys( self::LEGACY_SECTION_ROUTES ) as $path ) {
				$response = $this->server->dispatch( $this->wp_rest_get_request( $namespace . $path ) );
				$this->assertEquals( 200, $response->get_status(), 'GET ' . $namespace . $path );
			}
		}
	}

	// ──────────────────────────────────────────────
	// Read parity
	// ──────────────────────────────────────────────

	/**
	 * Test default general settings.
	 */
	public function test_get_general_default_settings(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/general' ) );
		$settings = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $settings['force_ssl'] );
		$this->assertFalse( $settings['pos_only_products'] );
		$this->assertTrue( $settings['generate_username'] );
		$this->assertFalse( $settings['default_customer_is_cashier'] );
		$this->assertEquals( 0, $settings['default_customer'] );
		$this->assertEquals( '_global_unique_id', $settings['barcode_field'] );
		$this->assertSame( array(), $settings['store_tax_ids'] );
	}

	/**
	 * Test default checkout settings.
	 */
	public function test_get_checkout_default_settings(): void {
		$settings = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/checkout' ) )->get_data();

		$this->assertArrayNotHasKey( 'order_status', $settings );
		$this->assertEquals( 'fiscal', $settings['receipt_default_mode'] );
		$this->assertIsArray( $settings['admin_emails'] );
		$this->assertTrue( $settings['admin_emails']['enabled'] );
		$this->assertIsArray( $settings['customer_emails'] );
		$this->assertTrue( $settings['customer_emails']['enabled'] );
		$this->assertIsArray( $settings['cashier_emails'] );
		$this->assertFalse( $settings['cashier_emails']['enabled'] );
	}

	/**
	 * Test default payment gateways settings.
	 */
	public function test_get_payment_gateways_default_settings(): void {
		$settings = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/payment-gateways' ) )->get_data();

		$this->assertEquals( 'pos_cash', $settings['default_gateway'] );
		$this->assertIsArray( $settings['gateways'] );
		$this->assertTrue( $settings['gateways']['pos_cash']['enabled'] );
		$this->assertEquals( 0, $settings['gateways']['pos_cash']['order'] );
	}

	/**
	 * Test default access settings.
	 */
	public function test_get_access_default_settings(): void {
		$settings      = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/access' ) )->get_data();
		$administrator = $settings['administrator'];

		$this->assertTrue( $administrator['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $administrator['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * Test default license settings.
	 */
	public function test_get_license_default_settings(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/license' ) );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEmpty( $response->get_data() );
	}

	/**
	 * The tax_ids/detection endpoint surfaces only customer-applicable types.
	 *
	 * Business-register identifiers (de_ust_id, nl_kvk, fr_siret, etc.) describe
	 * the store, not the customer, so they must not appear in the write-map UI.
	 */
	public function test_tax_ids_detection_excludes_business_register_types(): void {
		$data = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/tax_ids/detection' ) )->get_data();

		$this->assertArrayHasKey( 'types', $data );
		$this->assertSame( Tax_Id_Types::customer_applicable_types(), $data['types'] );

		foreach ( Tax_Id_Types::business_register_types() as $business_type ) {
			$this->assertNotContains(
				$business_type,
				$data['types'],
				"Business-register type {$business_type} must not appear in detection response"
			);
		}
	}

	// ──────────────────────────────────────────────
	// Write parity
	// ──────────────────────────────────────────────

	/**
	 * Test updating general settings.
	 */
	public function test_update_general_settings(): void {
		$response = $this->post_settings( '/wcpos/v1/settings/general', array( 'pos_only_products' => false ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['pos_only_products'] );

		$response = $this->post_settings( '/wcpos/v1/settings/general', array( 'pos_only_products' => true ) );
		$this->assertTrue( $response->get_data()['pos_only_products'] );
	}

	/**
	 * Test that store_tax_ids round-trips with sanitization applied.
	 */
	public function test_update_general_settings_round_trips_store_tax_ids_with_sanitization(): void {
		$response = $this->post_settings(
			'/wcpos/v1/settings/general',
			array(
				'store_tax_ids' => array(
					array(
						'type'    => 'de_steuernummer',
						'value'   => ' 12/345/67890 ',
						'country' => 'de',
						'label'   => ' Steuernummer ',
					),
					array(
						'type'  => 'de_hrb',
						'value' => '',
					),
					array(
						'type'  => 'de_hrb',
						'value' => 'HRB 12345',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'type'    => 'de_steuernummer',
					'value'   => '12/345/67890',
					'country' => 'DE',
					'label'   => 'Steuernummer',
				),
				array(
					'type'  => 'de_hrb',
					'value' => 'HRB 12345',
				),
			),
			$response->get_data()['store_tax_ids']
		);
	}

	/**
	 * Test that updating with an empty array replaces the stored store_tax_ids.
	 */
	public function test_update_general_settings_replaces_store_tax_ids_array(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array(
						'type'  => 'de_steuernummer',
						'value' => '12/345/67890',
					),
					array(
						'type'  => 'de_hrb',
						'value' => 'HRB 12345',
					),
				),
			)
		);

		$response = $this->post_settings( '/wcpos/v1/settings/general', array( 'store_tax_ids' => array() ) );

		$this->assertSame( array(), $response->get_data()['store_tax_ids'] );
	}

	/**
	 * Test updating checkout settings with array email format.
	 */
	public function test_update_checkout_settings(): void {
		$disabled_emails = array(
			'enabled'         => false,
			'new_order'       => true,
			'cancelled_order' => true,
			'failed_order'    => true,
		);
		$response        = $this->post_settings( '/wcpos/v1/settings/checkout', array( 'admin_emails' => $disabled_emails ) );
		$this->assertIsArray( $response->get_data()['admin_emails'] );
		$this->assertFalse( $response->get_data()['admin_emails']['enabled'] );

		$enabled_emails = array(
			'enabled'         => true,
			'new_order'       => true,
			'cancelled_order' => true,
			'failed_order'    => true,
		);
		$response       = $this->post_settings( '/wcpos/v1/settings/checkout', array( 'admin_emails' => $enabled_emails ) );
		$this->assertTrue( $response->get_data()['admin_emails']['enabled'] );

		$response = $this->post_settings( '/wcpos/v1/settings/checkout', array( 'receipt_default_mode' => 'live' ) );
		$this->assertEquals( 'live', $response->get_data()['receipt_default_mode'] );
	}

	/**
	 * Checkout saves must not persist payment-gateway-owned fields.
	 */
	public function test_update_checkout_settings_drops_payment_gateway_fields(): void {
		$response = $this->post_settings(
			'/wcpos/v1/settings/checkout',
			array(
				'receipt_default_mode' => 'live',
				'auto_print_receipt'   => 'not-a-bool',
				'default_gateway'      => array( 'not-a-string' ),
				'gateways'             => 'not-an-array',
			)
		);
		$data     = $response->get_data();
		$raw      = get_option( 'woocommerce_pos_settings_checkout' );

		$this->assertEquals( 'live', $data['receipt_default_mode'] );
		$this->assertArrayNotHasKey( 'auto_print_receipt', $data );
		$this->assertArrayNotHasKey( 'default_gateway', $data );
		$this->assertArrayNotHasKey( 'gateways', $data );
		$this->assertArrayNotHasKey( 'auto_print_receipt', $raw );
		$this->assertArrayNotHasKey( 'default_gateway', $raw );
		$this->assertArrayNotHasKey( 'gateways', $raw );
	}

	/**
	 * Test updating payment gateways settings.
	 */
	public function test_update_payment_gateways_settings(): void {
		$response = $this->post_settings( '/wcpos/v1/settings/payment-gateways', array( 'default_gateway' => 'pos_cash' ) );
		$this->assertEquals( 'pos_cash', $response->get_data()['default_gateway'] );

		$response = $this->post_settings( '/wcpos/v1/settings/payment-gateways', array( 'default_gateway' => 'pos_card' ) );
		$this->assertEquals( 'pos_card', $response->get_data()['default_gateway'] );

		$response = $this->post_settings(
			'/wcpos/v1/settings/payment-gateways',
			array(
				'gateways' => array(
					'pos_cash' => array(
						'enabled' => false,
					),
				),
			)
		);
		$this->assertFalse( $response->get_data()['gateways']['pos_cash']['enabled'] );
	}

	/**
	 * Endpoint args are projected from the section that owns them, so an
	 * invalid payload is rejected by the route before the section sees it.
	 */
	public function test_endpoint_args_are_projected_from_the_owning_section(): void {
		$response = $this->post_settings( '/wcpos/v1/settings/general', array( 'pos_only_products' => 'not-a-bool' ) );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'rest_invalid_param', $response->get_data()['code'] );

		// Payment-gateway validation belongs to the payment-gateways route,
		// not the checkout route.
		$response = $this->post_settings( '/wcpos/v1/settings/payment-gateways', array( 'default_gateway' => array( 'not-a-string' ) ) );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Visibility writes reject malformed trees before changing the option.
	 */
	public function test_visibility_update_rejects_malformed_tree(): void {
		$before   = SettingsService::instance()->get_visibility_settings();
		$response = $this->post_settings( '/wcpos/v1/settings/visibility', array( 'products' => 'bad' ) );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertEquals( $before, SettingsService::instance()->get_visibility_settings() );
	}

	/**
	 * Visibility id lists are replacements, including an explicitly empty list.
	 */
	public function test_visibility_update_replaces_id_lists(): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( 5, 6 ) ),
					),
				),
			)
		);

		$response = $this->post_settings(
			'/wcpos/v1/settings/visibility',
			array( 'products' => array( 'default' => array( 'online_only' => array( 'ids' => array( 6 ) ) ) ) )
		);
		$this->assertSame( array( 6 ), $response->get_data()['products']['default']['online_only']['ids'] );

		$response = $this->post_settings(
			'/wcpos/v1/settings/visibility',
			array( 'products' => array( 'default' => array( 'online_only' => array( 'ids' => array() ) ) ) )
		);
		$this->assertSame( array(), $response->get_data()['products']['default']['online_only']['ids'] );
	}

	/**
	 * Test updating access settings.
	 */
	public function test_update_access_settings(): void {
		$response = $this->post_settings(
			'/wcpos/v1/settings/access',
			array(
				'administrator' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => false,
						),
					),
				),
			)
		);
		$this->assertFalse( $response->get_data()['administrator']['capabilities']['wcpos']['access_woocommerce_pos'] );

		$response = $this->post_settings(
			'/wcpos/v1/settings/access',
			array(
				'administrator' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);
		$this->assertTrue( $response->get_data()['administrator']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * Form-encoded boolean strings are normalized before capability mutation.
	 */
	public function test_update_access_settings_normalizes_form_boolean_strings(): void {
		try {
			$response = $this->post_settings(
				'/wcpos/v1/settings/access',
				array(
					'administrator' => array(
						'capabilities' => array(
							'wcpos' => array( 'access_woocommerce_pos' => 'false' ),
						),
					),
				)
			);
			$this->assertFalse( $response->get_data()['administrator']['capabilities']['wcpos']['access_woocommerce_pos'] );
		} finally {
			get_role( 'administrator' )->add_cap( 'access_woocommerce_pos' );
		}
	}

	/**
	 * A section's write error is returned to the client untouched.
	 */
	public function test_section_write_error_is_returned_to_the_client(): void {
		$error  = new WP_Error( 'woocommerce_pos_settings_error', 'Write failed.', array( 'status' => 500 ) );
		$filter = static function ( Section_Registry $registry ) use ( $error ): void {
			$registry->register(
				new class( $error ) implements Settings_Section_Interface {
					/**
					 * Error returned by write().
					 *
					 * @var WP_Error
					 */
					private $error;

					public function __construct( WP_Error $error ) {
						$this->error = $error;
					}

					public function id(): string {
						return 'access';
					}

					public function defaults(): array {
						return array();
					}

					public function read(): array {
						return array();
					}

					public function write( array $settings ) {
						return $this->error;
					}

					public function merge( array $existing, array $patch ): array {
						return $patch;
					}

					public function endpoint_args(): array {
						return array();
					}
				}
			);
		};

		add_action( 'woocommerce_pos_register_settings_sections', $filter );
		SettingsService::instance()->reset_sections_for_testing();

		try {
			$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v1/settings/access' ) );
		} finally {
			remove_action( 'woocommerce_pos_register_settings_sections', $filter );
			SettingsService::instance()->reset_sections_for_testing();
		}

		$this->assertEquals( 500, $response->get_status() );
		$this->assertEquals( 'woocommerce_pos_settings_error', $response->get_data()['code'] );
		$this->assertEquals( 'Write failed.', $response->get_data()['message'] );
	}

	/**
	 * Cloud-print write failures keep their flat { code, message } body.
	 */
	public function test_cloud_print_write_error_keeps_flat_body(): void {
		$response = $this->post_settings(
			'/wcpos/v1/settings/cloud-print',
			array(
				'printers' => array(
					array(
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY-1',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => '',
					),
				),
			)
		);
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'wcpos_cloud_print_star_online_invalid', $data['code'] );
		$this->assertArrayHasKey( 'message', $data );
		$this->assertArrayNotHasKey( 'data', $data );
	}

	// ──────────────────────────────────────────────
	// Extension surface (the registry seam)
	// ──────────────────────────────────────────────

	/**
	 * A section registered through the public seam gets a GET route, on both
	 * namespaces, with no controller changes. This is the path Pro's
	 * License_Section takes.
	 */
	public function test_registered_section_gets_a_read_route(): void {
		foreach ( self::NAMESPACES as $namespace ) {
			$response = $this->server->dispatch( $this->wp_rest_get_request( $namespace . '/settings/test-fixture' ) );

			$this->assertEquals( 200, $response->get_status(), 'GET ' . $namespace . '/settings/test-fixture' );
			$this->assertEquals( 'default-alpha', $response->get_data()['alpha'] );
			$this->assertEquals( 0, $response->get_data()['beta'] );
		}
	}

	/**
	 * A section registered through the public seam gets a PATCH-semantics POST
	 * route that persists through the section's own write().
	 */
	public function test_registered_section_gets_a_write_route(): void {
		$response = $this->post_settings( '/wcpos/v1/settings/test-fixture', array( 'alpha' => 'written' ) );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'written', $response->get_data()['alpha'] );

		// A second partial write must not drop the first one — the controller
		// merges the patch over the section's existing view.
		$response = $this->post_settings( '/wcpos/v1/settings/test-fixture', array( 'beta' => 7 ) );

		$this->assertEquals( 'written', $response->get_data()['alpha'] );
		$this->assertEquals( 7, $response->get_data()['beta'] );

		$read = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings/test-fixture' ) );
		$this->assertEquals( 'written', $read->get_data()['alpha'] );
	}

	/**
	 * The registered section's endpoint_args() validate its own route.
	 */
	public function test_registered_section_endpoint_args_reject_invalid_payloads(): void {
		$response = $this->post_settings( '/wcpos/v1/settings/test-fixture', array( 'beta' => 'not-an-int' ) );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * The registered section inherits the same permission gates as the core
	 * sections — no extension opt-out.
	 */
	public function test_registered_section_inherits_the_settings_permission_gates(): void {
		wp_set_current_user( 0 );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/test-fixture' ) );
		$this->assertEquals( 401, $response->get_status() );

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user_id )->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings/test-fixture' ) );
		$this->assertEquals( 403, $response->get_status() );

		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v1/settings/test-fixture' ) );
		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Dispatch a POST to a settings route with a body payload.
	 *
	 * @param string $path   Route path.
	 * @param array  $params Body params.
	 *
	 * @return \WP_REST_Response
	 */
	private function post_settings( string $path, array $params = array() ) {
		$request = $this->wp_rest_post_request( $path );
		$request->set_body_params( $params );

		return $this->server->dispatch( $request );
	}

	/**
	 * Collect the HTTP verbs a registered route responds to.
	 *
	 * @param array $handlers Route handlers as stored by the REST server.
	 *
	 * @return string[]
	 */
	private function allowed_methods( array $handlers ): array {
		$allowed = array();

		foreach ( $handlers as $handler ) {
			foreach ( array_keys( $handler['methods'] ) as $method ) {
				$allowed[ $method ] = true;
			}
		}

		return array_keys( $allowed );
	}
}
