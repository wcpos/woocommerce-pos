<?php
/**
 * Tests for the WooCommerce account identity tool.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_UnitTestCase;

/**
 * Exercises registration and the manual reset callback.
 *
 * @covers \WCPOS\WooCommercePOS\Admin\User_Identity_Tool
 */
class Test_User_Identity_Tool extends WP_UnitTestCase {

	/** Admin initialization registers a working reset tool alongside existing tools. */
	public function test_tool_single_site_registers_and_resets_identities(): void {
		delete_metadata( 'user', 0, Pos_Uuid::META_KEY, '', true );
		$user_id = $this->factory->user->create();
		Pos_Uuid::ensure_user_uuid( $user_id );
		( new Admin() )->init();
		$tools = apply_filters( 'woocommerce_debug_tools', array( 'existing' => array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce's filter.

		$this->assertArrayHasKey( 'existing', $tools );
		$this->assertArrayHasKey( 'wcpos_reset_user_identities', $tools );
		$tool = $tools['wcpos_reset_user_identities'];
		$this->assertSame( 'Reset WCPOS account identities', $tool['name'] );
		$this->assertSame( 'Reset identities', $tool['button'] );
		$this->assertSame( 'WCPOS account identities reset. Users affected: 1.', call_user_func( $tool['callback'] ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, Pos_Uuid::META_KEY ) );
	}

	/** A failed reset returns false through WooCommerce's tool callback. */
	public function test_tool_failed_delete_returns_false(): void {
		$user_id = $this->factory->user->create();
		Pos_Uuid::ensure_user_uuid( $user_id );
		( new Admin() )->init();
		$tools = apply_filters( 'woocommerce_debug_tools', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce's filter.
		add_filter( 'delete_user_metadata', '__return_false' );

		try {
			$this->assertFalse( call_user_func( $tools['wcpos_reset_user_identities']['callback'] ) );
		} finally {
			remove_filter( 'delete_user_metadata', '__return_false' );
		}
	}

	/**
	 * A network must not offer a store-local identity reset.
	 *
	 * @group multisite
	 */
	public function test_tool_multisite_is_not_registered(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs in the multisite suite only.' );
		}
		( new Admin() )->init();

		$tools = apply_filters( 'woocommerce_debug_tools', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise WooCommerce's filter.

		$this->assertArrayNotHasKey( 'wcpos_reset_user_identities', $tools );
	}
}
