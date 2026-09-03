<?php
/**
 * Options the Init constructor reads on EVERY request ride in alloptions.
 *
 * Measured 2026-09-03 on dev-next (see
 * .claude/research/2026-09-03-online-store-footprint.md): with no persistent
 * object cache, each of `wcpos_sync_schema_version`,
 * `woocommerce_pos_sync_visibility_tombstone_seed` and
 * `woocommerce_pos_sync_config_fingerprint_cleanup_version` cost one
 * `SELECT option_value` per storefront page because they were written with
 * `autoload = false`. They are tiny latches consulted before `init` on every
 * load; autoloading them makes those reads free.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Sync\Visibility_Observer;

/**
 * @covers \WCPOS\WooCommercePOS\Activator
 * @covers \WCPOS\WooCommercePOS\Sync\Config_Fingerprint
 * @covers \WCPOS\WooCommercePOS\Sync\Visibility_Observer
 */
class Test_Request_Latch_Options_Autoload extends Sync_Store_Test_Case {
	public function test_the_sync_schema_latch_is_written_autoloaded(): void {
		delete_option( Api::SCHEMA_OPTION );

		( new Activator() )->install_sync_schema();

		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
		$this->assertTrue( $this->is_autoloaded( Api::SCHEMA_OPTION ) );
	}

	public function test_the_tombstone_seed_latch_is_written_autoloaded(): void {
		delete_option( Visibility_Observer::SEED_VERSION_OPTION );

		( new Visibility_Observer() )->maybe_seed_hidden_tombstones();

		$this->assertTrue( $this->is_autoloaded( Visibility_Observer::SEED_VERSION_OPTION ) );
	}

	public function test_the_fingerprint_cleanup_latch_is_written_autoloaded(): void {
		delete_option( Config_Fingerprint::CLEANUP_VERSION_OPTION );

		( new Config_Fingerprint() )->maybe_cleanup_legacy_options();

		$this->assertTrue( $this->is_autoloaded( Config_Fingerprint::CLEANUP_VERSION_OPTION ) );
	}

	public function test_the_upgrade_flips_existing_latches_to_autoload(): void {
		// An install that predates this change has all three rows with autoload off.
		update_option( Api::SCHEMA_OPTION, Api::SCHEMA_VERSION, false );
		update_option( Visibility_Observer::SEED_VERSION_OPTION, 1, false );
		update_option( Config_Fingerprint::CLEANUP_VERSION_OPTION, 1, false );
		$this->assertFalse( $this->is_autoloaded( Api::SCHEMA_OPTION ) );

		Activator::autoload_request_latches();

		$this->assertTrue( $this->is_autoloaded( Api::SCHEMA_OPTION ) );
		$this->assertTrue( $this->is_autoloaded( Visibility_Observer::SEED_VERSION_OPTION ) );
		$this->assertTrue( $this->is_autoloaded( Config_Fingerprint::CLEANUP_VERSION_OPTION ) );
		// Values survive the flip.
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION ) );
		$this->assertArrayHasKey( Api::SCHEMA_OPTION, wp_load_alloptions( true ) );
	}

	public function test_reactivation_also_flips_the_latches(): void {
		// db_upgrade() can be missed permanently once bump_versions() ran;
		// deactivate/reactivate is the repair a merchant can reach.
		update_option( Config_Fingerprint::CLEANUP_VERSION_OPTION, 1, false );
		$this->assertFalse( $this->is_autoloaded( Config_Fingerprint::CLEANUP_VERSION_OPTION ) );

		( new Activator() )->single_activate( false );

		$this->assertTrue( $this->is_autoloaded( Config_Fingerprint::CLEANUP_VERSION_OPTION ) );
	}

	public function test_the_upgrade_flip_ignores_a_missing_latch(): void {
		delete_option( Visibility_Observer::SEED_VERSION_OPTION );

		Activator::autoload_request_latches();

		$this->assertFalse( get_option( Visibility_Observer::SEED_VERSION_OPTION ), 'The flip must not invent a latch that was never written.' );
	}

	private function is_autoloaded( string $option ): bool {
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the stored flag is the point.
		// WordPress 6.6+ stores 'on'/'off' (and 'auto-on'); older versions 'yes'/'no'.
		return \in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true );
	}
}
