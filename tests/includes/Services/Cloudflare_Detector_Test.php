<?php
/**
 * Tests for Cloudflare detection.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloudflare_Detector;

/**
 * Cloudflare detector test case.
 */
class Cloudflare_Detector_Test extends \WP_UnitTestCase {
	/**
	 * Test a request with CF-Ray is proxied.
	 */
	public function test_proxied_ray_present_returns_true(): void {
		// Arrange.
		$detector = new Cloudflare_Detector();
		// Act.
		$result = $detector->detect( array( 'HTTP_CF_RAY' => 'abc123-MAD' ), array() );
		// Assert.
		$this->assertSame( true, $result['proxied'] );
	}

	/**
	 * Test a request without CF-Ray is not proxied.
	 */
	public function test_proxied_ray_absent_returns_false(): void {
		// Arrange.
		$detector = new Cloudflare_Detector();
		// Act.
		$result = $detector->detect( array(), array() );
		// Assert.
		$this->assertSame( false, $result['proxied'] );
	}

	/**
	 * Test an empty CF-Ray is not proxied.
	 */
	public function test_proxied_ray_empty_returns_false(): void {
		// Arrange.
		$detector = new Cloudflare_Detector();
		// Act.
		$result = $detector->detect( array( 'HTTP_CF_RAY' => '' ), array() );
		// Assert.
		$this->assertSame( false, $result['proxied'] );
	}

	/**
	 * Test the official plugin is detected from the injected list.
	 */
	public function test_plugin_active_official_plugin_present_returns_true(): void {
		// Arrange.
		$detector = new Cloudflare_Detector();
		// Act.
		$result = $detector->detect( array(), array( 'cloudflare/cloudflare.php' ) );
		// Assert.
		$this->assertSame( true, $result['plugin_active'] );
	}

	/**
	 * Test unrelated plugins do not count as the official plugin.
	 */
	public function test_plugin_active_official_plugin_absent_returns_false(): void {
		// Arrange.
		$detector = new Cloudflare_Detector();
		// Act.
		$result = $detector->detect( array(), array( 'other/other.php' ) );
		// Assert.
		$this->assertSame( false, $result['plugin_active'] );
	}
}
