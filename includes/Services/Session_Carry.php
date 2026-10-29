<?php
/**
 * Carry a drawer from one register to another.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Close before opening: a failed target opening never reopens the source. */
final class Session_Carry {
	/** Carry at server figures using the stores' existing write guards.
	 *
	 * @param array  $session Source session row, which must be open.
	 * @param array  $target Validated target register, in reach and not the source.
	 * @param array  $ids Client-minted closure_id and session_id UUIDs.
	 * @param string $at_gmt Closure and opening time, Y-m-d H:i:s GMT.
	 * @return array{closure: array, session: array}|\WP_Error
	 * @throws \RuntimeException On database failure.
	 */
	public function carry( array $session, array $target, array $ids, string $at_gmt ) {
		$closures = new Closure_Store();
		$sessions = new Register_Session_Store();
		$closure = $closures->get( $ids['closure_id'] );
		$opened = $sessions->get( $ids['session_id'] );
		if ( $closure && $opened ) {
			$closures->log_replay( $closure );
			$sessions->log_replay( $opened );
			return array(
				'closure' => $closure,
				'session' => $opened,
			);
		}
		if ( 'open' !== $session['status'] ) {
			return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		$open = $sessions->list(
			array(
				'register_id' => $target['id'],
				'not_closed' => true,
			)
		);
		if ( $open ) {
			return new \WP_Error(
				'wcpos_session_already_open',
				__( 'This register already has an open session.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'session_id' => $open[0]['id'],
				)
			);
		}
		$expected = Session_Figures::for_session( $session )->expected();
		$result = $sessions->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => $at_gmt,
				'counting_by' => get_current_user_id(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$session = $sessions->get( $session['id'] );
		try {
			$closure = $closures->create(
				array(
					'id' => $ids['closure_id'],
					'session_id' => $session['id'],
					'business_day' => $session['business_day'],
					'software_version' => 'woocommerce-pos/' . \WCPOS\WooCommercePOS\VERSION . ' carry',
					'breakdowns' => array(),
					'number' => $closures->last_number( $session['register_id'] ) + 1,
					'unsynced_count' => 0,
					'unsynced_total' => '0.0000',
					'first_sale_counter' => null,
					'last_sale_counter' => null,
					'opened_at_gmt' => $session['opened_at_gmt'],
					'closed_at_gmt' => $at_gmt,
					'printed_at_gmt' => null,
					'till_expected' => $expected,
					'counted' => $expected,
					'reason' => 'store_switch',
					'carried_to_register_id' => $target['id'],
				)
			);
		} catch ( \RuntimeException $error ) {
			$this->back_to_selling( $sessions, $session );
			throw $error;
		}
		// A replayed id that belongs to another session is not this drawer's closure.
		if ( ! is_wp_error( $closure ) && $closure['session_id'] !== $session['id'] ) {
			$closure = new \WP_Error( 'wcpos_closure_session_invalid', __( 'The session cannot be closed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( is_wp_error( $closure ) ) {
			$this->back_to_selling( $sessions, $session );
			return $closure;
		}
		$opened = $sessions->create(
			array(
				'id' => $ids['session_id'],
				'business_day' => $session['business_day'],
				'register_id' => $target['id'],
				'store_id' => $target['store_id'],
				'opened_at_gmt' => $at_gmt,
				'opened_by' => get_current_user_id(),
				'counted_float' => $expected['cash'] ?? '0.0000',
				'expected_float' => $expected['cash'] ?? '0.0000',
				'float_source' => 'carried',
				'carried_from_closure_id' => $ids['closure_id'],
			)
		);
		if ( is_wp_error( $opened ) ) {
			$opened->add_data( array_merge( (array) $opened->get_error_data(), array( 'closure' => $closure ) ) );
			return $opened;
		}
		return array(
			'closure' => $closure,
			'session' => $opened,
		);
	}

	/** A failure before the switch leaves everything as it was (spec § 4.6): the count is undone.
	 *
	 * @param Register_Session_Store $sessions Store.
	 * @param array                  $session The counting source row.
	 */
	private function back_to_selling( Register_Session_Store $sessions, array $session ): void {
		$sessions->transition(
			$session,
			array(
				'status' => 'open',
				'counting_started_at_gmt' => null,
				'counting_by' => null,
			)
		);
	}
}
