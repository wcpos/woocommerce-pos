<?php
/**
 * Tests for the v2 catalog proxy tax-rate contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;

/**
 * Cashier-facing tax-rate reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Taxes extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/**
	 * Remove the sync feature flag written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Cashiers receive the complete wc/v3 tax-rate representation.
	 */
	public function test_cashier_tax_rate_row_has_full_v2_field_set(): void {
		$tax_id = TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'state'    => 'NY',
				'postcode' => '10001; 10002',
				'city'     => 'New York; Albany',
				'rate'     => '8.375',
				'name'     => 'NY Tax',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
				'order'    => 3,
			)
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();
		$row      = current(
			array_values(
				array_filter(
					$rows,
					static fn( array $candidate ): bool => $tax_id === (int) $candidate['id']
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotFalse( $row );
		$this->assertEqualsCanonicalizing(
			array(
				'id',
				'country',
				'state',
				'postcode',
				'postcodes',
				'city',
				'cities',
				'rate',
				'name',
				'priority',
				'compound',
				'shipping',
				'order',
				'class',
				'_links',
			),
			array_keys( $row )
		);
		$this->assertSame( $tax_id, $row['id'] );
		$this->assertSame( 'NY Tax', $row['name'] );
	}

	/**
	 * Cashier reads can target specific tax rates.
	 */
	public function test_cashier_include_filter_returns_only_requested_tax_rate(): void {
		$included_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '7.25',
				'name'    => 'Included Tax',
			)
		);
		$excluded_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'FL',
				'rate'    => '6.00',
				'name'    => 'Excluded Tax',
			)
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_param( 'include', array( $included_id ) );

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( $included_id ), $ids );
		$this->assertNotContains( $excluded_id, $ids );
	}

	/**
	 * Cashier reads accept comma-separated include IDs from the query string.
	 */
	public function test_cashier_include_filter_accepts_comma_separated_tax_rate_ids(): void {
		$first_included_id  = TaxHelper::create_tax_rate( array( 'rate' => '5.00', 'name' => 'First Included Tax' ) );
		$second_included_id = TaxHelper::create_tax_rate( array( 'rate' => '10.00', 'name' => 'Second Included Tax' ) );
		$other_id           = TaxHelper::create_tax_rate( array( 'rate' => '15.00', 'name' => 'Other Tax' ) );
		$request            = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_query_params(
			array(
				'include' => $first_included_id . ',' . $second_included_id,
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( array( $first_included_id, $second_included_id ), $ids );
		$this->assertNotContains( $other_id, $ids );
	}

	/**
	 * Cashier reads can exclude specific tax rates.
	 */
	public function test_cashier_exclude_filter_removes_tax_rate(): void {
		$excluded_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '7.25',
				'name'    => 'Excluded Tax',
			)
		);
		$included_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'FL',
				'rate'    => '6.00',
				'name'    => 'Included Tax',
			)
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_param( 'exclude', array( $excluded_id ) );

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $excluded_id, $ids );
		$this->assertContains( $included_id, $ids );
	}

	/**
	 * Cashier reads accept comma-separated exclude IDs from the query string.
	 */
	public function test_cashier_exclude_filter_accepts_comma_separated_tax_rate_ids(): void {
		$first_excluded_id  = TaxHelper::create_tax_rate( array( 'rate' => '5.00', 'name' => 'First Excluded Tax' ) );
		$second_excluded_id = TaxHelper::create_tax_rate( array( 'rate' => '10.00', 'name' => 'Second Excluded Tax' ) );
		$included_id        = TaxHelper::create_tax_rate( array( 'rate' => '15.00', 'name' => 'Included Tax' ) );
		$request            = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_query_params(
			array(
				'exclude' => $first_excluded_id . ',' . $second_excluded_id,
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $first_excluded_id, $ids );
		$this->assertNotContains( $second_excluded_id, $ids );
		$this->assertContains( $included_id, $ids );
	}

	/**
	 * Include filters constrain pagination metadata as well as rows.
	 */
	public function test_cashier_include_filter_limits_pagination_totals(): void {
		$included_id = TaxHelper::create_tax_rate( array( 'rate' => '5.00', 'name' => 'Included Tax' ) );
		TaxHelper::create_tax_rate( array( 'rate' => '10.00', 'name' => 'Other Tax' ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_query_params(
			array(
				'include'  => (string) $included_id,
				'page'     => 1,
				'per_page' => 1,
			)
		);

		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( $included_id ), wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertEquals( 1, (int) $headers['X-WP-Total'] );
		$this->assertEquals( 1, (int) $headers['X-WP-TotalPages'] );
	}

	/**
	 * Include and class filters intersect.
	 */
	public function test_include_with_class_filter_intersects(): void {
		$gb_standard_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '20.00',
				'name'    => 'GB Standard Tax',
			)
		);
		$gb_reduced_id  = TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '5.00',
				'name'    => 'GB Reduced Tax',
				'class'   => 'reduced-rate',
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'rate'    => '8.00',
				'name'    => 'US Standard Tax',
			)
		);
		// A reduced-rate row OUTSIDE the include list: class= alone would return
		// it, so the assertion below only holds when include intersects class.
		TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'rate'    => '2.50',
				'name'    => 'US Reduced Tax',
				'class'   => 'reduced-rate',
			)
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_query_params(
			array(
				'include' => array( $gb_standard_id, $gb_reduced_id ),
				'class'   => 'reduced-rate',
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( $gb_reduced_id ), $ids );
	}

	/**
	 * Cashier reads retain WooCommerce's tax-class filtering.
	 */
	public function test_cashier_class_filter_returns_only_matching_tax_rates(): void {
		$reduced_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '5.00',
				'name'    => 'Reduced Tax',
				'class'   => 'reduced-rate',
			)
		);
		$standard_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'GB',
				'rate'    => '20.00',
				'name'    => 'Standard Tax',
			)
		);
		$request = $this->wp_rest_get_request( '/wcpos/v2/taxes' );
		$request->set_param( 'class', 'reduced-rate' );

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( $reduced_id ), $ids );
		$this->assertNotContains( $standard_id, $ids );
	}
}
