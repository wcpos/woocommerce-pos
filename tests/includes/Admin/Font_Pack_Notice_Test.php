<?php
/**
 * Receipt font failure notice tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Font_Pack_Notice;
use WCPOS\WooCommercePOS\Admin\Notices;
use WCPOS\WooCommercePOS\Services\Font_Pack_Loader;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Real filesystem fixtures.

/** Font_Pack_Notice_Test class. */
class Font_Pack_Notice_Test extends \WP_UnitTestCase {
	/** @var string Isolated uploads root. */
	private $uploads;
	/** @var \ReflectionProperty Notice storage to isolate between tests. */
	private $storage;
	/** @var array Previously queued notices. */
	private $notices;
	/** @var Font_Pack_Notice Callback under test. */
	private $notice;

	/** Arrange an administrator, empty uploads and a cached failure. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->uploads = get_temp_dir() . 'wcpos-font-notice-' . wp_generate_uuid4();
		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		$this->storage = new \ReflectionProperty( Notices::class, 'notices' );
		$this->storage->setAccessible( true );
		$this->notices = $this->storage->getValue();
		$this->storage->setValue( null, array() );
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
		set_transient( 'wcpos_font_pack_failed_dejavu', 1, HOUR_IN_SECONDS );
		$this->notice = new Font_Pack_Notice();
	}

	/** Restore notices, hooks, transients and files. */
	public function tearDown(): void {
		remove_action( 'admin_init', array( $this->notice, 'admin_init' ), 20 );
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		$this->storage->setValue( null, $this->notices );
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
		if ( is_dir( $this->uploads ) ) {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $this->uploads );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Redirect the loader to isolated uploads.
	 *
	 * @param array $uploads Upload locations.
	 * @return array Upload locations.
	 */
	public function upload_dir( array $uploads ): array {
		$uploads['basedir'] = $this->uploads;
		return $uploads;
	}

	/** Missing fonts with a cached failure produce the actionable warning. */
	public function test_admin_init_failed_pack_outputs_warning(): void {
		// Act.
		$this->notice->admin_init();
		ob_start();
		( new Notices() )->admin_notices();
		$output = ob_get_clean();
		// Assert.
		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'WCPOS could not download its receipt fonts from cdn.jsdelivr.net. Receipts will use a basic Latin-only font until the download succeeds. WCPOS retries automatically every hour. If your host blocks outgoing HTTP requests, ask them to allow cdn.jsdelivr.net.', $output );
	}

	/** Installed fonts suppress even a stale failure transient. */
	public function test_admin_init_installed_pack_outputs_nothing(): void {
		// Arrange.
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		$this->assertTrue( ( new Font_Pack_Loader() )->ensure_all() );
		set_transient( 'wcpos_font_pack_failed_dejavu', 1, HOUR_IN_SECONDS );
		// Act.
		$this->notice->admin_init();
		ob_start();
		( new Notices() )->admin_notices();
		$output = ob_get_clean();
		// Assert.
		$this->assertSame( '', $output );
	}
}
