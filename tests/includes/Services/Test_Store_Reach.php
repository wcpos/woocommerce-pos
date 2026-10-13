<?php
/**
 * Store reach contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Store_Reach;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Store authorization is independent of caller query filters. */
class Test_Store_Reach extends WCPOS_REST_Unit_Test_Case {
	/** Resolve one filter answer, always removing the hook.
	 *
	 * @param mixed $answer Filter answer.
	 */
	private function reach( $answer ): Store_Reach {
		// Arrange.
		$filter = static function () use ( $answer ) {
			return $answer;
		};
		add_filter( Store_Reach::FILTER, $filter );
		try {
			// Act.
			return Store_Reach::for_request( $this->wp_rest_get_request( '/wcpos/v2/closures' ) );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** Missing store keys leave every row and query unchanged. */
	public function test_store_reach_without_store_id_key_is_unrestricted(): void {
		// Arrange / Act.
		$unfiltered = Store_Reach::for_request( $this->wp_rest_get_request( '/wcpos/v2/closures' ) );
		foreach ( array( $unfiltered, $this->reach( array( 'foo' => 1 ) ) ) as $reach ) {
			// Assert.
			$this->assertNull( $reach->store_ids() );
			$this->assertFalse( $reach->is_malformed() );
			$this->assertTrue( $reach->admits( null ) );
			$this->assertTrue( $reach->admits( 5 ) );
			$this->assertSame( array( 'x' => 1 ), $reach->narrow( array( 'x' => 1 ) ) );
		}
	}

	/** The seam sees an empty base and the original request. */
	public function test_store_reach_reads_the_filter_from_an_empty_base(): void {
		// Arrange.
		$request = $this->wp_rest_get_request( '/wcpos/v2/closures' );
		$request->set_param( 'store_id', 7 );
		$seen = null;
		$filter = static function ( $args, $received ) use ( &$seen ) {
			$seen = array( $args, $received );
			return $args;
		};
		add_filter( Store_Reach::FILTER, $filter, 10, 2 );
		try {
			// Act.
			Store_Reach::for_request( $request );
			// Assert.
			$this->assertSame( array(), $seen[0] );
			$this->assertSame( $request, $seen[1] );
		} finally {
			remove_filter( Store_Reach::FILTER, $filter );
		}
	}

	/** Invalid containers never mean unrestricted. */
	public function test_store_reach_non_array_answer_denies_everything(): void {
		foreach ( array( 'nonsense', 7, null, false ) as $answer ) {
			// Arrange / Act.
			$reach = $this->reach( $answer );
			// Assert.
			$this->assertTrue( $reach->is_malformed() );
			$this->assertSame( array(), $reach->store_ids() );
			$this->assertFalse( $reach->admits( 1 ) );
		}
	}

	/** Validate before coercion, including the store each bad value would become. */
	public function test_store_reach_unreadable_restriction_denies_everything(): void {
		foreach ( array( 'invalid', false, null, 0, -1, true, 1.0, 1.9, '1.9', ' 1', '1e2', '007', '1 ', '+1', "1\n", array( 1 ), '1234567890123456789' ) as $bad ) {
			// Arrange / Act.
			$reach = $this->reach( array( 'store_id' => array( $bad ) ) );
			// Assert.
			$this->assertTrue( $reach->is_malformed(), var_export( $bad, true ) );
			$this->assertSame( array(), $reach->store_ids() );
			$this->assertFalse( $reach->admits( (int) $bad ) );
		}
	}

	/** Scalar and duplicate list values have one canonical form. */
	public function test_store_reach_scalar_and_list_restrictions_read_the_same(): void {
		foreach ( array( 7, '7', array( 7, '7' ) ) as $value ) {
			// Arrange / Act.
			$reach = $this->reach( array( 'store_id' => $value ) );
			// Assert.
			$this->assertSame( array( 7 ), $reach->store_ids() );
			$this->assertFalse( $reach->is_malformed() );
		}
	}

	/** Unassigned rows have no exemption from a restriction. */
	public function test_store_reach_never_admits_an_unassigned_row_under_a_restriction(): void {
		// Arrange / Act.
		$reach = $this->reach( array( 'store_id' => array( 7 ) ) );
		// Assert.
		$this->assertFalse( $reach->admits( null ) );
		$this->assertFalse( $reach->admits( 0 ) );
		$this->assertTrue( $reach->admits( 7 ) );
		$this->assertTrue( $reach->admits( '7' ) );
	}

	/** An empty restriction is valid and admits nothing. */
	public function test_store_reach_empty_list_is_a_restriction_not_a_fault(): void {
		// Arrange / Act.
		$reach = $this->reach( array( 'store_id' => array() ) );
		// Assert.
		$this->assertFalse( $reach->is_malformed() );
		$this->assertSame( array(), $reach->store_ids() );
		$this->assertFalse( $reach->admits( 7 ) );
	}

	/** Narrowing never replaces a caller's disjoint selection with reachable stores. */
	public function test_store_reach_narrow_intersects_requested_stores(): void {
		// Arrange / Act.
		$reach = $this->reach( array( 'store_id' => array( 1, 2 ) ) );
		// Assert.
		$this->assertSame( array( 'store_id' => array( 1, 2 ) ), $reach->narrow( array() ) );
		foreach ( array( 3, 2, array( 2, 3 ), null, -1 ) as $index => $requested ) {
			$expected = in_array( $index, array( 1, 2 ), true ) ? array( 2 ) : array();
			$this->assertSame( array( 'store_id' => $expected ), $reach->narrow( array( 'store_id' => $requested ) ) );
		}
	}
}
