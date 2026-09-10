<?php
/**
 * Font pack installation tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Font_Pack_Loader;
use WCPOS\WooCommercePOS\Templates\Frontend;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Real filesystem fixtures.

/** Font_Pack_Loader_Test class. */
class Font_Pack_Loader_Test extends \WP_UnitTestCase {

	/**
	 * Per-test uploads root.
	 *
	 * @var string
	 */
	private $uploads;
	/**
	 * Loader under test.
	 *
	 * @var Font_Pack_Loader
	 */
	private $loader;
	/**
	 * Requested HTTP URLs.
	 *
	 * @var string[]
	 */
	private $requests = array();
	/**
	 * Whether to serve CDN fixtures.
	 *
	 * @var bool
	 */
	private $cdn = false;
	/**
	 * File whose bytes should be corrupted.
	 *
	 * @var string
	 */
	private $corrupt = '';

	/** Set up isolated uploads and block all real HTTP. */
	public function setUp(): void {
		parent::setUp();
		$this->uploads = get_temp_dir() . 'wcpos-fonts-' . wp_generate_uuid4();
		$this->loader  = new Font_Pack_Loader();
		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		add_filter( 'pre_http_request', array( $this, 'http_response' ), 10, 3 );
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		delete_transient( 'wcpos_font_pack_failed_map' );
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
		// Earlier suites call Activator::single_activate(), which enqueues this
		// action and then creates the sync tables; that DDL commits the test
		// transaction, so the queued action outlives its test. Start clean.
		as_unschedule_all_actions( Font_Pack_Loader::ACTION );
	}

	/** Remove fixtures and restore WordPress state. */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		remove_filter( 'pre_http_request', array( $this, 'http_response' ), 10 );
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		delete_transient( 'wcpos_font_pack_failed_map' );
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
		as_unschedule_all_actions( Font_Pack_Loader::ACTION );
		$this->remove_dir( $this->uploads );
		parent::tearDown();
	}

	/**
	 * Delete a directory tree. wp_upload_dir() also creates its year/month
	 * subdirectory under the filtered basedir, so a fixed list is not enough.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Redirect font writes to this test's directory.
	 *
	 * @param array $uploads Upload locations.
	 * @return array Upload locations.
	 */
	public function upload_dir( array $uploads ): array {
		$uploads['basedir'] = $this->uploads;
		return $uploads;
	}

	/**
	 * Serve the real pack through WordPress's HTTP boundary, never the network.
	 *
	 * @param mixed  $preempt Preempted response.
	 * @param array  $args Request arguments.
	 * @param string $url Request URL.
	 * @return array|\WP_Error HTTP response.
	 */
	public function http_response( $preempt, array $args, string $url ) {
		$this->requests[] = $url;
		if ( ! $this->cdn ) {
			return new \WP_Error( 'network_disabled' );
		}
		$file = basename( $url );
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $file === $this->corrupt ? 'wrong bytes' : file_get_contents( PLUGIN_PATH . 'fonts/packs/dejavu/' . $file ),
		);
	}

	/** Use the CDN branch without moving the source pack. */
	private function use_cdn(): void {
		$this->cdn    = true;
		$this->loader = new class() extends Font_Pack_Loader {
			/**
			 * Hide the checkout source.
			 *
			 * @param string $pack Pack name.
			 * @return string No local directory.
			 */
			protected function local_pack_dir( string $pack ): string {
				return '';
			}
		};
	}

	/** Installation requires all pack receipts and the shared map. */
	public function test_installed_reports_true_only_with_all_receipts_and_map(): void {
		// Arrange / Assert: isolated uploads start empty.
		$this->assertFalse( $this->loader->installed() );
		// Act / Assert.
		$this->assertTrue( $this->loader->ensure_all() );
		$this->assertTrue( $this->loader->installed() );
		unlink( $this->loader->dir() . '/installed-fonts.json' );
		$this->assertFalse( $this->loader->installed() );
		$this->assertSame( array(), $this->requests );
	}

	/** Cached failures are visible until their transient is cleared. */
	public function test_failed_reflects_failure_transient(): void {
		// Arrange / Assert.
		$this->assertFalse( $this->loader->failed() );
		// Act / Assert.
		set_transient( 'wcpos_font_pack_failed_dejavu', 1, HOUR_IN_SECONDS );
		$this->assertTrue( $this->loader->failed() );
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		$this->assertFalse( $this->loader->failed() );
	}

	/** Normal installs deduplicate, but upgrades always enqueue a refresh. */
	public function test_schedule_enqueues_once_and_refresh_enqueues_again(): void {
		// Arrange.
		as_unschedule_all_actions( Font_Pack_Loader::ACTION );
		$query = array(
			'hook'   => Font_Pack_Loader::ACTION,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		);
		// Act / Assert.
		Font_Pack_Loader::schedule();
		Font_Pack_Loader::schedule();
		$this->assertCount( 1, as_get_scheduled_actions( $query, 'ids' ) );
		Font_Pack_Loader::schedule( true );
		$actions = as_get_scheduled_actions( $query, OBJECT );
		$this->assertCount( 2, $actions );
		$this->assertSame( array( array( 'refresh' => false ), array( 'refresh' => true ) ), array_values( array_map( static fn( $a ) => $a->get_args(), $actions ) ) );
	}

	/** A receipt request cannot enqueue another install while the action is running. */
	public function test_schedule_does_not_enqueue_while_action_is_running(): void {
		// Arrange: mark a due action in-progress through the store, as the queue
		// runner does, instead of running the shared queue, whose leftover actions
		// from earlier suites would be claimed ahead of this one.
		as_unschedule_all_actions( Font_Pack_Loader::ACTION );
		$id = as_schedule_single_action( time() - 1, Font_Pack_Loader::ACTION, array( 'refresh' => false ), 'wcpos' );
		\ActionScheduler::store()->log_execution( $id );
		$query = array(
			'hook'   => Font_Pack_Loader::ACTION,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		);
		try {
			// Act.
			Font_Pack_Loader::schedule();
			// Assert.
			$this->assertSame( array(), as_get_scheduled_actions( $query, 'ids' ) );
			$this->assertTrue( as_next_scheduled_action( Font_Pack_Loader::ACTION ) );
		} finally {
			\ActionScheduler::store()->cancel_action( $id );
		}
	}

	/** A receipt request does not enqueue work while an install failure is cached. */
	public function test_schedule_skips_immediate_install_during_failure_ttl(): void {
		// Arrange.
		as_unschedule_all_actions( Font_Pack_Loader::ACTION );
		set_transient( 'wcpos_font_pack_failed_dejavu', 1, Font_Pack_Loader::FAILED_TTL );
		// Act.
		Font_Pack_Loader::schedule();
		// Assert.
		$this->assertFalse( as_next_scheduled_action( Font_Pack_Loader::ACTION ) );
	}

	/** A pending WP-Cron retry suppresses an Action Scheduler duplicate. */
	public function test_schedule_deduplicates_pending_wp_cron_retry(): void {
		// Arrange.
		wp_clear_scheduled_hook( Font_Pack_Loader::ACTION, array( false ) );
		$this->assertTrue( wp_schedule_single_event( time() + Font_Pack_Loader::FAILED_TTL, Font_Pack_Loader::ACTION, array( false ) ) );
		try {
			// Act.
			Font_Pack_Loader::schedule();
			// Assert.
			$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Font_Pack_Loader::ACTION, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		} finally {
			wp_clear_scheduled_hook( Font_Pack_Loader::ACTION, array( false ) );
		}
	}

	/** The handler receives the refresh flag positionally, as Action Scheduler and WP-Cron pass it. */
	public function test_run_scheduled_refresh_flag_reinstalls_changed_version(): void {
		// Arrange: install, then age the receipt so only a refresh reinstalls.
		$this->assertTrue( $this->loader->ensure_all() );
		$receipt = $this->loader->dir() . '/dejavu.json';
		$aged    = json_decode( (string) file_get_contents( $receipt ), true );
		$aged['version'] = 0;
		file_put_contents( $receipt, wp_json_encode( $aged ) );
		// Act.
		Font_Pack_Loader::run_scheduled( false );
		$after_plain = json_decode( (string) file_get_contents( $receipt ), true );
		Font_Pack_Loader::run_scheduled( true );
		$after_refresh = json_decode( (string) file_get_contents( $receipt ), true );
		// Assert.
		$this->assertSame( 0, $after_plain['version'] );
		$this->assertSame( 1, $after_refresh['version'] );
	}

	/** A failed run books exactly one retry for when the failure cache expires. */
	public function test_run_scheduled_failure_schedules_one_retry_after_failed_ttl(): void {
		// Arrange: an extra pack whose CDN is unreachable (HTTP is mocked to fail).
		$broken = static function ( array $sources ): array {
			$sources['broken'] = 'https://cdn.example.invalid/%s/';
			return $sources;
		};
		add_filter( 'woocommerce_pos_font_pack_sources', $broken );
		$query = array(
			'hook'   => Font_Pack_Loader::ACTION,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		);
		try {
			// Act.
			Font_Pack_Loader::run_scheduled();
			$pending = as_get_scheduled_actions( $query, OBJECT );
			// A second failed run while the retry is pending must not add another.
			delete_transient( 'wcpos_font_pack_failed_broken' );
			Font_Pack_Loader::run_scheduled();
			// Assert.
			$this->assertCount( 1, $pending );
			$this->assertEqualsWithDelta( time() + Font_Pack_Loader::FAILED_TTL, reset( $pending )->get_schedule()->get_date()->getTimestamp(), 5 );
			$this->assertCount( 1, as_get_scheduled_actions( $query, 'ids' ) );
			$this->assertFalse( $this->loader->installed() );
		} finally {
			remove_filter( 'woocommerce_pos_font_pack_sources', $broken );
			delete_transient( 'wcpos_font_pack_failed_broken' );
			delete_transient( 'wcpos_font_pack_lock_broken' );
		}
	}

	/** A successful run books nothing further. */
	public function test_run_scheduled_success_schedules_no_retry(): void {
		// Act.
		Font_Pack_Loader::run_scheduled();
		// Assert.
		$this->assertTrue( $this->loader->installed() );
		$this->assertFalse( as_next_scheduled_action( Font_Pack_Loader::ACTION ) );
	}

	/** A receipt whose families are not an array reads as not installed and is reinstalled, never fatal. */
	public function test_installed_damaged_receipt_families_reinstalls_without_error(): void {
		// Arrange.
		$this->assertTrue( $this->loader->ensure_all() );
		$receipt = $this->loader->dir() . '/dejavu.json';
		$damaged = json_decode( (string) file_get_contents( $receipt ), true );
		$damaged['families'] = 'broken';
		file_put_contents( $receipt, wp_json_encode( $damaged ) );
		// Act / Assert.
		$this->assertFalse( $this->loader->installed() );
		$this->assertTrue( $this->loader->ensure_all() );
		$this->assertTrue( $this->loader->installed() );
		$this->assertArrayHasKey( 'dejavu sans', json_decode( (string) file_get_contents( $this->loader->dir() . '/installed-fonts.json' ), true ) );
		$this->assertSame( array(), $this->requests );
	}

	/** A stale or damaged map reads as not installed until the next run rebuilds it. */
	public function test_installed_false_when_map_content_is_stale(): void {
		// Arrange.
		$this->assertTrue( $this->loader->ensure_all() );
		$map = $this->loader->dir() . '/installed-fonts.json';
		file_put_contents( $map, '{}' );
		// Act / Assert.
		$this->assertFalse( $this->loader->installed() );
		$this->assertTrue( $this->loader->ensure_all() );
		$this->assertTrue( $this->loader->installed() );
		$this->assertSame( array(), $this->requests );
	}

	/** A background callback with no arguments installs the local pack. */
	public function test_run_scheduled_installs_pack(): void {
		// Arrange.
		$this->assertFalse( $this->loader->installed() );
		// Act.
		Font_Pack_Loader::run_scheduled();
		// Assert.
		$this->assertTrue( $this->loader->installed() );
		$this->assertSame( array(), $this->requests );
	}

	/** Missing local installation must copy every face and publish the map. */
	public function test_ensure_local_source_installs_fonts_and_map(): void {
		// Arrange.
		$manifest = json_decode( file_get_contents( PLUGIN_PATH . 'fonts/packs/dejavu/pack.json' ), true );
		// Act.
		$result = $this->loader->ensure( 'dejavu' );
		// Assert.
		$this->assertTrue( $result );
		$this->assertCount( 10, $manifest['files'] );
		foreach ( $manifest['files'] as $file => $hash ) {
			$this->assertFileExists( $this->loader->dir() . '/' . $file );
		}
		$this->assertFileExists( $this->loader->dir() . '/dejavu.json' );
		$this->assertSame( $manifest['families'], json_decode( file_get_contents( $this->loader->dir() . '/installed-fonts.json' ), true ) );
		$this->assertSame( array(), $this->requests );
	}

	/** Installed packs must avoid downloads and rewrites. */
	public function test_ensure_installed_pack_leaves_files_unchanged(): void {
		// Arrange: force old mtimes so a rewrite cannot hide in the same second.
		$this->assertTrue( $this->loader->ensure( 'dejavu' ) );
		$files = glob( $this->loader->dir() . '/*' );
		foreach ( $files as $file ) {
			touch( $file, 1000000000 );
		}
		// Act.
		$result = $this->loader->ensure( 'dejavu' );
		clearstatcache();
		// Assert: no content reads are needed to detect rewrites.
		$this->assertTrue( $result );
		$this->assertSame( array(), $this->requests );
		foreach ( $files as $file ) {
			$this->assertSame( 1000000000, filemtime( $file ) );
		}
	}

	/** Production downloads must use the plugin lane and install real bytes. */
	public function test_ensure_cdn_source_installs_from_lane_urls(): void {
		// Arrange.
		$this->use_cdn();
		$base = 'https://cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@' . Frontend::cdn_ref() . '/fonts/packs/dejavu/';
		// Act.
		$result = $this->loader->ensure( 'dejavu' );
		// Assert.
		$this->assertTrue( $result );
		$this->assertCount( 11, $this->requests );
		foreach ( $this->requests as $url ) {
			$this->assertStringStartsWith( $base, $url );
		}
		$this->assertFileExists( $this->loader->font_path( 'DejaVuSansMono.ttf' ) );
		$this->assertFalse( get_transient( 'wcpos_font_pack_lock_dejavu' ) );
	}

	/** A corrupt later download must roll back earlier writes and cache failure. */
	public function test_ensure_hash_mismatch_cleans_files_and_caches_failure(): void {
		// Arrange.
		$this->use_cdn();
		$this->corrupt = 'DejaVuSansMono.ufm';
		// Act.
		$result = $this->loader->ensure( 'dejavu' );
		// Assert.
		$this->assertFalse( $result );
		$this->assertSame( array(), glob( $this->loader->dir() . '/*' ) );
		$this->assertNotFalse( get_transient( 'wcpos_font_pack_failed_dejavu' ) );
		$this->assertFalse( get_transient( 'wcpos_font_pack_lock_dejavu' ) );
		$this->assertNotEmpty( $this->requests );
		$this->requests = array();
		$this->assertFalse( $this->loader->ensure( 'dejavu' ) );
		$this->assertSame( array(), $this->requests );
	}

	/** Unknown packs must not produce an HTTP request. */
	public function test_ensure_unknown_pack_returns_false_without_http(): void {
		// Act.
		$result = $this->loader->ensure( 'unknown' );
		// Assert.
		$this->assertFalse( $result );
		$this->assertSame( array(), $this->requests );
	}

	/** Sites can opt out of all packs without writing anything. */
	public function test_ensure_all_empty_pack_filter_succeeds_without_installing(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_font_packs', '__return_empty_array' );
		try {
			// Act.
			$result = $this->loader->ensure_all();
			// Assert.
			$this->assertTrue( $result );
			$this->assertDirectoryDoesNotExist( $this->loader->dir() );
			$this->assertSame( array(), $this->requests );
		} finally {
			remove_filter( 'woocommerce_pos_font_packs', '__return_empty_array' );
		}
	}

	/** A failed repair must leave every previously installed file in place. */
	public function test_ensure_repair_failure_preserves_installed_files(): void {
		// Arrange: a complete install, one file lost, and the CDN serving a corrupt replacement for another.
		$this->assertTrue( $this->loader->ensure( 'dejavu' ) );
		unlink( $this->loader->dir() . '/DejaVuSansMono.ufm' );
		$this->use_cdn();
		$this->corrupt = 'DejaVuSans-Bold.ttf';
		// Act.
		$result = $this->loader->ensure( 'dejavu' );
		// Assert.
		$this->assertFalse( $result );
		$this->assertFileExists( $this->loader->dir() . '/DejaVuSans.ttf' );
		$this->assertFileExists( $this->loader->dir() . '/dejavu.json' );
		$this->assertSame( array(), glob( $this->loader->dir() . '/*.tmp' ) );
	}

	/** Refresh reinstalls only when the source manifest version changed. */
	public function test_ensure_refresh_reinstalls_only_when_version_changes(): void {
		// Arrange.
		$this->assertTrue( $this->loader->ensure( 'dejavu' ) );
		$receipt = $this->loader->dir() . '/dejavu.json';
		$files   = glob( $this->loader->dir() . '/*' );
		foreach ( $files as $file ) {
			touch( $file, 1000000000 );
		}
		// Act 1: same version, nothing rewritten.
		$same = $this->loader->ensure( 'dejavu', true );
		clearstatcache();
		// Assert 1.
		$this->assertTrue( $same );
		foreach ( $files as $file ) {
			$this->assertSame( 1000000000, filemtime( $file ) );
		}
		// Act 2: the installed receipt claims an older version.
		$stale            = json_decode( file_get_contents( $receipt ), true );
		$stale['version'] = 0;
		file_put_contents( $receipt, wp_json_encode( $stale ) );
		$changed = $this->loader->ensure( 'dejavu', true );
		clearstatcache();
		// Assert 2.
		$this->assertTrue( $changed );
		$this->assertSame( 1, json_decode( file_get_contents( $receipt ), true )['version'] );
		$this->assertNotSame( 1000000000, filemtime( $this->loader->dir() . '/DejaVuSans.ttf' ) );
	}

	/** The sources filter can add a pack that is fetched from its own CDN. */
	public function test_ensure_sources_filter_adds_pack(): void {
		// Arrange.
		$this->cdn = true;
		$filter    = static function ( array $sources ): array {
			$sources['extra'] = 'https://cdn.example.com/packs/extra@%s/';
			return $sources;
		};
		add_filter( 'woocommerce_pos_font_pack_sources', $filter );
		try {
			// Act.
			$result = $this->loader->ensure( 'extra' );
			// Assert.
			$this->assertTrue( $result );
			$this->assertContains( 'extra', Font_Pack_Loader::packs() );
			$this->assertStringStartsWith( 'https://cdn.example.com/packs/extra@' . Frontend::cdn_ref() . '/', $this->requests[0] );
			$this->assertFileExists( $this->loader->dir() . '/extra.json' );
		} finally {
			remove_filter( 'woocommerce_pos_font_pack_sources', $filter );
		}
	}

	/** A stale shared map is rebuilt from the installed receipts on the next call. */
	public function test_ensure_all_rebuilds_stale_font_map(): void {
		// Arrange.
		$this->assertTrue( $this->loader->ensure_all() );
		$map = $this->loader->dir() . '/installed-fonts.json';
		file_put_contents( $map, '{}' );
		// Act.
		$result = $this->loader->ensure_all();
		// Assert.
		$this->assertTrue( $result );
		$this->assertArrayHasKey( 'dejavu sans', json_decode( file_get_contents( $map ), true ) );
	}

	/** A shared map write failure is cached and a successful rebuild clears it. */
	public function test_ensure_all_caches_and_clears_map_write_failure(): void {
		// Arrange: replace the map file with a directory so atomic publication fails.
		$this->assertTrue( $this->loader->ensure_all() );
		$map = $this->loader->dir() . '/installed-fonts.json';
		unlink( $map );
		mkdir( $map );
		try {
			// Act / Assert.
			$this->assertFalse( $this->loader->ensure_all() );
			$this->assertTrue( $this->loader->failed() );
		} finally {
			rmdir( $map );
		}
		$this->assertTrue( $this->loader->ensure_all() );
		$this->assertFalse( $this->loader->failed() );
	}

	/** A publish interrupted after some files were replaced must not leave a pack that passes the installed check. */
	public function test_ensure_publish_failure_forgets_the_pack(): void {
		// Arrange: one destination is blocked by a directory so its rename fails mid-publish.
		$this->assertTrue( $this->loader->ensure( 'dejavu' ) );
		$receipt = $this->loader->dir() . '/dejavu.json';
		$blocked = $this->loader->dir() . '/DejaVuSansMono-Bold.ufm';
		unlink( $blocked );
		mkdir( $blocked );
		try {
			// Act.
			$result = $this->loader->ensure( 'dejavu' );
			// Assert.
			$this->assertFalse( $result );
			$this->assertFileDoesNotExist( $receipt );
			$this->assertSame( array(), glob( $this->loader->dir() . '/*.tmp' ) );
			$this->assertNotFalse( get_transient( 'wcpos_font_pack_failed_dejavu' ) );
		} finally {
			rmdir( $blocked );
		}
	}
}
