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
 * The variation revision is `date_modified_gmt`, read from wherever the document carries it.
 *
 * # Why this is pinned directly rather than through a write
 *
 * The property under test is robustness to a document shape that does not exist YET. Today a
 * variation's document is the `{ id, parent_id, payload }` wrapper, so a nested-only read works and
 * a behavioural test cannot distinguish a correct implementation from the fragile one. The wrapper
 * is scheduled to be dropped (spec S3, sequenced behind a client release), and on a FLAT document
 * the old `$bare['payload']['date_modified_gmt'] ?? $bare['id']` chain silently degrades to the
 * variation's own id — a value that never changes again.
 *
 * That failure would be total and invisible. The grace comparer would still let a queued date-based
 * write through, the ack would hand the client the id as `currentRevision`, and from then on every
 * stale baseRevision would equal every recomputed one: two tills editing the same variation hours
 * apart would both pass the precondition, the per-record lock would serialize them, and there would
 * be no error — just a lost update, every time.
 *
 * So the invariant is pinned at the seam that owns it, via reflection, because there is no public
 * one and the whole point is to be correct before the shape changes.
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

	/**
	 * Today's wrapper document resolves to the nested date.
	 */
	public function test_wrapper_document_resolves_the_nested_date(): void {
		$revision = $this->revision_for(
			array(
				'id'        => 4242,
				'parent_id' => 11,
				'payload'   => array(
					'id'                => 4242,
					'date_modified_gmt' => '2026-08-25T10:00:00',
				),
			)
		);

		$this->assertSame( '2026-08-25T10:00:00', $revision );
	}

	/**
	 * A FLAT document resolves the same date — it must not fall back to the id.
	 */
	public function test_flat_document_resolves_the_top_level_date(): void {
		$revision = $this->revision_for(
			array(
				'id'                => 4242,
				'parent_id'         => 11,
				'date_modified_gmt' => '2026-08-25T10:00:00',
			)
		);

		$this->assertSame(
			'2026-08-25T10:00:00',
			$revision,
			'A flat variation document must resolve its own date, not degrade to the id.'
		);
		$this->assertNotSame( '4242', $revision, 'Falling back to the id makes the revision a constant.' );
	}

	/**
	 * Two saves of the same variation produce two different revisions, in both shapes.
	 *
	 * This is the property that actually matters — a revision that cannot change is not a
	 * precondition. An equality assertion on one document could pass with a constant.
	 */
	public function test_revision_changes_with_the_date_in_either_shape(): void {
		$nested_first  = $this->revision_for(
			array(
				'id' => 4242,
				'payload' => array( 'date_modified_gmt' => '2026-08-25T10:00:00' ),
			)
		);
		$nested_second = $this->revision_for(
			array(
				'id' => 4242,
				'payload' => array( 'date_modified_gmt' => '2026-08-25T10:00:01' ),
			)
		);
		$flat_first    = $this->revision_for(
			array(
				'id' => 4242,
				'date_modified_gmt' => '2026-08-25T10:00:00',
			)
		);
		$flat_second   = $this->revision_for(
			array(
				'id' => 4242,
				'date_modified_gmt' => '2026-08-25T10:00:01',
			)
		);

		$this->assertNotSame( $nested_first, $nested_second );
		$this->assertNotSame( $flat_first, $flat_second, 'A flat document whose date moved must produce a new revision.' );
		$this->assertSame( $nested_first, $flat_first, 'The same variation must revision identically in either shape.' );
	}

	/**
	 * A document carrying no date at all still yields something stable, not an empty string.
	 */
	public function test_dateless_document_falls_back_to_the_id(): void {
		$this->assertSame( '4242', $this->revision_for( array( 'id' => 4242 ) ) );
	}
}
