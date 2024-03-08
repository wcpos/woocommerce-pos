<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order_Item_Fee;
use WCPOS\WooCommercePOS\API\Orders_Controller;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;

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
		$this->assertEquals( '1.00', $data['tax_lines'][0]['tax_total'] );
		$this->assertEquals( 'US', $data['tax_lines'][0]['label'] );
		$this->assertEquals( '10', $data['tax_lines'][0]['rate_percent'] );
	}

	/**
	 * Create a new order with customer billing address as tax location.
	 */
	public function test_create_order_with_customer_billing_address_as_tax_location(): void {
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
		$this->assertEquals( '2.00', $data['tax_lines'][0]['tax_total'] );
		$this->assertEquals( 'VAT', $data['tax_lines'][0]['label'] );
		$this->assertEquals( '20', $data['tax_lines'][0]['rate_percent'] );
	}
}
