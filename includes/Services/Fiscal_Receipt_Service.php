<?php
/**
 * Fiscal receipt service.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Fiscal_Receipt_Service class.
 */
class Fiscal_Receipt_Service {
	/**
	 * Submission status meta key.
	 */
	const META_KEY_SUBMISSION_STATUS = '_wcpos_receipt_submission_status';

	/**
	 * Submission status updated timestamp meta key.
	 */
	const META_KEY_STATUS_UPDATED_AT = '_wcpos_receipt_submission_status_updated_at';

	/**
	 * Valid submission statuses.
	 */
	const VALID_STATUSES = array( 'pending', 'sent', 'failed' );

	/**
	 * Enrich fiscal payload via extension hook.
	 *
	 * @param array $snapshot Fiscal snapshot payload.
	 * @param int   $order_id Order ID.
	 *
	 * @return array
	 */
	public function enrich_snapshot( array $snapshot, int $order_id ): array {
		$enriched = apply_filters( 'woocommerce_pos_fiscal_snapshot_enrich', $snapshot, $order_id );

		return \is_array( $enriched ) ? $enriched : $snapshot;
	}

	/**
	 * Persist fiscal submission status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Submission status.
	 */
	public function set_submission_status( int $order_id, string $status ): void {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return;
		}

		// Through the order object, not update_post_meta(): on HPOS the order's meta
		// table is wc_orders_meta and a postmeta row is invisible to get_submission_status().
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_KEY_SUBMISSION_STATUS, $status );
		$order->update_meta_data( self::META_KEY_STATUS_UPDATED_AT, current_time( 'mysql', true ) );
		$order->save();
	}

	/**
	 * Get submission status for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public function get_submission_status( int $order_id ): string {
		$order  = wc_get_order( $order_id );
		$status = $order ? $order->get_meta( self::META_KEY_SUBMISSION_STATUS ) : '';

		if ( in_array( $status, self::VALID_STATUSES, true ) ) {
			return $status;
		}

		return 'pending';
	}

	/**
	 * Trigger retry hook and mark submission pending.
	 *
	 * @param int $order_id Order ID.
	 */
	public function retry_submission( int $order_id ): void {
		$this->set_submission_status( $order_id, 'pending' );
		do_action( 'woocommerce_pos_fiscal_submission_retry', $order_id );
	}
}
