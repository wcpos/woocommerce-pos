<?php
/**
 * Tests for V2 catalog proxy customer searches.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * V2 catalog proxy customer search tests.
 */
class Test_Catalog_Proxy_Customers extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Customer used by each search test.
	 *
	 * @var \WC_Customer
	 */
	private $customer;

	/**
	 * Enable sync routes and create the customer fixture.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		$this->customer = CustomerHelper::create_customer(
			array(
				'first_name'      => 'Jane',
				'last_name'       => 'Smith',
				'email'           => 'v2.multiword.customer@example.com',
				'billing_company' => 'Acme Consulting',
			)
		);
	}

	/**
	 * Restore database state after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Multi-word searches match terms across customer fields.
	 *
	 * @dataProvider multi_word_search_provider
	 *
	 * @param string $search Search string.
	 */
	public function test_multi_word_search_matches_customer( string $search ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => $search,
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $this->customer->get_id(), $data[0]['id'] );
	}

	/**
	 * Single-word searches also use the per-term filter (V1 parity) and match name fields.
	 */
	public function test_single_word_search_matches_customer(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => 'Jane',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $this->customer->get_id(), $data[0]['id'] );
	}

	/**
	 * Multi-word customer search cases.
	 *
	 * @return array<string, array{string}>
	 */
	public function multi_word_search_provider(): array {
		return array(
			'full name'       => array( 'Jane Smith' ),
			'reversed name'   => array( 'Smith Jane' ),
			'billing company' => array( 'Acme Consulting' ),
		);
	}
}
