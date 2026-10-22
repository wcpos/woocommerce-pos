<?php
/**
 * The body a v2 order push may forward to wc/v3.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WP_Error;

/**
 * Answers "what may a push write?" (ADR 0040 rule 2, D4): shape() returns exactly the body wc/v3
 * receives, or the 422 for a status the till may not push. Each step runs on the previous one's
 * output, in a fixed order:
 *
 * 1. Status: the `wc-` prefix is stripped; an echoed non-pushable current status is dropped; any
 *    other non-pushable status is refused and no further step runs.
 * 2. Top level: the money fields and `tax_ids` (persisted separately) are dropped; `created_via` is
 *    set on create and dropped on update.
 * 3. Order meta: every server-owned key, in any spelling the meta tables match to it, and every entry
 *    addressing a protected stored row by meta id under any key (a `value: null` tombstone and a
 *    keyless `{id, value}` entry included, since WooCommerce resolves an entry by id first).
 * 4. Lines: Order_Write_Payload's create or update shape, which reconciles line ids before it drops
 *    item storage meta by key and by meta id (so a line matched only by uuid is covered).
 *
 * No save, no hook and no read beyond the order handed in; the strips are silent.
 */
final class Pushable_Document {
	/**
	 * The statuses a till may push (D4): the open statuses and cancel. `pos-partial` is derived from
	 * the ledger, and `failed` and every paid status are the server's.
	 */
	public const STATUSES = array( 'pos-open', 'pending', 'on-hold', 'cancelled' );

	/**
	 * Line and identity shaping shared with v1.
	 *
	 * @var Order_Write_Payload
	 */
	private Order_Write_Payload $lines;

	/**
	 * Build over the line shaping.
	 *
	 * @param Order_Write_Payload $lines Line and identity shaping.
	 */
	public function __construct( Order_Write_Payload $lines ) {
		$this->lines = $lines;
	}

	/**
	 * The forwardable body for a create (`$id` 0) or an update.
	 *
	 * @param array                   $payload Client document.
	 * @param int                     $id      Order id, 0 on create.
	 * @param \WC_Abstract_Order|null $order   On update, the order as read under the order lock; null
	 *                                         when it did not resolve (no stored row is then protected by id).
	 * @return array|WP_Error
	 */
	public function shape( array $payload, int $id = 0, ?\WC_Abstract_Order $order = null ) {
		$forward = self::with_pushable_status( $payload, $order ? $order->get_status() : null );
		if ( is_wp_error( $forward ) ) {
			return $forward;
		}
		foreach ( Pos_Order_Audit::SERVER_MONEY_FIELDS as $field ) {
			unset( $forward[ $field ] );
		}
		unset( $forward['tax_ids'], $forward['created_via'] );
		if ( 0 === $id ) {
			$forward['created_via'] = 'woocommerce-pos';
		}
		if ( isset( $forward['meta_data'] ) && is_array( $forward['meta_data'] ) ) {
			$forward['meta_data'] = Pos_Order_Audit::strip_audit_meta( $forward['meta_data'], Pos_Order_Audit::audit_meta_ids( $order ) );
		}

		return 0 === $id ? $this->lines->for_create( $forward ) : $this->lines->for_update( $id, $forward );
	}

	/**
	 * The payload with its `status` normalised, or the 422. An absent or empty status is untouched
	 * (wc/v3 defaults a create to `pending`).
	 *
	 * @param array       $payload Client payload.
	 * @param string|null $current The order's status on the locked read, or null on create.
	 * @return array|WP_Error
	 */
	private static function with_pushable_status( array $payload, ?string $current ) {
		if ( ! isset( $payload['status'] ) || '' === $payload['status'] ) {
			return $payload;
		}
		$status = is_scalar( $payload['status'] ) ? (string) $payload['status'] : '';
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		if ( null !== $current && $status === $current && ! in_array( $status, self::STATUSES, true ) ) {
			unset( $payload['status'] );
			return $payload;
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error(
				'wcpos_status_not_pushable',
				'This order status is set by the server, not pushed by the till.',
				array(
					'status'    => 422,
					'requested' => $status,
					'pushable'  => self::STATUSES,
				)
			);
		}
		$payload['status'] = $status;
		return $payload;
	}
}
