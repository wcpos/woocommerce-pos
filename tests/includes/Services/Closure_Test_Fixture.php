<?php
/**
 * Closure fixtures.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;

/** Real database fixtures, including cleanup of committed transactions. */
trait Closure_Test_Fixture {
	/** Fixture register IDs.
	 *
	 * @var array
	 */
	private $closure_registers = array();

	/** Remove committed fixture rows. */
	public function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit lifecycle method supplied by the trait.
		parent::tearDown();
		global $wpdb;
		foreach ( $this->closure_registers as $id ) {
			foreach ( ( new Register_Session_Store() )->list( array( 'register_id' => $id ) ) as $session ) {
				$wpdb->delete( ( new Cash_Movement_Store() )->table_name(), array( 'session_id' => $session['id'] ) );
			}
			foreach ( array( new Closure_Store(), new Register_Session_Store(), new Fiscal_Record_Store() ) as $store ) {
				$wpdb->delete( $store->table_name(), array( 'register_id' => $id ) );
			}
			$wpdb->delete( ( new Register_Store() )->table_name(), array( 'id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	/** Create a session.
	 *
	 * @param string|null $register_id Existing register.
	 * @param string      $status Desired state.
	 */
	private function closure_session( ?string $register_id = null, string $status = 'counting' ): array {
		if ( null === $register_id ) {
			$register_id = ( new Register_Store() )->create(
				array(
					'name' => 'Closure fixture',
					'store_id' => 456,
				)
			)['id'];
			$this->closure_registers[] = $register_id;
		}
		$store = new Register_Session_Store();
		$session = $store->create(
			array(
				'id' => wp_generate_uuid4(),
				'register_id' => $register_id,
				'store_id' => 456,
				'opened_at_gmt' => '2026-09-11 08:00:00',
				'opened_by' => get_current_user_id(),
				'expected_float' => null,
				'counted_float' => '100',
			)
		);
		if ( 'open' !== $status ) {
			$session = $store->transition(
				$session,
				array(
					'status' => 'counting',
					'counting_started_at_gmt' => '2026-09-11 11:00:00',
				)
			);
		}
		if ( 'closed' === $status ) {
			$session = $store->transition(
				$session,
				array(
					'status' => 'closed',
					'closed_by' => get_current_user_id(),
					'closed_at_gmt' => '2026-09-11 12:00:00',
					'counted' => array( 'cash' => '100' ),
				)
			);
		}
		return $session;
	}

	/** Build a closure submission.
	 *
	 * @param array $session Session row.
	 * @param int   $number Closure number.
	 */
	private function closure_fields( array $session, int $number = 1 ): array {
		return array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'number' => $number,
			'opened_at_gmt' => $session['opened_at_gmt'],
			'closed_at_gmt' => '2026-09-11 12:00:00',
			'till_expected' => array( 'cash' => '100.0000' ),
			'counted' => array( 'cash' => '101.0000' ),
			'period_sales_total' => '0.0000',
			'period_refunds_total' => '0.0000',
			'perpetual_sales_total' => '0.0000',
			'perpetual_refunds_total' => '0.0000',
			'unsynced_count' => 0,
			'unsynced_total' => '0.0000',
			'first_sale_counter' => null,
			'last_sale_counter' => null,
			'software_version' => 'fixture',
			'printed_at_gmt' => null,
			'breakdowns' => array( 'transaction_count' => 0 ),
		);
	}

	/** Seed captured sales, refund forms and excluded rows.
	 *
	 * @param array $session Session row.
	 */
	private function closure_ledger( array $session ): \WC_Order {
		$order = new \WC_Order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '1000' );
		$order->update_meta_data( '_wcpos_session', $session['id'] );
		$order->update_meta_data( '_wcpos_register', $session['register_id'] );
		$order->save();
		$base = array(
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'status' => 'captured',
			'session_id' => $session['id'],
		);
		$rows = array();
		foreach ( array(
			array(
				'amount' => '50',
				'refunded_amount' => '1',
			),
			array(
				'amount' => '3',
				'kind' => 'refund',
			),
			array( 'amount' => '-6' ),
			array(
				'amount' => '30',
				'method_id' => 'pos_card',
			),
			array(
				'amount' => '999',
				'status' => 'pending',
			),
			array(
				'amount' => '999',
				'session_id' => wp_generate_uuid4(),
			),
		) as $fields ) {
			$rows[] = array_merge( $base, $fields, array( 'id' => wp_generate_uuid4() ) );
		}
		Ledger::instance()->save( $order, $rows, false );
		return $order;
	}
}
