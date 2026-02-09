<?php
/**
 * Tests for the Settings API endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Test_Settings_API class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Settings_API extends WP_UnitTestCase {
	/**
	 * Settings API instance.
	 *
	 * @var Settings
	 */
	private $api;

	/**
	 * Set up test fixtures.
	 */
	public function setup(): void {
		$this->api = new Settings();
		parent::setup();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Create a mock WP_REST_Request with the given params.
	 *
	 * @param array $params JSON params to return.
	 *
	 * @return WP_REST_Request The mocked request.
	 */
	public function mock_rest_request( array $params = array() ) {
		$request = $this->getMockBuilder( 'WP_REST_Request' )
			->setMethods( array( 'get_json_params' ) )
			->getMock();

		$request->method( 'get_json_params' )
			->willReturn( $params );

		return $request;
	}

	/**
	 * Test default general settings.
	 */
	public function test_get_general_default_settings(): void {
		$response = $this->api->get_general_settings( $this->mock_rest_request() );
		$settings = $response->get_data();
		$this->assertTrue( $settings['force_ssl'] );
		$this->assertFalse( $settings['pos_only_products'] );
		$this->assertTrue( $settings['generate_username'] );
		$this->assertFalse( $settings['default_customer_is_cashier'] );
		$this->assertEquals( 0, $settings['default_customer'] );
		$this->assertEquals( '_sku', $settings['barcode_field'] );
	}

	/**
	 * Test default checkout settings.
	 */
	public function test_get_checkout_default_settings(): void {
		$response = $this->api->get_checkout_settings( $this->mock_rest_request() );
		$settings = $response->get_data();
		$this->assertEquals( 'wc-completed', $settings['order_status'] );
		$this->assertIsArray( $settings['admin_emails'] );
		$this->assertTrue( $settings['admin_emails']['enabled'] );
		$this->assertIsArray( $settings['customer_emails'] );
		$this->assertTrue( $settings['customer_emails']['enabled'] );
		$this->assertIsArray( $settings['cashier_emails'] );
		$this->assertFalse( $settings['cashier_emails']['enabled'] );
	}

	/**
	 * Test default payment gateways settings.
	 */
	public function test_get_payment_gateways_default_settings(): void {
		$response = $this->api->get_payment_gateways_settings( $this->mock_rest_request() );
		$settings = $response->get_data();
		$this->assertEquals( 'pos_cash', $settings['default_gateway'] );
		$this->assertIsArray( $settings['gateways'] );

		$gateways = $settings['gateways'];
		$this->assertTrue( $gateways['pos_cash']['enabled'] );
		$this->assertEquals( 0, $gateways['pos_cash']['order'] );
	}

	/**
	 * Test default access settings.
	 */
	public function test_get_access_default_settings(): void {
		$response      = $this->api->get_access_settings( $this->mock_rest_request() );
		$settings      = $response->get_data();
		$administrator = $settings['administrator'];

		$this->assertTrue( $administrator['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $administrator['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * Test updating access settings.
	 */
	public function test_update_access_settings(): void {
		$request = $this->mock_rest_request(
			array(
				'administrator' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => false,
						),
					),
				),
			)
		);
		$response = $this->api->update_access_settings( $request );
		$this->assertFalse( $response['administrator']['capabilities']['wcpos']['access_woocommerce_pos'] );

		$request = $this->mock_rest_request(
			array(
				'administrator' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);
		$response = $this->api->update_access_settings( $request );
		$this->assertTrue( $response['administrator']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * Test default license settings.
	 */
	public function test_get_license_default_settings(): void {
		$response = $this->api->get_license_settings( $this->mock_rest_request() );
		$settings = $response->get_data();
		$this->assertEmpty( $settings );
	}

	/**
	 * Test updating general settings.
	 */
	public function test_update_general_settings(): void {
		$request  = $this->mock_rest_request( array( 'pos_only_products' => false ) );
		$response = $this->api->update_general_settings( $request );
		$this->assertFalse( $response['pos_only_products'] );

		$request  = $this->mock_rest_request( array( 'pos_only_products' => true ) );
		$response = $this->api->update_general_settings( $request );
		$this->assertTrue( $response['pos_only_products'] );
	}

	/**
	 * Test updating checkout settings with array email format.
	 */
	public function test_update_checkout_settings(): void {
		$disabled_emails = array(
			'enabled'         => false,
			'new_order'       => true,
			'cancelled_order' => true,
			'failed_order'    => true,
		);
		$request         = $this->mock_rest_request( array( 'admin_emails' => $disabled_emails ) );
		$response        = $this->api->update_checkout_settings( $request );
		$this->assertIsArray( $response['admin_emails'] );
		$this->assertFalse( $response['admin_emails']['enabled'] );

		$enabled_emails = array(
			'enabled'         => true,
			'new_order'       => true,
			'cancelled_order' => true,
			'failed_order'    => true,
		);
		$request        = $this->mock_rest_request( array( 'admin_emails' => $enabled_emails ) );
		$response       = $this->api->update_checkout_settings( $request );
		$this->assertIsArray( $response['admin_emails'] );
		$this->assertTrue( $response['admin_emails']['enabled'] );
	}

	/**
	 * Test updating payment gateways settings.
	 */
	public function test_update_payment_gateways_settings(): void {
		$request  = $this->mock_rest_request( array( 'default_gateway' => 'pos_cash' ) );
		$response = $this->api->update_payment_gateways_settings( $request );
		$this->assertEquals( 'pos_cash', $response['default_gateway'] );

		$request  = $this->mock_rest_request( array( 'default_gateway' => 'pos_card' ) );
		$response = $this->api->update_payment_gateways_settings( $request );
		$this->assertEquals( 'pos_card', $response['default_gateway'] );

		$request = $this->mock_rest_request(
			array(
				'gateways' => array(
					'pos_cash' => array(
						'enabled' => false,
					),
				),
			)
		);
		$response = $this->api->update_payment_gateways_settings( $request );
		$this->assertFalse( $response['gateways']['pos_cash']['enabled'] );
	}
}
