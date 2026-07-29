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
	 * The desired targeted-pull contract remains pinned while the proxy lacks it.
	 */
	public function test_cashier_include_filter_returns_only_requested_tax_rate(): void {
		$this->markTestSkipped( 'Gap: the v2 taxes proxy forwards include= to wc/v3, whose tax-rate collection ignores it; add proxy filtering before enabling this contract.' );

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
