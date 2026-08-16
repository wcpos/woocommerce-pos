<?php
/**
 * Tests for V2 catalog proxy customer searches.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Response;

/**
 * V2 catalog proxy customer search tests.
 */
class Test_Catalog_Proxy_Customers extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Customer used by each search test.
	 *
	 * @var \WC_Customer
	 */
	private $customer;

	/**
	 * Create the customer fixture after REST initialization.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->customer = CustomerHelper::create_customer(
			array(
				'first_name'      => 'Jane',
				'last_name'       => 'Smith',
				'email'           => 'v2.multiword.customer@example.com',
				'billing_company' => 'Acme Consulting',
			)
		);
	}

	/**
	 * Restore database state after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Targeted include= pulls must serve non-customer-role users without an
	 * explicit role param — V1 enumerated ALL users as POS customers, and the
	 * sync digests id-space is wp_users, so wc/v3's default role=customer made
	 * any pull batch containing a cashier/admin id shortfall its tick and the
	 * customers cursor never advanced (monorepo#850).
	 */
	public function test_include_pull_serves_non_customer_roles_by_default(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );

		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params( array( 'include' => (string) $cashier_id ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( array( $cashier_id ), array_column( $data, 'id' ) );
	}

	/**
	 * Param-less lists include every WordPress user in the POS customer space
	 * under the #1379 ruling (1.9 parity).
	 */
	public function test_plain_list_includes_non_customer_roles(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );

		$request  = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $cashier_id, array_column( $response->get_data(), 'id' ) );
	}

	/**
	 * An explicit role param still passes through untouched.
	 */
	public function test_explicit_role_param_is_preserved(): void {
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );

		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params(
			array(
				'include' => (string) $cashier_id,
				'role'    => 'customer',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * Multi-word searches match terms across customer fields.
	 *
	 * @dataProvider multi_word_search_provider
	 *
	 * @param string $search Search string.
	 */
	public function test_multi_word_search_matches_customer( string $search ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => $search,
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $this->customer->get_id(), $data[0]['id'] );
	}

	/**
	 * Single-word searches also use the per-term filter (V1 parity) and match name fields.
	 */
	public function test_single_word_search_matches_customer(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => 'Jane',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $this->customer->get_id(), $data[0]['id'] );
	}

	/**
	 * Unicode whitespace is a no-op, matching the V1 customer search contract.
	 */
	public function test_unicode_whitespace_search_is_a_no_op(): void {
		$response = $this->dispatch_customers(
			array(
				'include' => (string) $this->customer->get_id(),
				'role'    => 'all',
				'search'  => "\xC2\xA0",
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->customer->get_id() ), array_column( $response->get_data(), 'id' ) );
	}

	/**
	 * An unrelated user query must not consume hooks intended for the marked customer query.
	 */
	public function test_customer_hooks_survive_an_unrelated_user_query(): void {
		$customer_id = $this->factory->user->create(
			array(
				'role'         => 'customer',
				'user_login'   => 'aaa.scoped-hook-customer',
				'display_name' => 'A Scoped Hook',
			)
		);
		$admin_id    = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => 'zzz.scoped-hook-admin',
				'display_name' => 'Z Scoped Hook',
			)
		);
		$unmatched_id = $this->factory->user->create(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'middle.unmatched',
				'display_name' => 'No Match',
			)
		);
		$run_unrelated_query = static function ( array $args ): array {
			new \WP_User_Query( array( 'fields' => 'ids', 'number' => 1 ) );

			return $args;
		};

		add_filter( 'woocommerce_rest_customer_query', $run_unrelated_query, 9 );
		try {
			$response = $this->dispatch_customers(
				array(
					'include' => implode( ',', array( $customer_id, $admin_id, $unmatched_id ) ),
					'role'    => 'all',
					'search'  => 'Scoped Hook',
					'orderby' => 'role',
					'order'   => 'asc',
				)
			);
		} finally {
			remove_filter( 'woocommerce_rest_customer_query', $run_unrelated_query, 9 );
		}

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( $admin_id, $customer_id ), array_column( $response->get_data(), 'id' ) );
	}

	/**
	 * Customer rows expose the complete v2 field set.
	 */
	public function test_customer_row_has_full_v2_field_set(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params( array( 'include' => array( $this->customer->get_id() ) ) );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertEqualsCanonicalizing(
			array(
				'id',
				'date_created',
				'date_created_gmt',
				'date_modified',
				'date_modified_gmt',
				'email',
				'first_name',
				'last_name',
				'role',
				'username',
				'billing',
				'shipping',
				'is_paying_customer',
				'avatar_url',
				'meta_data',
				'_links',
			),
			array_keys( $rows[0] )
		);
	}

	/**
	 * Multi-word customer search cases.
	 *
	 * @return array<string, array{string}>
	 */
	public function multi_word_search_provider(): array {
		return array(
			'full name'       => array( 'Jane Smith' ),
			'reversed name'   => array( 'Smith Jane' ),
			'billing company' => array( 'Acme Consulting' ),
		);
	}

	/**
	 * A WCPOS-extended sort is served, not rejected.
	 *
	 * The wc/v3 customer `orderby` enum is id|include|name|registered_date, so
	 * `last_name` forwarded verbatim is a `rest_invalid_param` 400 — and
	 * `last_name asc` is the customers grid's DEFAULT sort (monorepo#1028).
	 * The proxy strips it off the inner request and re-applies it through
	 * `V1_Customers_Controller::wcpos_customer_query`.
	 */
	public function test_extended_orderby_last_name_is_applied(): void {
		$ids = $this->create_sortable_customers();

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'orderby' => 'last_name',
				'order'   => 'asc',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			array( $ids['alpha'], $ids['mercer'], $ids['zeta'] ),
			array_column( $data, 'id' )
		);
	}

	/**
	 * `order` is wc/v3-native and forwards untouched, so desc must invert the
	 * re-applied sort rather than being silently dropped.
	 */
	public function test_extended_orderby_honours_descending_order(): void {
		$ids = $this->create_sortable_customers();

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'orderby' => 'last_name',
				'order'   => 'desc',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			array( $ids['zeta'], $ids['mercer'], $ids['alpha'] ),
			array_column( $data, 'id' )
		);
	}

	/**
	 * Every value V1 widens the enum with is served rather than 400'd.
	 *
	 * `role` ordering is pinned end-to-end by
	 * `test_extended_orderby_role_orders_by_hierarchy` below; the other fields
	 * are pinned by `test_extended_orderby_orders_results`. This case just keeps
	 * the whole widened enum from ever 400'ing on the inner wc/v3 request.
	 *
	 * @dataProvider extended_orderby_provider
	 *
	 * @param string $orderby WCPOS-extended orderby value.
	 */
	public function test_extended_orderby_values_are_accepted( string $orderby ): void {
		$ids = $this->create_sortable_customers();

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'orderby' => $orderby,
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * WCPOS-extended customer orderby values.
	 *
	 * @return array<string, array{string}>
	 */
	public function extended_orderby_provider(): array {
		return array(
			'first_name' => array( 'first_name' ),
			'last_name'  => array( 'last_name' ),
			'email'      => array( 'email' ),
			'role'       => array( 'role' ),
			'username'   => array( 'username' ),
		);
	}

	/**
	 * Each re-applied sort orders on its own field.
	 *
	 * The fixture's id order matches none of these, and `first_name` order is
	 * deliberately the reverse-ish of `last_name` order, so a sort landing on
	 * the wrong column cannot pass by accident.
	 *
	 * @dataProvider extended_orderby_ordering_provider
	 *
	 * @param string        $orderby       WCPOS-extended orderby value.
	 * @param array<string> $expected_keys Fixture keys in expected ascending order.
	 */
	public function test_extended_orderby_orders_results( string $orderby, array $expected_keys ): void {
		$ids = $this->create_sortable_customers();

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'orderby' => $orderby,
				'order'   => 'asc',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			array_map(
				static function ( string $key ) use ( $ids ): int {
					return $ids[ $key ];
				},
				$expected_keys
			),
			array_column( $data, 'id' )
		);
	}

	/**
	 * Expected ascending order per WCPOS-extended sort field.
	 *
	 * @return array<string, array{string, array<string>}>
	 */
	public function extended_orderby_ordering_provider(): array {
		return array(
			// Wendy < Xavier < Yolanda.
			'first_name' => array( 'first_name', array( 'mercer', 'alpha', 'zeta' ) ),
			// Alpha < Mercer < Zeta.
			'last_name'  => array( 'last_name', array( 'alpha', 'mercer', 'zeta' ) ),
			// alpha.sortable@ < mercer.sortable@ < zeta.sortable@.
			'email'      => array( 'email', array( 'alpha', 'mercer', 'zeta' ) ),
			// user_login: alpha.sortable < mercer.sortable < zeta.sortable.
			'username'   => array( 'username', array( 'alpha', 'mercer', 'zeta' ) ),
		);
	}

	/**
	 * A wc/v3-native orderby is left alone and served by wc/v3 itself.
	 */
	public function test_native_orderby_passes_through(): void {
		$ids      = $this->create_sortable_customers();
		$expected = array_values( $ids );
		rsort( $expected );

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'orderby' => 'id',
				'order'   => 'desc',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( $expected, array_column( $data, 'id' ) );
	}

	/**
	 * `orderby=role` sorts by role hierarchy through the full proxy path.
	 *
	 * Proves #1488's re-application actually orders (not just accepts): the
	 * proxy strips `role` off the inner wc/v3 request and re-applies it via
	 * `V1_Customers_Controller::wcpos_customer_query`, whose `pre_user_query`
	 * ranks users administrator > shop_manager > cashier > … > customer >
	 * subscriber, with multi-role users at their highest privilege. The
	 * response is filtered to the created ids so interleaved fixtures do not
	 * affect the assertion.
	 */
	public function test_extended_orderby_role_orders_by_hierarchy(): void {
		$admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$cashier_id    = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$customer_id   = $this->factory->user->create( array( 'role' => 'customer' ) );
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Multi-role: customer + shop_manager must sort as staff (shop_manager).
		$multi_id   = $this->factory->user->create( array( 'role' => 'customer' ) );
		$multi_user = new \WP_User( $multi_id );
		$multi_user->add_role( 'shop_manager' );

		$expected_asc = array( $admin_id, $multi_id, $cashier_id, $customer_id, $subscriber_id );
		$my_ids       = $expected_asc;

		$response = $this->dispatch_customers(
			array(
				'role'     => 'all',
				'orderby'  => 'role',
				'order'    => 'asc',
				'per_page' => 100,
			)
		);
		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			$expected_asc,
			array_values( array_intersect( array_column( $data, 'id' ), $my_ids ) )
		);

		// Reverse order.
		$response = $this->dispatch_customers(
			array(
				'role'     => 'all',
				'orderby'  => 'role',
				'order'    => 'desc',
				'per_page' => 100,
			)
		);
		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			array_reverse( $expected_asc ),
			array_values( array_intersect( array_column( $data, 'id' ), $my_ids ) )
		);
	}

	/**
	 * The proxy re-applies a known list, it does not open the enum: anything
	 * outside both wc/v3's enum and V1's additions is still a 400.
	 */
	public function test_unknown_orderby_is_still_rejected(): void {
		$response = $this->dispatch_customers( array( 'orderby' => 'date_modified_gmt' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Search and an extended sort share one `woocommerce_rest_customer_query`
	 * pass, so combining them must not drop either.
	 */
	public function test_search_and_extended_orderby_compose(): void {
		$ids = $this->create_sortable_customers();

		$response = $this->dispatch_customers(
			array(
				'include' => implode( ',', $ids ),
				'search'  => 'Sortable',
				'orderby' => 'last_name',
				'order'   => 'asc',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame(
			array( $ids['alpha'], $ids['mercer'], $ids['zeta'] ),
			array_column( $data, 'id' )
		);
	}

	/**
	 * Three customers whose id order deliberately differs from every sortable
	 * field, so an assertion on order cannot pass by accident.
	 *
	 * @return array<string, int> Keyed by last name, in creation (id) order.
	 */
	private function create_sortable_customers(): array {
		$fixtures = array(
			'zeta'   => array( 'Yolanda', 'Zeta', 'zeta.sortable' ),
			'alpha'  => array( 'Xavier', 'Alpha', 'alpha.sortable' ),
			'mercer' => array( 'Wendy', 'Mercer', 'mercer.sortable' ),
		);

		$ids = array();
		foreach ( $fixtures as $key => $fixture ) {
			list( $first_name, $last_name, $slug ) = $fixture;
			$ids[ $key ]                           = $this->factory->user->create(
				array(
					'role'         => 'customer',
					'user_login'   => $slug,
					'user_email'   => $slug . '@example.com',
					'display_name' => $first_name . ' ' . $last_name . ' Sortable',
					'first_name'   => $first_name,
					'last_name'    => $last_name,
				)
			);
		}

		return $ids;
	}

	/**
	 * Dispatch a v2 customers list request with the given query params.
	 *
	 * @param array<string, string> $query_params Query params.
	 */
	private function dispatch_customers( array $query_params ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/customers' );
		$request->set_query_params( $query_params );

		return $this->server->dispatch( $request );
	}
}
