<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
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

	/**
	 * Wire the sync read lane a deployed client actually reads through.
	 *
	 * Production installs this on EVERY request: `Init::__construct()` registers
	 * `Meta_Normalizer` at priority 5 and calls `Sync\Augmentation_Pipeline::install()`
	 * behind the schema latch. The phpunit run never gets there. `Init` is
	 * constructed on `plugins_loaded`, and on the suite's only boot the latch is
	 * still unset at that moment — `Activator::version_check()` defers the schema
	 * install to `woocommerce_init`, which fires later. So `Init` reads an unset
	 * latch, skips the whole read lane, and every proxy read in the suite is served
	 * WITHOUT the revision and digest stamps. On a real site the NEXT request finds
	 * the latch written and wires everything; a one-boot process never gets that
	 * second request, which is what makes the gap invisible — the latch reads
	 * healthy by the time a test body runs, so nothing looks disabled.
	 *
	 * A payload pin that runs without this asserts a row shape nobody receives,
	 * and — the inverted signal this whole family exists to stop (#1712, #1717) —
	 * would go RED the day the production wiring were restored.
	 *
	 * Call from `setUp()`, and {@see uninstall_sync_read_lane()} from `tearDown()`.
	 */
	protected function install_sync_read_lane(): void {
		Meta_Normalizer::register_hooks();
		Augmentation_Pipeline::install();
	}

	/**
	 * Unwind every filter {@see install_sync_read_lane()} put up.
	 *
	 * `Augmentation_Pipeline::reset()` removes only the PROJECTIONS the pipeline
	 * installed; the three batch-lane stampers it wires keep their own
	 * `unregister_*` seams and have to be unwound by name, or they leak into every
	 * test that runs after this one.
	 */
	protected function uninstall_sync_read_lane(): void {
		Augmentation_Pipeline::reset();
		Revision::unregister_proxy_stamps();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		Integrity_Digest::unregister_proxy_digest_stampers();
		Meta_Normalizer::unregister_hooks();
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
