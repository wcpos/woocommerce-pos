<?php
/**
 * Fiscal history observers.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WC_Order_Refund;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

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
		add_action( 'woocommerce_pos_refund_allocated', array( $this, 'handle_allocation' ), 10, 4 );
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
			$closure = ( new Closure_Store() )->for_session( (string) $order->get_meta( '_wcpos_session', true ) );
			$sale = $this->store->find_sale( $order->get_id() );
			$snapshot_created = (string) $order->get_meta( Receipt_Snapshot_Store::META_KEY_CREATED_AT, true );
			// A replay of a sale already included in the closure is not a late sale.
			if ( $closure && $sale && '' !== $snapshot_created && $snapshot_created >= $closure['received_at_gmt'] && $sale['id'] > ( $closure['last_receipt_id'] ?? 0 ) ) {
				$rows = array_values(
					array_filter(
						\WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( $order ),
						static function ( $row ) use ( $closure ) {
							return 'captured' === ( $row['status'] ?? null ) && ( $row['session_id'] ?? null ) === $closure['session_id'];
						}
					)
				);
				$cash = array();
				foreach ( $rows as $row ) {
					// Every cash-kind gateway is the drawer.
					if ( 'cash' === ( $row['kind'] ?? '' ) ) {
						$cash[] = $row['amount'];
					}
				}
				$delta = array( 'cash' => Closure_Store::sum( $cash ) );
				$this->write(
					$order,
					array_merge(
						$this->store->provenance_from_order( $order ),
						array(
							'type' => 'late_sale',
							'order_id' => $order->get_id(),
							'closure_id' => $closure['id'],
							'corrects_record_id' => $sale['id'],
							'payload' => array(
								'tender_rows' => $rows,
								'expected_delta' => $delta,
								'variance_delta' => ( new Closure_Store() )->variance( array( 'cash' => '0' ), $delta ),
							),
						)
					)
				);
			}
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
		$provenance = $this->store->provenance_from_order( $order );
		foreach ( array(
			'register_id' => '_wcpos_register',
			'session_id' => '_wcpos_session',
		) as $key => $meta ) {
			$value = $refund->get_meta( $meta, true );
			$provenance[ $key ] = Pos_Uuid::is_uuid( $value ) ? strtolower( $value ) : null;
		}
		$session = $provenance['session_id'] ? ( new Register_Session_Store() )->get( $provenance['session_id'] ) : null;
		$provenance['store_id'] = $session['store_id'] ?? $provenance['store_id'];
		$this->write(
			$order,
			array_merge(
				$provenance,
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
		$closure = $provenance['session_id'] ? ( new Closure_Store() )->for_session( $provenance['session_id'] ) : null;
		$record = $this->store->find_refund( $order_id, $refund_id );
		// Replaying a refund received before the closure does not make it late.
		if ( $closure && $record && $record['received_at_gmt'] >= $closure['received_at_gmt'] ) {
			$sessions = new Register_Session_Store();
			$rows = $sessions->refund_tender_rows( $refund );
			$delta = array( 'cash' => $sessions->expected( array( 'counted_float' => '0' ), array(), array(), $rows )['cash'] );
			$this->write(
				$order,
				array_merge(
					$provenance,
					array(
						'type' => 'late_refund',
						'source_id' => 'refund:' . $refund_id,
						'order_id' => $order_id,
						'refund_id' => $refund_id,
						'closure_id' => $closure['id'],
						'corrects_record_id' => $record['id'],
						'cashier_id' => (int) $refund->get_refunded_by(),
						'device_time' => null,
						'device_tz' => null,
						'payload' => array(
							'reason' => (string) $refund->get_reason(),
							'amount' => Closure_Store::sum( array( (string) $refund->get_amount() ) ),
							'tender_rows' => $rows,
							'expected_delta' => $delta,
							'variance_delta' => ( new Closure_Store() )->variance( array( 'cash' => '0' ), $delta ),
						),
					)
				)
			);
		}
	}

	/** Record a post-closure shift from unallocated cash to a payment tender.
	 *
	 * @param WC_Order        $order Parent order.
	 * @param array           $row Allocated payment row.
	 * @param WC_Order_Refund $refund Refund object.
	 * @param string          $amount Normalized allocation amount.
	 */
	public function handle_allocation( WC_Order $order, array $row, WC_Order_Refund $refund, string $amount ): void {
		$session_id = $refund->get_meta( '_wcpos_session', true );
		$closure = Pos_Uuid::is_uuid( $session_id ) ? ( new Closure_Store() )->for_session( strtolower( $session_id ) ) : null;
		if ( ! wcpos_is_pos_order( $order ) || ! $closure ) {
			return;
		}
		foreach ( $this->store->list(
			array(
				'type' => 'late_refund',
				'order_id' => $order->get_id(),
			)
		) as $record ) {
			if ( $record['refund_id'] === $refund->get_id() && ! isset( $record['payload']['kind'] ) ) {
				return;
			}
		}
		$provenance = $this->store->provenance_from_order( $order );
		$register_id = $refund->get_meta( '_wcpos_register', true );
		$provenance['store_id'] = ( new Register_Session_Store() )->get( strtolower( $session_id ) )['store_id'] ?? $provenance['store_id'];
		$amount = Closure_Store::sum( array( $amount ) );
		$delta = array( 'cash' => $amount );
		$tender = array_intersect_key( $row, array_flip( array( 'method_id', 'kind' ) ) );
		$tender['amount'] = '-' . $amount;
		$tender['refund_id'] = $refund->get_id();
		$cash = $tender;
		$cash['method_id'] = 'pos_cash';
		$cash['kind'] = 'cash';
		$cash['amount'] = $amount;
		$provenance['type'] = 'late_refund';
		// The column is CHAR(36): a deterministic UUID-shaped digest of the allocation identity.
		$digest = md5( 'refund:' . $refund->get_id() . ':' . $row['id'] );
		$provenance['source_id'] = substr( $digest, 0, 8 ) . '-' . substr( $digest, 8, 4 ) . '-' . substr( $digest, 12, 4 ) . '-' . substr( $digest, 16, 4 ) . '-' . substr( $digest, 20, 12 );
		$provenance['order_id'] = $order->get_id();
		$provenance['refund_id'] = $refund->get_id();
		$provenance['session_id'] = strtolower( $session_id );
		$provenance['register_id'] = Pos_Uuid::is_uuid( $register_id ) ? strtolower( $register_id ) : null;
		$provenance['closure_id'] = $closure['id'];
		$provenance['corrects_record_id'] = $this->store->find_refund( $order->get_id(), $refund->get_id() )['id'] ?? null;
		$provenance['cashier_id'] = (int) $refund->get_refunded_by();
		$provenance['device_time'] = null;
		$provenance['device_tz'] = null;
		$provenance['payload'] = array(
			'kind' => 'reallocation',
			'reason' => (string) $refund->get_reason(),
			'amount' => $amount,
			'tender_rows' => array( $tender, $cash ),
			'expected_delta' => $delta,
			'variance_delta' => ( new Closure_Store() )->variance( array( 'cash' => '0' ), $delta ),
		);
		$this->write( $order, $provenance );
	}
}
