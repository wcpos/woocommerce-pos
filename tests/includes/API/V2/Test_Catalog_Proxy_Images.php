<?php
/**
 * Tests for medium product images across the v2 catalog read surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Product_Images;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

/**
 * Product and variation reads serve medium images when available.
 */
class Test_Catalog_Proxy_Images extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the v2 routes before REST initialization.
	 */
	public function setUp(): void {
		// Init.php registrations do not run in the test bootstrap, so declare the
		// image augmentation on the pipeline here, exactly as
		// Augmentation_Pipeline::install() does — one declaration, both read lanes.
		Augmentation_Pipeline::reset();
		Augmentation_Pipeline::add_record_augmenter( array( Product_Images::class, 'augment_record' ), 10 );
		Augmentation_Pipeline::wire();
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		Augmentation_Pipeline::reset();
	}

	/**
	 * Create an image attachment with deterministic metadata.
	 *
	 * @param bool $has_medium Whether the attachment has a medium size.
	 */
	private function create_attachment( bool $has_medium ): int {
		$sizes = $has_medium
			? array(
				'medium' => array(
					'file'      => 'full-300x225.jpg',
					'width'     => 300,
					'height'    => 225,
					'mime-type' => 'image/jpeg',
				),
			)
			: array();

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'img',
				'post_status'    => 'inherit',
			),
			'2026/07/full.jpg'
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1200,
				'height' => 900,
				'file'   => '2026/07/full.jpg',
				'sizes'  => $sizes,
			)
		);

		return $attachment_id;
	}

	/**
	 * Create a variation from the standard WooCommerce test fixture.
	 */
	private function create_variation(): WC_Product_Variation {
		$parent       = ProductHelper::create_variation_product();
		$variation_id = current( $parent->get_children() );

		return new WC_Product_Variation( $variation_id );
	}

	/**
	 * Read one product through the v2 catalog proxy.
	 *
	 * @param int $product_id Product ID.
	 */
	private function read_product( int $product_id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $product_id ) );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	/**
	 * Read one variation through the v2 targeted variation endpoint.
	 *
	 * @param int $variation_id Variation ID.
	 */
	private function read_variation( int $variation_id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/variations' );
		$request->set_param( 'include', array( $variation_id ) );

		$response  = $this->server->dispatch( $request );
		$documents = $response->get_data()['documents'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $documents );

		return $documents[0];
	}

	/**
	 * Product reads serve the medium image URL when it exists.
	 */
	public function test_product_read_serves_medium_image_url(): void {
		$attachment_id = $this->create_attachment( true );
		$product       = ProductHelper::create_simple_product();
		$product->set_image_id( $attachment_id );
		$product->save();
		$medium_url = dirname( wp_get_attachment_url( $attachment_id ) ) . '/full-300x225.jpg';

		$row = $this->read_product( $product->get_id() );

		$this->assertSame( $medium_url, $row['images'][0]['src'] );
	}

	/**
	 * Product reads preserve the original image URL when medium is unavailable.
	 */
	public function test_product_read_preserves_original_url_without_medium_image(): void {
		$attachment_id = $this->create_attachment( false );
		$product       = ProductHelper::create_simple_product();
		$product->set_image_id( $attachment_id );
		$product->save();
		$original_url = wp_get_attachment_url( $attachment_id );

		$row = $this->read_product( $product->get_id() );

		$this->assertSame( $original_url, $row['images'][0]['src'] );
	}

	/**
	 * Variation reads serve the medium image URL when it exists.
	 */
	public function test_variation_read_serves_medium_image_url(): void {
		$attachment_id = $this->create_attachment( true );
		$variation     = $this->create_variation();
		$variation->set_image_id( $attachment_id );
		$variation->save();
		$medium_url = dirname( wp_get_attachment_url( $attachment_id ) ) . '/full-300x225.jpg';

		$document = $this->read_variation( $variation->get_id() );

		$this->assertSame( $medium_url, $document['payload']['images'][0]['src'] );
	}
}
