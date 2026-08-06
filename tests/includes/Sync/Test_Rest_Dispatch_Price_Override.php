<?php
/**
 * Route-dispatch pins for POS price overrides on the v2 order push (#1456).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact parity scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Admin_Settings;
use WC_Product;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Price_Override extends Sync_REST_Store_Test_Case {
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );

		TaxHelper::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '20.000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'state'    => 'AL',
				'postcode' => '12345; 123456',
				'rate'     => '2.000',
				'name'     => 'US AL',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
			)
		);
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	private function taxable_product(): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
	}

	private function price_line( WC_Product $product, string $price ): array {
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => 1,
			'subtotal'   => $price,
			'total'      => $price,
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_data',
					'value' => wp_json_encode(
						array(
							'price'         => $price,
							'regular_price' => '10',
						)
					),
				),
			),
		);
	}

	private function push_order( string $operation, string $record_id, array $payload, ?string $base_revision = null ): WP_REST_Response {
		$envelope = array(
			'mutationId'   => wp_generate_uuid4(),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => $record_id,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function assert_order_amounts( WP_REST_Response $response, float $line_total, float $line_tax, float $order_total ): void {
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$lines = array_values( $order->get_items( 'line_item' ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( $line_total, (float) $lines[0]->get_subtotal() );
		$this->assertSame( $line_total, (float) $lines[0]->get_total() );
		$this->assertSame( $line_tax, (float) $lines[0]->get_total_tax() );
		$this->assertSame( $line_tax, (float) $order->get_total_tax() );
		$this->assertSame( $order_total, (float) $order->get_total() );
	}

	public function test_create_persists_price_override_below_catalog_and_taxes_it(): void {
		$product  = $this->taxable_product();
		$response = $this->push_order(
			'create',
			wp_generate_uuid4(),
			array( 'line_items' => array( $this->price_line( $product, '8' ) ) )
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assert_order_amounts( $response, 8.0, 0.8, 8.8 );
	}

	public function test_create_persists_price_override_above_catalog_and_taxes_it(): void {
		$product  = $this->taxable_product();
		$response = $this->push_order(
			'create',
			wp_generate_uuid4(),
			array( 'line_items' => array( $this->price_line( $product, '12' ) ) )
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assert_order_amounts( $response, 12.0, 1.2, 13.2 );
	}

	public function test_update_full_document_persists_changed_price_override(): void {
		$product   = $this->taxable_product();
		$record_id = wp_generate_uuid4();
		$created   = $this->push_order(
			'create',
			$record_id,
			array( 'line_items' => array( $this->price_line( $product, '8' ) ) )
		);
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$payload                             = $created->get_data()['document'];
		$payload['line_items'][0]['subtotal'] = '12';
		$payload['line_items'][0]['total']    = '12';
		foreach ( $payload['line_items'][0]['meta_data'] as &$meta ) {
			if ( '_woocommerce_pos_data' === $meta['key'] ) {
				$meta['value'] = wp_json_encode(
					array(
						'price' => '12',
						'regular_price' => '10',
					)
				);
			}
		}
		unset( $meta );

		$updated = $this->push_order( 'update', $record_id, $payload, $created->get_data()['currentRevision'] );

		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assert_order_amounts( $updated, 12.0, 1.2, 13.2 );
	}

	public function test_created_via_and_pos_tax_location_survive_ignored_wc_payload_parameter(): void {
		$strip_created_via = static function ( $result, $server, WP_REST_Request $request ) {
			if ( '/wc/v3/orders' === $request->get_route() && 'POST' === $request->get_method() ) {
				unset( $request['created_via'] );
			}

			return $result;
		};
		add_filter( 'rest_pre_dispatch', $strip_created_via, 9, 3 );
		try {
			$response = $this->push_order(
				'create',
				wp_generate_uuid4(),
				array(
					'line_items' => array( $this->price_line( $this->taxable_product(), '8' ) ),
					'billing'    => array( 'country' => 'GB' ),
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_tax_based_on',
							'value' => 'billing',
						),
					),
				)
			);
		} finally {
			remove_filter( 'rest_pre_dispatch', $strip_created_via, 9 );
		}

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 'woocommerce-pos', $order->get_created_via() );
		$this->assertSame( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );
		$this->assert_order_amounts( $response, 8.0, 1.6, 9.6 );
		$tax_lines = array_values( $order->get_items( 'tax' ) );
		$this->assertCount( 1, $tax_lines );
		$this->assertSame( 'VAT', $tax_lines[0]->get_label() );
	}

	public function test_pos_create_filter_does_not_leak_into_plain_wc_v3_create(): void {
		$product = $this->taxable_product();
		$pos     = $this->push_order(
			'create',
			wp_generate_uuid4(),
			array( 'line_items' => array( $this->price_line( $product, '8' ) ) )
		);
		$this->assertSame( 201, $pos->get_status(), wp_json_encode( $pos->get_data() ) );

		unset( $_SERVER['HTTP_X_WCPOS'] );
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
			)
		);
		$plain = $this->server->dispatch( $request );
		$_SERVER['HTTP_X_WCPOS'] = '1';

		$this->assertSame( 201, $plain->get_status(), wp_json_encode( $plain->get_data() ) );
		$this->assertSame( 'rest-api', wc_get_order( (int) $plain->get_data()['id'] )->get_created_via() );
	}
}
