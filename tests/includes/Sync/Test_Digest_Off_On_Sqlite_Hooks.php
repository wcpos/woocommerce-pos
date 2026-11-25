<?php
/**
 * The integrity digest is switched off on SQLite (#2661): hooks, cron and Site Health.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Digest_Support;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * Digest-off hooks, cron rebuild and Site Health notice; no REST server.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Support
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Digest_Off_On_Sqlite_Hooks extends Sync_Store_Test_Case {
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
