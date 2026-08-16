<?php
/** Variation writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Writers\Variation_Writer;
use WP_REST_Response;
use WP_UnitTestCase;

/** Pins variation parent validation. */
class Test_Variation_Writer extends WP_UnitTestCase {
	/** Refuse creates without a live variable parent. */
	public function test_prepare_create_requires_parent(): void {
		$result = ( new Variation_Writer() )->prepare_create(
			array( 'route' => '/wc/v3/products' ), array( 'parent_id' => 0 ), static function () {}
		);
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 428, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_parent_required', $result->get_data()['code'] );
	}

	/** Reject a replay whose requested parent differs from the stored parent. */
	public function test_existing_create_with_different_parent_returns_mismatch(): void {
		$parent       = ProductHelper::create_variation_product();
		$variation_id = (int) current( $parent->get_children() );
		$result       = ( new Variation_Writer() )->validate_existing_create(
			$variation_id,
			array(),
			array( 'context' => array( 'parent_id' => $parent->get_id() + 1 ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'woo_rxdb_sync_parent_mismatch', $result->get_data()['code'] );
	}

	/** Write the client record id into the targeted variation document payload. */
	public function test_response_document_writes_record_id_into_payload(): void {
		$record_id    = '3abf7d3e-e3ca-4e4c-a044-bc9d50e507c7';
		$parent       = ProductHelper::create_variation_product();
		$variation_id = (int) current( $parent->get_children() );
		$writer       = new Variation_Writer();
		$response     = $writer->document( array( 'route' => '/wc/v3/products' ), $variation_id, static function () {} );
		$document     = $writer->build_response_document( $response->get_data(), $record_id, array(), $variation_id, static function () {} );
		$meta         = wp_list_pluck( $document['payload']['meta_data'], 'value', 'key' );

		$this->assertSame( $record_id, $meta['_woocommerce_pos_uuid'] );
	}
}
