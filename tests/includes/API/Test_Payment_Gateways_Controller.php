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
}
