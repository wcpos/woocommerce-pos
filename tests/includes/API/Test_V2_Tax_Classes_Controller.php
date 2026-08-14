<?php
/**
 * Tests for the wcpos/v2 tax classes service pass-through.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * wcpos/v2 tax classes service parity tests.
 */
class Test_V2_Tax_Classes_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Authorized tax-class reads match the frozen v1 service.
	 */
	public function test_authorized_v2_tax_classes_matches_v1_status_and_payload(): void {
		$this->assertArrayHasKey( '/wcpos/v1/taxes/classes', $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertArrayHasKey( '/wcpos/v2/taxes/classes', $this->server->get_routes( 'wcpos/v2' ) );

		$v1_response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/taxes/classes' ) );
		$v2_response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/taxes/classes' ) );

		$this->assertSame( 200, $v1_response->get_status() );
		$this->assertSame( $v1_response->get_status(), $v2_response->get_status() );

		// _links hrefs carry the request namespace by design — compare the
		// payloads without them; everything else must be identical.
		$strip_links = static function ( array $records ): array {
			return array_map(
				static function ( $record ) {
					unset( $record['_links'] );
					return $record;
				},
				$records
			);
		};
		$this->assertEquals(
			$strip_links( $v1_response->get_data() ),
			$strip_links( $v2_response->get_data() )
		);
	}
}
