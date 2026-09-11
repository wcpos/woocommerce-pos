<?php
/**
 * Fiscal history observers.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WC_Order_Refund;

/** Writes history only; never changes order or payment state. */
final class Fiscal_Record_Writers {
	/**
	 * Singleton keeps the order-service listeners armed once.
	 *
	 * @var self|null
	 */
	private static $instance;
	/**
	 * Shared insert authority.
	 *
	 * @var Fiscal_Record_Store
	 */
	private $store;

	/** Arm observers when the order services become ready. */
	private function __construct() {
		$this->store = new Fiscal_Record_Store();
		add_action( 'woocommerce_pos_payment_voided', array( $this, 'handle_void' ), 10, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_cancellation' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 10, 2 );
	}

	/** Construct once from the order-services-ready hook. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Insert through the store and make a failed write visible on the order.
	 *
	 * The business transition has already happened when a writer runs, so a
	 * refused insert (lock timeout, database error) cannot roll it back. The
	 * sale row is self-repairing on the next payment_complete; the others are
	 * surfaced as an order note and an action for a recovery job to pick up.
	 *
	 * @param WC_Order $order  Order the record describes.
	 * @param array    $fields Record fields.
	 */
	private function write( WC_Order $order, array $fields ): void {
		if ( null !== $this->store->record( $fields ) ) {
			return;
		}
		$type = (string) ( $fields['type'] ?? '' );
		/* translators: %s: fiscal record type (sale, refund, void, cancellation). */
		$order->add_order_note( sprintf( __( 'POS fiscal %s record could not be written; it will need recovery.', 'woocommerce-pos' ), $type ) );
		/**
		 * Fires when a write-once fiscal record could not be inserted.
		 *
		 * @param string   $type   Record type.
		 * @param WC_Order $order  Order.
		 * @param array    $fields Record fields as offered to the store.
		 */
		do_action( 'woocommerce_pos_fiscal_record_failed', $type, $order, $fields );
	}

	/**
	 * Store or repair the sale using the receipt's already-minted sequence.
	 *
	 * @param WC_Order $order Source order.
	 * @param array    $snapshot Stored snapshot.
	 * @param int      $sequence Receipt sequence.
	 */
	public function record_sale( WC_Order $order, array $snapshot, int $sequence ): void {
		// Snapshot capture also runs for storefront orders; fiscal history is POS-only.
		if ( wcpos_is_pos_order( $order ) ) {
			$this->write(
				$order,
				array_merge(
					$this->store->provenance_from_order( $order ),
					array(
						'type' => 'sale',
						'order_id' => $order->get_id(),
						'number' => $sequence,
						'payload' => $snapshot,
					)
				)
			);
		}
	}

	/**
	 * Record only the reversal of an already captured payment.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $previous_row Row before the void.
	 * @param array    $applied Row after the void.
	 * @param string   $reason Operator reason.
	 */
	public function handle_void( WC_Order $order, array $previous_row, array $applied, string $reason ): void {
		if ( 'captured' !== $previous_row['status'] || 'voided' !== $applied['status'] || ! wcpos_is_pos_order( $order ) ) {
			return;
		}
		$this->write(
			$order,
			array_merge(
				$this->store->provenance_from_order( $order ),
				array(
					'type' => 'void',
					'order_id' => $order->get_id(),
					'payment_id' => $previous_row['id'],
					'corrects_record_id' => $this->store->find_sale( $order->get_id() )['id'] ?? null,
					'device_time' => null,
					'device_tz' => null,
					'payload' => array(
						'row' => $previous_row,
						'voided_row' => $applied,
						'reason' => $reason,
						'actor_id' => get_current_user_id(),
					),
				)
			)
		);
	}

	/**
	 * A cancelled unpaid cart has no fiscal sale to cancel.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order.
	 */
	public function handle_cancellation( int $order_id, WC_Order $order ): void {
		if ( ! wcpos_is_pos_order( $order ) ) {
			return;
		}
		$sale = $this->store->find_sale( $order_id );
		if ( ! $sale ) {
			return;
		}
		$this->write(
			$order,
			array_merge(
				$this->store->provenance_from_order( $order ),
				array(
					'type' => 'cancellation',
					'order_id' => $order_id,
					'corrects_record_id' => $sale['id'],
					'payload' => array(
						'sale' => array(
							'record_id' => $sale['id'],
							'number' => $sale['number'],
							'immutable_id' => $sale['payload']['fiscal']['immutable_id'] ?? null,
							'checksum' => $sale['checksum'],
						),
						'actor_id' => get_current_user_id(),
						'cancelled_at_gmt' => current_time( 'mysql', true ),
					),
				)
			)
		);
	}

	/**
	 * Capture refunds from every lane, including wp-admin.
	 *
	 * @param int $order_id Parent order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function handle_refund( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof WC_Order || ! wcpos_is_pos_order( $order ) || ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		$sale = $this->store->find_sale( $order_id );
		$this->write(
			$order,
			array_merge(
				$this->store->provenance_from_order( $order ),
				array(
					'type' => 'refund',
					'order_id' => $order_id,
					'refund_id' => $refund_id,
					'corrects_record_id' => $sale['id'] ?? null,
					'device_time' => null,
					'device_tz' => null,
					'cashier_id' => (int) $refund->get_refunded_by(),
					'payload' => static function ( int $number ) use ( $order, $refund, $sale ): array {
						return ( new Receipt_Data_Builder() )->build_refund_document( $order, $refund, $number, $sale['payload']['fiscal']['immutable_id'] ?? null );
					},
				)
			)
		);
	}
}
