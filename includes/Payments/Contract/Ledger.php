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
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} else {
			return array();
		}
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
				$order->save();
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
				'cashier_id'      => (int) ( $context['cashier_id'] ?? get_current_user_id() ),
				'store_id'        => isset( $context['store_id'] ) ? (int) $context['store_id'] : null,
			)
		);

		$balance = Money::minor( $this->balance( $order, $rows ) );
		if ( 0 === $balance || Money::minor( $amount ) > $balance ) {
			$row['status']          = 'failed';
			$row['failure_reason']  = 0 === $balance ? 'order_already_paid' : 'amount_exceeds_balance';
			$row['captured_at_gmt'] = null;
			$row                    = $this->normalize_row( $order, $row );
			$rows[]                 = $row;
			$this->save( $order, $rows );
			Settlement::instance()->apply_parked( $order, $row['id'] );
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
		$order->save();
		return $this->find( $order, $row['id'] );
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
		$row = $this->normalize_row(
			$order,
			array(
				'id' => $id,
				'method_id' => $descriptor['id'],
				'provider' => $descriptor['capture']['provider'],
				'kind' => $descriptor['kind'],
				'capture_mode' => $descriptor['capture']['mode'],
				'amount' => $amount,
				'currency' => $currency,
				'status' => 'pending',
				'cashier_id' => (int) ( $context['cashier_id'] ?? get_current_user_id() ),
				'store_id' => isset( $context['store_id'] ) ? (int) $context['store_id'] : null,
			)
		);
		$handler = Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] );
		$new = $handler ? $handler->intent( $row, $context ) : $this->unsupported();
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$handoff = $new['handoff'] ?? array();
		$row = $this->apply_transition( $row, $new );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row = $this->normalize_row( $order, $row );
		$rows[] = $row;
		$this->save( $order, $rows );
		// A provider can webhook before Free has written the row; that confirmation is
		// parked and drained here, as record() does — the sweep never drains.
		$settled = Settlement::instance()->apply_parked( $order, $row['id'] );
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
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
			return $new;
		}
		return $this->apply_result( $order, $id, $new );
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
		$allocated = 0;
		foreach ( $row['refunds'] ?? array() as $refund ) {
			if ( (int) $refund['id'] === $refund_id ) {
				return $row;
			}
			if ( in_array( $refund['status'], array( 'succeeded', 'pending' ), true ) ) {
				$allocated += Money::minor( $refund['amount'] );
			}
		}
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || 'shop_order_refund' !== $refund->get_type() || $refund->get_parent_id() !== $order->get_id() ) {
			return $this->invalid( __( 'Refund must belong to this order.', 'woocommerce-pos' ) );
		}
		if ( ! is_numeric( $amount ) || Money::minor( $amount ) <= 0 || $allocated + Money::minor( $amount ) > Money::minor( $row['amount'] ) ) {
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
		$refunded = 0;
		foreach ( $row['refunds'] as $refund ) {
			if ( 'succeeded' === $refund['status'] ) {
				$refunded += Money::minor( $refund['amount'] );
			}
		}
		$row['refunded_amount'] = Money::format( $refunded );
		$row = $this->normalize_row( $order, $row );
		$this->replace_and_save( $order, $rows, $row );
		return $row;
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
		if ( ! in_array( $reason, array( 'order_already_paid', 'amount_exceeds_balance', 'amount_mismatch' ), true ) ) {
			return null;
		}
		$already = 'order_already_paid' === $reason;
		return new WP_Error(
			'wcpos_' . $reason,
			'amount_mismatch' === $reason ? __( 'Provider-confirmed amount or currency does not match the payment.', 'woocommerce-pos' ) : ( $already ? __( 'The order is already paid.', 'woocommerce-pos' ) : __( 'Payment amount exceeds the order balance.', 'woocommerce-pos' ) ),
			array(
				'status' => 'amount_exceeds_balance' === $reason ? 400 : 409,
				'payment' => self::to_wire( $row ),
				'order' => $this->summary( $order ),
			)
		);
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
		return $this->apply_result( $order, $id, $new );
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
		foreach ( array( 'status', 'failure_reason', 'provider_refs', 'receipt', 'captured_at_gmt', 'transport', 'expires_at', 'refunds', 'events', 'void_requested_at' ) as $field ) {
			if ( array_key_exists( $field, $new ) ) {
				$row[ $field ] = $new[ $field ];
			}
		}
		return $row;
	}

	/**
	 * Normalize, persist, index, and derive a ledger.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $rows  Payment rows.
	 * @param bool     $derive Whether to project payment state (deferred during offline arrival).
	 */
	public function save( WC_Order $order, array $rows, bool $derive = true ): void {
		$normalized = array();
		foreach ( $rows as $row ) {
			$normalized[] = $this->normalize_row( $order, $row );
		}
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
		$order->delete_meta_data( self::INDEX_META_KEY );
		$order->delete_meta_data( self::PAYMENT_ID_META_KEY );
		$order->delete_meta_data( self::LIVE_LEG_META_KEY );
		$indexed = array();
		foreach ( $normalized as $row ) {
			$order->add_meta_data( self::PAYMENT_ID_META_KEY, $row['id'], false );
			if ( in_array( $row['status'], array( 'pending', 'authorized' ), true ) ) {
				$order->add_meta_data( self::LIVE_LEG_META_KEY, $row['id'], false );
			}
			if ( in_array( $row['status'], self::LIVE_STATUSES, true ) && ! in_array( $row['method_id'], $indexed, true ) ) {
				$indexed[] = $row['method_id'];
				$order->add_meta_data( self::INDEX_META_KEY, $row['method_id'], false );
			}
		}
		if ( $derive ) {
			$this->derive( $order, $normalized );
		}
		$order->save();
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
		if ( ! in_array( $order->get_status(), self::IN_PROGRESS_STATUSES, true ) ) {
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
		$pending = (bool) array_filter(
			$rows,
			static function ( array $row ): bool {
				return 'pending' === ( $row['status'] ?? '' );
			}
		);
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
			'created_at_gmt' => $this->valid_time( $row['created_at_gmt'] ?? null ) ? $this->valid_time( $row['created_at_gmt'] ?? null ) : $now,
			// Keep a valid captured_at_gmt across later transitions (a voided authorized leg
			// keeps the time the reader approved it); default to now only when the row counts.
			'captured_at_gmt' => $this->valid_time( $row['captured_at_gmt'] ?? null ) ? $this->valid_time( $row['captured_at_gmt'] ?? null ) : ( in_array( $status, self::COUNTING_STATUSES, true ) ? $now : null ),
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
