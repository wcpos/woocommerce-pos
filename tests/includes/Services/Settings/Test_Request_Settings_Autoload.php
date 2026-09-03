<?php
/**
 * The settings options every request reads ride in alloptions.
 *
 * Measured 2026-09-03 on dev-free (see
 * .claude/research/2026-09-03-online-store-footprint.md): after the sync
 * latches were autoloaded, `woocommerce_pos_settings_general` (read by the
 * Settings service during init) and `woocommerce_pos_settings_permalink`
 * (read by Template_Router's rewrite regex) were still queried on every
 * storefront page — the permalink row did not even exist on a store that
 * never set a slug, and an ABSENT option is queried on every request too.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\Services\Settings\Checkout_Section;
use WCPOS\WooCommercePOS\Services\Settings\General_Section;
use WCPOS\WooCommercePOS\Services\Settings\Visibility_Section;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Services\Settings\General_Section
 * @covers \WCPOS\WooCommercePOS\Admin\Permalink
 * @covers \WCPOS\WooCommercePOS\Activator
 */
class Test_Request_Settings_Autoload extends WP_UnitTestCase {
	private const GENERAL    = 'woocommerce_pos_settings_general';
	private const VISIBILITY = 'woocommerce_pos_settings_visibility';
	private const CHECKOUT   = 'woocommerce_pos_settings_checkout';
	private const PERMALINK  = Permalink::DB_KEY;
	private const TOOLS      = 'woocommerce_pos_settings_tools';

	public function tearDown(): void {
		delete_option( self::GENERAL );
		delete_option( self::VISIBILITY );
		delete_option( self::CHECKOUT );
		delete_option( self::PERMALINK );
		delete_option( self::TOOLS );
		parent::tearDown();
	}

	public function test_general_settings_are_written_autoloaded(): void {
		delete_option( self::GENERAL );

		$section = new General_Section();
		$section->write( array_merge( $section->defaults(), array( 'store_name' => 'Autoload probe' ) ) );

		$this->assertSame( 'Autoload probe', get_option( self::GENERAL )['store_name'] );
		$this->assertTrue( $this->is_autoloaded( self::GENERAL ) );
	}

	public function test_sections_that_can_hold_unbounded_lists_keep_autoload_off(): void {
		// Visibility holds product/variation id lists that can outgrow the
		// alloptions budget; Checkout is only read on POS requests. Both keep the
		// legacy byte-compatible write path.
		foreach ( array( new Visibility_Section(), new Checkout_Section() ) as $section ) {
			delete_option( $section->option_name() );
			$this->assertFalse( $section->autoload() );

			$section->write( $section->defaults() );

			$this->assertFalse( $this->is_autoloaded( $section->option_name() ), $section->id() );
		}
	}

	public function test_permalink_is_written_autoloaded(): void {
		delete_option( self::PERMALINK );
		$_POST['woocommerce_pos_permalink']  = '/till/';
		$_POST['wcpos-permalinks-nonce']     = wp_create_nonce( 'wcpos-permalinks' );

		( new Permalink() )->save();
		unset( $_POST['woocommerce_pos_permalink'], $_POST['wcpos-permalinks-nonce'] );

		$this->assertSame( 'till', Permalink::get_slug() );
		$this->assertTrue( $this->is_autoloaded( self::PERMALINK ) );
	}

	public function test_a_missing_permalink_row_is_seeded_empty_and_autoloaded_and_a_custom_one_is_kept(): void {
		// An absent option is queried on every request (notoptions is per request),
		// so a store that never customised the slug paid for it on every page. The
		// seeded row is EMPTY so get_slug() keeps resolving DEFAULT_SLUG.
		delete_option( self::PERMALINK );

		Permalink::ensure_default();

		$this->assertSame( '', get_option( self::PERMALINK ) );
		$this->assertSame( Permalink::DEFAULT_SLUG, Permalink::get_slug() );
		$this->assertTrue( $this->is_autoloaded( self::PERMALINK ) );

		update_option( self::PERMALINK, 'till', true );
		Permalink::ensure_default();
		$this->assertSame( 'till', get_option( self::PERMALINK ), 'A customised slug is never overwritten.' );
	}

	public function test_a_missing_autoloaded_section_is_seeded_and_reads_its_defaults(): void {
		// A fresh install has never saved General; the Settings service reads it
		// on every request regardless. Only consent is persisted so its legacy
		// lookup leaves the hot path; read() still merges the other defaults.
		delete_option( self::GENERAL );

		Activator::autoload_request_latches();

		$this->assertSame( array( 'tracking_consent' => 'undecided' ), get_option( self::GENERAL ) );
		$this->assertTrue( $this->is_autoloaded( self::GENERAL ) );
		$section = new General_Section();
		$this->assertSame( $section->defaults()['barcode_field'], $section->read()['barcode_field'] );
		$this->assertFalse( get_option( self::CHECKOUT ), 'Only sections that declare autoload() are seeded.' );
	}

	public function test_seeding_general_persists_legacy_tracking_consent(): void {
		delete_option( self::GENERAL );
		update_option( self::TOOLS, array( 'tracking_consent' => 'allowed' ), false );

		Activator::autoload_request_latches();
		delete_option( self::TOOLS );

		$this->assertSame( array( 'tracking_consent' => 'allowed' ), get_option( self::GENERAL ) );
		$this->assertSame( 'allowed', ( new General_Section() )->read()['tracking_consent'] );
	}

	public function test_reactivation_seeds_the_permalink_row_and_the_general_row(): void {
		delete_option( self::PERMALINK );
		delete_option( self::GENERAL );

		( new Activator() )->single_activate( false );

		$this->assertTrue( $this->is_autoloaded( self::PERMALINK ) );
		$this->assertTrue( $this->is_autoloaded( self::GENERAL ) );
		$this->assertSame( Permalink::DEFAULT_SLUG, Permalink::get_slug() );
	}

	public function test_the_upgrade_flip_covers_the_permalink_and_every_autoloaded_section(): void {
		update_option( self::GENERAL, array( 'store_name' => 'legacy' ), false );
		update_option( self::VISIBILITY, array( 'pos_only_products' => false ), false );
		update_option( self::PERMALINK, 'pos', false );
		$this->assertFalse( $this->is_autoloaded( self::GENERAL ) );

		Activator::autoload_request_latches();

		$this->assertTrue( $this->is_autoloaded( self::GENERAL ) );
		$this->assertTrue( $this->is_autoloaded( self::PERMALINK ) );
		$this->assertFalse( $this->is_autoloaded( self::VISIBILITY ), 'A section that keeps autoload() off is not flipped.' );
		$this->assertSame( 'legacy', get_option( self::GENERAL )['store_name'] );
	}

	private function is_autoloaded( string $option ): bool {
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the stored flag is the point.
		return \in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true );
	}
}
