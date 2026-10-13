<?php
/**
 * Webview capture handler tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Webview payments retain their descriptor and refund through their gateway.
 */
class Test_Webview_Handler extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Webview and gateway capabilities differ only by their capture mode.
	 */
	public function test_webview_description_differs_from_the_gateway_description_only_by_mode(): void {
		// Arrange.
		$g            = new Webview_Refund_Test_Gateway();
		$webview      = Capture_Mode_Registry::instance()->get( 'webview' );
		$gateway_mode = Capture_Mode_Registry::instance()->get( 'gateway' );

		foreach ( array( array(), array( 'refunds' ) ) as $supports ) {
			$g->supports = $supports;

			// Act.
			$expected                    = $gateway_mode->describe( $g );
			$expected['capture']['mode'] = 'webview';
			$description                 = $webview->describe( $g );

			// Assert.
			$this->assertSame( $expected, $description );
			$this->assertSame( 'wcpos_invalid_transition', $webview->void( array(), '' )->get_error_code() );
		}
	}

	/**
	 * A stored webview leg refunds through its original WooCommerce gateway.
	 */
	public function test_webview_leg_refund_runs_through_the_gateway(): void {
		// Arrange.
		$filter = static fn( array $gateways ): array => array_merge( $gateways, array( Webview_Refund_Test_Gateway::class ) );
		add_filter( 'woocommerce_payment_gateways', $filter );
		try {
			\WC_Payment_Gateways::instance()->init();
			Webview_Refund_Test_Gateway::$args   = array();
			Webview_Refund_Test_Gateway::$result = true;
			$order                             = OrderHelper::create_order();
			$order->update_meta_data( '_pos', '1' );
			$order->set_created_via( 'woocommerce-pos' );
			$order->set_payment_method( 'wcpos_webview_refund_test' );
			if ( 4.00 > (float) $order->get_total() ) {
				$order->set_total( '10.00' );
			}
			$order->save();
			$id = wp_generate_uuid4();
			Ledger::instance()->save(
				$order,
				array(
					array(
						'id'           => $id,
						'source'       => 'webview',
						'method_id'    => 'wcpos_webview_refund_test',
						'kind'         => 'other',
						'capture_mode' => 'webview',
						'amount'       => $order->get_total(),
						'currency'     => $order->get_currency(),
						'status'       => 'captured',
					),
				)
			);
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => '4.00',
					'reason'         => 'Damaged',
					'refund_payment' => false,
				)
			);

			// Act.
			$row = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $id, $refund->get_id(), '4.00' );

			// Assert.
			$this->assertIsArray( $row );
			$this->assertSame( 'succeeded', $row['refunds'][0]['status'] );
			$this->assertSame( $refund->get_id(), $row['refunds'][0]['id'] );
			$this->assertSame( array( $order->get_id(), 4.0, 'Damaged' ), Webview_Refund_Test_Gateway::$args );
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $filter );
			\WC_Payment_Gateways::instance()->init();
		}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The spec requires a file-local test gateway.
/**
 * A gateway that records refund requests without contacting a provider.
 */
class Webview_Refund_Test_Gateway extends \WC_Payment_Gateway {

	/**
	 * Arguments received by process_refund().
	 *
	 * @var array
	 */
	public static $args = array();

	/**
	 * Result returned by process_refund().
	 *
	 * @var mixed
	 */
	public static $result = true;

	/**
	 * Register the test gateway with refund support.
	 */
	public function __construct() {
		$this->id       = 'wcpos_webview_refund_test';
		$this->supports = array( 'products', 'refunds' );
	}

	/**
	 * Record a refund request and return the configured result.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		self::$args = func_get_args();
		return self::$result;
	}
}
