<?php
/**
 * Fiscal history observers.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WC_Order_Refund;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;

/** Writes history only; never changes order or payment state. */
final class Fiscal_Record_Writers {
	/**
	 * Sale documents whose insert failed, keyed by completion id: built at completion time and
	 * inserted unchanged by recover(), never rebuilt from the order as it is later. Server-owned.
	 */
	public const PENDING_META_KEY = '_wcpos_fiscal_pending';

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
		add_action( 'wcpos_sale_completed', array( $this, 'handle_sale_completed' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract action.
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
	 * sale row is recovered by the next money command or refund on the order (recover());
	 * every failure is surfaced as an order note and an action for a recovery job.
	 *
	 * @param WC_Order $order  Order the record describes.
	 * @param array    $fields Record fields.
	 */
	private function write( WC_Order $order, array $fields ): bool {
		if ( null !== $this->store->record( $fields ) ) {
			return true;
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
		return false;
	}

	/**
	 * Write the sale record for completion n, the one path every completion takes (ADR 0040 rule 1c).
	 *
	 * @param mixed $order Order, read after the completion entry was stored.
	 * @param mixed $n     Completion number.
	 */
	public function handle_sale_completed( $order, $n ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$completion = Completion_History::of( $order )->by_n( (int) $n );
		if ( null !== $completion ) {
			$this->record_sale_safely( $order, $completion );
		}
	}

	/**
	 * Insert every parked sale document (built at its completion, inserted unchanged), then let the
	 * completion's late correction follow. Nothing parked, nothing to recover: a document is never
	 * rebuilt from the order as it is now. Fires no completion event. The drain runs under the order
	 * lock on a fresh read of the parked map and the history, so a document parked by another request
	 * since the caller loaded the order is neither overwritten nor left without its late correction;
	 * while the lock is held elsewhere it is skipped and the next writer recovers.
	 *
	 * @param WC_Order $order Order; it serves only the pre-check and the id, the drain reads a fresh copy.
	 */
	public function recover( WC_Order $order ): void {
		if ( ! self::parked( $order ) ) {
			return; // A stale "nothing parked" only defers recovery to the next writer.
		}
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $order->get_id() ) ) {
			Logger::log( sprintf( 'Fiscal sale recovery on order #%d skipped: the order is locked elsewhere; the next writer recovers.', $order->get_id() ) );
			return;
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh instanceof WC_Order ) {
				return;
			}
			$fresh->read_meta_data( true );
			$history = Completion_History::of( $fresh );
			foreach ( self::parked( $fresh ) as $completion_id => $document ) {
				try {
					if ( null === $this->store->record( self::fields_of( $order, (array) $document ) ) ) {
						Logger::log( sprintf( 'Fiscal sale recovery for completion %s on order #%d failed again.', $completion_id, $order->get_id() ) );
						continue;
					}
					self::unpark( $fresh, (string) $completion_id );
					$entry = $history->by_id( (string) $completion_id );
					if ( null !== $entry ) {
						$this->record_sale_safely( $fresh, $entry ); // The row exists now: only its late correction runs.
					}
				} catch ( \Throwable $error ) {
					Logger::log( 'Fiscal sale recovery failed: ' . $error->getMessage() );
				}
			}
		} finally {
			$lock->release( $order->get_id() );
		}
	}

	/**
	 * Record a sale and make a failure visible, never interrupting the caller.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $completion Completion entry.
	 */
	private function record_sale_safely( WC_Order $order, array $completion ): void {
		try {
			$this->record_sale( $order, $completion );
		} catch ( \Throwable $error ) {
			Logger::log( 'Fiscal sale record failed: ' . $error->getMessage() );
			/* translators: %s: fiscal record type (sale, refund, void, cancellation). */
			$order->add_order_note( sprintf( __( 'POS fiscal %s record could not be written; it will need recovery.', 'woocommerce-pos' ), 'sale' ) );
			do_action( 'woocommerce_pos_fiscal_record_failed', 'sale', $order, array() );
		}
	}

	/**
	 * Store the sale record of one completion once, using the record store's minted sequence.
	 *
	 * Its predecessor is completion n−1: the record names that completion's sale row (when it
	 * landed), its total, and the signed adjustment from it (ADR 0040, fiscal section).
	 *
	 * @param WC_Order   $order      Source order.
	 * @param array|null $completion Completion entry; null for the latest one.
	 * @throws \Throwable Re-thrown after the built document is parked, when the insert throws.
	 */
	public function record_sale( WC_Order $order, ?array $completion = null ): void {
		$history    = Completion_History::of( $order );
		$completion = $completion ?? $history->latest();
		// Only a completion the server stamped at a register is a fiscal sale; a legacy entry's row already exists.
		if ( ! is_array( $completion ) || ! empty( $completion['legacy'] ) || ! is_string( $completion['id'] ?? null ) ) {
			return;
		}
		$n       = (int) $completion['n'];
		$missing = null === $this->store->find_sale( $order->get_id(), $completion['id'] );
		$parked  = self::parked( $order );
		if ( $missing && isset( $parked[ $completion['id'] ] ) ) {
			// Its document was built at the completion and parked when the insert failed: insert that.
			$this->recover( $order );
			return;
		}
		$standing = $history->standing();
		if ( $missing && ( null === $standing || $standing['id'] !== $completion['id'] ) ) {
			// Only the completion that stands may be built from the live order; any other has its parked
			// document or nothing (the failure note at its insert names it for a human).
			Logger::log( sprintf( 'Fiscal sale record for completion %d on order #%d was not built: the completion no longer stands and nothing is parked.', $n, $order->get_id() ) );
			return;
		}
		if ( $missing ) {
			$previous   = $history->by_n( $n - 1 );
			$prior      = $previous ? $this->store->find_sale( $order->get_id(), (string) $previous['id'] ) : null;
			$supersedes = $previous ? array(
				'record_id'     => $prior['id'] ?? null,
				'completion_id' => $previous['id'],
				'total'         => $previous['total'],
			) : null;
			$adjustment = $previous ? Money::format( Money::minor( $completion['total'] ) - Money::minor( $previous['total'] ) ) : null;
			// The builder reads the standing completion's till tuple: this one (checked above).
			$snapshot   = ( new Receipt_Data_Builder() )->build( $order, 'fiscal' );
			$provenance = $this->store->provenance_from_order( $order );
			unset( $snapshot['i18n'] );
			$document = array(
				'type'               => 'sale',
				'order_id'           => $order->get_id(),
				'source_id'          => strtolower( $completion['id'] ),
				'corrects_record_id' => $prior['id'] ?? null,
				'register_id'        => $completion['register'] ?? null,
				'session_id'         => $completion['session'] ?? null,
				'device_time'        => $completion['sale_time'] ?? null,
				'device_tz'          => $completion['sale_tz'] ?? null,
				'store_id'           => $provenance['store_id'],
				'cashier_id'         => $provenance['cashier_id'],
				'n'                  => $n,
				'supersedes'         => $supersedes,
				'adjustment'         => $adjustment,
				'snapshot'           => $snapshot,
			);
			try {
				$written = $this->write( $order, self::fields_of( $order, $document ) );
			} catch ( \Throwable $error ) {
				self::park( $order, $completion['id'], $document );
				throw $error;
			}
			if ( ! $written ) {
				self::park( $order, $completion['id'], $document );
				return;
			}
		}
		// Late against the closure of the session this completion took its money in.
		$session = (string) ( $completion['session'] ?? '' );
		$closure = ( new Closure_Store() )->for_session( '' !== $session ? $session : (string) $order->get_meta( '_wcpos_session', true ) );
		// The late correction belongs to this completion's own sale record: while that row is missing
		// it waits (recovery's later record_sale() writes it once the row exists).
		$sale = $this->store->find_sale( $order->get_id(), (string) $completion['id'] );
		if ( $closure && $sale ) {
			$source_id = Fiscal_Record_Store::digest_source_id( 'late_sale:' . $sale['id'] );
			// Legs an earlier completion's late correction already carried are not late again.
			$carried = array();
			foreach ( $this->store->list(
				array(
					'order_id' => $order->get_id(),
					'type'     => 'late_sale',
					'per_page' => Fiscal_Record_Store::MAX_PER_PAGE,
				)
			) as $earlier ) {
				if ( $earlier['source_id'] !== $source_id ) {
					$carried = array_merge( $carried, array_column( (array) ( $earlier['payload']['tender_rows'] ?? array() ), 'id' ) );
				}
			}
			$rows = array_values( array_filter( Session_Figures::late_legs( $order, $closure ), static fn( $leg ) => ! in_array( $leg['id'] ?? null, $carried, true ) ) );
			if ( $rows ) {
				$delta = Session_Figures::tender_totals( $rows );
				// Where and when the completion took the money, as the sale row says (the order's write-once
				// tuple only where the entry names nothing).
				$provenance = $this->store->provenance_from_order( $order );
				foreach ( array(
					'register_id' => 'register',
					'session_id'  => 'session',
					'device_time' => 'sale_time',
					'device_tz'   => 'sale_tz',
				) as $column => $field ) {
					$provenance[ $column ] = $completion[ $field ] ?? $provenance[ $column ];
				}
				$this->write(
					$order,
					array_merge(
						$provenance,
						array(
							'type' => 'late_sale',
							'order_id' => $order->get_id(),
							'source_id' => $source_id,
							'closure_id' => $closure['id'],
							'corrects_record_id' => $sale['id'],
							'payload' => array(
								'tender_rows' => self::tender_rows( $rows ),
								'expected_delta' => $delta,
								'variance_delta' => ( new Closure_Store() )->variance( array_fill_keys( array_keys( $closure['counted'] ), '0' ), $delta ),
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
		if ( 'captured' !== $previous_row['status'] || 'voided' !== $applied['status'] || ! $this->is_register_sale( $order ) ) {
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
						'row' => self::tender_rows( array( $previous_row ) )[0],
						'voided_row' => self::tender_rows( array( $applied ) )[0],
						'reason' => $reason,
						'actor_id' => get_current_user_id(),
					),
				)
			)
		);
	}

	/**
	 * Tender rows as a fiscal payload keeps them: without the server's command maps, which are
	 * idempotency bookkeeping (ADR 0040 rule 5), not facts of the sale.
	 *
	 * @param array $rows Ledger rows.
	 */
	private static function tender_rows( array $rows ): array {
		return array_map(
			static function ( array $row ): array {
				unset( $row['commands'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * A cancelled unpaid cart has no fiscal sale to cancel.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order.
	 */
	public function handle_cancellation( int $order_id, WC_Order $order ): void {
		if ( ! $this->is_register_sale( $order ) ) {
			return;
		}
		// The cancellation names the completion it cancels: the latest one, or for an order
		// completed before the history existed, its legacy sale (named by its row's digest).
		$latest = Completion_History::of( $order )->latest();
		$sale   = is_array( $latest ) ? $this->store->find_sale( $order_id, (string) $latest['id'] ) : $this->store->find_sale( $order_id );
		if ( ! $sale ) {
			return;
		}
		$completion_id = is_array( $latest ) ? strtolower( (string) $latest['id'] ) : Fiscal_Record_Store::digest_source_id( 'sale:' . $sale['id'] );
		$this->write(
			$order,
			array_merge(
				$this->store->provenance_from_order( $order ),
				array(
					'type' => 'cancellation',
					'order_id' => $order_id,
					'source_id' => $completion_id,
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
		if ( ! $order instanceof WC_Order || ! $this->is_register_sale( $order ) || ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		try {
			$sale       = $this->sale_for_refund( $order );
			$provenance = $this->refund_provenance( $order, $refund );
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
						'payload' => static function ( int $number ) use ( $order, $refund, $sale, $provenance ): array {
							return ( new Receipt_Data_Builder() )->build_refund_document( $order, $refund, $number, $sale['payload']['fiscal']['immutable_id'] ?? null, $provenance );
						},
					)
				)
			);
			$closure = $provenance['session_id'] ? ( new Closure_Store() )->for_session( $provenance['session_id'] ) : null;
			$record = $this->store->find_refund( $order_id, $refund_id );
			// A refund the closure counted (or a replay of one) is not late.
			if ( $closure && $record && ! Session_Figures::counted( $refund->get_meta( '_wcpos_closure', true ), $closure ) ) {
				$this->record_late_arrival( $order, $refund, $provenance, $record, $closure );
			}
		} catch ( \Throwable $error ) {
			// The refund is durable; fiscal bookkeeping must not fail its request, only signal.
			$this->fail_quietly( 'refund', $order, $refund, $error );
		}
	}

	/**
	 * A sale at a register: a POS order, or any order the server completed at one (an online order
	 * finished at the counter is a sale at that register).
	 *
	 * @param WC_Order $order Order.
	 */
	private function is_register_sale( WC_Order $order ): bool {
		return wcpos_is_pos_order( $order ) || ! Completion_History::of( $order )->is_empty();
	}

	/**
	 * The sale record a refund corrects: the latest completion's own record, or none while it is
	 * missing (never completion n−1's, and never rebuilt from the order the refund just changed). An
	 * order without a history (completed before it existed) keeps its latest sale. A parked sale
	 * document is drained first (under the order lock; skipped while another request holds it), so a
	 * refund written after a failed sale insert names the recovered sale; Fiscal_Record_Store::record()
	 * is idempotent by source_id under the sequence lock, so a recovery racing a money command inserts once.
	 *
	 * @param WC_Order $order Parent order.
	 */
	private function sale_for_refund( WC_Order $order ): ?array {
		$this->recover( $order );
		$latest = Completion_History::of( $order )->latest();
		if ( ! is_array( $latest ) ) {
			return $this->store->find_sale( $order->get_id() );
		}
		return $this->store->find_sale( $order->get_id(), (string) $latest['id'] );
	}

	/**
	 * The store fields of a sale document: the parked columns, and a payload that stamps the minted
	 * number into the completion-time snapshot and runs the enrich filter.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $document Built (or parked) document.
	 */
	private static function fields_of( WC_Order $order, array $document ): array {
		$fields            = array_intersect_key( $document, array_flip( array( 'type', 'order_id', 'source_id', 'corrects_record_id', 'register_id', 'session_id', 'device_time', 'device_tz', 'store_id', 'cashier_id' ) ) );
		$order_id          = $order->get_id();
		$snapshot          = (array) ( $document['snapshot'] ?? array() );
		$n                 = (int) ( $document['n'] ?? 0 );
		$supersedes        = $document['supersedes'] ?? null;
		$adjustment        = $document['adjustment'] ?? null;
		$fields['payload'] = static function ( int $number ) use ( $order_id, $snapshot, $n, $supersedes, $adjustment ): array {
			$snapshot['fiscal']['sequence'] = $number;
			$snapshot['fiscal']['receipt_number'] = (string) $number;
			$snapshot['fiscal']['immutable_id'] = $order_id . ':' . $number;
			$snapshot['fiscal']['completion'] = $n;
			$snapshot['fiscal']['supersedes'] = $supersedes;
			$snapshot['fiscal']['adjustment'] = $adjustment;
			/**
			 * Enrich the numbered, label-free document before storage.
			 *
			 * Runs under wcpos_fiscal_sequence_lock (five-second wait,
			 * shared by all record types): local computation only, no
			 * network I/O. Submit asynchronously after the row exists.
			 *
			 * @param array $snapshot Fiscal document.
			 * @param int   $order_id Order ID.
			 */
			$enriched = apply_filters( 'woocommerce_pos_fiscal_snapshot_enrich', $snapshot, $order_id );
			return is_array( $enriched ) ? $enriched : $snapshot;
		};
		return $fields;
	}

	/**
	 * The order's parked sale documents, keyed by completion id.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function parked( WC_Order $order ): array {
		$raw     = $order->get_meta( self::PENDING_META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Park a sale document whose insert failed.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $completion_id Completion entry id.
	 * @param array    $document      The built document.
	 */
	private static function park( WC_Order $order, string $completion_id, array $document ): void {
		$parked                                 = self::parked( $order );
		$parked[ strtolower( $completion_id ) ] = $document;
		$order->update_meta_data( self::PENDING_META_KEY, wp_json_encode( $parked ) );
		$order->save_meta_data();
	}

	/**
	 * Drop a parked document once its record exists.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $completion_id Completion entry id.
	 */
	private static function unpark( WC_Order $order, string $completion_id ): void {
		$parked = self::parked( $order );
		unset( $parked[ $completion_id ] );
		if ( $parked ) {
			$order->update_meta_data( self::PENDING_META_KEY, wp_json_encode( $parked ) );
		} else {
			$order->delete_meta_data( self::PENDING_META_KEY );
		}
		$order->save_meta_data();
	}

	/** Log a lost correction and fire the recovery signal without failing the caller.
	 *
	 * @param string          $type Record type.
	 * @param WC_Order        $order Parent order.
	 * @param WC_Order_Refund $refund Refund object.
	 * @param \Throwable      $error What went wrong.
	 */
	private function fail_quietly( string $type, WC_Order $order, WC_Order_Refund $refund, \Throwable $error ): void {
		Logger::log( 'Fiscal ' . $type . ' record failed: ' . $error->getMessage() );
		do_action( 'woocommerce_pos_fiscal_record_failed', $type, $order, array( 'refund_id' => $refund->get_id() ) );
	}

	/** Provenance for a refund's records: the refund's own stamp and its session's store.
	 *
	 * @param WC_Order        $order Parent order.
	 * @param WC_Order_Refund $refund Refund object.
	 * @throws \RuntimeException When the session row cannot be read.
	 */
	private function refund_provenance( WC_Order $order, WC_Order_Refund $refund ): array {
		$provenance = $this->store->provenance_from_order( $order );
		foreach ( array(
			'register_id' => '_wcpos_register',
			'session_id' => '_wcpos_session',
		) as $key => $meta ) {
			$value = $refund->get_meta( $meta, true );
			$provenance[ $key ] = Pos_Uuid::is_uuid( $value ) ? strtolower( $value ) : null;
		}
		global $wpdb;
		$session = $provenance['session_id'] ? ( new Register_Session_Store() )->get( $provenance['session_id'] ) : null;
		if ( '' !== $wpdb->last_error ) {
			// A failed read must not freeze the sale's store onto the refund's record.
			throw new \RuntimeException( 'Refund session read failed.' );
		}
		$provenance['store_id'] = $session['store_id'] ?? $provenance['store_id'];
		return $provenance;
	}

	/** Write the late-arrival correction for a refund received after its closure.
	 *
	 * @param WC_Order        $order Parent order.
	 * @param WC_Order_Refund $refund Refund object.
	 * @param array           $provenance Refund provenance.
	 * @param array           $record The refund record.
	 * @param array           $closure The closure it arrived after.
	 */
	private function record_late_arrival( WC_Order $order, WC_Order_Refund $refund, array $provenance, array $record, array $closure ): void {
		$order_id = $order->get_id();
		$refund_id = $refund->get_id();
		$rows = Session_Figures::refund_tender_rows( $refund );
		$delta = Session_Figures::tender_totals( $rows );
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
						'variance_delta' => ( new Closure_Store() )->variance( array_fill_keys( array_keys( $closure['counted'] ), '0' ), $delta ),
					),
				)
			)
		);
	}

	/** Record a post-closure shift from unallocated cash to a payment tender.
	 *
	 * @param WC_Order        $order Parent order.
	 * @param array           $row Allocated payment row.
	 * @param WC_Order_Refund $refund Refund object.
	 * @param string          $amount Normalized allocation amount.
	 */
	public function handle_allocation( WC_Order $order, array $row, WC_Order_Refund $refund, string $amount ): void {
		try {
			$this->record_reallocation( $order, $row, $refund, $amount );
		} catch ( \Throwable $error ) {
			// The allocation is already saved; fiscal bookkeeping must not fail the request.
			$this->fail_quietly( 'late_refund', $order, $refund, $error );
		}
	}

	/** The write behind handle_allocation(): only a succeeded, non-cash allocation after the closure shifts the drawer.
	 *
	 * @param WC_Order        $order Parent order.
	 * @param array           $row Allocated payment row.
	 * @param WC_Order_Refund $refund Refund object.
	 * @param string          $amount Normalized allocation amount.
	 */
	private function record_reallocation( WC_Order $order, array $row, WC_Order_Refund $refund, string $amount ): void {
		$succeeded = false;
		foreach ( $row['refunds'] ?? array() as $allocation ) {
			if ( (int) $allocation['id'] === $refund->get_id() && 'succeeded' === ( $allocation['status'] ?? null ) ) {
				$succeeded = true;
			}
		}
		// A pending allocation has moved no money yet; cash to cash is no shift at all.
		if ( ! $succeeded || 'cash' === ( $row['kind'] ?? '' ) ) {
			return;
		}
		$session_id = $refund->get_meta( '_wcpos_session', true );
		$closure = Pos_Uuid::is_uuid( $session_id ) ? ( new Closure_Store() )->for_session( strtolower( $session_id ) ) : null;
		if ( ! $this->is_register_sale( $order ) || ! $closure ) {
			return;
		}
		$record = $this->store->find_refund( $order->get_id(), $refund->get_id() );
		if ( ! $record ) {
			// The refund record never landed: the refund observer is idempotent, so run it again
			// (it also writes the late arrival for an uncounted refund) before deciding anything.
			$this->handle_refund( $order->get_id(), $refund->get_id() );
			$record = $this->store->find_refund( $order->get_id(), $refund->get_id() );
			if ( ! $record ) {
				return;
			}
		}
		$provenance = $this->refund_provenance( $order, $refund );
		// A refund the closure did not count is a late arrival whose record projects the live
		// allocations: recover that record if its insert failed, and never write a reallocation.
		if ( ! Session_Figures::counted( $refund->get_meta( '_wcpos_closure', true ), $closure ) ) {
			if ( ! $this->store->find_by_source( 'late_refund', 'refund:' . $refund->get_id() ) ) {
				$this->record_late_arrival( $order, $refund, $provenance, $record, $closure );
			}
			return;
		}
		$amount = Closure_Store::sum( array( $amount ) );
		$tender = array_intersect_key( $row, array_flip( array( 'method_id', 'kind' ) ) );
		$tender['amount'] = '-' . $amount;
		$tender['refund_id'] = $refund->get_id();
		$cash = $tender;
		$cash['method_id'] = 'pos_cash';
		$cash['kind'] = 'cash';
		$cash['amount'] = $amount;
		$delta = Session_Figures::tender_totals( array( $tender, $cash ) );
		$provenance['type'] = 'late_refund';
		// The column is CHAR(36): a deterministic UUID-shaped digest of the allocation identity.
		$provenance['source_id'] = Fiscal_Record_Store::digest_source_id( 'refund:' . $refund->get_id() . ':' . $row['id'] );
		$provenance['order_id'] = $order->get_id();
		$provenance['refund_id'] = $refund->get_id();
		$provenance['closure_id'] = $closure['id'];
		$provenance['corrects_record_id'] = $record['id'];
		$provenance['cashier_id'] = (int) $refund->get_refunded_by();
		$provenance['device_time'] = null;
		$provenance['device_tz'] = null;
		$provenance['payload'] = array(
			'kind' => 'reallocation',
			'reason' => (string) $refund->get_reason(),
			'amount' => $amount,
			'tender_rows' => array( $tender, $cash ),
			'expected_delta' => $delta,
			'variance_delta' => ( new Closure_Store() )->variance( array_fill_keys( array_keys( $closure['counted'] ), '0' ), $delta ),
		);
		$this->write( $order, $provenance );
	}
}
