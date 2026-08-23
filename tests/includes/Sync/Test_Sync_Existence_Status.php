<?php
/**
 * Tests for publish-scoped sync existence endpoints.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * REST-dispatched existence endpoint status tests.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Digests_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 */
class Test_Sync_Existence_Status extends Sync_REST_Store_Test_Case {
	/**
	 * Initialize REST routes before status checks.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Dispatch a digests request.
	 *
	 * @param int[]       $ids        Requested ids.
	 * @param string      $collection Digest collection.
	 * @param string|null $status     Optional status.
	 *
	 * @return array<int, array{id: int, digest: string}>
	 */
	private function digests( array $ids, string $collection = 'products', ?string $status = null ): array {
		$params = array(
			'include'    => implode( ',', $ids ),
			'collection' => $collection,
		);
		if ( null !== $status ) {
			$params['status'] = $status;
		}
		$request = $this->wp_rest_get_request( '/wcpos/v2/digests' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data()['digests'];
	}

	/**
	 * Dispatch a digests request that asks for authoritative absence.
	 *
	 * @param int[]  $ids        Requested ids.
	 * @param string $collection Digest collection.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function digests_with_absence( array $ids, string $collection ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/digests' );
		$request->set_query_params(
			array(
				'include'    => implode( ',', $ids ),
				'collection' => $collection,
				'absence'    => 'explicit',
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data()['digests'];
	}

	/**
	 * Dispatch a single-id integrity bucket request.
	 *
	 * @param int         $id     Record id.
	 * @param string|null $status Optional status.
	 *
	 * @return array<int, array{id: int, digest: string, object_type: string}>
	 */
	private function bucket_rows( int $id, ?string $status = null ): array {
		$params = array(
			'collection'  => 'products',
			'bucket_size' => 1,
			'bucket'      => $id,
		);
		if ( null !== $status ) {
			$params['status'] = $status;
		}
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/bucket' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data()['ids'];
	}

	/**
	 * status=publish excludes a draft product from both existence surfaces.
	 */
	public function test_publish_status_excludes_draft_product(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_status( 'draft' );
		$product->save();
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );

		$this->assertSame( array( $product->get_id() ), array_column( $this->digests( array( $product->get_id() ) ), 'id' ) );
		$this->assertSame( array(), $this->digests( array( $product->get_id() ), 'products', 'publish' ) );
		$this->assertSame( array( $product->get_id() ), array_column( $this->bucket_rows( $product->get_id() ), 'id' ) );
		$this->assertSame( array(), $this->bucket_rows( $product->get_id(), 'publish' ) );
	}

	/**
	 * Variation publish scoping follows the parent product status.
	 */
	public function test_publish_status_scopes_variations_by_parent(): void {
		$published_parent = ProductHelper::create_variation_product();
		$draft_parent     = ProductHelper::create_variation_product();
		$draft_parent->set_status( 'draft' );
		$draft_parent->save();
		$published_variation = (int) $published_parent->get_children()[0];
		$draft_variation     = (int) $draft_parent->get_children()[0];
		$digest              = new Integrity_Digest();
		$digest->upsert_digest( $published_variation );
		$digest->upsert_digest( $draft_variation );

		$this->assertSame( 'publish', get_post_status( $draft_variation ) );
		$this->assertSame(
			array( $published_variation ),
			array_column( $this->digests( array( $published_variation, $draft_variation ), 'products', 'publish' ), 'id' )
		);
		$this->assertSame( array( $published_variation ), array_column( $this->bucket_rows( $published_variation, 'publish' ), 'id' ) );
		$this->assertSame( array(), $this->bucket_rows( $draft_variation, 'publish' ) );
	}

	/**
	 * Only literal publish opts into filtering; absent and other values retain the legacy result.
	 */
	public function test_no_status_and_other_status_return_identical_results(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_status( 'draft' );
		$product->save();
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );

		$this->assertSame(
			$this->digests( array( $product->get_id() ) ),
			$this->digests( array( $product->get_id() ), 'products', ' publish ' )
		);
		$this->assertSame( $this->bucket_rows( $product->get_id() ), $this->bucket_rows( $product->get_id(), ' publish ' ) );
	}

	/**
	 * A failed servable query must never manufacture an authoritative deletion.
	 *
	 * `deleted: true` tells the till to drop its local record — for the orders
	 * id-space that is order data — so the membership query fails OPEN: on any SQL
	 * error every requested id comes back servable and the response simply carries
	 * no digest for it. This guard predates the reader unification and moved into
	 * Digest_Index::servable() unchanged.
	 */
	public function test_servable_ids_sql_error_treats_all_ids_as_servable_result(): void {
		global $wpdb;

		// Arrange: an id with no stored digest and no live row, so absence is real.
		$missing_id = 987654;
		$this->assertFalse( get_userdata( $missing_id ), 'The fixture id must not name a live user.' );
		$break_servable = static function ( $query ) {
			if ( \is_string( $query ) && false !== strpos( $query, 'SELECT requested.id FROM (' ) ) {
				return 'SELECT wcpos_no_such_column FROM wcpos_no_such_table';
			}
			return $query;
		};

		// Act: once with a working query, once with the membership query broken.
		$reported                 = $this->digests_with_absence( array( $missing_id ), 'customers' );
		$previous_suppress_errors = $wpdb->suppress_errors();
		add_filter( 'query', $break_servable );
		try {
			$failed_open = $this->digests_with_absence( array( $missing_id ), 'customers' );
		} finally {
			remove_filter( 'query', $break_servable );
			$wpdb->suppress_errors( $previous_suppress_errors );
		}

		// Assert.
		$this->assertSame(
			array(
				array(
					'id' => $missing_id,
					'deleted' => true,
				),
			),
			$reported
		);
		$this->assertSame( array(), $failed_open, 'A database error must never reach the till as a deletion.' );
	}

	/**
	 * Customer digests include non-customer roles and ignore product status.
	 */
	public function test_customer_digests_include_non_customer_roles_and_ignore_publish_status(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		( new Integrity_Digest() )->upsert_customer_digest( $user_id );

		$this->assertSame(
			array( $user_id ),
			array_column( $this->digests( array( $user_id ), 'customers' ), 'id' )
		);
		$this->assertSame(
			array( $user_id ),
			array_column( $this->digests( array( $user_id ), 'customers', 'publish' ), 'id' )
		);
	}
}
