<?php
/**
 * Closure print bookkeeping beside the order receipt counter.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Count only a produced document; the row update serializes copy numbers. */
final class Closure_Print_Counter {
	/** Render under a closure's next count, rolling back failed output.
	 *
	 * @param array    $data Document payload.
	 * @param callable $render Renderer accepting the marked payload.
	 * @return mixed Rendered output.
	 * @throws \RuntimeException When print bookkeeping fails.
	 */
	public function count_after( array $data, callable $render ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Closure print transaction failed.' );
		}
		$committed = false;
		try {
			$row = ( new Closure_Store() )->record_print( $data['closure']['id'] );
			if ( ! $row ) {
				throw new \RuntimeException( 'Closure not found.' );
			}
			$data['fiscal']['is_reprint'] = $row['print_count'] > 1;
			$data['fiscal']['reprint_count'] = max( 0, $row['print_count'] - 1 );
			$data['closure']['print_count'] = $row['print_count'];
			$data['closure']['last_printed_at_gmt'] = $row['last_printed_at_gmt'];
			$result = $render( $data );
			if ( '' !== $result ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'Closure print commit failed.' );
				}
				$committed = true;
			}
			return $result;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}
}
