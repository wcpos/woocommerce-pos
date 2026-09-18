<?php
/**
 * Tests for the sync collection registry.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Collections;
use WP_UnitTestCase;

/**
 * Collection policy tests adapted from the lab golden tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collections
 */
class Test_Collections extends WP_UnitTestCase {
	/**
	 * Every digest owner explicitly records repair support and explains absences.
	 *
	 * @see Integrity_Controller
	 */
	public function test_digest_registry_repair_capabilities_have_explicit_reasons(): void {
		// Arrange: these id-spaces back /wcpos/v2/integrity/scan.
		$rows = Collections::with( 'digest' );
		$this->assertTrue( $rows['products']['repair']['drill_down'] );
		$this->assertTrue( $rows['products']['repair']['self_heal'] );

		foreach ( $rows as $collection => $row ) {
			// Act: read the capability declaration used by the v2 repair lane.
			$this->assertArrayHasKey( 'repair', $row, $collection );
			$repair = $row['repair'];

			// Assert: null is deliberate, never an unmodeled absence.
			foreach ( array( 'drill_down', 'self_heal' ) as $capability ) {
				$this->assertArrayHasKey( $capability, $repair, $collection );
				$this->assertContains( $repair[ $capability ], array( true, null ) );
				if ( null === $repair[ $capability ] ) {
					$this->assertArrayHasKey( 'reason', $repair, $collection );
					$this->assertIsString( $repair['reason'] );
					$this->assertNotSame( '', trim( $repair['reason'] ), $collection );
				}
			}
		}
	}

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
