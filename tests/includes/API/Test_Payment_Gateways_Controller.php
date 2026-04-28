<?php
/**
 * Tests for the POS payment gateways controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Payment_Gateways_Controller extends WCPOS_REST_Unit_Test_Case {
	public function test_payment_gateways_returns_pos_contract_fields(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );

		$match = wp_list_filter(
			$data,
			array(
				'id' => 'pos_cash',
			)
		);

		$this->assertNotEmpty( $match );

		$gateway = array_shift( $match );

		$this->assertArrayHasKey( 'id', $gateway );
		$this->assertArrayHasKey( 'title', $gateway );
		$this->assertArrayHasKey( 'enabled', $gateway );
		$this->assertArrayHasKey( 'provider', $gateway );
		$this->assertArrayHasKey( 'pos_type', $gateway );
		$this->assertArrayHasKey( 'capabilities', $gateway );
		$this->assertArrayHasKey( 'provider_data', $gateway );
	}

	public function test_payment_gateways_default_checkout_support_matches_registered_handler(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$cash = array_shift(
			wp_list_filter(
				$data,
				array(
					'id' => 'pos_cash',
				)
			)
		);
		$bacs = array_shift(
			wp_list_filter(
				$data,
				array(
					'id' => 'bacs',
				)
			)
		);

		$this->assertNotEmpty( $cash );
		$this->assertNotEmpty( $bacs );
		$this->assertTrue( $cash['capabilities']['supports_checkout'] );
		$this->assertFalse( $bacs['capabilities']['supports_checkout'] );
	}

	/**
	 * It exposes refund capability flags for built-in and Woo gateways.
	 */
	public function test_payment_gateways_expose_refund_capabilities_for_built_in_and_woo_gateways(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$cash = array_shift( wp_list_filter( $data, array( 'id' => 'pos_cash' ) ) );
		$bacs = array_shift( wp_list_filter( $data, array( 'id' => 'bacs' ) ) );

		$this->assertSame( 'wcpos', $cash['provider'] );
		$this->assertSame( 'manual', $cash['pos_type'] );
		$this->assertFalse( $cash['capabilities']['supports_provider_refunds'] );
		$this->assertFalse( $cash['capabilities']['supports_automatic_refunds'] );
		$this->assertArrayHasKey( 'provider_data', $cash );

		$this->assertArrayHasKey( 'supports_provider_refunds', $bacs['capabilities'] );
		$this->assertArrayHasKey( 'supports_automatic_refunds', $bacs['capabilities'] );
	}
}
