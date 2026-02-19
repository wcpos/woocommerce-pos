<?php
/**
 * Immutable receipt snapshot store.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Receipt_Snapshot_Store class.
 */
class Receipt_Snapshot_Store {
	/**
	 * Snapshot payload meta key.
	 */
	const META_KEY_PAYLOAD = '_wcpos_receipt_snapshot';

	/**
	 * Snapshot checksum meta key.
	 */
	const META_KEY_CHECKSUM = '_wcpos_receipt_snapshot_checksum';

	/**
	 * Snapshot sequence meta key.
	 */
	const META_KEY_SEQUENCE = '_wcpos_receipt_sequence';

	/**
	 * Snapshot created timestamp meta key.
	 */
	const META_KEY_CREATED_AT = '_wcpos_receipt_snapshot_created_at_gmt';

	/**
	 * Snapshot option key for sequence counter.
	 */
	const OPTION_SEQUENCE = 'wcpos_receipt_sequence_counter';

	/**
	 * Singleton instance.
	 *
	 * @var null|self
	 */
	private static $instance = null;

	/**
	 * Data builder.
	 *
	 * @var Receipt_Data_Builder
	 */
	private $builder;

	/**
	 * Fiscal service.
	 *
	 * @var Fiscal_Receipt_Service
	 */
	private $fiscal_service;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->builder        = new Receipt_Data_Builder();
		$this->fiscal_service = new Fiscal_Receipt_Service();
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete' ) );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Handle payment complete event and persist snapshot if needed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $this->has_snapshot( $order_id ) ) {
			return;
		}

		$snapshot = $this->builder->build( $order, 'fiscal' );
		$this->persist_snapshot( $order_id, $snapshot );
	}

	/**
	 * Check if order has a stored snapshot.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	public function has_snapshot( int $order_id ): bool {
		$payload = get_post_meta( $order_id, self::META_KEY_PAYLOAD, true );

		return \is_string( $payload ) && '' !== $payload;
	}

	/**
	 * Get stored snapshot.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return null|array
	 */
	public function get_snapshot( int $order_id ): ?array {
		$payload = get_post_meta( $order_id, self::META_KEY_PAYLOAD, true );
		if ( ! \is_string( $payload ) || '' === $payload ) {
			return null;
		}

		$decoded = json_decode( $payload, true );
		if ( ! \is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Persist immutable snapshot fields.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $snapshot Snapshot payload.
	 */
	public function persist_snapshot( int $order_id, array $snapshot ): void {
		if ( $this->has_snapshot( $order_id ) ) {
			return;
		}

		$sequence = $this->next_sequence();
		$created  = current_time( 'mysql', true );

		$snapshot['meta']['created_at_gmt']   = $created;
		$snapshot['meta']['mode']             = 'fiscal';
		$snapshot['fiscal']['sequence']       = $sequence;
		$snapshot['fiscal']['receipt_number'] = (string) $sequence;
		$snapshot['fiscal']['immutable_id']   = $order_id . ':' . $sequence;

		$snapshot = $this->fiscal_service->enrich_snapshot( $snapshot, $order_id );

		$json     = wp_json_encode( $snapshot );
		$checksum = hash( 'sha256', (string) $json );

		update_post_meta( $order_id, self::META_KEY_PAYLOAD, $json );
		update_post_meta( $order_id, self::META_KEY_CHECKSUM, $checksum );
		update_post_meta( $order_id, self::META_KEY_SEQUENCE, $sequence );
		update_post_meta( $order_id, self::META_KEY_CREATED_AT, $created );

		$this->fiscal_service->set_submission_status( $order_id, 'pending' );
	}

	/**
	 * Get the default mode from settings.
	 *
	 * @return string
	 */
	public function get_default_mode(): string {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );
		$mode              = \is_array( $checkout_settings ) ? ( $checkout_settings['receipt_default_mode'] ?? 'fiscal' ) : 'fiscal';

		return in_array( $mode, array( 'fiscal', 'live' ), true ) ? $mode : 'fiscal';
	}

	/**
	 * Resolve effective receipt mode.
	 *
	 * @param null|string $mode Requested mode.
	 *
	 * @return string
	 */
	public function resolve_mode( ?string $mode = null ): string {
		if ( in_array( $mode, array( 'fiscal', 'live' ), true ) ) {
			return $mode;
		}

		return $this->get_default_mode();
	}

	/**
	 * Get next sequence number.
	 *
	 * @return int
	 */
	private function next_sequence(): int {
		global $wpdb;

		$lock_name = 'wcpos_receipt_sequence_lock';
		$acquired  = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, 5 )
		);

		$sequence = (int) get_option( self::OPTION_SEQUENCE, 0 );
		$sequence++;
		update_option( self::OPTION_SEQUENCE, $sequence, false );

		if ( 1 === $acquired ) {
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name )
			);
		}

		return $sequence;
	}
}
