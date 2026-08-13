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
	public function test_names_covers_the_nine_collections(): void {
		$this->assertSame(
			array( 'products', 'variations', 'orders', 'customers', 'categories', 'brands', 'tags', 'coupons', 'tax_rates' ),
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
	 * Every collection declares how it participates in the unified journal.
	 */
	public function test_journal_projection_covers_all_collections(): void {
		$journal = Collections::with( 'journal' );

		$this->assertSame( Collections::names(), array_keys( $journal ) );
		$this->assertSame( array( 'object_type' => 'order' ), $journal['orders']['journal'] );
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
