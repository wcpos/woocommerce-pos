<?php
/** Null writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Null_Writer;
use WP_UnitTestCase;

/** Pins pass-through preparation and forwarding. */
class Test_Null_Writer extends WP_UnitTestCase {
	/** Forward the payload and route unchanged. */
	public function test_create_is_pass_through(): void {
		$payload  = array( 'name' => 'Retail' );
		$writer   = new Null_Writer();
		$prepared = $writer->prepare_create( array( 'route' => '/wc/v3/product-categories' ), $payload, static function () {} );
		$result   = $writer->forward( $prepared, static function ( $method, $route, $body ) { return array( $method, $route, $body ); } );
		$this->assertSame( array( 'POST', '/wc/v3/product-categories', $payload ), $result );
	}
}
