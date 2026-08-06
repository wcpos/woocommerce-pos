<?php
/**
 * Tests for the global WCPOS helper functions.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Test_Wcpos_Functions class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Wcpos_Functions extends WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setup(): void {
		parent::setup();

		global $wp;

		unset( $wp->query_vars['wcpos'], $wp->query_vars['rest_route'], $_SERVER['HTTP_X_WCPOS'] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;

		// The test framework only resets permalinks for core tests, so undo
		// set_permalink_structure() here or it leaks into later test classes.
		$this->set_permalink_structure( '' );
		unset( $wp->query_vars['wcpos'], $wp->query_vars['rest_route'], $_SERVER['HTTP_X_WCPOS'] );

		parent::tearDown();
	}

	/**
	 * A v1 REST route is detected without either legacy request marker.
	 */
	public function test_wcpos_request_v1_rest_route_without_markers_returns_true(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['rest_route'] = '/wcpos/v1/orders/1';

		// Act.
		$is_wcpos_request = wcpos_request();
		$is_rest_route    = wcpos_request( 'rest_route' );

		// Assert.
		$this->assertEquals( true, $is_wcpos_request );
		$this->assertEquals( true, $is_rest_route );
	}

	/**
	 * A v2 REST route is detected by its namespace.
	 */
	public function test_wcpos_request_v2_rest_route_returns_true(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['rest_route'] = '/wcpos/v2/changes/tick';

		// Act.
		$is_rest_route = wcpos_request( 'rest_route' );

		// Assert.
		$this->assertEquals( true, $is_rest_route );
	}

	/**
	 * A WCPOS REST route without a leading slash is normalized and detected.
	 */
	public function test_wcpos_request_rest_route_without_leading_slash_returns_true(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['rest_route'] = 'wcpos/v1/orders/1';

		// Act.
		$is_rest_route = wcpos_request( 'rest_route' );

		// Assert.
		$this->assertEquals( true, $is_rest_route );
	}

	/**
	 * A WooCommerce REST route is not classified as a WCPOS request.
	 */
	public function test_wcpos_request_wc_rest_route_returns_false(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['rest_route'] = '/wc/v3/orders';

		// Act.
		$is_wcpos_request = wcpos_request();
		$is_rest_route    = wcpos_request( 'rest_route' );

		// Assert.
		$this->assertEquals( false, $is_wcpos_request );
		$this->assertEquals( false, $is_rest_route );
	}

	/**
	 * An unset REST route is handled without classifying the request.
	 */
	public function test_wcpos_request_unset_rest_route_returns_false(): void {
		// Arrange.
		global $wp;

		unset( $wp->query_vars['rest_route'] );

		// Act.
		$is_wcpos_request = wcpos_request();
		$is_rest_route    = wcpos_request( 'rest_route' );

		// Assert.
		$this->assertEquals( false, $is_wcpos_request );
		$this->assertEquals( false, $is_rest_route );
	}

	/**
	 * Specific legacy marker checks ignore a matching REST route.
	 */
	public function test_wcpos_request_matching_rest_route_keeps_legacy_branches_isolated(): void {
		global $wp;

		// Arrange.
		$wp->query_vars['rest_route'] = '/wcpos/v1/orders/1';

		// Act.
		$is_header    = wcpos_request( 'header' );
		$is_query_var = wcpos_request( 'query_var' );

		// Assert.
		$this->assertEquals( false, $is_header );
		$this->assertEquals( false, $is_query_var );
	}

	/**
	 * Test getting general settings.
	 */
	public function test_woocommerce_pos_get_general_settings(): void {
		$general_settings = woocommerce_pos_get_settings( 'general' );
		$this->assertIsArray( $general_settings );
		$this->assertArrayHasKey( 'pos_only_products', $general_settings );
		$this->assertArrayHasKey( 'decimal_qty', $general_settings );
		$this->assertArrayHasKey( 'default_customer', $general_settings );
		$this->assertArrayHasKey( 'default_customer_is_cashier', $general_settings );
		$this->assertArrayHasKey( 'barcode_field', $general_settings );
		$this->assertArrayHasKey( 'generate_username', $general_settings );

		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$this->assertEquals( '_global_unique_id', $barcode_field );
	}

	/**
	 * Test getting general settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_general_settings_with_invalid_key(): void {
		$general_settings = woocommerce_pos_get_settings( 'general', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $general_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test getting checkout settings.
	 */
	public function test_woocommerce_pos_get_checkout_settings(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );
		$this->assertIsArray( $checkout_settings );
		$this->assertArrayNotHasKey( 'order_status', $checkout_settings );
		$this->assertArrayHasKey( 'admin_emails', $checkout_settings );
		$this->assertArrayHasKey( 'customer_emails', $checkout_settings );
	}

	/**
	 * Test getting checkout settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_checkout_settings_with_invalid_key(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $checkout_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test getting payment gateways settings.
	 */
	public function test_woocommerce_pos_get_payment_gateways_settings(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );
		$this->assertIsArray( $payment_gateways_settings );
		$this->assertArrayHasKey( 'default_gateway', $payment_gateways_settings );
		$this->assertArrayHasKey( 'gateways', $payment_gateways_settings );

		$active_gateways = array_filter(
			$payment_gateways_settings['gateways'],
			function ( $gateway ) {
				return $gateway['enabled'];
			}
		);
		$this->assertEquals( count( $active_gateways ), 2 );

		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'default_gateway' );
		$this->assertEquals( 'pos_cash', $payment_gateways_settings );
	}

	/**
	 * Test getting payment gateways settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_payment_gateways_settings_with_invalid_key(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $payment_gateways_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test checkout URL uses https when force_ssl is enabled (the default).
	 */
	public function test_wcpos_checkout_url_force_ssl_enabled_returns_https(): void {
		// Arrange: force_ssl defaults to true; test site home URL is http.
		$this->assertStringStartsWith( 'http://', get_home_url() );

		// Act.
		$url = wcpos_checkout_url( 'order-pay/123' );

		// Assert.
		$this->assertStringStartsWith( 'https://', $url );
		$this->assertStringEndsWith( '/wcpos-checkout/order-pay/123', $url );
	}

	/**
	 * Test checkout URL preserves the home URL scheme when force_ssl is disabled.
	 */
	public function test_wcpos_checkout_url_force_ssl_disabled_preserves_home_scheme(): void {
		// Arrange.
		add_filter(
			'woocommerce_pos_general_settings',
			function ( $settings ) {
				$settings['force_ssl'] = false;

				return $settings;
			}
		);

		// Act.
		$url = wcpos_checkout_url( 'order-pay/123' );

		// Assert.
		$this->assertStringStartsWith( 'http://', $url );
		$this->assertStringEndsWith( '/wcpos-checkout/order-pay/123', $url );
	}

	/**
	 * Test checkout URL ends with a trailing slash when the permalink structure
	 * uses trailing slashes, so it doesn't trip origin rewrite rules that
	 * force a trailing slash (see wcpos_checkout_url()).
	 */
	public function test_wcpos_checkout_url_trailing_slash_permalinks_ends_with_slash(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%/' );

		// Act.
		$url = wcpos_checkout_url( 'order-pay/123' );

		// Assert.
		$this->assertStringEndsWith( '/wcpos-checkout/order-pay/123/', $url );
	}

	/**
	 * Test checkout URL keeps no trailing slash when the permalink structure
	 * has no trailing slash.
	 */
	public function test_wcpos_checkout_url_no_slash_permalinks_ends_without_slash(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%' );

		// Act.
		$url = wcpos_checkout_url( 'order-pay/123' );

		// Assert.
		$this->assertStringEndsWith( '/wcpos-checkout/order-pay/123', $url );
		$this->assertStringEndsNotWith( '/', $url );
	}

	/**
	 * Test POS URL ends with a trailing slash when the permalink structure
	 * uses trailing slashes.
	 */
	public function test_wcpos_url_trailing_slash_permalinks_ends_with_slash(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%/' );

		// Act & Assert.
		$this->assertStringEndsWith( '/pos/', wcpos_url() );
		$this->assertStringEndsWith( '/pos/cashiers/', wcpos_url( 'cashiers' ) );
	}

	/**
	 * Test POS URL keeps no trailing slash when the permalink structure has no
	 * trailing slash.
	 */
	public function test_wcpos_url_no_slash_permalinks_ends_without_slash(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%' );

		// Act & Assert.
		$this->assertStringEndsWith( '/pos', wcpos_url() );
		$this->assertStringEndsWith( '/pos/cashiers', wcpos_url( 'cashiers' ) );
	}

	/**
	 * Test getting license settings.
	 */
	public function test_woocommerce_pos_get_license_settings(): void {
		$license_settings = woocommerce_pos_get_settings( 'license' );
		$this->assertIsArray( $license_settings );

		$license_settings = woocommerce_pos_get_settings( 'license', 'key' );
		$this->assertInstanceOf( 'WP_Error', $license_settings, 'The result should be a WP_Error instance.' );
	}
}
