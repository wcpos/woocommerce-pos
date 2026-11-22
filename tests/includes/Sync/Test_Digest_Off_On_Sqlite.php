<?php
/**
 * The integrity digest is switched off on SQLite (#2661).
 *
 * The suite runs on MySQL/MariaDB, so the detector is forced through its test
 * override; every gate is exercised without a SQLite database.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Digest_Support;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * Digest gates behind Digest_Support::is_disabled().
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Support
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 */
class Test_Digest_Off_On_Sqlite extends Sync_REST_Store_Test_Case {
	use Seeds_Stored_Digests;
	use Sync_Observer_Unhook_Trait;

	/**
	 * Digest observer under test.
	 *
	 * @var Integrity_Digest
	 */
	private $digest;

	/**
	 * Journal observer, the fallback change feed.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * Whether the bootstrap's static shutdown flush was attached before this test.
	 *
	 * @var bool
	 */
	private $had_shutdown_flush = false;

	/**
	 * Messages written through the WCPOS logger.
	 *
	 * @var string[]
	 */
	private $logged = array();

	/**
	 * Force the SQLite answer and start from clean digest hooks.
	 */
	public function setUp(): void {
		parent::setUp();
		Digest_Support::set_override_for_tests( true );
		Integrity_Digest::reset_request_state();
		Sync_Journal::reset_request_state();
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		$this->had_shutdown_flush = false !== has_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ) );
		remove_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ), PHP_INT_MAX );

		$this->digest = new Integrity_Digest();
		$this->digest->register_hooks();
		$this->journal = new Sync_Journal();
		$this->journal->register_hooks();

		Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', array( $this, 'capture_log' ), 10, 2 );
	}

	/**
	 * Unhook the observers and clear the override so it never leaks.
	 */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', array( $this, 'capture_log' ), 10 );
		Logger::reset_dedup_state();
		$this->remove_observer_callbacks( array( $this->digest, $this->journal ) );
		remove_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ), PHP_INT_MAX );
		if ( $this->had_shutdown_flush ) {
			add_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ), PHP_INT_MAX, 0 );
		}
		remove_filter( 'site_status_tests', array( Integrity_Digest::class, 'site_status_tests' ) );
		Integrity_Digest::reset_request_state();
		Sync_Journal::reset_request_state();
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		Digest_Support::reset();
		parent::tearDown();
	}

	/**
	 * Record a logged message and keep it out of the log file.
	 *
	 * @param bool   $enabled Whether to write the message.
	 * @param string $message The message.
	 */
	public function capture_log( $enabled, $message ): bool {
		$this->logged[] = (string) $message;

		return false;
	}

	/**
	 * Count every row in the stored digest table.
	 */
	private function digest_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->digest->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
	}

	/**
	 * Dispatch a GET request with query parameters.
	 *
	 * @param string $path   Route path.
	 * @param array  $params Query parameters.
	 *
	 * @return \WP_REST_Response
	 */
	private function get( string $path, array $params ) {
		$request = $this->wp_rest_get_request( $path );
		$request->set_query_params( $params );

		return $this->server->dispatch( $request );
	}

	/**
	 * Assert the switched-off scan envelope.
	 *
	 * @param string $collection Requested collection.
	 * @param array  $checkpoint Expected checkpoint.
	 * @param mixed  $response   Scan response.
	 */
	private function assert_digest_off_envelope( string $collection, array $checkpoint, $response ): void {
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( $collection, $data['collection'] );
		$this->assertSame( $checkpoint, $data['checkpoint'] );
		$this->assertSame( array(), $data['changes'] );
		$this->assertTrue( $data['complete'] );
		$this->assertFalse( $data['meta']['supported'] );
		$this->assertArrayNotHasKey( 'rebuilding', $data['meta'] );
		$this->assertSame( 'Integrity digest is off on this database engine (SQLite); changes are tracked by the sequence log.', $data['meta']['note'] );
	}

	/**
	 * (a) No save observer and no shutdown flush; only the Site Health test.
	 */
	public function test_register_hooks_when_disabled_adds_no_observers(): void {
		// Arrange: setUp registered the hooks with the digest off.

		// Act.
		$product_observer  = has_action( 'woocommerce_update_product', array( $this->digest, 'record_post_saved' ) );
		$customer_observer = has_action( 'profile_update', array( $this->digest, 'record_customer_saved' ) );
		$order_observer    = has_action( 'woocommerce_update_order', array( $this->digest, 'record_order_saved' ) );
		$shutdown_flush    = has_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ) );
		$site_health       = has_filter( 'site_status_tests', array( Integrity_Digest::class, 'site_status_tests' ) );

		// Assert.
		$this->assertFalse( $product_observer );
		$this->assertFalse( $customer_observer );
		$this->assertFalse( $order_observer );
		$this->assertFalse( $shutdown_flush );
		$this->assertNotFalse( $site_health );
	}

	/**
	 * (b) Product, customer and order saves write no digest row and log nothing.
	 */
	public function test_saves_when_disabled_write_no_digest_rows_and_log_no_error(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$this->factory->user->create( array( 'role' => 'customer' ) );
		$order = OrderHelper::create_order();

		// Act: the flush stands in for shutdown.
		$product->set_name( 'Renamed' );
		$product->save();
		$order->set_customer_note( 'saved again' );
		$order->save();
		Integrity_Digest::flush_pending_digests();

		// Assert.
		$this->assertSame( 0, $this->digest_rows() );
		$this->assertSame( array(), $this->logged );
	}

	/**
	 * (c) The fallback: the sequence log carries the save without the digest.
	 */
	public function test_sequence_log_when_disabled_reports_saved_product(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();

		// Act.
		$response = $this->get(
			'/wcpos/v2/changes/sequence-log',
			array(
				'collection' => 'products',
				'since'      => 0,
			)
		);

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$rows = array_values(
			array_filter(
				$response->get_data()['changes'],
				static function ( $row ) use ( $product ) {
					return $product->get_id() === $row['id'];
				}
			)
		);
		$this->assertNotEmpty( $rows );
		$this->assertSame( 0, end( $rows )['deleted'] );
		Integrity_Digest::flush_pending_digests();
		$this->assertSame( 0, $this->digest_rows() );
		$this->assertSame( array(), $this->logged );
	}

	/**
	 * (d) The aggregate scan answers "nothing to report" for products and customers.
	 */
	public function test_aggregate_scan_when_disabled_returns_unsupported_envelope(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$this->factory->user->create( array( 'role' => 'customer' ) );
		$fingerprint = get_option( Digest_Index::FORMULA_FP_OPTION );
		$params      = array(
			'bucket_size'   => 1000,
			'after_id'      => 0,
			'limit_buckets' => 50,
		);

		// Act.
		$products  = $this->get( '/wcpos/v2/integrity/scan', $params + array( 'collection' => 'products' ) );
		$customers = $this->get( '/wcpos/v2/integrity/scan', $params + array( 'collection' => 'customers' ) );

		// Assert.
		$checkpoint = array(
			'bucket_size' => 1000,
			'after_id'    => 49999,
		);
		$this->assert_digest_off_envelope( 'products', $checkpoint, $products );
		$this->assert_digest_off_envelope( 'customers', $checkpoint, $customers );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertSame( $fingerprint, get_option( Digest_Index::FORMULA_FP_OPTION ) );
	}

	/**
	 * (e) The drill-down answers "nothing to report" with the bucket checkpoint.
	 */
	public function test_drill_down_scan_when_disabled_returns_unsupported_envelope(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		$fingerprint = get_option( Digest_Index::FORMULA_FP_OPTION );

		// Act.
		$response = $this->get(
			'/wcpos/v2/integrity/scan',
			array(
				'collection'  => 'products',
				'bucket_size' => 1000,
				'bucket'      => 0,
			)
		);

		// Assert.
		$this->assert_digest_off_envelope(
			'products',
			array(
				'bucket_size' => 1000,
				'bucket'      => 0,
			),
			$response
		);
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertSame( $fingerprint, get_option( Digest_Index::FORMULA_FP_OPTION ) );
	}

	/**
	 * (f) The bucket listing errors instead of answering an empty 200.
	 */
	public function test_bucket_list_when_disabled_returns_503_and_keeps_400_for_unsupported_collection(): void {
		// Arrange.
		ProductHelper::create_simple_product();

		// Act.
		$listing     = $this->get(
			'/wcpos/v2/integrity/bucket',
			array(
				'collection' => 'products',
				'bucket'     => 0,
			)
		);
		$unsupported = $this->get(
			'/wcpos/v2/integrity/bucket',
			array(
				'collection' => 'nope',
				'bucket'     => 0,
			)
		);

		// Assert.
		$this->assertSame( 503, $listing->get_status() );
		$this->assertSame( 'woocommerce_pos_sync_integrity_unavailable', $listing->get_data()['code'] );
		$this->assertSame( 400, $unsupported->get_status() );
		$this->assertSame( 'woocommerce_pos_sync_unsupported_bucket_collection', $unsupported->get_data()['code'] );
	}

	/**
	 * (g) /digests stamps nothing, but authoritative absence still reports a trashed id.
	 */
	public function test_digests_when_disabled_returns_no_digest_and_keeps_deleted_rows(): void {
		// Arrange: a row left over from a MySQL past must not be served.
		$live    = ProductHelper::create_simple_product();
		$trashed = ProductHelper::create_simple_product();
		Digest_Support::set_override_for_tests( false );
		$this->seed_digests( 'products', $live->get_id() );
		Digest_Support::set_override_for_tests( true );
		wp_trash_post( $trashed->get_id() );

		// Act.
		$response = $this->get(
			'/wcpos/v2/digests',
			array(
				'collection' => 'products',
				'include'    => $live->get_id() . ',' . $trashed->get_id(),
				'absence'    => 'explicit',
			)
		);

		// Assert.
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame(
			array(
				array(
					'id'      => $trashed->get_id(),
					'deleted' => true,
				),
			),
			$response->get_data()['digests']
		);
	}

	/**
	 * (h) The cron rebuild writes nothing and releases its lease.
	 */
	public function test_run_scheduled_rebuild_when_disabled_writes_nothing_and_releases_lease(): void {
		// Arrange.
		ProductHelper::create_simple_product();
		Integrity_Digest::reset_request_state();
		set_transient( Integrity_Digest::REBUILD_LOCK, 'lease-under-test', Integrity_Digest::REBUILD_LOCK_TTL );

		// Act.
		Integrity_Digest::run_scheduled_rebuild();

		// Assert.
		$this->assertSame( 0, $this->digest_rows() );
		$this->assertFalse( get_transient( Integrity_Digest::REBUILD_LOCK ) );
		$this->assertSame( array(), $this->logged );
	}

	/**
	 * (i) Site Health carries one recommended notice.
	 */
	public function test_site_health_when_disabled_reports_recommended_notice(): void {
		// Arrange.
		$tests = array( 'direct' => array() );

		// Act.
		$tests  = apply_filters( 'site_status_tests', $tests ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook.
		$result = Integrity_Digest::site_health_test();

		// Assert.
		$this->assertArrayHasKey( 'wcpos_sync_digest_sqlite', $tests['direct'] );
		$this->assertSame( array( Integrity_Digest::class, 'site_health_test' ), $tests['direct']['wcpos_sync_digest_sqlite']['test'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'wcpos_sync_digest_sqlite', $result['test'] );
	}

	/**
	 * (j) The admin rebuild is not implemented on this engine.
	 */
	public function test_rebuild_endpoint_when_disabled_returns_501(): void {
		// Arrange.
		ProductHelper::create_simple_product();

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/integrity/rebuild' ) );

		// Assert.
		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_sync_integrity_unavailable', $response->get_data()['code'] );
		$this->assertSame( 0, $this->digest_rows() );
	}

	/**
	 * (k) On MySQL nothing changes: observers register and no notice is added.
	 */
	public function test_register_hooks_when_not_disabled_keeps_observers_and_adds_no_notice(): void {
		// Arrange.
		$this->remove_observer_callbacks( array( $this->digest ) );
		remove_filter( 'site_status_tests', array( Integrity_Digest::class, 'site_status_tests' ) );
		Digest_Support::set_override_for_tests( false );
		$digest = new Integrity_Digest();

		// Act.
		$digest->register_hooks();
		$observer = has_action( 'woocommerce_update_product', array( $digest, 'record_post_saved' ) );
		$tests    = apply_filters( 'site_status_tests', array( 'direct' => array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook.
		$this->remove_observer_callbacks( array( $digest ) );
		Digest_Support::reset();
		$detected = Digest_Support::is_disabled();

		// Assert.
		$this->assertSame( 10, $observer );
		$this->assertArrayNotHasKey( 'wcpos_sync_digest_sqlite', $tests['direct'] );
		$this->assertFalse( $detected );
	}
}
