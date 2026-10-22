<?php
/**
 * Order collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Money;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Payments\Contract\Sale_State;
use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Order_Write_Intent;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Quick_Discount;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Stock_Validator;
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
	 * The statuses a till may push (ADR 0040 D4): the open statuses and cancel. `pos-partial`
	 * is derived from the ledger, and `failed` and every paid status are the server's.
	 */
	public const PUSHABLE_STATUSES = array( 'pos-open', 'pending', 'on-hold', 'cancelled' );

	/**
	 * Payload keys that can move money on the order: a push carrying one is serialised against the
	 * money commands. Billing and shipping count because wc/v3 recalculates the totals when either is sent.
	 */
	private const MONEY_SENSITIVE_KEYS = array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines', 'currency', 'status', 'customer_id', 'date_created_gmt', 'billing', 'shipping' );

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
		$payload = self::with_pushable_status( $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$forward                = self::without_money( $payload );
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
		return array(
			'method' => 'POST',
			'route' => $meta['route'],
			'payload' => $this->order_payload->for_create( $forward ),
			'context' => array(
				'operation' => 'create',
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
		// The pushable-status check runs on the locked pre-read: a full document echoes the
		// order's current status, and an unchanged status is not a push of that status.
		return array(
			'method' => 'PUT',
			'route' => $meta['route'] . '/' . $id,
			'payload' => $payload,
			'context' => array(),
			'context_factory' => function () use ( $id, $payload, $created_gmt ) {
				return $this->prepare_order_update_after_read( $id, $payload, $created_gmt );
			},
		);
	}

	/** Repair an existing born-twice order without inventing a version stamp. */
	public function validate_existing_create( int $id, array $payload, array $prepared ) {
		$this->stamp_order_audit( $id, $payload, false );
		return null;
	}

	/**
	 * Hold the order lock (the one the money commands and the ledger keeper hold) from the
	 * revision read through the wc/v3 write when the push can move money (ADR 0040 rule 6b),
	 * so a capture cannot land between the compare and the write. The Mutation_Store record
	 * lock is already held; Order_Lock falls back to its option lease on servers that cannot
	 * nest named locks. A note-only push takes no order lock.
	 */
	public function with_write_lock( int $id, array $payload, callable $work ) {
		if ( ! array_intersect( self::MONEY_SENSITIVE_KEYS, array_keys( $payload ) ) ) {
			return $work();
		}
		return self::under_order_lock( $id, $work );
	}

	/** A delete always takes the order lock: it is refused while money is in motion (rule 3), as a cancel is. */
	public function with_delete_lock( int $id, callable $work ) {
		return self::under_order_lock( $id, $work );
	}

	/** Run the work holding the order lock; a busy lock is a retryable 409. */
	private static function under_order_lock( int $id, callable $work ) {
		if ( ! Order_Lock::instance()->acquire( $id ) ) {
			return new WP_Error( 'woo_rxdb_sync_record_locked', 'Record is being written; retry shortly.', array( 'status' => 409 ) );
		}
		try {
			return $work();
		} finally {
			Order_Lock::instance()->release( $id );
		}
	}

	/** Forward within the named order hook lifecycle. */
	public function forward( array $prepared, callable $forward ) {
		$declared = $prepared['context'];
		if ( ! in_array( $declared['operation'] ?? '', array( 'create', 'update' ), true ) ) {
			return $this->forward_with_reserved_stock( $prepared, $forward );
		}
		$payload = $prepared['payload'];
		$declared['requested_status'] = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$prepared['context']          = $declared;
		if ( isset( $declared['id'] ) ) {
			Ledger::save_was_refused( (int) $declared['id'] ); // A stale refusal from earlier in this request must not leak.
		}
		$response = Order_Write_Intent::open(
			$declared,
			function () use ( $prepared, $forward ) {
				return $this->forward_with_reserved_stock( $prepared, $forward );
			}
		);
		$id = 0;
		if ( $response instanceof WP_REST_Response && $response->get_status() >= 200 && $response->get_status() < 300 ) {
			$data = $response->get_data();
			$id   = (int) ( ( is_array( $data ) ? ( $data['id'] ?? null ) : null ) ?? $declared['id'] ?? 0 );
		}
		// WooCommerce swallows the keeper's refusal and answers 2xx with nothing written.
		if ( $id > 0 && Ledger::save_was_refused( $id ) ) {
			return new WP_Error( 'woo_rxdb_sync_record_locked', 'Order save was refused while its payment ledger was locked; retry shortly.', array( 'status' => 409 ) );
		}
		return $response;
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
	 * wc/v3 is about to write, so a payment that completed in between wins — the same
	 * shape as the provenance re-decision. A restart push is money-sensitive (it carries
	 * date_created_gmt), so both decisions run under the order lock (with_write_lock()).
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
		$status  = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$validator = Stock_Validator::instance();

		// The push carries no money (ADR 0040), so `set_paid` is always false here: the one
		// pushable status that is not stock-exempt (`on-hold`) is created pending, held, then applied.
		if ( 'create' !== $context['operation']
			|| ! SettingsService::instance()->prevent_overselling_enabled()
			|| ! $validator->should_validate_create_payload( $status, false ) ) {
			return $this->forward_with_order_lifecycle( $prepared, $forward );
		}

		$response = null;
		$order    = $validator->around_paid_create(
			array( 'status' => $status ),
			function ( array $neutralised ) use ( $prepared, $forward, &$response ) {
				$prepared['payload']['status'] = $neutralised['status'];
				$response                      = $this->forward_with_order_lifecycle( $prepared, $forward );
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

	/** Restore audit metadata and tax IDs after recovery. */
	public function after_recovery( int $id, array $payload ): void {
		$this->stamp_order_audit( $id, $payload, false );
		$this->order_payload->persist_tax_ids( $id, $payload, true );
	}

	/** Persist till metadata, tax IDs, reassignment, and email changes after update. */
	public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void {
		$this->stamp_order_till_meta( $id, $payload );
		$this->order_payload->persist_tax_ids( $id, $payload, false );
		$this->persist_cashier_store_reassignment( $id, $current, $response_data, $context );
		if ( ! empty( $context['frozen_addresses'] ) ) {
			// A non-editable order's address change lands without wc/v3's calculate_totals().
			$order = wc_get_order( $id );
			if ( $order ) {
				foreach ( $context['frozen_addresses'] as $type => $address ) {
					$order->set_address( $address, $type );
				}
				Ledger::saving( $id, fn() => $order->save() );
			}
		}
		if ( ! empty( $context['clear_email'] ) ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->set_billing_email( '' );
				$order->get_data_store()->update( $order );
			}
		}
	}

	/**
	 * Execute delete with the named stock restore/rollback lifecycle. Run under the order lock
	 * (with_delete_lock()), a trash or a force delete is refused while money is in motion
	 * (`wcpos_money_in_motion`, rule 3), before any stock moves: the sweeper still has to resolve
	 * that money. Captured money does not block, as on cancel.
	 */
	public function delete( array $meta, int $id, array $mutation, callable $dispatch, callable $can_delete ) {
		$order  = wc_get_order( $id );
		$motion = $order ? Ledger::instance()->sale_state( $order )->in_motion() : null;
		if ( null !== $motion ) {
			return self::in_motion_refusal( $motion );
		}
		$force = (bool) ( $mutation['force'] ?? false );
		return $this->forward_with_stock_restore_rollback(
			$this->delete_request( $meta['route'] . '/' . $id, $id, $force ),
			$id,
			$force,
			$dispatch,
			$can_delete
		);
	}

	/** Read the order as both lanes compare it (Order_Serializer::compared_read()). */
	public function document( array $meta, int $id, callable $default_document ) {
		return Order_Serializer::compared_read( $id );
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
		// wc/v3 adjusts a set line's stock only on processing/completed/on-hold (and restores a removed
		// line's reduction itself); a reopened sale is pos-open with its stock still reduced, so after the
		// save every line is brought to its quantity. An unchanged line is a no-op: `_reduced_stock` matches.
		// Every movement runs after the save, so a failed write moves nothing (rule 4b).
		$adjust_stock = static function ( $order, $request, $creating ) use ( $context, $prepared ) {
			if ( ! $creating && $order instanceof \WC_Order && $context['id'] === $order->get_id() && $request->get_route() === $prepared['route'] ) {
				self::adjust_line_stock( $order, $context['bindings'] ?? array() );
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
			if ( ! empty( $context['adjust_stock'] ) ) {
				add_action( 'woocommerce_rest_insert_shop_order_object', $adjust_stock, 10, 3 );
			}
			return $forward( $prepared['method'], $prepared['route'], $prepared['payload'] );
		} finally {
			$qd->clear();
			remove_action( 'woocommerce_rest_insert_shop_order_object', $persist_discount, 10 );
			remove_action( 'woocommerce_rest_insert_shop_order_object', $adjust_stock, 10 );
			if ( $use_filter ) {
				remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $pre_insert, 10 );
			}
			if ( 'create' === $context['operation'] ) {
				remove_action( 'woocommerce_before_order_object_save', $created_via );
			}
		}
	}

	/**
	 * D5: a sale a WooCommerce gateway paid outside the ledger (online, or before the ledger existed)
	 * shows that tender as a captured row once reopened, so its balance counts it and its refunds,
	 * and a later close completes on it. Written before the status moves, without deriving, so the
	 * reopen never completes the sale; a second reopen finds the row and adopts nothing.
	 *
	 * @param \WC_Order $order The order read under the push's order lock.
	 */
	private static function adopt_original_tender( \WC_Order $order ): ?WP_Error {
		$paid   = $order->get_date_paid( 'edit' );
		$method = (string) $order->get_payment_method( 'edit' );
		if ( null === $paid || '' === $method ) {
			return null;
		}
		foreach ( Ledger::instance()->read( $order ) as $row ) {
			if ( 'captured' === ( $row['status'] ?? '' ) ) {
				return null;
			}
		}
		$descriptor = Descriptor_Builder::instance()->get( $method );
		$at         = gmdate( 'c', $paid->getTimestamp() );
		$refunds    = array();
		$refunded   = 0;
		foreach ( $order->get_refunds() as $refund ) {
			$amount     = Money::normalize( $refund->get_amount() );
			$refunds[]  = array(
				'id'           => $refund->get_id(),
				'amount'       => $amount,
				'status'       => 'succeeded',
				'provider_ref' => null,
			);
			$refunded  += Money::minor( $amount );
		}
		$transaction = (string) $order->get_transaction_id( 'edit' );
		try {
			$adopted = Ledger::instance()->adopt(
				$order,
				array(
					'id'              => wp_generate_uuid4(),
					'source'          => 'woocommerce',
					'method_id'       => $method,
					'kind'            => $descriptor['kind'] ?? 'other',
					'provider'        => $descriptor['capture']['provider'] ?? null,
					'capture_mode'    => 'webview',
					'amount'          => $order->get_total(),
					'currency'        => $order->get_currency(),
					'status'          => 'captured',
					'created_at_gmt'  => $at,
					'captured_at_gmt' => $at,
					'provider_refs'   => array( 'transaction_id' => '' !== $transaction ? $transaction : null ),
					'cashier_id'      => (int) $order->get_meta( '_pos_user' ),
					'refunds'         => $refunds,
					'refunded_amount' => Money::format( $refunded ),
				),
				false,
				static fn( $stored ) => 'woocommerce' === ( $stored['source'] ?? null )
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'woo_rxdb_sync_record_locked', $error->getMessage(), array( 'status' => 409 ) );
		}
		return is_wp_error( $adopted ) ? $adopted : null;
	}

	/**
	 * After the save, move stock for a sale whose stock was already reduced. A line re-bound to
	 * another product first gives its old product back the reduction the pre-read recorded (wc/v3
	 * keeps the line's `_reduced_stock`); then every line is brought to its quantity with wc/v3's
	 * own helper and note. When the status change itself restored the order's stock (pending,
	 * cancelled), WooCommerce returned a re-bound line's reduction to its NEW product, so that
	 * unit is moved back to the old one. A helper that fails is logged and noted for the merchant,
	 * never rolled back: the write landed, and a retry would compare unchanged.
	 *
	 * @param \WC_Order $order    The saved order.
	 * @param array     $bindings Reduced lines as the pre-read saw them: id => [product, variation, reduced, name].
	 */
	private static function adjust_line_stock( \WC_Order $order, array $bindings ): void {
		$reduced_now = (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
		require_once WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			list( $product_id, $variation_id, $reduced, $name ) = $bindings[ $item->get_id() ] ?? array( 0, 0, 0, '' );
			if ( $reduced > 0 && $item instanceof \WC_Order_Item_Product
				&& ( $product_id !== $item->get_product_id() || $variation_id !== $item->get_variation_id() ) ) {
				if ( ! $reduced_now ) {
					self::move_stock( $order, $item->get_product(), $item->get_name(), $reduced, 'decrease' );
				}
				$moved = self::move_stock( $order, wc_get_product( $variation_id ? $variation_id : $product_id ), $name, $reduced, 'increase' );
				if ( ! $moved || ! $reduced_now ) {
					continue;
				}
				$item->update_meta_data( '_reduced_stock', '0' );
				$item->save();
			}
			if ( ! $reduced_now ) {
				continue;
			}
			$changed = wc_maybe_adjust_line_item_product_stock( $item );
			if ( is_array( $changed ) ) {
				/* translators: %s item name. */
				$order->add_order_note( sprintf( __( 'Adjusted stock: %s', 'woocommerce' ), sprintf( '%1$s (%2$s&rarr;%3$s)', $item->get_name(), $changed['from'], $changed['to'] ) ), 0, true );
			} elseif ( is_wp_error( $changed ) ) {
				$delta = wc_stock_amount( $item->get_quantity() ) - wc_stock_amount( $item->get_meta( '_restock_refunded_items', true ) ) - wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
				self::stock_not_adjusted( $order, $item->get_name(), $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(), $delta, $changed->get_error_message() );
			}
		}
	}

	/**
	 * Move a stock-managed product's stock by a line's reduction, with wc/v3's note; a failure is noted.
	 *
	 * @param \WC_Order       $order     The order.
	 * @param \WC_Product|null $product   The product, or null/false when it is gone.
	 * @param string          $name      Line name for the note.
	 * @param int|float       $quantity  Units to move.
	 * @param string          $operation `increase` or `decrease`.
	 * @return bool Whether the stock moved (or needed no move: an unmanaged or missing product).
	 */
	private static function move_stock( \WC_Order $order, $product, string $name, $quantity, string $operation ): bool {
		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() ) {
			return true;
		}
		// wc_update_product_stock() answers the new stock, or false/null when it could not update it.
		$to = wc_update_product_stock( $product, $quantity, $operation );
		if ( ! is_numeric( $to ) ) {
			self::stock_not_adjusted( $order, $name, $product->get_id(), 'increase' === $operation ? $quantity : -$quantity, 'wc_update_product_stock() did not update the stock' );
			return false;
		}
		$from = 'increase' === $operation ? $to - $quantity : $to + $quantity;
		/* translators: %s item name. */
		$order->add_order_note( sprintf( __( 'Adjusted stock: %s', 'woocommerce' ), sprintf( '%1$s (%2$s&rarr;%3$s)', $name, $from, $to ) ), 0, true );
		return true;
	}

	/**
	 * Log and note a stock movement that did not happen, for the merchant to reconcile.
	 *
	 * @param \WC_Order $order      The order.
	 * @param string    $name       Line name.
	 * @param int       $product_id Product whose stock did not move.
	 * @param int|float $delta      Units the stock should have moved by (positive back into stock).
	 * @param string    $message    The helper's error.
	 */
	private static function stock_not_adjusted( \WC_Order $order, string $name, int $product_id, $delta, string $message ): void {
		Logger::error( sprintf( 'WCPOS: stock not adjusted for %1$s (#%2$d) on order #%3$d: %4$s', $name, $product_id, $order->get_id(), $message ) );
		/* translators: 1: line name, 2: units. */
		$order->add_order_note( sprintf( __( 'WCPOS: stock for %1$s could not be adjusted by %2$s; reconcile the product\'s stock manually.', 'woocommerce-pos' ), $name, $delta ), 0, true );
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
	 * @return array|WP_Error The late payload and context, or the refusal.
	 */
	private function prepare_order_update_after_read( int $id, array $payload, ?int $created_gmt = null ) {
		$order   = wc_get_order( $id );
		$payload = self::with_pushable_status( $payload, $order ? $order->get_status() : null );
		if ( is_wp_error( $payload ) ) {
			return $payload;
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
		$forward = self::without_money( $payload );
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
		if ( $order ) {
			// Cart restart (wcpos/monorepo#2183): a first decision on the pre-read;
			// the binding one is re-taken at pre-insert, see cart_restart_gmt().
			$restart_gmt = self::cart_restart_gmt( $order, $created_gmt );
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
		$forward       = $this->order_payload->for_update( $id, $forward );
		$lines_changed = $order && $this->order_payload->lines_changed( $order, $forward );
		// One state from the locked pre-read; adopt_original_tender() moves neither the status nor the completion.
		$state         = $order ? Ledger::instance()->sale_state( $order ) : null;
		$reopens       = $state && $state->reopens( (string) ( $forward['status'] ?? '' ) );
		$refusal       = $state ? $this->money_gate( $order, $state, $forward, $lines_changed ) : null;
		if ( null === $refusal && $reopens ) {
			$refusal = self::adopt_original_tender( $order );
		}
		if ( null !== $refusal ) {
			return $refusal;
		}
		// A non-editable order that is not being reopened keeps its lines and totals: wc/v3 recalculates
		// whenever lines or an address are posted, which would move a paid order's tax. Its unchanged
		// lines are not forwarded and its addresses are applied after the write, without a recalculation.
		if ( $state && $state->locked() && ! $reopens && ! $lines_changed ) {
			unset( $forward['line_items'], $forward['fee_lines'], $forward['shipping_lines'], $forward['coupon_lines'] );
			$context['frozen_addresses'] = array_filter( array_intersect_key( $forward, array_flip( array( 'billing', 'shipping' ) ) ), 'is_array' );
			unset( $forward['billing'], $forward['shipping'] );
			$email = $context['frozen_addresses']['billing']['email'] ?? '';
			if ( ! is_scalar( $email ) || ( '' !== (string) $email && ! is_email( (string) $email ) ) ) {
				return new WP_Error( 'rest_invalid_param', 'Invalid parameter(s): billing', array( 'status' => 400 ) );
			}
		}
		// Stock already reduced (a paid or reopened sale): the line edit moves stock by its delta (rule 4b).
		$context['adjust_stock'] = $lines_changed && (bool) $order->get_data_store()->get_stock_reduced( $id );
		// wc/v3 saves an updated line before the pre-insert filters run: keep each reduced line's binding as read.
		$context['bindings'] = array();
		foreach ( $context['adjust_stock'] ? $order->get_items( 'line_item' ) : array() as $item ) {
			$reduced = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
			if ( $reduced > 0 && $item instanceof \WC_Order_Item_Product ) {
				$context['bindings'][ $item->get_id() ] = array( $item->get_product_id(), $item->get_variation_id(), $reduced, $item->get_name() );
			}
		}
		return array(
			'payload' => $forward,
			'context' => $context,
		);
	}

	/**
	 * Refuse a push the order may not take, on the locked read (ADR 0040 rules 3, 4 and 6b).
	 *
	 * A currency change is refused once any money exists on the order (`wcpos_currency_locked`,
	 * rule 6b): there is no conversion protocol, and a 100 USD row must not cover a 100 EUR total.
	 * A reopen (a non-editable order pushed into an editable status) or a cancel is refused
	 * while money is in motion (`wcpos_money_in_motion`, rule 3); captured, failed and voided
	 * rows never block, and nothing is archived. A line change needs an editable order
	 * (`reopen_required`, rule 4) unless the same push reopens it (D3: reopen and edit are one
	 * write); `cancelled` never unlocks lines.
	 *
	 * @param \WC_Order  $order         The order read under the order lock.
	 * @param Sale_State $state         Its state, from the same read.
	 * @param array      $forward       The forwarded update payload.
	 * @param bool       $lines_changed Whether the forward changes the order's money lines.
	 */
	private function money_gate( \WC_Order $order, Sale_State $state, array $forward, bool $lines_changed ): ?WP_Error {
		$currency = isset( $forward['currency'] ) && is_scalar( $forward['currency'] ) ? (string) $forward['currency'] : '';
		if ( '' !== $currency && $currency !== $order->get_currency( 'edit' ) && $state->has_money() ) {
			return new WP_Error( 'wcpos_currency_locked', 'The currency cannot change once a payment exists on this order.', array( 'status' => 422 ) );
		}
		$status  = (string) ( $forward['status'] ?? '' );
		$reopens = $state->reopens( $status );
		$cancels = 'cancelled' === $status && 'cancelled' !== $order->get_status();
		$motion  = ( $reopens || $cancels ) ? $state->in_motion() : null;
		if ( null !== $motion ) {
			return self::in_motion_refusal( $motion );
		}
		if ( $state->locked() && ! $reopens && $lines_changed ) {
			return new WP_Error(
				'reopen_required',
				'Reopen the order before changing its lines.',
				array(
					'status'       => 422,
					'order_status' => $order->get_status(),
				)
			);
		}
		return null;
	}

	/**
	 * The push lane's refusal for money in motion: both keys present, the one not in motion null.
	 *
	 * @param array $motion {@see Sale_State::in_motion()}.
	 */
	private static function in_motion_refusal( array $motion ): WP_Error {
		$named = array(
			'payment_id' => $motion['payment_id'],
			'attempt_id' => null === $motion['payment_id'] ? (string) $motion['attempt_id'] : null,
		);
		return new WP_Error( 'wcpos_money_in_motion', 'A payment on this order is still in progress; finish or cancel it first.', array( 'status' => 422 ) + $named );
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
			Ledger::saving( $order->get_id(), fn() => $order->save() );
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

	/**
	 * The payload with its `status` normalised (no `wc-` prefix), or a 422 when the till may not push it.
	 * An absent or empty status is untouched (wc/v3 defaults a create to `pending`); on an update, the
	 * order's own status echoed back by a full document is dropped from the forward, not refused.
	 *
	 * @param array       $payload Client payload.
	 * @param string|null $current The order's status on the locked read, or null on create.
	 * @return array|WP_Error
	 */
	private static function with_pushable_status( array $payload, ?string $current = null ) {
		if ( ! isset( $payload['status'] ) || '' === $payload['status'] ) {
			return $payload;
		}
		$status = is_scalar( $payload['status'] ) ? (string) $payload['status'] : '';
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		if ( null !== $current && $status === $current && ! in_array( $status, self::PUSHABLE_STATUSES, true ) ) {
			unset( $payload['status'] );
			return $payload;
		}
		if ( ! in_array( $status, self::PUSHABLE_STATUSES, true ) ) {
			return new WP_Error(
				'wcpos_status_not_pushable',
				'This order status is set by the server, not pushed by the till.',
				array(
					'status'    => 422,
					'requested' => $status,
					'pushable'  => self::PUSHABLE_STATUSES,
				)
			);
		}
		$payload['status'] = $status;
		return $payload;
	}

	/** Drop every top-level money field: the push lane carries no money (ADR 0040 rule 2). */
	private static function without_money( array $payload ): array {
		foreach ( Pos_Order_Audit::SERVER_MONEY_FIELDS as $field ) {
			unset( $payload[ $field ] );
		}
		return $payload;
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
