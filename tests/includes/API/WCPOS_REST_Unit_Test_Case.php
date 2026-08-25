<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WP_REST_Request;
use WP_User;

/**
 * Base test class for WCPOS.
 */
abstract class WCPOS_REST_Unit_Test_Case extends WC_REST_Unit_Test_Case {
	/**
	 * @var Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	public function setUp(): void {
		$this->drop_stale_rest_api_init_callbacks();
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) ); // add hook before parent::setUp()

		parent::setUp();
		$this->user = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->user );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function rest_api_init(): void {
		new API();
	}

	/**
	 * Drop rest_api_init callbacks left behind by an earlier test case.
	 *
	 * WP_UnitTestCase_Base::set_up() snapshots $wp_filter on the FIRST test of
	 * the whole run and restores that snapshot after every test. This class has
	 * to hook rest_api_init BEFORE parent::setUp(), because the WC REST base
	 * fires rest_api_init from its own setUp — so when a run opens on a WCPOS
	 * REST test, that first test case's callback is baked into the snapshot and
	 * comes back for every later test in the run.
	 *
	 * Every subsequent test then boots TWO WCPOS API objects: two full sets of
	 * controllers, both hooked on rest_dispatch_request. One request is
	 * dispatched through two controller instances, so every request-scoped
	 * filter they install lands twice — a wcpos_include read, for instance,
	 * gets its WHERE clause appended twice. WordPress boots exactly one API
	 * object per PHP request in production, so the second instance is pure
	 * harness noise, and it makes results depend on which file the run opened
	 * with: green in a full-suite run, red when a REST file is run on its own.
	 */
	private function drop_stale_rest_api_init_callbacks(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['rest_api_init'] ) ) {
			return;
		}

		// Collect first: remove_action() rewrites the array being walked.
		$stale = array();
		foreach ( $wp_filter['rest_api_init']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( \is_array( $function ) && isset( $function[0] ) && $function[0] instanceof self && $function[0] !== $this ) {
					$stale[] = array( $function, $priority );
				}
			}
		}

		foreach ( $stale as $entry ) {
			remove_action( 'rest_api_init', $entry[0], $entry[1] );
		}
	}

	public function wp_rest_get_request( $path = '' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( 'GET' );
		$request->set_route( $path );

		return $request;
	}

	public function wp_rest_post_request( $path = '' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( 'POST' );
		$request->set_route( $path );

		return $request;
	}

	/**
	 * NOTE: all PATCH requests are sent as POST requests with a _method=PATCH query param.
	 * This is because PATCH requests are not supported by some servers.
	 *
	 * @param mixed $path
	 */
	public function wp_rest_patch_request( $path = '' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( 'POST' );
		$request->set_route( $path );
		$request->set_query_params( array( '_method' => 'PATCH' ) );

		return $request;
	}

	public function get_reflected_property_value( $propertyName ) {
		$reflection = new ReflectionClass( $this->endpoint );
		$property   = $reflection->getProperty( $propertyName );
		$property->setAccessible( true );

		return $property->getValue( $this->endpoint );
	}

	/**
	 * The field names a WooCommerce schema declares for a VIEW-context read.
	 *
	 * The primitive behind every DERIVED payload field-set pin. A hand-copied
	 * field list can only ever ratify whatever we happened to emit the day
	 * someone wrote it down — that is exactly how the v2 variation payload
	 * changed shape with both suites green (#1710). Deriving the expectation
	 * from the controller's own schema states the rule instead: we serve what
	 * WooCommerce declares, and every deviation has to be named.
	 *
	 * Mirrors WordPress's own `rest_filter_response_by_context()`: a property is
	 * served when its `context` list contains `view`, OR when it declares no
	 * context at all. Properties scoped to `edit` only (`set_paid`,
	 * `manual_update` on orders; `password` on customers) are write-side
	 * arguments and never reach a read payload.
	 *
	 * @param array $properties A schema's `properties` map, e.g.
	 *                          `$controller->get_public_item_schema()['properties']`
	 *                          or a nested `['items']['properties']`.
	 *
	 * @return string[] Field names, in schema order.
	 */
	protected function view_context_fields( array $properties ): array {
		return array_keys(
			array_filter(
				$properties,
				static function ( $property ): bool {
					$context = (array) ( $property['context'] ?? array() );

					return empty( $context ) || \in_array( 'view', $context, true );
				}
			)
		);
	}

	protected function setup_decimal_quantity_tests(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function ( $settings ) {
				$settings['decimal_qty'] = true;
				return $settings;
			}
		);
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
	}
}
