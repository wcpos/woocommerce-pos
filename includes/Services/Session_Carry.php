<?php
/**
 * Carry a drawer from one register to another.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Close before opening: a failed target opening never reopens the source; the target is re-read under its own lock, so a register retired or moved to another store meanwhile takes no session. */
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
		// An id that already names something else is refused before the drawer changes.
		if ( $closure && ( $closure['session_id'] !== $session['id'] || 'store_switch' !== $closure['reason'] || $closure['carried_to_register_id'] !== $target['id'] ) ) {
			return new \WP_Error( 'wcpos_closure_session_invalid', __( 'The session cannot be closed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( $opened && ( $opened['register_id'] !== $target['id'] || $opened['carried_from_closure_id'] !== $ids['closure_id'] ) ) {
			return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
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
		$closure = $this->close( $closures, $sessions, $session, $target, $ids['closure_id'], $at_gmt );
		if ( is_wp_error( $closure ) ) {
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
				'counted_float' => $closure['counted']['cash'] ?? '0.0000',
				'expected_float' => $closure['counted']['cash'] ?? '0.0000',
				'float_source' => 'carried',
				'carried_from_closure_id' => $ids['closure_id'],
			),
			function ( $fields ) use ( $target ) {
				$fresh = ( new Register_Store() )->get( $target['id'] );
				return $fresh && $fresh['store_id'] === $target['store_id']
					? $fields
					: new \WP_Error(
						'rest_invalid_param',
						__( 'Invalid parameter.', 'woocommerce-pos' ),
						array(
							'status' => 400,
							'param' => 'to_register_id',
						)
					);
			}
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

	/** Close the open source at the snapshot's expected; a refused, failed or foreign closure resumes selling. A count another device started under the lock reads as a successful transition and is refused by its stamp; a replayed closure to another target funds nothing twice.
	 *
	 * @param Closure_Store          $closures Closures.
	 * @param Register_Session_Store $sessions Sessions.
	 * @param array                  $session The open source row.
	 * @param array                  $target The target register.
	 * @param string                 $closure_id Client-minted closure id.
	 * @param string                 $at_gmt Closing time.
	 * @return array|\WP_Error
	 * @throws \RuntimeException On database failure.
	 */
	private function close( Closure_Store $closures, Register_Session_Store $sessions, array $session, array $target, string $closure_id, string $at_gmt ) {
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
		if ( get_current_user_id() !== (int) $session['counting_by'] || $at_gmt !== $session['counting_started_at_gmt'] ) {
			return new \WP_Error( 'wcpos_session_transition_refused', __( 'The session state has changed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		try {
			$closure = $closures->create(
				array(
					'id' => $closure_id,
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
					'till_expected' => null,
					'counted' => null,
					'reason' => 'store_switch',
					'carried_to_register_id' => $target['id'],
				)
			);
		} catch ( \RuntimeException $error ) {
			$this->back_to_selling( $sessions, $session );
			throw $error;
		}
		if ( ! is_wp_error( $closure ) && ( $closure['session_id'] !== $session['id'] || 'store_switch' !== $closure['reason'] || $closure['carried_to_register_id'] !== $target['id'] ) ) {
			$closure = new \WP_Error( 'wcpos_closure_session_invalid', __( 'The session cannot be closed.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( is_wp_error( $closure ) ) {
			$this->back_to_selling( $sessions, $session );
		}
		return $closure;
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
