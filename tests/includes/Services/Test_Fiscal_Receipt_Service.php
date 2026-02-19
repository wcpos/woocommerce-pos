<?php
/**
 * Tests for fiscal receipt service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Fiscal_Receipt_Service;
use WC_REST_Unit_Test_Case;

/**
 * Test_Fiscal_Receipt_Service class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Fiscal_Receipt_Service extends WC_REST_Unit_Test_Case {
	/**
	 * Service instance.
	 *
	 * @var Fiscal_Receipt_Service
	 */
	private $service;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = new Fiscal_Receipt_Service();
	}

	/**
	 * Test submission status lifecycle.
	 */
	public function test_submission_status_lifecycle(): void {
		$order = OrderHelper::create_order();

		$this->assertEquals( 'pending', $this->service->get_submission_status( $order->get_id() ) );

		$this->service->set_submission_status( $order->get_id(), 'failed' );
		$this->assertEquals( 'failed', $this->service->get_submission_status( $order->get_id() ) );

		$this->service->set_submission_status( $order->get_id(), 'sent' );
		$this->assertEquals( 'sent', $this->service->get_submission_status( $order->get_id() ) );
	}

	/**
	 * Test snapshot enrichment hook modifies payload.
	 */
	public function test_enrich_snapshot_hook(): void {
		$order    = OrderHelper::create_order();
		$snapshot = array( 'fiscal' => array() );

		add_filter(
			'woocommerce_pos_fiscal_snapshot_enrich',
			function ( $payload, $order_id ) use ( $order ) {
				if ( $order->get_id() !== $order_id ) {
					return $payload;
				}
				$payload['fiscal']['tax_agency_code'] = 'TEST-CODE';
				return $payload;
			},
			10,
			2
		);

		$enriched = $this->service->enrich_snapshot( $snapshot, $order->get_id() );
		$this->assertEquals( 'TEST-CODE', $enriched['fiscal']['tax_agency_code'] );
	}
}
