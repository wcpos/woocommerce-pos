<?php
/** WCPOS order payment ledger. */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;

/** Persists payment legs and derives WooCommerce order payment state. */
class Ledger {
	public const META_KEY = '_wcpos_payments';
	public const INDEX_META_KEY = '_wcpos_payment_method';
	public const SCHEMA = 1;
	public const LIVE_STATUSES = array( 'pending', 'authorized', 'captured' );
	public const COUNTING_STATUSES = array( 'authorized', 'captured' );
	public const STATUSES = array( 'pending', 'authorized', 'captured', 'failed', 'voided' );
	public const KINDS = array( 'cash', 'card', 'stored_value', 'bank_transfer', 'other' );
	public const SOURCES = array( 'app', 'webview' );

	/** @var self|null */
	private static $instance = null;

	/** @var array<int, bool> */
	private $logged_invalid_ledgers = array();

	/** Get the shared ledger. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @var bool True while derive() is running payment_complete() — lets hooks skip re-entry. */
	private static $deriving = false;

	/** Whether the ledger is currently deriving order state (inside payment_complete()). */
	public static function is_deriving(): bool {
		return self::$deriving;
	}

	/** Read stored payment rows. An order without a ledger reads as empty, silently. */
	public function read( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || self::SCHEMA !== ( $decoded['schema'] ?? null ) || ! isset( $decoded['payments'] ) || ! is_array( $decoded['payments'] ) ) {
			$id = $order->get_id();
			if ( empty( $this->logged_invalid_ledgers[ $id ] ) ) {
				Logger::log( sprintf( 'Invalid WCPOS payment ledger on order #%d; treating as empty.', $id ) );
				$this->logged_invalid_ledgers[ $id ] = true;
			}
			return array();
		}
		return $decoded['payments'];
	}

	/** Find a payment row by UUID. */
	public function find( WC_Order $order, string $id ): ?array {
		$id = strtolower( $id );
		foreach ( $this->read( $order ) as $row ) {
			if ( $id === ( $row['id'] ?? null ) ) {
				return $row;
			}
		}
		return null;
	}

	/** Sum authorized and captured rows. */
	public function paid( array $rows ): string {
		$paid = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', self::COUNTING_STATUSES, true ) ) {
				$paid += Money::minor( $row['amount'] ?? 0 );
			}
		}
		return Money::format( $paid );
	}

	/** Calculate the non-negative order balance. */
	public function balance( WC_Order $order, array $rows ): string {
		$balance = Money::minor( $order->get_total() ) - Money::minor( $this->paid( $rows ) );
		return Money::format( max( 0, $balance ) );
	}

	/** Build the ledger-backed order summary. */
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

	/** Validate and record money already taken. */
	public function record( WC_Order $order, array $input, array $context = array() ) {
		if ( ! Pos_Uuid::is_uuid( $input['id'] ?? null ) ) {
			return $this->invalid( __( 'Payment id must be a UUID.', 'woocommerce-pos' ) );
		}
		$descriptor = Descriptor_Builder::instance()->get( (string) ( $input['method_id'] ?? '' ) );
		if ( ! $descriptor ) {
			return new WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$amount = wc_format_decimal( $input['amount'] ?? '', wc_get_price_decimals() );
		if ( '' === $amount || ! is_numeric( $amount ) || Money::minor( $amount ) <= 0 ) {
			return $this->invalid( __( 'Payment amount must be positive.', 'woocommerce-pos' ) );
		}
		$amount   = Money::normalize( $amount );
		$currency = isset( $input['currency'] ) ? (string) $input['currency'] : $order->get_currency();
		if ( $currency !== $order->get_currency() ) {
			return $this->invalid( __( 'Payment currency must match the order.', 'woocommerce-pos' ) );
		}
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'captured';
		if ( ! in_array( $status, array( 'captured', 'authorized' ), true ) ) {
			return $this->invalid( __( 'Recorded payments must be captured or authorized.', 'woocommerce-pos' ) );
		}
		$tendered = null;
		if ( array_key_exists( 'tendered', $input ) && null !== $input['tendered'] ) {
			$value = wc_format_decimal( $input['tendered'], wc_get_price_decimals() );
			if ( 'cash' !== $descriptor['kind'] || empty( $descriptor['capabilities']['change'] ) || '' === $value || ! is_numeric( $value ) || Money::minor( $value ) < Money::minor( $amount ) ) {
				return $this->invalid( __( 'Tendered amount is invalid for this payment method.', 'woocommerce-pos' ) );
			}
			$tendered = Money::normalize( $value );
		}

		$rows = $this->read( $order );
		$row  = array_merge(
			$input,
			array(
				'id'              => strtolower( $input['id'] ),
				'source'          => in_array( $input['source'] ?? 'app', self::SOURCES, true ) ? $input['source'] ?? 'app' : 'app',
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
				'cashier_id'      => (int) ( $context['cashier_id'] ?? get_current_user_id() ),
				'store_id'        => isset( $context['store_id'] ) ? (int) $context['store_id'] : null,
			)
		);

		$stored = $this->find_in_rows( $rows, $row['id'] );
		if ( $stored ) {
			foreach ( array( 'method_id', 'amount', 'kind', 'capture_mode', 'currency' ) as $field ) {
				if ( $row[ $field ] !== ( $stored[ $field ] ?? null ) ) {
					return new WP_Error( 'wcpos_payment_conflict', __( 'Payment id conflicts with an existing payment.', 'woocommerce-pos' ), array( 'status' => 409, 'payment' => $stored ) );
				}
			}
			$refusal = $this->refusal_error( $stored, $order );
			return $refusal ?: $stored;
		}

		$balance = Money::minor( $this->balance( $order, $rows ) );
		if ( 0 === $balance || Money::minor( $amount ) > $balance ) {
			$row['status']          = 'failed';
			$row['failure_reason']  = 0 === $balance ? 'order_already_paid' : 'amount_exceeds_balance';
			$row['captured_at_gmt'] = null;
			$row                    = $this->normalize_row( $order, $row );
			$rows[]                 = $row;
			$this->save( $order, $rows );
			return $this->refusal_error( $row, $order );
		}

		$row    = $this->normalize_row( $order, $row );
		$rows[] = $row;
		$this->save( $order, $rows );
		return $row;
	}

	/** Rebuild a stored overpay refusal. */
	public function refusal_error( array $row, WC_Order $order ): ?WP_Error {
		$reason = 'failed' === ( $row['status'] ?? '' ) ? ( $row['failure_reason'] ?? '' ) : '';
		if ( ! in_array( $reason, array( 'order_already_paid', 'amount_exceeds_balance' ), true ) ) {
			return null;
		}
		$already = 'order_already_paid' === $reason;
		return new WP_Error(
			$already ? 'wcpos_order_already_paid' : 'wcpos_amount_exceeds_balance',
			$already ? __( 'The order is already paid.', 'woocommerce-pos' ) : __( 'Payment amount exceeds the order balance.', 'woocommerce-pos' ),
			array( 'status' => $already ? 409 : 400, 'payment' => $row, 'order' => $this->summary( $order ) )
		);
	}

	/** Refresh one row through its capture-mode handler. */
	public function status( WC_Order $order, string $id ) {
		$rows = $this->read( $order );
		$row  = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		$handler = Capture_Mode_Registry::instance()->get( $row['capture_mode'] );
		if ( ! $handler ) {
			return $this->unsupported();
		}
		$new = $handler->status( $row );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$applied = $this->apply_transition( $row, $new );
		if ( ! is_wp_error( $applied ) && $applied !== $row ) {
			$this->replace_and_save( $order, $rows, $applied );
		}
		return $applied;
	}

	/** Void a pending or authorized row. */
	public function void( WC_Order $order, string $id, string $reason ) {
		$rows = $this->read( $order );
		$row  = $this->find_in_rows( $rows, strtolower( $id ) );
		if ( ! $row ) {
			return $this->not_found();
		}
		if ( ! in_array( $row['status'], array( 'pending', 'authorized' ), true ) ) {
			return $this->invalid_transition();
		}
		$handler = Capture_Mode_Registry::instance()->get( $row['capture_mode'] );
		if ( ! $handler ) {
			return $this->unsupported();
		}
		$new = $handler->void( $row, $reason );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$applied = $this->apply_transition( $row, $new );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$order->add_order_note(
			'' === $reason
				/* translators: %s: payment row uuid. */
				? sprintf( __( 'WCPOS payment %s voided.', 'woocommerce-pos' ), $row['id'] )
				/* translators: 1: payment row uuid, 2: void reason given by the cashier. */
				: sprintf( __( 'WCPOS payment %1$s voided: %2$s', 'woocommerce-pos' ), $row['id'], $reason )
		);
		$this->replace_and_save( $order, $rows, $applied );
		return $applied;
	}

	/** Apply handler-owned fields while enforcing the one-way lifecycle. */
	public function apply_transition( array $row, array $new ) {
		$from    = $row['status'] ?? '';
		$to      = $new['status'] ?? $from;
		$allowed = array( 'pending' => array( 'authorized', 'captured', 'failed', 'voided' ), 'authorized' => array( 'captured', 'voided' ) );
		if ( $to !== $from && ! in_array( $to, $allowed[ $from ] ?? array(), true ) ) {
			return $this->invalid_transition();
		}
		foreach ( array( 'status', 'failure_reason', 'provider_refs', 'receipt', 'captured_at_gmt', 'transport' ) as $field ) {
			if ( array_key_exists( $field, $new ) ) {
				$row[ $field ] = $new[ $field ];
			}
		}
		return $row;
	}

	/** Normalize, persist, index, and derive a ledger. */
	public function save( WC_Order $order, array $rows ): void {
		$normalized = array();
		foreach ( $rows as $row ) {
			$normalized[] = $this->normalize_row( $order, $row );
		}
		$wire = $normalized;
		foreach ( $wire as &$row ) {
			foreach ( array( 'provider_refs', 'receipt' ) as $field ) {
				if ( empty( $row[ $field ] ) ) {
					$row[ $field ] = new \stdClass();
				}
			}
		}
		unset( $row );
		$order->update_meta_data( self::META_KEY, wp_json_encode( array( 'schema' => self::SCHEMA, 'payments' => $wire ) ) );
		$order->delete_meta_data( self::INDEX_META_KEY );
		$indexed = array();
		foreach ( $normalized as $row ) {
			if ( in_array( $row['status'], self::LIVE_STATUSES, true ) && ! in_array( $row['method_id'], $indexed, true ) ) {
				$indexed[] = $row['method_id'];
				$order->add_meta_data( self::INDEX_META_KEY, $row['method_id'], false );
			}
		}
		$this->derive( $order, $normalized );
		$order->save();
	}

	/** Derive WooCommerce fields without changing the order total. */
	public function derive( WC_Order $order, array $rows ): void {
		$counting = array_values( array_filter( $rows, static function ( array $row ): bool {
			return in_array( $row['status'] ?? '', self::COUNTING_STATUSES, true );
		} ) );
		$candidates = array_values( array_filter( $counting, static function ( array $row ): bool {
			return 'stored_value' !== ( $row['kind'] ?? '' );
		} ) );
		$candidates = $candidates ?: $counting;
		$selected   = null;
		foreach ( $candidates as $row ) {
			$amount = Money::minor( $row['amount'] );
			$time   = $this->timestamp( $row['captured_at_gmt'] ?? null );
			if ( null === $selected || $amount > Money::minor( $selected['amount'] ) || ( $amount === Money::minor( $selected['amount'] ) && $time < $this->timestamp( $selected['captured_at_gmt'] ?? null ) ) ) {
				$selected = $row;
			}
		}
		// No counting rows: leave payment_method/title untouched (the cart, or every leg voided).
		if ( $selected ) {
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
		if ( ! in_array( $order->get_status(), array( 'pos-open', 'pos-partial', 'pending', 'failed' ), true ) ) {
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
					$order->payment_complete();
				} finally {
					self::$deriving = false;
				}
			}
			return;
		}
		$pending = (bool) array_filter( $rows, static function ( array $row ): bool {
			return 'pending' === ( $row['status'] ?? '' );
		} );
		$order->set_status( $pending ? 'pending' : ( $paid > 0 ? 'pos-partial' : 'pos-open' ) );
	}

	/** Normalize every required row field while preserving extras. */
	private function normalize_row( WC_Order $order, array $row ): array {
		$now      = gmdate( 'c' );
		$status   = in_array( $row['status'] ?? '', self::STATUSES, true ) ? $row['status'] : 'failed';
		$amount   = Money::normalize( $row['amount'] ?? 0 );
		$tendered = $this->nullable_money( $row['tendered'] ?? null );
		$defaults = array(
			'id' => '', 'source' => 'app', 'order_id' => $order->get_id(), 'method_id' => '', 'provider' => null,
			'kind' => 'other', 'capture_mode' => '', 'transport' => null, 'recorded_offline' => false,
			'amount' => $amount, 'currency' => $order->get_currency(), 'tendered' => $tendered,
			'change' => null === $tendered ? null : Money::format( Money::minor( $tendered ) - Money::minor( $amount ) ),
			'tip' => $this->nullable_money( $row['tip'] ?? null ), 'status' => $status, 'failure_reason' => null,
			'refunded_amount' => Money::normalize( $row['refunded_amount'] ?? 0 ), 'refunds' => array(),
			'provider_refs' => array(), 'receipt' => array(), 'cashier_id' => 0, 'store_id' => null,
			'created_at_gmt' => $this->valid_time( $row['created_at_gmt'] ?? null ) ?: $now,
			// Keep a valid captured_at_gmt across later transitions (a voided authorized leg
			// keeps the time the reader approved it); default to now only when the row counts.
			'captured_at_gmt' => $this->valid_time( $row['captured_at_gmt'] ?? null ) ?: ( in_array( $status, self::COUNTING_STATUSES, true ) ? $now : null ),
			'updated_at_gmt' => $now,
		);
		$row = array_merge( $defaults, $row );
		$row['id']               = strtolower( (string) $row['id'] );
		$row['order_id']         = $order->get_id();
		$row['amount']           = $amount;
		$row['tendered']         = $tendered;
		$row['change']           = $defaults['change'];
		$row['tip']              = $defaults['tip'];
		$row['status']           = $status;
		$row['created_at_gmt']   = $defaults['created_at_gmt'];
		$row['captured_at_gmt']  = $defaults['captured_at_gmt'];
		$row['cashier_id']       = (int) $row['cashier_id'];
		$row['store_id']         = null === $row['store_id'] ? null : (int) $row['store_id'];
		$row['recorded_offline'] = (bool) $row['recorded_offline'];
		$row['provider_refs']    = is_array( $row['provider_refs'] ) ? $row['provider_refs'] : array();
		$row['receipt']          = is_array( $row['receipt'] ) ? $row['receipt'] : array();
		$row['refunds']          = is_array( $row['refunds'] ) ? $row['refunds'] : array();
		$row['updated_at_gmt']   = $now;
		return $row;
	}

	/** Find a row without reading storage again. */
	private function find_in_rows( array $rows, string $id ): ?array {
		foreach ( $rows as $row ) {
			if ( $id === ( $row['id'] ?? null ) ) {
				return $row;
			}
		}
		return null;
	}

	/** Replace a row and persist the ledger. */
	private function replace_and_save( WC_Order $order, array $rows, array $replacement ): void {
		foreach ( $rows as &$row ) {
			if ( $row['id'] === $replacement['id'] ) {
				$row = $replacement;
				break;
			}
		}
		unset( $row );
		$this->save( $order, $rows );
	}

	/** Normalize optional money, dropping invalid values. */
	private function nullable_money( $value ): ?string {
		$value = null === $value ? '' : wc_format_decimal( $value, wc_get_price_decimals() );
		return '' !== $value && is_numeric( $value ) ? Money::normalize( $value ) : null;
	}

	/** Return an ISO time only when parseable. */
	private function valid_time( $value ): ?string {
		return is_string( $value ) && false !== strtotime( $value ) ? $value : null;
	}

	/** Sort missing timestamps after real timestamps. */
	private function timestamp( $value ): int {
		$timestamp = is_string( $value ) ? strtotime( $value ) : false;
		return false === $timestamp ? PHP_INT_MAX : $timestamp;
	}

	/** Standard invalid input response. */
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
