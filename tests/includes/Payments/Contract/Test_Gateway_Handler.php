<?php
/**
 * Gateway capture handler tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Handler;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Gateway mode has fixed full-order capabilities. */
class Test_Gateway_Handler extends WCPOS_REST_Unit_Test_Case {
	/** Refund capability follows WooCommerce. */
	public function test_gateway_description_has_fixed_capabilities(): void {
		// Arrange.
		$gateway = new Gateway_Handler_Test_Gateway();
		$handler = Capture_Mode_Registry::instance()->get( 'gateway' );
		$this->assertInstanceOf( Gateway_Handler::class, $handler );
		foreach ( array( false, true ) as $refunds ) {
			$gateway->supports = $refunds ? array( 'refunds' ) : array();
			// Act.
			$description = $handler->describe( $gateway );
			// Assert.
			$this->assertSame(
				array(
					'mode' => 'gateway',
					'provider' => null,
					'hardware' => null,
					'webview_available' => true,
				),
				$description['capture']
			);
			$this->assertSame(
				array(
					'amount' => array( 'partial' => false ),
					'change' => false,
					'refunds' => array(
						'via' => $refunds ? 'provider' : 'manual',
						'partial' => $refunds,
					),
					'tips' => 'none',
					'offline' => 'none',
					'void' => false,
				),
				$description['capabilities']
			);
			$this->assertSame( array(), $description['provider_data'] );
		}
		$this->assertSame( 'wcpos_invalid_transition', $handler->void( array(), '' )->get_error_code() );
		$this->assertSame( 409, $handler->void( array(), '' )->get_error_data()['status'] );
	}

	/** Mode opt-in retains the Legacy tab. */
	public function test_gateway_filter_opts_into_gateway_mode(): void {
		// Arrange.
		$filter = static fn() => 'gateway';
		add_filter( 'wcpos_payment_method_capture_mode', $filter );
		try {
			// Act.
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
			// Assert.
			$this->assertSame( 'gateway', $descriptor['capture']['mode'] );
			$this->assertTrue( $descriptor['capture']['webview_available'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter );
		}
	}

	/** Addendum: specialization uses registration, not a describe filter. */
	public function test_gateway_second_registration_replaces_handler(): void {
		// Arrange.
		$registry = Capture_Mode_Registry::instance();
		$original = get_class( $registry->get( 'gateway' ) );
		try {
			// Act.
			$registry->register( 'gateway', Replacement_Gateway_Handler::class );
			// Assert.
			$this->assertSame( array( 'replacement' => true ), $registry->get( 'gateway' )->describe( new Gateway_Handler_Test_Gateway() ) );
		} finally {
			$registry->register( 'gateway', $original );
		}
	}
}

/** Test-only replacement. */
class Replacement_Gateway_Handler extends Abstract_Capture_Mode_Handler {
	/**
	 * Identify the replacement.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array( 'replacement' => true );
	}
}

/** Plain Woo gateway fixture. */
class Gateway_Handler_Test_Gateway extends \WC_Payment_Gateway {}
