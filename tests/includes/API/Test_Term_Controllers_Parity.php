<?php
/**
 * Test_Term_Controllers_Parity.
 *
 * One behaviour suite for the three wcpos/v1 term controllers (tags,
 * categories, brands). They serve the same collection shape, so a behaviour
 * that only holds for one of them is a parity bug, not a design — see the
 * Read Lane entry in CONTEXT.md.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\V1\Product_Brands_Controller;
use WCPOS\WooCommercePOS\API\V1\Product_Categories_Controller;
use WCPOS\WooCommercePOS\API\V1\Product_Tags_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Term_Controllers_Parity extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The taxonomy currently under test, set by boot_endpoint().
	 *
	 * @var string
	 */
	protected $taxonomy = '';

	/**
	 * The three term collections, as [ controller class, taxonomy, rest base ].
	 *
	 * Brands ship with WooCommerce 9.4+; the row is skipped inside the test
	 * body (not here) because the provider runs before the taxonomy is
	 * guaranteed to be registered.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function taxonomy_provider(): array {
		return array(
			'tags'       => array( Product_Tags_Controller::class, 'product_tag', 'products/tags' ),
			'categories' => array( Product_Categories_Controller::class, 'product_cat', 'products/categories' ),
			'brands'     => array( Product_Brands_Controller::class, 'product_brand', 'products/brands' ),
		);
	}

	/**
	 * The namespace is pinned for every term controller.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_controller_namespace_is_wcpos_v1( string $controller_class, string $taxonomy, string $rest_base ): void {
		$this->boot_endpoint( $controller_class, $taxonomy );

		$this->assertSame( 'wcpos/v1', $this->get_reflected_property_value( 'namespace' ) );
	}

	/**
	 * The rest base is pinned for every term controller.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_controller_rest_base_matches_the_collection_route( string $controller_class, string $taxonomy, string $rest_base ): void {
		$this->boot_endpoint( $controller_class, $taxonomy );

		$this->assertSame( $rest_base, $this->get_reflected_property_value( 'rest_base' ) );
	}

	/**
	 * Collection, item and batch routes are registered for every term controller.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_controller_registers_collection_item_and_batch_routes( string $controller_class, string $taxonomy, string $rest_base ): void {
		$this->boot_endpoint( $controller_class, $taxonomy );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/' . $rest_base, $routes );
		$this->assertArrayHasKey( '/wcpos/v1/' . $rest_base . '/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/' . $rest_base . '/batch', $routes );
	}

	/**
	 * An item response carries exactly the controller's own schema plus `uuid`.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_response_fields_are_the_schema_plus_uuid( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$term_id  = $this->create_term( 'Fields Music' );
		$expected = $this->viewable_schema_fields();
		$expected[] = 'uuid';

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/' . $rest_base . '/' . $term_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$fields = array_keys( $response->get_data() );
		$this->assertEmpty(
			array_diff( $expected, $fields ),
			'Missing from the response: ' . implode( ', ', array_diff( $expected, $fields ) )
		);
		$this->assertEmpty(
			array_diff( $fields, $expected ),
			'Unexpected in the response: ' . implode( ', ', array_diff( $fields, $expected ) )
		);
	}

	/**
	 * Every term response carries a valid uuid.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_response_contains_a_valid_uuid( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$term_id = $this->create_term( 'Uuid Music' );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/' . $rest_base . '/' . $term_id ) );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'uuid', $data );
		$this->assertTrue( Uuid::isValid( $data['uuid'] ), 'The UUID value is not valid.' );
	}

	/**
	 * Two terms sharing a stored uuid are rekeyed so the served list stays unique.
	 *
	 * Generalises Test_Product_Categories_Controller::test_unique_product_category_uuid().
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_duplicate_stored_uuids_are_rekeyed_to_unique_values( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$uuid  = Uuid::uuid4()->toString();
		$first = $this->create_term( 'Collision Music1' );
		$last  = $this->create_term( 'Collision Music2' );
		add_term_meta( $first, '_woocommerce_pos_uuid', $uuid );
		add_term_meta( $last, '_woocommerce_pos_uuid', $uuid );

		// Act.
		$data = $this->read_collection( $rest_base, array( 'include' => array( $first, $last ) ) );

		// Assert.
		$this->assertCount( 2, $data );
		$uuids = wp_list_pluck( $data, 'uuid' );
		$this->assertCount( 2, array_unique( $uuids ), 'Two terms were served the same uuid.' );
	}

	/**
	 * `include` narrows the collection to the listed ids.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_list_with_include_returns_only_the_included_term( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Include Music' );
		$last  = $this->create_term( 'Include Clothes' );

		// Act.
		$ids = wp_list_pluck( $this->read_collection( $rest_base, array( 'include' => $first ) ), 'id' );

		// Assert.
		$this->assertSame( array( $first ), array_map( 'intval', $ids ) );
		$this->assertNotContains( $last, $ids );
	}

	/**
	 * `exclude` drops the listed ids from the collection.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_list_with_exclude_omits_the_excluded_term( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Exclude Music' );
		$last  = $this->create_term( 'Exclude Clothes' );

		// Act.
		$ids = array_map( 'intval', wp_list_pluck( $this->read_collection( $rest_base, array( 'exclude' => $first ) ), 'id' ) );

		// Assert.
		$this->assertNotContains( $first, $ids );
		$this->assertContains( $last, $ids );
	}

	/**
	 * `search` and `include` narrow the collection together.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_search_with_include_returns_only_the_included_match( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Searchable Music1' );
		$this->create_term( 'Searchable Music2' );

		// Act.
		$data = $this->read_collection(
			$rest_base,
			array(
				'include' => $first,
				'search'  => 'Searchable Music',
			)
		);

		// Assert.
		$this->assertCount( 1, $data );
		$this->assertSame( $first, (int) $data[0]['id'] );
	}

	/**
	 * `search` and `exclude` narrow the collection together.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_search_with_exclude_omits_the_excluded_match( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Searchable Music1' );
		$last  = $this->create_term( 'Searchable Music2' );

		// Act.
		$data = $this->read_collection(
			$rest_base,
			array(
				'exclude' => $first,
				'search'  => 'Searchable Music',
			)
		);

		// Assert.
		$this->assertCount( 1, $data );
		$this->assertSame( $last, (int) $data[0]['id'] );
	}

	/**
	 * The bulk-ID fast path returns every term id in the taxonomy.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_bulk_id_fast_path_returns_all_term_ids( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Fast Path Music' );
		$last  = $this->create_term( 'Fast Path Clothes' );

		// Act.
		$ids = array_map( 'intval', wp_list_pluck( $this->read_all_ids( $rest_base ), 'id' ) );

		// Assert.
		$this->assertContains( $first, $ids );
		$this->assertContains( $last, $ids );
	}

	/**
	 * The bulk-ID fast path honours `include`.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_bulk_id_fast_path_with_include_returns_only_included_ids( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Fast Include Music' );
		$last  = $this->create_term( 'Fast Include Clothes' );

		// Act.
		$ids = array_map( 'intval', wp_list_pluck( $this->read_all_ids( $rest_base, array( 'include' => array( $first ) ) ), 'id' ) );

		// Assert.
		$this->assertSame( array( $first ), $ids );
		$this->assertNotContains( $last, $ids );
	}

	/**
	 * The bulk-ID fast path honours `exclude`.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_bulk_id_fast_path_with_exclude_omits_excluded_ids( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Fast Exclude Music' );
		$last  = $this->create_term( 'Fast Exclude Clothes' );

		// Act.
		$ids = array_map( 'intval', wp_list_pluck( $this->read_all_ids( $rest_base, array( 'exclude' => array( $first ) ) ), 'id' ) );

		// Assert.
		$this->assertNotContains( $first, $ids );
		$this->assertContains( $last, $ids );
	}

	/**
	 * Terms carry no modified date, so a `modified_after` sync window is empty.
	 *
	 * Pins the @TODO in Traits\Term_Controller::wcpos_get_all_posts(): the POS
	 * client asks for the changed slice on every sync tick and must be told
	 * "nothing changed", not handed the whole taxonomy again.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_bulk_id_fast_path_with_modified_after_returns_empty( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$this->create_term( 'Modified After Music' );

		// Act.
		$data = $this->read_all_ids( $rest_base, array( 'modified_after' => '2020-01-01T00:00:00' ) );

		// Assert.
		$this->assertSame( array(), $data );
	}

	/**
	 * Tied names carry the term_id tiebreak into the ORDER BY clause.
	 *
	 * The v2 proxy lane pins this in Terms_Proxy_Behavior; the direct lane must
	 * carry the same Collection Rule or the two lanes paginate differently.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_list_orderby_name_carries_the_term_id_tiebreak( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$this->create_term( 'Tiebreak Clause' );
		$captured = array();
		add_filter(
			'terms_clauses',
			function ( $clauses, $taxonomies ) use ( &$captured, $taxonomy ) {
				if ( \in_array( $taxonomy, (array) $taxonomies, true ) && '' !== (string) ( $clauses['orderby'] ?? '' ) ) {
					$captured[] = $clauses;
				}

				return $clauses;
			},
			99,
			2
		);

		// Act.
		$this->read_collection( $rest_base, array( 'orderby' => 'name' ) );

		// Assert.
		$this->assertNotEmpty( $captured, 'No ordered term query ran for ' . $taxonomy . '.' );
		$this->assertSame( 'ORDER BY t.name ASC, t.term_id', $captured[0]['orderby'] );
		$this->assertSame( 'ASC', $captured[0]['order'] );
	}

	/**
	 * A tie straddling a page boundary appears exactly once across the walk.
	 *
	 * ORDER BY a tied column alone gives MySQL no total order across separate
	 * offset queries, so the same row can land on two pages while the POS
	 * client walks a multi-page window (mono#1372).
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_list_paginates_stably_when_names_tie( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$tied = array(
			$this->create_term( 'Duplicate Term', array( 'slug' => 'duplicate-term-one' ) ),
			$this->create_term( 'Duplicate Term', array( 'slug' => 'duplicate-term-two' ) ),
		);
		sort( $tied );

		// Act: walk the collection one row at a time, tie straddling the boundary.
		$walked = array();
		for ( $page = 1; $page <= 2; $page++ ) {
			$rows   = $this->read_collection(
				$rest_base,
				array(
					'include'  => $tied,
					'orderby'  => 'name',
					'order'    => 'asc',
					'per_page' => 1,
					'page'     => $page,
				)
			);
			$walked = array_merge( $walked, array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		}

		// Assert.
		$this->assertSame( $tied, $walked, 'each tied term appears exactly once, in term_id order, across pages' );
	}

	/**
	 * Tied names resolve by ascending term_id whatever the sort direction.
	 *
	 * The POS client renders ties in ascending id order, so the wire must too —
	 * a descending name sort must NOT flip the tie into descending id order.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_tied_term_names_resolve_by_ascending_term_id( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$tied = array(
			$this->create_term( 'Duplicate Term', array( 'slug' => 'duplicate-term-one' ) ),
			$this->create_term( 'Duplicate Term', array( 'slug' => 'duplicate-term-two' ) ),
		);
		sort( $tied );

		foreach ( array( 'asc', 'desc' ) as $order ) {
			// Act.
			$rows = $this->read_collection(
				$rest_base,
				array(
					'include'  => $tied,
					'orderby'  => 'name',
					'order'    => $order,
					'per_page' => 20,
				)
			);

			// Assert.
			$this->assertSame(
				$tied,
				array_map( 'intval', wp_list_pluck( $rows, 'id' ) ),
				"tied names must list term_id-ascending ({$order})"
			);
		}
	}

	/**
	 * The include/exclude clauses filter does not outlive the request that added it.
	 *
	 * Left installed it re-writes any later get_terms() in the same PHP request —
	 * including plain wc/v3 term reads — with this request's WHERE clause and
	 * ORDER BY tiebreak.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_terms_clauses_filter_is_removed_after_the_query( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Teardown Music' );
		$this->create_term( 'Teardown Clothes' );
		$installed_during_query = 0;
		add_filter(
			'terms_clauses',
			function ( $clauses ) use ( &$installed_during_query ) {
				$installed_during_query = max( $installed_during_query, $this->count_wcpos_callbacks( 'terms_clauses', 'wcpos_terms_clauses_include_exclude' ) );

				return $clauses;
			},
			99,
			1
		);

		// Act.
		$this->read_collection( $rest_base, array( 'include' => $first ) );

		// Assert: installed while the query ran, gone once the request finished.
		$this->assertSame( 1, $installed_during_query, 'the clauses filter never ran for this request' );
		$this->assertSame( 0, $this->count_wcpos_callbacks( 'terms_clauses', 'wcpos_terms_clauses_include_exclude' ) );
	}

	/**
	 * The response and query filters do not outlive the request that added them.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_term_response_and_query_filters_are_removed_after_the_request( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$this->create_term( 'Teardown Hooks' );

		// Act.
		$this->read_collection( $rest_base );

		// Assert.
		$this->assertFalse( has_filter( 'woocommerce_rest_prepare_' . $taxonomy ), 'the prepare filter outlived the request' );
		$this->assertFalse( has_filter( 'woocommerce_rest_' . $taxonomy . '_query' ), 'the query filter outlived the request' );
	}

	/**
	 * An include-filtered collection reports the FILTERED total, not the taxonomy total.
	 *
	 * WC_REST_Terms_Controller::get_items() runs a second wp_count_terms() query
	 * for X-WP-Total after get_terms(), so the wcpos_include/wcpos_exclude WHERE
	 * clause has to still be installed when that second query runs — otherwise
	 * the client is told to walk pages that hold nothing.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_include_filtered_collection_reports_the_filtered_total( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		$first = $this->create_term( 'Total Music' );
		$this->create_term( 'Total Clothes' );
		$this->create_term( 'Total Books' );

		// Act.
		$request = $this->wp_rest_get_request( '/wcpos/v1/' . $rest_base );
		$request->set_param( 'include', array( $first ) );
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Dispatch registers the historical, individually detachable filter callbacks.
	 *
	 * These six method names are public API: extensions detach them with
	 * remove_filter( 'woocommerce_rest_prepare_product_tag', array( $c,
	 * 'wcpos_product_tags_response' ), 10 ), which matches on the exact string.
	 * Registering a shared trait method instead would silently break every one
	 * of those calls.
	 *
	 * @dataProvider taxonomy_provider
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $rest_base        Route below the namespace.
	 */
	public function test_dispatch_registers_the_historical_filter_callbacks( string $controller_class, string $taxonomy, string $rest_base ): void {
		// Arrange.
		$this->boot_endpoint( $controller_class, $taxonomy );
		list( $response_method, $query_method ) = $this->historical_filter_methods( $taxonomy );
		$route      = '/wcpos/v1/' . $rest_base;
		$controller = $this->endpoint;

		// Act.
		$controller->wcpos_dispatch_request( null, $this->wp_rest_get_request( $route ), $route, array() );

		// Assert: registered under the historical names...
		$this->assertSame( 10, has_filter( 'woocommerce_rest_prepare_' . $taxonomy, array( $controller, $response_method ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_rest_' . $taxonomy . '_query', array( $controller, $query_method ) ) );

		// ...and detachable by them.
		remove_filter( 'woocommerce_rest_prepare_' . $taxonomy, array( $controller, $response_method ), 10 );
		remove_filter( 'woocommerce_rest_' . $taxonomy . '_query', array( $controller, $query_method ), 10 );
		$this->assertFalse( has_filter( 'woocommerce_rest_prepare_' . $taxonomy, array( $controller, $response_method ) ) );
		$this->assertFalse( has_filter( 'woocommerce_rest_' . $taxonomy . '_query', array( $controller, $query_method ) ) );
	}

	/**
	 * The public method names each taxonomy has always registered.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function historical_filter_methods( string $taxonomy ): array {
		$methods = array(
			'product_tag'   => array( 'wcpos_product_tags_response', 'wcpos_product_tag_query' ),
			'product_cat'   => array( 'wcpos_product_categories_response', 'wcpos_product_category_query' ),
			'product_brand' => array( 'wcpos_product_brands_response', 'wcpos_product_brand_query' ),
		);

		return $methods[ $taxonomy ];
	}

	/**
	 * Boot the controller for one taxonomy, skipping collections this WooCommerce lacks.
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $taxonomy         Taxonomy name.
	 */
	private function boot_endpoint( string $controller_class, string $taxonomy ): void {
		if ( ! class_exists( $controller_class ) ) {
			$this->markTestSkipped( $controller_class . ' is not available in this WooCommerce version.' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->markTestSkipped( 'The ' . $taxonomy . ' taxonomy is not available in this WooCommerce version.' );
		}

		$this->taxonomy = $taxonomy;
		$this->endpoint = new $controller_class();
	}

	/**
	 * Insert a term in the taxonomy under test.
	 *
	 * A distinct slug is what lets a flat taxonomy hold two same-named terms,
	 * which is how the tie-prone sort cases are built.
	 *
	 * @param string $name Term name.
	 * @param array  $args Extra wp_insert_term() arguments.
	 *
	 * @return int
	 */
	private function create_term( string $name, array $args = array() ): int {
		$result = wp_insert_term( $name, $this->taxonomy, $args );
		$this->assertNotWPError( $result, 'Could not create the ' . $this->taxonomy . ' term "' . $name . '".' );

		return (int) $result['term_id'];
	}

	/**
	 * Dispatch a collection read and return its rows.
	 *
	 * @param string $rest_base Route below the namespace.
	 * @param array  $params    Query parameters.
	 *
	 * @return array
	 */
	private function read_collection( string $rest_base, array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v1/' . $rest_base );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Dispatch a bulk-ID fast-path read and return its rows.
	 *
	 * @param string $rest_base Route below the namespace.
	 * @param array  $params    Extra query parameters.
	 *
	 * @return array
	 */
	private function read_all_ids( string $rest_base, array $params = array() ): array {
		return $this->read_collection(
			$rest_base,
			array_merge(
				array(
					'posts_per_page' => -1,
					'fields'         => array( 'id' ),
				),
				$params
			)
		);
	}

	/**
	 * The schema fields this controller serves in the default `view` context.
	 *
	 * @return array<int, string>
	 */
	private function viewable_schema_fields(): array {
		$fields = array();
		foreach ( $this->endpoint->get_item_schema()['properties'] as $field => $definition ) {
			if ( empty( $definition['context'] ) || \in_array( 'view', (array) $definition['context'], true ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Count registered callbacks on a hook whose method carries the given name.
	 *
	 * The controller instance the REST server built is not $this->endpoint, so
	 * has_filter() cannot be used to look these up — match on the method name.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method name to match.
	 *
	 * @return int
	 */
	private function count_wcpos_callbacks( string $hook, string $method ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( \is_array( $function ) && isset( $function[1] ) && $method === $function[1] ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
