<?php
/**
 * Site discovery endpoint tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Tests the public site discovery endpoint.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Site
 */
class Test_Site extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Anonymous requests receive the discovery payload.
	 */
	public function test_anonymous_get_returns_site_discovery_payload(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( wcpos_get_site_uuid(), $data['uuid'] );
		$this->assertEquals( get_bloginfo( 'name' ), $data['name'] );
		$this->assertEquals( get_option( 'siteurl' ), $data['url'] );
		$this->assertEquals( get_bloginfo( 'version' ), $data['wp_version'] );
		$this->assertEquals( VERSION, $data['wcpos_version'] );
		$this->assertContains( 'wcpos/v2', $data['namespaces'] );
		$this->assertNotEmpty( $data['authentication']['wcpos']['endpoints']['authorization'] );
	}

	/**
	 * WooCommerce discovery data is present when WooCommerce is active.
	 */
	public function test_woocommerce_version_and_namespace_are_present(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) );
		$data     = $response->get_data();

		$this->assertEquals( WC()->version, $data['wc_version'] );
		$this->assertContains( 'wc/v3', $data['namespaces'] );
	}

	/**
	 * Extensions can add fields to the discovery payload.
	 */
	/** The payload lists only the meta keys a store ADDED to search, and only ones REST exposes. */
	public function test_search_meta_keys_lists_filter_additions_the_client_can_read(): void {
		wp_set_current_user( 0 );
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) );
		$this->assertSame(
			array(
				'customers' => array(),
				'orders' => array(),
			),
			$response->get_data()['search_meta_keys']
		);

		$filter = static function ( $search, $collection ) {
			if ( 'customers' === $collection ) {
				$search['meta'][] = 'loyalty_number';
				$search['meta'][] = '_hidden_internal';
				$search['meta'][] = 'first_name'; // Already declared: not an addition.
				$search['meta'][] = array( 'not', 'a', 'key' ); // A broken callback must not 500 discovery.
				$search['meta'][] = new \stdClass();
			}
			if ( 'orders' === $collection ) {
				$search['posts']['meta'][] = 'delivery_slot';
			}
			return $search;
		};
		add_filter( 'woocommerce_pos_search_fields', $filter, 10, 2 );
		try {
			$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) );
			$this->assertSame(
				array(
					'customers' => array( 'loyalty_number' ),
					'orders' => array( 'delivery_slot' ),
				),
				$response->get_data()['search_meta_keys']
			);
		} finally {
			remove_filter( 'woocommerce_pos_search_fields', $filter, 10 );
		}
	}

	public function test_site_info_filter_can_add_a_field(): void {
		$filter = static function ( array $data ): array {
			$data['extension_field'] = 'extension-value';

			return $data;
		};
		add_filter( 'wcpos_rest_site_info', $filter );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) );

		remove_filter( 'wcpos_rest_site_info', $filter );
		$this->assertEquals( 'extension-value', $response->get_data()['extension_field'] );
	}
}
