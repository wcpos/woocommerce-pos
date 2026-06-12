<?php
/**
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Anon_ID;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Anon_ID extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_option( Anon_ID::OPTION );
	}

	public function tearDown(): void {
		delete_option( Anon_ID::OPTION );
		parent::tearDown();
	}

	public function test_get_with_no_stored_value_creates_and_persists_uuid4(): void {
		$service = new Anon_ID();
		$id      = $service->get();

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$id,
			'anon_id must be a v4 UUID'
		);
		$this->assertSame( $id, get_option( Anon_ID::OPTION ), 'generated id must be persisted' );
	}

	public function test_get_with_existing_value_is_stable_across_calls(): void {
		$service = new Anon_ID();
		$first   = $service->get();
		$second  = $service->get();
		$third   = ( new Anon_ID() )->get();

		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	public function test_rotate_replaces_the_stored_uuid(): void {
		$service = new Anon_ID();
		$old     = $service->get();
		$new     = $service->rotate();

		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, get_option( Anon_ID::OPTION ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $new );
	}

	public function test_delete_removes_the_option(): void {
		$service = new Anon_ID();
		$service->get();
		$service->delete();

		$this->assertFalse( get_option( Anon_ID::OPTION ) );
	}

	public function test_get_after_delete_generates_a_fresh_uuid(): void {
		$service = new Anon_ID();
		$old     = $service->get();
		$service->delete();
		$new = $service->get();

		$this->assertNotSame( $old, $new );
	}
}
