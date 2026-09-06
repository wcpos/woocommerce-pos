<?php
/**
 * Init constructs POS services by request lane, and never too late for an order.
 *
 * Measured 2026-09-03 (see .claude/research/2026-09-03-lazy-service-construction-spec.md):
 * a storefront page constructed 22 objects and loaded ~80 plugin files for
 * hooks that never fire there. These tests pin the two halves of the fix: a
 * storefront request builds only the always-on group, and the order-event
 * group is constructed on the first order write of ANY request — so the lane
 * classifier is an optimisation, not a correctness gate.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Services\Request_Lane;
use WCPOS\WooCommercePOS\Templates;
use WC_Unit_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\Init
 * @covers \WCPOS\WooCommercePOS\Templates::ensure_registered
 */
class Test_Lazy_Service_Construction extends WC_Unit_Test_Case {
	/** @var array<string, \WP_Hook> */
	private array $wp_filter_snapshot = array();

	public function setUp(): void {
		parent::setUp();
		global $wp_filter;
		foreach ( $wp_filter as $hook_name => $hook ) {
			$this->wp_filter_snapshot[ $hook_name ] = clone $hook;
		}
		Init::reset_request_state();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a storefront request has no admin screen.
	}

	public function tearDown(): void {
		global $wp_filter;
		// Also puts back the suite's default-lane filter that force_lane() removed.
		$wp_filter = $this->wp_filter_snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the registry a second Init mutated.
		Init::reset_request_state();
		parent::tearDown();
	}

	public function test_a_storefront_request_constructs_only_the_always_group(): void {
		$this->force_lane( Request_Lane::STOREFRONT );
		$emails_before = $this->callback_count( 'woocommerce_email_recipient_new_order' );
		$ready_before  = did_action( 'woocommerce_pos_order_services_ready' );

		$this->run_init();

		$this->assertSame( array( 'always' ), Init::constructed_groups() );
		$this->assertSame( $emails_before, $this->callback_count( 'woocommerce_email_recipient_new_order' ), 'Emails is an order-event service; a shop page never constructs it.' );
		$this->assertSame( $ready_before, did_action( 'woocommerce_pos_order_services_ready' ) );
		// The always-on group is really there: the read-side order filters and
		// the reserved-stock filter WooCommerce consults before any order exists.
		$this->assertGreaterThan( 0, $this->callback_count( 'wc_order_statuses' ) );
		$this->assertGreaterThan( 0, $this->callback_count( 'woocommerce_query_for_reserved_stock' ) );
		$this->assertGreaterThan( 0, $this->callback_count( 'woocommerce_payment_gateways' ) );
	}

	public function test_an_order_write_on_a_storefront_request_constructs_the_order_services_first(): void {
		$this->force_lane( Request_Lane::STOREFRONT );
		$this->run_init();
		$emails_before = $this->callback_count( 'woocommerce_email_recipient_new_order' );
		$ready_before  = did_action( 'woocommerce_pos_order_services_ready' );

		// A webhook, a cron spawned from a page view, a plugin creating an order
		// on template_redirect: none of them wait for the lane classifier.
		$order = wc_create_order();

		$this->assertContains( 'order', Init::constructed_groups(), 'The first order write constructs the order-event services.' );
		$this->assertSame( $emails_before + 1, $this->callback_count( 'woocommerce_email_recipient_new_order' ) );
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ), 'The ready action fires exactly once.' );
		$this->assertTrue( post_type_exists( 'wcpos_print_job' ), 'Print_Job_Service registered its post type although init had already run.' );

		// Idempotent: further saves construct nothing again.
		$order->set_customer_note( 'again' );
		$order->save();
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ) );
		$this->assertSame( $emails_before + 1, $this->callback_count( 'woocommerce_email_recipient_new_order' ) );
	}

	/**
	 * Every WooCommerce order write goes through save(), so a single armed hook
	 * covers the paths that carry no create: refunds and status transitions on
	 * an order that already existed when the request began.
	 *
	 * @dataProvider order_writes_without_a_create
	 */
	public function test_any_order_write_on_a_storefront_request_constructs_the_order_services( string $label, callable $write ): void {
		// Pending: payment_complete() only saves (and only fires) from an unpaid status.
		$order = wc_create_order();
		$order->set_status( 'pending' );
		$order->save();

		$this->force_lane( Request_Lane::STOREFRONT );
		$this->run_init();
		$this->assertSame( array( 'always' ), Init::constructed_groups(), $label );
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		$write( $order );

		$this->assertContains( 'order', Init::constructed_groups(), $label );
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ), $label );
		$this->assertGreaterThan( 0, $this->callback_count( 'woocommerce_pos_print_job_created' ), $label . ': the cloud-print relay observer is present.' );
	}

	public function order_writes_without_a_create(): array {
		return array(
			'status transition' => array(
				'update_status()',
				static function ( \WC_Order $order ): void {
					$order->update_status( 'completed' );
				},
			),
			'payment complete'  => array(
				'payment_complete()',
				static function ( \WC_Order $order ): void {
					$order->payment_complete( 'txn' );
				},
			),
			'refund'            => array(
				'wc_create_refund()',
				static function ( \WC_Order $order ): void {
					wc_create_refund(
						array(
							'order_id' => $order->get_id(),
							'amount'   => 0,
							'reason'   => 'lane test',
						)
					);
				},
			),
		);
	}

	/**
	 * @dataProvider eager_lanes
	 */
	public function test_non_storefront_lanes_construct_every_group_eagerly( string $lane ): void {
		$this->force_lane( $lane );
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		$this->run_init();

		$this->assertSame( array( 'always', 'order', 'pos' ), Init::constructed_groups(), $lane );
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ), $lane );
	}

	public function eager_lanes(): array {
		return array(
			array( Request_Lane::POS ),
			array( Request_Lane::REST ),
			array( Request_Lane::ADMIN ),
			array( Request_Lane::CRON ),
			array( Request_Lane::CLI ),
		);
	}

	/**
	 * Regression: My Account renders on the storefront lane and reads the active
	 * receipt template through Templates' static API. Without the post type and
	 * taxonomy registered, the enabled-templates query matched nothing and
	 * get_active_template_id() deleted the merchant's active-template option.
	 */
	public function test_reading_the_active_template_on_a_request_that_never_constructed_templates_keeps_it(): void {
		$template_id = self::factory()->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Custom receipt',
				'post_content' => '<p>{{order.number}}</p>',
			)
		);
		wp_set_object_terms( $template_id, 'receipt', 'wcpos_template_type' );
		update_option( 'wcpos_active_template_receipt', $template_id );

		// A storefront request: Templates was never constructed, so nothing
		// registered its types. The suite's bootstrap did; undo that here.
		unregister_taxonomy( 'wcpos_template_type' );
		unregister_taxonomy( 'wcpos_template_category' );
		unregister_post_type( 'wcpos_template' );
		$this->force_lane( Request_Lane::STOREFRONT );
		$this->run_init();
		$this->assertSame( array( 'always' ), Init::constructed_groups() );

		$active = Templates::get_active_template_id( 'receipt' );

		$this->assertSame( $template_id, $active, 'The custom template is still the active one.' );
		$this->assertSame( (string) $template_id, (string) get_option( 'wcpos_active_template_receipt' ), 'The active-template option survived the read.' );
		$this->assertTrue( taxonomy_exists( 'wcpos_template_type' ), 'The static reader registered the types it needs.' );
		$this->assertSame( array( 'always' ), Init::constructed_groups(), 'Reading a template does not construct the order-event group.' );
	}

	public function test_ensure_order_services_is_idempotent_when_called_directly(): void {
		$this->force_lane( Request_Lane::STOREFRONT );
		$this->run_init();
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		Init::ensure_order_services();
		Init::ensure_order_services();

		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ) );
	}

	private function force_lane( string $lane ): void {
		remove_all_filters( 'woocommerce_pos_request_lane' );
		add_filter(
			'woocommerce_pos_request_lane',
			static function () use ( $lane ): string {
				return $lane;
			}
		);
		Request_Lane::reset();
	}

	private function run_init(): void {
		// The bootstrap's Init already ran on `init`; run a fresh one's init-time
		// wiring under the forced lane. tearDown restores the filter registry.
		( new Init() )->init();
	}

	private function callback_count( string $hook ): int {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$count += \count( $callbacks );
		}
		return $count;
	}
}
