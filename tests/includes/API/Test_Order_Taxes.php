<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Orders_Controller;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WC_Admin_Settings;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Order_Taxes extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Orders_Controller();
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );

		// Set default address
		// update_option( 'woocommerce_default_country', 'GB' );

		/**
		 * Init Taxes
		 *
		 * use WooCommerce Tax Dummy Data
		 */
		TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '20.000',
				'name'    => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '5.000',
				'name'    => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'reduced-rate',
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '0.000',
				'name'    => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'zero-rate',
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'rate'    => '10.000',
				'name'    => 'US',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'AL',
				'postcode' => '12345; 123456',
				'rate'    => '2.000',
				'name'    => 'US AL',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
			)
		);
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Create a new order.
	 */
	public function test_create_order_with_tax(): void {
		$this->assertEquals( 'base', WC_Admin_Settings::get_option( 'woocommerce_tax_based_on' ) );
		$this->assertEquals( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'line_items'     => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'price'      => 10,
						'total'      => '10.00',
					),
				),
				'billing' => array(
					'email'      => '',
					'first_name' => '',
					'last_name'  => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => '',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
					'phone'      => '',
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		// line item taxes
		$this->assertEquals( 1, \count( $data['line_items'] ) );
		$this->assertEquals( 1, \count( $data['line_items'][0]['taxes'] ) );
		$this->assertEquals( '1', $data['line_items'][0]['taxes'][0]['total'] );

		// order taxes
		$this->assertEquals( 1, \count( $data['tax_lines'] ) );
		$this->assertEquals( '1.000000', $data['tax_lines'][0]['tax_total'] );
		$this->assertEquals( 'US', $data['tax_lines'][0]['label'] );
		$this->assertEquals( '10', $data['tax_lines'][0]['rate_percent'] );
	}

	/**
	 * Create a new order with customer billing address as tax location.
	 */
	public function test_create_order_with_customer_billing_address_as_tax_location(): void {
		$this->assertEquals( 'base', WC_Admin_Settings::get_option( 'woocommerce_tax_based_on' ) );
		$this->assertEquals( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		// Prepare your data as an array and then JSON-encode it
		$data = array(
			'payment_method' => 'pos_cash',
			'line_items'     => array(
				array(
					'product_id' => 1,
					'quantity'   => 1,
					'price'      => 10,
					'total'      => '10.00',
				),
			),
			'billing' => array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
				'address_1'  => '',
				'address_2'  => '',
				'city'       => '',
				'state'      => '',
				'postcode'   => '',
				'country'    => 'GB',
				'phone'      => '',
			),
			'meta_data' => array(
				array(
					'key'   => '_woocommerce_pos_tax_based_on',
					'value' => 'billing',
				),
			),
		);

		// Set the body to a JSON string
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $data ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		// check meta data
		$count      = 0;
		$tax_based_on = '';

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_tax_based_on' === $meta['key'] ) {
				$count++;
				$tax_based_on = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_tax_based_on.' );
		$this->assertEquals( 'billing', $tax_based_on, 'The value of _woocommerce_pos_tax_based_on should be billing.' );

		// line item taxes
		$this->assertEquals( 1, \count( $data['line_items'] ) );
		$this->assertEquals( 1, \count( $data['line_items'][0]['taxes'] ) );
		$this->assertEquals( '2', $data['line_items'][0]['taxes'][0]['total'] );

		// order taxes
		$this->assertEquals( 1, \count( $data['tax_lines'] ) );
		$this->assertEquals( '2.000000', $data['tax_lines'][0]['tax_total'] );
		$this->assertEquals( 'VAT', $data['tax_lines'][0]['label'] );
		$this->assertEquals( '20', $data['tax_lines'][0]['rate_percent'] );
	}

	/**
	 * Create a new order with customer billing address as tax location.
	 */
	public function test_create_order_with_customer_shipping_address_as_tax_location(): void {
		$this->assertEquals( 'base', WC_Admin_Settings::get_option( 'woocommerce_tax_based_on' ) );
		$this->assertEquals( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		// Prepare your data as an array and then JSON-encode it
		$data = array(
			'payment_method' => 'pos_cash',
			'line_items'     => array(
				array(
					'product_id' => 1,
					'quantity'   => 1,
					'price'      => 10,
					'total'      => '10.00',
				),
			),
			'shipping' => array(
				'country'    => 'US',
				'state'      => 'AL',
				'postcode'   => '12345',
			),
			'meta_data' => array(
				array(
					'key'   => '_woocommerce_pos_tax_based_on',
					'value' => 'shipping',
				),
			),
		);

		// Set the body to a JSON string
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $data ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		// check meta data
		$count      = 0;
		$tax_based_on = '';

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_tax_based_on' === $meta['key'] ) {
				$count++;
				$tax_based_on = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_tax_based_on.' );
		$this->assertEquals( 'shipping', $tax_based_on, 'The value of _woocommerce_pos_tax_based_on should be billing.' );

		// line item taxes
		$this->assertEquals( 1, \count( $data['line_items'] ) );
		$this->assertEquals( 2, \count( $data['line_items'][0]['taxes'] ) );
		$this->assertEquals( '1', $data['line_items'][0]['taxes'][0]['total'] );
		$this->assertEquals( '0.22', $data['line_items'][0]['taxes'][1]['total'] );

		// order taxes
		$this->assertEquals( 2, \count( $data['tax_lines'] ) );
		$this->assertEquals( '1.000000', $data['tax_lines'][0]['tax_total'] );
		$this->assertEquals( 'US', $data['tax_lines'][0]['label'] );
		$this->assertEquals( '10', $data['tax_lines'][0]['rate_percent'] );
		$this->assertEquals( '0.220000', $data['tax_lines'][1]['tax_total'] );
		$this->assertEquals( 'US AL', $data['tax_lines'][1]['label'] );
		$this->assertEquals( '2', $data['tax_lines'][1]['rate_percent'] );

		// order total
		$this->assertEquals( '11.220000', $data['total'] );
		$this->assertEquals( '1.220000', $data['cart_tax'] );
		$this->assertEquals( '1.220000', $data['total_tax'] );
	}

	/**
	 *
	 */
	public function test_fee_lines_should_respect_tax_status_when_negative() {
		$this->assertEquals( 'base', WC_Admin_Settings::get_option( 'woocommerce_tax_based_on' ) );
		$this->assertEquals( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'line_items'     => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'price'      => 20,
						'total'      => '20.00',
					),
				),
				'fee_lines'     => array(
					array(
						'name' => 'Fee',
						'total' => '-10',
						'tax_status' => 'none',
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		// fee line taxes
		$this->assertEquals( 1, \count( $data['fee_lines'] ) );
		$this->assertEquals( '-10.000000', $data['fee_lines'][0]['total'] );
		$this->assertEquals( '0.000000', $data['fee_lines'][0]['total_tax'] );
		$this->assertEquals( 0, \count( $data['fee_lines'][0]['taxes'] ) );

		// order taxes
		$this->assertEquals( 1, \count( $data['tax_lines'] ) );
		$this->assertEquals( '2.000000', $data['total_tax'] );
		$this->assertEquals( '12.000000', $data['total'] );
	}
}
