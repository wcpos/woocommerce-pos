<?php
/**
 * Gallery preview URL tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Tests preview hosting through the gallery bootstrap.
 */
class Test_Menu_Gallery_Preview_Url extends WP_UnitTestCase {
	/**
	 * Menu under test.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_deregister_script( 'wcpos-template-gallery' );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );
		$this->menu = new Menu();
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		unset( $_ENV['WCPOS_WEB_BUNDLE_REF'], $_ENV['DEVELOPMENT'] );
		remove_all_filters( 'woocommerce_pos_template_gallery_preview_base_url' );
		remove_all_filters( 'woocommerce_pos_development_mode' );
		wp_set_current_user( 0 );
		wp_deregister_script( 'wcpos-template-gallery' );
		parent::tearDown();
	}

	/**
	 * Read the inline scripts attached to the gallery bundle.
	 *
	 * @param string $position Either before or after.
	 * @return string Inline script source.
	 */
	private function inline_scripts( string $position ): string {
		$data = wp_scripts()->get_data( 'wcpos-template-gallery', $position );
		return \is_array( $data ) ? implode( "\n", $data ) : (string) $data;
	}

	/**
	 * Decode the JSON string value from the JavaScript object literal.
	 *
	 * @return string Preview base URL.
	 */
	private function preview_base_url(): string {
		preg_match( '/previewBaseUrl: ("(?:[^"\\\\]|\\\\.)*")/', $this->inline_scripts( 'before' ), $matches );
		return json_decode( $matches[1] );
	}

	/**
	 * Released sites use their plugin major.minor lane.
	 */
	public function test_preview_url_default_uses_release_lane(): void {
		// Arrange.
		$ref = implode( '.', \array_slice( explode( '.', VERSION ), 0, 2 ) );
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( 'https://cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@' . $ref . '/assets/img/template-gallery/previews', $this->preview_base_url() );
	}

	/**
	 * The explicit next override selects next previews.
	 */
	public function test_preview_url_next_override_uses_next_lane(): void {
		// Arrange.
		$_ENV['WCPOS_WEB_BUNDLE_REF'] = 'next';
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( 'https://cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@next/assets/img/template-gallery/previews', $this->preview_base_url() );
	}

	/**
	 * Other bundle overrides do not change the preview lane.
	 */
	public function test_preview_url_other_override_uses_release_lane(): void {
		// Arrange.
		$_ENV['WCPOS_WEB_BUNDLE_REF'] = 'somebranch';
		$ref                        = implode( '.', \array_slice( explode( '.', VERSION ), 0, 2 ) );
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( 'https://cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@' . $ref . '/assets/img/template-gallery/previews', $this->preview_base_url() );
	}

	/**
	 * Sites can supply their own preview host.
	 */
	public function test_preview_url_filter_uses_custom_host(): void {
		// Arrange.
		add_filter(
			'woocommerce_pos_template_gallery_preview_base_url',
			static function () {
				return 'https://example.com/previews';
			}
		);
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( 'https://example.com/previews', $this->preview_base_url() );
	}

	/**
	 * The shared development-mode filter (the WCPOS_DEVELOPMENT constant path) also selects local previews.
	 */
	public function test_preview_url_development_filter_uses_local_files(): void {
		// Arrange.
		add_filter( 'woocommerce_pos_development_mode', '__return_true' );
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( PLUGIN_URL . 'assets/img/template-gallery/previews', $this->preview_base_url() );
	}

	/**
	 * Local development uses the working tree previews.
	 */
	public function test_preview_url_development_uses_local_files(): void {
		// Arrange.
		$_ENV['DEVELOPMENT'] = '1';
		// Act.
		$this->menu->enqueue_gallery_assets();
		// Assert.
		$this->assertSame( PLUGIN_URL . 'assets/img/template-gallery/previews', $this->preview_base_url() );
	}
}
