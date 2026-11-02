<?php
/**
 * A variation's revision must not depend on the envelope shape it arrives in.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use ReflectionMethod;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * A bare variation must never degrade to a constant id revision (#1736).
 */
class Test_Variation_Revision_Shape_Independence extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Call the controller's private revision resolver.
	 *
	 * @param array $bare Document as the write path would hold it.
	 */
	private function revision_for( array $bare ): string {
		$method = new ReflectionMethod( Write_Controller::class, 'revision_for' );
		$method->setAccessible( true );

		return (string) $method->invoke(
			new Write_Controller(),
			array( 'post_type' => 'product_variation' ),
			4242,
			$bare
		);
	}

	/** Bare records hash content rather than falling back to a date or id. */
	public function test_revision_bare_stock_edits_with_same_date_change_hash(): void {
		// Arrange.
		$bare = array( 'id' => 4242, 'date_modified_gmt' => '2026-08-25T10:00:00', 'stock_quantity' => 10 );
		// Act.
		$first = $this->revision_for( $bare );
		$bare['stock_quantity'] = 9;
		$second = $this->revision_for( $bare );
		// Assert.
		$this->assertStringStartsWith( 'sha256:', $first );
		$this->assertNotSame( $first, $second );
		$this->assertNotSame( '4242', $first );
	}

	/** A dateless record still has a deterministic content hash, never an id fallback. */
	public function test_revision_dateless_record_hashes_content(): void {
		// Arrange.
		$bare = array( 'id' => 4242 );
		// Act.
		$revision = $this->revision_for( $bare );
		// Assert.
		$this->assertSame( \WCPOS\WooCommercePOS\Sync\Revision::compute_variation( $bare ), $revision );
		$this->assertNotSame( '4242', $revision );
	}
}
