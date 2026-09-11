<?php
/**
 * Gallery bundle cache busting.
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WP_UnitTestCase;

use const WCPOS\WooCommercePOS\VERSION;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Gallery_Asset_Version extends WP_UnitTestCase {
	/** @var mixed */
	private $development;

	public function setUp(): void {
		parent::setUp();
		wp_deregister_script( 'wcpos-template-gallery' );
		wp_deregister_style( 'wcpos-template-gallery-styles' );
		// The enqueue reads $_ENV['DEVELOPMENT'] to pick build/ over assets/; pin the release path.
		$this->development = $_ENV['DEVELOPMENT'] ?? null;
		unset( $_ENV['DEVELOPMENT'] );
	}

	public function tearDown(): void {
		if ( null !== $this->development ) {
			$_ENV['DEVELOPMENT'] = $this->development;
		}
		wp_deregister_script( 'wcpos-template-gallery' );
		wp_deregister_style( 'wcpos-template-gallery-styles' );
		parent::tearDown();
	}

	public function test_asset_version_existing_file_returns_plugin_version_with_mtime(): void {
		$base = trailingslashit( sys_get_temp_dir() ) . 'wcpos-gallery-assets-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $base . 'assets/js' );
		file_put_contents( $base . 'assets/js/template-gallery.js', '// built' );
		touch( $base . 'assets/js/template-gallery.js', 1700000000 );

		$this->assertSame( VERSION . '.1700000000', Menu::asset_version( 'assets/js/template-gallery.js', $base ) );
		$this->assertSame( VERSION, Menu::asset_version( 'assets/css/missing.css', $base ) );

		unlink( $base . 'assets/js/template-gallery.js' );
	}

	public function test_asset_version_base_without_trailing_slash_returns_mtime_version(): void {
		$base = trailingslashit( sys_get_temp_dir() ) . 'wcpos-gallery-base-' . wp_generate_password( 8, false );
		wp_mkdir_p( $base . '/assets/js' );
		file_put_contents( $base . '/assets/js/template-gallery.js', '// built' );
		touch( $base . '/assets/js/template-gallery.js', 1700000000 );

		$this->assertSame( VERSION . '.1700000000', Menu::asset_version( 'assets/js/template-gallery.js', $base ) );

		unlink( $base . '/assets/js/template-gallery.js' );
	}

	public function test_enqueue_gallery_assets_registers_bundle_with_versioned_string(): void {
		( new Menu() )->enqueue_gallery_assets();

		$this->assertSame( Menu::asset_version( 'assets/js/template-gallery.js' ), wp_scripts()->registered['wcpos-template-gallery']->ver );
		$this->assertSame( Menu::asset_version( 'assets/css/template-gallery.css' ), wp_styles()->registered['wcpos-template-gallery-styles']->ver );
	}
}
