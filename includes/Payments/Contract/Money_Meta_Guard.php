<?php
/**
 * Server-owned money metadata on every order-save lane.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

// phpcs:disable Squiz.Commenting -- Compact save-boundary guard.
namespace WCPOS\WooCommercePOS\Payments\Contract;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;

final class Money_Meta_Guard {
	private static $instance;
	private array $locks = array();
	private static array $authorised = array();
	private const KEYS = array( '_wcpos_money_owner', '_wcpos_money_epoch', '_wcpos_completed_at', '_wcpos_completed_epoch', '_wcpos_completed_session', '_wcpos_epoch_provenance', '_wcpos_reopened_at', '_wcpos_awaiting_customer', '_wcpos_gateway_attempts', '_wcpos_money_hold' );

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function register_hooks(): void {
		// Restore the stored epoch before the ledger keeper stamps new rows with it.
		add_action( 'woocommerce_before_order_object_save', array( self::instance(), 'keep_stored' ), 9 );
		add_action( 'woocommerce_after_order_object_save', array( self::instance(), 'release_lock' ) );
		add_action( 'shutdown', array( self::instance(), 'release_lock' ), 10, 0 );
	}

	public static function is_authorised( int $order_id ): bool {
		return ! empty( self::$authorised[ $order_id ] );
	}

	public static function authorised( int $order_id, callable $fn ) {
		self::$authorised[ $order_id ] = ( self::$authorised[ $order_id ] ?? 0 ) + 1;
		try {
			return self::saving( $order_id, $fn );
		} finally {
			if ( 0 === --self::$authorised[ $order_id ] ) {
				unset( self::$authorised[ $order_id ] );
			}
		}
	}

	/**
	 * WC catches save exceptions without an unconditional failure hook. Bound our
	 * keeper acquisitions to plugin-owned work; third-party saves fall back to shutdown.
	 * This does not authorise metadata or release an enclosing caller's acquisitions.
	 */
	public static function saving( int $order_id, callable $fn ) {
		$guard = self::instance();
		$count = $guard->locks[ $order_id ] ?? 0;
		try {
			return Ledger::instance()->with_save_lock_cleanup( $order_id, $fn );
		} finally {
			while ( ( $guard->locks[ $order_id ] ?? 0 ) > $count ) {
				Order_Lock::instance()->release( $order_id );
				if ( 0 === --$guard->locks[ $order_id ] ) {
					unset( $guard->locks[ $order_id ] );
				}
			}
		}
	}

	/** WC's public reader hides deletions; both save guards must see tombstones. */
	public static function raw_meta( WC_Order $order ): array {
		$order->get_meta_data();
		$property = new \ReflectionProperty( \WC_Data::class, 'meta_data' );
		$property->setAccessible( true );
		return $property->getValue( $order ) ?? array();
	}

	public function keep_stored( $order ): void {
		if ( ! $order instanceof WC_Order || ! empty( self::$authorised[ $order->get_id() ] ) ) {
			return;
		}
		$raw = self::raw_meta( $order );
		$raw = array_filter( $raw, static fn( $meta ) => ! $meta->id || $meta->get_changes() || null === $meta->value );
		if ( ! $raw ) {
			return;
		}
		// A renamed persisted row may have been a protected key: lock before resolving its id.
		$pending = array_filter( $raw, static fn( $meta ) => in_array( $meta->key, self::KEYS, true ) || ( $meta->id && isset( $meta->get_changes()['key'] ) ) );
		$id = $order->get_id();
		if ( $id && $pending ) {
			if ( Order_Lock::instance()->acquire( $id ) ) {
				$this->locks[ $id ] = ( $this->locks[ $id ] ?? 0 ) + 1;
			} else {
				$message = sprintf( 'WCPOS money meta locked on order #%d; the save was refused', $id );
				Logger::error( $message, array( 'order_id' => $id ) );
				throw new \RuntimeException( esc_html( $message ) );
			}
		}
		$stored = $order->get_id() ? $order->get_data_store()->read_meta( $order ) : array();
		$protected = array();
		foreach ( $stored as $meta ) {
			if ( in_array( $meta->meta_key, self::KEYS, true ) ) {
				$protected[ $meta->meta_id ] = $meta;
			}
		}
		$dirty = array();
		foreach ( $raw as $meta ) {
			if ( ! $meta->id || $meta->get_changes() || null === $meta->value ) {
				if ( in_array( $meta->key, self::KEYS, true ) ) {
					$dirty[ $meta->key ] = true;
				}
				if ( isset( $protected[ $meta->id ] ) ) {
					$dirty[ $protected[ $meta->id ]->meta_key ] = true;
					$order->delete_meta_data_by_mid( $meta->id );
				}
			}
		}
		foreach ( array_keys( $dirty ) as $key ) {
			$order->delete_meta_data( $key );
			foreach ( $protected as $meta ) {
				if ( $key === $meta->meta_key ) {
					$order->update_meta_data( $key, maybe_unserialize( $meta->meta_value ), $meta->meta_id );
				}
			}
		}
	}
	/** Release this guard's acquisition after save, or outstanding acquisitions at shutdown. */
	public function release_lock( $order = null ): void {
		if ( null !== $order && ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( null === $order ? $this->locks : array( $order->get_id() => 1 ) as $id => $count ) {
			if ( ! isset( $this->locks[ $id ] ) ) {
				continue;
			}
			for ( $i = 0; $i < $count; ++$i ) {
				Order_Lock::instance()->release( $id );
				if ( 0 === --$this->locks[ $id ] ) {
					unset( $this->locks[ $id ] );
				}
			}
		}
	}
}
