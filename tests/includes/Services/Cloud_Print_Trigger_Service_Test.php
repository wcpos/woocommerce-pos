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
	 * Tear down — clear scheduled submit events and the settings option.
	 */
	public function tearDown(): void {
		wp_clear_scheduled_hook( Cloud_Print_Trigger_Service::CRON_SUBMIT );
		delete_option( 'woocommerce_pos_settings_cloud_print' );
		parent::tearDown();
	}

	/**
	 * Create a thermal template post with raw markup, bypassing wp_kses.
	 *
	 * The wp_insert_post() call runs content through wp_kses for users without
	 * the unfiltered_html cap, which strips the custom thermal tags. Writing the
	 * content directly with $wpdb mirrors how real templates are stored.
	 *
	 * @param string $engine Template engine slug.
	 *
	 * @return int Template post ID.
	 */
	private function create_thermal_template( string $engine = 'thermal' ): int {
		$tid = wp_insert_post(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'T',
				'post_content' => '',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $tid, 'wp_insert_post() returned a WP_Error creating the thermal template.' );
		$this->assertIsInt( $tid );
		$this->assertGreaterThan( 0, $tid, 'wp_insert_post() failed to create the thermal template post.' );

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="48"><text>Order #{{order.number}}</text><cut /></receipt>' ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNotFalse( $updated, 'Failed to write raw template content via $wpdb->update().' );
		clean_post_cache( $tid );

		update_post_meta( $tid, '_template_engine', $engine );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * Persist printers + assignments to the cloud-print settings option.
	 *
	 * Assignments without an explicit `trigger` are pinned to 'created' so the
	 * mechanics tests (locks, copies, formats) stay independent of order
	 * status — OrderHelper orders are 'pending', which the default 'paid'
	 * trigger would skip. Trigger semantics have their own dedicated tests.
	 *
	 * @param array $printers    Printer definitions.
	 * @param array $assignments Assignment definitions.
	 */
	private function set_cloud_print( array $printers, array $assignments ): void {
		$assignments = array_map(
			function ( $assignment ) {
				if ( \is_array( $assignment ) && ! isset( $assignment['trigger'] ) ) {
					$assignment['trigger'] = 'created';
				}

				return $assignment;
			},
			$assignments
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => $printers,
				'assignments' => $assignments,
			)
		);
	}

	/**
	 * It creates one job when a POS order matches a POS-scoped assignment.
	 */
	public function test_pos_order_with_pos_assignment_creates_one_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'pos',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'kitchen' ) );
		$this->assertEquals( 1, \count( $jobs ) );
		$job = $this->jobs->get( $jobs[0]['id'] );
		$this->assertEquals( 'application/xml', $job['content_type'] );
		$this->assertEquals( (string) $tid, $job['template_id'] );
	}

	/**
	 * An assignment requesting two copies creates two jobs.
	 */
	public function test_assignment_with_two_copies_creates_two_jobs(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
					'copies'      => 2,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 2, $this->jobs->count( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Firing the trigger twice does not exceed the requested copy count.
	 */
	public function test_trigger_fired_twice_with_two_copies_leaves_two_jobs(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
					'copies'      => 2,
				),
			)
		);
		$order   = OrderHelper::create_order();
		$service = new Cloud_Print_Trigger_Service();

		// Act.
		$service->handle_order( $order->get_id() );
		$service->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 2, $this->jobs->count( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Overlapping triggers do not exceed the requested copy count.
	 */
	public function test_overlapping_triggers_do_not_exceed_requested_copy_count(): void {
		// Arrange.
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
					'copies'      => 2,
				),
			)
		);
		$service   = new Cloud_Print_Trigger_Service();
		$reentered = false;
		$callback  = function () use ( $service, $order, &$reentered ): void {
			if ( $reentered ) {
				return;
			}
			$reentered = true;
			$service->handle_order( $order->get_id() );
		};
		add_action( 'woocommerce_pos_print_job_created', $callback );

		// Act.
		try {
			$service->handle_order( $order->get_id() );
		} finally {
			remove_action( 'woocommerce_pos_print_job_created', $callback );
		}

		// Assert.
		$this->assertTrue( $reentered );
		$this->assertEquals(
			2,
			$this->jobs->count(
				array(
					'printer_id'  => 'kitchen',
					'order_id'    => $order->get_id(),
					'template_id' => (string) $tid,
				)
			)
		);
	}

	/**
	 * A callback exception does not leave the assignment locked.
	 */
	public function test_assignment_lock_is_released_when_job_created_callback_throws(): void {
		// Arrange.
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$service  = new Cloud_Print_Trigger_Service();
		$lock     = 'wcpos_cloud_print_assignment_lock_' . md5( $order->get_id() . "\0kitchen\0" . $tid );
		$callback = static function (): void {
			throw new \RuntimeException( 'Job-created callback failed.' );
		};
		$exception = null;
		add_action( 'woocommerce_pos_print_job_created', $callback );

		// Act.
		try {
			$service->handle_order( $order->get_id() );
		} catch ( \RuntimeException $caught ) {
			$exception = $caught;
		} finally {
			remove_action( 'woocommerce_pos_print_job_created', $callback );
		}
		$locked_at = get_option( $lock, false );
		delete_option( $lock );

		// Assert.
		$this->assertInstanceOf( \RuntimeException::class, $exception );
		$this->assertFalse( $locked_at );
	}

	/**
	 * Reclaiming a stale lock does not delete a replacement lock.
	 */
	public function test_stale_lock_reclaim_preserves_replacement_lock(): void {
		// Arrange.
		$lock      = 'wcpos_cloud_print_assignment_lock_replacement_test';
		$fresh     = (string) ( time() + 60 );
		$stale     = (string) ( time() - Cloud_Print_Trigger_Service::ASSIGNMENT_LOCK_TTL - 1 );
		$filter    = static function () use ( $stale ): string {
			return $stale;
		};
		$method    = new \ReflectionMethod( Cloud_Print_Trigger_Service::class, 'acquire_assignment_lock' );
		$service   = new Cloud_Print_Trigger_Service();
		$acquired  = null;
		delete_option( $lock );
		$this->assertTrue( add_option( $lock, $fresh, '', false ) );
		add_filter( 'pre_option_' . $lock, $filter );

		// Act.
		try {
			$method->setAccessible( true );
			$acquired = $method->invoke( $service, $lock );
		} finally {
			remove_filter( 'pre_option_' . $lock, $filter );
		}
		$stored = get_option( $lock );
		delete_option( $lock );

		// Assert.
		$this->assertFalse( $acquired );
		$this->assertSame( $fresh, $stored );
	}

	/**
	 * Copy counts above the maximum are clamped to five jobs.
	 */
	public function test_assignment_with_excessive_copies_clamps_to_five_jobs(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
					'copies'      => 99,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 5, $this->jobs->count( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * It skips online orders for POS-scoped assignments.
	 */
	public function test_online_order_with_pos_scope_creates_no_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'pos',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * A paid-trigger assignment skips an order that has not been paid yet.
	 */
	public function test_unpaid_order_with_paid_trigger_creates_no_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'counter',
					'name' => 'Counter',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'counter',
					'scope' => 'every',
					'template_id' => (string) $tid,
					'trigger' => 'paid',
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'counter' ) ) ) );
	}

	/**
	 * A paid-trigger assignment prints once the order reaches a paid status.
	 */
	public function test_paid_order_with_paid_trigger_creates_one_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'counter',
					'name' => 'Counter',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'counter',
					'scope' => 'every',
					'template_id' => (string) $tid,
					'trigger' => 'paid',
				),
			)
		);
		$order = OrderHelper::create_order();
		$order->set_status( 'processing' );
		$order->save();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'counter' ) ) ) );
	}

	/**
	 * Legacy assignments stored without a trigger default to 'paid'.
	 */
	public function test_assignment_without_trigger_defaults_to_paid(): void {
		// Arrange — write the option directly: set_cloud_print() pins a
		// trigger, but this test exercises the stored-before-upgrade shape.
		$tid = $this->create_thermal_template();
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id' => 'counter',
						'name' => 'Counter',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(
					array(
						'printer_id' => 'counter',
						'scope' => 'every',
						'template_id' => (string) $tid,
					),
				),
			)
		);
		$unpaid = OrderHelper::create_order();
		$paid   = OrderHelper::create_order();
		$paid->set_status( 'completed' );
		$paid->save();

		// Act.
		$service = new Cloud_Print_Trigger_Service();
		$service->handle_order( $unpaid->get_id() );
		$service->handle_order( $paid->get_id() );

		// Assert — only the paid order printed.
		$jobs = $this->jobs->query( array( 'printer_id' => 'counter' ) );
		$this->assertEquals( 1, \count( $jobs ) );
		$job = $this->jobs->get( $jobs[0]['id'] );
		$this->assertEquals( $paid->get_id(), (int) $job['order_id'] );
	}

	/**
	 * A created-trigger assignment prints an unpaid order immediately.
	 */
	public function test_unpaid_order_with_created_trigger_creates_one_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'template_id' => (string) $tid,
					'trigger' => 'created',
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * Payment completing fires the paid trigger through the real order hooks.
	 */
	public function test_payment_complete_fires_paid_trigger_via_hooks(): void {
		// Arrange — instantiate the service so its order hooks are registered.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'counter',
					'name' => 'Counter',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'counter',
					'scope' => 'every',
					'template_id' => (string) $tid,
					'trigger' => 'paid',
				),
			)
		);
		new Cloud_Print_Trigger_Service();
		$order = OrderHelper::create_order();
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'counter' ) ) ), 'Order creation must not print a paid-trigger assignment.' );

		// Act — payment lands, moving the order to a paid status.
		$order->payment_complete();

		// Assert.
		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'counter' ) ) ) );
	}

	/**
	 * It creates one job for each matching assignment and avoids duplicates.
	 */
	public function test_two_assignments_create_kitchen_and_counter_jobs(): void {
		// Arrange.
		$kitchen_tid = $this->create_thermal_template();
		$counter_tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
				array(
					'id' => 'counter',
					'name' => 'Counter',
					'provider' => 'star-cloudprnt',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'template_id' => (string) $kitchen_tid,
				),
				array(
					'printer_id' => 'counter',
					'scope' => 'every',
					'template_id' => (string) $counter_tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		$service = new Cloud_Print_Trigger_Service();
		$service->handle_order( $order->get_id() );
		$service->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
		$counter_jobs = $this->jobs->query( array( 'printer_id' => 'counter' ) );
		$this->assertEquals( 1, \count( $counter_jobs ) );
		$counter_job = $this->jobs->get( $counter_jobs[0]['id'] );
		$this->assertEquals( 'application/vnd.star.starprnt', $counter_job['content_type'] );
	}

	/**
	 * Two rules for the same printer with different templates create two jobs.
	 */
	public function test_same_printer_different_templates_creates_two_jobs(): void {
		// Arrange.
		$t1 = $this->create_thermal_template();
		$t2 = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $t1,
				),
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $t2,
				),
			)
		);
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'kitchen' ) );
		$this->assertEquals( 2, \count( $jobs ) );
	}

	/**
	 * Two identical rules (same printer + template) still dedupe to one job.
	 */
	public function test_identical_rules_dedupe_to_one_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id'       => 'kitchen',
					'name'     => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
				),
				array(
					'printer_id'  => 'kitchen',
					'scope'       => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'kitchen' ) );
		$this->assertEquals( 1, \count( $jobs ) );
	}

	/**
	 * It checks beyond the first query page when avoiding duplicates.
	 */
	public function test_duplicate_guard_scans_beyond_first_fifty_jobs(): void {
		// Arrange.
		$tid   = $this->create_thermal_template();
		$order = OrderHelper::create_order();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		// Decoy order ids sit far above the real order's id: auto-increment
		// counters never roll back with test transactions, so a fixed range
		// like 1000-1049 eventually collides with a real order once enough
		// prior tests have inflated the counter.
		for ( $i = 0; $i < 50; $i++ ) {
			$this->jobs->create(
				array(
					'printer_id'  => 'kitchen',
					'order_id'    => $order->get_id() + 100000 + $i,
					'template_id' => (string) $tid,
				)
			);
		}
		$this->jobs->create(
			array(
				'printer_id'  => 'kitchen',
				'order_id'    => $order->get_id(),
				'template_id' => (string) $tid,
			)
		);

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$matches = array_filter(
			$this->jobs->query(
				array(
					'printer_id' => 'kitchen',
					'limit' => -1,
				)
			),
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
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'outlet-1',
					'name' => 'Outlet 1',
					'provider' => 'star-cloudprnt',
				),
			),
			array()
		);
		$callback = static function () use ( $tid ) {
			return array(
				array(
					'printer_id' => 'outlet-1',
					'scope' => 'every',
					'template_id' => (string) $tid,
					'trigger' => 'created',
				),
			);
		};
		add_filter( 'woocommerce_pos_cloud_print_assignments', $callback );
		$order = OrderHelper::create_order();

		// Act + Assert.
		try {
			( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );
			$this->assertEquals( 1, \count( $this->jobs->query( array( 'printer_id' => 'outlet-1' ) ) ) );
		} finally {
			remove_filter( 'woocommerce_pos_cloud_print_assignments', $callback );
		}
	}

	/**
	 * It enqueues one PDF job and schedules a submit event for a PrintNode printer (default format).
	 */
	public function test_printnode_printer_default_format_enqueues_pdf_job_and_schedules_submit(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'pn',
					'name' => 'PrintNode',
					'provider' => 'printnode',
				),
			),
			array(
				array(
					'printer_id' => 'pn',
					'scope' => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'pn' ) );
		$this->assertEquals( 1, \count( $jobs ) );
		$job = $this->jobs->get( $jobs[0]['id'] );
		$this->assertEquals( 'pdf', $job['pn_kind'] );
		$this->assertEquals( 'application/pdf', $job['content_type'] );
		$this->assertNotFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $jobs[0]['id'] ) )
		);
	}

	/**
	 * It enqueues an ESC/POS job for a PrintNode printer configured for raw format.
	 */
	public function test_printnode_printer_raw_format_enqueues_escpos_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'pn',
					'name' => 'PrintNode',
					'provider' => 'printnode',
					'printnode_format' => 'raw',
				),
			),
			array(
				array(
					'printer_id' => 'pn',
					'scope' => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'pn' ) );
		$this->assertEquals( 1, \count( $jobs ) );
		$job = $this->jobs->get( $jobs[0]['id'] );
		$this->assertEquals( 'escpos', $job['pn_kind'] );
		$this->assertEquals( 'application/octet-stream', $job['content_type'] );
	}

	/**
	 * It schedules no submit event for a star/epson assignment.
	 */
	public function test_star_assignment_schedules_no_submit_event(): void {
		// Arrange.
		$tid = $this->create_thermal_template();
		$this->set_cloud_print(
			array(
				array(
					'id' => 'counter',
					'name' => 'Counter',
					'provider' => 'star-cloudprnt',
				),
			),
			array(
				array(
					'printer_id' => 'counter',
					'scope' => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$jobs = $this->jobs->query( array( 'printer_id' => 'counter' ) );
		$this->assertEquals( 1, \count( $jobs ) );
		$job = $this->jobs->get( $jobs[0]['id'] );
		$this->assertEquals( 'application/vnd.star.starprnt', $job['content_type'] );
		$this->assertFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $jobs[0]['id'] ) )
		);
	}

	/**
	 * It enqueues no job for a non-thermal template on a direct printer.
	 */
	public function test_non_thermal_template_for_direct_printer_enqueues_no_job(): void {
		// Arrange.
		$tid = $this->create_thermal_template( 'logicless' );
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'star-cloudprnt',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'template_id' => (string) $tid,
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * It enqueues no job when the assignment template_id is empty.
	 */
	public function test_empty_template_id_enqueues_no_job(): void {
		// Arrange.
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'template_id' => '',
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}

	/**
	 * It enqueues no job for a legacy format-only assignment (dormant path removed).
	 */
	public function test_legacy_format_only_assignment_enqueues_no_job(): void {
		// Arrange.
		$this->set_cloud_print(
			array(
				array(
					'id' => 'kitchen',
					'name' => 'Kitchen',
					'provider' => 'epson-sdp',
				),
			),
			array(
				array(
					'printer_id' => 'kitchen',
					'scope' => 'every',
					'format' => 'epos-xml',
				),
			)
		);
		$order = OrderHelper::create_order();

		// Act.
		( new Cloud_Print_Trigger_Service() )->handle_order( $order->get_id() );

		// Assert.
		$this->assertEquals( 0, \count( $this->jobs->query( array( 'printer_id' => 'kitchen' ) ) ) );
	}
	/**
	 * A legacy printer row without a stored provider behaves exactly like an
	 * explicit star-cloudprnt row. Before Provider::normalize() was applied
	 * here this held only by coincidence (every consumer's ''-branch happened
	 * to match the star default); this pin makes the equivalence load-bearing
	 * so a future provider-keyed branch cannot silently diverge.
	 */
	public function test_enqueue_without_stored_provider_matches_star_cloudprnt(): void {
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt><text>Hi</text></receipt>',
		);
		$order = OrderHelper::create_order();

		$legacy_job_id = Cloud_Print_Trigger_Service::enqueue_order_job(
			$this->jobs,
			'legacy',
			array( 'id' => 'legacy' ),
			$order->get_id(),
			'virtual-receipt',
			$template
		);
		$explicit_job_id = Cloud_Print_Trigger_Service::enqueue_order_job(
			$this->jobs,
			'explicit',
			array(
				'id'       => 'explicit',
				'provider' => 'star-cloudprnt',
			),
			$order->get_id(),
			'virtual-receipt',
			$template
		);

		$this->assertGreaterThan( 0, $legacy_job_id );
		$this->assertGreaterThan( 0, $explicit_job_id );

		$legacy   = $this->jobs->get( $legacy_job_id );
		$explicit = $this->jobs->get( $explicit_job_id );
		$this->assertEquals( $explicit['content_type'], $legacy['content_type'] );
		$this->assertEquals( $explicit['auto_open_drawer'], $legacy['auto_open_drawer'] );
		$this->assertEquals( $explicit['drawer_connector'], $legacy['drawer_connector'] );
		// Polling provider: nothing is scheduled for submission.
		$this->assertFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $legacy_job_id ) )
		);
	}

	/**
	 * It schedules submit for a Star Online push printer.
	 */
	public function test_enqueue_schedules_submit_for_star_online(): void {
		$printer = array(
			'id'                 => 'star',
			'provider'           => 'star-online',
			'star_api_key'       => 'k',
			'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
			'star_device_id'     => 'abc',
		);
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt><text>Hi</text></receipt>',
		);
		$order = OrderHelper::create_order();

		$job_id = Cloud_Print_Trigger_Service::enqueue_order_job(
			$this->jobs,
			'star',
			$printer,
			$order->get_id(),
			'virtual-receipt',
			$template
		);

		$this->assertGreaterThan( 0, $job_id );
		$this->assertNotFalse(
			wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $job_id ) )
		);
	}
}
