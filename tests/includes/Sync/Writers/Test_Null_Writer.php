<?php
/** Null writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Null_Writer;
use WP_REST_Response;
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

	/** Collect every forwarded delete and answer each from a queue. */
	private function dispatcher( array $responses, array &$forwarded ): \Closure {
		return static function ( $request ) use ( &$forwarded, &$responses ) {
			$forwarded[] = $request;
			return array_shift( $responses );
		};
	}

	private static function trash_refused(): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => 'woocommerce_rest_trash_not_supported' ), 501 );
	}

	private static function deleted(): WP_REST_Response {
		return new WP_REST_Response( array( 'id' => 17 ), 200 );
	}

	private function delete( array $mutation, array $responses, array &$forwarded ) {
		return ( new Null_Writer() )->delete(
			array( 'route' => '/wc/v3/product-categories' ),
			17,
			$mutation,
			$this->dispatcher( $responses, $forwarded ),
			static function () {
				return true;
			}
		);
	}

	private static function forces( array $forwarded ): array {
		return array_map( static fn( $request ) => $request->get_param( 'force' ), $forwarded );
	}

	/** #1741: without `force` the writer asks WooCommerce to trash. */
	public function test_delete_without_force_forwards_a_trash_request(): void {
		$forwarded = array();
		$result    = $this->delete( array(), array( self::deleted() ), $forwarded );

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( false ), self::forces( $forwarded ) );
		$this->assertSame( 17, $forwarded[0]->get_param( 'id' ) );
		$this->assertSame( '/wc/v3/product-categories/17', $forwarded[0]->get_route() );
	}

	/** #1741: when WooCommerce says the type cannot be trashed, retry once permanently. */
	public function test_delete_without_force_retries_permanently_when_trash_is_not_supported(): void {
		$forwarded = array();
		$result    = $this->delete( array(), array( self::trash_refused(), self::deleted() ), $forwarded );

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( false, true ), self::forces( $forwarded ) );
	}

	/** Any other refusal is returned as-is — only the trash-not-supported answer triggers the retry. */
	public function test_delete_without_force_does_not_retry_other_errors(): void {
		$forwarded = array();
		$refused   = new WP_REST_Response( array( 'code' => 'woocommerce_rest_cannot_delete' ), 403 );
		$result    = $this->delete( array(), array( $refused, self::deleted() ), $forwarded );

		$this->assertSame( $refused, $result );
		$this->assertSame( array( false ), self::forces( $forwarded ) );
	}

	/** An explicit envelope value is forwarded verbatim and never retried. */
	public function test_delete_with_explicit_force_is_forwarded_without_retry(): void {
		$forwarded = array();
		$result    = $this->delete( array( 'force' => false ), array( self::trash_refused(), self::deleted() ), $forwarded );
		$this->assertSame( 501, $result->get_status() );
		$this->assertSame( array( false ), self::forces( $forwarded ) );

		$forwarded = array();
		$result    = $this->delete( array( 'force' => true ), array( self::deleted() ), $forwarded );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( array( true ), self::forces( $forwarded ) );
	}
}
