<?php
/**
 * A refund on a deferred lane must not outrun the order-event services.
 *
 * WC_Abstract_Order::save() derives its pre-save action from the object type, so a
 * WC_Order_Refund fires `woocommerce_before_order_refund_object_save` — not the
 * `woocommerce_before_order_object_save` the order group is armed on. Inside
 * wc_create_refund() WooCommerce then decides whether to send the customer
 * refunded-order email BEFORE it saves the parent order, so arming on the parent
 * save alone is too late: the POS customer-email filter is not registered when the
 * decision is taken, and a merchant who switched POS customer emails off still has
 * the shopper emailed. Gateway refund webhooks (?wc-api=) are on the storefront lane.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact regression coverage.

use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Services\Request_Lane;
use WCPOS\WooCommercePOS\Services\Service_Groups;
use WC_Unit_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\Services\Service_Groups
 */
class Test_Refund_Service_Arming extends WC_Unit_Test_Case {
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
		parent::tearDown();
	}

	public function test_a_refund_save_on_a_storefront_request_constructs_the_order_services(): void {
		$order = $this->pos_order();
		$this->run_storefront_init();
		$this->assertSame( array( 'always' ), Service_Groups::constructed() );

		$refund = new \WC_Order_Refund();
		$refund->set_amount( 0 );
		$refund->set_parent_id( $order->get_id() );
		$refund->save();

		$this->assertContains( 'order', Service_Groups::constructed(), 'A refund is an order write; its observers must exist.' );
	}

	/**
	 * The regression that motivated this: WooCommerce consults the refunded-email
	 * filter between the refund save and the parent save, so the order group has to
	 * be built by the first of those, not the second.
	 */
	public function test_the_pos_customer_email_filter_is_registered_when_a_storefront_refund_decides(): void {
		$order = $this->pos_order();

		// The bootstrap's Init constructed Emails on another lane and its filters
		// outlive reset_request_state(); strip them so this measures a fresh request.
		remove_all_filters( 'woocommerce_email_enabled_customer_refunded_order' );
		$this->run_storefront_init();
		$this->assertFalse( $this->pos_email_filter_present(), 'Arrange: the POS filter starts absent on a storefront request.' );

		$present_at_decision = null;
		add_filter(
			'woocommerce_email_enabled_customer_refunded_order',
			function ( $enabled ) use ( &$present_at_decision ) {
				$present_at_decision = $this->pos_email_filter_present();
				return $enabled;
			},
			1,
			3
		);

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 0,
				'reason'   => 'arming regression',
			)
		);

		$this->assertTrue( $present_at_decision, 'WooCommerce decided the refunded email before the POS email filter existed.' );
	}

	private function pos_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'completed' );
		$order->save();

		return $order;
	}

	private function run_storefront_init(): void {
		remove_all_filters( 'woocommerce_pos_request_lane' );
		add_filter(
			'woocommerce_pos_request_lane',
			static function (): string {
				return Request_Lane::STOREFRONT;
			}
		);
		Request_Lane::reset();
		Init::reset_request_state();
		( new Init() )->init();
	}

	private function pos_email_filter_present(): bool {
		global $wp_filter;
		$hook = 'woocommerce_email_enabled_customer_refunded_order';
		if ( ! isset( $wp_filter[ $hook ]->callbacks[999] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks[999] as $callback ) {
			if ( \is_array( $callback['function'] ) && \is_object( $callback['function'][0] )
				&& $callback['function'][0] instanceof \WCPOS\WooCommercePOS\Emails ) {
				return true;
			}
		}

		return false;
	}
}
