<?php
/**
 * Tests for the sync collection registry.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collections;
use WP_UnitTestCase;

/**
 * Collection policy tests adapted from the lab golden tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collections
 */
class Test_Collections extends WP_UnitTestCase {
	/**
	 * All canonical collections remain ordered and explicit.
	 */
	public function test_names_covers_the_ten_collections(): void {
		$this->assertSame(
			array( 'products', 'variations', 'orders', 'customers', 'categories', 'brands', 'tags', 'coupons', 'tax_rates', 'refunds' ),
			Collections::names()
		);
	}

	/**
	 * Unknown identifiers fail closed rather than defaulting to products.
	 */
	public function test_unknown_lookups_return_null(): void {
		$this->assertNull( Collections::row( 'nonsense' ) );
		$this->assertNull( Collections::by_object_type( 'surprise' ) );
		$this->assertNull( Collections::by_proxy_slug( 'tax_rates' ) );
	}

	/**
	 * Digest capability belongs only to the three id-space owners.
	 */
	public function test_digest_projection_has_three_id_space_owners(): void {
		$digest = Collections::with( 'digest' );

		$this->assertSame( array( 'products', 'orders', 'customers' ), array_keys( $digest ) );
		$this->assertSame( array( 'product', 'variation' ), $digest['products']['digest']['object_types'] );
	}

	/**
	 * Only journaled collections participate in the unified journal.
	 */
	public function test_journal_projection_has_the_explicit_journal_set(): void {
		$journal = Collections::with( 'journal' );

		$this->assertSame(
			array( 'products', 'variations', 'orders', 'customers', 'categories', 'brands', 'tags', 'coupons', 'tax_rates' ),
			array_keys( $journal )
		);
		$this->assertSame( array( 'object_type' => 'order' ), $journal['orders']['journal'] );
	}

	/**
	 * Refunds are proxied without identity, journal, digest, write or backfill work.
	 */
	public function test_refunds_are_read_only_without_sync_identity(): void {
		$row = Collections::row( 'refunds' );

		$this->assertNotNull( $row );
		foreach ( array( 'identity', 'journal', 'digest', 'write', 'backfill' ) as $capability ) {
			$this->assertArrayHasKey( $capability, $row );
			$this->assertNull( $row[ $capability ] );
		}
	}

	/**
	 * The canonical write surface exposes exactly the seven client-push collections.
	 */
	public function test_write_projection_has_the_contract_collections(): void {
		$this->assertSame(
			array( 'products', 'variations', 'orders', 'customers', 'categories', 'brands', 'coupons' ),
			array_keys( Collections::with( 'write' ) )
		);
	}
}
