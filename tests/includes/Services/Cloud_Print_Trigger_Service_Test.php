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

	/**
	 * It checks beyond the first query page when avoiding duplicates.
	 */
	public function test_duplicate_guard_scans_beyond_first_fifty_jobs(): void {
		$order = OrderHelper::create_order();
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'assignments' => array(
					array(
						'printer_id' => 'kitchen',
						'scope'      => 'every',
						'format'     => 'epos-xml',
					),
				),
			)
		);
		for ( $i = 0; $i < 50; $i++ ) {
			$this->jobs->create(
				array(
					'printer_id' => 'kitchen',
					'order_id'   => 1000 + $i,
					'format'     => 'epos-xml',
				)
			);
		}
		$this->jobs->create(
			array(
				'printer_id' => 'kitchen',
				'order_id'   => $order->get_id(),
				'format'     => 'epos-xml',
			)
		);

		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		$matches = array_filter(
			$this->jobs->query( array( 'printer_id' => 'kitchen', 'limit' => -1 ) ),
			static function ( $job ) use ( $order ) {
				return (int) $job['order_id'] === $order->get_id();
			}
		);
		$this->assertEquals( 1, \count( $matches ) );
	}

	/**
	 * It lets Pro substitute per-outlet assignments through a filter.
	 */
	public function test_assignments_filter_can_substitute_assignments(): void {
		update_option( 'woocommerce_pos_settings_cloud_print', array( 'assignments' => array() ) );
		add_filter(
			'woocommerce_pos_cloud_print_assignments',
			function () {
				return array( array( 'printer_id' => 'outlet-1', 'scope' => 'every', 'format' => 'starprnt' ) );
			}
		);
		$order = OrderHelper::create_order();

		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'outlet-1' ) ) ) );
	}

}
