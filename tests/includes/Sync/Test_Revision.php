<?php
/**
 * Tests for sync revision canonicalization.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Revision;
use WP_UnitTestCase;

/**
 * Revision canonicalization tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Revision
 */
class Test_Revision extends WP_UnitTestCase {
	/**
	 * Build two distinct terms with the same display name.
	 */
	private function terms(): array {
		return array(
			array(
				'id'   => 66,
				'name' => 'Pants',
			),
			array(
				'id'   => 68,
				'name' => 'Pants',
			),
		);
	}

	/**
	 * Taxonomy terms are canonicalized by id at any depth.
	 */
	public function test_taxonomy_term_order_does_not_change_revision(): void {
		$terms = $this->terms();
		$first = array(
			'categories' => $terms,
			'nested'     => array(
				'tags'   => $terms,
				'brands' => array_map(
					static function ( array $term ): object {
						return (object) $term;
					},
					$terms
				),
			),
		);
		$second = $first;

		$second['categories']       = array_reverse( $second['categories'] );
		$second['nested']['tags']   = array_reverse( $second['nested']['tags'] );
		$second['nested']['brands'] = array_reverse( $second['nested']['brands'] );

		$this->assertSame( Revision::compute( $first ), Revision::compute( $second ) );
	}

	/**
	 * Taxonomy term membership remains part of the revision.
	 */
	public function test_taxonomy_term_changes_change_revision(): void {
		$original = array( 'categories' => $this->terms() );
		$changed  = $original;
		$removed  = $original;

		$changed['categories'][1]['id'] = 69;
		array_pop( $removed['categories'] );

		$this->assertNotSame( Revision::compute( $original ), Revision::compute( $changed ) );
		$this->assertNotSame( Revision::compute( $original ), Revision::compute( $removed ) );
	}

	/**
	 * Non-taxonomy list order remains part of the revision.
	 */
	public function test_image_order_changes_revision(): void {
		$original = array(
			'images' => array(
				array( 'id' => 10 ),
				array( 'id' => 11 ),
			),
		);
		$reordered = $original;

		$reordered['images'] = array_reverse( $reordered['images'] );

		$this->assertNotSame( Revision::compute( $original ), Revision::compute( $reordered ) );
	}
}
