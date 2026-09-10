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
	public function test_asset_version_is_plugin_version_plus_file_mtime(): void {
		$base = trailingslashit( sys_get_temp_dir() ) . 'wcpos-gallery-assets-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $base . 'assets/js' );
		file_put_contents( $base . 'assets/js/template-gallery.js', '// built' );
		touch( $base . 'assets/js/template-gallery.js', 1700000000 );

		$this->assertSame( VERSION . '.1700000000', Menu::asset_version( 'assets/js/template-gallery.js', $base ) );
		$this->assertSame( VERSION, Menu::asset_version( 'assets/css/missing.css', $base ) );

		unlink( $base . 'assets/js/template-gallery.js' );
	}

	public function test_gallery_bundle_is_enqueued_with_the_versioned_string(): void {
		( new Menu() )->enqueue_gallery_assets();

		$this->assertSame( Menu::asset_version( 'assets/js/template-gallery.js' ), wp_scripts()->registered['wcpos-template-gallery']->ver );
		$this->assertSame( Menu::asset_version( 'assets/css/template-gallery.css' ), wp_styles()->registered['wcpos-template-gallery-styles']->ver );
	}
}
