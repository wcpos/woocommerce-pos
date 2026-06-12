<?php
/**
 * Tests for the Anon ID service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Anon_ID;
use WP_UnitTestCase;

/**
 * Tests the anonymous analytics identity service.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Anon_ID
 */
class Test_Anon_ID extends WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Anon_ID::OPTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( Anon_ID::OPTION );
		parent::tearDown();
	}

	/**
	 * Asserts that get() creates and persists a v4 UUID when no value is stored.
	 */
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

	/**
	 * Asserts that repeated get() calls return the same UUID.
	 */
	public function test_get_with_existing_value_is_stable_across_calls(): void {
		$service = new Anon_ID();
		$first   = $service->get();
		$second  = $service->get();
		$third   = ( new Anon_ID() )->get();

		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	/**
	 * Asserts that rotate() replaces the stored UUID with a new one.
	 */
	public function test_rotate_replaces_the_stored_uuid(): void {
		$service = new Anon_ID();
		$old     = $service->get();
		$new     = $service->rotate();

		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, get_option( Anon_ID::OPTION ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $new );
	}

	/**
	 * Asserts that delete() removes the stored option.
	 */
	public function test_delete_removes_the_option(): void {
		$service = new Anon_ID();
		$service->get();
		$service->delete();

		$this->assertFalse( get_option( Anon_ID::OPTION ) );
	}

	/**
	 * Asserts that get() after delete() generates a fresh UUID.
	 */
	public function test_get_after_delete_generates_a_fresh_uuid(): void {
		$service = new Anon_ID();
		$old     = $service->get();
		$service->delete();
		$new = $service->get();

		$this->assertNotSame( $old, $new );
	}

	/**
	 * Asserts that the global accessor woocommerce_pos_get_anon_id() returns
	 * the same value as the Anon_ID service for the same site.
	 */
	public function test_global_accessor_returns_the_service_value(): void {
		$id = woocommerce_pos_get_anon_id();

		$this->assertSame( ( new Anon_ID() )->get(), $id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $id );
	}
}
