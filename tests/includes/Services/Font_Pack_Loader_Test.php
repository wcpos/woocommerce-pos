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
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
	}

	/** Remove fixtures and restore WordPress state. */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		remove_filter( 'pre_http_request', array( $this, 'http_response' ), 10 );
		delete_transient( 'wcpos_font_pack_failed_dejavu' );
		delete_transient( 'wcpos_font_pack_lock_dejavu' );
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
}
