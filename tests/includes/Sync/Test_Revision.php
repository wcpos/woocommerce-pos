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
	 * Meta rows hash as an id-less set: ids and row order are storage facts, not content.
	 * Entries may be arrays or WC_Meta_Data objects; both reduce to the same pair. Applies
	 * at any depth (an order's line-item meta included).
	 */
	public function test_meta_row_ids_order_and_object_shape_do_not_change_revision(): void {
		$as_arrays = array(
			'name'       => 'Pants',
			'meta_data'  => array(
				array( 'id' => 11, 'key' => 'colour', 'value' => 'red' ),
				array( 'id' => 12, 'key' => 'size', 'value' => array( 'w' => 32, 'l' => 34 ) ),
			),
			'line_items' => array(
				array( 'id' => 1, 'meta_data' => array( array( 'id' => 21, 'key' => 'note', 'value' => 'gift' ) ) ),
			),
		);
		$resaved_reordered = $as_arrays;
		$resaved_reordered['meta_data'] = array(
			array( 'id' => 99, 'key' => 'size', 'value' => array( 'l' => 34, 'w' => 32 ) ),
			array( 'id' => 98, 'key' => 'colour', 'value' => 'red' ),
		);
		$resaved_reordered['line_items'][0]['meta_data'][0]['id'] = 77;
		$as_objects = $as_arrays;
		$as_objects['meta_data'] = array_map(
			static function ( array $entry ): \WC_Meta_Data {
				return new \WC_Meta_Data( $entry );
			},
			$as_arrays['meta_data']
		);

		$this->assertSame( Revision::compute( $as_arrays ), Revision::compute( $resaved_reordered ) );
		$this->assertSame( Revision::compute( $as_arrays ), Revision::compute( $as_objects ) );
	}

	/**
	 * A changed meta value, or a changed meta key, still changes the revision.
	 */
	public function test_meta_content_changes_change_revision(): void {
		$base = array( 'meta_data' => array( array( 'id' => 11, 'key' => 'colour', 'value' => 'red' ) ) );
		$changed_value = array( 'meta_data' => array( array( 'id' => 11, 'key' => 'colour', 'value' => 'blue' ) ) );
		$changed_key   = array( 'meta_data' => array( array( 'id' => 11, 'key' => 'color', 'value' => 'red' ) ) );

		$this->assertNotSame( Revision::compute( $base ), Revision::compute( $changed_value ) );
		$this->assertNotSame( Revision::compute( $base ), Revision::compute( $changed_key ) );
	}

	/**
	 * Generated creation and modification timestamps are not variation content.
	 */
	public function test_variation_revision_ignores_generated_dates(): void {
		$before = array(
			'name'              => 'Pants',
			'date_created'      => '2026-09-05T19:14:30',
			'date_created_gmt'  => '2026-09-05T19:14:30',
			'date_modified'     => '2026-09-05T19:14:30',
			'date_modified_gmt' => '2026-09-05T19:14:30',
		);
		$after = array_merge(
			$before,
			array(
				'date_created'      => '2026-09-05T19:14:31',
				'date_created_gmt'  => '2026-09-05T19:14:31',
				'date_modified'     => '2026-09-05T19:14:31',
				'date_modified_gmt' => '2026-09-05T19:14:31',
			)
		);

		$this->assertSame( Revision::compute_variation( $before ), Revision::compute_variation( $after ) );
		$after['name'] = 'Shirts';
		$this->assertNotSame( Revision::compute_variation( $before ), Revision::compute_variation( $after ) );
	}

	/**
	 * Taxonomy lists without an id on every entry retain their order.
	 */
	public function test_idless_taxonomy_term_order_changes_revision(): void {
		$original = array(
			'categories' => array(
				array( 'id' => 66 ),
				array( 'name' => 'Unresolved term' ),
			),
		);
		$reordered = $original;

		$reordered['categories'] = array_reverse( $reordered['categories'] );

		$this->assertNotSame( Revision::compute( $original ), Revision::compute( $reordered ) );
	}

	/**
	 * Fractional ids are outside the integer term-id contract and retain order.
	 */
	public function test_fractional_taxonomy_term_id_order_changes_revision(): void {
		$original = array(
			'categories' => array(
				array( 'id' => 2.1 ),
				array( 'id' => 1.9 ),
			),
		);
		$reordered = $original;

		$reordered['categories'] = array_reverse( $reordered['categories'] );

		$this->assertNotSame( Revision::compute( $original ), Revision::compute( $reordered ) );
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
