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
use WCPOS\WooCommercePOS\Services\Save_Hooks;
use WCPOS\WooCommercePOS\Services\Stock_Validator;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_Error;

/** Persists payment legs and derives WooCommerce order payment state. */
class Ledger {
	public const META_KEY = '_wcpos_payments';
	public const INDEX_META_KEY = '_wcpos_payment_method';
	public const PAYMENT_ID_META_KEY = '_wcpos_payment_id';
	/**
	 * One meta row per pending or authorized leg. What the sweep selects orders by: a status
	 * filter would lose a leg on an order whose status moved on (a cancel, an earlier
	 * completion), which is exactly the leg that most needs reconciling.
	 */
	public const LIVE_LEG_META_KEY = '_wcpos_payment_live';
	/** On a Tip fee item: the row whose on-reader capture added it, so a retried capture adds none. Server-owned (rule 2). */
	public const TIP_FEE_META_KEY = '_wcpos_tip_payment_id';
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
	private const RECORD_INPUT_KEYS = array( 'id', 'method_id', 'amount', 'currency', 'status', 'tendered', 'register_id', 'session_id', 'recorded_offline', 'transport', 'created_at_gmt', 'captured_at_gmt', 'authorized_at_gmt', 'provider_refs', 'receipt' );
	public const SCHEMA = 1;
	public const LIVE_STATUSES = array( 'pending', 'authorized', 'captured' );
	/** Only captured money pays a sale (ADR 0040 rule 1, D6): an authorization is money in motion. */
	public const COUNTING_STATUSES = array( 'captured' );
	/** Provider answers whose amount and currency are verified against the row before they are applied. */
	private const VERIFIED_STATUSES = array( 'authorized', 'captured' );
	public const STATUSES = array( 'pending', 'authorized', 'captured', 'failed', 'voided' );

	/**
	 * Order statuses the ledger still projects. Once an order has completed (or is
	 * processing / on-hold / refunded) a ledger write never pulls it back — so that is
	 * also the boundary past which a captured cash leg is refunded rather than voided.
	 */
	public const IN_PROGRESS_STATUSES = array( 'pos-open', 'pos-partial', 'pending', 'failed' );
	public const KINDS = array( 'cash', 'card', 'stored_value', 'bank_transfer', 'other' );
	/** Who opened a row: the till, a webview mint, or WooCommerce itself (a tender adopted at reopen, D5). */
	public const SOURCES = array( 'app', 'webview', 'woocommerce' );
	/** Write modes: project and maybe complete; stage only; or close (may complete zero or on-hold). */
	public const MODE_AUTO  = 'auto';
	public const MODE_HOLD  = 'hold';
	public const MODE_CLOSE = 'close';

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

	/**
	 * Ledger-owned saves in progress, keyed by order id: only these may write server-owned row fields.
	 *
	 * @var array<int, int>
	 */
	private $server_saves = array();

	/**
	 * Orders whose save the keeper refused in this request (WooCommerce swallows the throw).
	 *
	 * @var array<int, bool>
	 */
	private static $refused = array();

	/**
	 * Writes in progress, by order id; a nested write counts.
	 *
	 * @var array<int, int>
	 */
	private static $writing = array();

	/**
	 * Deferred work by order id: [ work, label ] pairs.
	 *
	 * @var array<int, array<int, array{0: callable, 1: string}>>
	 */
	private static $deferred = array();

	/**
	 * Depth of save_meta() calls.
	 *
	 * @var int
	 */
	private static $meta_only = 0;

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
	 * @var int The order derive() is running payment_complete() on, 0 when none — lets hooks skip re-entry.
	 */
	private static $deriving = 0;

	/**
	 * Whether the ledger is deriving order state (inside payment_complete()): for this order when one
	 * is named, for any order otherwise.
	 *
	 * @param int|null $order_id Order ID, or null for any order.
	 */
	public static function is_deriving( ?int $order_id = null ): bool {
		return null === $order_id ? 0 !== self::$deriving : ( 0 !== $order_id && self::$deriving === $order_id );
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
	private function decode( $raw ): ?array {
		$value = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		return is_array( $value ) && self::SCHEMA === ( $value['schema'] ?? null ) && isset( $value['payments'] ) && is_array( $value['payments'] ) ? $value : null;
	}

	/** Read storage, not the possibly stale order object.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $lock Use a current locking read, not the transaction snapshot.
	 * @return array|null Meta id and payments, or no readable ledger.
	 * @throws Ledger_Persistence_Exception On a read failure or an invalid locked ledger: retryable.
	 */
	private function stored( int $order_id, bool $lock ): ?array {
		global $wpdb;
		$hpos = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id = $hpos ? 'id' : 'meta_id';
		$owner = $hpos ? 'order_id' : 'post_id';
		$suffix = $lock ? ' FOR UPDATE' : '';
		// `meta_key` compares under a `_ci` PAD SPACE collation, so `_WCPOS_PAYMENTS` matches too: the
		// binary comparison keeps only the exact key, and the plain one keeps the index usable.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Storage identifiers and lock clause are selected above.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$id} AS meta_id, meta_value FROM {$table} WHERE {$owner} = %d AND meta_key = %s AND CAST(meta_key AS BINARY) = %s ORDER BY {$id} ASC LIMIT 1{$suffix}", $order_id, self::META_KEY, self::META_KEY ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new Ledger_Persistence_Exception( 'Ledger storage read failed.' );
		}
		if ( ! $row ) {
			return null;
		}
		$value = $this->decode( maybe_unserialize( $row['meta_value'] ) );
		if ( null === $value ) {
			if ( $lock ) {
				throw new Ledger_Persistence_Exception( 'Ledger storage decode failed.' );
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
		self::save_meta( $order );
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
				// Server-owned history, command entries and refund allocations cannot arrive with a new order.
				unset( $row['closure_id'], $row['updated_at_gmt'] );
				foreach ( array( 'seen_events', 'events', 'commands' ) as $field ) {
					if ( array_key_exists( $field, $row ) ) {
						$row[ $field ] = array();
					}
				}
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
			$property = new \ReflectionProperty( \WC_Data::class, 'meta_data' );
			$property->setAccessible( true );
			foreach ( $property->getValue( $order ) as $meta ) {
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
			self::$refused[ $order_id ] = true;
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
		foreach ( $value['payments'] as &$row ) {
			$id = strtolower( $row['id'] );
			$present[ $id ] = true;
			if ( empty( $this->server_saves[ $order_id ] ) ) {
				// Event history, the change stamp and refund allocations are server-owned, not order-write intent.
				$previous = $stored_rows[ $id ] ?? null;
				foreach ( array( 'seen_events', 'events', 'commands' ) as $field ) {
					if ( array_key_exists( $field, $row ) || isset( $previous[ $field ] ) ) {
						$row[ $field ] = $previous[ $field ] ?? array();
					}
				}
				unset( $row['updated_at_gmt'] );
				if ( isset( $previous['updated_at_gmt'] ) ) {
					$row['updated_at_gmt'] = $previous['updated_at_gmt'];
				}
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

	/**
	 * Whether the keeper refused a save of this order since the last check; reading clears it.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save_was_refused( int $order_id ): bool {
		$refused = isset( self::$refused[ $order_id ] );
		unset( self::$refused[ $order_id ] );
		return $refused;
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

	/**
	 * Run a plugin-owned order save; a failed save releases only the keeper locks it took.
	 *
	 * WooCommerce catches save exceptions without an after-save hook, so the keeper's own
	 * acquisition would otherwise outlive the save. An enclosing caller keeps its lock.
	 *
	 * @param int      $order_id Order being saved.
	 * @param callable $work     Plugin-owned save operation.
	 * @return mixed
	 */
	public static function saving( int $order_id, callable $work ) {
		$ledger = self::instance();
		$count  = $ledger->closure_locks[ $order_id ] ?? 0;
		try {
			return $work();
		} finally {
			while ( ( $ledger->closure_locks[ $order_id ] ?? 0 ) > $count ) {
				Order_Lock::instance()->release( $order_id );
				if ( 0 === --$ledger->closure_locks[ $order_id ] ) {
					unset( $ledger->closure_locks[ $order_id ] );
				}
			}
		}
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
	 * Sum captured rows.
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
		return Money::format( max( 0, $this->signed_balance( $order, $rows ) ) );
	}

	/**
	 * The signed balance in minor units (ADR 0040 rule 1): total − Σ captured rows + Σ succeeded
	 * refund allocations. Positive is still due; negative is owed back to the customer. A pending
	 * allocation is a reservation shown beside it, never added.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 */
	public function signed_balance( WC_Order $order, array $rows ): int {
		$refunded = 0;
		foreach ( $rows as $row ) {
			$refunded += self::refunded_total( $row );
		}
		return Money::minor( $order->get_total() ) - Money::minor( $this->paid( $rows ) ) + $refunded;
	}

	/**
	 * Build the ledger-backed order summary.
	 *
	 * @param WC_Order   $order Order object.
	 * @param array|null $rows  Payment rows.
	 */
	public function summary( WC_Order $order, ?array $rows = null ): array {
		$rows = null === $rows ? $this->read( $order ) : $rows;
		$authorized = 0;
		$pending    = 0;
		foreach ( $rows as $row ) {
			if ( 'authorized' === ( $row['status'] ?? '' ) ) {
				$authorized += Money::minor( $row['amount'] ?? 0 );
			}
			foreach ( $row['refunds'] ?? array() as $allocation ) {
				$pending += 'pending' === ( $allocation['status'] ?? '' ) ? Money::minor( $allocation['amount'] ?? 0 ) : 0;
			}
		}
		return array(
			'status'               => $order->get_status(),
			'total'                => Money::normalize( $order->get_total() ),
			'paid'                 => $this->paid( $rows ),
			'authorized'           => Money::format( $authorized ),
			'balance'              => $this->balance( $order, $rows ),
			'refund_due'           => Money::format( max( 0, -$this->signed_balance( $order, $rows ) ) ),
			'refund_pending'       => Money::format( $pending ),
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
	 * @param array    $command The till's command (row id, body fingerprint); its entry rides the row's save.
	 *
	 * @return array|\WP_Error
	 */
	public function record( WC_Order $order, array $input, array $context = array(), array $command = array() ) {
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
				self::saving( $order->get_id(), fn() => $order->save() );
			}
			return $this->find( $order, $stored['id'] );
		}
		// A closed sale takes no new tender (§4.2). A paid one keeps the refused-row record below
		// (offline cash may already be in the drawer); one never paid is refused outright.
		$closed = $this->accepts_tender( $order );
		if ( $closed && 'wcpos_order_already_paid' !== $closed->get_error_code() ) {
			return $closed;
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
		if ( 'authorized' !== $status ) {
			unset( $input['authorized_at_gmt'] ); // The approval time belongs to an authorization; a captured record was never on hold.
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

		$balance = $closed ? 0 : Money::minor( $this->balance( $order, $rows ) );
		// A provider leg still pending has reserved its share of the balance: cash taken on top
		// of it would count twice if the terminal later confirms (the money moved both ways).
		// A leg the till has already asked to cancel does not block cash: the cashier accepted
		// that risk by releasing it, and a provider outage must not stop the sale.
		$reserved = $this->pending_reserved( $rows, false );
		if ( 0 === $balance || Money::minor( $amount ) > $balance || Money::minor( $amount ) > $balance - $reserved ) {
			$row['status']          = 'failed';
			$row['failure_reason']  = 0 === $balance ? 'order_already_paid' : ( Money::minor( $amount ) > $balance ? 'amount_exceeds_balance' : 'payment_in_flight' );
			$row['captured_at_gmt'] = null;
			// The refusal is the command's stored outcome: its replay answers the same refusal.
			$row = $this->normalize_row( $order, $this->with_command( $row, $command ) );
			$rows[]                 = $row;
			$this->save( $order, $rows );
			Settlement::instance()->apply_parked( $order, $row['id'] );
			$row = $this->find( $order, $row['id'] ) ?? $row;
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

		// Prevent-overselling holds the sale's stock before money is taken for it (§7.5).
		$held = Stock_Validator::instance()->validate_checkout( $order );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		$row    = $this->normalize_row( $order, $this->with_command( $row, $command ) );
		$rows[] = $row;
		// Index the arrival before consuming its webhook, but do not mark money paid
		// until a parked provider confirmation has passed capture verification.
		$this->save( $order, $rows, false );
		$settled = Settlement::instance()->apply_parked( $order, $row['id'] );
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
		$this->derive( $order, $this->read( $order ) );
		self::saving( $order->get_id(), fn() => $order->save() );
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
	 * @param array    $command The till's command (`id`, `fp`, `kind`); empty stores no entry.
	 * @return array|WP_Error
	 */
	public function intent( WC_Order $order, string $id, array $input, array $context, array $command = array() ) {
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
				// A new command id on a settled leg changes nothing else, but its entry is still kept.
				$noted = $this->with_command( $replayed, $command );
				if ( $noted !== $replayed ) {
					$replayed = $this->replace_and_save( $order, $rows, $noted, false );
				}
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
					$failed = $this->apply_transition(
						$replayed,
						array(
							'status' => 'failed',
							'failure_reason' => $resumed->get_error_code(),
						)
					);
					if ( ! is_wp_error( $failed ) ) {
						// The command failed, so it keeps no entry (spec §5.2): its retry is a fresh command.
						$this->replace_and_save( $order, $rows, $this->normalize_row( $order, Command_Log::without_on_row( $failed, (string) ( $command['id'] ?? '' ) ) ) );
						$this->release_hold_if_idle( $order );
					}
				}
				return $resumed;
			}
			$handoff = $resumed['handoff'] ?? array();
			// A resumed leg can come back captured (the reader finished while the till was
			// away), so it takes the same verification as capture(), never a bare transition.
			$applied = $this->apply_result( $order, $replayed['id'], $resumed, true, $command );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			return array(
				'payment' => $applied,
				'handoff' => $handoff,
			);
		}
		// Only a NEW leg needs the method enabled: a replay or resume above answers what already started.
		if ( ! $descriptor['pos_enabled'] ) {
			return new WP_Error( 'wcpos_payment_method_disabled', __( 'Payment method is disabled.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}
		$closed = $this->accepts_tender( $order );
		if ( $closed ) {
			return $closed;
		}
		$balance = Money::minor( $this->balance( $order, $rows ) );
		if ( 0 === $balance || Money::minor( $amount ) > $balance ) {
			return new WP_Error( 0 === $balance ? 'wcpos_order_already_paid' : 'wcpos_amount_exceeds_balance', 0 === $balance ? __( 'The order is already paid.', 'woocommerce-pos' ) : __( 'Payment amount exceeds the order balance.', 'woocommerce-pos' ), array( 'status' => 0 === $balance ? 409 : 400 ) );
		}
		$pending = $this->pending_reserved( $rows );
		$newest_pending = null;
		foreach ( $rows as $existing ) {
			if ( in_array( $existing['status'], array( 'pending', 'authorized' ), true ) && ( null === $newest_pending || strtotime( $existing['created_at_gmt'] ) >= strtotime( $newest_pending['created_at_gmt'] ) ) ) {
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
		$held = Stock_Validator::instance()->validate_checkout( $order );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		$row = $this->normalize_row(
			$order,
			array(
				'id' => $id,
				// The entry rides the save before the provider is asked: a lost answer still holds it.
				'commands' => $this->with_command( array(), $command )['commands'] ?? array(),
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
				self::saving( $order->get_id(), fn() => $order->save() );
			} else {
				$rows = array_filter( $rows, static fn( $saved ) => $saved['id'] !== $row['id'] );
				// Delete only this refused intent; omitted concurrent rows must survive.
				$this->save( $order, $rows, false, array( $row['id'] ) );
				$this->release_hold_if_idle( $order );
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
		self::saving( $order->get_id(), fn() => $order->save() );
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
	 * @param array    $command The till's command; empty stores no entry.
	 * @return array|WP_Error
	 */
	public function capture( WC_Order $order, string $id, array $context, array $command = array() ) {
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
		return $this->apply_result( $order, $id, $new, true, $command );
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
	 * @param array    $command The command this result answers; its entry rides the result's save.
	 * @return array|WP_Error
	 */
	public function apply_result( WC_Order $order, string $id, array $new, bool $derive = true, array $command = array() ) {
		$rows = $this->read( $order );
		$row = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$event = $new['event_id'] ?? null;
		if ( is_string( $event ) && in_array( $event, $row['seen_events'] ?? array(), true ) ) {
			$noted = $this->with_command( $row, $command );
			if ( $noted !== $row ) {
				$noted = $this->replace_and_save( $order, $rows, $noted, false );
			}
			return $noted;
		}
		$refusal = $this->refusal_error( $row, $order );
		if ( $refusal ) {
			return $refusal;
		}
		$applied = $this->apply_transition( $row, $new );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		if ( in_array( $applied['status'], self::VERIFIED_STATUSES, true ) ) {
			$confirmed = array_key_exists( 'amount', $new ) ? $new['amount'] : $row['amount'];
			$valid = is_numeric( $confirmed );
			$difference = $valid ? Money::minor( $confirmed ) - Money::minor( $row['amount'] ) : 0;
			$currency = ! array_key_exists( 'currency', $new ) || $new['currency'] === $row['currency'];
			$descriptor = Descriptor_Builder::instance()->get( $row['method_id'] );
			$tip = $difference > 0 && 'on_reader' === ( $descriptor['capabilities']['tips'] ?? 'none' );
			if ( ! $valid || ! $currency || ( 0 !== $difference && ! $tip ) ) {
				$applied['status'] = 'failed';
				$applied['failure_reason'] = 'amount_mismatch';
				$applied = $this->replace_and_save( $order, $rows, $this->normalize_row( $order, $applied ), $derive );
				$this->release_hold_if_idle( $order );
				/* translators: 1: payment ID, 2: expected amount/currency, 3: confirmed amount/currency. */
				$order->add_order_note( sprintf( __( 'WCPOS payment %1$s amount mismatch: expected %2$s, confirmed %3$s.', 'woocommerce-pos' ), $row['id'], $row['amount'] . ' ' . $row['currency'], ( is_scalar( $confirmed ) ? (string) $confirmed : 'invalid' ) . ' ' . ( is_string( $new['currency'] ?? null ) ? $new['currency'] : $row['currency'] ) ) );
				return $this->refusal_error( $applied, $order );
			}
			// An authorization above the leg is tolerated but takes no tip: the hold may yet be voided,
			// and only the captured amount confirms what the customer paid (the capture adds the fee).
			if ( $tip && 'captured' === $applied['status'] ) {
				// update_taxes() and calculate_totals() each save the order before the row: a retry after a
				// failed row save finds the fee and adds none, but still recalculates (either save may have failed).
				$tipped = array_filter( $order->get_fees(), static fn( $item ) => $row['id'] === $item->get_meta( self::TIP_FEE_META_KEY ) );
				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( __( 'Tip', 'woocommerce-pos' ) );
				$fee->add_meta_data( self::TIP_FEE_META_KEY, $row['id'], true );
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
				if ( ! $tipped ) {
					$order->add_item( $fee );
				}
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
		$applied = $this->with_command( $applied, $command );
		if ( $applied !== $row ) {
			$applied = $this->replace_and_save( $order, $rows, $this->normalize_row( $order, $applied ), $derive );
			if ( in_array( $applied['status'], array( 'failed', 'voided' ), true ) ) {
				$this->release_hold_if_idle( $order );
			}
		}
		return $applied;
	}

	/**
	 * Allocate an existing WooCommerce refund to a payment row, in two verified saves around the
	 * provider (ADR 0040 rule 8): reserved `pending` before the call, the answer after. Nothing
	 * thrown escapes; an outcome the server cannot know returns the stored, still pending row.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $id        Payment ID.
	 * @param int      $refund_id WooCommerce refund ID.
	 * @param string   $amount    Allocation amount.
	 * @param array    $command   The till's command; empty (Pro's callers) stores no entry.
	 * @return array|WP_Error
	 */
	public function refund( WC_Order $order, string $id, int $refund_id, string $amount, array $command = array() ) {
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
				// Already allocated: a new command id changes nothing else, but its entry is kept.
				$noted = $this->with_command( $row, $command, $refund_id );
				if ( $noted !== $row ) {
					return $this->replace_and_save( $order, $rows, $noted, false );
				}
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
		if ( ! $handler ) {
			return $this->unsupported();
		}
		// Rule 8, save 1: the allocation is reserved `pending`, with the command's entry, before the provider is asked.
		$reserve = array(
			'id'           => $refund_id,
			'amount'       => Money::normalize( $amount ),
			'status'       => 'pending',
			'provider_ref' => null,
		);
		$entry   = empty( $command['id'] ) ? null : strtolower( $command['id'] );
		$held    = $this->with_command( $row, $command, $refund_id );
		$held['refunds'][] = $reserve;
		// No money moved: take the reservation and its entry back out, so the same command runs again (§7.13).
		$refuse = fn( WP_Error $answer ): WP_Error => $this->withdraw( $order, $rows, $row, $reserve, $entry, $answer );
		$strand = fn( string $cause ): array => $this->strand( $order, $rows, $held, $reserve, $entry, $cause );
		if ( ! $this->persist( $order, $rows, $held, false, fn( ?array $stored ) => $this->reserved( $stored, $reserve, $held, $entry ) ) ) {
			// Returned, never thrown: Pro allocates one leg at a time inside WooCommerce's deleting catch.
			return $refuse( new WP_Error( 'woo_rxdb_sync_record_locked', __( 'The refund could not be reserved; retry.', 'woocommerce-pos' ), array( 'status' => 409 ) ) );
		}
		try {
			// The row as it was before the reservation: every handler appends its own allocation.
			$new = $handler->refund( $row, $refund_id, Money::normalize( $amount ) );
		} catch ( \Throwable $error ) {
			return $strand( 'the provider call threw ' . get_class( $error ) . ': ' . $error->getMessage() );
		}
		if ( is_wp_error( $new ) ) {
			$data = $new->get_error_data();
			// The provider may have refunded: the reservation stands, and the answer is the stored row (§7.16).
			return is_array( $data ) && true === ( $data['indeterminate'] ?? false ) ? $strand( 'the provider answered indeterminately: ' . $new->get_error_message() ) : $refuse( $new );
		}
		// A check that fails once the handler reports money moved (or in motion) strands; only "no money moved" withdraws.
		$moved = (bool) array_filter( (array) ( $new['refunds'] ?? array() ), static fn( $allocation ) => (int) ( $allocation['id'] ?? 0 ) === $refund_id && in_array( $allocation['status'] ?? '', array( 'succeeded', 'pending' ), true ) );
		$fail  = fn( WP_Error $error ) => $moved ? $strand( 'the handler reported it, but ' . $error->get_error_code() . ': ' . $error->get_error_message() ) : $refuse( $error );
		$row   = $this->apply_transition( $row, $new );
		if ( is_wp_error( $row ) ) {
			return $fail( $row );
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
			return $fail( new WP_Error( 'wcpos_refund_not_allocatable', __( 'Refund amount cannot be allocated to this payment.', 'woocommerce-pos' ), array( 'status' => 400 ) ) );
		}
		$row['refunded_amount'] = Money::format( $refunded );
		$row = $this->normalize_row( $order, $this->with_command( $row, $command, $refund_id ) );
		// Save 2: the handler's allocation replaces the reservation; storage must hold it whole, with the command's entry.
		$answer = current( array_filter( $row['refunds'], static fn( $allocation ) => (int) $allocation['id'] === $refund_id ) );
		// Not derived in the same save: a settle attempt that misses must not have completed the sale (rule 3).
		$settled = fn( ?array $stored ): bool => $this->reserved( $stored, $answer, $row, $entry );
		if ( ! $this->persist( $order, $rows, $row, false, $settled ) ) {
			return $strand( 'its settle did not land' );
		}
		// The allocation has landed; derive on its own save, as Payments_Sweeper::run() does.
		$derive = function () use ( $order ): ?string {
			$this->derive( $order, $this->read( $order ) );
			if ( $order->get_changes() ) {
				self::saving( $order->get_id(), fn() => $order->save() );
			}
			$derived = wc_get_order( $order->get_id() );
			return $derived instanceof WC_Order && $derived->get_status() === $order->get_status() ? null : 'the derived status was not stored';
		};
		if ( ! $this->after_settle( $order, $rows, $row, $settled, $derive ) ) {
			// No hook and no success storage does not hold: the reservation is the guard, re-persisted (§7.16).
			return $strand( 'the provider settled it, but storage will not keep the settled allocation' );
		}
		$row = $this->stored_row( $order->get_id(), $row['id'] ) ?? $row;
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
		try {
			do_action( 'woocommerce_pos_refund_allocated', $order, $row, $refund, Money::format( $saved ) );
		} catch ( \Throwable $error ) {
			// The allocation is stored; a listener's throw must not reach WooCommerce's deleting catch (rule 8).
			$this->report( $order, sprintf( 'WCPOS refund #%d allocated on order #%d, but a woocommerce_pos_refund_allocated listener threw: %s', $refund_id, $order->get_id(), $error->getMessage() ) );
		}
		return $row;
	}

	/**
	 * Write a refund's row whole and verify it from storage, once more on a miss. A save that
	 * throws is a miss like one that does not land: nothing escapes refund() (rule 8).
	 *
	 * @param WC_Order $order       Order object.
	 * @param array    $rows        Payment rows as read.
	 * @param array    $replacement The row to store.
	 * @param bool     $derive      Project payment state.
	 * @param callable $landed      Whether the stored row (null when unreadable) is what was written.
	 */
	private function persist( WC_Order $order, array $rows, array $replacement, bool $derive, callable $landed ): bool {
		for ( $try = 0; $try < 2; ++$try ) {
			try {
				$this->replace_and_save( $order, $rows, $replacement, $derive );
			} catch ( \Throwable $error ) {
				$this->report( $order, sprintf( 'WCPOS refund save on order #%d failed: %s', $order->get_id(), $error->getMessage() ), false );
			}
			if ( $landed( $this->stored_row( $order->get_id(), $replacement['id'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every save refund() makes once its settle has landed runs through here: the save inside a
	 * Throwable guard (a failure is a warning; the next money command or `close` re-derives,
	 * rule 1), then storage must still hold the settled row, re-persisted with derive off when
	 * a save lost it.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $rows    Payment rows as read.
	 * @param array    $row     The settled row.
	 * @param callable $settled Whether a stored row holds the settle (allocation whole, entry).
	 * @param callable $save    The save; returns why it did not land, or null.
	 * @return bool Whether storage holds the settled row.
	 */
	private function after_settle( WC_Order $order, array $rows, array $row, callable $settled, callable $save ): bool {
		try {
			$failed = $save();
		} catch ( \Throwable $error ) {
			$failed = $error->getMessage();
		}
		if ( null !== $failed ) {
			$this->report( $order, sprintf( 'WCPOS refund settled on order #%d, payment %s, but a save after it failed: %s', $order->get_id(), $row['id'], $failed ), false );
		}
		return $settled( $this->stored_row( $order->get_id(), $row['id'] ) ) || $this->persist( $order, $rows, $row, false, $settled );
	}

	/**
	 * One row as storage holds it; null when absent or unreadable.
	 *
	 * @param int    $order_id Order id.
	 * @param string $id       Payment ID.
	 */
	private function stored_row( int $order_id, string $id ): ?array {
		try {
			return $this->find_in_rows( $this->stored( $order_id, false )['payments'] ?? array(), $id );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * A refund the provider was (or may have been) asked for, whose answer is not in storage:
	 * the reservation stands, marked for a human, and the stored row is the answer, never an
	 * error (Pro's hook would throw an error and WooCommerce delete the refund; spec §7.16).
	 *
	 * Storage must still hold the reservation (a settle can land with it stripped): when it does
	 * not, the reserved row is written again; when that does not land either, the answer is the
	 * reserved row, so it still says pending.
	 *
	 * @param WC_Order    $order   Order object.
	 * @param array       $rows    Payment rows as read.
	 * @param array       $held    The reserved row.
	 * @param array       $reserve The reservation.
	 * @param string|null $entry   The command id, if any.
	 * @param string      $cause   What happened.
	 */
	private function strand( WC_Order $order, array $rows, array $held, array $reserve, ?string $entry, string $cause ): array {
		$this->mark( $order, $held, $reserve, $cause . '; the allocation stays pending until it is confirmed with the provider' );
		$kept = fn( ?array $stored ): bool => $this->reserved( $stored, $reserve, $held, $entry );
		if ( $kept( $this->stored_row( $order->get_id(), $held['id'] ) ) || $this->persist( $order, $rows, $held, false, $kept ) ) {
			return $this->stored_row( $order->get_id(), $held['id'] ) ?? $held;
		}
		$this->report( $order, sprintf( 'WCPOS refund #%d of %s on order #%d, payment %s: storage will not keep the reservation; the provider may have refunded with no allocation stored.', $reserve['id'], $reserve['amount'], $order->get_id(), $held['id'] ) );
		return $held;
	}

	/**
	 * Whether a stored row holds this allocation whole and alone (the reservation, or the settled
	 * answer) and, when there is a command, its entry as written.
	 *
	 * @param array|null  $stored  Stored row.
	 * @param array       $reserve The allocation.
	 * @param array       $written The row that was written.
	 * @param string|null $entry   The command id, if any.
	 */
	private function reserved( ?array $stored, array $reserve, array $written, ?string $entry ): bool {
		if ( ! $this->same_allocation( $reserve, $stored, (int) $reserve['id'] ) ) {
			return false;
		}
		if ( null === $entry ) {
			return true;
		}
		// The entry as written, not just its key: Command_Log::find() trusts what is stored. at_gmt is not
		// compared (the settle's entry is minted again, so its stamp may be a second later than the reservation's).
		$want = $written['commands'][ $entry ] ?? null;
		$got  = $stored['commands'][ $entry ] ?? null;
		foreach ( array( 'fp', 'kind', 'ref' ) as $field ) {
			if ( ! is_array( $want ) || ! is_array( $got ) || ( $want[ $field ] ?? null ) !== ( $got[ $field ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a stored row holds this allocation for the refund: status, reference and amount.
	 *
	 * @param array      $allocation Expected allocation.
	 * @param array|null $stored     Stored row.
	 * @param int        $refund_id  WooCommerce refund ID.
	 */
	private function same_allocation( array $allocation, ?array $stored, int $refund_id ): bool {
		// Exactly one allocation for the refund: a merged duplicate would keep counting in the caps and refund_pending.
		$found = array_values( array_filter( (array) ( $stored['refunds'] ?? array() ), static fn( $kept ) => (int) ( $kept['id'] ?? 0 ) === $refund_id ) );
		if ( 1 !== count( $found ) ) {
			return false;
		}
		$kept = $found[0];
		return ( $allocation['status'] ?? null ) === ( $kept['status'] ?? null ) && (string) ( $allocation['provider_ref'] ?? '' ) === (string) ( $kept['provider_ref'] ?? '' ) && Money::minor( $allocation['amount'] ?? 0 ) === Money::minor( $kept['amount'] ?? 0 );
	}

	/**
	 * Take a reservation back out (no money moved): the row as it was before it, without the
	 * command's entry. A withdrawal that does not land is logged and noted; the answer stands.
	 *
	 * @param WC_Order    $order   Order object.
	 * @param array       $rows    Payment rows as read.
	 * @param array       $row     The row before the reservation.
	 * @param array       $reserve The reservation.
	 * @param string|null $entry   The command id, if any.
	 * @param WP_Error    $answer  The refusal to answer.
	 */
	private function withdraw( WC_Order $order, array $rows, array $row, array $reserve, ?string $entry, WP_Error $answer ): WP_Error {
		$gone = static fn( ?array $stored ): bool => null !== $stored && ! array_filter( $stored['refunds'] ?? array(), static fn( $kept ) => (int) ( $kept['id'] ?? 0 ) === $reserve['id'] ) && ( null === $entry || ! isset( $stored['commands'][ $entry ] ) );
		if ( ! $this->persist( $order, $rows, $row, false, $gone ) ) {
			$this->mark( $order, $row, $reserve, 'it was refused (' . $answer->get_error_code() . '), but its reservation could not be withdrawn; the allocation stays pending until it is confirmed with the provider', false );
		}
		return $answer;
	}

	/**
	 * Mark a refund outcome storage does not hold as it should, for a human: an error log line and an order
	 * note (a comment row, written by its own query, so it survives an order save that did not land).
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $row     Payment row.
	 * @param array    $reserve The reservation.
	 * @param string   $cause   What happened.
	 * @param bool     $sent    Whether the provider was (or may have been) asked; false for a refusal whose withdrawal did not land.
	 */
	private function mark( WC_Order $order, array $row, array $reserve, string $cause, bool $sent = true ): void {
		$this->report( $order, sprintf( 'WCPOS refund #%d of %s on order #%d, payment %s: %s.', $reserve['id'], $reserve['amount'], $order->get_id(), $row['id'], $cause ) );
		if ( $sent ) {
			/* translators: 1: refund ID, 2: amount with currency, 3: payment method ID */
			$note = __( 'WCPOS: refund #%1$d for %2$s was sent to %3$s; confirm it with the provider before retrying.', 'woocommerce-pos' );
		} else {
			/* translators: 1: refund ID, 2: amount with currency, 3: payment method ID */
			$note = __( 'WCPOS: refund #%1$d for %2$s did not go through %3$s but its reservation could not be removed; check the payment before retrying.', 'woocommerce-pos' );
		}
		try {
			$order->add_order_note( sprintf( $note, $reserve['id'], $reserve['amount'] . ' ' . $order->get_currency(), $row['method_id'] ) );
		} catch ( \Throwable $error ) {
			// Note hooks run inside add_order_note(); their throw must not escape refund() (rule 8).
			$this->report( $order, sprintf( 'WCPOS refund #%d on order #%d: its order note failed: %s', $reserve['id'], $order->get_id(), $error->getMessage() ) );
		}
	}

	/**
	 * Log on the refund path, never throwing: a logger that throws is swallowed.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $message Message.
	 * @param bool     $error   Error level, else warning.
	 */
	private function report( WC_Order $order, string $message, bool $error = true ): void {
		try {
			if ( $error ) {
				Logger::error( $message, array( 'order_id' => $order->get_id() ) );
			} else {
				Logger::warning( $message, array( 'order_id' => $order->get_id() ) );
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored ); // Nothing left to tell; the refund's answer stands.
		}
	}

	/**
	 * Append a leg the Ledger did not open: money a WooCommerce gateway already took
	 * (payment_complete(), the order-pay page), or a provider attempt started outside
	 * the POS. It does no balance check and no handler call. The caller decides that
	 * the payment is not already represented, with an optional locked identity recheck.
	 * One $order->save(), so order meta the caller staged rides the same write.
	 *
	 * @param WC_Order      $order    Order object.
	 * @param array         $row      Payment row.
	 * @param bool          $derive   Whether to project payment state.
	 * @param callable|null $identity Whether a stored row already represents this payment.
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
			if ( ! wc_get_order( $order->get_id() ) instanceof WC_Order ) {
				throw new Ledger_Persistence_Exception( 'WCPOS payment order no longer exists.' );
			}
			// Check identity against storage under the lock: a concurrent callback may have minted it.
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
			$row = $this->replace_and_save( $order, $rows, $this->normalize_row( $order, $row ), false );
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
				// Same id, another body: one code across the command family (spec §7 decision 12).
				return new WP_Error(
					'wcpos_command_conflict',
					__( 'Payment id conflicts with an existing payment.', 'woocommerce-pos' ),
					array(
						'status' => 422,
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
				if ( ! in_array( $live['status'], array( 'pending', 'authorized' ), true ) ) {
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
	 * What may happen to the sale now, and what money is in motion on it: one read of the order,
	 * valid until it is next saved. The push lane and the money-command routes translate it into
	 * their own refusals (ADR 0040 rules 3 and 4).
	 *
	 * @param WC_Order $order Order object, read under the caller's order lock.
	 */
	public function sale_state( WC_Order $order ): Sale_State {
		return new Sale_State( $order, $this->read( $order ) );
	}

	/**
	 * A closed sale takes no new tender (spec §4.2): a new row is accepted only while the sale is open
	 * (editable, in progress, or awaiting a sent submit). A completed sale whose signed balance went
	 * positive through a refund is reopened first, so the money lands on an open sale and completes it
	 * again. Replays and the commands on an existing row never ask.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function accepts_tender( WC_Order $order ): ?WP_Error {
		$state = $this->sale_state( $order );
		if ( $state->takes_tender() ) {
			return null;
		}
		if ( $state->already_paid() ) {
			return new WP_Error(
				'wcpos_order_already_paid',
				__( 'The order is already paid.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'order'  => $this->summary( $order ),
				)
			);
		}
		return $this->invalid_transition();
	}

	/**
	 * Close a sale (ADR 0040 rule 1, D2): move no money, re-derive, and complete it when the signed
	 * balance covers the total, or the total is zero — the way a reopened sale is completed again
	 * (completion n+1). A sale already paid replays its last completion. Refused while money is in
	 * motion, with `wcpos_balance_outstanding` when uncovered or the total is negative, and
	 * `wcpos_insufficient_stock` when prevent-overselling cannot hold the sale's stock.
	 *
	 * @param WC_Order $order   Order object, read under the route's order lock.
	 * @param array    $command The till's command; its entry names the completion it answered.
	 * @return array|WP_Error `{ order: summary, completion: { n, id } }`.
	 */
	public function close( WC_Order $order, array $command = array() ) {
		$rows  = $this->read( $order );
		$state = new Sale_State( $order, $rows );
		// A standing completion is replayed whatever its status (a merchant's POS status may be on-hold).
		// A sale paid before the history existed stands too: it replays with no completion to name.
		if ( $state->stands() ) {
			$latest = Completion_History::of( $order )->standing() ?? false;
			$this->remember_close( $order, $command, $latest );
			return $this->closed( $order, $rows, $latest );
		}
		if ( ! $state->open() ) {
			return $this->invalid_transition(); // Never completed, or its completion was reopened (a cancel).
		}
		$motion = $state->in_motion();
		if ( null !== $motion ) {
			return $this->in_flight( $order, null !== $motion['payment_id'] ? array( 'payment_id' => $motion['payment_id'] ) : array( 'attempt_id' => $motion['attempt_id'] ) );
		}
		if ( Money::minor( $order->get_total() ) < 0 || $this->signed_balance( $order, $rows ) > 0 ) {
			return new WP_Error(
				'wcpos_balance_outstanding',
				__( 'The order is not covered by its payments.', 'woocommerce-pos' ),
				array(
					'status' => 409,
					'order' => $this->summary( $order, $rows ),
				)
			);
		}
		// Prevent-overselling holds the sale's stock before it completes, as record and intent do (§4.8);
		// a paid or stock-reduced order (a reopened sale) is skipped by validate_checkout().
		$held = Stock_Validator::instance()->validate_checkout( $order );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		if ( ! empty( $command['id'] ) ) {
			// Staged before the completion so the entry rides WooCommerce's own save; its ref is filled below.
			Command_Log::remember( $order, $command['id'], Command_Log::entry( $command['fp'], 'close' ) );
		}
		$this->derive( $order, $rows, true );
		self::saving( $order->get_id(), fn() => $order->save() );
		$fresh = wc_get_order( $order->get_id() );
		$fresh->read_meta_data( true );
		// Only a completion that stands is reported: when the new entry could not be recorded, the
		// latest is the reopened one (the failure note names the gap), so none is named.
		$latest = Completion_History::of( $fresh )->standing() ?? false;
		// The completion id exists only now, so the entry takes a save of its own (§5.2).
		$this->remember_close( $fresh, $command, $latest );
		return $this->closed( $fresh, $this->read( $fresh ), $latest );
	}

	/**
	 * Keep a close's command entry, naming the completion it answered (null when none stands).
	 *
	 * @param WC_Order    $order   Order object.
	 * @param array       $command The till's command.
	 * @param array|false $entry   The completion it answered.
	 * @throws Ledger_Persistence_Exception When the entry did not reach storage: never acknowledged, the retry replays.
	 */
	private function remember_close( WC_Order $order, array $command, $entry ): void {
		if ( empty( $command['id'] ) ) {
			return;
		}
		$ref = is_array( $entry ) ? (string) $entry['id'] : null;
		Command_Log::remember( $order, $command['id'], Command_Log::entry( $command['fp'], 'close', $ref ) );
		self::saving( $order->get_id(), fn() => $order->save() );
		$stored = wc_get_order( $order->get_id() );
		$stored->read_meta_data( true );
		$kept = Command_Log::find( $stored, array(), $command['id'] );
		if ( ! $kept || $kept['fp'] !== $command['fp'] || $kept['ref'] !== $ref ) {
			throw new Ledger_Persistence_Exception( 'WCPOS close command was not persisted.' );
		}
	}

	/**
	 * The row with the command's entry, when there is a command and the row does not hold it yet.
	 *
	 * @param array $row     Payment row.
	 * @param array $command The till's command (`id`, `fp`, `kind`); empty for callers without one.
	 * @param mixed $ref     The refund id a refund allocated.
	 */
	private function with_command( array $row, array $command, $ref = null ): array {
		return empty( $command['id'] ) ? $row : Command_Log::with_on_row( $row, $command['id'], Command_Log::entry( $command['fp'], $command['kind'], $ref ) );
	}

	/**
	 * The close answer: the order summary and the completion it stands on.
	 *
	 * @param WC_Order    $order Order object.
	 * @param array       $rows  Payment rows.
	 * @param array|false $entry Latest completion entry, if any.
	 */
	private function closed( WC_Order $order, array $rows, $entry ): array {
		return array(
			'order'      => $this->summary( $order, $rows ),
			'completion' => is_array( $entry ) ? array(
				'n'  => (int) $entry['n'],
				'id' => (string) $entry['id'],
			) : null,
		);
	}

	/**
	 * Money in motion refuses a close.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $named The payment or attempt in motion.
	 */
	private function in_flight( WC_Order $order, array $named ): WP_Error {
		return new WP_Error( 'wcpos_payment_in_flight', __( 'Another payment is already in progress on this order.', 'woocommerce-pos' ), array_merge( array( 'status' => 409 ), $named, array( 'order' => $this->summary( $order ) ) ) );
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
	 * @param array    $command The till's command; empty (Pro's callers) stores no entry.
	 *
	 * @return array|\WP_Error
	 */
	public function void( WC_Order $order, string $id, string $reason, array $command = array() ) {
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
		$applied = $this->apply_result( $order, $row['id'], $new, true, $command );
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
			self::saving( $order->get_id(), fn() => $order->save() );
			$this->release_hold_if_idle( $order );
			Logger::log( sprintf( 'WCPOS payment %s voided on order #%d: %s %s%s', $row['id'], $order->get_id(), $row['amount'], $row['currency'], '' === $reason ? '' : ': ' . $reason ) );
			do_action( 'woocommerce_pos_payment_voided', $order, $row, $applied, $reason );
		}
		return $applied;
	}

	/**
	 * Nothing is paying for the sale any more (no pending, authorized or captured row) and its stock
	 * was never reduced: the prevent-overselling hold goes back to the shelf.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function release_hold_if_idle( WC_Order $order ): void {
		if ( array_filter( $this->read( $order ), static fn( $live ) => in_array( $live['status'] ?? '', self::LIVE_STATUSES, true ) ) ) {
			return;
		}
		if ( ! $order->get_data_store()->get_stock_reduced( $order->get_id() ) ) {
			Stock_Validator::instance()->release_checkout_stock( $order );
		}
	}

	/**
	 * Whether a row may be voided: pending or authorized always; captured only when it is a
	 * manual row on an order still in progress (cancelling a split mid-way) that no completion
	 * has counted. Once a completion counted it, a reopen does not make it voidable again: the
	 * money leaves only through a linked refund (ADR 0040 rule 3).
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
			&& in_array( $order->get_status(), self::IN_PROGRESS_STATUSES, true )
			&& ! Completion_History::of( $order )->counted( (string) ( $row['id'] ?? '' ) );
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
		$keeper_count = $this->closure_locks[ $order->get_id() ] ?? 0;
		if ( ! $lock->acquire( $order->get_id() ) ) {
			throw new Ledger_Persistence_Exception( 'WCPOS payment ledger is locked.' );
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			$fresh = $fresh instanceof WC_Order ? $fresh : $order;
			$fresh->read_meta_data( true );
			// updated_at_gmt is when a leg last changed. normalize_row() stamps now, so a row whose
			// content is what is already stored keeps its stored stamp: a write to one leg must not
			// make every other leg on the order look fresh to Payments_Sweeper.
			$stored = array();
			foreach ( $this->stored( $order->get_id(), false )['payments'] ?? array() as $existing ) {
				if ( is_array( $existing ) && isset( $existing['id'] ) ) {
					$stored[ strtolower( (string) $existing['id'] ) ] = $existing;
				}
			}
			// Refresh meta ids too: an object loaded before the first row otherwise inserts
			// a second ledger meta entry on HPOS. Keep meta deliberately staged by adopt's caller.
			$property = new \ReflectionProperty( \WC_Data::class, 'meta_data' );
			$property->setAccessible( true );
			$pending = array_filter( $property->getValue( $order ) ?? array(), static fn( $meta ) => ! $meta->id || $meta->get_changes() || null === $meta->value );
			// Core totals/status must also come from the locked read, not the waiting caller.
			$caller = $order;
			$order = $fresh;
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
						foreach ( array( 'refunded_amount', 'refunds', 'events', 'seen_events', 'commands' ) as $key ) {
							if ( $row[ $key ] === $base[ $key ] ) {
								$row[ $key ] = $latest[ $key ];
							}
						}
						if ( ( $read_row['updated_at_gmt'] ?? null ) === $requested_stamp && $this->valid_time( $previous['updated_at_gmt'] ?? null ) ) {
							$row['updated_at_gmt'] = $previous['updated_at_gmt'];
						}
					}
				}
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
			$this->stage( $order, $normalized );
			$order_id = $order->get_id();
			$this->server_saves[ $order_id ] = ( $this->server_saves[ $order_id ] ?? 0 ) + 1;
			try {
				if ( $derive ) {
					$this->derive( $order, $normalized );
				}
				// The before-save keeper rebuilds this index again from the final ledger; that is intentional.
				$order->save();
			} finally {
				if ( 0 === --$this->server_saves[ $order_id ] ) {
					unset( $this->server_saves[ $order_id ] );
				}
			}
			// record()/adopt() return through the supplied object; refresh core and meta together.
			$caller->get_data_store()->read( $caller );
			$caller->read_meta_data( true );
			// The keeper may legitimately restore closure stamps during save.
			if ( ( $this->stored( $order->get_id(), false )['payments'] ?? null ) !== $this->read( $order ) ) {
				throw new Ledger_Persistence_Exception( 'WCPOS payment ledger was not persisted.' );
			}
		} finally {
			// A failed WC save can skip after-save, including nested payment_complete() saves.
			// Release only keeper acquisitions made here; an enclosing save still owns its lock.
			while ( ( $this->closure_locks[ $order->get_id() ] ?? 0 ) > $keeper_count ) {
				$this->release_closure_lock( $order );
			}
			$lock->release( $order->get_id() );
		}
	}

	/**
	 * The one write of payment rows: lock, fresh read, heal, the change, the rows (S1), the paid transition
	 * (S2), the completion (S3), deferred work, unlock. S2 and S3 are functions of storage, so a retry of the same command
	 * heals a crash between them (ADR 0040, amended). A change breaking a row's invariants (I5) is a LogicException.
	 *
	 * @param WC_Order|int  $order  Order or order id; the write reads its own.
	 * @param callable|null $change fn( Ledger_Draft $draft ): mixed. Pure: no provider call, no save, no hook; it may
	 *                              run twice. Null is a replay or a sweep: it may complete a sale its rows cover.
	 * @param string        $mode   MODE_AUTO, MODE_HOLD (stage only) or MODE_CLOSE (may complete zero or on-hold).
	 * @throws Ledger_Persistence_Exception Retryable; `stage` names the step that failed.
	 */
	public function write( $order, ?callable $change = null, string $mode = self::MODE_AUTO ): Ledger_Written {
		$id   = $order instanceof WC_Order ? $order->get_id() : (int) $order;
		$hook = self::order_hook();
		if ( null !== $hook ) {
			throw Ledger_Persistence_Exception::at( 'lock', esc_html( sprintf( 'WCPOS payment ledger write refused inside %s; defer it.', $hook ) ) );
		}
		$lock = Order_Lock::instance();
		if ( ! $lock->acquire( $id ) ) {
			throw Ledger_Persistence_Exception::at( 'lock', 'WCPOS payment ledger is locked.' );
		}
		$depth = ( self::$writing[ $id ] ?? 0 ) + 1;
		try {
			self::$writing[ $id ] = $depth;
			try {
				$written = $this->locked_write( $id, $change, $mode );
			} finally {
				self::$writing[ $id ] = $depth - 1;
				if ( 1 === $depth ) {
					unset( self::$writing[ $id ] );
				}
			}
			// The outermost write is done, so its deferred work runs as any other caller would, still under the lock.
			if ( 1 === $depth && self::flush( $id ) ) {
				$fresh   = $this->fresh( $id );
				$written = new Ledger_Written( $fresh, $this->read( $fresh ), $written->answer, Completion_History::of( $fresh )->standing() );
			}
			if ( null !== $change ) {
				$this->release_hold_if_idle( $written->order );
			}
			return $written;
		} finally {
			$lock->release( $id );
		}
	}

	/**
	 * Whether a write on this order is running in this request.
	 *
	 * @param int $order_id Order id.
	 */
	public static function is_writing( int $order_id ): bool {
		return isset( self::$writing[ $order_id ] );
	}

	/**
	 * Run work for an order outside every hook: after the outermost write on it, at order-pay's
	 * successful result, or at shutdown. None of the three runs inside a save, transition or payment
	 * hook. For listeners that must write the ledger (I3). A failure is logged and noted on the order
	 * under its label; the flush never throws.
	 *
	 * @param int      $order_id Order id.
	 * @param callable $work     The work.
	 * @param string   $label    What it is for, as a merchant reads it in the note.
	 */
	public static function defer( int $order_id, callable $work, string $label ): void {
		self::$deferred[ $order_id ][] = array( $work, $label );
		if ( ! has_action( 'shutdown', array( __CLASS__, 'flush_all' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_all' ), 10, 0 );
			// Priority 0: while the order-pay window is still open (the passthrough closes it at 1), so the
			// completion a deferred mint stamps reads the till as its origin, as it did inside the hook.
			add_filter( 'woocommerce_payment_successful_result', array( __CLASS__, 'flush_result' ), 0, 2 );
		}
	}

	/** Run every order's deferred work, including work a job defers for another order (shutdown). */
	public static function flush_all(): void {
		while ( self::$deferred ) {
			self::flush( (int) array_key_first( self::$deferred ) );
		}
	}

	/**
	 * Run an order's deferred work when order-pay hands back its result.
	 *
	 * @param mixed $result   Gateway result.
	 * @param mixed $order_id Order id.
	 * @return mixed The result, unchanged.
	 */
	public static function flush_result( $result, $order_id ) {
		self::flush( (int) $order_id );
		return $result;
	}

	/**
	 * Save only an order's meta (H2). On HPOS a meta change runs a full order save, and every save
	 * and transition listener with it, whenever date_modified is older than now and no prop changed.
	 *
	 * @param WC_Order $order Order whose staged meta to save.
	 */
	public static function save_meta( WC_Order $order ): void {
		if ( 0 === self::$meta_only++ ) {
			add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', array( __CLASS__, 'meta_only' ), PHP_INT_MAX );
		}
		try {
			$order->save_meta_data();
		} finally {
			if ( 0 === --self::$meta_only ) {
				remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', array( __CLASS__, 'meta_only' ), PHP_INT_MAX );
			}
		}
	}

	/** The filter answer save_meta() owns: no full save after a meta change. */
	public static function meta_only(): bool {
		return false;
	}

	/**
	 * Steps 2 to 7 of a write, under the lock: S1 is retried once from a fresh read on a read-back miss.
	 *
	 * @param int           $id     Order id.
	 * @param callable|null $change The change.
	 * @param string        $mode   Write mode.
	 * @throws Ledger_Persistence_Exception When a step does not reach storage.
	 */
	private function locked_write( int $id, ?callable $change, string $mode ): Ledger_Written {
		$before = null;
		for ( $try = 0; ; ++$try ) {
			$order  = $this->fresh( $id );
			$ledger = $this->stored( $id, true );
			$stored = $ledger['payments'] ?? array();
			if ( 0 === $try && $this->heal( $order, null !== $ledger ) ) {
				$order = $this->fresh( $id );
			}
			// Covered before this write: from the first read, never from this write's own half-stored S1.
			$before = $before ?? $this->project( $order, $stored, $mode );
			$draft  = new Ledger_Draft( $order, $stored );
			$answer = null === $change ? null : $change( $draft );
			$rows   = $this->settle( $order, $stored, $draft->rows() );
			if ( $this->store_rows( $order, $stored, $rows, $draft, $this->project( $order, $rows, $mode ), null !== $change ) ) {
				break;
			}
			if ( 0 < $try ) {
				throw Ledger_Persistence_Exception::at( 'rows', 'WCPOS payment ledger was not persisted.' );
			}
		}
		// S2 is decided from storage: the rows and the order as S1 stored them (I4).
		$order = $this->fresh( $id );
		$after = $this->project( $order, $this->read( $order ), $mode );
		// A sale its rows already covered completes only on a replay, a sweep or a close (rule 3: a
		// reopened sale its rows still cover is covered and open, and stays open under other writes).
		if ( $after['completes'] && ( ! $before['completes'] || null === $change || self::MODE_CLOSE === $mode ) ) {
			$order = $this->complete( $order, $draft->commands() );
		}
		return new Ledger_Written( $order, $this->read( $order ), $answer, Completion_History::of( $order )->standing() );
	}

	/**
	 * The rows to store, normalized, an untouched row keeping its stamp, every stored row's invariants held
	 * (I5): a captured row keeps id, amount, currency and method and moves only to voided, only when manual;
	 * a row carrying refunds or a closure, or not pending, never vanishes; a closure id is write-once.
	 *
	 * @param WC_Order $order  Fresh order.
	 * @param array    $stored Stored rows.
	 * @param array    $rows   The draft's rows.
	 * @throws \LogicException When the draft breaks an invariant: a programming error, never a merchant outcome.
	 */
	private function settle( WC_Order $order, array $stored, array $rows ): array {
		$settled = array();
		foreach ( $rows as $row ) {
			$row                   = $this->normalize_row( $order, $row );
			$settled[ $row['id'] ] = $row;
		}
		foreach ( $stored as $old ) {
			$new  = $settled[ strtolower( (string) $old['id'] ) ] ?? null;
			$kept = null === $new
				? 'pending' === ( $old['status'] ?? '' ) && empty( $old['refunds'] ) && empty( $old['closure_id'] )
				: ( empty( $old['closure_id'] ) || ( $new['closure_id'] ?? null ) === $old['closure_id'] )
					&& ( 'captured' !== ( $old['status'] ?? '' ) || (
						Money::minor( $old['amount'] ) === Money::minor( $new['amount'] ) && (string) $old['currency'] === $new['currency'] && (string) $old['method_id'] === $new['method_id']
						&& ( 'captured' === $new['status'] || ( 'voided' === $new['status'] && 'manual' === ( $old['capture_mode'] ?? '' ) ) )
					) );
			if ( ! $kept ) {
				throw new \LogicException( esc_html( sprintf( 'Payment row %s breaks the ledger invariants.', $old['id'] ) ) );
			}
			if ( $new && $this->valid_time( $old['updated_at_gmt'] ?? null ) && $this->same_content( $this->normalize_row( $order, $old ), $new ) ) {
				$settled[ $new['id'] ]['updated_at_gmt'] = $old['updated_at_gmt'];
			}
		}
		return array_values( $settled );
	}

	/**
	 * S1: one save carrying the draft and the projection that does not complete; true once storage
	 * holds the rows and every staged server-owned key. A replay that changes nothing saves nothing.
	 *
	 * @param WC_Order     $order      Fresh order, with whatever the change staged on it.
	 * @param array        $stored     Stored rows.
	 * @param array        $rows       Settled rows.
	 * @param Ledger_Draft $draft      The draft.
	 * @param array        $projection Projection of the settled rows.
	 * @param bool         $changed    Whether a change ran.
	 */
	private function store_rows( WC_Order $order, array $stored, array $rows, Ledger_Draft $draft, array $projection, bool $changed ): bool {
		$id     = $order->get_id();
		$wire   = json_decode( (string) wp_json_encode( array_map( array( __CLASS__, 'to_wire' ), $rows ) ), true );
		$staged = $draft->attempts() || $draft->commands() || $wire !== $stored;
		// The awaiting-customer stamp stays until S3: storage keeps saying POS-owned until the sale completes.
		if ( null !== $projection['method'] ) {
			$order->set_payment_method( $projection['method'] );
			$order->set_payment_method_title( (string) $projection['title'] );
			$order->set_transaction_id( (string) $projection['txn'] );
		}
		if ( null !== $projection['status'] ) {
			$order->set_status( $projection['status'] );
		}
		// A completing write always stores its ledger (empty rows too): heal's marker lands before the paid transition.
		if ( ! $changed && ! $staged && ! $projection['completes'] && ! $order->get_changes() ) {
			return true;
		}
		$props = $order->get_changes();
		if ( $draft->attempts() ) {
			$history = Gateway_Submission::read_attempts( $order );
			foreach ( $draft->attempts() as $attempt_id => $fate ) {
				unset( $history[ $attempt_id ] );
				$history[ $attempt_id ] = $fate;
			}
			$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, array_slice( $history, - Gateway_Submission::ATTEMPTS_CAP, null, true ) );
		}
		foreach ( $draft->commands() as $command_id => $entry ) {
			Command_Log::remember( $order, $command_id, $entry );
		}
		$this->stage( $order, $rows );
		// What storage must hold afterwards, as sets (HPOS reuses meta ids): taken now, as the save re-reads the meta.
		$values = static function ( WC_Order $read, string $key ): array {
			$list = array_map( static fn( $meta ) => $meta->value, $read->get_meta( $key, false ) );
			sort( $list );
			return $list;
		};
		$keys   = array( self::INDEX_META_KEY, self::PAYMENT_ID_META_KEY, self::LIVE_LEG_META_KEY, Gateway_Submission::ATTEMPTS_META_KEY, Command_Log::META_KEY );
		$want   = array_map( static fn( $key ) => $values( $order, $key ), $keys );
		$this->server_saves[ $id ] = ( $this->server_saves[ $id ] ?? 0 ) + 1;
		try {
			self::saving( $id, fn() => $order->save() );
		} finally {
			if ( 0 === --$this->server_saves[ $id ] ) {
				unset( $this->server_saves[ $id ] );
			}
		}
		// The rows, their three indexes, the attempt fates their coverage settled, and every order property staged.
		$check = $this->fresh( $id );
		$kept  = $this->stored( $id, true );
		return array_map( static fn( $key ) => $values( $check, $key ), $keys ) === $want && null !== $kept && $kept['payments'] === $wire && self::holds( $check->get_data(), $props );
	}

	/**
	 * Whether stored data holds every staged change: a date by its time, a map key by key.
	 *
	 * @param mixed $stored Stored value.
	 * @param mixed $staged Staged value.
	 */
	private static function holds( $stored, $staged ): bool {
		if ( is_array( $staged ) ) {
			foreach ( $staged as $key => $value ) {
				if ( ! self::holds( is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null, $value ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $staged instanceof \DateTimeInterface ) {
			return $stored instanceof \DateTimeInterface && $stored->getTimestamp() === $staged->getTimestamp();
		}
		return (string) $staged === (string) $stored;
	}

	/**
	 * S2, the paid transition carrying no ledger meta (I1, I2), then S3 and the completion a close command answered.
	 *
	 * @param WC_Order $order    Order as S1 stored it.
	 * @param array    $commands The order-level command entries this write staged.
	 * @throws Ledger_Persistence_Exception When the paid status or the completion does not reach storage.
	 */
	private function complete( WC_Order $order, array $commands ): WC_Order {
		$id       = $order->get_id();
		$previous = self::$deriving;
		// The completion is the ledger's, so it has a register origin (Completion_Stamp::has_origin()).
		self::$deriving = $id;
		try {
			$order->payment_complete();
		} finally {
			self::$deriving = $previous;
		}
		$order = $this->fresh( $id );
		if ( ! self::paid_now( $order ) ) {
			throw Ledger_Persistence_Exception::at( 'paid', 'WCPOS paid transition was not persisted.' );
		}
		$order    = $this->finish( $order );
		$standing = Completion_History::of( $order )->standing();
		$closes   = array_filter( $commands, static fn( $entry ) => 'close' === ( $entry['kind'] ?? '' ) );
		if ( $closes ) {
			foreach ( $closes as $command_id => $entry ) {
				Command_Log::remember( $order, $command_id, array_merge( $entry, array( 'ref' => (string) $standing['id'] ) ) );
			}
			self::save_meta( $order );
			if ( $this->fresh( $id )->get_meta( Command_Log::META_KEY ) !== $order->get_meta( Command_Log::META_KEY ) ) {
				throw Ledger_Persistence_Exception::at( 'completion', 'WCPOS close command was not persisted.' );
			}
		}
		return $order;
	}

	/**
	 * S3-heal: a paid POS sale the ledger wrote (a zero-total close has no rows) whose completion never
	 * landed: a crash after or inside S2, whose hooks may never have run. It repairs in storage order: a paid
	 * status without its paid date gets the date, then stock (idempotent), then S3.
	 *
	 * @param WC_Order $order    Fresh order.
	 * @param bool     $ledgered Whether the ledger has stored this order (WCPOS 1.x never did).
	 * @return bool Whether it healed.
	 * @throws Ledger_Persistence_Exception When the paid date does not reach storage.
	 */
	private function heal( WC_Order $order, bool $ledgered ): bool {
		$paid = self::paid_now( $order ) || in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
		if ( ! $ledgered || ! wcpos_is_pos_order( $order ) || ! $paid || null !== Completion_History::of( $order )->standing() ) {
			return false;
		}
		if ( null === $order->get_date_paid( 'edit' ) ) {
			$order->set_date_paid( time() );
			self::saving( $order->get_id(), fn() => $order->save() );
			$order = $this->fresh( $order->get_id() );
			if ( ! self::paid_now( $order ) ) {
				throw Ledger_Persistence_Exception::at( 'paid', 'WCPOS paid date was not persisted.' );
			}
		}
		wc_maybe_reduce_stock_levels( $order->get_id() );
		$this->finish( $order );
		return true;
	}

	/**
	 * S3: the completion (idempotent: a listener inside S2 may have stamped it), then the awaiting-customer
	 * stamp cleared beside it, each read back.
	 *
	 * @param WC_Order $order Paid order.
	 * @throws Ledger_Persistence_Exception When either does not reach storage.
	 */
	private function finish( WC_Order $order ): WC_Order {
		$id       = $order->get_id();
		$previous = self::$deriving;
		self::$deriving = $id;
		try {
			Completion_Stamp::memoise( $order ); // The completion names the register a sent submit came from.
			Completion_Stamp::stamp( $order );
		} finally {
			self::$deriving = $previous;
		}
		$order = $this->fresh( $id );
		if ( null !== Completion_History::of( $order )->standing() && null !== Gateway_Submission::read_stamp( $order ) ) {
			Gateway_Submission::clear_stamp( $order );
			self::save_meta( $order );
			$order = $this->fresh( $id );
		}
		if ( null === Completion_History::of( $order )->standing() || null !== Gateway_Submission::read_stamp( $order ) ) {
			throw Ledger_Persistence_Exception::at( 'completion', 'WCPOS completion was not persisted.' );
		}
		return $order;
	}

	/**
	 * Paid as the completion stamp reads it: a completed status and a paid date.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function paid_now( WC_Order $order ): bool {
		return null !== $order->get_date_paid( 'edit' ) && in_array( $order->get_status(), Completion_Stamp::completed_statuses( $order ), true );
	}

	/**
	 * Project rows onto the order as it stands.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $rows  Rows.
	 * @param string   $mode  Write mode.
	 */
	private function project( WC_Order $order, array $rows, string $mode ): array {
		$title = static function ( $method_id ) {
			$descriptor = Descriptor_Builder::instance()->get( (string) $method_id );
			return $descriptor ? $descriptor['title'] : $method_id;
		};
		return Projection::of( $rows, (string) $order->get_total(), $order->get_status(), null !== Gateway_Submission::read_stamp( $order ), $mode, $order->is_editable(), $title );
	}

	/**
	 * The order and its meta as storage holds them.
	 *
	 * @param int $id Order id.
	 * @throws Ledger_Persistence_Exception When the order cannot be read.
	 */
	private function fresh( int $id ): WC_Order {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			throw Ledger_Persistence_Exception::at( 'rows', 'WCPOS order could not be read.' );
		}
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * The innermost order save, transition or payment hook really running (I3). A listener that threw
	 * out of a hook WooCommerce caught leaves the hook on `$wp_current_filter` (do_action() pops only
	 * on return), so a listed name there is confirmed against the dispatches on the call stack.
	 */
	private static function order_hook(): ?string {
		if ( ! array_filter( (array) ( $GLOBALS['wp_current_filter'] ?? array() ), array( Save_Hooks::class, 'contains' ) ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only while an order hook name is on the stack: which dispatches are live.
		foreach ( debug_backtrace( 0 ) as $frame ) {
			$name = $frame['args'][0] ?? null;
			if ( ! isset( $frame['class'] ) && in_array( $frame['function'], array( 'do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array' ), true ) && Save_Hooks::contains( $name ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Run an order's deferred work, including work deferred while it runs.
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether any ran.
	 */
	private static function flush( int $order_id ): bool {
		$ran = false;
		while ( ! empty( self::$deferred[ $order_id ] ) ) {
			list( $work, $label ) = array_shift( self::$deferred[ $order_id ] );
			$ran                  = true;
			try {
				$work();
			} catch ( \Throwable $error ) {
				Logger::error( sprintf( 'WCPOS deferred "%s" failed on order #%d: %s', $label, $order_id, $error->getMessage() ), array( 'order_id' => $order_id ) );
				try {
					$order = wc_get_order( $order_id );
					if ( $order instanceof WC_Order ) {
						/* translators: %s: what the deferred work was for. */
						$order->add_order_note( sprintf( __( 'WCPOS: %s did not complete; reconcile manually.', 'woocommerce-pos' ), $label ) );
					}
				} catch ( \Throwable $note_error ) {
					Logger::error( sprintf( 'WCPOS could not note the failed "%s" on order #%d: %s', $label, $order_id, $note_error->getMessage() ) );
				}
			}
		}
		unset( self::$deferred[ $order_id ] );
		return $ran;
	}

	/**
	 * Stage the rows, their indexes and the attempt fates their coverage settles on the order.
	 *
	 * @param WC_Order $order      Order object, saved by the caller.
	 * @param array    $normalized Normalized rows.
	 */
	private function stage( WC_Order $order, array $normalized ): void {
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
		// Money that covers the order: the send whose row paid it is paid, the sends it replaced are superseded.
		$paid = Money::minor( $this->paid( $normalized ) );
		if ( $paid > 0 && $paid >= Money::minor( $order->get_total() ) ) {
			$history  = Gateway_Submission::read_attempts( $order );
			$counting = array_column( array_filter( $normalized, static fn( $row ) => in_array( $row['status'], self::COUNTING_STATUSES, true ) ), 'id' );
			$paying   = array_intersect_key( array_filter( $history, static fn( $state ) => 'sent' === $state ), array_flip( array_map( 'strtolower', $counting ) ) );
			if ( $paying ) {
				foreach ( $history as $attempt_id => $state ) {
					if ( 'sent' === $state ) {
						$history[ $attempt_id ] = isset( $paying[ $attempt_id ] ) ? 'paid' : 'superseded';
					}
				}
				$order->update_meta_data( Gateway_Submission::ATTEMPTS_META_KEY, $history );
			}
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
	 * @param WC_Order $order   Order object.
	 * @param array    $rows    Payment rows.
	 * @param bool     $closing Whether `close` asked: only it may complete an editable order outside the projected set (on-hold).
	 */
	public function derive( WC_Order $order, array $rows, bool $closing = false ): void {
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
			// The completion this may cause still names the register a sent submit came from.
			Completion_Stamp::memoise( $order );
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
		if ( ! in_array( $order->get_status(), self::IN_PROGRESS_STATUSES, true ) && ! $stamped && ! ( $closing && $order->is_editable() ) ) {
			return;
		}
		$paid  = Money::minor( $this->paid( $rows ) );
		$total = Money::minor( $order->get_total() );
		// Covered on the signed balance: a refund allocated after an earlier completion is money
		// that left, so gross captured money alone does not complete a reopened sale again.
		// A close completes a zero total through payment_complete(), the way WooCommerce's
		// process_order_without_payment() does; a money write never does.
		if ( ( $total > 0 || $closing ) && $this->signed_balance( $order, $rows ) <= 0 ) {
			if ( 0 === self::$deriving ) {
				self::$deriving = $order->get_id();
				try {
					// Woo sets date_paid, reduces stock and fires woocommerce_payment_complete;
					// Orders::payment_complete_order_status() lands the per-gateway status
					// because payment_method was set above.
					$order->payment_complete();
				} finally {
					self::$deriving = 0;
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
	 * Minor units reserved by pending and authorized legs: money a provider may still confirm or capture.
	 *
	 * @param array $rows               Payment rows.
	 * @param bool  $include_cancelling Whether legs whose cancel is already requested still reserve.
	 */
	private function pending_reserved( array $rows, bool $include_cancelling = true ): int {
		$reserved = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', array( 'pending', 'authorized' ), true ) && ( $include_cancelling || empty( $row['void_requested_at'] ) ) ) {
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
			// The commands answered on this row (ADR 0040 rule 5); never evicted, kept off the route wire.
			'commands' => array(),
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
		$row['commands']         = is_array( $row['commands'] ) ? array_filter( $row['commands'], 'is_array' ) : array();
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
	 * @return array The row as stored. save() normalizes again under the lock, so the caller's copy
	 *               may carry another updated_at_gmt: an answer built from it is not what a replay reads (D14).
	 */
	private function replace_and_save( WC_Order $order, array $rows, array $replacement, bool $derive = true ): array {
		foreach ( $rows as &$row ) {
			if ( $row['id'] === $replacement['id'] ) {
				$row = $replacement;
				break;
			}
		}
		unset( $row );
		$this->save( $order, $rows, $derive );
		return $this->find( $order, $replacement['id'] ) ?? $replacement;
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
