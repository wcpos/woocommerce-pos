<?php
/**
 * Tests for the wcpos/v2 products ID fast path (#2113).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Store_Scope;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Response;
use WP_User;

/**
 * `per_page=-1` with exactly the four reconciliation fields is one SQL query whose rows
 * match the hydrated listing; every other request stays hydrated.
 */
class Test_Catalog_Proxy_Products_Fast_Path extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The four reconciliation fields, in response order.
	 */
	private const FIELDS = array( 'id', 'date_modified_gmt', 'stock_quantity', 'stock_status' );

	/**
	 * Calls to `woocommerce_rest_prepare_product_object` (one per hydrated product).
	 *
	 * @var int
	 */
	private $prepared = 0;

	/**
	 * Calls to `woocommerce_pos_sync_proxy_response` (the proxy's filter chain).
	 *
	 * @var int
	 */
	private $proxy_filtered = 0;

	/**
	 * Inner wc/v3 dispatches (the proxy's forward).
	 *
	 * @var int
	 */
	private $forwarded = 0;

	/**
	 * Install the production sync read lane and the hydration counters.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_read_lane();
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'count_prepared' ) );
		add_filter( 'woocommerce_pos_sync_proxy_response', array( $this, 'count_proxy_filtered' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'count_forwarded' ), 10, 3 );
	}

	/**
	 * Remove the counters, the lane and settings written outside the test transaction.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'count_prepared' ) );
		remove_filter( 'woocommerce_pos_sync_proxy_response', array( $this, 'count_proxy_filtered' ) );
		remove_filter( 'rest_pre_dispatch', array( $this, 'count_forwarded' ), 10 );
		parent::tearDown();
		$this->uninstall_sync_read_lane();
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
	}

	/**
	 * Count one hydrated product.
	 *
	 * @param mixed $response Prepared product response.
	 *
	 * @return mixed
	 */
	public function count_prepared( $response ) {
		++$this->prepared;

		return $response;
	}

	/**
	 * Count one pass through the proxy's filter chain.
	 *
	 * @param mixed $data Proxy batch.
	 *
	 * @return mixed
	 */
	public function count_proxy_filtered( $data ) {
		++$this->proxy_filtered;

		return $data;
	}

	/**
	 * Count one inner wc/v3 dispatch.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return mixed
	 */
	public function count_forwarded( $result, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/v3/' ) ) {
			++$this->forwarded;
		}

		return $result;
	}

	/**
	 * Dispatch a products request on the v2 route.
	 *
	 * @param array $params  Query parameters.
	 * @param array $headers Extra request headers.
	 */
	private function dispatch_products( array $params, array $headers = array() ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_query_params( $params );

		return $this->server->dispatch( $request );
	}

	/**
	 * Read the fast path: `per_page=-1` plus the four fields.
	 *
	 * @param array $params Extra query parameters.
	 */
	private function fast( array $params = array() ): WP_REST_Response {
		$response = $this->dispatch_products(
			array_merge(
				array(
					'per_page' => '-1',
					'_fields'  => implode( ',', self::FIELDS ),
				),
				$params
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response;
	}

	/**
	 * Walk the hydrated `status=publish` listing (`per_page=100`) until an empty page, projected
	 * to the four fields — the set the fast path serves.
	 *
	 * @param array $params Extra query parameters.
	 */
	private function hydrated( array $params = array() ): array {
		$rows = array();
		for ( $page = 1; $page < 100; $page++ ) {
			$response = $this->dispatch_products(
				array_merge(
					array( 'status' => 'publish' ),
					$params,
					array(
						'per_page' => 100,
						'page'     => $page,
					)
				)
			);
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			if ( array() === $response->get_data() ) {
				break;
			}
			foreach ( $response->get_data() as $row ) {
				$rows[] = array(
					'id'                => $row['id'],
					'date_modified_gmt' => $row['date_modified_gmt'],
					'stock_quantity'    => $row['stock_quantity'],
					'stock_status'      => $row['stock_status'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Ids of a list of rows.
	 *
	 * @param array $rows Rows carrying `id`.
	 *
	 * @return int[]
	 */
	private static function ids( array $rows ): array {
		return array_map( 'intval', array_column( $rows, 'id' ) );
	}

	/**
	 * Create a simple product.
	 *
	 * @param array  $props  Product props.
	 * @param string $status Post status.
	 */
	private function product( array $props = array(), string $status = 'publish' ): WC_Product {
		$product = ProductHelper::create_simple_product( $props );
		if ( 'publish' !== $status ) {
			$product->set_status( $status );
			$product->save();
		}

		return $product;
	}

	/**
	 * Create the standard fixture set, keyed by role.
	 *
	 * Some products share a creation date and some do not, so the order check sees
	 * both the `post_date DESC` key and the `ID ASC` tiebreak.
	 *
	 * @return array<string, int>
	 */
	private function fixtures(): array {
		$ids = array(
			'managed'     => $this->product(
				array(
					'manage_stock'   => true,
					'stock_quantity' => 5,
					'date_created'   => '2024-03-01 10:00:00',
				)
			)->get_id(),
			'unmanaged'   => $this->product( array( 'date_created' => '2024-03-01 10:00:00' ) )->get_id(),
			'outofstock'  => $this->product(
				array(
					'stock_status' => 'outofstock',
					'date_created' => '2024-05-01 10:00:00',
				)
			)->get_id(),
			'onbackorder' => $this->product(
				array(
					'stock_status' => 'onbackorder',
					'date_created' => '2024-01-01 10:00:00',
				)
			)->get_id(),
			'draft'       => $this->product( array( 'date_created' => '2024-03-01 10:00:00' ), 'draft' )->get_id(),
			'private'     => $this->product( array( 'date_created' => '2024-02-01 10:00:00' ), 'private' )->get_id(),
			'trashed'     => $this->product()->get_id(),
			'auto_draft'  => wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'auto-draft',
				)
			),
		);
		wp_trash_post( $ids['trashed'] );

		return $ids;
	}

	/**
	 * Configure a product as hidden from the POS (the POS-only feature on).
	 *
	 * @param int $product_id Product ID.
	 */
	private function hide_product( int $product_id ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $product_id ) ),
					),
				),
			)
		);
	}

	/**
	 * Set a product's local and GMT modified dates directly.
	 *
	 * @param int    $id    Product ID.
	 * @param string $local `post_modified`.
	 * @param string $gmt   `post_modified_gmt`.
	 */
	private function set_modified( int $id, string $local, string $gmt ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $local,
				'post_modified_gmt' => $gmt,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
	}

	/**
	 * The fast path serves the hydrated listing's rows, in its order, with its values.
	 */
	public function test_fast_path_matches_the_hydrated_listing(): void {
		$ids = $this->fixtures();

		$expected = $this->hydrated();
		$actual   = $this->fast()->get_data();

		$this->assertSame( $expected, $actual );
		$served = self::ids( $actual );
		$this->assertContains( $ids['managed'], $served );
		$this->assertNotContains( $ids['draft'], $served );
		$this->assertNotContains( $ids['private'], $served );
		$this->assertNotContains( $ids['draft'], self::ids( $expected ) );
		$this->assertNotContains( $ids['private'], self::ids( $expected ) );
		$this->assertNotContains( $ids['trashed'], $served );
		$this->assertNotContains( $ids['auto_draft'], $served );
	}

	/**
	 * Each fast-path row is the four fields, typed, and the totals describe one page.
	 */
	public function test_fast_path_rows_carry_only_the_four_fields_and_totals(): void {
		$ids = $this->fixtures();

		$response = $this->fast();
		$rows     = array_column( $response->get_data(), null, 'id' );
		$headers  = $response->get_headers();

		$this->assertSame( (string) \count( $rows ), (string) $headers['X-WP-Total'] );
		$this->assertSame( '1', $headers['X-WP-TotalPages'] );
		foreach ( $rows as $row ) {
			$this->assertSame( self::FIELDS, array_keys( $row ) );
			$this->assertIsInt( $row['id'] );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $row['date_modified_gmt'] );
		}
		$this->assertSame( 5, $rows[ $ids['managed'] ]['stock_quantity'] );
		$this->assertSame( 'instock', $rows[ $ids['managed'] ]['stock_status'] );
		$this->assertNull( $rows[ $ids['unmanaged'] ]['stock_quantity'] );
		$this->assertSame( 'outofstock', $rows[ $ids['outofstock'] ]['stock_status'] );
		$this->assertSame( 'onbackorder', $rows[ $ids['onbackorder'] ]['stock_status'] );
	}

	/**
	 * Only published products are listed; draft, private and pending ones are absent.
	 */
	public function test_fast_path_lists_published_products_only(): void {
		$published = $this->product()->get_id();
		$draft     = $this->product( array(), 'draft' )->get_id();
		$private   = $this->product( array(), 'private' )->get_id();
		$pending   = $this->product( array(), 'pending' )->get_id();

		$served = self::ids( $this->fast()->get_data() );

		$this->assertContains( $published, $served );
		$this->assertNotContains( $draft, $served );
		$this->assertNotContains( $private, $served );
		$this->assertNotContains( $pending, $served );
	}

	/**
	 * Stock matches the hydrated product whether its lookup row is missing, stale or current,
	 * and a missing `_stock_status` answers WooCommerce's `instock` fallback.
	 */
	public function test_fast_path_stock_matches_the_product_with_or_without_a_lookup_row(): void {
		global $wpdb;
		$missing   = $this->product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'stock_status'   => 'outofstock',
			)
		)->get_id();
		$stale     = $this->product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 5,
			)
		)->get_id();
		$no_status = $this->product( array( 'stock_status' => 'outofstock' ) )->get_id();
		$wpdb->delete( $wpdb->wc_product_meta_lookup, array( 'product_id' => $missing ) );
		// Written outside the data layer: the lookup row keeps 5.
		update_post_meta( $stale, '_stock', '3' );
		delete_post_meta( $no_status, '_stock_status' );

		$rows     = array_column( $this->fast()->get_data(), null, 'id' );
		$hydrated = array_column( $this->hydrated(), null, 'id' );

		$this->assertSame( 0, $rows[ $missing ]['stock_quantity'] );
		$this->assertSame( 'outofstock', $rows[ $missing ]['stock_status'] );
		$this->assertSame( 3, $hydrated[ $stale ]['stock_quantity'] );
		$this->assertSame( 'instock', $hydrated[ $no_status ]['stock_status'] );
		foreach ( array( $missing, $stale, $no_status ) as $id ) {
			$this->assertSame( $hydrated[ $id ]['stock_quantity'], $rows[ $id ]['stock_quantity'], "stock_quantity {$id}" );
			$this->assertSame( $hydrated[ $id ]['stock_status'], $rows[ $id ]['stock_status'], "stock_status {$id}" );
		}
	}

	/**
	 * A leftover `_stock` on an unmanaged product is reported by the product, so the fast path reports it too.
	 */
	public function test_fast_path_unmanaged_product_with_leftover_stock_reports_the_hydrated_value(): void {
		$unmanaged = $this->product( array( 'manage_stock' => false ) )->get_id();
		update_post_meta( $unmanaged, '_stock', '7' );

		$rows     = array_column( $this->fast()->get_data(), null, 'id' );
		$hydrated = array_column( $this->hydrated(), null, 'id' );

		$this->assertSame( 7, $hydrated[ $unmanaged ]['stock_quantity'] );
		$this->assertSame( 7, $rows[ $unmanaged ]['stock_quantity'] );
		$this->assertSame( $hydrated[ $unmanaged ]['stock_quantity'], $rows[ $unmanaged ]['stock_quantity'] );
	}

	/**
	 * The fast path neither forwards, hydrates nor filters, and its query count is flat in N.
	 */
	public function test_fast_path_does_not_hydrate_products(): void {
		global $wpdb;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->product();
		}
		$this->fast();
		$before = $wpdb->num_queries;
		$three  = $this->fast()->get_data();
		$small  = $wpdb->num_queries - $before;
		for ( $i = 0; $i < 9; $i++ ) {
			$this->product();
		}
		$this->fast();
		$this->prepared       = 0;
		$this->proxy_filtered = 0;
		$this->forwarded      = 0;

		$before = $wpdb->num_queries;
		$twelve = $this->fast()->get_data();
		$large  = $wpdb->num_queries - $before;

		$this->assertSame( 0, $this->prepared );
		$this->assertSame( 0, $this->proxy_filtered );
		$this->assertSame( 0, $this->forwarded );
		$this->assertSame( \count( $three ) + 9, \count( $twelve ) );
		$this->assertSame( $small, $large );
	}

	/**
	 * `include`, `exclude` (ignored under `include`) and `modified_after` select the hydrated ids.
	 */
	public function test_fast_path_honours_include_exclude_and_modified_after(): void {
		$ids   = $this->fixtures();
		$later = $ids['managed'];
		$early = $ids['unmanaged'];
		// UTC+10 in the stored columns: the GMT cutoff keeps $later and drops $early.
		$this->set_modified( $later, '2020-06-01 12:00:00', '2020-06-01 02:00:00' );
		$this->set_modified( $early, '2020-06-01 08:00:00', '2020-05-31 22:00:00' );
		foreach ( $ids as $role => $id ) {
			if ( ! in_array( $role, array( 'managed', 'unmanaged' ), true ) ) {
				$this->set_modified( $id, '2019-01-01 00:00:00', '2019-01-01 00:00:00' );
			}
		}
		$cases = array(
			'include'         => array( 'include' => implode( ',', array( $later, $early, $ids['draft'] ) ) ),
			'exclude'         => array( 'exclude' => array( $later, $ids['private'] ) ),
			'include+exclude' => array(
				'include' => array( $later, $early ),
				'exclude' => array( $later ),
			),
			'modified local'  => array( 'modified_after' => '2020-06-01T01:00:00' ),
			'modified gmt'    => array(
				'modified_after' => '2020-06-01T01:00:00',
				'dates_are_gmt'  => 'true',
			),
		);

		$served = array();
		foreach ( $cases as $name => $params ) {
			$served[ $name ] = self::ids( $this->fast( $params )->get_data() );

			$this->assertSame( self::ids( $this->hydrated( $params ) ), $served[ $name ], $name );
		}

		$this->assertSame( array( $later, $early ), $served['include+exclude'] );
		$this->assertSame( array( $later, $early ), $served['modified local'] );
		$this->assertSame( array( $later ), $served['modified gmt'] );
	}

	/**
	 * A product hidden from the POS is absent from the fast path, as from the hydrated listing.
	 */
	public function test_fast_path_excludes_products_hidden_from_the_pos(): void {
		$visible = $this->product();
		$hidden  = $this->product();
		$this->hide_product( $hidden->get_id() );

		$served   = self::ids( $this->fast()->get_data() );
		$hydrated = self::ids( $this->hydrated() );

		$this->assertNotContains( $hidden->get_id(), $hydrated );
		$this->assertNotContains( $hidden->get_id(), $served );
		$this->assertContains( $visible->get_id(), $served );
		$this->assertSame( $hydrated, $served );
	}

	/**
	 * Any request that is not exactly the fast-path shape reaches wc/v3.
	 */
	public function test_hydrated_requests_never_take_the_fast_path(): void {
		$this->product();
		$fields = implode( ',', self::FIELDS );

		$this->prepared = 0;
		$response       = $this->dispatch_products(
			array(
				'per_page' => 100,
				'_fields'  => $fields,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThan( 0, $this->prepared, 'per_page=100' );

		$fast_shape = array(
			'per_page' => '-1',
			'_fields'  => $fields,
		);
		$cases      = array(
			'one field missing' => array( '_fields' => 'id,date_modified_gmt,stock_quantity' ),
			'one extra field'   => array( '_fields' => $fields . ',name' ),
			'duplicate field'   => array( '_fields' => $fields . ',id' ),
			'_fields absent'    => array( '_fields' => null ),
			'search'            => array( 'search' => 'x' ),
			'orderby'           => array( 'orderby' => 'title' ),
			'store scope param' => array( Store_Scope::PARAM => '1' ),
			'bad modified'      => array( 'modified_after' => 'yesterday' ),
		);
		foreach ( $cases as $name => $overrides ) {
			$params   = array_filter( array_merge( $fast_shape, $overrides ), 'is_scalar' );
			$response = $this->dispatch_products( $params );

			$this->assert_wc_v3_rejected_per_page( $response, $name );
		}

		$response = $this->dispatch_products( $fast_shape, array( Store_Scope::HEADER => '1' ) );
		$this->assert_wc_v3_rejected_per_page( $response, 'store scope header' );
	}

	/**
	 * `_fields` sent as an array, in any order, still takes the fast path.
	 */
	public function test_fast_path_accepts_fields_as_an_array_in_any_order(): void {
		$product = $this->product();

		$rows = $this->fast( array( '_fields' => array_reverse( self::FIELDS ) ) )->get_data();

		$this->assertSame( 0, $this->prepared );
		$this->assertContains( $product->get_id(), self::ids( $rows ) );
		$this->assertSame( self::FIELDS, array_keys( $rows[0] ) );
	}

	/**
	 * A cashier wc/v3 refuses to list products for is refused by the fast path with
	 * wc/v3's own error, answered without the forward and without the query.
	 */
	public function test_fast_path_refuses_a_cashier_who_cannot_read_products_like_the_hydrated_route(): void {
		$this->product();
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		// The cap wc_rest_check_post_permissions( 'product', 'read' ) checks; the role stays untouched.
		( new WP_User( $cashier_id ) )->add_cap( 'read_private_products', false );
		wp_set_current_user( $cashier_id );

		$this->forwarded = 0;
		$fast            = $this->dispatch_products(
			array(
				'per_page' => '-1',
				'_fields'  => implode( ',', self::FIELDS ),
			)
		);
		$fast_forwarded  = $this->forwarded;
		$hydrated        = $this->dispatch_products( array( 'per_page' => 10 ) );

		$this->assertTrue( $fast->is_error(), wp_json_encode( $fast->get_data() ) );
		$this->assertTrue( $hydrated->is_error(), wp_json_encode( $hydrated->get_data() ) );
		$this->assertSame( $hydrated->get_status(), $fast->get_status() );
		$this->assertSame( $hydrated->get_data()['code'], $fast->get_data()['code'] );
		$this->assertSame( 'woocommerce_rest_cannot_view', $fast->get_data()['code'] );
		$this->assertSame( 0, $fast_forwarded );
		$this->assertSame( 0, $this->prepared );
	}

	/**
	 * As a stock cashier, the fast path serves the hydrated listing's rows.
	 */
	public function test_fast_path_parity_as_a_cashier(): void {
		$this->fixtures();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$expected = $this->hydrated();
		$actual   = $this->fast()->get_data();

		$this->assertNotEmpty( $actual );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Assert wc/v3 answered: its `per_page` 400, which only the forward can produce.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $name     Case name.
	 */
	private function assert_wc_v3_rejected_per_page( WP_REST_Response $response, string $name ): void {
		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status(), $name );
		$this->assertSame( 'rest_invalid_param', $data['code'], $name );
		$this->assertArrayHasKey( 'per_page', $data['data']['params'], $name );
	}
}
