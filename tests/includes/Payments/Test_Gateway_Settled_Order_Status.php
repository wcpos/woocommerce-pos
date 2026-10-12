<?php
/**
 * Tests for the per-gateway settled order status the POS catalog exposes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments
 */

namespace WCPOS\WooCommercePOS\Tests\Payments;

use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * The status a gateway settles a POS sale to is the merchant's explicit choice,
 * read from the stored option — never the settings view, which invents
 * Completed for every gateway it has not seen.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Gateway_Settled_Order_Status extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		parent::tearDown();
	}

	/**
	 * A stored status comes back without the wc- prefix.
	 */
	public function test_settled_order_status_returns_stored_status_without_prefix(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending' );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'bacs' );

		// Assert.
		$this->assertSame( 'pending', $status );
	}

	/**
	 * A gateway the merchant never configured has no settled status.
	 */
	public function test_settled_order_status_is_empty_for_unconfigured_gateway(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending' );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'cheque' );

		// Assert.
		$this->assertSame( '', $status );
	}

	/**
	 * A stored status on a gateway disabled for POS is not intent.
	 */
	public function test_settled_order_status_is_empty_for_gateway_disabled_for_pos(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending', false );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'bacs' );

		// Assert.
		$this->assertSame( '', $status );
	}

	/**
	 * A stored status WooCommerce does not know is dropped rather than returned.
	 */
	public function test_settled_order_status_is_empty_for_unregistered_status(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-no-such-status' );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'bacs' );

		// Assert.
		$this->assertSame( '', $status );
	}

	/**
	 * Statuses that can never be a settled outcome, whatever the merchant stored.
	 *
	 * @return array<string, array{string}>
	 */
	public function never_settled_statuses(): array {
		return array(
			'failed'         => array( 'wc-failed' ),
			'cancelled'      => array( 'wc-cancelled' ),
			'refunded'       => array( 'wc-refunded' ),
			'checkout-draft' => array( 'wc-checkout-draft' ),
			'pos-open'       => array( 'wc-pos-open' ),
			'pos-partial'    => array( 'wc-pos-partial' ),
		);
	}

	/**
	 * The catalog reports null, not the stored value, for a never-settled status.
	 */
	public function test_payment_gateways_catalog_reports_null_for_stored_failed_status(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-failed' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$gateways = $this->index_by_id( $response->get_data() );
		$this->assertNull( $gateways['bacs']['settled_order_status'] );
	}

	/**
	 * A stored failed/cancelled/parked status is not a settled outcome: the picker
	 * offers every registered status, and a failed payment must stay open for a retry.
	 *
	 * @dataProvider never_settled_statuses
	 *
	 * @param string $stored The stored status, with prefix.
	 */
	public function test_settled_order_status_is_empty_for_never_settled_status( string $stored ): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', $stored );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'bacs' );

		// Assert.
		$this->assertSame( '', $status );
	}

	/**
	 * The legacy global checkout status still applies to an enabled gateway with no
	 * status of its own, matching Orders and Payment_Gateways_Section::read().
	 */
	public function test_settled_order_status_falls_back_to_legacy_checkout_status(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'bacs' => array(
						'order'   => 0,
						'enabled' => true,
					),
				),
			)
		);
		update_option( 'woocommerce_pos_settings_checkout', array( 'order_status' => 'wc-on-hold' ) );

		// Act.
		$status = ( new Gateway_Contract() )->get_settled_order_status( 'bacs' );

		// Assert.
		$this->assertSame( 'on-hold', $status );
	}

	/**
	 * The catalog carries the settled status so the app can recognise a configured
	 * outcome that WooCommerce does not call paid, and null where none is stored.
	 */
	public function test_payment_gateways_catalog_exposes_settled_order_status(): void {
		// Arrange.
		$this->allow_all_gateways_for_pos();
		$this->set_gateway_settings( 'bacs', 'wc-pending' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$gateways = $this->index_by_id( $response->get_data() );
		$this->assertArrayHasKey( 'bacs', $gateways );
		$this->assertArrayHasKey( 'cheque', $gateways );
		$this->assertSame( 'pending', $gateways['bacs']['settled_order_status'] );
		$this->assertNull( $gateways['cheque']['settled_order_status'] );
	}

	/**
	 * Without Pro the free plugin keeps POS gateways to cash and card, so a stored
	 * BACS status is not exposed as settled.
	 */
	public function test_payment_gateways_catalog_settled_status_is_null_for_free_restricted_gateway(): void {
		// Arrange: the free plugin's option filter stays in place.
		$this->set_gateway_settings( 'bacs', 'wc-pending' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$gateways = $this->index_by_id( $response->get_data() );
		$this->assertNull( $gateways['bacs']['settled_order_status'] );
	}

	/**
	 * The catalog schema declares the field as a nullable string.
	 */
	public function test_payment_gateways_schema_declares_settled_order_status(): void {
		// Act.
		$request = $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' );
		$request->set_method( 'OPTIONS' );
		$response = $this->server->dispatch( $request );
		$schema   = $response->get_data()['schema'] ?? array();

		// Assert.
		$this->assertArrayHasKey( 'settled_order_status', $schema['properties'] );
		$this->assertSame( array( 'string', 'null' ), $schema['properties']['settled_order_status']['type'] );
	}

	/**
	 * Simulate Pro, which lifts the free plugin's cash/card-only restriction.
	 */
	private function allow_all_gateways_for_pos(): void {
		remove_all_filters( 'option_woocommerce_pos_settings_payment_gateways' );
	}

	/**
	 * Store POS payment-gateway settings for one gateway.
	 *
	 * @param string $gateway_id   Gateway id.
	 * @param string $order_status Configured status, with the 'wc-' prefix.
	 * @param bool   $enabled      Whether the gateway is enabled for POS.
	 */
	private function set_gateway_settings( string $gateway_id, string $order_status, bool $enabled = true ): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					$gateway_id => array(
						'order'        => 0,
						'enabled'      => $enabled,
						'order_status' => $order_status,
					),
				),
			)
		);
	}

	/**
	 * Index catalog items by gateway id.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function index_by_id( $data ): array {
		$indexed = array();

		foreach ( (array) $data as $item ) {
			if ( \is_array( $item ) && isset( $item['id'] ) ) {
				$indexed[ (string) $item['id'] ] = $item;
			}
		}

		return $indexed;
	}
}
