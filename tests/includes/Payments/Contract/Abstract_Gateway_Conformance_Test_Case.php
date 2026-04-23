<?php
/**
 * Base assertions for POS payment gateway conformance tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Shared assertions for gateway repos adopting the POS contract.
 */
abstract class Abstract_Gateway_Conformance_Test_Case extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Gateway ID under test.
	 *
	 * @var string
	 */
	protected $gateway_id = '';

	/**
	 * Assert the discovery contract for the configured gateway.
	 */
	protected function assert_gateway_contract_shape( array $gateway ): void {
		$this->assertSame( $this->gateway_id, $gateway['id'] ?? '' );
		$this->assertArrayHasKey( 'provider', $gateway );
		$this->assertArrayHasKey( 'pos_type', $gateway );
		$this->assertArrayHasKey( 'capabilities', $gateway );
		$this->assertArrayHasKey( 'provider_data', $gateway );
		$this->assertIsArray( $gateway['capabilities'] );
		$this->assertIsArray( $gateway['provider_data'] );
	}

	/**
	 * Fetch the gateway discovery payload.
	 */
	protected function get_gateway_contract_payload(): array {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$match    = wp_list_filter(
			$data,
			array(
				'id' => $this->gateway_id,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $match, 'Gateway missing from POS catalog.' );

		return array_shift( $match );
	}

	/**
	 * Fetch bootstrap response for the configured gateway.
	 */
	protected function get_gateway_bootstrap_payload(): array {
		$request  = $this->wp_rest_post_request( '/wcpos/v1/payment-gateways/' . $this->gateway_id . '/bootstrap' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->gateway_id, $data['gateway_id'] ?? '' );

		return $data;
	}
}
