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
use WC_Unit_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\Init
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
		$wp_filter = $this->wp_filter_snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the registry a second Init mutated.
		Init::reset_request_state();
		remove_all_filters( 'woocommerce_pos_request_lane' );
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

	public function test_the_receipt_shortcode_constructs_the_template_services_on_demand(): void {
		$this->force_lane( Request_Lane::STOREFRONT );
		$this->run_init();
		$this->assertSame( array( 'always' ), Init::constructed_groups() );

		do_shortcode( '[wcpos_receipt order_id="0"]' );

		$this->assertContains( 'order', Init::constructed_groups(), 'Rendering a receipt needs Templates; the shortcode asks for it.' );
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
