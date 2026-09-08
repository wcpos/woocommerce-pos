<?php
/**
 * Payment methods controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Payment methods controller tests. */
class Test_Payment_Methods_Controller extends WCPOS_REST_Unit_Test_Case {
	/** The descriptor envelope includes the built-in cash method. */
	public function test_get_payment_methods_returns_envelope_with_cash_descriptor(): void {
		// Arrange / Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-methods' ) );
		$data     = $response->get_data();
		$cash     = array_values( wp_list_filter( $data['methods'], array( 'id' => 'pos_cash' ) ) )[0];

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['schema'] );
		$this->assertSame( '1.1', $data['contract'] );
		$this->assertSame( 'manual', $cash['capture']['mode'] );
		$this->assertSame( true, $cash['capabilities']['change'] );
		$this->assertSame( array(), (array) $cash['provider_data'] );
	}

	public function test_bootstrap_returns_handler_payload(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'bootstrap_test', Bootstrap_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static function () { return 'bootstrap_test'; } );
		$request = $this->wp_rest_post_request( '/wcpos/v2/payment-methods/pos_cash/bootstrap' );
		$request->set_body_params( array( 'context' => array( 'reader' => 'test-reader' ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'provider' => 'test', 'handoff' => array( 'reader' => 'test-reader' ), 'expires_at' => null ), $response->get_data() );
	}

	public function test_bootstrap_unknown_method_returns_404(): void {
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/payment-methods/missing/bootstrap' ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_payment_method_not_found', $response->get_data()['code'] );
	}

	public function test_bootstrap_missing_handler_returns_501(): void {
		\WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::instance()->register( 'bootstrap_test', Bootstrap_Handler::class );
		Bootstrap_Handler::$mode = 'unregistered';
		add_filter( 'wcpos_payment_method_capture_mode', static function () { return 'bootstrap_test'; } );
		try {
			$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/payment-methods/pos_cash/bootstrap' ) );
			$this->assertSame( 501, $response->get_status() );
			$this->assertSame( 'wcpos_capture_mode_unsupported', $response->get_data()['code'] );
		} finally {
			Bootstrap_Handler::$mode = 'bootstrap_test';
		}
	}

	public function test_bootstrap_requires_order_publishing(): void {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/payment-methods/pos_cash/bootstrap' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/** Anonymous callers are rejected by the baseline POS access gate. */
	public function test_get_payment_methods_requires_pos_access(): void {
		// Arrange.
		wp_set_current_user( 0 );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-methods' ) );

		// Assert.
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}
}

class Bootstrap_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler {
	public static $mode = 'bootstrap_test';
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor = parent::describe( $gateway );
		$descriptor['capture']['mode'] = self::$mode;
		return $descriptor;
	}
	public function bootstrap( \WC_Payment_Gateway $gateway, array $context ) {
		return array( 'provider' => 'test', 'handoff' => $context, 'expires_at' => null );
	}
}
