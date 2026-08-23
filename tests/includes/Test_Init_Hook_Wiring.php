<?php
/**
 * Pins the hook wiring performed by the Init constructor.
 *
 * Init's constructor is the plugin's bootstrap: it registers close to ninety
 * callbacks, and almost none of them are pinned anywhere else. The single most
 * dangerous property it holds is not a priority number but a STATEMENT ORDER —
 * `Core_Order_Audit_Guard` and `Init::determine_current_user_early` share the
 * `determine_current_user` hook AND priority 20, so WordPress runs them in
 * registration order and nothing else decides which wins. Inverting them makes
 * the audit guard fail OPEN on `/wc/v3/orders`, silently, on a route no smoke
 * test hits. See the ordering table on `Init::__construct()`.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use ReflectionProperty;
use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Services\Core_Order_Audit_Guard;
use WCPOS\WooCommercePOS\Sync\Api as Sync_Api;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
use WC_Unit_Test_Case;

/**
 * Init hook wiring test case.
 */
class Test_Init_Hook_Wiring extends WC_Unit_Test_Case {
	/**
	 * Every hook name the constructor registers ONLY when the sync schema latch
	 * is set. Derived from `Sync_Journal::register_hooks()` (32 names),
	 * `Integrity_Digest::register_hooks()` (21, all a subset of the journal's),
	 * `Sync_Journal_Purge::register_hooks()` and the four sync lane filters.
	 *
	 * `woocommerce_update_coupon` is deliberately absent: `Coupon_Modified_Date`
	 * registers it unconditionally, so the latch changes how many callbacks it
	 * carries but not whether the hook exists.
	 *
	 * @var string[]
	 */
	private const LATCHED_ONLY_HOOKS = array(
		'add_user_role',
		'added_term_meta',
		'before_delete_post',
		'created_term',
		'delete_term',
		'delete_user',
		'deleted_term_meta',
		'edited_term',
		'profile_update',
		'remove_user_role',
		'set_user_role',
		'untrashed_post',
		'updated_term_meta',
		'user_register',
		'wcpos_sync_journal_purge',
		'woocommerce_before_delete_order',
		'woocommerce_before_trash_order',
		'woocommerce_created_customer',
		'woocommerce_new_coupon',
		'woocommerce_new_customer',
		'woocommerce_new_order',
		'woocommerce_new_product',
		'woocommerce_new_product_variation',
		'woocommerce_pos_sync_order_pull_payloads',
		'woocommerce_pos_sync_proxy_response',
		'woocommerce_pos_sync_serialized_order',
		'woocommerce_pos_sync_serialized_product',
		'woocommerce_tax_rate_added',
		'woocommerce_tax_rate_deleted',
		'woocommerce_tax_rate_updated',
		'woocommerce_untrash_order',
		'woocommerce_update_customer',
		'woocommerce_update_order',
		'woocommerce_update_product',
		'woocommerce_update_product_variation',
		'wp_trash_post',
	);

	/**
	 * Per-hook clone of the filter registry as it stood before the test.
	 *
	 * @var array<string, \WP_Hook>
	 */
	private $wp_filter_snapshot = array();

	/**
	 * Values of the augmentation pipeline's static bookkeeping before the test.
	 *
	 * @var array<int, mixed>
	 */
	private $static_snapshot = array();

	/**
	 * The sync schema latch option as it stood before the test.
	 *
	 * @var null|string
	 */
	private $schema_latch_snapshot;

	/**
	 * Snapshot every piece of global state constructing Init would disturb.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_filter;

		// The suite boots the plugin at `muplugins_loaded` (tests/bootstrap.php)
		// and then detaches the Sync_Journal / Integrity_Digest observers, so this
		// snapshot already reflects that detachment. Restoring it in tearDown is
		// therefore strictly stronger than re-detaching by hand: it also removes
		// the fresh observer instances a second Init would attach.
		$snapshot = array();
		foreach ( $wp_filter as $hook_name => $hook ) {
			$snapshot[ $hook_name ] = clone $hook;
		}
		$this->wp_filter_snapshot = $snapshot;

		$this->static_snapshot = array();
		foreach ( self::pipeline_statics() as $index => $target ) {
			$this->static_snapshot[ $index ] = self::static_property( $target )->getValue();
		}

		$this->schema_latch_snapshot = get_option( Sync_Api::SCHEMA_OPTION, null );
	}

	/**
	 * Put every snapshotted global back exactly as it was.
	 */
	public function tearDown(): void {
		global $wp_filter;

		$wp_filter = $this->wp_filter_snapshot;

		// Restoring the registry rewinds the REGISTRATIONS but not the pipeline's
		// static record of what it registered; left alone, a later install() would
		// fail to unwire the restored projections and double-augment.
		foreach ( self::pipeline_statics() as $index => $target ) {
			self::static_property( $target )->setValue( null, $this->static_snapshot[ $index ] );
		}

		if ( null === $this->schema_latch_snapshot ) {
			delete_option( Sync_Api::SCHEMA_OPTION );
		} else {
			update_option( Sync_Api::SCHEMA_OPTION, $this->schema_latch_snapshot, false );
		}

		parent::tearDown();
	}

	/**
	 * THE regression this file exists for.
	 *
	 * Both callbacks sit on `determine_current_user` at priority 20, so only
	 * insertion order separates them. The guard has to record the PRIOR
	 * authentication — a cookie or application password — before WCPOS's JWT
	 * filter runs. If the JWT filter ran first the guard would see
	 * `pre_wcpos_user_id > 0` on every token-authenticated request,
	 * `is_wcpos_jwt_authenticated()` would return false for all of them, and
	 * forged `_pos_*` audit meta on core order routes would be accepted.
	 *
	 * Asserted by ARRAY POSITION, not by priority: a priority assertion passes
	 * happily on the broken order.
	 */
	public function test_determine_current_user_registers_the_audit_guard_before_the_jwt_filter(): void {
		// Arrange.
		update_option( Sync_Api::SCHEMA_OPTION, Sync_Api::SCHEMA_VERSION, false );

		// Act.
		$callbacks = $this->with_isolated_init(
			function (): array {
				return $this->callback_labels( 'determine_current_user', 20 );
			}
		);

		// Assert.
		$this->assertSame(
			array(
				Core_Order_Audit_Guard::class . '::record_prior_authentication',
				Init::class . '::determine_current_user_early',
			),
			$callbacks,
			'Core_Order_Audit_Guard must be registered BEFORE Init::determine_current_user_early. '
				. 'They share determine_current_user at priority 20, so statement order in Init::__construct() '
				. 'is the only thing deciding which runs first, and inverting them makes the core-route audit '
				. 'guard fail open on /wc/v3/orders.'
		);
	}

	/**
	 * The exact hook/priority set a constructor sees with the latch DOWN.
	 */
	public function test_constructor_without_the_schema_latch_registers_only_the_unconditional_hooks(): void {
		// Arrange.
		delete_option( Sync_Api::SCHEMA_OPTION );

		$expected = array(
			'activated_plugin'                                      => array( 10 ),
			'admin_enqueue_scripts'                                 => array( 10 ),
			'admin_init'                                            => array( 10 ),
			'admin_notices'                                         => array( 10 ),
			'determine_current_user'                                => array( 20 ),
			'init'                                                  => array( 10 ),
			'query_vars'                                            => array( 10 ),
			'rest_api_init'                                         => array( 10, 20 ),
			'rest_pre_dispatch'                                     => array( 10 ),
			// No rest_pre_serve_request row: this PR moved that registration out of
			// Init and behind Rest_Cors::register_hooks(), which registers it at 20
			// rather than 5. Init no longer publishes any part of the wire contract.
			'send_headers'                                          => array( 99, 9999 ),
			'upgrader_process_complete'                             => array( 10 ),
			'wcpos_analytics_group_refresh'                         => array( 10 ),
			'wcpos_integrity_digest_rebuild'                        => array( 10 ),
			'woocommerce_before_product_object_save'                => array( 10 ),
			'woocommerce_before_product_variation_object_save'      => array( 10 ),
			'woocommerce_pos_rest_api_controllers'                  => array( 10 ),
			'woocommerce_update_coupon'                             => array( 10 ),
		);
		ksort( $expected );

		// Act.
		$actual = $this->capture_constructor_hooks();

		// Assert.
		$this->assertSame(
			$expected,
			$actual,
			'The unconditional half of Init::__construct() changed. Update the ordering table on the '
				. 'constructor at the same time — every priority in it is documented there.'
		);
	}

	/**
	 * The latch adds hooks; it must never move or drop an unconditional one.
	 */
	public function test_constructor_with_the_schema_latch_adds_exactly_the_sync_observer_hooks(): void {
		// Arrange.
		delete_option( Sync_Api::SCHEMA_OPTION );
		$unlatched = $this->capture_constructor_hooks();
		update_option( Sync_Api::SCHEMA_OPTION, Sync_Api::SCHEMA_VERSION, false );

		// Act.
		$latched = $this->capture_constructor_hooks();

		// Assert.
		$added = array_values( array_diff( array_keys( $latched ), array_keys( $unlatched ) ) );
		sort( $added );

		$expected = self::LATCHED_ONLY_HOOKS;
		sort( $expected );

		$this->assertSame( $expected, $added, 'The set of hooks gated by the sync schema latch changed.' );

		foreach ( $unlatched as $hook_name => $priorities ) {
			$this->assertArrayHasKey( $hook_name, $latched, $hook_name . ' disappeared once the latch was set.' );
			$this->assertSame(
				$priorities,
				$latched[ $hook_name ],
				'The schema latch moved ' . $hook_name . ' to a different priority.'
			);
		}
	}

	/**
	 * The sync read lanes' priority gaps, stated as numbers rather than order.
	 *
	 * Meta_Normalizer at 5 must precede Revision at 9 so the stamped revision
	 * bytes equal what the write path recomputes from a bare wc/v3 re-read
	 * (see the Augmentation_Pipeline class docblock).
	 */
	public function test_sync_proxy_response_normalizes_meta_at_5_before_stamping_revisions_at_9(): void {
		// Arrange.
		update_option( Sync_Api::SCHEMA_OPTION, Sync_Api::SCHEMA_VERSION, false );

		// Act.
		$lanes = $this->with_isolated_init(
			function (): array {
				return array(
					'priorities' => $this->hook_priority_map()[ Augmentation_Pipeline::PROXY_FILTER ] ?? array(),
					'five'       => $this->callback_labels( Augmentation_Pipeline::PROXY_FILTER, 5 ),
					'nine'       => $this->callback_labels( Augmentation_Pipeline::PROXY_FILTER, 9 ),
				);
			}
		);

		// Assert.
		$this->assertSame(
			array( 5, 9, 10 ),
			$lanes['priorities'],
			'The batch read lane must run meta normalization (5), then revision stamping (9), then the '
				. 'uuid/digest/record augmenters (10).'
		);
		$this->assertSame(
			array( Meta_Normalizer::class . '::normalize' ),
			$lanes['five'],
			'Meta_Normalizer must own priority 5 on the proxy lane.'
		);
		$this->assertSame(
			array( Revision::class . '::stamp_proxy_revisions' ),
			$lanes['nine'],
			'Revision must own priority 9 on the proxy lane, between normalization and augmentation.'
		);
	}

	/**
	 * Meta normalization also fronts both per-object lanes at priority 5.
	 */
	public function test_sync_serialized_lanes_normalize_meta_at_priority_5(): void {
		// Arrange.
		update_option( Sync_Api::SCHEMA_OPTION, Sync_Api::SCHEMA_VERSION, false );

		// Act.
		$lanes = $this->with_isolated_init(
			function (): array {
				return array(
					'product' => $this->callback_labels( Augmentation_Pipeline::SERIALIZED_FILTER, 5 ),
					'order'   => $this->callback_labels( 'woocommerce_pos_sync_serialized_order', 5 ),
				);
			}
		);

		// Assert.
		$this->assertSame( array( Meta_Normalizer::class . '::normalize' ), $lanes['product'] );
		$this->assertSame( array( Meta_Normalizer::class . '::normalize' ), $lanes['order'] );
	}

	/**
	 * Read the callbacks registered on one hook at one priority, in order.
	 *
	 * @param string $hook_name Hook to inspect.
	 * @param int    $priority  Priority bucket to inspect.
	 *
	 * @return string[] Human-readable callback labels, in registration order.
	 */
	private function callback_labels( string $hook_name, int $priority ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ]->callbacks[ $priority ] ) ) {
			return array();
		}

		$labels = array();
		foreach ( $wp_filter[ $hook_name ]->callbacks[ $priority ] as $registered ) {
			$labels[] = self::label_for( $registered['function'] );
		}

		return $labels;
	}

	/**
	 * Map every registered hook name to its sorted list of priorities.
	 *
	 * @return array<string, int[]>
	 */
	private function hook_priority_map(): array {
		global $wp_filter;

		$map = array();
		foreach ( $wp_filter as $hook_name => $hook ) {
			$priorities = array_map( 'intval', array_keys( $hook->callbacks ) );
			sort( $priorities, SORT_NUMERIC );
			$map[ $hook_name ] = $priorities;
		}
		ksort( $map );

		return $map;
	}

	/**
	 * The hook/priority set that constructing Init ADDS, and nothing else.
	 *
	 * Measured as a delta rather than as the absolute contents of `$wp_filter`,
	 * because the process re-arms hooks on its own. WordPress core's
	 * `wpdb::placeholder_escape()` re-adds `wpdb::remove_placeholder_escape` to
	 * the `query` hook at priority 0 whenever `has_filter()` reports it missing
	 * (wp-includes/class-wpdb.php, in `placeholder_escape()`) — and the empty
	 * registry below guarantees it
	 * is missing, so the very first `$wpdb->prepare()` inside the constructor
	 * puts it back and it looks like something Init registered. It is not: the
	 * live registry carries `query` at 0 and 10 long before any of this runs.
	 *
	 * So: wipe, deliberately trigger a prepared read so everything that re-arms
	 * itself on a database access does so INTO THE BASELINE, then construct and
	 * diff. Anything ambient cancels; only Init's own registrations survive.
	 *
	 * @return array<string, int[]>
	 */
	private function capture_constructor_hooks(): array {
		global $wp_filter, $wpdb;

		$outer     = $wp_filter;
		$wp_filter = array();

		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT %d', 1 ) );
			$baseline = $this->hook_priority_map();

			new Init();

			$after = $this->hook_priority_map();
		} finally {
			$wp_filter = $outer;
		}

		$delta = array();
		foreach ( $after as $hook_name => $priorities ) {
			$added = array_values( array_diff( $priorities, $baseline[ $hook_name ] ?? array() ) );
			if ( array() !== $added ) {
				$delta[ $hook_name ] = $added;
			}
		}
		ksort( $delta );

		return $delta;
	}

	/**
	 * Construct Init against an EMPTY filter registry and inspect the result.
	 *
	 * The suite already carries a globally constructed Init (tests/bootstrap.php
	 * loads the plugin at `muplugins_loaded`), so diffing against the live
	 * registry would show almost nothing — a second registration on the same
	 * hook at the same priority is invisible in a hook/priority map. Wiping the
	 * registry for the duration of the construction is what makes the captured
	 * set exact. The outer registry is restored in `finally`, and setUp/tearDown
	 * restore it again from a clone. Callers that need a WHOLE-registry snapshot
	 * must use {@see capture_constructor_hooks()} instead, which also subtracts
	 * the hooks the process re-arms by itself.
	 *
	 * @param callable $inspect Runs while only Init's own registrations exist.
	 *
	 * @return mixed Whatever $inspect returned.
	 */
	private function with_isolated_init( callable $inspect ) {
		global $wp_filter;

		$outer     = $wp_filter;
		$wp_filter = array();

		try {
			new Init();

			return $inspect();
		} finally {
			$wp_filter = $outer;
		}
	}

	/**
	 * Render a registered callback as a stable, readable label.
	 *
	 * @param mixed $function Registered callback.
	 */
	private static function label_for( $function ): string {
		if ( \is_array( $function ) && isset( $function[0], $function[1] ) ) {
			$class = \is_object( $function[0] ) ? \get_class( $function[0] ) : (string) $function[0];

			return $class . '::' . $function[1];
		}

		if ( \is_string( $function ) ) {
			return $function;
		}

		if ( $function instanceof \Closure ) {
			return 'Closure';
		}

		return \is_object( $function ) ? \get_class( $function ) . '::__invoke' : 'unknown';
	}

	/**
	 * Static properties the augmentation pipeline mutates when Init is built.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function pipeline_statics(): array {
		return array(
			array( Augmentation_Pipeline::class, 'record_augmenters' ),
			array( Augmentation_Pipeline::class, 'projections' ),
			array( Proxy_Uuid_Stamper::class, 'proxy_stampers' ),
		);
	}

	/**
	 * Accessor for one private static property.
	 *
	 * @param array{0: string, 1: string} $target Class name and property name.
	 */
	private static function static_property( array $target ): ReflectionProperty {
		$property = new ReflectionProperty( $target[0], $target[1] );
		$property->setAccessible( true );

		return $property;
	}
}
