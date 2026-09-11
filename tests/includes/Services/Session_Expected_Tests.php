<?php
/**
 * Shared register-session balance fixture.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;

/** Exercise owner and v2 detail balances against the same seeded ledger. */
trait Session_Expected_Tests {
	/** The seeded contract is also exposed unchanged by the v2 detail resource. */
	public function test_expected_seeded_ledger_and_distinct_sales_count(): void {
		$store = new Register_Session_Store();
		$register = ( new Register_Store() )->create( array( 'name' => 'Expected fixture' ) );
		$session = $store->create(
			array(
				'id' => wp_generate_uuid4(),
				'register_id' => $register['id'],
				'opened_at_gmt' => '2026-09-11 10:00:00',
				'opened_by' => get_current_user_id(),
				'expected_float' => null,
				'counted_float' => '100',
			)
		);
		$this->assertSame( array( 'cash' => '100.0000' ), $store->expected( $session ) );
		$this->assertSame( 0, $store->sales_count( $session ) );
		$movements = new Cash_Movement_Store();
		$base = array(
			'session_id' => $session['id'],
			'reason' => 'Fixture',
			'actor' => get_current_user_id(),
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		foreach ( array( array( 'paid_in', '20' ), array( 'paid_out', '5' ), array( 'paid_out', '7' ) ) as list( $type, $amount ) ) {
			$movement = $movements->create(
				$base + array(
					'id' => wp_generate_uuid4(),
					'type' => $type,
					'amount' => $amount,
				)
			);
		}
		$movements->create(
			$base + array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0',
				'voids' => $movement['id'],
			)
		);
		// Resume the test transaction after the production void transaction commits.
		$this->start_transaction();
		$ledger = Ledger::instance();
		$enable_card = static function ( $settings ) {
			$settings['gateways']['pos_card']['enabled'] = true;
			return $settings;
		};
		add_filter( 'woocommerce_pos_payment_gateways_settings', $enable_card );
		try {
			foreach ( array(
				'pos_cash' => '50',
				'pos_card' => '30',
			) as $method => $amount ) {
				$order = new \WC_Order();
				$order->set_total( '1000' );
				$order->save();
				$payment = $ledger->record(
					$order,
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => $method,
						'amount' => $amount,
						'session_id' => $session['id'],
					)
				);
				$this->assertIsArray( $payment );
				$this->assertSame( 'captured', $payment['status'] );
				$rows = $ledger->read( $order );
				if ( 'pos_cash' === $method ) {
					// The ledger records a refund on the original row's refunded_amount (no refund row).
					$rows[0]['refunded_amount'] = '10.00';
				}
				foreach ( array( 'authorized', 'pending', 'failed', 'voided' ) as $status ) {
					$rows[] = array_merge(
						$payment,
						array(
							'id' => wp_generate_uuid4(),
							'status' => $status,
							'amount' => '999',
						)
					);
				}
				$rows[] = array_merge(
					$payment,
					array(
						'id' => wp_generate_uuid4(),
						'session_id' => wp_generate_uuid4(),
						'amount' => '999',
					)
				);
				$ledger->save( $order, $rows, false );
			}
			$this->assertSame(
				array(
					'cash' => '155.0000',
					'card' => '30.0000',
				),
				$store->expected( $session )
			);
			$this->assertSame( 2, $store->sales_count( $session ) );
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/sessions/' . $session['id'] ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $store->expected( $session ), $response->get_data()['expected'] );
			$this->assertSame( 2, $response->get_data()['sales_count'] );
			$this->assertCount( 4, $response->get_data()['movements'] );
		} finally {
			remove_filter( 'woocommerce_pos_payment_gateways_settings', $enable_card );
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
			$wpdb->delete( $movements->table_name(), array( 'session_id' => $session['id'] ) );
			$wpdb->delete( $store->table_name(), array( 'id' => $session['id'] ) );
			$wpdb->delete( ( new Register_Store() )->table_name(), array( 'id' => $register['id'] ) );
			$this->start_transaction();
		}
	}
}
