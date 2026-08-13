<?php
/**
 * Tests for uninstall.php cleanup.
 *
 * The uninstall script must remove every derived/operational record the
 * plugin creates (sync tables, cron events, transients, print-job posts,
 * token user meta, sync-state options) while preserving user-authored
 * configuration (settings, receipt templates, receipt sequence, roles) and
 * NEVER touching WCPOS Pro's data. A full wipe of user-authored
 * configuration is opt-in via the WCPOS_REMOVE_ALL_DATA constant or the
 * wcpos_remove_all_data option.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Relay_Service;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge;

/**
 * Tests for uninstall.php behavior.
 *
 * @internal
 */
class Test_Uninstall extends WP_UnitTestCase {
	/**
	 * Cron hooks the uninstall script must clear that no longer have a class
	 * constant (legacy hooks). Current hooks are asserted via their constants.
	 *
	 * @var string[]
	 */
	private const LEGACY_CRON_HOOKS = array(
		'wcpos_change_log_purge',
	);

	/**
	 * User fixture committed by real DDL.
	 *
	 * @var int
	 */
	private $committed_user_id = 0;

	/**
	 * Snapshot of plugin option rows taken before each test.
	 *
	 * @var array<string, string>
	 */
	private $options_snapshot = array();

	/**
	 * Load the uninstall script (defines functions; must not execute the sweep).
	 *
	 * These tests exercise the uninstall DROP TABLE path: they need REAL DDL,
	 * so the wp-phpunit filters that rewrite per-test CREATE/DROP TABLE into
	 * TEMPORARY variants (invisible to the SHOW TABLES health probe) are
	 * removed — the same pattern as Test_Sync_Install.
	 *
	 * Real DDL also commits the wp-phpunit transaction, which makes the
	 * option sweep durable — so plugin option rows are snapshotted here and
	 * restored post-rollback in tearDown, keeping the committed world stable
	 * for later test classes.
	 */
	public function setUp(): void {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		require_once \dirname( __DIR__, 2 ) . '/uninstall.php';

		global $wpdb;
		$rows                   = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wcpos\_%' OR option_name LIKE 'woocommerce\_pos\_%'",
			ARRAY_A
		);
		$this->options_snapshot = wp_list_pluck( $rows, 'option_value', 'option_name' );
	}

	/**
	 * Post-rollback hygiene — real DDL implicitly commits the wp-phpunit
	 * transaction, so cleanup must run AFTER parent::tearDown()'s rollback
	 * and restore a sane committed world for later classes.
	 */
	public function tearDown(): void {
		parent::tearDown();

		( new Activator() )->install_sync_schema();
		$this->restore_roles_and_caps();
		( new \WCPOS\WooCommercePOS\Templates() )->register_taxonomy();
		if ( 0 !== $this->committed_user_id ) {
			wp_delete_user( $this->committed_user_id );
			$this->committed_user_id = 0;
		}

		// Restore the pre-test plugin option rows the committed sweep deleted,
		// and remove any plugin options a test added.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcpos\_%' OR option_name LIKE 'woocommerce\_pos\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test hygiene for committed writes.
		foreach ( $this->options_snapshot as $name => $value ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $value,
				)
			);
		}
		wp_cache_flush();

		$leftovers = get_posts(
			array(
				'post_type'      => array( 'wcpos_print_job', 'wcpos_template' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $leftovers as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Run the uninstall exactly as the WP_UNINSTALL_PLUGIN block does:
	 * per-site sweep, then network-wide user meta, then a cache flush.
	 *
	 * @param bool $remove_all Full-wipe flag.
	 */
	private function run_uninstall( bool $remove_all ): void {
		woocommerce_pos_uninstall_site( $remove_all );
		woocommerce_pos_uninstall_user_meta();
		wp_cache_flush();
	}

	/**
	 * Restore POS roles and capabilities via the activator (the full-wipe
	 * path removes them, and later test classes rely on activation state).
	 */
	private function restore_roles_and_caps(): void {
		$activator  = new Activator();
		$reflection = new \ReflectionClass( $activator );
		$method     = $reflection->getMethod( 'single_activate' );
		$method->setAccessible( true );
		$method->invoke( $activator, false );
	}

	/**
	 * Requiring uninstall.php without WP_UNINSTALL_PLUGIN must be a no-op.
	 */
	public function test_require_without_uninstall_constant_does_not_run_sweep(): void {
		$this->assertTrue(
			\function_exists( 'woocommerce_pos_uninstall_site' ),
			'uninstall.php should define woocommerce_pos_uninstall_site() when required'
		);
		$this->assertTrue(
			Health::is_healthy(),
			'Requiring uninstall.php must not drop the sync tables'
		);
	}

	/**
	 * The hardcoded uninstall table list must cover every current sync table.
	 *
	 * The uninstall script runs without the plugin loaded so it hardcodes
	 * names; this guards against schema drift (a new table added without
	 * uninstall coverage).
	 */
	public function test_uninstall_table_list_covers_health_required_tables(): void {
		global $wpdb;

		$suffixes = woocommerce_pos_uninstall_table_suffixes();
		foreach ( Health::required_tables() as $table ) {
			$this->assertContains(
				substr( $table, \strlen( $wpdb->prefix ) ),
				$suffixes,
				"uninstall.php must drop {$table}"
			);
		}
	}

	/**
	 * The hardcoded cron hook list must cover every hook the plugin
	 * schedules, asserted via the owning class constants so renames fail here.
	 */
	public function test_uninstall_cron_list_covers_known_hooks(): void {
		$hooks = woocommerce_pos_uninstall_cron_hooks();

		$this->assertContains( Sync_Journal_Purge::PURGE_HOOK, $hooks );
		$this->assertContains( Print_Job_Service::PURGE_HOOK, $hooks );
		$this->assertContains( Integrity_Digest::REBUILD_HOOK, $hooks );
		$this->assertContains( Cloud_Print_Trigger_Service::CRON_SUBMIT, $hooks );
		$this->assertContains( Cloud_Print_Relay_Service::REREGISTER_HOOK, $hooks );
		foreach ( self::LEGACY_CRON_HOOKS as $hook ) {
			$this->assertContains( $hook, $hooks );
		}
	}

	/**
	 * Default uninstall drops the three sync tables and both legacy tables.
	 */
	public function test_uninstall_default_drops_sync_and_legacy_tables(): void {
		global $wpdb;

		// Arrange: current tables exist; create stand-in legacy tables too.
		$this->assertTrue( Health::is_healthy(), 'Precondition: sync tables installed' );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcpos_sync_change_log ( id BIGINT )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcpos_sync_order_index ( id BIGINT )" );

		// Act.
		$this->run_uninstall( false );

		// Assert.
		$this->assertCount( 3, Health::missing_tables(), 'All three sync tables should be dropped' );
		$this->assertFalse(
			Health::table_exists( $wpdb->prefix . 'wcpos_sync_change_log' ),
			'Legacy change-log table should be dropped'
		);
		$this->assertFalse(
			Health::table_exists( $wpdb->prefix . 'wcpos_sync_order_index' ),
			'Legacy order-index table should be dropped'
		);
	}

	/**
	 * Default uninstall clears every scheduled event for every plugin hook.
	 */
	public function test_uninstall_default_clears_cron_events(): void {
		// Arrange.
		foreach ( woocommerce_pos_uninstall_cron_hooks() as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		}

		// Act.
		$this->run_uninstall( false );

		// Assert.
		foreach ( woocommerce_pos_uninstall_cron_hooks() as $hook ) {
			$this->assertFalse(
				wp_next_scheduled( $hook ),
				"Scheduled event for {$hook} should be cleared"
			);
		}
	}

	/**
	 * Default uninstall deletes operational options and transients but
	 * preserves user-authored configuration — including options that do not
	 * exist yet but match the preserved patterns (drift protection).
	 */
	public function test_uninstall_default_deletes_operational_state_preserves_user_config(): void {
		// Arrange: operational state.
		update_option( 'woocommerce_pos_db_version', '1.10.0' );
		update_option( 'wcpos_sync_schema_version', '3' );
		update_option( 'woocommerce_pos_secret_key', 'abc123' );
		set_transient( 'wcpos_landing_profile', array( 'foo' => 'bar' ), HOUR_IN_SECONDS );

		// Arrange: user-authored configuration, including a future settings
		// section that does not exist in today's code.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option( 'woocommerce_pos_settings_some_future_section', array( 'x' => 1 ) );
		update_option( 'wcpos_active_template_receipt', 42 );
		update_option( 'wcpos_receipt_sequence_counter', 1234 );

		// Act.
		$this->run_uninstall( false );

		// Assert: operational state gone.
		$this->assertFalse( get_option( 'woocommerce_pos_db_version' ), 'db_version should be deleted' );
		$this->assertFalse( get_option( 'wcpos_sync_schema_version' ), 'sync schema latch should be deleted' );
		$this->assertFalse( get_option( 'woocommerce_pos_secret_key' ), 'JWT secret should be deleted' );
		$this->assertFalse( get_transient( 'wcpos_landing_profile' ), 'wcpos transients should be deleted' );

		// Assert: user-authored configuration preserved.
		$this->assertNotFalse( get_option( 'woocommerce_pos_settings_general' ), 'settings should be preserved by default' );
		$this->assertNotFalse( get_option( 'woocommerce_pos_settings_some_future_section' ), 'future settings sections must be preserved by pattern, not by list' );
		$this->assertEquals( 42, get_option( 'wcpos_active_template_receipt' ), 'active template choice should be preserved by default' );
		$this->assertEquals( 1234, get_option( 'wcpos_receipt_sequence_counter' ), 'receipt sequence should be preserved by default' );
	}

	/**
	 * WCPOS Pro's options and transients survive BOTH default and full-wipe
	 * uninstalls of the free plugin — they belong to a different plugin.
	 */
	public function test_uninstall_never_deletes_pro_plugin_data(): void {
		// Arrange.
		update_option( 'woocommerce_pos_pro_settings_license', array( 'key' => 'secret' ) );
		update_option( 'woocommerce_pos_pro_db_version', '1.8.11' );
		update_option( 'wcpos_stores_migrated', 'yes' );
		set_transient( 'woocommerce_pos_pro_license_status', 'active', HOUR_IN_SECONDS );

		// Act: the stronger of the two paths.
		$this->run_uninstall( true );

		// Assert.
		$this->assertNotFalse( get_option( 'woocommerce_pos_pro_settings_license' ), 'Pro license must never be deleted by the free plugin' );
		$this->assertNotFalse( get_option( 'woocommerce_pos_pro_db_version' ), 'Pro db_version must never be deleted' );
		$this->assertNotFalse( get_option( 'wcpos_stores_migrated' ), 'Pro migration latch must never be deleted' );
		$this->assertNotFalse( get_transient( 'woocommerce_pos_pro_license_status' ), 'Pro transients must never be deleted' );
	}

	/**
	 * Default uninstall deletes print-job posts (and meta) but preserves
	 * user-authored receipt templates.
	 */
	public function test_uninstall_default_deletes_print_jobs_preserves_templates(): void {
		// Arrange.
		$job_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_print_job',
				'post_status' => 'publish',
				'post_title'  => 'Job',
			)
		);
		add_post_meta( $job_id, '_wcpos_print_job_state', 'queued' );

		$template_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'My custom receipt',
			)
		);

		// Act.
		$this->run_uninstall( false );

		// Assert.
		$this->assertNull( get_post( $job_id ), 'Print-job posts should be deleted' );
		$this->assertEmpty( get_post_meta( $job_id ), 'Print-job post meta should be deleted' );
		$this->assertNotNull( get_post( $template_id ), 'Receipt templates should be preserved by default' );
	}

	/**
	 * Uninstall deletes POS token/access user meta network-wide, covering
	 * every known key by prefix.
	 */
	public function test_uninstall_deletes_pos_user_meta(): void {
		// Arrange.
		$user_id                 = $this->factory()->user->create();
		$this->committed_user_id = $user_id;
		add_user_meta( $user_id, '_woocommerce_pos_refresh_tokens', array( 'token' ) );
		add_user_meta( $user_id, '_woocommerce_pos_last_access', '2026-08-14' );
		add_user_meta( $user_id, '_woocommerce_pos_uuid', 'abc-123' );
		add_user_meta( $user_id, '_wcpos_logs_last_viewed', '2026-08-14' );
		add_user_meta( $user_id, '_wcpos_consent_callout_hidden_until', '2026-09-01' );
		add_user_meta( $user_id, '_wcpos_tax_ids', array( 1 ) );

		// Act.
		$this->run_uninstall( false );

		// Assert.
		foreach ( array( '_woocommerce_pos_refresh_tokens', '_woocommerce_pos_last_access', '_woocommerce_pos_uuid', '_wcpos_logs_last_viewed', '_wcpos_consent_callout_hidden_until', '_wcpos_tax_ids' ) as $key ) {
			$this->assertEmpty( get_user_meta( $user_id, $key, true ), "{$key} should be deleted" );
		}
	}

	/**
	 * A remove-all uninstall additionally deletes settings, template
	 * configuration, template posts with their revisions and term
	 * relationships, and the template taxonomies.
	 */
	public function test_uninstall_remove_all_deletes_settings_templates_revisions_and_terms(): void {
		global $wpdb;

		// Arrange.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option( 'wcpos_active_template_receipt', 42 );
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'My custom receipt',
			)
		);
		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $template_id,
				'post_title'  => 'My custom receipt (revision)',
			)
		);
		register_taxonomy( 'wcpos_template_type', 'wcpos_template' );
		$term    = wp_insert_term( 'receipt', 'wcpos_template_type' );
		$term_id = is_wp_error( $term ) ? (int) $term->get_error_data( 'term_exists' ) : (int) $term['term_id'];
		$this->assertGreaterThan( 0, $term_id, 'Precondition: template-type term available' );
		wp_set_object_terms( $template_id, array( $term_id ), 'wcpos_template_type' );

		// Act.
		$this->run_uninstall( true );

		// Assert.
		$this->assertFalse( get_option( 'woocommerce_pos_settings_general' ), 'settings should be deleted on full wipe' );
		$this->assertFalse( get_option( 'wcpos_active_template_receipt' ), 'template config should be deleted on full wipe' );
		$this->assertNull( get_post( $template_id ), 'template posts should be deleted on full wipe' );
		$this->assertNull( get_post( $revision_id ), 'template revisions should be deleted on full wipe' );
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d", $template_id ) ),
			'term relationships for deleted templates must not be orphaned'
		);
		$this->assertSame(
			'0',
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'wcpos_template_type'" ),
			'template taxonomy terms should be deleted on full wipe'
		);
	}

	/**
	 * The cashier role survives a default uninstall (removing it would strand
	 * assigned users) and is removed, along with POS caps, on a full wipe.
	 */
	public function test_uninstall_role_handling(): void {
		// Arrange: activation created role + caps.
		$this->assertNotNull( get_role( 'cashier' ), 'Precondition: cashier role exists' );

		// Act + Assert: default keeps the role.
		$this->run_uninstall( false );
		$this->assertNotNull( get_role( 'cashier' ), 'Default uninstall must keep the cashier role' );

		// Act + Assert: full wipe removes role and POS caps everywhere.
		$this->run_uninstall( true );
		$this->assertNull( get_role( 'cashier' ), 'Full wipe should remove the cashier role' );
		$admin = get_role( 'administrator' );
		foreach ( array_keys( (array) $admin->capabilities ) as $cap ) {
			$this->assertStringNotContainsString( 'woocommerce_pos', $cap, 'Full wipe should strip POS caps from all roles' );
		}
	}

	/**
	 * The remove-all switch reads the wcpos_remove_all_data option and
	 * rejects boolean-ish "off" strings.
	 */
	public function test_remove_all_data_option_enables_full_wipe(): void {
		$this->assertFalse( woocommerce_pos_uninstall_remove_all_data(), 'Full wipe should be off by default' );

		update_option( 'wcpos_remove_all_data', 'yes' );
		$this->assertTrue( woocommerce_pos_uninstall_remove_all_data(), 'wcpos_remove_all_data=yes should enable full wipe' );

		update_option( 'wcpos_remove_all_data', 'no' );
		$this->assertFalse( woocommerce_pos_uninstall_remove_all_data(), 'wcpos_remove_all_data=no must NOT enable full wipe' );
	}
}
