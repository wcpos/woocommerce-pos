<?php
/**
 * Order collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Epoch;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Meta_Guard;
use WCPOS\WooCommercePOS\Payments\Contract\Money_Owner;
use WCPOS\WooCommercePOS\Payments\Contract\Settlement_Change;
use WCPOS\WooCommercePOS\Services\Client_Signal;
use WCPOS\WooCommercePOS\Services\Protocol_Gate;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Order_Write_Intent;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Quick_Discount;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Stock_Validator;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Response;
use const WCPOS\WooCommercePOS\VERSION;

/** Owns order audit, tax, reassignment, hook, note, email, and stock behavior. */
class Order_Writer extends Null_Writer {
	/**
	 * Audit meta recording HOW an order came to be paid (ADR 0035 path 3):
	 * `offline` means the till ASSERTED payment via `set_paid` — no gateway ran.
	 * Server-owned (spoof-stripped via Pos_Order_Audit::SERVER_META_KEYS).
	 */
	public const PAYMENT_ASSERTED_META    = '_pos_payment_asserted';
	public const PAYMENT_ASSERTED_OFFLINE = 'offline';

	/** @var object Mutation store used for HPOS-safe audit persistence. */
	private $store;

	/** @var Order_Write_Payload Order forward payload shaper. */
	private Order_Write_Payload $order_payload;

	/**
	 * Provenance keys the pre-insert callback refused because the order had
	 * been paid between the pre-read and the write; consumed by the update
	 * phase's order note so the refusal never goes unrecorded.
	 *
	 * @var string[]
	 */
	private array $late_provenance_refusals = array();

	/** @var array Context prepared under the lock, consumed by after_update. */
	private array $update_context = array();

	/** Construct the order writer. */
	public function __construct( object $store, ?Order_Write_Payload $order_payload = null ) {
		$this->store         = $store;
		$this->order_payload = $order_payload ?? new Order_Write_Payload();
	}

	/** Prepare an order create and its create-only hook policy. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids ) {
		$created_gmt = $this->order_payload->validate_client_created_gmt( $payload );
		if ( is_wp_error( $created_gmt ) ) {
			return $created_gmt;
		}
		$error = $validate_tax_ids( $payload );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$forward                = $payload;
		$forward['created_via'] = 'woocommerce-pos';
		unset( $forward['tax_ids'] );
		if ( isset( $forward['meta_data'] ) && is_array( $forward['meta_data'] ) ) {
			$forward['meta_data'] = $this->without_pos_audit_meta( $forward );
		}
		$meta_data = isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ? $payload['meta_data'] : array();
		$till_meta = Pos_Order_Audit::till_meta_from_payload( $meta_data );
		if ( isset( $till_meta['_wcpos_sale_time'] ) ) {
			$till_meta['_wcpos_sale_received_gmt'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		$till_meta['_pos_user']         = (string) get_current_user_id();
		$till_meta['_pos_user_created'] = $till_meta['_pos_user'];
		if ( self::asserts_payment( $payload ) ) {
			// A CREATE carrying set_paid IS the payment event — the push itself
			// marks the fresh order paid with no gateway involved.
			$till_meta[ self::PAYMENT_ASSERTED_META ] = self::PAYMENT_ASSERTED_OFFLINE;
		}
		return array(
			'method' => 'POST',
			'route' => $meta['route'],
			'payload' => $this->order_payload->for_create( $forward ),
			'context' => array(
				'operation' => 'create',
				'money' => $meta['money'] ?? array(),
				'pushed_payload' => $payload,
				'created_gmt' => $created_gmt,
				'fill_meta' => $till_meta,
			),
		);
	}

	/** Prepare an order update and its update-only hook/reassignment policy. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids ) {
		// Validated up front, exactly as on create: a bad value is a 400 before the
		// pre-read, and the late context only decides whether the order may take it.
		$created_gmt = $this->order_payload->validate_client_created_gmt( $payload );
		if ( is_wp_error( $created_gmt ) ) {
			return $created_gmt;
		}
		$error = $validate_tax_ids( $payload );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return array(
			'method' => 'PUT',
			'route' => $meta['route'] . '/' . $id,
			'payload' => $payload,
			'context' => array(
				'operation' => 'update',
				'prepare_under_lock' => true,
				'id' => $id,
				'pushed_payload' => $payload,
				'requested_created_gmt' => $created_gmt,
				'money' => $meta['money'] ?? array(),
			),
		);
	}

	/** Whether the push payload asserts payment via wc/v3's write-only set_paid flag. */
	private static function asserts_payment( array $payload ): bool {
		return isset( $payload['set_paid'] ) && rest_sanitize_boolean( $payload['set_paid'] );
	}

	/** Repair an existing born-twice order without inventing a version stamp. */
	public function validate_existing_create( int $id, array $payload, array $prepared ) {
		if ( ! $this->lock( $id ) ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_record_locked',
					'message' => 'Record is being written; retry shortly.',
				),
				409
			);
		}
		try {
			// The repair runs against an EXISTING order: if a gateway paid it between
			// the two create arrivals, the replayed set_paid no longer describes this
			// order's payment — same needs_payment() guard as the update path. The
			// recovery phases keep stamping unconditionally: there the order was paid
			// by this very push.
			$order = wc_get_order( $id );
			if ( $order && ! $order->needs_payment() && '' === (string) $order->get_meta( self::PAYMENT_ASSERTED_META ) ) {
				unset( $payload['set_paid'] );
			}
			// A post-create lock refusal precedes after_create(); fallback VAT is not proof of persistence.
			// The tax-ID writer stores explicit IDs canonically; keep that original set on duplicate creates.
			if ( $order && ! $order->meta_exists( Tax_Id_Reader::CANONICAL_META_KEY ) ) {
				$this->order_payload->persist_tax_ids( $id, $payload, true );
			}
			$this->stamp_order_audit( $id, $payload, false );
			$order = wc_get_order( $id );
			// A create replay is an epoch-0 fact: on a reopened sale it only acknowledges identity.
			if ( $order && 0 === Money_Epoch::current( $order ) ) {
				if ( '' === $order->get_meta( Money_Owner::META_KEY ) ) {
					$this->stamp_owner( $prepared['context']['pushed_payload'], $prepared['context']['money']['tillAggregate'] ?? null, $order );
				}
				$this->complete_money_transition( $order, $prepared['context'] );
			}
			return null;
		} catch ( \Throwable $error ) {
			return $this->money_attention( $id, $prepared['context']['money']['mutation_id'] ?? null, $error->getMessage() );
		} finally {
			$this->unlock( $id );
		}
	}

	/** Acquire the payment lock before the controller reads the revision. */
	public function lock( int $id ): bool {
		return Order_Lock::instance()->acquire( $id );
	}

	/** Release the controller's acquisition, including conflict and error paths. */
	public function unlock( int $id ): void {
		Order_Lock::instance()->release( $id );
	}

	/** Forward within the named order hook lifecycle. */
	public function forward( array $prepared, callable $forward ) {
		$declared = $prepared['context'];
		if ( ! in_array( $declared['operation'] ?? '', array( 'create', 'update' ), true ) ) {
			return $this->forward_with_reserved_stock( $prepared, $forward );
		}
		if ( ! empty( $declared['prepare_under_lock'] ) ) {
			// Prepare once under the payment lock: no payment can land between proof and save.
			$late = $this->prepare_order_update_after_read( $declared['id'], $declared['pushed_payload'], $declared['requested_created_gmt'], $declared['money'] );
			if ( ! is_array( $late ) ) {
				return $late;
			}
			$prepared['payload'] = $late['payload'];
			$declared = $late['context'];
			$this->update_context = $declared;
			if ( $declared['reopen'] ) {
				unset( $prepared['payload']['set_paid'] ); // Reopening is not another payment completion.
				$error = Money_Epoch::reopen_refusal( $declared['money_before'] );
				if ( $error ) {
					return $error;
				}
			}
		}
		$payload = $prepared['payload'];
		$declared['requested_status'] = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$declared['set_paid'] = isset( $payload['set_paid'] ) && rest_sanitize_boolean( $payload['set_paid'] );
		$prepared['context'] = $declared;
		$response = Order_Write_Intent::open(
			$declared,
			function () use ( $prepared, $forward ) {
				return Money_Meta_Guard::saving( (int) ( $prepared['context']['id'] ?? 0 ), fn() => $this->forward_with_reserved_stock( $prepared, $forward ) );
			}
		);
		if ( $response instanceof WP_REST_Response && $response->get_status() >= 200 && $response->get_status() < 300 && ( 'update' === $declared['operation'] || 201 === $response->get_status() ) ) {
			$id = (int) ( $response->get_data()['id'] ?? 0 );
			if ( $id > 0 && 'create' === $declared['operation'] && isset( $declared['preserve_create_identity'] ) ) {
				// Prove identity before any fallible post-create work, without finalizing:
				// a retry must find this order and still run its money transition.
				$error = $declared['preserve_create_identity']( $id );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
			// The write has committed: a failed money transition is never auto-recovered.
			$mutation_id = $declared['money']['mutation_id'] ?? null;
			if ( ! $this->lock( $id ) ) {
				return $this->money_attention( $id, $mutation_id, 'the order lock is held by another operation' );
			}
			try {
				$saved = wc_get_order( $id );
				if ( $saved ) {
					$this->stamp_owner( $declared['pushed_payload'], $declared['money']['tillAggregate'] ?? null, $saved );
					$this->complete_money_transition( $saved, $declared );
				}
			} catch ( \Throwable $error ) {
				return $this->money_attention( $id, $mutation_id, $error->getMessage() );
			} finally {
				$this->unlock( $id );
			}
		}
		return $response;
	}

	/**
	 * Fail loudly after a committed write whose money transition did not run: an
	 * error log, an order note for a human, and the retryable 409 the sync lane knows.
	 *
	 * @param int         $id          Order id.
	 * @param string|null $mutation_id Sync mutation id, when the push supplied one.
	 * @param string      $reason      Why the transition failed.
	 */
	private function money_attention( int $id, ?string $mutation_id, string $reason ): WP_Error {
		$mutation_id = $mutation_id ?? 'unknown';
		Logger::error(
			sprintf( 'WCPOS money transition failed after the write on order #%d (mutation %s): %s', $id, $mutation_id, $reason ),
			array(
				'order_id' => $id,
				'mutation_id' => $mutation_id,
			)
		);
		try {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->add_order_note( sprintf( 'WCPOS: money state needs attention (mutation %s): %s', $mutation_id, $reason ) );
			}
		} catch ( \Throwable $note_error ) {
			Logger::error( sprintf( 'WCPOS could not add the money-attention note on order #%d: %s', $id, $note_error->getMessage() ) );
		}
		return new WP_Error(
			'woo_rxdb_sync_record_locked',
			'Order money transition failed after the write; retry shortly.',
			array(
				'status' => 409,
				'order_id' => $id,
				'mutation_id' => $mutation_id,
			)
		);
	}

	/** Persist only server-proven completion/reopen intents; forged meta was already stripped. */
	private function complete_money_transition( \WC_Order $saved, array $context ): void {
		$meta = Settlement_Change::meta( $context['pushed_payload'] );
		if ( ! empty( $context['reopen'] ) ) {
			Money_Meta_Guard::authorised(
				$saved->get_id(),
				static function () use ( $saved, $context, $meta ): void {
					$saved->update_meta_data( '_wcpos_money_epoch', (string) ( $context['epoch_before'] + 1 ) );
					foreach ( array( '_wcpos_completed_at', '_wcpos_completed_epoch', '_wcpos_completed_session', Money_Owner::META_KEY ) as $key ) {
						$saved->delete_meta_data( $key );
					}
					$saved->update_meta_data( '_wcpos_reopened_at', $meta['_wcpos_reopened_at'] );
					$saved->save();
				}
			);
			self::verify_persisted(
				$saved,
				array(
					'_wcpos_money_epoch' => (string) ( $context['epoch_before'] + 1 ),
					'_wcpos_completed_at' => '',
				),
				'reopen'
			);
			return;
		}
		if ( ! is_string( $meta['_wcpos_completed_at'] ?? null ) || '' === $meta['_wcpos_completed_at'] ) {
			return;
		}
		$before_epoch = $context['epoch_before'] ?? 0;
		$epoch = Money_Epoch::current( $saved );
		$stamp = Money_Epoch::completion_stamp( $saved );
		$keep_stamp = $stamp && $stamp['epoch'] >= $epoch;
		$reduced = $before_epoch > 0
			&& ( array_intersect( array_keys( Settlement_Change::TYPES ), $context['settlement_changes'] ) || Money::minor( $saved->get_total() ) < Money::minor( $context['total_before'] ) )
			&& Money::minor( $saved->get_total() ) <= Money_Epoch::net_paid( Money_Epoch::current_rows( $saved, Ledger::instance()->read( $saved ) ) );
		// A zero-total sale completes at epoch 0 whether created or updated; never after a reopen.
		$free = 0 === $epoch && 0 === Money::minor( $saved->get_total() );
		if ( ! Money_Epoch::is_completed_in_current_epoch( $saved, Ledger::instance() ) && ! $reduced && ! $free ) {
			Logger::warning( sprintf( 'WCPOS completion stamp refused on order #%d: no completion proof in the current epoch', $saved->get_id() ) );
			return;
		}
		Money_Meta_Guard::authorised(
			$saved->get_id(),
			static function () use ( $saved, $meta, $epoch, $keep_stamp, $context ): void {
				if ( ! $keep_stamp ) {
					$saved->update_meta_data( '_wcpos_completed_at', $meta['_wcpos_completed_at'] );
					$saved->update_meta_data( '_wcpos_completed_epoch', (string) $epoch );
					if ( is_string( $meta['_wcpos_completed_session'] ?? null ) ) {
						$saved->update_meta_data( '_wcpos_completed_session', $meta['_wcpos_completed_session'] );
					}
				}
				$till = Pos_Order_Audit::till_meta_from_payload( $context['pushed_payload']['meta_data'] ?? array() );
				$provenance = $saved->get_meta( '_wcpos_epoch_provenance' );
				$provenance = is_array( $provenance ) ? $provenance : array();
				if ( $epoch > 0 ) {
					foreach ( array(
						'register_id' => '_wcpos_register',
						'session_id' => '_wcpos_session',
						'sale_time' => '_wcpos_sale_time',
						'sale_zone' => '_wcpos_sale_tz',
						'sale_counter' => '_wcpos_sale_counter',
						'completed_at' => '_wcpos_completed_at',
					) as $field => $key ) {
						$stored = $saved->get_meta( $key );
						$validation_key = 'completed_at' === $field ? '_wcpos_sale_time' : $key;
						$value = Pos_Order_Audit::is_valid_till_value( $validation_key, $stored ) ? $stored : ( $till[ $key ] ?? null );
						// Existing non-null provenance stands; a later valid completion may fill a gap.
						$provenance[ $epoch ][ $field ] = $provenance[ $epoch ][ $field ] ?? ( 'sale_counter' === $field && null !== $value ? (int) $value : $value );
					}
					$saved->update_meta_data( '_wcpos_epoch_provenance', $provenance );
				}
				$saved->save();
			}
		);
		if ( ! $keep_stamp ) {
			self::verify_persisted(
				$saved,
				array(
					'_wcpos_completed_at' => $meta['_wcpos_completed_at'],
					'_wcpos_completed_epoch' => (string) $epoch,
				),
				'completion'
			);
		}
	}

	/**
	 * Stamp the money owner, then prove a stamp it decided to write landed.
	 *
	 * @param array      $payload   Pushed order document.
	 * @param array|null $aggregate Till aggregate from the envelope.
	 * @param \WC_Order  $order     Order under the payment lock.
	 * @throws Ledger_Persistence_Exception When the owner stamp was not persisted.
	 */
	private function stamp_owner( array $payload, ?array $aggregate, \WC_Order $order ): void {
		$before = $order->get_meta( Money_Owner::META_KEY );
		Money_Owner::stamp( $payload, $aggregate, $order );
		if ( Money_Owner::VALUE_SERVER !== $before && Money_Owner::VALUE_SERVER === $order->get_meta( Money_Owner::META_KEY ) ) {
			self::verify_persisted( $order, array( Money_Owner::META_KEY => Money_Owner::VALUE_SERVER ), 'owner' );
		}
	}

	/**
	 * WooCommerce swallows save exceptions: read storage back and prove each written fact landed.
	 * Not wc_get_order(): under HPOS its cached order shares meta objects with the refused
	 * in-memory order, so it can report a change that never reached the database.
	 *
	 * @param \WC_Order $order    The order that was saved.
	 * @param array     $expected Meta key => stored string value ('' for absent).
	 * @param string    $which    Transition name for the failure message.
	 * @throws Ledger_Persistence_Exception When a written fact is missing.
	 */
	private static function verify_persisted( \WC_Order $order, array $expected, string $which ): void {
		$stored = array();
		foreach ( $order->get_data_store()->read_meta( $order ) as $row ) {
			if ( ! array_key_exists( $row->meta_key, $stored ) ) {
				$stored[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
			}
		}
		foreach ( $expected as $key => $value ) {
			$actual = $stored[ $key ] ?? '';
			if ( ! is_scalar( $actual ) || (string) $actual !== $value ) {
				throw new Ledger_Persistence_Exception( esc_html( sprintf( 'WCPOS money transition was not persisted (%s).', $which ) ) );
			}
		}
	}

	/**
	 * The creation date a cart restart may write, or null when the order may not take it.
	 *
	 * A cart the cashier emptied restarts when its next line goes in
	 * (wcpos/monorepo#2183): the till restamps date_created_gmt and sends it on the
	 * next push. wc/v3 drops the field on PUT (readonly), so the update's intent
	 * carries it — only while the order is still an unpaid open cart with no payment
	 * rows, and only forward: a second till still holding the old date sends an
	 * older value, which is ignored, so a stale push cannot undo a restart.
	 * `needs_payment()` is the wrong gate here, but not because of the zero total: Free's
	 * woocommerce_order_needs_payment callback answers true for a 0-total pos-open order
	 * (Orders.php, pinned in Test_Orders). It is wrong because it does not say "open, unpaid,
	 * no payment rows" — it also answers true for pos-partial, which must never restart.
	 * The requested status is deliberately not consulted: wc/v3 applies it after pre-insert,
	 * so a push that restarts and closes the cart in one request takes the restart date.
	 *
	 * Decided twice on purpose: at the pre-read, and again at pre-insert on the order
	 * wc/v3 is about to write (under the payment lock), so a payment that completed
	 * in between wins — the same shape as the provenance re-decision.
	 *
	 * @param \WC_Order $order       The order as currently loaded.
	 * @param int|null  $created_gmt Validated client date_created_gmt (UTC timestamp), or null when absent.
	 */
	public static function cart_restart_gmt( \WC_Order $order, ?int $created_gmt ): ?int {
		if ( null === $created_gmt || 'pos-open' !== $order->get_status() || $order->get_date_paid( 'edit' ) ) {
			return null;
		}
		if ( array() !== Ledger::instance()->read( $order ) ) {
			return null;
		}
		$stored = $order->get_date_created( 'edit' );
		return ! $stored || $created_gmt > $stored->getTimestamp() ? $created_gmt : null;
	}

	/**
	 * Wrap the create forward in the shared create-pending -> reserve -> complete
	 * sequence, so this lane gets the SAME anti-overselling guarantee as wcpos/v1.
	 *
	 * Without this the only stock check on this lane is the `pre_insert` filter,
	 * which runs against an unsaved order (id 0) and so can only compare
	 * availability — it cannot take a reservation, and two terminals selling the
	 * last unit concurrently both pass it. See Stock_Validator::around_paid_create().
	 *
	 * @param array    $prepared Prepared forward.
	 * @param callable $forward  Underlying wc/v3 dispatch.
	 */
	private function forward_with_reserved_stock( array $prepared, callable $forward ) {
		$context = $prepared['context'];
		$payload = $prepared['payload'];
		$paid    = isset( $payload['set_paid'] ) && rest_sanitize_boolean( $payload['set_paid'] );
		$status  = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$validator = Stock_Validator::instance();

		if ( 'create' !== $context['operation']
			|| ! SettingsService::instance()->prevent_overselling_enabled()
			|| ! $validator->should_validate_create_payload( $status, $paid ) ) {
			return $this->forward_with_order_lifecycle( $prepared, $forward );
		}

		$response = null;
		$order    = $validator->around_paid_create(
			array(
				'status'         => $status,
				'set_paid'       => $paid,
				'transaction_id' => isset( $payload['transaction_id'] ) ? (string) $payload['transaction_id'] : '',
			),
			function ( array $neutralised ) use ( $prepared, $forward, &$response ) {
				$prepared['payload']['status']   = $neutralised['status'];
				$prepared['payload']['set_paid'] = $neutralised['set_paid'];
				$response                        = $this->forward_with_order_lifecycle( $prepared, $forward );
				$data                            = $response instanceof \WP_REST_Response ? $response->get_data() : null;
				$id                              = is_array( $data ) && isset( $data['id'] ) ? (int) $data['id'] : 0;

				return $id > 0 ? wc_get_order( $id ) : $response;
			}
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// The controller rebuilds its response document from wc_get_order( $id )
		// (see document()/build_response_document()), so the forwarded body does
		// not need re-shaping after payment completes — only the id has to be
		// right, and it is the same order throughout.
		return $response;
	}

	/** Persist tax IDs before the identity proof. */
	public function after_create( int $id, array $payload ): void {
		$this->order_payload->persist_tax_ids( $id, $payload, true );
	}

	/** Stamp audit metadata and add the creation note after the identity proof. */
	public function after_identity( int $id, array $payload ): void {
		$this->stamp_order_audit( $id, $payload, true );
		$order = wc_get_order( $id );
		if ( $order ) {
			Order_Notes::add_creation_note( $order, get_current_user_id(), $order->get_meta( '_pos_store' ) );
		}
	}

	/** Restore create side effects before a poisoned mutation can be finalized. */
	public function after_recovery( int $id, array $payload, array $money = array() ) {
		if ( ! $this->lock( $id ) ) {
			return $this->money_attention( $id, $money['mutation_id'] ?? null, 'the order lock is held by another operation' );
		}
		try {
			$this->stamp_order_audit( $id, $payload, false );
			$this->order_payload->persist_tax_ids( $id, $payload, true );
			$order = wc_get_order( $id );
			if ( $order ) {
				if ( '' === $order->get_meta( Money_Owner::META_KEY ) ) {
					$this->stamp_owner( $payload, $money['tillAggregate'] ?? null, $order );
				}
				$this->complete_money_transition(
					$order,
					array(
						'operation' => 'create',
						'pushed_payload' => $payload,
					)
				);
			}
			return null;
		} catch ( \Throwable $error ) {
			return $this->money_attention( $id, $money['mutation_id'] ?? null, $error->getMessage() );
		} finally {
			$this->unlock( $id );
		}
	}

	/** Persist till metadata, tax IDs, reassignment, and email changes after update. */
	public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void {
		if ( ! empty( $context['prepare_under_lock'] ) && ! empty( $this->update_context ) ) {
			$context = $this->update_context;
			$this->update_context = array();
		}
		$this->stamp_order_till_meta( $id, $payload );
		$this->order_payload->persist_tax_ids( $id, $payload, false );
		$this->persist_cashier_store_reassignment( $id, $current, $response_data, $context );
		if ( ! empty( $context['clear_email'] ) ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->set_billing_email( '' );
				$order->get_data_store()->update( $order );
			}
		}
	}

	/** Execute delete with the named stock restore/rollback lifecycle. */
	public function delete( array $meta, int $id, array $mutation, callable $dispatch, callable $can_delete ) {
		$force = (bool) ( $mutation['force'] ?? false );
		return $this->forward_with_stock_restore_rollback(
			$this->delete_request( $meta['route'] . '/' . $id, $id, $force ),
			$id,
			$force,
			$dispatch,
			$can_delete
		);
	}

	/** Read an order with six-decimal precision and POS links. */
	public function document( array $meta, int $id, callable $default_document ) {
		$response = $default_document( $meta, $id, array( 'dp' => '6' ) );
		$data     = $response->get_data();
		$order    = wc_get_order( $id );
		if ( is_array( $data ) && $order ) {
			$response->set_data( Order_Serializer::add_pos_links( $data, $order ) );
		}
		return $response;
	}

	/** Build the canonical augmented order response document. */
	public function build_response_document( array $bare, string $record_id, array $meta, int $id, callable $default_builder ): array {
		$order = wc_get_order( $id );
		if ( $order ) {
			$bare = ( new Order_Serializer() )->document( $bare, Order_Serializer::V2_AUGMENTATIONS, null, $order );
		}
		return Pos_Uuid::ensure_in_payload( $bare, $record_id );
	}

	/** Apply create/update hook policies around one exact forwarded order. */
	private function forward_with_order_lifecycle( array $prepared, callable $forward ) {
		$context = $prepared['context'];
		$pre_insert = function ( $order, $request, $creating ) use ( $context ) {
			// Provenance on update: decided here, on the object about to be
			// written, so a payment that completed since the pre-read wins. The
			// intent named the subject at priority 1.
			$intent = Order_Write_Intent::current();
			if ( $order instanceof \WC_Order && null !== $intent && $intent->is_subject( $order ) ) {
				if ( isset( $context['restart_gmt'] ) ) {
					// Re-decided on the order about to be written: a payment row or
					// paid date that landed since the pre-read refuses the restart.
					$restart_gmt = self::cart_restart_gmt( $order, (int) $context['restart_gmt'] );
					if ( null !== $restart_gmt ) {
						$order->set_date_created( $restart_gmt );
					}
				}
				// Provenance only when there is provenance to write: a restart alone
				// must not restamp the receipt time, or Provenance_Health would read
				// a clock skew off an unchanged sale time.
				if ( array() === ( $context['fill_provenance'] ?? array() ) ) {
					return $order;
				}
				if ( $order->needs_payment() ) {
					foreach ( $context['fill_provenance'] as $key => $value ) {
						$order->update_meta_data( $key, $value );
					}
					$order->update_meta_data( '_wcpos_sale_received_gmt', gmdate( 'Y-m-d\TH:i:s\Z' ) );
				} else {
					// Paid since the pre-read: refused here, noted after the forward.
					$this->late_provenance_refusals = array_keys( $context['fill_provenance'] );
				}
			}
			return $order;
		};
		$created_via = static function ( $order ) {
			$intent = Order_Write_Intent::current();
			if ( $order instanceof \WC_Order && null !== $intent && $intent->is_subject( $order ) && 'woocommerce-pos' !== $order->get_created_via() ) {
				$order->set_created_via( 'woocommerce-pos' );
			}
		};
		$qd = new Quick_Discount();
		$persist_discount = static function ( $order, $request, $creating ) use ( $qd, $context, $prepared ) {
			// save_object() reloads the order before this action: compare IDs, not objects.
			$intent  = Order_Write_Intent::current();
			$subject = null !== $intent ? $intent->subject() : null;
			$id      = 'create' === $context['operation'] ? ( $subject ? $subject->get_id() : 0 ) : $context['id'];
			if ( $order instanceof \WC_Order && $id === $order->get_id()
				&& $request->get_route() === $prepared['route'] && ( 'create' === $context['operation'] ) === $creating ) {
				$qd->persist( $order );
			}
		};
		$use_filter = array() !== ( $context['fill_provenance'] ?? array() ) || isset( $context['restart_gmt'] );
		if ( $use_filter ) {
			add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $pre_insert, 10, 3 );
		}
		if ( 'create' === $context['operation'] ) {
			add_action( 'woocommerce_before_order_object_save', $created_via );
		}
		try {
			$error = $qd->register_from_lines( is_array( $prepared['payload']['coupon_lines'] ?? null ) ? $prepared['payload']['coupon_lines'] : array() );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			add_action( 'woocommerce_rest_insert_shop_order_object', $persist_discount, 10, 3 );
			return $forward( $prepared['method'], $prepared['route'], $prepared['payload'] );
		} finally {
			$qd->clear();
			remove_action( 'woocommerce_rest_insert_shop_order_object', $persist_discount, 10 );
			if ( $use_filter ) {
				remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $pre_insert, 10 );
			}
			if ( 'create' === $context['operation'] ) {
				remove_action( 'woocommerce_before_order_object_save', $created_via );
			}
		}
	}

	/** Name and execute the permanent/trash stock restore/rollback protocol. */
	private function forward_with_stock_restore_rollback( $request, int $id, bool $force, callable $dispatch, callable $can_delete ) {
		$setting       = SettingsService::instance()->restore_stock_on_delete_enabled();
		$restore_stock = apply_filters( 'woocommerce_pos_restore_stock_on_delete', $setting, $id );
		$pre_restored  = false;
		if ( $restore_stock && $force && $this->order_stock_reduced( $id ) && $can_delete( $id ) ) {
			wc_maybe_increase_stock_levels( $id );
			$pre_restored = true;
		}
		$response = $dispatch( $request );
		if ( $response->get_status() >= 400 ) {
			if ( $pre_restored ) {
				wc_maybe_reduce_stock_levels( $id );
			}
			return new WP_REST_Response( $response->get_data(), $response->get_status() );
		}
		if ( $restore_stock && ! $force ) {
			wc_maybe_increase_stock_levels( $id );
		}
		return $response;
	}

	/**
	 * Capture and authorize the cashier/store reassignment lifecycle.
	 *
	 * @param int      $id          Order ID.
	 * @param array    $payload     Client order document.
	 * @param int|null $created_gmt Validated client date_created_gmt (UTC timestamp), or null when absent.
	 */
	private function prepare_order_update_after_read( int $id, array $payload, ?int $created_gmt = null, array $money = array() ) {
		$before = wc_get_order( $id );
		$changes = $before ? Settlement_Change::changes( $payload, $before ) : array();
		$reopen = $before && Money_Epoch::is_reopen( $payload, $before );
		$request = $money['request'] ?? new \WP_REST_Request();
		// A completed sale becomes payable only through the epoch-advancing reopen command.
		if ( $before && ! $reopen && isset( $payload['status'] ) && $payload['status'] !== $before->get_status()
			&& in_array( $payload['status'], Ledger::IN_PROGRESS_STATUSES, true ) && Money_Epoch::is_completed_in_current_epoch( $before, Ledger::instance() ) ) {
			if ( ! Client_Signal::payments_at_least( Client_Signal::read( $request ), '1.3' ) ) {
				return Protocol_Gate::refusal(
					$request,
					array(
						'reason' => 'money_epoch',
						'epoch' => Money_Epoch::current( $before ),
					)
				);
			}
			return new WP_Error(
				'wcpos_invalid_transition',
				__( 'This sale requires a reopen command.', 'woocommerce-pos' ),
				array(
					'status' => 422,
					'reason' => 'reopen_required',
				)
			);
		}
		if ( $before && ( $changes || $reopen || array_key_exists( '_wcpos_completed_at', Settlement_Change::meta( $payload ) ) ) ) {
			$error = Money_Epoch::gate( $before, $request, $money['expectedEpoch'] ?? null );
			if ( $error ) {
				return $error;
			}
		}
		$pushed_payload = $payload;
		if ( $before && 0 === Money_Epoch::current( $before ) && Money_Owner::VALUE_SERVER === $before->get_meta( Money_Owner::META_KEY ) && ! Client_Signal::payments_at_least( Client_Signal::read( $request ), '1.3' ) ) {
			// The legacy till never learned the ownership rule: keep intent, not its rejected aggregate.
			foreach ( array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines' ) as $key ) {
				foreach ( $payload[ $key ] ?? array() as $index => $line ) {
					if ( ! is_array( $line ) ) {
						continue; // Let wc/v3 reject malformed items.
					}
					$payload[ $key ][ $index ] = array_diff_key( $line, array_flip( array( 'subtotal', 'subtotal_tax', 'total', 'total_tax', 'taxes', 'discount', 'discount_tax' ) ) );
				}
			}
		}
		$reassignment = array();
		foreach ( is_array( $payload['meta_data'] ?? null ) ? $payload['meta_data'] : array() as $entry ) {
			$key = Meta_Entry::key( $entry );
			if ( is_scalar( $key ) && in_array( (string) $key, array( '_pos_user', '_pos_store' ), true ) ) {
				$reassignment[ (string) $key ] = Meta_Entry::value( $entry ) ?? '';
			}
		}
		$authorized = isset( $reassignment['_pos_store'] ) && is_scalar( $reassignment['_pos_store'] ) && '' !== (string) $reassignment['_pos_store']
			? (bool) apply_filters( 'woocommerce_pos_order_store_reassignment_allowed', true, (string) $reassignment['_pos_store'], $id )
			: false;
		$forward = $payload;
		unset( $forward['created_via'], $forward['tax_ids'] );
		if ( isset( $forward['meta_data'] ) && is_array( $forward['meta_data'] ) ) {
			$forward['meta_data'] = $this->without_pos_audit_meta( $forward, $id );
		}
		$clear_email = isset( $forward['billing'] ) && is_array( $forward['billing'] )
			&& array_key_exists( 'email', $forward['billing'] ) && '' === $forward['billing']['email'];
		$fill_meta          = array();
		$fill_provenance    = array();
		$provenance_refused = array();
		$pre_store   = null;
		$restart_gmt = null;
		$order       = wc_get_order( $id );
		if ( $order ) {
			// Cart restart (wcpos/monorepo#2183): a first decision on the pre-read;
			// the binding one is re-taken at pre-insert, see cart_restart_gmt().
			$restart_gmt = self::cart_restart_gmt( $order, $created_gmt );
			/*
			 * `set_paid` is write-only in wc/v3, so a client re-sends it on every
			 * later edit of an order it created offline. WooCommerce only takes
			 * payment on update when the order still needs it (`$creating ||
			 * needs_payment()` in its orders controller) — mirror that, pre-forward,
			 * or an update to an order ALREADY paid by a real gateway (hosted pay
			 * page) would stamp 'offline' over a gateway-taken payment: the exact
			 * distinction this marker exists to draw. Fill-only, never overwrite.
			 */
			if ( self::asserts_payment( $payload ) && $order->needs_payment() && '' === (string) $order->get_meta( self::PAYMENT_ASSERTED_META ) ) {
				$fill_meta[ self::PAYMENT_ASSERTED_META ] = self::PAYMENT_ASSERTED_OFFLINE;
			}
			$pre_store = (string) $order->get_meta( '_pos_store' );
			foreach ( array( '_pos_user', '_pos_user_created' ) as $key ) {
				if ( '' === (string) $order->get_meta( $key ) ) {
					$fill_meta[ $key ] = (string) get_current_user_id();
				}
			}
			$till = Pos_Order_Audit::till_meta_from_payload( is_array( $payload['meta_data'] ?? null ) ? $payload['meta_data'] : array() );
			foreach ( Pos_Order_Audit::cash_meta_keys() as $key ) {
				if ( isset( $till[ $key ] ) && '' === (string) $order->get_meta( $key ) ) {
					$fill_meta[ $key ] = $till[ $key ];
				}
			}
			// Sale provenance is fillable while the order still needs payment (a
			// failed final leg re-stamps it) and frozen once the order is paid. The
			// decision is re-taken on the fresh order inside the pre-insert callback
			// (`fill_provenance`), so a payment completing between this read and the
			// write cannot be followed by a provenance write.
			$unpaid = $order->needs_payment();
			foreach ( Pos_Order_Audit::provenance_meta_keys() as $key ) {
				if ( ! isset( $till[ $key ] ) ) {
					continue;
				}
				$stored = (string) $order->get_meta( $key );
				if ( $unpaid && $stored !== $till[ $key ] ) {
					$fill_provenance[ $key ] = $till[ $key ];
				} elseif ( ! $unpaid && $stored !== $till[ $key ] ) {
					// A paid order is frozen: a missing key stays missing and a
					// different value is refused — nothing invents audit data later.
					$provenance_refused[] = $key;
				}
			}
			if ( $authorized && (string) $order->get_meta( '_pos_store' ) !== (string) $reassignment['_pos_store'] ) {
				$fill_meta['_pos_store'] = (string) $reassignment['_pos_store'];
			}
		}
		$context = array(
			'operation' => 'update',
			'money' => $money,
			'pushed_payload' => $pushed_payload,
			'money_before' => $before,
			'epoch_before' => $before ? Money_Epoch::current( $before ) : 0,
			'total_before' => $before ? $before->get_total() : '0',
			'settlement_changes' => $changes,
			'reopen' => $reopen,
			'requested_created_gmt' => $created_gmt,
			'id' => $id,
			'clear_email' => $clear_email,
			'reassignment' => $reassignment,
			'store_authorized' => $authorized,
			'pre_store' => $pre_store,
			'fill_meta' => $fill_meta,
			'fill_provenance' => $fill_provenance,
			'provenance_refused' => $provenance_refused,
		);
		if ( null !== $restart_gmt ) {
			$context['restart_gmt'] = $restart_gmt;
		}
		return array(
			'payload' => $this->order_payload->for_update( $id, $forward ),
			'context' => $context,
		);
	}

	/** Persist reassignment and its mutually exclusive order-note policy. */
	private function persist_cashier_store_reassignment( int $id, array $current, array $data, array $context ): void {
		$order = wc_get_order( $id );
		if ( ! $order || ! is_array( $data ) ) {
			return;
		}
		$refused                        = array_values( array_unique( array_merge( $context['provenance_refused'] ?? array(), $this->late_provenance_refusals ) ) );
		$this->late_provenance_refusals = array();
		if ( ! empty( $refused ) ) {
			Order_Notes::add_provenance_refused_note( $order, $refused );
		}
		$current_user = get_current_user_id();
		$change       = $context['reassignment'];
		$old_user     = $order->get_meta( '_pos_user' );
		$old_store    = null !== $context['pre_store'] ? $context['pre_store'] : $order->get_meta( '_pos_store' );
		$cashier_changed = isset( $change['_pos_user'] ) && is_numeric( $change['_pos_user'] )
			&& (int) $change['_pos_user'] === $current_user && (string) $old_user !== (string) $current_user;
		$store_changed = $context['store_authorized'] && (string) $old_store !== (string) $change['_pos_store'];
		if ( $cashier_changed ) {
			$order->update_meta_data( '_pos_user', (string) $current_user );
		}
		if ( $store_changed ) {
			$order->update_meta_data( '_pos_store', (string) $change['_pos_store'] );
		}
		if ( $cashier_changed || $store_changed ) {
			Money_Meta_Guard::saving( $order->get_id(), fn() => $order->save() );
		}
		if ( 'pos-open' === ( $data['status'] ?? '' ) && 'pos-open' !== ( $current['status'] ?? '' ) ) {
			Order_Notes::add_reopen_note( $order, $current_user, $order->get_meta( '_pos_store' ) );
		} else {
			if ( $cashier_changed ) {
				Order_Notes::add_cashier_change_note( $order, $old_user, $current_user );
			}
			if ( $store_changed ) {
				Order_Notes::add_store_change_note( $order, $old_store, $change['_pos_store'] );
			}
		}
		if ( array_key_exists( 'customer_id', $current ) && array_key_exists( 'customer_id', $data ) && (int) $current['customer_id'] !== (int) $data['customer_id'] ) {
			Order_Notes::add_pos_customer_change_note( $order, $current['customer_id'], $data['customer_id'] );
		}
	}

	/** Persist server-owned order audit metadata. */
	private function stamp_order_audit( int $id, array $payload, bool $stamp_version ): void {
		$meta = array( '_pos_user' => (string) get_current_user_id() );
		// Create-shaped phases only (create_after_identity, create_recovery, the
		// born-twice repair): the create push itself asserted the payment, so no
		// needs_payment() gate — by the time this runs post-forward the order is
		// already paid BY THIS PUSH. The update path carries its own guard.
		if ( self::asserts_payment( $payload ) ) {
			$meta[ self::PAYMENT_ASSERTED_META ] = self::PAYMENT_ASSERTED_OFFLINE;
		}
		if ( $stamp_version ) {
			$meta['_woocommerce_pos_version'] = VERSION;
			$meta['_pos_user_created']         = $meta['_pos_user'];
		}
		$payload_meta = is_array( $payload['meta_data'] ?? null ) ? $payload['meta_data'] : array();
		$this->store->persist_order_audit_meta( $id, array_merge( $meta, Pos_Order_Audit::till_meta_from_payload( $payload_meta ) ), 'woocommerce-pos' );
	}

	/** Persist missing cash-tender metadata after update. */
	private function stamp_order_till_meta( int $id, array $payload ): void {
		$meta = array();
		foreach ( is_array( $payload['meta_data'] ?? null ) ? $payload['meta_data'] : array() as $entry ) {
			$key   = Meta_Entry::key( $entry );
			$value = Meta_Entry::value( $entry ) ?? '';
			if ( is_scalar( $key ) && in_array( (string) $key, Pos_Order_Audit::cash_meta_keys(), true ) && is_scalar( $value ) && '' !== (string) $value ) {
				$meta[ (string) $key ] = (string) $value;
			}
		}
		// Provenance is written pre-insert (prepare_order_update_after_read); the
		// post-forward fill stays cash-only so a paid order never gains a key here.

		if ( $meta ) {
			$this->store->persist_order_audit_meta( $id, $meta );
		}
	}

	/** Strip server-owned audit metadata from a forward. */
	private function without_pos_audit_meta( array $payload, int $id = 0 ): array {
		$meta      = is_array( $payload['meta_data'] ?? null ) ? $payload['meta_data'] : array();
		$protected = $id > 0 ? Pos_Order_Audit::audit_meta_ids( wc_get_order( $id ) ) : array();
		return Pos_Order_Audit::strip_audit_meta( $meta, $protected );
	}

	/** Whether order stock was actually reduced before delete. */
	private function order_stock_reduced( int $id ): bool {
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order && (bool) $order->get_data_store()->get_stock_reduced( $id );
	}
}
