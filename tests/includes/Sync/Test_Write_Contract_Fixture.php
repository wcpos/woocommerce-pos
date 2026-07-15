<?php
/**
 * Golden sync write contract fixture tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab tests retain compact contract-focused documentation.

use ReflectionMethod;
use stdClass;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Sync\Header_Mirror;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Consumer checks for the vendored canonical contract.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Header_Mirror
 */
class Test_Write_Contract_Fixture extends WP_UnitTestCase {
	private function fixtures( string $name ): array {
		$path = __DIR__ . '/write-contract/fixtures/' . $name . '.json';
		$data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );

		return $data;
	}

	private function parse_and_validate( array $wire, string $path_collection ): array {
		$request = new WP_REST_Request( 'POST', '/' );
		// The controller parses the canonical envelope from the JSON body (get_json_params), so the wire
		// must be a real JSON body — else an absent key (a delete's payload) or an unknown property would
		// be lost through the get_param fallback. The lab stub returned set_body_params verbatim from
		// get_json_params (tests/bootstrap.php:148); real WP only parses an application/json body.
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $wire ) );
		$controller = new Write_Controller( new stdClass() );
		$envelope   = new ReflectionMethod( $controller, 'envelope' );
		$envelope->setAccessible( true );
		$parsed     = $envelope->invoke( $controller, $request );
		$validate   = new ReflectionMethod( $controller, 'validate_envelope' );
		$validate->setAccessible( true );

		return array( $parsed, $validate->invoke( $controller, $parsed, $path_collection ) );
	}

	public function test_valid_golden_envelopes_parse_exactly_and_are_accepted(): void {
		$fixtures = $this->fixtures( 'valid-envelopes' );
		foreach ( $fixtures as $fixture ) {
			list( $parsed, $error ) = $this->parse_and_validate( $fixture['envelope'], $fixture['collection'] );
			$this->assertSame( $fixture['envelope'], $parsed, $fixture['name'] );
			$this->assertNull( $error, $fixture['name'] );
		}
		$by_name = array_column( $fixtures, null, 'name' );
		$this->assertSame(
			array(
				array(
					'id' => 7,
					'option' => 'Blue',
				),
			),
			$by_name['variation-create-with-parent']['envelope']['payload']['attributes']
		);
		$this->assertSame( 'Ocean', $by_name['variation-create-with-parent']['envelope']['payload']['meta_data'][1]['value'] );
		$this->assertSame( 'summer', $by_name['coupon-create']['envelope']['payload']['meta_data'][1]['value'] );
	}

	public function test_invalid_golden_envelopes_are_rejected_with_documented_codes(): void {
		foreach ( $this->fixtures( 'invalid-envelopes' ) as $fixture ) {
			$path_collection = $fixture['pathCollection'] ?? ( $fixture['envelope']['collection'] ?? 'products' );
			list( , $error ) = $this->parse_and_validate( $fixture['envelope'], $path_collection );
			$this->assertInstanceOf( WP_Error::class, $error, $fixture['name'] );
			$this->assertSame( $fixture['errorCode'], $error->get_error_code(), $fixture['name'] );
		}
	}

	public function test_response_fixtures_match_the_controller_wire_shapes(): void {
		$fixtures = array_column( $this->fixtures( 'responses' ), null, 'name' );
		$this->assertSame( 201, $fixtures['replayed-create']['status'] );
		$this->assertSame( 41, $fixtures['replayed-create']['body']['document']['id'] );
		$this->assertSame( array(), $fixtures['deleted']['body'] );
		$this->assertSame( array( 'code', 'message', 'current', 'currentRevision' ), array_keys( $fixtures['conflict']['body'] ) );

		$schema = json_decode( (string) file_get_contents( __DIR__ . '/write-contract/schema.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame(
			array(
				array( '$ref' => '#/$defs/pushEnvelope' ),
				array( '$ref' => '#/$defs/successResponse' ),
				array( '$ref' => '#/$defs/deleteSuccessResponse' ),
				array( '$ref' => '#/$defs/conflictResponse' ),
				array( '$ref' => '#/$defs/codedResponse' ),
			),
			$schema['oneOf']
		);
		$this->assertSame( array( 'current', 'currentRevision' ), $schema['$defs']['codedResponse']['not']['required'] );
		$this->assertSame(
			array( 'products', 'orders', 'customers', 'categories', 'brands', 'variations', 'coupons' ),
			$schema['$defs']['pushEnvelope']['properties']['collection']['enum']
		);
		$this->assertSame( 'woo_rxdb_sync_parent_required', $schema['$defs']['parentRequiredResponse']['properties']['code']['const'] );
		$this->assertSame( 'woo_rxdb_sync_parent_mismatch', $schema['$defs']['parentMismatchResponse']['properties']['code']['const'] );
	}

	public function test_header_mirror_golden_pairs_use_the_production_cross_check(): void {
		foreach ( $this->fixtures( 'header-mirrors' ) as $fixture ) {
			$request = new WP_REST_Request( 'POST', '/' );
			foreach ( $fixture['headers'] as $name => $value ) {
				$request->set_header( $name, $value );
			}
			$result = Header_Mirror::assert( $request, $fixture['body']['mutationId'], $fixture['body']['baseRevision'] );
			if ( $fixture['accepted'] ) {
				$this->assertNull( $result, $fixture['name'] );
			} else {
				$this->assertInstanceOf( WP_Error::class, $result, $fixture['name'] );
				$this->assertSame( $fixture['errorCode'], $result->get_error_code(), $fixture['name'] );
			}
		}
	}
}
