<?php
/**
 * Shared v1 <-> v2 Read Lane parity probes for the customer collection.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\API\V1\Customers_Controller;
use WCPOS\WooCommercePOS\API\V2\Proxy\Customers_Proxy_Behavior;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_REST_Request;

/**
 * The customer sibling of `Collection_Rules_Parity_Tests`: the SAME query, dispatched
 * down both Read Lanes, must come back as the same ids.
 *
 * Separate from the order trait rather than folded into it because the two collections
 * share nothing below the surface — customers are a `WP_User_Query` over
 * `wp_users`/`wp_usermeta`, they have exactly ONE storage (so there is no HPOS variant
 * of this class), and their clause bodies live in each lane's own
 * `woocommerce_rest_customer_query` callback rather than in a Collection Rule.
 *
 * Both lanes leave `pre_user_query` callbacks installed behind them, so every dispatch
 * goes through `isolated_lane_customer_ids()` — without the snapshot, lane one's
 * callbacks would still be hooked when lane two runs and the comparison would be
 * measuring contamination rather than parity.
 */
trait Customers_Lane_Parity_Tests {
	/**
	 * The sorts WCPOS adds to wc/v3's customer `orderby` enum.
	 *
	 * @var string[]
	 */
	private static $wcpos_customer_sorts = array( 'first_name', 'last_name', 'email', 'role', 'username' );

	/**
	 * THE headline: a param-less customer list is the same set of users on both lanes.
	 *
	 * WooCommerce's wc/v3 defaults `role` to `customer`. The proxy lane has always overridden that to
	 * `all`, and `wcpos_get_all_posts()` (the bulk id path both lanes' clients sync
	 * against) enumerates `wp_users` unfiltered — so the direct lane's paged list was
	 * the one place a cashier or shop manager vanished from the till's customer space.
	 */
	public function test_default_role_returns_identical_id_sets_on_both_lanes(): void {
		$cashier_id  = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$manager_id  = $this->factory->user->create( array( 'role' => 'shop_manager' ) );
		$customer_id = CustomerHelper::create_customer()->get_id();

		$ids = $this->assert_lane_parity( array() );

		$this->assertContains( $cashier_id, $ids, 'Staff users belong to the POS customer space' );
		$this->assertContains( $manager_id, $ids );
		$this->assertContains( $customer_id, $ids );
	}

	/**
	 * An explicit `role` still narrows, identically, on both lanes.
	 */
	public function test_explicit_role_narrows_identically_on_both_lanes(): void {
		$cashier_id  = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$customer_id = CustomerHelper::create_customer()->get_id();

		$ids = $this->assert_lane_parity( array( 'role' => 'customer' ) );

		$this->assertContains( $customer_id, $ids );
		$this->assertNotContains( $cashier_id, $ids );
	}

	/**
	 * `roles` (plural) — WCPOS's multi-role filter — narrows identically on both lanes.
	 *
	 * It is not a wc/v3 param, and wc/v3 drops an unregistered param in silence, so
	 * before the proxy claimed it the v2 lane answered a narrowed request with the
	 * whole customer space.
	 */
	public function test_roles_filter_narrows_identically_on_both_lanes(): void {
		$cashier_id  = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$editor_id   = $this->factory->user->create( array( 'role' => 'editor' ) );
		$customer_id = CustomerHelper::create_customer()->get_id();

		$ids = $this->assert_lane_parity( array( 'roles' => array( 'cashier', 'customer' ) ) );

		$this->assertContains( $cashier_id, $ids );
		$this->assertContains( $customer_id, $ids );
		$this->assertNotContains( $editor_id, $ids );
	}

	/**
	 * A comma-joined `roles` string resolves the same way on both lanes.
	 *
	 * The direct lane gets the split for free from its `type => array` schema row; the proxy route
	 * carries no schema, so it has to reproduce `wp_parse_list()` itself.
	 */
	public function test_comma_joined_roles_string_narrows_identically_on_both_lanes(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$editor_id  = $this->factory->user->create( array( 'role' => 'editor' ) );

		$ids = $this->assert_lane_parity( array( 'roles' => 'cashier,subscriber' ) );

		$this->assertContains( $cashier_id, $ids );
		$this->assertNotContains( $editor_id, $ids );
	}

	/**
	 * `modified_after` narrows to the recently touched users on both lanes.
	 *
	 * The client's customer sync pull sends this every tick. wc/v3 has no such customer
	 * param, so an unclaimed `modified_after` meant the proxy lane re-served the entire
	 * customer space on every pull.
	 */
	public function test_modified_after_narrows_identically_on_both_lanes(): void {
		$stale_id  = CustomerHelper::create_customer()->get_id();
		$fresh_id  = CustomerHelper::create_customer()->get_id();
		$cutoff    = time() - 3600;

		// Written last, so WooCommerce's own `last_update` bookkeeping cannot overwrite it.
		update_user_meta( $stale_id, 'last_update', $cutoff - 3600 );
		update_user_meta( $fresh_id, 'last_update', time() );

		$ids = $this->assert_lane_parity( array( 'modified_after' => gmdate( 'Y-m-d\TH:i:s', $cutoff ) ) );

		$this->assertContains( $fresh_id, $ids );
		$this->assertNotContains( $stale_id, $ids );
	}

	/**
	 * A blank `modified_after` is no filter at all, on both lanes.
	 */
	public function test_blank_modified_after_is_not_a_filter_on_both_lanes(): void {
		$customer_id = CustomerHelper::create_customer()->get_id();

		$this->assertContains(
			$customer_id,
			$this->assert_lane_parity( array( 'modified_after' => '' ) )
		);
	}

	/**
	 * Every WCPOS sort returns the same id sequence on both lanes, in both directions.
	 */
	public function test_every_extended_sort_returns_identical_id_sequences_on_both_lanes(): void {
		$this->create_sort_fixtures();

		foreach ( self::$wcpos_customer_sorts as $orderby ) {
			foreach ( array( 'asc', 'desc' ) as $order ) {
				$ids = $this->assert_lane_parity(
					array(
						'orderby' => $orderby,
						'order'   => $order,
					)
				);

				$this->assertNotEmpty( $ids, "{$orderby} {$order} returned nothing on either lane" );
			}
		}
	}

	/**
	 * The sequences are pinned to the expected order, not merely agreed on.
	 *
	 * Guards against both lanes being wrong the same way. The response is filtered to
	 * the fixture ids so the users the base test class authenticates as cannot affect
	 * the assertion.
	 *
	 * @dataProvider extended_sort_provider
	 *
	 * @param string   $orderby       Sort to apply.
	 * @param string[] $expected_keys Fixture keys in ascending order.
	 */
	public function test_extended_sort_sequence_is_pinned_on_both_lanes( string $orderby, array $expected_keys ): void {
		$fixtures = $this->create_sort_fixtures();
		$expected = array_map(
			static function ( string $key ) use ( $fixtures ): int {
				return $fixtures[ $key ];
			},
			$expected_keys
		);

		$ascending = $this->assert_lane_parity(
			array(
				'orderby' => $orderby,
				'order'   => 'asc',
			)
		);
		$this->assertSame( $expected, $this->only_fixtures( $ascending, $fixtures ) );

		$descending = $this->assert_lane_parity(
			array(
				'orderby' => $orderby,
				'order'   => 'desc',
			)
		);
		$this->assertSame( array_reverse( $expected ), $this->only_fixtures( $descending, $fixtures ) );
	}

	/**
	 * Fixture order under each WCPOS sort.
	 *
	 * @return array<string, array{string, string[]}>
	 */
	public function extended_sort_provider(): array {
		return array(
			'first_name' => array( 'first_name', array( 'alpha', 'bravo', 'charlie' ) ),
			'last_name'  => array( 'last_name', array( 'charlie', 'bravo', 'alpha' ) ),
			'email'      => array( 'email', array( 'alpha', 'bravo', 'charlie' ) ),
			'username'   => array( 'username', array( 'alpha', 'bravo', 'charlie' ) ),
		);
	}

	/**
	 * The role ladder ranks users identically on both lanes.
	 *
	 * Its own test rather than a provider row: `role` sorts by privilege rank, not by a
	 * customer field, so it needs users spread across the ladder instead of the
	 * three-customer sort fixture.
	 */
	public function test_role_sort_ranks_by_hierarchy_on_both_lanes(): void {
		$expected = array(
			$this->factory->user->create(
				array(
					'role'       => 'administrator',
					'user_login' => 'zz_parity_admin',
				)
			),
			$this->factory->user->create(
				array(
					'role'       => 'cashier',
					'user_login' => 'zz_parity_cashier',
				)
			),
			$this->factory->user->create(
				array(
					'role'       => 'editor',
					'user_login' => 'zz_parity_editor',
				)
			),
			$this->factory->user->create(
				array(
					'role'       => 'subscriber',
					'user_login' => 'zz_parity_subscriber',
				)
			),
		);
		$ascending = $this->assert_lane_parity(
			array(
				'orderby' => 'role',
				'order'   => 'asc',
			)
		);
		$this->assertSame( $expected, $this->only_fixtures( $ascending, $expected ) );

		$descending = $this->assert_lane_parity(
			array(
				'orderby' => 'role',
				'order'   => 'desc',
			)
		);
		$this->assertSame( array_reverse( $expected ), $this->only_fixtures( $descending, $expected ) );
	}

	/**
	 * Multi-term search matches the same customers on both lanes.
	 *
	 * @dataProvider multi_term_search_provider
	 *
	 * @param string $search   Search string.
	 * @param bool   $expected Whether the fixture should match.
	 */
	public function test_multi_term_search_matches_identically_on_both_lanes( string $search, bool $expected ): void {
		$customer_id = CustomerHelper::create_customer(
			array(
				'first_name'      => 'Jane',
				'last_name'       => 'Smith',
				'email'           => 'lane.parity.customer@example.com',
				'billing_company' => 'Acme Consulting',
			)
		)->get_id();

		$ids = $this->assert_lane_parity( array( 'search' => $search ) );

		if ( $expected ) {
			$this->assertSame( array( $customer_id ), $ids );
		} else {
			$this->assertNotContains( $customer_id, $ids );
		}
	}

	/**
	 * Search strings and whether the fixture customer should match.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function multi_term_search_provider(): array {
		return array(
			'full name'          => array( 'Jane Smith', true ),
			'reversed name'      => array( 'Smith Jane', true ),
			'billing company'    => array( 'Acme Consulting', true ),
			'email'              => array( 'lane.parity.customer@example.com', true ),
			'internal tab'       => array( "Jane \t  Smith", true ),
			'one term misses'    => array( 'Jane Nonexistent', false ),
			'nothing matches'    => array( 'Nonexistent', false ),
		);
	}

	/**
	 * A whitespace-only search is no search at all, on both lanes.
	 */
	public function test_whitespace_only_search_is_ignored_on_both_lanes(): void {
		$customer_id = CustomerHelper::create_customer( array( 'first_name' => 'Jane' ) )->get_id();

		$this->assertContains(
			$customer_id,
			$this->assert_lane_parity( array( 'search' => "\u{00A0}" ) )
		);
	}

	/**
	 * `orderby_enum( 'customers' )` is the ONE list both lanes read.
	 *
	 * Pure — no fixtures and no dispatch. It asserts the projection itself, then that
	 * each lane actually claims every name in it: the direct lane by advertising it in
	 * its REST schema enum, the proxy lane by removing it from the params it forwards
	 * to wc/v3 (which cannot express any of them).
	 */
	public function test_orderby_enum_projection_matches_both_lane_claim_lists(): void {
		$this->assertSame( self::$wcpos_customer_sorts, Collection_Rules::orderby_enum( 'customers' ) );

		$schema = ( new Customers_Controller() )->get_collection_params();
		$this->assertSame( 'all', $schema['role']['default'], 'The POS customer space is every user' );

		foreach ( Collection_Rules::orderby_enum( 'customers' ) as $orderby ) {
			$this->assertContains( $orderby, $schema['orderby']['enum'], "v1 does not advertise {$orderby}" );

			$request = new WP_REST_Request();
			$request->set_query_params( array( 'orderby' => $orderby ) );
			$forwarded = ( new Customers_Proxy_Behavior() )->forwarded_params( $request->get_query_params(), $request );

			$this->assertArrayNotHasKey( 'orderby', $forwarded, "the proxy forwards {$orderby} to wc/v3 instead of claiming it" );
		}
	}

	/**
	 * Dispatch the same logical query down both lanes and assert the ids match.
	 *
	 * @param array $query Query params, shared by both lanes.
	 *
	 * @return int[] The agreed id sequence.
	 */
	private function assert_lane_parity( array $query ): array {
		$query['per_page'] = 100;

		$direct = $this->isolated_lane_customer_ids( '/wcpos/v1/customers', $query );
		$proxy  = $this->isolated_lane_customer_ids( '/wcpos/v2/customers', $query );

		$this->assertSame( $direct, $proxy, 'Read Lane divergence for ' . wp_json_encode( $query ) );

		return $direct;
	}

	/**
	 * Dispatch one lane without carrying its persistent hooks into the other lane.
	 *
	 * Both lanes install `pre_user_query` callbacks; the direct lane's survive the
	 * request (they are removed on first fire, which never happens if the query is
	 * short-circuited), so the snapshot is what keeps this a parity measurement.
	 *
	 * @param string $route Route to dispatch.
	 * @param array  $query Query params.
	 *
	 * @return int[]
	 */
	private function isolated_lane_customer_ids( string $route, array $query ): array {
		$filter_snapshot = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
			$filter_snapshot[ $hook ] = clone $callbacks;
		}

		try {
			return $this->lane_customer_ids( $route, $query );
		} finally {
			$GLOBALS['wp_filter'] = $filter_snapshot;
		}
	}

	/**
	 * Dispatch one lane and return its customer ids.
	 *
	 * @param string $route Route to dispatch.
	 * @param array  $query Query params.
	 *
	 * @return int[]
	 */
	private function lane_customer_ids( string $route, array $query ): array {
		$request = $this->wp_rest_get_request( $route );
		$request->set_query_params( $query );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), "{$route} did not return 200: " . wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', wp_list_pluck( (array) $response->get_data(), 'id' ) );
	}

	/**
	 * Narrow a served id sequence to this test's own fixtures, order preserved.
	 *
	 * @param int[] $ids      Served ids, in served order.
	 * @param array $fixtures Fixture ids, as values.
	 *
	 * @return int[]
	 */
	private function only_fixtures( array $ids, array $fixtures ): array {
		$mine = array_map( 'intval', array_values( $fixtures ) );

		return array_values( array_intersect( $ids, $mine ) );
	}

	/**
	 * Three customers that sort differently under every WCPOS customer sort.
	 *
	 * Each field deliberately disagrees with the others, so a lane that silently sorts
	 * by the wrong column cannot pass by accident.
	 *
	 * @return array<string, int>
	 */
	private function create_sort_fixtures(): array {
		$specs = array(
			// Key => first_name, last_name, email, username.
			'alpha'   => array( 'Amelia', 'Zimmerman', 'aa.parity@example.com', 'aa_parity' ),
			'bravo'   => array( 'Beatrix', 'Mortimer', 'bb.parity@example.com', 'bb_parity' ),
			'charlie' => array( 'Cordelia', 'Ainsworth', 'cc.parity@example.com', 'cc_parity' ),
		);

		$fixtures = array();
		foreach ( $specs as $key => $spec ) {
			$fixtures[ $key ] = CustomerHelper::create_customer(
				array(
					'first_name' => $spec[0],
					'last_name'  => $spec[1],
					'email'      => $spec[2],
					'username'   => $spec[3],
				)
			)->get_id();
		}

		return $fixtures;
	}
}
