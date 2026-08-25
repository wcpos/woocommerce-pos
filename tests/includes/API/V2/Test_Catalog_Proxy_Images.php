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
	 *
	 * On the SINGULAR key: a variation is served by WooCommerce's variations controller, whose
	 * response carries `image`, not the products controller's `images[]`. This assertion used to
	 * read `images[0]['src']` — it pinned the products-controller shape as correct and so stayed
	 * green while every variation thumbnail in the POS went blank (#1710).
	 */
	public function test_variation_read_serves_medium_image_url(): void {
		$attachment_id = $this->create_attachment( true );
		$variation     = $this->create_variation();
		$variation->set_image_id( $attachment_id );
		$variation->save();
		$medium_url = dirname( wp_get_attachment_url( $attachment_id ) ) . '/full-300x225.jpg';

		$document = $this->read_variation( $variation->get_id() );

		$this->assertSame( $medium_url, $document['payload']['image']['src'] );
	}

	/**
	 * Variation reads preserve the original image URL when medium is unavailable.
	 *
	 * The singular twin of the product case above: without the `image` branch in
	 * Product_Images::downsize_images() a variation is served the FULL SIZE original, which is
	 * the silent bandwidth regression the controller switch would otherwise introduce.
	 */
	public function test_variation_read_preserves_original_url_without_medium_image(): void {
		$attachment_id = $this->create_attachment( false );
		$variation     = $this->create_variation();
		$variation->set_image_id( $attachment_id );
		$variation->save();
		$original_url = wp_get_attachment_url( $attachment_id );

		$document = $this->read_variation( $variation->get_id() );

		$this->assertSame( $original_url, $document['payload']['image']['src'] );
	}

	/**
	 * A variation document is served in the VARIATION shape, not the product shape.
	 *
	 * The contract this file exists to hold, stated once: 1.9.x served variations from
	 * WooCommerce's variations controller and the client was built against that shape (its RxDB
	 * schema declares `image`; `variation-image.tsx` reads `payload.image.src`). Serializing a
	 * variation through the PRODUCTS controller silently swapped `image` for `images[]` and
	 * bolted ~25 product-only fields onto every variation. Nothing compared the two lanes, so
	 * both sides stayed green while the POS rendered blank thumbnails (#1710).
	 */
	public function test_variation_read_uses_the_variation_shape(): void {
		$variation = $this->create_variation();

		$payload = $this->read_variation( $variation->get_id() )['payload'];

		$this->assertArrayHasKey( 'image', $payload, 'a variation carries the singular image key' );
		$this->assertArrayNotHasKey( 'images', $payload, 'a variation is not a product' );

		// Product-only fields that mean nothing on a variation and rode the wire on every one of
		// them. Not an exhaustive list of the difference — a representative sample, so a
		// re-introduction of the products controller fails here rather than in a merchant's POS.
		foreach ( array( 'categories', 'tags', 'related_ids', 'price_html', 'default_attributes', 'variations' ) as $product_only ) {
			$this->assertArrayNotHasKey( $product_only, $payload, "a variation must not carry the product field {$product_only}" );
		}

		// What the client actually reads off a variation, all still present.
		foreach ( array( 'id', 'parent_id', 'name', 'sku', 'price', 'regular_price', 'stock_status', 'attributes', 'meta_data', 'type', 'date_modified_gmt' ) as $required ) {
			$this->assertArrayHasKey( $required, $payload, "the client reads {$required} off a variation" );
		}

		// The revision the client stores for a variation IS `date_modified_gmt` (Write_Controller
		// carves variations out of the payload hash), so this key is load-bearing for change
		// detection, not decoration.
		$this->assertSame( 'variation', $payload['type'] );

		// Attributes keep the singular-`option` shape on both controllers; the client's promoted
		// `attributes[]` column and its variation filter are built on it.
		if ( ! empty( $payload['attributes'] ) ) {
			$this->assertArrayHasKey( 'option', $payload['attributes'][0] );
			$this->assertArrayNotHasKey( 'options', $payload['attributes'][0] );
		}
	}
}
