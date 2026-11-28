<?php
/**
 * Product collection writer.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WC_Product;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;

/** Write stock last, relative to the compared value S0, so a concurrent sale is kept (#2853). */
class Product_Writer extends Null_Writer {
	/** @var array|null Deferred quantity and its compared base. */
	private $stock = null;

	/** Prepare the ordinary forward without an independently managed stock adjustment. */
	public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids ) {
		return $this->defer_stock( $id, parent::prepare_update( $meta, $id, $payload, $validate_tax_ids ) );
	}

	/** Defer only quantities WooCommerce would store unchanged on this record. */
	protected function defer_stock( int $id, array $prepared ): array {
		if ( ! array_key_exists( 'stock_quantity', $prepared['payload'] ) ) {
			return $prepared;
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() || true !== $product->get_manage_stock( 'edit' ) ) {
			return $prepared;
		}
		if ( array_key_exists( 'manage_stock', $prepared['payload'] ) && true !== $prepared['payload']['manage_stock'] ) {
			if ( false === $prepared['payload']['manage_stock'] ) {
				unset( $prepared['payload']['stock_quantity'] );
			}
			return $prepared;
		}
		$value = $prepared['payload']['stock_quantity'];
		if ( null !== $value && '' !== $value && is_numeric( $value ) && (float) wc_stock_amount( $value ) === (float) $value ) {
			$this->stock = array( 'pushed' => $value );
			unset( $prepared['payload']['stock_quantity'] );
		}
		return $prepared;
	}

	/** Keep the quantity from the revision compare, not a post-forward read. */
	public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void {
		if ( null !== $this->stock ) {
			$this->stock['s0'] = $current['stock_quantity'] ?? null;
		}
	}

	/** Consume the pending write once, only after a successful forward. */
	public function with_write_lock( int $id, array $payload, callable $work ) {
		$result      = parent::with_write_lock( $id, $payload, $work );
		$pending     = $this->stock;
		$this->stock = null;
		if ( null !== $result || null === $pending || ! array_key_exists( 's0', $pending ) ) {
			return $result;
		}
		return $this->write_stock( $id, $pending['pushed'], $pending['s0'] );
	}

	/** Apply the adjustment atomically, or set the quantity if there was no numeric base. */
	private function write_stock( int $id, $pushed, $s0 ) {
		$target = wc_stock_amount( $pushed );
		try {
			if ( is_numeric( $s0 ) ) {
				$delta = $target - wc_stock_amount( $s0 );
				if ( 0.0 === (float) $delta ) {
					return null;
				}
				$result = wc_update_product_stock( $id, abs( $delta ), $delta > 0 ? 'increase' : 'decrease' );
			} else {
				$result = wc_update_product_stock( $id, $target, 'set' );
			}
		} catch ( \Throwable $e ) {
			Logger::warning( sprintf( 'Stock write failed for record %d: %s', $id, $e->getMessage() ) );
			$result = false;
		}
		if ( false === $result ) {
			return new WP_Error( 'woo_rxdb_sync_stock_write_failed', 'The record was saved but its stock was not written. Re-read the record before retrying.', array( 'status' => 500 ) );
		}
		return null;
	}
}
