<?php
/**
 * Stored digest fixture helper.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

trait Seeds_Stored_Digests {
	/** Seed a collection's stored rows and fail at the fixture if any are missing. */
	protected function seed_digests( string $collection, int ...$ids ): void {
		$methods = array(
			'products'   => 'record_post_saved',
			'variations' => 'record_post_saved',
			'orders'     => 'record_order_saved',
			'customers'  => 'record_customer_saved',
		);
		$digest = new Integrity_Digest();
		foreach ( $ids as $id ) {
			$digest->{$methods[ $collection ]}( $id );
		}
		Integrity_Digest::flush_pending_digests();
		$rows = ( new Digest_Index() )->read_digests( $collection, $ids );
		foreach ( $ids as $id ) {
			$this->assertArrayHasKey( $id, $rows, "Missing seeded {$collection} digest for {$id}" );
		}
	}
}
