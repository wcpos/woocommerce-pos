<?php
/**
 * Tests for publish-scoped sync existence endpoints.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * REST-dispatched existence endpoint status tests.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Digests_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 */
class Test_Sync_Existence_Status extends Sync_REST_Store_Test_Case {
	/**
	 * Enable sync routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the non-transactional feature flag.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
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
	 * Product status does not scope the customer digest id-space.
	 */
	public function test_customer_digests_ignore_publish_status(): void {
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		( new Integrity_Digest() )->upsert_customer_digest( $customer_id );

		$this->assertSame(
			$this->digests( array( $customer_id ), 'customers' ),
			$this->digests( array( $customer_id ), 'customers', 'publish' )
		);
	}
}
