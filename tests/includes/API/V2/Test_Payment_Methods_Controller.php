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
		$this->assertSame( '1.0', $data['contract'] );
		$this->assertSame( 'manual', $cash['capture']['mode'] );
		$this->assertSame( true, $cash['capabilities']['change'] );
		$this->assertSame( array(), (array) $cash['provider_data'] );
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
