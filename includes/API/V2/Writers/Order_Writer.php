<?php
/**
 * Order collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Stock_Validator;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Response;
use const WCPOS\WooCommercePOS\VERSION;

/** Owns order audit, tax, reassignment, hook, note, email, and stock behavior. */
class Order_Writer extends Null_Writer {
	/** @var object Mutation store used for HPOS-safe audit persistence. */
	private $store;

	/** @var Order_Write_Payload Order forward payload shaper. */
	private Order_Write_Payload $order_payload;

	/** Construct the order writer. */
	public function __construct( object $store, ?Order_Write_Payload $order_payload = null ) {
		$this->store         = $store;
		$this->order_payload = $order_payload ?? new Order_Write_Payload();
	}

	/** Prepare an order create and its create-only hook policy. */
	public function prepare_create( array $meta, array $payload, callable $validate_tax_ids ) {
		$created_gmt = $this->validate_client_created_gmt( $payload );
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
		$error = $validate_tax_ids( $payload );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return array(
			'method' => 'PUT',
			'route' => $meta['route'] . '/' . $id,
			'payload' => $payload,
			'context' => array(),
			'context_factory' => function () use ( $id, $payload ) {
				return $this->prepare_order_update_after_read( $id, $payload );
			},
		);
	}

	/** Repair an existing born-twice order without inventing a version stamp. */
	public function validate_existing_create( int $id, array $payload, array $prepared ) {
		$this->stamp_order_audit( $id, $payload, false );
		return null;
	}

	/** Forward within the named order hook lifecycle. */
	public function forward( array $prepared, callable $forward ) {
		return $this->forward_with_reserved_stock( $prepared, $forward );
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

	/** Persist the order behavior assigned to a controller-owned protocol phase. */
	public function persist( string $phase, int $id, array $payload, array $current = array(), array $response_data = array(), array $context = array() ): void {
		if ( 'create_before_identity' === $phase ) {
			$this->persist_tax_ids( $id, $payload, true );
		} elseif ( 'create_after_identity' === $phase ) {
			$this->stamp_order_audit( $id, $payload, true );
			$order = wc_get_order( $id );
			if ( $order ) {
				Order_Notes::add_creation_note( $order, get_current_user_id(), $order->get_meta( '_pos_store' ) );
			}
		} elseif ( 'create_recovery' === $phase ) {
			$this->stamp_order_audit( $id, $payload, false );
			$this->persist_tax_ids( $id, $payload, true );
		} elseif ( 'update' === $phase ) {
			$this->stamp_order_till_meta( $id, $payload );
			$this->persist_tax_ids( $id, $payload, false );
			$this->persist_cashier_store_reassignment( $id, $current, $response_data, $context );
			if ( ! empty( $context['clear_email'] ) ) {
				$order = wc_get_order( $id );
				if ( $order ) {
					$order->set_billing_email( '' );
					$order->get_data_store()->update( $order );
				}
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
		$context         = $prepared['context'];
		$forwarded_order = null;
		$pre_insert      = static function ( $order, $request, $creating ) use ( $context, &$forwarded_order ) {
			$is_create = 'create' === $context['operation'];
			if ( $is_create && $creating && $order instanceof \WC_Order && null === $forwarded_order ) {
				$forwarded_order = $order;
			}
			$target = $is_create ? ( $creating && $order === $forwarded_order ) : ( $order instanceof \WC_Order && $context['id'] === $order->get_id() );
			if ( $target ) {
				foreach ( $context['fill_meta'] as $key => $value ) {
					$order->update_meta_data( $key, $value );
				}
			}
			if ( $is_create && $creating && null !== $context['created_gmt'] && $order instanceof \WC_Order ) {
				$order->set_date_created( $context['created_gmt'] );
			}
			return $order;
		};
		$created_via = static function ( $order ) use ( &$forwarded_order ) {
			if ( $order instanceof \WC_Order && $order === $forwarded_order && 'woocommerce-pos' !== $order->get_created_via() ) {
				$order->set_created_via( 'woocommerce-pos' );
			}
		};
		$use_filter = 'create' === $context['operation'] || array() !== $context['fill_meta'];
		if ( $use_filter ) {
			add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $pre_insert, 10, 3 );
		}
		if ( 'create' === $context['operation'] ) {
			add_action( 'woocommerce_before_order_object_save', $created_via );
		}
		try {
			return $forward( $prepared['method'], $prepared['route'], $prepared['payload'] );
		} finally {
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

	/** Capture and authorize the cashier/store reassignment lifecycle. */
	private function prepare_order_update_after_read( int $id, array $payload ): array {
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
		$fill_meta = array();
		$pre_store = null;
		$order     = wc_get_order( $id );
		if ( $order ) {
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
			if ( $authorized && (string) $order->get_meta( '_pos_store' ) !== (string) $reassignment['_pos_store'] ) {
				$fill_meta['_pos_store'] = (string) $reassignment['_pos_store'];
			}
		}
		return array(
			'payload' => $this->order_payload->for_update( $id, $forward ),
			'context' => array(
				'operation' => 'update',
				'id' => $id,
				'clear_email' => $clear_email,
				'reassignment' => $reassignment,
				'store_authorized' => $authorized,
				'pre_store' => $pre_store,
				'fill_meta' => $fill_meta,
			),
		);
	}

	/** Persist reassignment and its mutually exclusive order-note policy. */
	private function persist_cashier_store_reassignment( int $id, array $current, array $data, array $context ): void {
		$order = wc_get_order( $id );
		if ( ! $order || ! is_array( $data ) ) {
			return;
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
			$order->save();
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

	/** Persist order tax IDs or the create-time customer snapshot. */
	private function persist_tax_ids( int $id, array $payload, bool $is_create ): void {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return;
		}
		if ( is_array( $payload['tax_ids'] ?? null ) ) {
			( new Tax_Id_Writer() )->write_for_order( $order, $payload['tax_ids'] );
		} elseif ( $is_create && $order->get_customer_id() > 0 ) {
			( new Tax_Id_Writer() )->snapshot_from_user_to_order( $order, $order->get_customer_id() );
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

	/** Validate and normalize the optional client create timestamp. */
	private function validate_client_created_gmt( array $payload ) {
		if ( ! isset( $payload['date_created_gmt'] ) ) {
			return null;
		}
		if ( ! is_scalar( $payload['date_created_gmt'] ) ) {
			return $this->invalid_created_gmt();
		}
		$value = wc_clean( wp_unslash( (string) $payload['date_created_gmt'] ) );
		if ( '' === $value ) {
			return null;
		}
		$timestamp = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/i', $value )
			? rest_parse_date( 'Z' === strtoupper( substr( $value, -1 ) ) ? $value : $value . 'Z', true ) : false;
		if ( false === $timestamp ) {
			return $this->invalid_created_gmt();
		}
		return $timestamp > time() + DAY_IN_SECONDS
			? new WP_Error( 'woocommerce_pos_rest_future_date_created_gmt', __( 'date_created_gmt cannot be more than 24 hours in the future.', 'woocommerce-pos' ), array( 'status' => 400 ) )
			: $timestamp;
	}

	/** Build the stable invalid create timestamp error. */
	private function invalid_created_gmt(): WP_Error {
		return new WP_Error( 'woocommerce_pos_rest_invalid_date_created_gmt', __( 'date_created_gmt must be a valid ISO 8601 UTC date.', 'woocommerce-pos' ), array( 'status' => 400 ) );
	}

	/** Whether order stock was actually reduced before delete. */
	private function order_stock_reduced( int $id ): bool {
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order && (bool) $order->get_data_store()->get_stock_reduced( $id );
	}
}
