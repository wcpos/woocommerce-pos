<?php
/**
 * Default template terms are seeded once, not checked on every request.
 *
 * Measured 2026-09-03 on dev-next (see
 * .claude/research/2026-09-03-online-store-footprint.md): `Templates` is
 * constructed on every request from `Init::init_common()`, and its
 * `register_taxonomy()` re-ran nine `term_exists()` checks — 18 of the 31
 * queries the plugin added to every storefront page — to confirm terms that
 * were seeded on the first request after install.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Templates;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Templates
 */
class Test_Templates_Default_Terms extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		// The suite wipes terms between classes while the autoloaded latch
		// survives in wp_options: arrange a fresh, complete seed every time.
		delete_option( Templates::DEFAULT_TERMS_OPTION );
		new Templates();
		$this->assertSame( Templates::DEFAULT_TERMS_VERSION, (int) get_option( Templates::DEFAULT_TERMS_OPTION ) );
	}

	public function tearDown(): void {
		delete_option( Templates::DEFAULT_TERMS_OPTION );
		parent::tearDown();
	}

	public function test_first_construction_seeds_the_terms_and_sets_an_autoloaded_latch(): void {
		delete_option( Templates::DEFAULT_TERMS_OPTION );
		$this->delete_default_term( 'bar-ticket', 'wcpos_template_category' );

		new Templates();

		$this->assertNotFalse( term_exists( 'bar-ticket', 'wcpos_template_category' ) );
		$this->assertNotFalse( term_exists( 'receipt', 'wcpos_template_type' ) );
		$this->assertSame( Templates::DEFAULT_TERMS_VERSION, (int) get_option( Templates::DEFAULT_TERMS_OPTION ) );
		$this->assertArrayHasKey( Templates::DEFAULT_TERMS_OPTION, wp_load_alloptions(), 'The latch is read on every request, so it must ride in alloptions.' );
	}

	public function test_construction_behind_the_latch_runs_no_queries(): void {
		wp_cache_flush();
		wp_load_alloptions();

		$queries = array();
		$spy     = static function ( $query ) use ( &$queries ) {
			// WordPress core reads the `permalink_structure` option while
			// registering the post type; it is autoloaded on a real site but not
			// in the test store. Only the plugin's own reads are under test here.
			if ( false === strpos( (string) $query, "option_name = 'permalink_structure'" ) ) {
				$queries[] = (string) $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		new Templates();
		remove_filter( 'query', $spy );

		$this->assertSame( array(), $queries, 'Registering the post type and taxonomies behind the latch must be free.' );
	}

	public function test_a_failed_term_insert_leaves_the_latch_unset_until_the_next_request_succeeds(): void {
		delete_option( Templates::DEFAULT_TERMS_OPTION );
		$this->delete_default_term( 'credit-note', 'wcpos_template_category' );
		$refuse = static function ( $term, $taxonomy ) {
			return ( 'wcpos_template_category' === $taxonomy && 'Credit Note' === $term ) ? new \WP_Error( 'test_refused', 'refused' ) : $term;
		};
		add_filter( 'pre_insert_term', $refuse, 10, 2 );

		new Templates();
		remove_filter( 'pre_insert_term', $refuse, 10 );

		$this->assertNull( term_exists( 'credit-note', 'wcpos_template_category' ) );
		$this->assertFalse( get_option( Templates::DEFAULT_TERMS_OPTION ), 'A partial seed must not be marked complete.' );

		new Templates();

		$this->assertNotFalse( term_exists( 'credit-note', 'wcpos_template_category' ) );
		$this->assertSame( Templates::DEFAULT_TERMS_VERSION, (int) get_option( Templates::DEFAULT_TERMS_OPTION ) );
	}

	public function test_a_failed_display_type_insert_leaves_the_latch_unset(): void {
		// Every supported type is verified before latching, not a hand-kept
		// list: `display` seeded unverified until the check read SUPPORTED_TYPES.
		delete_option( Templates::DEFAULT_TERMS_OPTION );
		$this->delete_default_term( 'display', 'wcpos_template_type' );
		$refuse = static function ( $term, $taxonomy ) {
			return ( 'wcpos_template_type' === $taxonomy && 'Display' === $term ) ? new \WP_Error( 'test_refused', 'refused' ) : $term;
		};
		add_filter( 'pre_insert_term', $refuse, 10, 2 );

		new Templates();
		remove_filter( 'pre_insert_term', $refuse, 10 );

		$this->assertNull( term_exists( 'display', 'wcpos_template_type' ) );
		$this->assertFalse( get_option( Templates::DEFAULT_TERMS_OPTION ), 'A seed missing the display type must not be marked complete.' );

		new Templates();

		$this->assertNotFalse( term_exists( 'display', 'wcpos_template_type' ) );
		$this->assertSame( Templates::DEFAULT_TERMS_VERSION, (int) get_option( Templates::DEFAULT_TERMS_OPTION ) );
	}

	public function test_plugin_activation_rearms_the_seed(): void {
		( new Activator() )->single_activate( false );

		$this->assertFalse( get_option( Templates::DEFAULT_TERMS_OPTION ), '(Re)activation is the repair a merchant reaches for after deleting a term.' );
	}

	public function test_a_latch_below_the_current_version_reseeds_missing_terms(): void {
		update_option( Templates::DEFAULT_TERMS_OPTION, Templates::DEFAULT_TERMS_VERSION - 1, true );
		$this->delete_default_term( 'kitchen-ticket', 'wcpos_template_category' );

		new Templates();

		$this->assertNotFalse( term_exists( 'kitchen-ticket', 'wcpos_template_category' ) );
		$this->assertSame( Templates::DEFAULT_TERMS_VERSION, (int) get_option( Templates::DEFAULT_TERMS_OPTION ) );
	}

	public function test_version_upgrade_migrates_only_legacy_pocket_and_marquee_categories(): void {
		$posts = array();
		foreach ( array( 'display-pocket', 'display-marquee', 'display-ledger' ) as $gallery_key ) {
			$posts[ $gallery_key ] = self::factory()->post->create(
				array(
					'post_type'   => 'wcpos_template',
					'post_status' => 'publish',
				)
			);
			update_post_meta( $posts[ $gallery_key ], '_template_gallery_key', $gallery_key );
			wp_set_object_terms( $posts[ $gallery_key ], 'display', 'wcpos_template_category' );
		}

		$customized = self::factory()->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $customized, '_template_gallery_key', 'display-pocket' );
		wp_set_object_terms( $customized, 'promotion', 'wcpos_template_category' );
		update_option( Templates::DEFAULT_TERMS_OPTION, Templates::DEFAULT_TERMS_VERSION - 1, true );

		new Templates();

		$this->assertSame( array( 'standard' ), wp_get_post_terms( $posts['display-pocket'], 'wcpos_template_category', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'standard' ), wp_get_post_terms( $posts['display-marquee'], 'wcpos_template_category', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'display' ), wp_get_post_terms( $posts['display-ledger'], 'wcpos_template_category', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'promotion' ), wp_get_post_terms( $customized, 'wcpos_template_category', array( 'fields' => 'slugs' ) ) );
	}

	private function delete_default_term( string $slug, string $taxonomy ): void {
		$term = term_exists( $slug, $taxonomy );
		$this->assertIsArray( $term, "Arrangement expects the default term '{$slug}' to exist." );
		wp_delete_term( (int) $term['term_id'], $taxonomy );
		$this->assertNull( term_exists( $slug, $taxonomy ) );
	}
}
