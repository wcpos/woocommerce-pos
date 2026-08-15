<?php
/** Variation writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

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
}
