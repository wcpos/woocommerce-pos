<?php
/**
 * Tests for the bootstrap page-cache request check.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;
use function WCPOS\WooCommercePOS\woocommerce_pos_is_uncacheable_request;

/**
 * Test the paths that must bypass page caching.
 */
class Test_Uncacheable_Request extends WP_UnitTestCase {

	/**
	 * Request paths determine whether page caching must be disabled.
	 *
	 * @dataProvider uncacheable_request_provider
	 *
	 * @param string $request_uri Raw request URI.
	 * @param string $home_path   Site home path.
	 * @param bool   $expected    Whether caching must be disabled.
	 */
	public function test_uncacheable_request_path_returns_expected_result( string $request_uri, string $home_path, bool $expected ): void {
		// Arrange: the data provider supplies the request URI and home path.

		// Act.
		$actual = woocommerce_pos_is_uncacheable_request( $request_uri, $home_path );

		// Assert.
		if ( $expected ) {
			$this->assertTrue( $actual );
		} else {
			$this->assertFalse( $actual );
		}
	}

	/**
	 * Provide cache-sensitive paths and unrelated requests.
	 *
	 * @return array
	 */
	public static function uncacheable_request_provider(): array {
		return array(
			'auth trailing slash'     => array( '/wcpos-auth/', '', true ),
			'auth no trailing slash'  => array( '/wcpos-auth', '', true ),
			'auth query string'       => array( '/wcpos-auth?state=x&redirect_uri=y', '', true ),
			'auth uppercase'          => array( '/WCPOS-AUTH/', '', true ),
			'checkout child path'     => array( '/wcpos-checkout/order-pay/12/', '', true ),
			'subdirectory auth'       => array( '/shop/wcpos-auth/', '/shop', true ),
			'subdirectory auth query' => array( '/shop/wcpos-auth?state=x', 'shop', true ),
			'root'                    => array( '/', '', false ),
			'auth lookalike'          => array( '/wcpos-authx/', '', false ),
			'pos'                     => array( '/pos/', '', false ),
			'rest status'             => array( '/wp-json/wcpos/v2/status', '', false ),
			'auth only in query'      => array( '/?s=/wcpos-auth/', '', false ),
			'home prefix mismatch'    => array( '/blog/wcpos-auth/', '/shop', false ),
			'home root'               => array( '/shop/', '/shop', false ),
		);
	}
}
