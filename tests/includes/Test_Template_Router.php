<?php
/**
 * Tests for the WCPOS Template_Router class.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Template_Router;

/**
 * Test_Template_Router class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Template_Router extends WC_Unit_Test_Case {
	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;
		unset( $wp->query_vars['wcpos'], $wp->query_vars['order-pay'] );

		// The test framework only resets permalinks for core tests, so undo
		// set_permalink_structure() here or it leaks into later test classes.
		$this->set_permalink_structure( '' );

		parent::tearDown();
	}

	/**
	 * Order received URL should use https when the force_ssl setting is
	 * enabled (the default), even when the site home URL is http.
	 */
	public function test_order_received_url_force_ssl_enabled_returns_https(): void {
		// Arrange: force_ssl defaults to true; test site home URL is http.
		global $wp;
		$this->assertStringStartsWith( 'http://', get_home_url() );
		$this->set_permalink_structure( '/%postname%/' );
		$order                       = OrderHelper::create_order();
		$wp->query_vars['wcpos']     = 1;
		$wp->query_vars['order-pay'] = $order->get_id();
		$router                      = new Template_Router();

		// Act.
		$redirect = $router->order_received_url( get_home_url( null, '/checkout/order-received/' ), $order );

		// Assert.
		$this->assertStringStartsWith( 'https://', $redirect );
		$this->assertStringContainsString( '/wcpos-checkout/order-received/' . $order->get_id() . '/?key=', $redirect );
	}

	/**
	 * Order received URL should respect the permalink trailing-slash style
	 * before the query args (see wcpos_checkout_url()).
	 */
	public function test_order_received_url_no_slash_permalinks_keeps_no_slash(): void {
		// Arrange.
		global $wp;
		$this->set_permalink_structure( '/%postname%' );
		$order                       = OrderHelper::create_order();
		$wp->query_vars['wcpos']     = 1;
		$wp->query_vars['order-pay'] = $order->get_id();
		$router                      = new Template_Router();

		// Act.
		$redirect = $router->order_received_url( get_home_url( null, '/checkout/order-received/' ), $order );

		// Assert.
		$this->assertStringContainsString( '/wcpos-checkout/order-received/' . $order->get_id() . '?key=', $redirect );
	}

	/**
	 * Auth URL should use https when the force_ssl setting is enabled (the
	 * default), even when the site home URL is http. It is handed to the POS
	 * app as the authorization/login URL, so an http URL is blocked as mixed
	 * content when the POS is served over https.
	 */
	public function test_get_auth_url_force_ssl_enabled_returns_https(): void {
		// Arrange: force_ssl defaults to true; test site home URL is http.
		$this->assertStringStartsWith( 'http://', get_home_url() );
		$this->set_permalink_structure( '/%postname%/' );

		// Act.
		$auth_url = Template_Router::get_auth_url();

		// Assert.
		$this->assertStringStartsWith( 'https://', $auth_url );
		$this->assertStringEndsWith( '/wcpos-auth/', $auth_url );
	}

	/**
	 * Auth URL should keep no trailing slash when the permalink structure has
	 * no trailing slash.
	 */
	public function test_get_auth_url_no_slash_permalinks_ends_without_slash(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%' );

		// Act.
		$auth_url = Template_Router::get_auth_url();

		// Assert.
		$this->assertStringEndsWith( '/wcpos-auth', $auth_url );
	}

	/**
	 * Auth URL should preserve the home URL scheme when the force_ssl setting
	 * is disabled.
	 */
	public function test_get_auth_url_force_ssl_disabled_preserves_home_scheme(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%/' );
		add_filter(
			'woocommerce_pos_general_settings',
			function ( $settings ) {
				$settings['force_ssl'] = false;

				return $settings;
			}
		);

		// Act.
		$auth_url = Template_Router::get_auth_url();

		// Assert.
		$this->assertStringStartsWith( 'http://', $auth_url );
		$this->assertStringEndsWith( '/wcpos-auth/', $auth_url );
	}

	/**
	 * Order received URL should preserve the home URL scheme when the
	 * force_ssl setting is disabled.
	 */
	public function test_order_received_url_force_ssl_disabled_preserves_home_scheme(): void {
		// Arrange.
		global $wp;
		add_filter(
			'woocommerce_pos_general_settings',
			function ( $settings ) {
				$settings['force_ssl'] = false;

				return $settings;
			}
		);
		$order                       = OrderHelper::create_order();
		$wp->query_vars['wcpos']     = 1;
		$wp->query_vars['order-pay'] = $order->get_id();
		$router                      = new Template_Router();

		// Act.
		$redirect = $router->order_received_url( get_home_url( null, '/checkout/order-received/' ), $order );

		// Assert.
		$this->assertStringStartsWith( 'http://', $redirect );
		$this->assertStringContainsString( '/wcpos-checkout/order-received/' . $order->get_id(), $redirect );
	}

	/**
	 * The POS checkout rewrite rule accepts an optional trailing slash, so both
	 * the slashed URLs we now generate and legacy slash-less URLs must resolve
	 * to the same order-pay request.
	 */
	public function test_checkout_rewrite_resolves_with_and_without_trailing_slash(): void {
		// Arrange: pretty permalinks with trailing slashes, POS rewrite rules registered.
		global $wp_rewrite;
		$this->set_permalink_structure( '/%postname%/' );
		new Template_Router();
		$wp_rewrite->flush_rules();

		foreach ( array( '/wcpos-checkout/order-pay/123/', '/wcpos-checkout/order-pay/123' ) as $path ) {
			// Act.
			$this->go_to( $path );

			// Assert: go_to() replaces the global $wp instance, so read it via $GLOBALS.
			$this->assertEquals(
				'^wcpos-checkout/([a-z-]+)/([0-9]+)[/]?$',
				$GLOBALS['wp']->matched_rule,
				"URL {$path} did not match the POS checkout rewrite rule."
			);
			$this->assertEquals(
				'123',
				$GLOBALS['wp']->query_vars['order-pay'],
				"URL {$path} did not resolve the order-pay query var."
			);
		}
	}

	/**
	 * The retired login path is a normal 404 with no POS frame exemption.
	 */
	public function test_retired_login_path_resolves_to_404_without_frame_exemption(): void {
		// Arrange.
		global $wp_rewrite;
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$this->set_permalink_structure( '/%postname%/' );
		new Template_Router();
		$wp_rewrite->flush_rules();

		// Act.
		$this->go_to( '/wcpos-login/' );

		// Assert: go_to() replaces the global $wp instance, so read it via $GLOBALS.
		$this->assertNotSame( '^wcpos-login/?', $GLOBALS['wp']->matched_rule );
		$this->assertArrayNotHasKey( 'wcpos-login', $GLOBALS['wp']->query_vars );
		$this->assertTrue( is_404() );
		// This is the frame-exemption predicate; headers_list() is always empty under the CLI SAPI.
		$this->assertFalse( woocommerce_pos_request() );
	}

	/**
	 * Cached rules that still route the retired login page must be regenerated.
	 */
	public function test_rewrite_rules_cache_holding_retired_login_rule_is_rejected(): void {
		// Arrange.
		global $wp_rewrite;
		$this->set_permalink_structure( '/%postname%/' );
		$router = new Template_Router();
		$wp_rewrite->flush_rules();
		$live  = $router->rewrite_rules( get_option( 'rewrite_rules' ) );
		$stale = $live + array(
			'^wcpos-login/?' => 'index.php?wcpos-login=1',
		);

		// Act and Assert.
		$this->assertSame( $live, $router->rewrite_rules( $live ) );
		$this->assertFalse( $router->rewrite_rules( $stale ) );
	}

	/**
	 * Only the exact auth path routes to the auth template; a lookalike is a plain 404.
	 *
	 * WordPress matches rewrite regexes as prefixes, so an unanchored rule routed
	 * /wcpos-authx/ to the auth template while the page-cache kill check refused it.
	 */
	public function test_auth_rewrite_matches_only_the_exact_path(): void {
		// Arrange.
		global $wp_rewrite;
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$this->set_permalink_structure( '/%postname%/' );
		$router = new Template_Router();
		$wp_rewrite->flush_rules();
		$rules = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^wcpos-auth/?$', $rules );
		$this->assertArrayNotHasKey( '^wcpos-auth/?', $rules );

		foreach ( array( '/wcpos-auth/', '/wcpos-auth' ) as $path ) {
			// Act: go_to() runs WP::main() (routing) but not template-loader.php, so nothing renders.
			$this->go_to( $path );

			// Assert: go_to() replaces the global $wp instance, so read it via $GLOBALS.
			$this->assertSame( '^wcpos-auth/?$', $GLOBALS['wp']->matched_rule, "{$path} did not match the auth rule." );
			$this->assertSame( '1', $GLOBALS['wp']->query_vars['wcpos-auth'], "{$path} did not set the auth query var." );
		}

		foreach ( array( '/wcpos-authx/', '/wcpos-authentication/', '/wcpos-auth/extra/' ) as $path ) {
			// Act.
			$this->go_to( $path );

			// Assert.
			$this->assertNotSame( '^wcpos-auth/?$', $GLOBALS['wp']->matched_rule, "{$path} matched the auth rule." );
			$this->assertArrayNotHasKey( 'wcpos-auth', $GLOBALS['wp']->query_vars, "{$path} set the auth query var." );
			$this->assertTrue( is_404(), "{$path} is not a 404." );
		}

		// A cache that still holds the unanchored rule is stale.
		$stale = $rules;
		unset( $stale['^wcpos-auth/?$'] );
		$stale['^wcpos-auth/?'] = 'index.php?wcpos-auth=1';
		$this->assertFalse( $router->rewrite_rules( $stale ) );
	}
}
