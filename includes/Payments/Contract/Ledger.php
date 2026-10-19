<?php
/**
 * WCPOS order payment ledger.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_Error;

/** Persists payment legs and derives WooCommerce order payment state. */
class Ledger {
	public const META_KEY = '_wcpos_payments';
	public const INDEX_META_KEY = '_wcpos_payment_method';
	public const PAYMENT_ID_META_KEY = '_wcpos_payment_id';
	/**
	 * One meta row per pending or authorized leg. What the sweep selects orders by: an
	 * authorization that covers the balance completes the order through payment_complete(),
	 * so a status filter would lose exactly the leg that most needs reconciling.
	 */
	public const LIVE_LEG_META_KEY = '_wcpos_payment_live';
	/** Bound provider-event dedupe history without growing each row indefinitely. */
	public const SEEN_EVENTS_MAX = 20;
	/**
	 * Cap on the cashier event log a server-mode handler writes to the row (the audit's
	 * ask: server truth on the row instead of five browser-side panels). Free owns
	 * persistence, so Free enforces the cap; newest entries win.
	 */
	public const EVENTS_MAX = 100;
	/**
	 * Top-level keys `record()` takes from the till. Everything else on a recorded
	 * row is the server's: money and method are re-derived from the validated
	 * input, `events` and `void_requested_at` belong to a handler leg, `tip` is
	 * written only when a provider confirms an on-reader amount, and an unknown
	 * key is not harmless (`Session_Figures::tender_key()` reads `method` before
	 * `method_id`). The Payments Contract's device facts stay: when and how the
	 * till took the money, and what the device handed it.
	 *
	 * @var string[]
	 */
	private const RECORD_INPUT_KEYS = array( 'id', 'method_id', 'amount', 'currency', 'status', 'tendered', 'register_id', 'session_id', 'recorded_offline', 'transport', 'created_at_gmt', 'captured_at_gmt', 'provider_refs', 'receipt' );
	public const SCHEMA = 1;
	public const LIVE_STATUSES = array( 'pending', 'authorized', 'captured' );
	public const COUNTING_STATUSES = array( 'authorized', 'captured' );
	public const STATUSES = array( 'pending', 'authorized', 'captured', 'failed', 'voided' );

	/**
	 * Order statuses the ledger still projects. Once an order has completed (or is
	 * processing / on-hold / refunded) a ledger write never pulls it back — so that is
	 * also the boundary past which a captured cash leg is refunded rather than voided.
	 */
	public const IN_PROGRESS_STATUSES = array( 'pos-open', 'pos-partial', 'pending', 'failed' );
	public const KINDS = array( 'cash', 'card', 'stored_value', 'bank_transfer', 'other' );
	public const SOURCES = array( 'app', 'webview' );

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Ledgers already logged as invalid.
	 *
	 * @var array<int, bool>
	 */
	private $logged_invalid_ledgers = array();

	/**
	 * Keeper acquisitions held until after-save, keyed by order id.
	 *
	 * @var array<int, int>
	 */
	private $closure_locks = array();

	/** Get the shared ledger. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether order state is currently being derived.
	 *
	 * @var bool True while derive() is running payment_complete() — lets hooks skip re-entry.
	 */
	private static $deriving = false;

	/** Whether the ledger is currently deriving order state (inside payment_complete()). */
	public static function is_deriving(): bool {
		return self::$deriving;
	}

	/**
	 * Read stored payment rows. An order without a ledger reads as empty, silently.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function read( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY, true );
		// The app sends the ledger as a typed-meta object on order writes, so the value may
		// arrive already decoded (array) rather than as the JSON string this class stores.
		if ( ! is_array( $raw ) && ( ! is_string( $raw ) || '' === $raw ) ) {
			return array();
		}
		$decoded = $this->decode( $raw );
		if ( null === $decoded ) {
			$id = $order->get_id();
			if ( empty( $this->logged_invalid_ledgers[ $id ] ) ) {
				Logger::log( sprintf( 'Invalid WCPOS payment ledger on order #%d; treating as empty.', $id ) );
				$this->logged_invalid_ledgers[ $id ] = true;
			}
			return array();
		}
		return $decoded['payments'];
	}

	/** Keep server stamps on every order-writing lane. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_before_order_object_save', array( self::instance(), 'keep_closure_stamps' ), 10, 1 );
		add_action( 'woocommerce_after_order_object_save', array( self::instance(), 'release_closure_lock' ), 10, 1 );
		add_action( 'shutdown', array( self::instance(), 'release_closure_lock' ), 10, 0 );
	}

	/** Decode both typed meta and the stored JSON form.
	 *
	 * @param mixed $raw Ledger value.
	 */
	public function decode( $raw ): ?array {
		$value = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		return is_array( $value ) && self::SCHEMA === ( $value['schema'] ?? null ) && isset( $value['payments'] ) && is_array( $value['payments'] ) ? $value : null;
	}

	/** Read storage, not the possibly stale order object.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $lock Use a current locking read, not the transaction snapshot.
	 * @return array|null Meta id and payments, or no readable ledger.
	 * @throws \RuntimeException On a read failure or an invalid locked ledger.
	 */
	private function stored( int $order_id, bool $lock ): ?array {
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id = $hpos ? 'id' : 'meta_id';
		$owner = $hpos ? 'order_id' : 'post_id';
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage identifiers and lock clause are selected above.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$id} AS meta_id, meta_value FROM {$table} WHERE {$owner} = %d AND meta_key = %s ORDER BY {$id} ASC LIMIT 1{$suffix}", $order_id, self::META_KEY ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Ledger storage read failed.' );
		}
		if ( ! $row ) {
			return null;
		}
		$value = $this->decode( maybe_unserialize( $row['meta_value'] ) );
		if ( null === $value ) {
			if ( $lock ) {
				throw new \RuntimeException( 'Ledger storage decode failed.' );
			}
			return null;
		}
		return array(
			'meta_id' => (int) $row['meta_id'],
			'payments' => $value['payments'],
		);
	}

	/** Record permanently which legs a closure counted, without changing timestamps.
	 *
	 * The caller holds Order_Lock inside its transaction until commit or rollback.
	 * A locking read avoids erasing legs written after the caller's snapshot began.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $row_ids Counted leg ids.
	 * @param string   $closure_id Closure UUID.
	 * @throws \RuntimeException On missing/conflicting legs or an unverifiable write.
	 */
	public function stamp_closure( WC_Order $order, array $row_ids, string $closure_id ): void {
		$stored = $this->stored( $order->get_id(), true );
		if ( null === $stored && $row_ids ) {
			throw new \RuntimeException( 'Closure stamp: ledger missing.' );
		}
		$wanted = array_fill_keys( array_map( 'strtolower', $row_ids ), true );
		$missing = $wanted;
		$payments = $stored['payments'] ?? array();
		$changed = false;
		foreach ( $payments as &$row ) {
			$id = strtolower( $row['id'] );
			if ( ! isset( $wanted[ $id ] ) ) {
				continue;
			}
			unset( $missing[ $id ] );
			if ( ( $row['closure_id'] ?? null ) === $closure_id ) {
				continue;
			}
			if ( ! empty( $row['closure_id'] ) ) {
				throw new \RuntimeException( 'Closure stamp: leg belongs to another closure.' );
			}
			$row['closure_id'] = $closure_id;
			$changed = true;
		}
		unset( $row );
		if ( $missing ) {
			throw new \RuntimeException( 'Closure stamp: leg missing.' );
		}
		if ( ! $changed ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'schema' => self::SCHEMA,
				'payments' => array_map( array( __CLASS__, 'to_wire' ), $payments ),
			)
		);
		$order->update_meta_data( self::META_KEY, $json, $stored['meta_id'] );
		$order->save_meta_data();
		$check = $this->stored( $order->get_id(), true );
		foreach ( $check['payments'] ?? array() as $row ) {
			if ( ( $row['closure_id'] ?? null ) === $closure_id ) {
				unset( $wanted[ strtolower( $row['id'] ) ] );
			}
		}
		if ( $wanted ) {
			throw new \RuntimeException( 'Closure stamp failed.' );
		}
	}

	/** Hold Order_Lock across the storage read and save; stamps come only from storage.
	 * Keep every stored stamped leg, even when the writer omits it or sends an undecodable ledger.
	 *
	 * @param mixed $order Order about to be saved.
	 * @throws \RuntimeException When the order lock cannot be acquired.
	 */
	public function keep_closure_stamps( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$warned = false;
		if ( 0 >= $order->get_id() ) {
			// A new order has nothing in storage, so no stamp can be kept and every stamp it
			// carries is the client's claim: drop them all before the first save.
			$raw   = $order->get_meta( self::META_KEY, true );
			$value = $this->decode( $raw );
			if ( null === $value ) {
				// Its index rows are the client's claim too: no readable ledger, no index.
				$this->reindex( $order, array() );
				return;
			}
			$before = $value;
			$value['payments'] = $this->unique_rows( $value['payments'], 0, $warned );
			foreach ( $value['payments'] as &$row ) {
				unset( $row['closure_id'], $row['updated_at_gmt'] );
				foreach ( array( 'seen_events', 'events' ) as $field ) {
					if ( array_key_exists( $field, $row ) ) {
						$row[ $field ] = array();
					}
				}
				$row['epoch'] = 0;
				$row['refunded_amount'] = '0';
				if ( array_key_exists( 'refunds', $row ) ) {
					$row['refunds'] = array();
				}
			}
			unset( $row );
			if ( $value !== $before ) {
				$wire = array(
					'schema'   => self::SCHEMA,
					'payments' => array_map( array( __CLASS__, 'to_wire' ), $value['payments'] ),
				);
				$order->update_meta_data( self::META_KEY, is_array( $raw ) ? $value : wp_json_encode( $wire ) );
			}
			$this->reindex( $order, $value['payments'] );
			return;
		}
		// An index row pending on its own (a client re-pushed the ledger unchanged beside id-less
		// `_wcpos_payment_method` rows, which WooCommerce's REST meta loop collapses to one) must
		// also re-derive, or the collapsed index would be persisted as-is.
		$pending = false;
		foreach ( $order->get_meta_data() as $meta ) {
			if ( in_array( $meta->key, array( self::META_KEY, self::INDEX_META_KEY ), true ) && ( null === $meta->id || $meta->get_changes() ) ) {
				$pending = true;
				break;
			}
		}
		$deleting = false;
		try {
			foreach ( Money_Meta_Guard::raw_meta( $order ) as $meta ) {
				if ( self::META_KEY === $meta->key && null === $meta->value ) {
					$deleting = true;
					break;
				}
				if ( self::INDEX_META_KEY === $meta->key && null === $meta->value ) {
					// A collapsed or removed index row: re-derive from the ledger being saved.
					$pending = true;
				}
			}
		} catch ( \Throwable $error ) {
			// If raw metadata is unavailable, retain the normal pending-change check.
			$deleting = false;
		}
		if ( ! $pending && ! $deleting ) {
			return;
		}
		$order_id = $order->get_id();
		if ( Order_Lock::instance()->acquire( $order_id ) ) {
			$this->closure_locks[ $order_id ] = ( $this->closure_locks[ $order_id ] ?? 0 ) + 1;
		} else {
			$message = sprintf( 'WCPOS payment ledger locked on order #%d; the save was refused', $order_id );
			Logger::error( $message, array( 'order_id' => $order_id ) );
			throw new \RuntimeException( esc_html( $message ) );
		}
		$stored = $this->stored( $order->get_id(), false );
		if ( $stored ) {
			$stored['payments'] = $this->unique_rows( $stored['payments'], $order_id, $warned );
		}
		$stamps = array();
		$stored_rows = array();
		foreach ( $stored['payments'] ?? array() as $row ) {
			$stored_rows[ strtolower( $row['id'] ) ] = $row;
			if ( ! empty( $row['closure_id'] ) ) {
				$stamps[ strtolower( $row['id'] ) ] = $row['closure_id'];
			}
		}
		$raw = $order->get_meta( self::META_KEY, true );
		$value = $this->decode( $raw );
		if ( $deleting || null === $value ) {
			if ( $stamps ) {
				$value = array(
					'schema'   => self::SCHEMA,
					'payments' => array_map( array( __CLASS__, 'to_wire' ), $stored['payments'] ),
				);
				$order->update_meta_data( self::META_KEY, wp_json_encode( $value ) );
			}
			$this->reindex( $order, $stamps ? $stored['payments'] : array() );
			return;
		}
		$before = $value;
		$value['payments'] = $this->unique_rows( $value['payments'], $order_id, $warned );
		$present = array();
		$fresh = wc_get_order( $order_id );
		foreach ( $value['payments'] as &$row ) {
			$id = strtolower( $row['id'] );
			$present[ $id ] = true;
			$previous = $stored_rows[ $id ] ?? null;
			$row['epoch'] = $previous ? (int) ( $previous['epoch'] ?? 0 ) : Money_Epoch::current( $fresh instanceof WC_Order ? $fresh : $order );
			if ( ! Money_Meta_Guard::is_authorised( $order_id ) ) {
				foreach ( array( 'seen_events', 'events' ) as $field ) {
					if ( array_key_exists( $field, $row ) || isset( $previous[ $field ] ) ) {
						$row[ $field ] = $previous[ $field ] ?? array();
					}
				}
				unset( $row['updated_at_gmt'] );
				if ( isset( $previous['updated_at_gmt'] ) ) {
					$row['updated_at_gmt'] = $previous['updated_at_gmt'];
				}
				// Refund allocations and their totals are server-owned, not order-write intent.
				$row['refunded_amount'] = $previous['refunded_amount'] ?? '0';
				if ( array_key_exists( 'refunds', $row ) || isset( $previous['refunds'] ) ) {
					$row['refunds'] = $previous['refunds'] ?? array();
				}
			}
			if ( isset( $stamps[ $id ] ) ) {
				$row['closure_id'] = $stamps[ $id ];
			} else {
				unset( $row['closure_id'] );
			}
		}
		unset( $row );
		foreach ( $stored['payments'] ?? array() as $row ) {
			if ( ! empty( $row['closure_id'] ) && ! isset( $present[ strtolower( $row['id'] ) ] ) ) {
				$value['payments'][] = $row;
			}
		}
		if ( $value !== $before ) {
			if ( ! is_array( $raw ) ) {
				$value['payments'] = array_map( array( __CLASS__, 'to_wire' ), $value['payments'] );
			}
			$order->update_meta_data( self::META_KEY, is_array( $raw ) ? $value : wp_json_encode( $value ) );
		}
		$this->reindex( $order, $value['payments'] );
	}

	/** Keep the first occurrence of each row id on the order-write lane.
	 *
	 * @param array $rows     Pushed rows.
	 * @param int   $order_id Order ID (zero on create).
	 * @param bool  $warned   Whether malformed rows were already logged for this save.
	 */
	private function unique_rows( array $rows, int $order_id, bool &$warned ): array {
		$unique = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || '' === trim( $row['id'] ) ) {
				if ( ! $warned ) {
					Logger::warning( 'Malformed payment rows dropped on an order save.', array( 'order_id' => $order_id ) );
					$warned = true;
				}
				continue;
			}
			$id = strtolower( $row['id'] );
			if ( isset( $unique[ $id ] ) ) {
				Logger::warning(
					'Duplicate payment row dropped on an order save.',
					array(
						'order_id' => $order_id,
						'payment_id' => $id,
					)
				);
				continue;
			}
			$unique[ $id ] = $row;
		}
		return array_values( $unique );
	}

	/** Release a keeper acquisition after saving, or all remaining acquisitions on shutdown.
	 *
	 * @param mixed $order Saved order, or null at shutdown.
	 */
	public function release_closure_lock( $order = null ): void {
		if ( null !== $order && ! $order instanceof WC_Order ) {
			return;
		}
		$orders = null === $order ? $this->closure_locks : array( $order->get_id() => 1 );
		foreach ( $orders as $order_id => $count ) {
			if ( ! isset( $this->closure_locks[ $order_id ] ) ) {
				continue;
			}
			for ( $i = 0; $i < $count; ++$i ) {
				Order_Lock::instance()->release( $order_id );
				--$this->closure_locks[ $order_id ];
			}
			if ( 0 === $this->closure_locks[ $order_id ] ) {
				unset( $this->closure_locks[ $order_id ] );
			}
		}
	}

	/**
	 * Find a payment row by UUID.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $id    Payment ID.
	 */
	public function find( WC_Order $order, string $id ): ?array {
		$id = strtolower( $id );
		foreach ( $this->read( $order ) as $row ) {
			if ( ( $row['id'] ?? null ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Sum authorized and captured rows.
	 *
	 * @param array $rows Payment rows.
	 */
	public function paid( array $rows ): string {
		$paid = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', self::COUNTING_STATUSES, true ) ) {
				$paid += Money::minor( $row['amount'] ?? 0 );
			}
		}
		return Money::format( $paid );
	}

	/**
	 * Calculate the non-negative order balance.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	public function balance( WC_Order $order, array $rows ): string {
		$paid = Money::minor( $this->paid( $rows ) );
		// An order paid before this ledger existed — an upgraded store, or a legacy
		// webview sale — has no rows to sum, so the ledger alone would report the whole
		// total as outstanding and let a second tender be recorded against money that
		// was already taken. Woo's own paid state is the fallback truth while the
		// ledger is empty; once there is a row the ledger is authoritative.
		if ( 0 === $paid && ( $order->is_paid() || $order->get_date_paid() ) ) {
			return Money::format( 0 );
		}
		$balance = Money::minor( $order->get_total() ) - $paid;
		return Money::format( max( 0, $balance ) );
	}

	/**
	 * Build the ledger-backed order summary.
	 *
	 * @param WC_Order   $order Order object.
	 * @param array|null $rows  Payment rows.
	 */
	public function summary( WC_Order $order, ?array $rows = null ): array {
		$rows = null === $rows ? $this->read( $order ) : $rows;
		return array(
			'status'               => $order->get_status(),
			'total'                => Money::normalize( $order->get_total() ),
			'paid'                 => $this->paid( $rows ),
			'balance'              => $this->balance( $order, $rows ),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
		);
	}

	/**
	 * Validate and record money already taken.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $input   Payment input.
	 * @param array    $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function record( WC_Order $order, array $input, array $context = array() ) {
		// The till's row carries server-derived fields too (source, kind, change, refunds…); only these are inputs.
		$input = array_intersect_key( $input, array_flip( self::RECORD_INPUT_KEYS ) );
		$validated = $this->validate_input( $order, $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		list( $amount, $currency ) = $validated;
		$rows   = $this->read( $order );
		$stored = $this->find_in_rows( $rows, strtolower( $input['id'] ) );
		if ( $stored ) {
			$replayed = $this->replay( $order, $stored, $input, $amount, $currency );
			if ( is_wp_error( $replayed ) ) {
				return $replayed;
			}
			$settled = Settlement::instance()->apply_parked( $order, $stored['id'] );
			if ( is_wp_error( $settled ) ) {
				return $settled;
			}
			$this->derive( $order, $this->read( $order ) );
			if ( $order->get_changes() ) {
				Money_Meta_Guard::authorised( $order->get_id(), fn() => $order->save() );
			}
			return $this->find( $order, $stored['id'] );
		}

		$descriptor = $this->validate_method( (string) ( $input['method_id'] ?? '' ) );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( ! $this->is_recordable( $descriptor ) ) {
			return new WP_Error(
				'wcpos_method_not_recordable',
				__( 'This payment method cannot be recorded; it has to run its own payment leg.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'captured';
		if ( ! in_array( $status, array( 'captured', 'authorized' ), true ) ) {
			return $this->invalid( __( 'Recorded payments must be captured or authorized.', 'woocommerce-pos' ) );
		}
		$tendered = null;
		if ( array_key_exists( 'tendered', $input ) && null !== $input['tendered'] ) {
			if ( ! is_scalar( $input['tendered'] ) ) {
				return $this->invalid( __( 'Tendered amount is invalid for this payment method.', 'woocommerce-pos' ) );
			}
			$value = wc_format_decimal( $input['tendered'], wc_get_price_decimals() );
			if ( 'cash' !== $descriptor['kind'] || empty( $descriptor['capabilities']['change'] ) || '' === $value || ! is_numeric( $value ) || Money::minor( $value ) < Money::minor( $amount ) ) {
				return $this->invalid( __( 'Tendered amount is invalid for this payment method.', 'woocommerce-pos' ) );
			}
			$tendered = Money::normalize( $value );
		}

		$row = array_merge(
			$input,
			array(
				'id'              => strtolower( $input['id'] ),
				'source'          => 'app',
				'order_id'        => $order->get_id(),
				'method_id'       => $descriptor['id'],
				'provider'        => $descriptor['capture']['provider'],
				'kind'            => $descriptor['kind'],
				'capture_mode'    => $descriptor['capture']['mode'],
				'amount'          => $amount,
				'currency'        => $currency,
				'tendered'        => $tendered,
				'status'          => $status,
				'failure_reason'  => null,
				'refunded_amount' => Money::format( 0 ),
				'refunds'         => array(),
				'seen_events'     => array(),
				'expires_at'      => null,
				'register_id'     => Pos_Uuid::is_uuid( $input['register_id'] ?? null ) ? strtolower( $input['register_id'] ) : null,
				'session_id'      => Pos_Uuid::is_uuid( $input['session_id'] ?? null ) ? strtolower( $input['session_id'] ) : null,
				'cashier_id'      => (int) ( $context['cashier_id'] ?? get_current_user_id() ),
				'store_id'        => isset( $context['store_id'] ) ? (int) $context['store_id'] : null,
			)
		);

		$balance = Money::minor( $this->balance( $order, $rows ) );
		// A provider leg still pending has reserved its share of the balance: cash taken on top
		// of it would count twice if the terminal later confirms (the money moved both ways).
		// A leg the till has already asked to cancel does not block cash: the cashier accepted
		// that risk by releasing it, and a provider outage must not stop the sale.
		$reserved = $this->pending_reserved( $rows, false );
		if ( 0 === $balance || Money::minor( $amount ) > $balance || Money::minor( $amount ) > $balance - $reserved ) {
			$row['status']          = 'failed';
			$row['failure_reason']  = 0 === $balance ? 'order_already_paid' : ( Money::minor( $amount ) > $balance ? 'amount_exceeds_balance' : 'payment_in_flight' );
			$row['captured_at_gmt'] = null;
			$row                    = $this->normalize_row( $order, $row );
			$rows[]                 = $row;
			$this->save( $order, $rows );
			Settlement::instance()->apply_parked( $order, $row['id'] );
			Logger::warning( sprintf( 'WCPOS payment %s refused on order #%d: %s (%s %s against %s owed)', $row['id'], $order->get_id(), $row['failure_reason'], $amount, $currency, Money::format( $balance ) ) );
			/**
			 * Fires after a new payment row is saved as refused.
			 *
			 * @param WC_Order $order Order object.
			 * @param array    $row   Failed payment row.
			 *
			 * @hook woocommerce_pos_payment_refused
			 */
			do_action( 'woocommerce_pos_payment_refused', $order, $row );
			return $this->refusal_error( $row, $order );
		}

		$row    = $this->normalize_row( $order, $row );
		$rows[] = $row;
		// Index the arrival before consuming its webhook, but do not mark money paid
		// until a parked provider confirmation has passed capture verification.
		$this->save( $order, $rows, false );
		$settled = Settlement::instance()->apply_parked( $order, $row['id'] );
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
		$this->derive( $order, $this->read( $order ) );
		Money_Meta_Guard::authorised( $order->get_id(), fn() => $order->save() );
		$row = $this->find( $order, $row['id'] );
		Logger::log( sprintf( 'WCPOS payment %s recorded on order #%d: %s %s via %s (%s) by cashier #%d', $row['id'], $order->get_id(), $row['amount'], $row['currency'], $row['method_id'], $row['status'], $row['cashier_id'] ) );
		/**
		 * Fires after a new payment row is recorded and the order is saved.
		 *
		 * @param WC_Order $order Order object.
		 * @param array    $row   Recorded payment row.
		 *
		 * @hook woocommerce_pos_payment_recorded
		 */
		do_action( 'woocommerce_pos_payment_recorded', $order, $row );
		return $row;
	}

	/**
	 * Start a provider payment without recording money before the handler succeeds.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $id      Payment ID.
	 * @param array    $input   Payment input.
	 * @param array    $context Provider context.
	 * @return array|WP_Error
	 */
	public function intent( WC_Order $order, string $id, array $input, array $context ) {
		$input['id'] = $id;
		$validated = $this->validate_input( $order, $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		list( $amount, $currency ) = $validated;
		$descriptor = $this->validate_method( (string) ( $input['method_id'] ?? '' ) );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( ! $descriptor['pos_enabled'] ) {
			return new WP_Error( 'wcpos_payment_method_disabled', __( 'Payment method is disabled.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( $row ) {
			$replayed = $this->replay( $order, $row, $input, $amount, $currency );
			if ( is_wp_error( $replayed ) ) {
				return $replayed;
			}
			// A leg still in flight RESUMES at the provider (§4.3): handlers key their
			// provider call on the row id, so calling intent() again returns the same
			// provider intent — and with it the handoff the app lost when the first
			// response never arrived. A settled leg has nothing left to hand off.
			if ( 'pending' !== ( $replayed['status'] ?? '' ) ) {
				return array(
					'payment' => $replayed,
					'handoff' => array(),
				);
			}
			$handler = Capture_Mode_Registry::instance()->resolve( (string) $replayed['capture_mode'], $replayed['provider'] ?? null );
			$resumed = $handler ? $handler->intent( $replayed, $context ) : $this->unsupported();
			if ( is_wp_error( $resumed ) ) {
				// A row the provider never answered (no ref but the reader) is live only on our
				// side: a determinate refusal on the retry ends it, as the sweeper would, so it
				// stops reserving the balance. An answered row keeps its provider object.
				if ( self::is_unanswered( $replayed ) && true !== ( $resumed->get_error_data()['indeterminate'] ?? false ) ) {
					$this->apply_result(
						$order,
						$replayed['id'],
						array(
							'status' => 'failed',
							'failure_reason' => $resumed->get_error_code(),
						)
					);
				}
				return $resumed;
			}
			$handoff = $resumed['handoff'] ?? array();
			// A resumed leg can come back captured (the reader finished while the till was
			// away), so it takes the same verification as capture(), never a bare transition.
			$applied = $this->apply_result( $order, $replayed['id'], $resumed );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			return array(
				'payment' => $applied,
				'handoff' => $handoff,
			);
		}
		$balance = Money::minor( $this->balance( $order, $rows ) );
		if ( 0 === $balance || Money::minor( $amount ) > $balance ) {
			return new WP_Error( 0 === $balance ? 'wcpos_order_already_paid' : 'wcpos_amount_exceeds_balance', 0 === $balance ? __( 'The order is already paid.', 'woocommerce-pos' ) : __( 'Payment amount exceeds the order balance.', 'woocommerce-pos' ), array( 'status' => 0 === $balance ? 409 : 400 ) );
		}
		$pending = $this->pending_reserved( $rows );
		$newest_pending = null;
		foreach ( $rows as $existing ) {
			if ( 'pending' === $existing['status'] && ( null === $newest_pending || strtotime( $existing['created_at_gmt'] ) >= strtotime( $newest_pending['created_at_gmt'] ) ) ) {
				$newest_pending = $existing;
			}
		}
		if ( Money::minor( $amount ) > $balance - $pending ) {
			return new WP_Error(
				'wcpos_payment_in_flight',
				__( 'Another payment is already in progress on this order.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'payment_id' => $newest_pending['id'],
				)
			);
		}
		$row = $this->normalize_row(
			$order,
			array(
				'id' => $id,
				'source' => in_array( $input['source'] ?? '', self::SOURCES, true ) ? $input['source'] : 'app',
				'method_id' => $descriptor['id'],
				'provider' => $descriptor['capture']['provider'],
				'kind' => $descriptor['kind'],
				'capture_mode' => $descriptor['capture']['mode'],
				'amount' => $amount,
				'currency' => $currency,
				'status' => 'pending',
				// The reader is the one provider ref the till knows before the provider does:
				// a device-mode leg is minted for a specific reader, and the order must say
				// which one took the money. Every other ref comes from the handler.
				'provider_refs' => $this->reader_ref( $input, $context ),
				'register_id' => $input['register_id'] ?? null,
				'session_id' => $input['session_id'] ?? null,
				'cashier_id' => (int) ( $context['cashier_id'] ?? get_current_user_id() ),
				'store_id' => isset( $context['store_id'] ) ? (int) $context['store_id'] : null,
			)
		);
		// Persist before contacting the provider: a lost answer must not erase the leg.
		$rows[] = $row;
		$this->save( $order, $rows, false );
		$handler = Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] );
		$new = $handler ? $handler->intent( $row, $context ) : $this->unsupported();
		if ( is_wp_error( $new ) ) {
			if ( true === ( $new->get_error_data()['indeterminate'] ?? false ) ) {
				$row['events'][] = array(
					't' => gmdate( 'c' ),
					'level' => 'warning',
					'message' => 'Provider did not answer: ' . $new->get_error_code(),
				);
				$this->replace_and_save( $order, $rows, $row, false );
				$this->derive( $order, $this->read( $order ) );
				Money_Meta_Guard::authorised( $order->get_id(), fn() => $order->save() );
			} else {
				$rows = array_filter( $rows, static fn( $saved ) => $saved['id'] !== $row['id'] );
				// Delete only this refused intent; omitted concurrent rows must survive.
				$this->save( $order, $rows, false, array( $row['id'] ) );
			}
			return $new;
		}
		$handoff = $new['handoff'] ?? array();
		$row = $this->apply_result( $order, $row['id'], $new, false );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// Drain parked confirmations before deriving: a parked void must be applied
		// before an authorization covering the balance could complete the order.
		$settled = Settlement::instance()->apply_parked( $order, $row['id'] );
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
		$this->derive( $order, $this->read( $order ) );
		Money_Meta_Guard::authorised( $order->get_id(), fn() => $order->save() );
		return array(
			'payment' => $this->find( $order, $row['id'] ),
			'handoff' => $handoff,
		);
	}

	/**
	 * Capture and verify provider-confirmed money before it counts toward payment.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $id      Payment ID.
	 * @param array    $context Provider context.
	 * @return array|WP_Error
	 */
	public function capture( WC_Order $order, string $id, array $context ) {
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$refusal = $this->refusal_error( $row, $order );
		if ( $refusal ) {
			return $refusal;
		}
		if ( ! in_array( $row['status'], array( 'pending', 'authorized' ), true ) ) {
			return $this->invalid_transition();
		}
		$handler = Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] ?? null );
		$new = $handler ? $handler->capture( $row, $context ) : $this->unsupported();
		if ( is_wp_error( $new ) ) {
			$this->record_failure_events( $order, $id, $new );
			return $new;
		}
		return $this->apply_result( $order, $id, $new );
	}

	/**
	 * Preserve provider diagnostics even when capture returns no payment result.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $id    Payment ID.
	 * @param WP_Error $error Provider error carrying redacted events.
	 */
	public function record_failure_events( WC_Order $order, string $id, WP_Error $error ): void {
		$events = $error->get_error_data()['events'] ?? null;
		if ( is_array( $events ) && ! empty( $events ) ) {
			$this->append_events( $order, $id, $events );
		}
	}

	/**
	 * Apply provider state under the caller's order lock, sharing capture verification.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $id    Payment ID.
	 * @param array    $new   Provider result.
	 * @param bool     $derive Project payment state after applying.
	 * @return array|WP_Error
	 */
	public function apply_result( WC_Order $order, string $id, array $new, bool $derive = true ) {
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$event = $new['event_id'] ?? null;
		if ( is_string( $event ) && in_array( $event, $row['seen_events'] ?? array(), true ) ) {
			return $row;
		}
		$refusal = $this->refusal_error( $row, $order );
		if ( $refusal ) {
			return $refusal;
		}
		$applied = $this->apply_transition( $row, $new );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		if ( in_array( $applied['status'], self::COUNTING_STATUSES, true ) ) {
			$confirmed = array_key_exists( 'amount', $new ) ? $new['amount'] : $row['amount'];
			$valid = is_numeric( $confirmed );
			$difference = $valid ? Money::minor( $confirmed ) - Money::minor( $row['amount'] ) : 0;
			$currency = ! array_key_exists( 'currency', $new ) || $new['currency'] === $row['currency'];
			$descriptor = Descriptor_Builder::instance()->get( $row['method_id'] );
			$tip = $difference > 0 && 'on_reader' === ( $descriptor['capabilities']['tips'] ?? 'none' );
			if ( ! $valid || ! $currency || ( 0 !== $difference && ! $tip ) ) {
				$applied['status'] = 'failed';
				$applied['failure_reason'] = 'amount_mismatch';
				$applied = $this->normalize_row( $order, $applied );
				$this->replace_and_save( $order, $rows, $applied, $derive );
				/* translators: 1: payment ID, 2: expected amount/currency, 3: confirmed amount/currency. */
				$order->add_order_note( sprintf( __( 'WCPOS payment %1$s amount mismatch: expected %2$s, confirmed %3$s.', 'woocommerce-pos' ), $row['id'], $row['amount'] . ' ' . $row['currency'], ( is_scalar( $confirmed ) ? (string) $confirmed : 'invalid' ) . ' ' . ( is_string( $new['currency'] ?? null ) ? $new['currency'] : $row['currency'] ) ) );
				return $this->refusal_error( $applied, $order );
			}
			if ( $tip ) {
				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( __( 'Tip', 'woocommerce-pos' ) );
				$fee->set_tax_status( 'none' );
				$fee->set_total( Money::format( $difference ) );
				// The customer has already paid the confirmed amount, so a tax on the tip is
				// carved OUT of the difference — never added on top of money nobody collected
				// (that would leave a phantom balance on a fully paid order). Per-rate
				// rounding here matches how the order sums item taxes, so fee + tax equals
				// the difference to the cent. update_taxes() folds the item taxes into the
				// order's tax lines and cart_tax — calculate_totals( false ) reads cart_tax
				// but only refreshes it through calculate_taxes(), which we skip so the rest
				// of the order is not recalculated.
				if ( apply_filters( 'wcpos_payment_tip_fee_taxable', false, $order, $row ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract filter.
					$fee->set_tax_status( 'taxable' );
					$tax_location = $order->get_taxable_location();
					$tax_location = array( $tax_location['country'], $tax_location['state'], $tax_location['postcode'], $tax_location['city'] );
					$taxes        = array_map( 'wc_round_tax_total', \WC_Tax::calc_inclusive_tax( (float) Money::format( $difference ), \WC_Tax::get_rates_from_location( $fee->get_tax_class(), $tax_location ) ) );
					$fee->set_taxes( array( 'total' => $taxes ) );
					$fee->set_total( Money::format( $difference - Money::minor( array_sum( $taxes ) ) ) );
				}
				$order->add_item( $fee );
				$order->update_taxes();
				$order->calculate_totals( false );
				$applied['amount'] = Money::normalize( $confirmed );
				$applied['tip'] = Money::format( $difference );
			}
		}
		if ( is_string( $event ) ) {
			$applied['seen_events'][] = $event;
			$applied['seen_events'] = array_slice( $applied['seen_events'], -self::SEEN_EVENTS_MAX );
		}
		if ( $applied !== $row ) {
			$applied = $this->normalize_row( $order, $applied );
			$this->replace_and_save( $order, $rows, $applied, $derive );
		}
		return $applied;
	}

	/**
	 * Allocate an existing WooCommerce refund to a payment row.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $id        Payment ID.
	 * @param int      $refund_id WooCommerce refund ID.
	 * @param string   $amount    Allocation amount.
	 * @return array|WP_Error
	 */
	public function refund( WC_Order $order, string $id, int $refund_id, string $amount ) {
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		if ( ! in_array( $row['status'], self::COUNTING_STATUSES, true ) ) {
			return $this->invalid_transition();
		}
		foreach ( $row['refunds'] ?? array() as $refund ) {
			if ( (int) $refund['id'] === $refund_id ) {
				return $row;
			}
		}
		$available = self::available_for_refund( $row );
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || 'shop_order_refund' !== $refund->get_type() || $refund->get_parent_id() !== $order->get_id() ) {
			return $this->invalid( __( 'Refund must belong to this order.', 'woocommerce-pos' ) );
		}
		if ( ! is_numeric( $amount ) || Money::minor( $amount ) <= 0 ) {
			return new WP_Error( 'wcpos_refund_not_allocatable', __( 'Refund amount cannot be allocated to this payment.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		// The refund itself is a cap too: its allocations across every row may not exceed its amount
		// (so a zero-amount refund allocates nothing).
		$requested = Money::minor( $amount );
		$refund_allocated = 0;
		foreach ( $rows as $payment_row ) {
			foreach ( $payment_row['refunds'] ?? array() as $allocation ) {
				if ( (int) $allocation['id'] === $refund_id && in_array( $allocation['status'], array( 'succeeded', 'pending' ), true ) ) {
					$refund_allocated += Money::minor( $allocation['amount'] );
				}
			}
		}
		$refund_amount = Money::minor( $refund->get_amount() );
		if ( $requested > $available || $refund_allocated + $requested > $refund_amount ) {
			return new WP_Error( 'wcpos_refund_not_allocatable', __( 'Refund amount cannot be allocated to this payment.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$handler = Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] ?? null );
		$new = $handler ? $handler->refund( $row, $refund_id, Money::normalize( $amount ) ) : $this->unsupported();
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$row = $this->apply_transition( $row, $new );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// A handler may save a different amount than was requested: hold it to the same caps, and it must be positive.
		$refunded = self::refunded_total( $row );
		$reserved = self::reserved_total( $row );
		$saved = 0;
		foreach ( $row['refunds'] as $allocation ) {
			if ( (int) $allocation['id'] === $refund_id && in_array( $allocation['status'], array( 'succeeded', 'pending' ), true ) ) {
				$saved += Money::minor( $allocation['amount'] );
			}
		}
		if ( $saved <= 0 || $reserved > Money::minor( $row['amount'] ) || $refund_allocated + $saved > $refund_amount ) {
			return new WP_Error( 'wcpos_refund_not_allocatable', __( 'Refund amount cannot be allocated to this payment.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$row['refunded_amount'] = Money::format( $refunded );
		$row = $this->normalize_row( $order, $row );
		$this->replace_and_save( $order, $rows, $row );
		/**
		 * Fires after an allocation is saved, and again when a pending allocation settles
		 * (succeeded or failed) through settle_allocation(). $amount is the allocation's
		 * amount on this row. Listeners read the allocation's status from $row['refunds'].
		 *
		 * @param WC_Order        $order  Order object.
		 * @param array           $row    Payment row.
		 * @param \WC_Order_Refund $refund Refund object.
		 * @param string          $amount Allocation amount on this row.
		 *
		 * @hook woocommerce_pos_refund_allocated
		 */
		do_action( 'woocommerce_pos_refund_allocated', $order, $row, $refund, Money::format( $saved ) );
		return $row;
	}

	/**
	 * Append a leg the Ledger did not open: money a WooCommerce gateway already took
	 * (payment_complete(), the order-pay page), or a provider attempt started outside
	 * the POS. It does no balance check and no handler call. The caller decides that
	 * the payment is not already represented, with an optional locked identity recheck.
	 * One $order->save(), so order meta the
	 * caller staged rides the same write.
	 *
	 * @param WC_Order      $order  Order object.
	 * @param array         $row    Payment row.
	 * @param bool          $derive Whether to project payment state.
	 * @param callable|null $identity Whether a stored row represents this payment.
	 * @throws Ledger_Persistence_Exception When the order lock cannot be acquired.
	 * @return array|WP_Error
	 */
	public function adopt( WC_Order $order, array $row, bool $derive = true, ?callable $identity = null ) {
		$id = strtolower( (string) ( $row['id'] ?? '' ) );
		if ( ! Pos_Uuid::is_uuid( $id ) ) {
			return $this->invalid( __( 'Payment id must be a UUID.', 'woocommerce-pos' ) );
		}
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $order->get_id() ) ) {
			throw new Ledger_Persistence_Exception( 'WCPOS payment ledger is locked.' );
		}
		try {
			foreach ( null === $identity ? array() : ( $this->stored( $order->get_id(), false )['payments'] ?? array() ) as $existing ) {
				if ( $identity( $existing ) ) {
					return $existing;
				}
			}
			$rows = $this->read( $order );
			$stored = $this->find_in_rows( $rows, $id );
			if ( $stored ) {
				return new WP_Error(
					'wcpos_payment_conflict',
					__( 'Payment id conflicts with an existing payment.', 'woocommerce-pos' ),
					array(
						'status'  => 409,
						'payment' => self::to_wire( $stored ),
					)
				);
			}
			$row['id'] = $id;
			$read_rows = $rows;
			$rows[] = $row;
			$this->save( $order, $rows, $derive, array(), $read_rows );
			return $this->find( $order, $id );
		} finally {
			$lock->release( $order->get_id() );
		}
	}

	/**
	 * Apply a provider's later answer to a pending refund allocation. A final answer
	 * (succeeded, failed) sets the allocation's status and reference, recomputes
	 * refunded_amount, and fires woocommerce_pos_refund_allocated again. A pending
	 * answer only records a reference the allocation does not have yet. An allocation
	 * that is no longer pending, or does not exist, is returned unchanged. The caller
	 * holds the order lock and has re-read the order's meta.
	 *
	 * @param WC_Order    $order        Order object.
	 * @param string      $id           Payment ID.
	 * @param int         $refund_id    WooCommerce refund ID.
	 * @param string      $status       Provider allocation status.
	 * @param string|null $provider_ref Provider reference, if answered.
	 * @param array       $events       Redacted cashier-log events.
	 * @return array|WP_Error
	 */
	public function settle_allocation( WC_Order $order, string $id, int $refund_id, string $status, ?string $provider_ref = null, array $events = array() ) {
		if ( ! in_array( $status, array( 'pending', 'succeeded', 'failed' ), true ) ) {
			return $this->invalid( __( 'Refund status is invalid.', 'woocommerce-pos' ) );
		}
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$answered = null !== $provider_ref && '' !== $provider_ref ? $provider_ref : null;
		foreach ( $row['refunds'] ?? array() as $i => $allocation ) {
			if ( (int) $allocation['id'] !== $refund_id || 'pending' !== ( $allocation['status'] ?? '' ) ) {
				continue;
			}
			if ( 'pending' === $status ) {
				if ( null === $answered || ! in_array( $allocation['provider_ref'] ?? null, array( null, '' ), true ) ) {
					return $row;
				}
				$row['refunds'][ $i ]['provider_ref'] = $answered;
			} else {
				$row['refunds'][ $i ]['status'] = $status;
				$row['refunds'][ $i ]['provider_ref'] = $answered ?? ( $allocation['provider_ref'] ?? null );
				$row['refunded_amount'] = Money::format( self::refunded_total( $row ) );
			}
			if ( $events ) {
				$row['events'] = array_merge( $row['events'] ?? array(), $events );
			}
			$row = $this->normalize_row( $order, $row );
			$this->replace_and_save( $order, $rows, $row, false );
			if ( 'pending' !== $status ) {
				$refund = wc_get_order( $refund_id );
				if ( $refund instanceof \WC_Order_Refund && $refund->get_parent_id() === $order->get_id() ) {
					do_action( 'woocommerce_pos_refund_allocated', $order, $row, $refund, Money::format( Money::minor( $allocation['amount'] ) ) );
				}
			}
			return $row;
		}
		return $row;
	}

	/**
	 * Pending and succeeded allocations reserve the leg. A failed one releases its
	 * amount. Minor units, never negative.
	 *
	 * @param array $row Payment row.
	 */
	public static function available_for_refund( array $row ): int {
		return max( 0, Money::minor( $row['amount'] ?? 0 ) - self::reserved_total( $row ) );
	}

	/**
	 * Append cashier-log events to one leg (capped at EVENTS_MAX, newest kept).
	 * Events are already redacted by the caller.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $id     Payment ID.
	 * @param array    $events Redacted cashier-log events.
	 */
	public function append_events( WC_Order $order, string $id, array $events ): void {
		if ( ! $events ) {
			return;
		}
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return;
		}
		$row['events'] = array_merge( $row['events'] ?? array(), $events );
		$this->replace_and_save( $order, $rows, $this->normalize_row( $order, $row ), false );
	}

	/**
	 * Shared record/intent identity and money validation.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $input Payment input.
	 * @return array|WP_Error
	 */
	private function validate_input( WC_Order $order, array $input ) {
		if ( ! Pos_Uuid::is_uuid( $input['id'] ?? null ) ) {
			return $this->invalid( __( 'Payment id must be a UUID.', 'woocommerce-pos' ) );
		}
		if ( ! isset( $input['amount'] ) || ! is_scalar( $input['amount'] ) ) {
			return $this->invalid( __( 'Payment amount must be positive.', 'woocommerce-pos' ) );
		}
		$amount = wc_format_decimal( $input['amount'], wc_get_price_decimals() );
		if ( '' === $amount || ! is_numeric( $amount ) || Money::minor( $amount ) <= 0 ) {
			return $this->invalid( __( 'Payment amount must be positive.', 'woocommerce-pos' ) );
		}
		$amount   = Money::normalize( $amount );
		$currency = isset( $input['currency'] ) ? (string) $input['currency'] : $order->get_currency();
		if ( $currency !== $order->get_currency() ) {
			return $this->invalid( __( 'Payment currency must match the order.', 'woocommerce-pos' ) );
		}
		return array( $amount, $currency );
	}

	/**
	 * Shared record/intent method lookup and missing-method error.
	 *
	 * @param string $id Gateway ID.
	 * @return array|WP_Error
	 */
	private function validate_method( string $id ) {
		$descriptor = Descriptor_Builder::instance()->get( $id );
		return $descriptor ? $descriptor : new WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}

	/**
	 * Replay the stored row, rejecting conflicting immutable fields.
	 *
	 * @param WC_Order $order    Order object.
	 * @param array    $stored   Stored row.
	 * @param array    $input    Requested row.
	 * @param string   $amount   Normalized amount.
	 * @param string   $currency Order currency.
	 * @return array|WP_Error
	 */
	private function replay( WC_Order $order, array $stored, array $input, string $amount, string $currency ) {
		// routes.md §4.3 makes `amount` immutable "outside a tip adjustment": an on-reader
		// tip rewrites the row's amount at capture, so a replay of the original call is
		// compared against the leg the cashier actually sent, not the tipped total.
		$stored_amount = isset( $stored['amount'] ) ? (string) $stored['amount'] : null;
		if ( null !== $stored_amount && null !== ( $stored['tip'] ?? null ) ) {
			$stored_amount = Money::format( Money::minor( $stored_amount ) - Money::minor( $stored['tip'] ) );
		}
		$comparable = array(
			'method_id' => $stored['method_id'] ?? null,
			'amount'    => $stored_amount,
			'currency'  => $stored['currency'] ?? null,
		);
		$requested = array(
			'method_id' => (string) ( $input['method_id'] ?? '' ),
			'amount'    => $amount,
			'currency'  => $currency,
		);
		if ( Pos_Uuid::is_uuid( $input['register_id'] ?? null ) ) {
			$comparable['register_id'] = $stored['register_id'] ?? null;
			$requested['register_id'] = strtolower( $input['register_id'] );
		}
		foreach ( $requested as $field => $value ) {
			if ( $comparable[ $field ] !== $value ) {
				return new WP_Error(
					'wcpos_payment_conflict',
					__( 'Payment id conflicts with an existing payment.', 'woocommerce-pos' ),
					array(
						'status' => 409,
						'payment' => self::to_wire( $stored ),
					)
				);
			}
		}
		$refusal = $this->refusal_error( $stored, $order );
		return $refusal ? $refusal : $stored;
	}

	/**
	 * Rebuild a stored payment refusal, including provider amount mismatches.
	 *
	 * @param array    $row   Payment row.
	 * @param WC_Order $order Order object.
	 */
	public function refusal_error( array $row, WC_Order $order ): ?WP_Error {
		$reason = 'failed' === ( $row['status'] ?? '' ) ? ( $row['failure_reason'] ?? '' ) : '';
		$messages = array(
			'order_already_paid'     => __( 'The order is already paid.', 'woocommerce-pos' ),
			'amount_exceeds_balance' => __( 'Payment amount exceeds the order balance.', 'woocommerce-pos' ),
			'amount_mismatch'        => __( 'Provider-confirmed amount or currency does not match the payment.', 'woocommerce-pos' ),
			'payment_in_flight'      => __( 'Another payment is already in progress on this order.', 'woocommerce-pos' ),
		);
		if ( ! isset( $messages[ $reason ] ) ) {
			return null;
		}
		$data = array(
			'status' => 'amount_exceeds_balance' === $reason ? 400 : 409,
			'payment' => self::to_wire( $row ),
			'order' => $this->summary( $order ),
		);
		if ( 'payment_in_flight' === $reason ) {
			// Name the newest leg that is actually blocking: one not already being cancelled,
			// falling back to a cancelling one only when nothing else is live.
			$blocking = null;
			foreach ( $this->read( $order ) as $live ) {
				if ( 'pending' !== $live['status'] ) {
					continue;
				}
				$live_cancelling     = ! empty( $live['void_requested_at'] );
				$blocking_cancelling = null !== $blocking && ! empty( $blocking['void_requested_at'] );
				if ( null === $blocking || ( $blocking_cancelling && ! $live_cancelling ) || ( $blocking_cancelling === $live_cancelling && strtotime( $live['created_at_gmt'] ) >= strtotime( $blocking['created_at_gmt'] ) ) ) {
					$blocking = $live;
				}
			}
			if ( $blocking ) {
				$data['payment_id'] = $blocking['id'];
			}
		}
		return new WP_Error( 'wcpos_' . $reason, $messages[ $reason ], $data );
	}

	/**
	 * Refresh one row through its capture-mode handler.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $id    Payment ID.
	 *
	 * @return array|\WP_Error
	 */
	public function status( WC_Order $order, string $id ) {
		$rows = $this->read( $order );
		$row  = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$handler = Capture_Mode_Registry::instance()->resolve( (string) ( $row['capture_mode'] ?? '' ), isset( $row['provider'] ) ? (string) $row['provider'] : null );
		if ( ! $handler ) {
			return $this->unsupported();
		}
		$new = $handler->status( $row );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$prompt = isset( $new['prompt'] ) && is_array( $new['prompt'] ) ? $new['prompt'] : null;
		unset( $new['prompt'] );
		$result = $this->apply_result( $order, $id, $new );
		if ( ! is_wp_error( $result ) && null !== $prompt ) {
			$result['prompt'] = $prompt;
		}
		return $result;
	}

	/**
	 * Void a pending or authorized row — or a captured manual row.
	 *
	 * A manual (cash, dummy card) row is captured the moment it is recorded, so
	 * cancelling a split mid-way has to void a captured row: the cashier hands the
	 * cash back and nothing at a provider needs reversing (wcpos/roadmap#107 rule 6).
	 * Provider-captured rows are never voided; they are refunded.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $id     Payment ID.
	 * @param string   $reason Void reason.
	 *
	 * @return array|\WP_Error
	 */
	public function void( WC_Order $order, string $id, string $reason ) {
		$rows = $this->read( $order );
		$row  = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		if ( ! $this->is_voidable( $row, $order ) ) {
			return $this->invalid_transition();
		}
		$handler = Capture_Mode_Registry::instance()->resolve( (string) ( $row['capture_mode'] ?? '' ), isset( $row['provider'] ) ? (string) $row['provider'] : null );
		if ( ! $handler ) {
			return $this->unsupported();
		}
		$new = $handler->void( $row, $reason );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		// A server-mode cancel is a request: the handler may answer with the row still
		// pending (void_requested_at set), or captured if the reader finished first — so
		// the result takes the same verification and persistence as every other write.
		$applied = $this->apply_result( $order, $row['id'], $new );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		if ( 'voided' === $applied['status'] ) {
			$order->add_order_note(
				'' === $reason
					/* translators: %s: payment row uuid. */
					? sprintf( __( 'WCPOS payment %s voided.', 'woocommerce-pos' ), $row['id'] )
					/* translators: 1: payment row uuid, 2: void reason given by the cashier. */
					: sprintf( __( 'WCPOS payment %1$s voided: %2$s', 'woocommerce-pos' ), $row['id'], $reason )
			);
			$order->save();
			Logger::log( sprintf( 'WCPOS payment %s voided on order #%d: %s %s%s', $row['id'], $order->get_id(), $row['amount'], $row['currency'], '' === $reason ? '' : ': ' . $reason ) );
			do_action( 'woocommerce_pos_payment_voided', $order, $row, $applied, $reason );
		}
		return $applied;
	}

	/**
	 * Whether a row may be voided: pending or authorized always; captured only when it is a
	 * manual row on an order still in progress (cancelling a split mid-way). Once the order
	 * has completed, derive() will not unwind it, so a captured cash leg is refunded instead.
	 *
	 * @param array    $row   Payment row.
	 * @param WC_Order $order Order object.
	 */
	private function is_voidable( array $row, WC_Order $order ): bool {
		$status = $row['status'] ?? '';
		if ( in_array( $status, array( 'pending', 'authorized' ), true ) ) {
			return true;
		}
		return 'captured' === $status
			&& 'manual' === ( $row['capture_mode'] ?? '' )
			&& in_array( $order->get_status(), self::IN_PROGRESS_STATUSES, true );
	}

	/**
	 * Apply handler-owned fields while enforcing the one-way lifecycle.
	 *
	 * @param array $row Payment row.
	 * @param array $new Updated payment row.
	 *
	 * @return array|\WP_Error
	 */
	public function apply_transition( array $row, array $new ) {
		$from    = $row['status'] ?? '';
		$to      = $new['status'] ?? $from;
		$allowed = array(
			'pending' => array( 'authorized', 'captured', 'failed', 'voided' ),
			'authorized' => array( 'captured', 'voided' ),
			// captured → voided is gated by is_voidable() (manual row, order in progress).
			'captured' => 'manual' === ( $row['capture_mode'] ?? '' ) ? array( 'voided' ) : array(),
		);
		if ( $to !== $from && ! in_array( $to, $allowed[ $from ] ?? array(), true ) ) {
			return $this->invalid_transition();
		}
		foreach ( array( 'status', 'failure_reason', 'receipt', 'captured_at_gmt', 'transport', 'expires_at', 'refunds', 'events', 'void_requested_at' ) as $field ) {
			if ( array_key_exists( $field, $new ) ) {
				$row[ $field ] = $new[ $field ];
			}
		}
		// Provider references merge: keys the confirmation carries are written, keys it does
		// not carry are kept (a webhook's intent id must never erase the till's reader, action
		// or Pro's own intent), and a null is ignored so no provider has to filter its
		// observation before sending it.
		if ( is_array( $new['provider_refs'] ?? null ) ) {
			$row['provider_refs'] = is_array( $row['provider_refs'] ?? null ) ? $row['provider_refs'] : array();
			foreach ( $new['provider_refs'] as $key => $value ) {
				if ( null !== $value ) {
					$row['provider_refs'][ $key ] = $value;
				}
			}
		}
		// The approval time is write-once: a later capture, void or status answer that
		// carries none (or an invalid one) must not erase the stamp already on the row.
		if ( $this->valid_time( $new['authorized_at_gmt'] ?? null ) ) {
			$row['authorized_at_gmt'] = $this->valid_time( $new['authorized_at_gmt'] );
		}
		return $row;
	}

	/**
	 * Normalize, persist, index, and derive a ledger.
	 *
	 * @internal The persistence step behind the Ledger's verbs, and the seeding seam
	 * for tests. Code outside this class writes rows through a verb (record, intent,
	 * capture, apply_result, refund, settle_allocation, adopt, append_events, void).
	 * A raw save() skips the transition table, the refund caps and the hooks.
	 *
	 * @param WC_Order   $order Order object.
	 * @param array      $rows  Payment rows.
	 * @param bool       $derive Whether to project payment state (deferred during offline arrival).
	 * @param array      $deleted_ids Explicitly removed payment ids (a refused intent only).
	 * @param array|null $read_rows Original rows read by an unlocked caller; null treats all rows as edited.
	 * @throws Ledger_Persistence_Exception When the ledger cannot be locked or persisted.
	 */
	public function save( WC_Order $order, array $rows, bool $derive = true, array $deleted_ids = array(), ?array $read_rows = null ): void {
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $order->get_id() ) ) {
			throw new Ledger_Persistence_Exception( 'WCPOS payment ledger is locked.' );
		}
		try {
			// updated_at_gmt is when a leg last changed. normalize_row() stamps now, so a row whose
			// content is what is already stored keeps its stored stamp: a write to one leg must not
			// make every other leg on the order look fresh to Payments_Sweeper.
			$fresh = wc_get_order( $order->get_id() );
			$fresh = $fresh instanceof WC_Order ? $fresh : $order;
			$fresh->read_meta_data( true );
			$epoch = Money_Epoch::current( $fresh );
			$stored = array();
			foreach ( $this->stored( $order->get_id(), false )['payments'] ?? array() as $existing ) {
				if ( is_array( $existing ) && isset( $existing['id'] ) ) {
					$stored[ strtolower( (string) $existing['id'] ) ] = $existing;
				}
			}
			// Refresh meta ids too: an object loaded before the first row otherwise inserts
			// a second ledger meta entry on HPOS. Keep meta deliberately staged by adopt's caller.
			$pending = array_filter( Money_Meta_Guard::raw_meta( $order ), static fn( $meta ) => ! $meta->id || $meta->get_changes() || null === $meta->value );
			$order->read_meta_data( true );
			foreach ( $pending as $meta ) {
				if ( null === $meta->value ) {
					$order->delete_meta_data_by_mid( $meta->id );
				} elseif ( $meta->id ) {
					$order->update_meta_data( $meta->key, $meta->value, $meta->id );
				} else {
					$order->add_meta_data( $meta->key, $meta->value, false );
				}
			}
			$original = array();
			foreach ( $read_rows ?? array() as $read_row ) {
				$original[ strtolower( (string) $read_row['id'] ) ] = $read_row;
			}
			$normalized = array();
			foreach ( $rows as $row ) {
				$requested_stamp = $row['updated_at_gmt'] ?? null;
				$row      = $this->normalize_row( $fresh, $row );
				$previous = $stored[ $row['id'] ] ?? null;
				$read_row = $original[ $row['id'] ] ?? null;
				if ( null !== $previous && null !== $read_row ) {
					$base = $this->normalize_row( $fresh, $read_row );
					$latest = $this->normalize_row( $fresh, $previous );
					if ( $this->same_content( $row, $base ) ) {
						$row = $latest;
					} else {
						// Carry concurrent server updates unless this writer explicitly edited them.
						foreach ( array( 'refunded_amount', 'refunds', 'events', 'seen_events' ) as $key ) {
							if ( $row[ $key ] === $base[ $key ] ) {
								$row[ $key ] = $latest[ $key ];
							}
						}
						if ( ( $read_row['updated_at_gmt'] ?? null ) === $requested_stamp && $this->valid_time( $previous['updated_at_gmt'] ?? null ) ) {
							$row['updated_at_gmt'] = $previous['updated_at_gmt'];
						}
					}
				}
				$row['epoch'] = null === $previous ? $epoch : (int) ( $previous['epoch'] ?? 0 );
				$stamp    = null === $previous ? null : $this->valid_time( $previous['updated_at_gmt'] ?? null );
				if ( null !== $stamp && $this->same_content( $this->normalize_row( $fresh, $previous ), $row ) ) {
					$row['updated_at_gmt'] = $stamp;
				}
				$normalized[] = $row;
				unset( $stored[ $row['id'] ] );
			}
			foreach ( $deleted_ids as $id ) {
				unset( $stored[ strtolower( $id ) ] );
			}
			// Absence is not deletion: a hook may have read before another route appended a leg.
			$normalized = array_merge( $normalized, array_values( $stored ) );
			$wire = array_map( array( __CLASS__, 'to_wire' ), $normalized );
			$order->update_meta_data(
				self::META_KEY,
				wp_json_encode(
					array(
						'schema' => self::SCHEMA,
						'payments' => $wire,
					)
				)
			);
			$this->reindex( $order, $normalized );
			$order->delete_meta_data( self::PAYMENT_ID_META_KEY );
			$order->delete_meta_data( self::LIVE_LEG_META_KEY );
			foreach ( $normalized as $row ) {
				$order->add_meta_data( self::PAYMENT_ID_META_KEY, $row['id'], false );
				if ( in_array( $row['status'], array( 'pending', 'authorized' ), true ) ) {
					$order->add_meta_data( self::LIVE_LEG_META_KEY, $row['id'], false );
				}
			}
			// Authorise server rollups through derive() too: payment_complete() can save the order.
			Money_Meta_Guard::authorised(
				$order->get_id(),
				function () use ( $order, $normalized, $derive ): void {
					$paid = Money_Epoch::net_paid( $normalized );
					if ( $paid > 0 && $paid >= Money::minor( $order->get_total() ) ) {
						$history = Gateway_Submission::read_attempts( $order );
						$updated = $history;
						foreach ( $updated as &$attempt ) {
							if ( 'sent' === $attempt['state'] ) {
								$attempt['state'] = 'paid';
							}
						}
						unset( $attempt );
						if ( $updated !== $history ) {
							$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, $updated );
						}
					}
					if ( $derive ) {
						$this->derive( $order, $normalized );
					}
					$order->save();
				}
			);
			// The keeper may legitimately restore closure stamps during save.
			if ( ( $this->stored( $order->get_id(), false )['payments'] ?? null ) !== $this->read( $order ) ) {
				throw new Ledger_Persistence_Exception( 'WCPOS payment ledger was not persisted.' );
			}
		} finally {
			$lock->release( $order->get_id() );
		}
	}

	/**
	 * Rebuild the distinct live payment-method index from the ledger being persisted.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows Payment rows.
	 */
	private function reindex( WC_Order $order, array $rows ): void {
		$order->delete_meta_data( self::INDEX_META_KEY );
		$indexed = array();
		foreach ( $rows as $row ) {
			// A pushed row is client input: no method id, no index row — never a notice on the save hook.
			$method = is_array( $row ) ? ( $row['method_id'] ?? null ) : null;
			if ( ! is_string( $method ) || '' === $method || ! in_array( $row['status'] ?? '', self::LIVE_STATUSES, true ) ) {
				continue;
			}
			if ( ! in_array( $method, $indexed, true ) ) {
				$indexed[] = $method;
				$order->add_meta_data( self::INDEX_META_KEY, $method, false );
			}
		}
	}

	/**
	 * Derive WooCommerce fields without changing the order total.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	public function derive( WC_Order $order, array $rows ): void {
		$counting = array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return in_array( $row['status'] ?? '', self::COUNTING_STATUSES, true );
				}
			)
		);
		$candidates = array_values(
			array_filter(
				$counting,
				static function ( array $row ): bool {
					return 'stored_value' !== ( $row['kind'] ?? '' );
				}
			)
		);
		$candidates = $candidates ? $candidates : $counting;
		$selected   = null;
		foreach ( $candidates as $row ) {
			$amount = Money::minor( $row['amount'] );
			$time   = $this->timestamp( $row['captured_at_gmt'] ?? null );
			if ( null === $selected || $amount > Money::minor( $selected['amount'] ) || ( Money::minor( $selected['amount'] ) === $amount && $time < $this->timestamp( $selected['captured_at_gmt'] ?? null ) ) ) {
				$selected = $row;
			}
		}
		// A sent gateway order (awaiting the customer off-till) is POS-owned whatever status
		// the gateway left it in, so it re-enters the projection the moment money lands.
		$stamped = null !== Gateway_Submission::read_stamp( $order );
		// No counting rows: leave payment_method/title untouched (the cart, or every leg voided).
		if ( $selected ) {
			Gateway_Submission::clear_stamp( $order );
			$titles = array();
			foreach ( $counting as $row ) {
				$descriptor = Descriptor_Builder::instance()->get( $row['method_id'] );
				$title      = $descriptor ? $descriptor['title'] : $row['method_id'];
				if ( ! in_array( $title, $titles, true ) ) {
					$titles[] = $title;
				}
			}
			$order->set_payment_method( $selected['method_id'] );
			$order->set_payment_method_title( implode( ' + ', $titles ) );
			$refs = is_array( $selected['provider_refs'] ?? null ) ? $selected['provider_refs'] : array();
			$order->set_transaction_id( (string) ( $refs['payment_intent'] ?? $refs['transaction_id'] ?? '' ) );
		}

		// Only the ledger-managed states are projected; a completed/processing/on-hold/refunded
		// order is never pulled back by a later ledger write (a refused row, a void of a leg).
		if ( ! in_array( $order->get_status(), self::IN_PROGRESS_STATUSES, true ) && ! $stamped ) {
			return;
		}
		$paid  = Money::minor( $this->paid( $rows ) );
		$total = Money::minor( $order->get_total() );
		if ( $total > 0 && $paid >= $total ) {
			if ( ! self::$deriving ) {
				self::$deriving = true;
				try {
					// Woo sets date_paid, reduces stock and fires woocommerce_payment_complete;
					// Orders::payment_complete_order_status() lands the per-gateway status
					// because payment_method was set above.
					Money_Meta_Guard::authorised( $order->get_id(), fn() => $order->payment_complete() );
				} finally {
					self::$deriving = false;
				}
			}
			return;
		}
		$pending = (bool) array_filter(
			$rows,
			static function ( array $row ): bool {
				return 'pending' === ( $row['status'] ?? '' );
			}
		);
		if ( ! $selected && $stamped ) {
			return;
		}
		$order->set_status( $pending ? 'pending' : ( $paid > 0 ? 'pos-partial' : 'pos-open' ) );
	}

	/**
	 * Shape a row for JSON: the two open maps encode as `{}` when empty, never `[]`.
	 *
	 * @param array $row Normalized row.
	 *
	 * @return array
	 */
	public static function to_wire( array $row ): array {
		$row['epoch'] = (int) ( $row['epoch'] ?? 0 );
		foreach ( array( 'provider_refs', 'receipt' ) as $field ) {
			if ( empty( $row[ $field ] ) ) {
				$row[ $field ] = new \stdClass();
			}
		}
		return $row;
	}

	/**
	 * The reader a leg is minted for, from the payment input or the handler context the app
	 * sends — recorded before the provider is called so a re-dispatch after a lost answer
	 * goes to the same reader.
	 *
	 * @param array $input   Payment input.
	 * @param array $context Provider context.
	 */
	private function reader_ref( array $input, array $context ): array {
		$reader = $input['provider_refs']['reader'] ?? ( $context['reader'] ?? null );
		return is_string( $reader ) && '' !== $reader ? array( 'reader' => sanitize_text_field( $reader ) ) : array();
	}

	/**
	 * Unclamped sum of pending and succeeded allocations, in minor units.
	 *
	 * @param array $row Payment row.
	 */
	private static function reserved_total( array $row ): int {
		$total = 0;
		foreach ( $row['refunds'] ?? array() as $allocation ) {
			if ( in_array( $allocation['status'], array( 'pending', 'succeeded' ), true ) ) {
				$total += Money::minor( $allocation['amount'] );
			}
		}
		return $total;
	}

	/**
	 * Sum of succeeded allocations, in minor units.
	 *
	 * @param array $row Payment row.
	 */
	private static function refunded_total( array $row ): int {
		$total = 0;
		foreach ( $row['refunds'] ?? array() as $allocation ) {
			if ( 'succeeded' === $allocation['status'] ) {
				$total += Money::minor( $allocation['amount'] );
			}
		}
		return $total;
	}

	/**
	 * Minor units reserved by pending legs: money a provider may still confirm.
	 *
	 * @param array $rows               Payment rows.
	 * @param bool  $include_cancelling Whether legs whose cancel is already requested still reserve.
	 */
	private function pending_reserved( array $rows, bool $include_cancelling = true ): int {
		$reserved = 0;
		foreach ( $rows as $row ) {
			if ( 'pending' === ( $row['status'] ?? '' ) && ( $include_cancelling || empty( $row['void_requested_at'] ) ) ) {
				$reserved += Money::minor( $row['amount'] ?? 0 );
			}
		}
		return $reserved;
	}

	/**
	 * A pending row the provider has never answered: no provider ref other than the reader
	 * the till chose. Such a row may be re-dispatched (idempotently) or ended locally.
	 *
	 * @param array $row Payment row.
	 */
	public static function is_unanswered( array $row ): bool {
		return 'pending' === ( $row['status'] ?? '' ) && empty( array_diff_key( (array) ( $row['provider_refs'] ?? array() ), array( 'reader' => true ) ) );
	}

	/**
	 * Normalize every required row field while preserving extras.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $row   Payment row.
	 */
	private function normalize_row( WC_Order $order, array $row ): array {
		$now      = gmdate( 'c' );
		$status   = in_array( $row['status'] ?? '', self::STATUSES, true ) ? $row['status'] : 'failed';
		$amount   = Money::normalize( $row['amount'] ?? 0 );
		$tendered = $this->nullable_money( $row['tendered'] ?? null );
		$defaults = array(
			'id' => '',
			'source' => 'app',
			'order_id' => $order->get_id(),
			'method_id' => '',
			'provider' => null,
			'kind' => 'other',
			'capture_mode' => '',
			'transport' => null,
			'recorded_offline' => false,
			'amount' => $amount,
			'currency' => $order->get_currency(),
			'tendered' => $tendered,
			'change' => null === $tendered ? null : Money::format( Money::minor( $tendered ) - Money::minor( $amount ) ),
			'tip' => $this->nullable_money( $row['tip'] ?? null ),
			'status' => $status,
			'failure_reason' => null,
			'refunded_amount' => Money::normalize( $row['refunded_amount'] ?? 0 ),
			'refunds' => array(),
			'expires_at' => null,
			'seen_events' => array(),
			// Server-mode handlers: cancel is a request, not a result — the stamp says one is
			// in flight so nobody asks the provider twice; events[] is the cashier log.
			'void_requested_at' => null,
			'events' => array(),
			'provider_refs' => array(),
			'receipt' => array(),
			'cashier_id' => 0,
			'store_id' => null,
			'register_id' => null,
			'session_id' => null,
			'created_at_gmt' => $this->valid_time( $row['created_at_gmt'] ?? null ) ? $this->valid_time( $row['created_at_gmt'] ?? null ) : $now,
			// Keep a valid captured_at_gmt across later transitions (a voided authorized leg
			// keeps the time the reader approved it); default to now only when the row counts.
			'captured_at_gmt' => $this->valid_time( $row['captured_at_gmt'] ?? null ) ? $this->valid_time( $row['captured_at_gmt'] ?? null ) : ( in_array( $status, self::COUNTING_STATUSES, true ) ? $now : null ),
			// When the reader approved the hold. captured_at_gmt used to double as this and is
			// overwritten at capture, so a dispute could not tell approval from settlement.
			// Stamped once, on the first authorized normalization, and kept afterwards.
			'authorized_at_gmt' => $this->valid_time( $row['authorized_at_gmt'] ?? null ) ? $this->valid_time( $row['authorized_at_gmt'] ?? null ) : ( 'authorized' === $status ? $now : null ),
			'updated_at_gmt' => $now,
		);
		$row = array_merge( $defaults, $row );
		foreach ( array( 'register_id', 'session_id' ) as $key ) {
			$row[ $key ] = Pos_Uuid::is_uuid( $row[ $key ] ) ? strtolower( $row[ $key ] ) : null;
		}
		$row['id']               = strtolower( (string) $row['id'] );
		$row['order_id']         = $order->get_id();
		$row['amount']           = $amount;
		$row['tendered']         = $tendered;
		$row['change']           = $defaults['change'];
		$row['tip']              = $defaults['tip'];
		$row['status']           = $status;
		$row['created_at_gmt']   = $defaults['created_at_gmt'];
		$row['captured_at_gmt']  = $defaults['captured_at_gmt'];
		$row['authorized_at_gmt'] = $defaults['authorized_at_gmt'];
		$row['cashier_id']       = (int) $row['cashier_id'];
		$row['store_id']         = null === $row['store_id'] ? null : (int) $row['store_id'];
		$row['recorded_offline'] = (bool) $row['recorded_offline'];
		$row['provider_refs']    = is_array( $row['provider_refs'] ) ? $row['provider_refs'] : array();
		$row['receipt']          = is_array( $row['receipt'] ) ? $row['receipt'] : array();
		$row['refunds']          = is_array( $row['refunds'] ) ? $row['refunds'] : array();
		$row['expires_at']       = $this->valid_time( $row['expires_at'] );
		$row['seen_events']      = is_array( $row['seen_events'] ) ? array_values( array_filter( $row['seen_events'], 'is_string' ) ) : array();
		$row['void_requested_at'] = $this->valid_time( $row['void_requested_at'] );
		$row['events']           = is_array( $row['events'] ) ? array_slice( array_values( array_filter( $row['events'], 'is_array' ) ), -self::EVENTS_MAX ) : array();
		$row['updated_at_gmt']   = $now;
		return $row;
	}

	/**
	 * Whether a descriptor may be recorded through `POST orders/{id}/payments`.
	 *
	 * Record asserts money that was already taken, so it is only for methods that take
	 * it away from the server: `manual` methods handed over at the till, and `device`
	 * methods settling the offline (`queue`) rows and replays that ride the order write
	 * (routes.md §4.2). Everything else — a webview gateway, a server-mode provider —
	 * has to run its own leg through `intent`/`capture`, so a caller-asserted captured
	 * row here would mark the order paid with no provider transaction behind it.
	 *
	 * @param array $descriptor Payment method descriptor.
	 */
	private function is_recordable( array $descriptor ): bool {
		if ( 'manual' === ( $descriptor['capture']['mode'] ?? '' ) ) {
			return true;
		}

		return in_array( (string) ( $descriptor['capabilities']['offline'] ?? 'none' ), array( 'record', 'queue' ), true );
	}

	/**
	 * Find a row without reading storage again.
	 *
	 * @param array  $rows Payment rows.
	 * @param string $id   Payment ID.
	 */
	private function find_in_rows( array $rows, string $id ): ?array {
		foreach ( $rows as $row ) {
			if ( ( $row['id'] ?? null ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Replace a row and persist the ledger.
	 *
	 * @param WC_Order $order       Order object.
	 * @param array    $rows        Payment rows.
	 * @param array    $replacement Replacement payment row.
	 * @param bool     $derive      Project payment state after saving.
	 */
	private function replace_and_save( WC_Order $order, array $rows, array $replacement, bool $derive = true ): void {
		foreach ( $rows as &$row ) {
			if ( $row['id'] === $replacement['id'] ) {
				$row = $replacement;
				break;
			}
		}
		unset( $row );
		$this->save( $order, $rows, $derive );
	}

	/**
	 * Whether two normalized rows hold the same content, ignoring when they were written.
	 *
	 * @param array $a Normalized row.
	 * @param array $b Normalized row.
	 */
	private function same_content( array $a, array $b ): bool {
		unset( $a['updated_at_gmt'], $b['updated_at_gmt'] );
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Normalize optional money, dropping invalid values.
	 *
	 * @param mixed $value Money value.
	 */
	private function nullable_money( $value ): ?string {
		$value = null === $value ? '' : wc_format_decimal( $value, wc_get_price_decimals() );
		return '' !== $value && is_numeric( $value ) ? Money::normalize( $value ) : null;
	}

	/**
	 * Return an ISO time only when parseable.
	 *
	 * @param mixed $value Time value.
	 */
	private function valid_time( $value ): ?string {
		return is_string( $value ) && false !== strtotime( $value ) ? $value : null;
	}

	/**
	 * Sort missing timestamps after real timestamps.
	 *
	 * @param mixed $value Time value.
	 */
	private function timestamp( $value ): int {
		$timestamp = is_string( $value ) ? strtotime( $value ) : false;
		return false === $timestamp ? PHP_INT_MAX : $timestamp;
	}

	/**
	 * Standard invalid input response.
	 *
	 * @param string $message Error message.
	 */
	private function invalid( string $message ): WP_Error {
		return new WP_Error( 'rest_invalid_param', $message, array( 'status' => 400 ) );
	}

	/** Standard missing row response. */
	private function not_found(): WP_Error {
		return new WP_Error( 'wcpos_payment_not_found', __( 'Payment not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}

	/** Standard unsupported mode response. */
	private function unsupported(): WP_Error {
		return new WP_Error( 'wcpos_capture_mode_unsupported', __( 'Payment capture mode is unsupported.', 'woocommerce-pos' ), array( 'status' => 501 ) );
	}

	/** Standard invalid lifecycle response. */
	private function invalid_transition(): WP_Error {
		return new WP_Error( 'wcpos_invalid_transition', __( 'Payment transition is invalid.', 'woocommerce-pos' ), array( 'status' => 409 ) );
	}
}
