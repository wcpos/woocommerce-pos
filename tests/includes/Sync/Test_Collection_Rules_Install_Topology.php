<?php
/**
 * Tests for the Collection Rules install topology.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Both Read Lanes decide WHICH callbacks to install from the declaration table.
 *
 * The direct lane used to re-derive these two decisions from literals
 * (`isset( $request['wcpos_include'] )` and `'status' === $request['orderby']`),
 * so a row added to the table applied on the proxy lane and silently did not
 * apply on the direct lane. These pin the predicates the table now answers.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan::claims_id_sets
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan::needs_legacy_posts_orderby
 */
class Test_Collection_Rules_Install_Topology extends WP_UnitTestCase {
	/**
	 * Build an orders plan for a set of query params.
	 *
	 * @param array $params Query params.
	 *
	 * @return \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
	 */
	private function plan_for( array $params ) {
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/orders' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// Mirrors Orders_Controller::WCPOS_COLLECTION_PARAM_MAP, which is private.
		// Storage is pinned so these assertions do not depend on whether the test
		// site happens to have HPOS enabled — neither predicate reads storage.
		return Collection_Rules::for_request(
			'orders',
			$request,
			array(
				'orderby'     => 'orderby',
				'order'       => 'order',
				'include'     => 'wcpos_include',
				'exclude'     => 'wcpos_exclude',
				'pos_cashier' => 'pos_cashier',
				'pos_store'   => 'pos_store',
			),
			Collection_Rules::STORAGE_POSTS
		);
	}

	/**
	 * An include id set is claimed from the table.
	 */
	public function test_include_param_claims_an_id_set(): void {
		$this->assertTrue( $this->plan_for( array( 'wcpos_include' => array( 1, 2 ) ) )->claims_id_sets() );
	}

	/**
	 * An exclude id set is claimed from the table.
	 */
	public function test_exclude_param_claims_an_id_set(): void {
		$this->assertTrue( $this->plan_for( array( 'wcpos_exclude' => array( 3 ) ) )->claims_id_sets() );
	}

	/**
	 * A request naming neither claims no id set.
	 */
	public function test_request_without_id_params_claims_no_id_set(): void {
		$this->assertFalse( $this->plan_for( array( 'orderby' => 'date' ) )->claims_id_sets() );
	}

	/**
	 * A present-but-empty id set claims nothing.
	 *
	 * Narrower than the `isset()` literal this replaced, but not a behaviour
	 * change: both clause bodies already skip empty sets, so installing the
	 * callback for one appended nothing to the query either way.
	 */
	public function test_empty_id_set_claims_nothing(): void {
		$this->assertFalse( $this->plan_for( array( 'wcpos_include' => array() ) )->claims_id_sets() );
	}

	/**
	 * The status sort declares a legacy posts_orderby recipe.
	 */
	public function test_status_sort_needs_the_legacy_posts_orderby(): void {
		$this->assertTrue( $this->plan_for( array( 'orderby' => 'status' ) )->needs_legacy_posts_orderby() );
	}

	/**
	 * A sort with no posts_orderby recipe does not need the legacy rewrite.
	 */
	public function test_date_sort_does_not_need_the_legacy_posts_orderby(): void {
		$this->assertFalse( $this->plan_for( array( 'orderby' => 'date' ) )->needs_legacy_posts_orderby() );
	}

	/**
	 * A request claiming no sort at all does not need the legacy rewrite.
	 */
	public function test_request_without_a_sort_does_not_need_the_legacy_posts_orderby(): void {
		$this->assertFalse( $this->plan_for( array() )->needs_legacy_posts_orderby() );
	}
}
