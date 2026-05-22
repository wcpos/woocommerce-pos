<?php
/**
 * Print job render tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Job_Service_Render_Test class.
 */
class Print_Job_Service_Render_Test extends \WC_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Set up service and CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs = new Print_Job_Service();
		$this->jobs->register_post_type();
	}

	/**
	 * It decodes stored raw payloads.
	 */
	public function test_render_payload_decodes_raw_job_payload(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'RAW' ),
			)
		);

		$this->assertSame( 'RAW', $this->jobs->render_payload( $this->jobs->get( $id ) ) );
	}

	/**
	 * It renders order-based jobs using the requested output adapter.
	 */
	public function test_render_payload_renders_order_based_epos_xml_job(): void {
		$order = OrderHelper::create_order();
		$id    = $this->jobs->create(
			array(
				'printer_id' => 'p1',
				'order_id'   => $order->get_id(),
				'format'     => 'epos-xml',
			)
		);

		$xml = $this->jobs->render_payload( $this->jobs->get( $id ) );

		$this->assertStringContainsString( '<epos-print', $xml );
		$this->assertStringContainsString( (string) $order->get_order_number(), $xml );
	}
}
