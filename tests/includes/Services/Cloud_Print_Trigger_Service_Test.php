<?php
/**
 * Cloud print trigger service tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Cloud_Print_Trigger_Service_Test class.
 */
class Cloud_Print_Trigger_Service_Test extends \WP_UnitTestCase {
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
	 * It creates one job when a POS order matches a POS-scoped assignment.
	 */
	public function test_pos_order_with_pos_assignment_creates_one_job(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'assignments' => array(
					array(
						'printer_id' => 'kitchen',
						'scope'      => 'pos',
						'format'     => 'epos-xml',
					),
				),
			)
		);
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * It skips online orders for POS-scoped assignments.
	 */
	public function test_online_order_with_pos_scope_creates_no_job(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'assignments' => array(
					array(
						'printer_id' => 'kitchen',
						'scope'      => 'pos',
						'format'     => 'epos-xml',
					),
				),
			)
		);
		$order = OrderHelper::create_order();

		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * It creates one job for each matching assignment and avoids duplicates.
	 */
	public function test_two_assignments_create_kitchen_and_counter_jobs(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'assignments' => array(
					array(
						'printer_id' => 'kitchen',
						'scope'      => 'every',
						'format'     => 'epos-xml',
					),
					array(
						'printer_id' => 'counter',
						'scope'      => 'every',
						'format'     => 'starprnt',
					),
				),
			)
		);
		$order = OrderHelper::create_order();

		$service = new Cloud_Print_Trigger_Service();
		$service->handle_order( $order->get_id() );
		$service->handle_order( $order->get_id() );

		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'counter' ) ) ) );
	}
}
