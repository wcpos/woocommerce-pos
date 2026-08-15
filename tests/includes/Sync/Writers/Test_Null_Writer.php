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

	/** Build the generic update route without changing the payload. */
	public function test_update_is_pass_through(): void {
		$payload  = array( 'name' => 'Wholesale' );
		$prepared = ( new Null_Writer() )->prepare_update(
			array( 'route' => '/wc/v3/product-categories' ),
			17,
			$payload,
			static function () {}
		);

		$this->assertSame( 'PUT', $prepared['method'] );
		$this->assertSame( '/wc/v3/product-categories/17', $prepared['route'] );
		$this->assertSame( $payload, $prepared['payload'] );
	}

	/** Force generic deletes and send the resolved id to the forwarded request. */
	public function test_delete_sets_force_and_id_parameters(): void {
		$request = null;
		$result  = ( new Null_Writer() )->delete(
			array( 'route' => '/wc/v3/product-categories' ),
			17,
			array(),
			static function ( $forwarded ) use ( &$request ) {
				$request = $forwarded;
				return 'deleted';
			},
			static function () {
				return true;
			}
		);

		$this->assertSame( 'deleted', $result );
		$this->assertSame( 17, $request->get_param( 'id' ) );
		$this->assertTrue( $request->get_param( 'force' ) );
	}
}
