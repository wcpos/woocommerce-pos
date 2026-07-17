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
		$order                       = OrderHelper::create_order();
		$wp->query_vars['wcpos']     = 1;
		$wp->query_vars['order-pay'] = $order->get_id();
		$router                      = new Template_Router();

		// Act.
		$redirect = $router->order_received_url( get_home_url( null, '/checkout/order-received/' ), $order );

		// Assert.
		$this->assertStringStartsWith( 'https://', $redirect );
		$this->assertStringContainsString( '/wcpos-checkout/order-received/' . $order->get_id(), $redirect );
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

		// Act.
		$auth_url = Template_Router::get_auth_url();

		// Assert.
		$this->assertStringStartsWith( 'https://', $auth_url );
		$this->assertStringEndsWith( '/wcpos-auth/', $auth_url );
	}

	/**
	 * Auth URL should preserve the home URL scheme when the force_ssl setting
	 * is disabled.
	 */
	public function test_get_auth_url_force_ssl_disabled_preserves_home_scheme(): void {
		// Arrange.
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
}
