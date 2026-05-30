<?php
/**
 * Tests for the prefixed firebase/php-jwt JWT::encode() header precedence.
 *
 * Locks the realignment of vendor_prefixed/firebase/php-jwt with upstream
 * firebase/php-jwt ^6.10 (the version pinned in php-scoper/composer.json): a
 * custom $head may override `typ`, but the signing `alg` is always authoritative
 * and cannot be spoofed through $head.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\Vendor\Firebase\JWT\JWT;
use WCPOS\Vendor\Firebase\JWT\Key;
use WP_UnitTestCase;

/**
 * JWT_Encode_Header_Test class.
 */
class JWT_Encode_Header_Test extends WP_UnitTestCase {
	/**
	 * Decode a JWT header segment into an array.
	 *
	 * @param string $jwt Encoded JWT.
	 *
	 * @return array
	 */
	private function decode_header( string $jwt ): array {
		$segment = explode( '.', $jwt )[0];

		return (array) json_decode( base64_decode( strtr( $segment, '-_', '+/' ), true ), true );
	}

	/**
	 * It lets $head override typ but keeps the signing alg authoritative.
	 */
	public function test_encode_head_overrides_typ_but_not_alg(): void {
		// Arrange / Act: attempt to override both typ and alg via $head.
		$jwt    = JWT::encode( array( 'sub' => 1 ), 'secret', 'HS256', null, array( 'typ' => 'CUSTOM', 'alg' => 'none' ) );
		$header = $this->decode_header( $jwt );

		// Assert: typ from $head wins (upstream precedence); alg is the real
		// signing algorithm and cannot be spoofed through $head.
		$this->assertEquals( 'CUSTOM', $header['typ'] );
		$this->assertEquals( 'HS256', $header['alg'] );
	}

	/**
	 * It produces the default JWT header and round-trips with no custom head.
	 */
	public function test_encode_default_header_round_trips(): void {
		// Arrange / Act.
		$token   = JWT::encode( array( 'sub' => 42 ), 'secret', 'HS256' );
		$header  = $this->decode_header( $token );
		$decoded = JWT::decode( $token, new Key( 'secret', 'HS256' ) );

		// Assert.
		$this->assertEquals( 'JWT', $header['typ'] );
		$this->assertEquals( 'HS256', $header['alg'] );
		$this->assertEquals( 42, $decoded->sub );
	}
}
