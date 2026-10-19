<?php
/**
 * Activation checks and set up.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Admin\Consent;
use WCPOS\WooCommercePOS\Services\Font_Pack_Loader;
use WCPOS\WooCommercePOS\Services\Lifecycle_Events;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Cleanup;
use WCPOS\WooCommercePOS\Sync\Api as Sync_Api;
use WCPOS\WooCommercePOS\Sync\Health as Sync_Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Services\Option_Register_Repository;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use const DOING_AJAX;

/**
 * Activator class.
 */
class Activator {
	/**
	 * Lock name used by WP_Upgrader::create_lock().
	 */
	private const DB_UPGRADE_LOCK_NAME = 'woocommerce_pos_db_upgrade_lock';

	/**
	 * Lock TTL in seconds.
	 */
	private const DB_UPGRADE_LOCK_TTL = 600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		register_activation_hook( PLUGIN_FILE, array( $this, 'activate' ) );
		add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );
		// The staff-account rule (#1918, #2104) must hold even when WooCommerce is
		// inactive and Init never starts; it depends on nothing from WooCommerce.
		add_filter( 'map_meta_cap', array( Services\Permission_Rules::class, 'map_user_meta_caps' ), 10, 4 );
		// So must the role fence: a cashier may hand out no role above customer. Last,
		// so a role-editor plugin adding roles back at a later priority cannot widen it.
		add_filter( 'editable_roles', array( Services\Permission_Rules::class, 'filter_editable_roles' ), PHP_INT_MAX );
		add_action( 'invite_user', array( Services\Permission_Rules::class, 'refuse_unfenced_invite' ), 10, 3 );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Checks for valid install and begins execution of the plugin.
	 */
	public function init(): void {
		// Check for min requirements to run.
		if ( $this->php_check() && $this->woocommerce_check() ) {
			// Defer permalink check to admin_init so __() calls happen after
			// after_setup_theme (WordPress 6.7+ triggers a notice otherwise).
			if ( is_admin() && ( ! \defined( '\DOING_AJAX' ) || ! DOING_AJAX ) ) { // @phpstan-ignore-line
				add_action( 'admin_init', array( Receipt_Snapshot_Cleanup::class, 'arm' ) );
				add_action(
					'admin_init',
					function () {
						$this->permalink_check();
					}
				);
			}

			// Init update script if required.
			$this->version_check();
			$this->pro_version_check();

			// resolve plugin plugins.
			$this->plugin_check();

			new Init();
		}
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param bool $network_wide Whether to activate network-wide.
	 */
	public function activate( $network_wide ): void {
		if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_wide ) {
				// Get all blog ids.
				$blog_ids = $this->get_blog_ids();

				foreach ( $blog_ids as $blog_id ) {
					switch_to_blog( $blog_id );
					$this->single_activate();

					restore_current_blog();
				}
			} else {
				self::single_activate();
			}
		} else {
			self::single_activate();
		}
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param bool $install_sync_schema Whether to install the sync schema.
	 * @param bool $full_role_sync      Whether to repair all default role capabilities.
	 */
	public function single_activate( bool $install_sync_schema = true, bool $full_role_sync = true ): void {
		$role_capabilities          = self::role_capability_definition();
		$capability_names           = $role_capabilities;
		$capability_names['cashier'] = array_merge( array( 'access_woocommerce_pos' ), array_keys( $role_capabilities['cashier'] ) );
		$synced                     = get_option( 'woocommerce_pos_role_caps_synced', false );
		if ( ! $full_role_sync && false === $synced && get_option( 'woocommerce_pos_role_caps_fingerprint' ) === $this->role_caps_fingerprint() ) {
			$synced = $capability_names;
		}
		// An upgrade grants only capabilities new to the definition since the
		// last sync, so a capability the merchant removed on the Access screen
		// stays removed. Explicit activation still repairs every default.
		$granted = $capability_names;
		if ( ! $full_role_sync && \is_array( $synced ) ) {
			foreach ( $granted as $slug => $capabilities ) {
				$already = isset( $synced[ $slug ] ) && \is_array( $synced[ $slug ] ) ? $synced[ $slug ] : array();
				$granted[ $slug ] = array_values( array_diff( $capabilities, $already ) );
			}
		}

		// Reseed the default template terms on the next request: (re)activation
		// is the repair a merchant reaches for after deleting a term by hand.
		// This also runs once per upgrade (version_check re-activates to sync
		// role caps), so one post-upgrade request pays the ~18 seeding queries.
		delete_option( Templates::DEFAULT_TERMS_OPTION );

		// Second, merchant-reachable trigger for the autoload repair: db_upgrade()
		// only runs when version_check() trips on an admin load that reaches
		// woocommerce_init, and a miss there is permanent once bump_versions() ran.
		self::autoload_request_latches();
		Admin\Permalink::ensure_default();
		Receipt_Snapshot_Cleanup::arm();

		// POS Only ⇒ catalog visibility hidden (#1862): re-apply after a
		// deactivation restored it. Idempotent, one pass over the set.
		( new Catalog_Visibility() )->force_all();

		// create POS specific roles.
		$this->create_pos_roles( $granted['cashier'] );

		// add pos capabilities to non POS roles.
		$this->add_pos_capability(
			array(
				'administrator' => $granted['administrator'],
				'shop_manager'  => $granted['shop_manager'],
			)
		);

		$stored_roles        = get_option( wp_roles()->role_key, array() );
		$roles_are_persisted = is_array( $stored_roles );
		if ( $roles_are_persisted ) {
			foreach ( $granted as $slug => $capabilities ) {
				if ( ! isset( $stored_roles[ $slug ] ) ) {
					$roles_are_persisted = false;
					break;
				}
				foreach ( $capabilities as $capability ) {
					if ( empty( $stored_roles[ $slug ]['capabilities'][ $capability ] ) ) {
						$roles_are_persisted = false;
						break 2;
					}
				}
			}
		}

		$obsolete_customer_create_cap = isset( $role_capabilities['cashier']['create_customers'] ) ? 'promote_users' : 'create_customers';
		if ( $roles_are_persisted && empty( $stored_roles['cashier']['capabilities'][ $obsolete_customer_create_cap ] ) ) {
			// Snapshot first: a fingerprint that advanced past a failed snapshot
			// write would never retry it.
			update_option( 'woocommerce_pos_role_caps_synced', $capability_names, true );
			update_option( 'woocommerce_pos_role_caps_fingerprint', $this->role_caps_fingerprint(), true );
		}

		// Flag the consent pop-up for the next admin page load. Done here
		// because the `activated_plugin` action in Admin\Consent fires
		// inside the activation request, at which point our plugin's
		// `plugins_loaded` callback hasn't yet instantiated Init on a
		// fresh install.
		//
		// Read the option directly — woocommerce_pos_get_settings() lives in
		// wcpos-functions.php which Init loads on `plugins_loaded`, but
		// plugins_loaded has already fired by the time activation runs.
		$general_settings = get_option( 'woocommerce_pos_settings_general', array() );
		$tracking_consent = is_array( $general_settings ) && isset( $general_settings['tracking_consent'] )
			? $general_settings['tracking_consent']
			: 'undecided';
		if ( 'undecided' === $tracking_consent ) {
			set_transient( Consent::MODAL_TRANSIENT, 1, Consent::MODAL_TRANSIENT_TTL );
		}

		if ( $install_sync_schema ) {
			Font_Pack_Loader::schedule();
			$this->install_sync_schema();
		}

		// Record the install for analytics. Consent is still `undecided` at this
		// point, so the event is held until the user answers the pop-up flagged
		// above; Lifecycle_Events owns that deferral and reports at most once.
		( new Lifecycle_Events() )->record_install();
	}

	/**
	 * Install the till bookkeeping tables. The register is an option, minted on first read.
	 *
	 * Each install is a no-op once its table exists. Called from activation and from
	 * every upgrade pass, independently of the sync schema latch.
	 */
	private function install_till_tables(): void {
		( new Register_Session_Store() )->install();
		( new Fiscal_Record_Store() )->install();
	}

	/**
	 * Install the sync store and latch its aggregate schema version after verification.
	 *
	 * @throws \RuntimeException On fiscal carry, approval snapshot, closure, movement or register fold upgrade failure.
	 */
	public function install_sync_schema(): void {
		$previous_schema = get_option( Sync_Api::SCHEMA_OPTION, null );

		$journal = new Sync_Journal();
		$journal->install();
		( new Integrity_Digest() )->install();
		( new Mutation_Store() )->install();
		$this->install_till_tables();

		if ( null === $previous_schema || version_compare( (string) $previous_schema, '7', '<' ) ) {
			$this->upgrade_business_days();
		}

		if ( null === $previous_schema || version_compare( (string) $previous_schema, '8', '<' ) ) {
			$this->add_column_if_missing( ( new Fiscal_Record_Store() )->table_name(), 'carried_by_closure_id', 'CHAR(36) NULL', 'Fiscal carry column' );
		}

		if ( null === $previous_schema || version_compare( (string) $previous_schema, '9', '<' ) ) {
			foreach ( array( 'approved_counted', 'approved_expected' ) as $name ) {
				$this->add_column_if_missing( ( new Register_Session_Store() )->table_name(), $name, 'LONGTEXT NULL', 'Approval snapshot column' );
			}
		}

		if ( null === $previous_schema || version_compare( (string) $previous_schema, '10', '<' ) ) {
			$this->fold_closures_into_sessions();
		}

		if ( null === $previous_schema || version_compare( (string) $previous_schema, '11', '<' ) ) {
			$this->fold_movements_into_sessions();
		}

		if ( ( null === $previous_schema || version_compare( (string) $previous_schema, '12', '<' ) ) && ! $this->fold_registers_into_option() ) {
			return;
		}

		if ( ! Sync_Health::is_healthy() ) {
			if ( Sync_Api::SCHEMA_VERSION === $previous_schema ) {
				delete_option( Sync_Api::SCHEMA_OPTION );
			}
			return;
		}

		// Schema 3 (#1379): the customer space widened from role=customer to ALL users.
		// Upgrading installs carry role-departure tombstones in the persisted stream that
		// would replay against now-live users; compensating updates supersede them (see
		// Sync_Journal::append_customer_updates_for_all_users). The old latch stays until
		// migration succeeds, so retries may append duplicate but harmless superseding
		// updates. Fresh installs (no previous latch) have no stream to repair.
		if (
			null !== $previous_schema
			&& version_compare( (string) $previous_schema, '3', '<' )
			&& ! $journal->append_customer_updates_for_all_users()
		) {
			return;
		}

		// Schema 6 (#1757): order journal rows carry no revision value — the order
		// revision is computed at pull from the served payload (ADR 0033) and the
		// planner's stored-wins branch is gone. Upgrading installs still hold
		// pre-#1746 stored hashes and 'deleted' tombstone markers; blank them so a
		// legacy value can never be served. The UPDATE is idempotent and the old
		// latch stays until it succeeds, so a failed run retries.
		if (
			null !== $previous_schema
			&& version_compare( (string) $previous_schema, '6', '<' )
			&& ! $journal->blank_order_revisions()
		) {
			return;
		}

		// Autoloaded: the Init constructor reads this latch on every request.
		// This flips an existing row only on WP 6.4+; older rows are flipped by
		// autoload_request_latches() on upgrade.
		update_option( Sync_Api::SCHEMA_OPTION, Sync_Api::SCHEMA_VERSION, true );

		// Old code can recreate a legacy table up to the latch write, so probe after it and restore
		// the old latch: the next run copies it. A recreation after this probe is the accepted
		// dev-only gap recorded on the PR (old code still serving requests seconds after a deploy).
		global $wpdb;
		$folds = array();
		if ( null === $previous_schema || version_compare( (string) $previous_schema, '10', '<' ) ) {
			$folds['Closure fold'] = $wpdb->prefix . Closure_Store::LEGACY_TABLE;
		}
		if ( null === $previous_schema || version_compare( (string) $previous_schema, '11', '<' ) ) {
			$folds['Movement fold'] = $wpdb->prefix . Cash_Movement_Store::LEGACY_TABLE;
		}
		if ( null === $previous_schema || version_compare( (string) $previous_schema, '12', '<' ) ) {
			$folds['Register fold'] = $wpdb->prefix . Register_Store::LEGACY_TABLE;
		}
		foreach ( $folds as $label => $legacy ) {
			try {
				$recreated = self::legacy_table_exists( $legacy, $label );
			} catch ( \RuntimeException $error ) {
				$recreated = $error;
			}
			if ( false === $recreated ) {
				continue;
			}
			// Read back rather than trust the return (false also means unchanged); retry once.
			for ( $attempt = 0; $attempt < 2 && get_option( Sync_Api::SCHEMA_OPTION, null ) !== $previous_schema; ++$attempt ) {
				null === $previous_schema ? delete_option( Sync_Api::SCHEMA_OPTION ) : update_option( Sync_Api::SCHEMA_OPTION, $previous_schema, true );
			}
			if ( get_option( Sync_Api::SCHEMA_OPTION, null ) !== $previous_schema ) {
				Logger::error(
					"{$label} latch rollback failed",
					array(
						'previous' => $previous_schema,
						'legacy_table' => $legacy,
					)
				);
			}
			throw true === $recreated ? new \RuntimeException( esc_html( "{$label} found a recreated legacy table." ) ) : $recreated;
		}

		if ( null !== $previous_schema && version_compare( (string) $previous_schema, Sync_Api::SCHEMA_VERSION, '<' ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcpos_sync_change_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table name.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcpos_sync_order_index" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table name.
			// The legacy Change_Log_Purge class is gone; its recurring cron event
			// would otherwise survive the upgrade and fire a hook with no handler
			// forever. Literal hook name — the constant was removed with the class.
			wp_clear_scheduled_hook( 'wcpos_change_log_purge' );
		}
	}

	/** Add business-day columns and fill missing stamps before latching schema 7.
	 *
	 * This is a backfill approximation using the SITE timezone at upgrade time,
	 * not the original device/store stamp. Existing stamps are never overwritten.
	 *
	 * @throws \RuntimeException On schema, read or write failure; leave the old latch in place.
	 */
	private function upgrade_business_days(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$store = new Register_Session_Store();
		$table = $store->table_name();
		dbDelta( $store->schema_sql( $table, $wpdb->get_charset_collate() ) );
		do {
			// Bounded batches; stamped rows leave the next batch automatically.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
			$rows = $wpdb->get_results( "SELECT id, opened_at_gmt FROM {$table} WHERE business_day IS NULL ORDER BY id LIMIT 100", ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Business day upgrade read failed.' );
			}
			foreach ( $rows as $row ) {
				$day = ( new \DateTimeImmutable( $row['opened_at_gmt'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
				if ( false === $wpdb->update(
					$table,
					array( 'business_day' => $day ),
					array(
						'id' => $row['id'],
						'business_day' => null,
					)
				) ) {
					throw new \RuntimeException( 'Business day upgrade write failed.' );
				}
			}
			$size = count( $rows );
		} while ( 100 === $size );
	}

	/** Schema 10: a closure is its closed session row. Add the closure columns and indexes,
	 * copy any legacy closures (dev builds of `next` only) and drop their table.
	 *
	 * The latch has no transaction and retries until this completes, so every statement
	 * is guarded and a partial copy resumes where it stopped. The verified table is
	 * renamed before a second copy and the drop, so a closure committed meanwhile by a
	 * request still on pre-fold code is copied too, and a later one fails and is retried.
	 *
	 * @throws \RuntimeException On schema, read, copy or verification failure; leave the old latch in place.
	 */
	private function fold_closures_into_sessions(): void {
		global $wpdb;
		$sessions = ( new Register_Session_Store() )->table_name();
		foreach ( array(
			'number' => 'BIGINT NULL',
			'printed_number' => 'BIGINT NULL',
			'print_count' => 'INT NOT NULL DEFAULT 0',
			'last_printed_at_gmt' => 'DATETIME NULL',
			'closure' => 'LONGTEXT NULL',
		) as $name => $definition ) {
			$this->add_column_if_missing( $sessions, $name, $definition, 'Closure column' );
		}
		foreach ( array(
			'register_number' => 'UNIQUE KEY register_number (register_id, number)',
			'closure_id' => 'UNIQUE KEY closure_id (closure_id)',
			'store_closed' => 'KEY store_closed (store_id, closed_at_gmt)',
		) as $name => $definition ) {
			$this->add_index_if_missing( $sessions, $name, $definition, 'Closure index' );
		}
		$legacy = $wpdb->prefix . Closure_Store::LEGACY_TABLE;
		$folded = $legacy . '_folded';
		// An interrupted run left the renamed table: finish it before touching the legacy name.
		if ( self::legacy_table_exists( $folded, 'Closure fold' ) ) {
			$this->finish_folded_closures( $folded, $sessions );
		}
		if ( ! self::legacy_table_exists( $legacy, 'Closure fold' ) ) {
			return;
		}
		$this->copy_legacy_closures( $legacy, $sessions );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table names.
		if ( false === $wpdb->query( "RENAME TABLE {$legacy} TO {$folded}" ) ) {
			throw new \RuntimeException( 'Closure fold rename failed.' );
		}
		$this->finish_folded_closures( $folded, $sessions );
	}

	/** Whether a legacy table exists; a failed probe is never taken for absence.
	 *
	 * @param string $table Legacy table.
	 * @param string $label Fold named in the failure message.
	 * @throws \RuntimeException When the probe fails.
	 */
	private static function legacy_table_exists( string $table, string $label ): bool {
		$exists = Sync_Health::table_exists( $table );
		if ( self::query_failed() ) {
			throw new \RuntimeException( esc_html( "{$label} probe failed." ) );
		}
		return $exists;
	}

	/** Copy the renamed table's stragglers, keep any later print bookkeeping, then drop it.
	 *
	 * @param string $folded Renamed legacy table.
	 * @param string $sessions Sessions table.
	 * @throws \RuntimeException On copy, reconcile or drop failure.
	 */
	private function finish_folded_closures( string $folded, string $sessions ): void {
		global $wpdb;
		$this->copy_legacy_closures( $folded, $sessions );
		// The copy left each legacy row holding only the prints recorded since. A maximum would
		// drop one of a pre-fold print on that row and a new-code print on the session row, so
		// add the delta and zero it in the same statement: a re-run after a failed drop adds nothing.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables.
		$wpdb->query( "UPDATE {$sessions} s INNER JOIN {$folded} c ON s.closure_id = c.id SET s.print_count = s.print_count + c.print_count, c.print_count = 0, s.last_printed_at_gmt = COALESCE(GREATEST(s.last_printed_at_gmt, c.last_printed_at_gmt), s.last_printed_at_gmt, c.last_printed_at_gmt)" );
		if ( self::query_failed() ) {
			throw new \RuntimeException( 'Closure fold print reconcile failed.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table name.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$folded}" ) ) {
			throw new \RuntimeException( 'Closure fold drop failed.' );
		}
	}

	/** Copy each legacy closure not yet on its session row, then verify every one row by row.
	 *
	 * @param string $legacy Legacy closures table.
	 * @param string $sessions Sessions table.
	 * @throws \RuntimeException On mismatched data, read, write or verification failure.
	 */
	private function copy_legacy_closures( string $legacy, string $sessions ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables, fixed predicates.
		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy} c LEFT JOIN {$sessions} s ON s.id = c.session_id WHERE s.id IS NULL" );
		$mismatches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy} c INNER JOIN {$sessions} s ON s.id = c.session_id WHERE ( s.closure_id IS NOT NULL AND s.closure_id <> c.id ) OR c.register_id <> s.register_id" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::query_failed() ) {
			throw new \RuntimeException( 'Closure fold read failed.' );
		}
		if ( $orphans || $mismatches ) {
			// Dev data a human must repair; the latch retries on every upgrade pass until then.
			Logger::error(
				'Closure fold refused: legacy closures do not match their sessions',
				array(
					'orphans' => $orphans,
					'mismatches' => $mismatches,
				)
			);
			throw new \RuntimeException( 'Closure fold found orphaned or mismatched closures.' );
		}
		do {
			// Copied rows leave the next batch automatically.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables.
			$rows = $wpdb->get_results( "SELECT c.*, s.opened_at_gmt AS session_opened_at_gmt, s.closed_at_gmt AS session_closed_at_gmt FROM {$legacy} c INNER JOIN {$sessions} s ON s.id = c.session_id WHERE s.number IS NULL ORDER BY c.number LIMIT 100", ARRAY_A );
			if ( self::query_failed() ) {
				throw new \RuntimeException( 'Closure fold read failed.' );
			}
			foreach ( $rows as $row ) {
				// A number another session of the register holds proves the row was minted against a
				// restarted sequence (a recreated table), so its perpetual totals cannot be trusted.
				// A human repairs the dev data (drop or renumber that table); the latch retries.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; values are prepared.
				$taken = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE register_id = %s AND number = %d AND id <> %s", $row['register_id'], $row['number'], $row['session_id'] ) );
				if ( self::query_failed() ) {
					throw new \RuntimeException( 'Closure fold read failed.' );
				}
				if ( $taken ) {
					Logger::error(
						'Closure fold refused: legacy closure number already folded',
						array(
							'closure_id' => $row['id'],
							'register_id' => $row['register_id'],
							'number' => (int) $row['number'],
							'session_id' => $row['session_id'],
						)
					);
					throw new \RuntimeException( 'Closure fold found a recreated legacy closure.' );
				}
				foreach ( array( 'expected', 'till_expected', 'counted', 'variance', 'breakdowns', 'findings' ) as $key ) {
					$row[ $key ] = null === $row[ $key ] ? null : json_decode( $row[ $key ], true );
				}
				// The row reads the session's timestamps; the till's differing claims stay in the document.
				foreach ( array( 'opened_at_gmt', 'closed_at_gmt' ) as $key ) {
					$row[ 'till_' . $key ] = $row[ $key ] === $row[ 'session_' . $key ] ? null : $row[ $key ];
				}
				$document = wp_json_encode( Closure_Store::document( Closure_Store::normalize( $row ) ) );
				// The copied prints leave the legacy row in the write's transaction, so a concurrent
				// pre-fold increment stays behind as the delta finish_folded_closures() adds.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy table; values are prepared.
				$subtract = $wpdb->prepare( "UPDATE {$legacy} SET print_count = print_count - %d WHERE id = %s", $row['print_count'], $row['id'] );
				if ( false === $document || false === $wpdb->query( 'START TRANSACTION' ) ) {
					throw new \RuntimeException( 'Closure fold write failed.' );
				}
				if ( 1 !== $wpdb->update(
					$sessions,
					array(
						'closure_id' => $row['id'],
						'number' => $row['number'],
						'printed_number' => $row['printed_number'],
						'print_count' => $row['print_count'],
						'last_printed_at_gmt' => $row['last_printed_at_gmt'],
						'closure' => $document,
					),
					array(
						'id' => $row['session_id'],
						'number' => null,
					)
				) || false === $wpdb->query( $subtract ) || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
					$wpdb->query( 'ROLLBACK' );
					throw new \RuntimeException( 'Closure fold write failed.' );
				}
			}
			$size = count( $rows );
		} while ( 100 === $size );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables.
		$legacy_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy}" );
		$folded_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy} c INNER JOIN {$sessions} s ON s.id = c.session_id AND s.closure_id = c.id AND s.number = c.number" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::query_failed() || null === $legacy_count || $legacy_count !== $folded_count ) {
			throw new \RuntimeException( 'Closure fold verification failed.' );
		}
	}

	/** Schema 11: a session row carries its movements as one JSON array. Add the column, copy any
	 * legacy movements (dev builds of `next` only) and drop their table.
	 *
	 * As the closure fold: every statement is guarded, a partial copy resumes where it stopped,
	 * and the verified table is renamed before a second copy and the drop. Data that does not
	 * match its sessions is refused, never repaired: a human fixes it and the latch retries.
	 *
	 * @throws \RuntimeException On schema, read, copy or verification failure; leave the old latch in place.
	 */
	private function fold_movements_into_sessions(): void {
		global $wpdb;
		$sessions = ( new Register_Session_Store() )->table_name();
		$this->add_column_if_missing( $sessions, 'movements', 'LONGTEXT NULL', 'Movement column' );
		$legacy = $wpdb->prefix . Cash_Movement_Store::LEGACY_TABLE;
		$folded = $legacy . '_folded';
		// An interrupted run left the renamed table: finish it before touching the legacy name.
		if ( self::legacy_table_exists( $folded, 'Movement fold' ) ) {
			$this->finish_folded_movements( $folded, $sessions );
		}
		if ( ! self::legacy_table_exists( $legacy, 'Movement fold' ) ) {
			return;
		}
		$this->copy_legacy_movements( $legacy, $sessions );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table names.
		if ( false === $wpdb->query( "RENAME TABLE {$legacy} TO {$folded}" ) ) {
			throw new \RuntimeException( 'Movement fold rename failed.' );
		}
		$this->finish_folded_movements( $folded, $sessions );
	}

	/** Copy the renamed table's stragglers, verify, then drop it.
	 *
	 * @param string $folded Renamed legacy table.
	 * @param string $sessions Sessions table.
	 * @throws \RuntimeException On copy, verification or drop failure.
	 */
	private function finish_folded_movements( string $folded, string $sessions ): void {
		global $wpdb;
		$this->copy_legacy_movements( $folded, $sessions );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table name.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$folded}" ) ) {
			throw new \RuntimeException( 'Movement fold drop failed.' );
		}
	}

	/** Copy each session's legacy movements into its NULL array, then verify every row.
	 *
	 * @param string $legacy Legacy movements table.
	 * @param string $sessions Sessions table.
	 * @throws \RuntimeException On mismatched data, read, write or verification failure.
	 */
	private function copy_legacy_movements( string $legacy, string $sessions ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables, fixed predicates.
		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy} m LEFT JOIN {$sessions} s ON s.id = m.session_id WHERE s.id IS NULL" );
		if ( self::query_failed() ) {
			throw new \RuntimeException( 'Movement fold read failed.' );
		}
		if ( $orphans ) {
			// Dev data a human must repair; the latch retries on every upgrade pass until then.
			Logger::error(
				'Movement fold refused: legacy movements have no session',
				array(
					'orphans' => $orphans,
					'legacy_table' => $legacy,
				)
			);
			throw new \RuntimeException( 'Movement fold found orphaned movements.' );
		}
		// A session already folded holds every copied row; any other legacy row for it was written
		// after its copy (a table recreated by old code) and is refused before any write.
		$this->matched_legacy_movements( $legacy, $sessions, true );
		do {
			// Copied sessions leave the next batch automatically.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables.
			$batch = $wpdb->get_results( "SELECT DISTINCT m.session_id, s.register_id FROM {$legacy} m INNER JOIN {$sessions} s ON s.id = m.session_id WHERE s.movements IS NULL LIMIT 100", ARRAY_A );
			if ( self::query_failed() ) {
				throw new \RuntimeException( 'Movement fold read failed.' );
			}
			foreach ( $batch as $session ) {
				$this->copy_session_movements( $legacy, $sessions, $session['session_id'], $session['register_id'] );
			}
			$size = count( $batch );
		} while ( 100 === $size );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table.
		$legacy_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy}" );
		if ( self::query_failed() || null === $legacy_count || (int) $legacy_count !== $this->matched_legacy_movements( $legacy, $sessions, false ) ) {
			throw new \RuntimeException( 'Movement fold verification failed.' );
		}
	}

	/** Write one session's legacy movements as its array, under its register lock, in one transaction.
	 *
	 * @param string $legacy Legacy movements table.
	 * @param string $sessions Sessions table.
	 * @param string $session_id Session UUID.
	 * @param string $register_id Register UUID.
	 * @throws \RuntimeException On lock, read or write failure; the write is rolled back.
	 */
	private function copy_session_movements( string $legacy, string $sessions, string $session_id, string $register_id ): void {
		global $wpdb;
		$registers = new Register_Store();
		if ( ! $registers->lock( $register_id ) ) {
			throw new \RuntimeException( 'Movement fold lock failed.' );
		}
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table; the id is prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$legacy} WHERE session_id = %s ORDER BY created_at_gmt, id", $session_id ), ARRAY_A );
			if ( self::query_failed() ) {
				throw new \RuntimeException( 'Movement fold read failed.' );
			}
			$movements = wp_json_encode( Cash_Movement_Store::ordered( array_map( array( Cash_Movement_Store::class, 'entry' ), $rows ) ) );
			if ( false === $movements || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Movement fold write failed.' );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; values are prepared.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET movements = %s WHERE id = %s AND movements IS NULL", $movements, $session_id ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'Movement fold write failed.' );
			}
		} finally {
			$registers->unlock( $register_id );
		}
	}

	/** Count the legacy rows each folded session's array holds unchanged; any other row stops the fold.
	 *
	 * Before a copy, pre-fold code may have written to a session after it was copied: a legacy row
	 * the array lacks is new data (uuids do not collide by accident) and is appended, and a stamp
	 * gone from null to set is applied. An id on both sides with any other difference is refused.
	 *
	 * @param string $legacy Legacy movements table.
	 * @param string $sessions Sessions table.
	 * @param bool   $refuse Whether a mismatch is refused data (before the copy) or a failed verification.
	 * @throws \RuntimeException On read failure or a legacy row the session's array does not hold.
	 */
	private function matched_legacy_movements( string $legacy, string $sessions, bool $refuse ): int {
		global $wpdb;
		$matched = 0;
		$cursor = '';
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables; the cursor is prepared.
			$batch = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.session_id FROM {$legacy} m INNER JOIN {$sessions} s ON s.id = m.session_id WHERE s.movements IS NOT NULL AND m.session_id > %s ORDER BY m.session_id LIMIT 100", $cursor ) );
			if ( self::query_failed() ) {
				throw new \RuntimeException( 'Movement fold read failed.' );
			}
			foreach ( $batch as $session_id ) {
				// Each read is checked before the next: a later query clears the earlier one's error.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table; the id is prepared.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$legacy} WHERE session_id = %s", $session_id ), ARRAY_A );
				if ( self::query_failed() ) {
					throw new \RuntimeException( 'Movement fold read failed.' );
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT movements FROM {$sessions} WHERE id = %s", $session_id ) );
				if ( self::query_failed() ) {
					throw new \RuntimeException( 'Movement fold read failed.' );
				}
				$raw = (string) $stored;
				$stored = json_decode( $raw, true );
				$stored = is_array( $stored ) ? array_column( $stored, null, 'id' ) : array();
				$entries = array_column( array_map( array( Cash_Movement_Store::class, 'entry' ), $rows ), null, 'id' );
				$late = array();
				foreach ( $entries as $entry ) {
					if ( $refuse && ( $stored[ $entry['id'] ] ?? null ) !== $entry && self::is_late_movement( $entry, $stored ) ) {
						$late[] = $entry;
					} elseif ( ( $stored[ $entry['id'] ] ?? null ) !== $entry ) {
						if ( ! $refuse ) {
							throw new \RuntimeException( 'Movement fold verification failed.' );
						}
						Logger::error(
							'Movement fold refused: session already folded',
							array(
								'session_id' => $session_id,
								'movement_id' => $entry['id'],
								'legacy_table' => $legacy,
							)
						);
						throw new \RuntimeException( 'Movement fold found a movement for a session already folded.' );
					}
					++$matched;
				}
				if ( $late ) {
					$this->apply_late_movements( $sessions, $session_id, $raw, $late );
				}
				$cursor = $session_id;
			}
			$size = count( $batch );
		} while ( 100 === $size );
		return $matched;
	}

	/** Whether a legacy row is pre-fold data written after its session was copied.
	 *
	 * Either a row the array lacks, of any type, or a row identical but for a stamp gone from null to set.
	 *
	 * @param array $entry Legacy row as an entry.
	 * @param array $stored The session's array entries by id.
	 */
	private static function is_late_movement( array $entry, array $stored ): bool {
		if ( ! isset( $stored[ $entry['id'] ] ) ) {
			return true;
		}
		return null === $stored[ $entry['id'] ]['voided_by']
			&& null !== $entry['voided_by']
			&& array_merge( $entry, array( 'voided_by' => null ) ) === $stored[ $entry['id'] ];
	}

	/** Append late movements and apply late stamps to a folded session's array, under its register lock, in one transaction.
	 *
	 * @param string $sessions Sessions table.
	 * @param string $session_id Session UUID.
	 * @param string $raw The array as checked; the write requires it unchanged.
	 * @param array  $late New entries and stamped targets.
	 * @throws \RuntimeException On read, lock or write failure; the write is rolled back.
	 */
	private function apply_late_movements( string $sessions, string $session_id, string $raw, array $late ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
		$register_id = (string) $wpdb->get_var( $wpdb->prepare( "SELECT register_id FROM {$sessions} WHERE id = %s", $session_id ) );
		if ( self::query_failed() ) {
			throw new \RuntimeException( 'Movement fold read failed.' );
		}
		$registers = new Register_Store();
		if ( ! $registers->lock( $register_id ) ) {
			throw new \RuntimeException( 'Movement fold lock failed.' );
		}
		try {
			$entries = array_column( (array) json_decode( $raw, true ), null, 'id' );
			foreach ( $late as $entry ) {
				$entries[ $entry['id'] ] = $entry;
			}
			$movements = wp_json_encode( Cash_Movement_Store::ordered( array_values( $entries ) ) );
			if ( false === $movements || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Movement fold write failed.' );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; values are prepared.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET movements = %s WHERE id = %s AND movements = %s", $movements, $session_id, $raw ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'Movement fold write failed.' );
			}
		} finally {
			$registers->unlock( $register_id );
		}
		Logger::log(
			'Movement fold applied late movements',
			array(
				'session_id' => $session_id,
				'count' => count( $late ),
				'movement_ids' => array_column( $late, 'id' ),
			)
		);
	}

	/** Schema 12: Free keeps one register in the `wcpos_register` option. Copy the register with the
	 * most recent session (else the oldest created) under its UUID, keep every other row in the
	 * option's `retired` map so history still names it, verify, then drop the table (dev builds of
	 * `next` only; no release shipped it). An option already holding a register the table also
	 * holds is kept. Runs under the default-register lock, outside any transaction (DDL commits).
	 *
	 * @return bool False when the option did not read back; the old latch stays.
	 * @throws \RuntimeException On probe, lock, read, write or drop failure; leave the old latch in place.
	 */
	private function fold_registers_into_option(): bool {
		global $wpdb;
		$legacy = $wpdb->prefix . Register_Store::LEGACY_TABLE;
		if ( ! self::legacy_table_exists( $legacy, 'Register fold' ) ) {
			return true;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', Register_Store::DEFAULT_LOCK ) ) ) {
			throw new \RuntimeException( 'Register fold lock failed.' );
		}
		try {
			$sessions = ( new Register_Session_Store() )->table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned and known legacy tables, fixed query.
			$rows = $wpdb->get_results( "SELECT r.id, r.name, r.store_id, r.default_float, r.created_at_gmt, MAX(s.opened_at_gmt) AS last_opened FROM {$legacy} r LEFT JOIN {$sessions} s ON s.register_id = r.id GROUP BY r.id, r.name, r.store_id, r.default_float, r.created_at_gmt ORDER BY r.created_at_gmt, r.id", ARRAY_A );
			if ( self::query_failed() ) {
				throw new \RuntimeException( 'Register fold read failed.' );
			}
			if ( $rows ) {
				Option_Register_Repository::forget_cached();
				$option = get_option( Option_Register_Repository::OPTION, array() );
				$option = is_array( $option ) ? $option : array();
				$held = is_array( $option['register'] ?? null ) ? $option['register'] : null;
				$candidates = $rows;
				if ( null !== $held && ! in_array( $held['id'], array_column( $rows, 'id' ), true ) ) {
					// A register already in the option competes on the same rule, so a re-run after a
					// recreated table keeps it, and a lazily minted one nobody used loses to the table's.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
					$held['last_opened'] = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(opened_at_gmt) FROM {$sessions} WHERE register_id = %s", $held['id'] ) );
					if ( self::query_failed() ) {
						throw new \RuntimeException( 'Register fold read failed.' );
					}
					$candidates[] = $held;
				}
				// The most recent session wins, else the oldest register; the option's own register when the table holds it.
				usort(
					$candidates,
					static function ( $a, $b ) {
						return array( null === $a['last_opened'], $b['last_opened'], $a['created_at_gmt'] ) <=> array( null === $b['last_opened'], $a['last_opened'], $b['created_at_gmt'] );
					}
				);
				$keep = null !== $held && in_array( $held['id'], array_column( $rows, 'id' ), true ) ? $held['id'] : $candidates[0]['id'];
				$retired = is_array( $option['retired'] ?? null ) ? $option['retired'] : array();
				if ( null !== $held && $held['id'] !== $keep ) {
					$retired[ $held['id'] ] = $option['register'];
				}
				foreach ( $rows as $row ) {
					unset( $row['last_opened'] );
					$row['store_id'] = null === $row['store_id'] ? null : (int) $row['store_id'];
					if ( $row['id'] !== $keep ) {
						$retired[ $row['id'] ] = $row;
					} elseif ( null === $held || $keep !== $held['id'] ) {
						// Free's register has no store; Pro re-parents from the retired rows that carry one.
						$option['register'] = array_replace( $row, array( 'store_id' => null ) );
					}
				}
				$option['retired'] = $retired;
				update_option( Option_Register_Repository::OPTION, $option, true );
				Option_Register_Repository::forget_cached();
				$stored = get_option( Option_Register_Repository::OPTION );
				$others = array_diff( array_column( $rows, 'id' ), array( $keep ) );
				if ( ! is_array( $stored ) || ( $stored['register']['id'] ?? null ) !== $keep || array_diff( $others, array_keys( (array) ( $stored['retired'] ?? array() ) ) ) ) {
					Logger::error(
						'Register fold refused: the option did not read back',
						array(
							'kept' => $keep,
							'legacy_table' => $legacy,
						)
					);
					return false;
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table name.
			if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ) ) {
				throw new \RuntimeException( 'Register fold drop failed.' );
			}
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', Register_Store::DEFAULT_LOCK ) );
		}
	}

	/** Add a column unless it exists; DDL runs outside closure transactions.
	 *
	 * @param string $table Owned table.
	 * @param string $column Fixed column name.
	 * @param string $definition Fixed column definition.
	 * @param string $label Error message prefix.
	 * @throws \RuntimeException On read or DDL failure.
	 */
	private function add_column_if_missing( string $table, string $column, string $definition, string $label ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed column name.
		$existing = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$column}'" );
		if ( self::query_failed() ) {
			throw new \RuntimeException( esc_html( "{$label} read failed." ) );
		}
		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed definition.
			$wpdb->query( "ALTER TABLE {$table} ADD {$column} {$definition}" );
			if ( self::query_failed() ) {
				throw new \RuntimeException( esc_html( "{$label} upgrade failed." ) );
			}
		}
	}

	/** Add an index unless one of that name exists; DDL runs outside closure transactions.
	 *
	 * @param string $table Owned table.
	 * @param string $name Key name.
	 * @param string $definition Fixed key definition.
	 * @param string $label Error message prefix.
	 * @throws \RuntimeException On read or DDL failure.
	 */
	private function add_index_if_missing( string $table, string $name, string $definition, string $label ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the key name is prepared.
		$existing = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) );
		if ( self::query_failed() ) {
			throw new \RuntimeException( esc_html( "{$label} read failed." ) );
		}
		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed definition.
			$wpdb->query( "ALTER TABLE {$table} ADD {$definition}" );
			if ( self::query_failed() ) {
				throw new \RuntimeException( esc_html( "{$label} upgrade failed." ) );
			}
		}
	}

	/**
	 * Whether the last database query failed, read fresh after any earlier check.
	 *
	 * @phpstan-impure
	 */
	private static function query_failed(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param int $blog_id Blog ID.
	 */
	public function activate_new_site( $blog_id ): void {
		if ( 1 !== did_action( 'wpmu_new_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		$this->single_activate();
		restore_current_blog();
	}

	/**
	 * Check min version of PHP.
	 */
	private function php_check() {
		$php_version = PHP_VERSION;
		if ( version_compare( $php_version, PHP_MIN_VERSION, '>' ) ) {
			return true;
		}

		// Defer __() call to avoid "too early" warning in WordPress 6.7+.
		add_action(
			'admin_init',
			function () {
				$message = \sprintf(
					// translators: 1: Minimum PHP version, 2: Update URL.
					__( '<strong>WCPOS</strong> requires PHP %1$s or higher. Read more information about <a href="%2$s">how you can update</a>', 'woocommerce-pos' ),
					PHP_MIN_VERSION,
					'http://www.wpupdatephp.com/update/'
				) . ' &raquo;';

				Admin\Notices::add( $message );
			}
		);
	}

	/**
	 * Check min version of WooCommerce installed.
	 */
	private function woocommerce_check() {
		if ( class_exists( '\WooCommerce' ) && version_compare( WC()->version, WC_MIN_VERSION, '>=' ) ) {
			return true;
		}

		// Defer __() call to avoid "too early" warning in WordPress 6.7+.
		add_action(
			'admin_init',
			function () {
				$message = \sprintf(
					// translators: 1: WooCommerce URL, 2: Minimum WC version, 3: Plugins URL.
					__( '<strong>WCPOS</strong> requires <a href="%1$s">WooCommerce %2$s or higher</a>. Please <a href="%3$s">install and activate WooCommerce</a>', 'woocommerce-pos' ),
					'http://wordpress.org/plugins/woocommerce/',
					WC_MIN_VERSION,
					admin_url( 'plugins.php' )
				) . ' &raquo;';

				Admin\Notices::add( $message );
			}
		);
	}

	/**
	 * POS Frontend will give 404 if pretty permalinks not active.
	 */
	private function permalink_check(): void {
		$permalinks = get_option( 'permalink_structure' );

		// early return.
		if ( $permalinks ) {
			return;
		}

		$message = /* translators: Plugin activation notice label. */ __( '<strong>WooCommerce REST API</strong> requires <em>pretty</em> permalinks to work correctly', 'woocommerce-pos' ) . '. ';
		$message .= \sprintf( '<a href="%s">%s</a>', admin_url( 'options-permalink.php' ), /* translators: Plugin activation notice label. */ __( 'Enable permalinks', 'woocommerce-pos' ) ) . ' &raquo;';

		Admin\Notices::add( $message );
	}

	/**
	 * Check version number, runs every admin page load.
	 */
	private function version_check(): void {
		$old                  = (string) Services\Settings::get_db_version();
		$plugin_needs_upgrade = version_compare( $old, VERSION, '<' );
		$sync_needs_upgrade   = Sync_Api::SCHEMA_VERSION !== get_option( Sync_Api::SCHEMA_OPTION, null );

		$role_caps_fingerprint = $this->role_caps_fingerprint();
		$role_caps_need_sync   = get_option( 'woocommerce_pos_role_caps_fingerprint' ) !== $role_caps_fingerprint
			|| false === get_option( 'woocommerce_pos_role_caps_synced' );
		if ( ! $plugin_needs_upgrade && ! $sync_needs_upgrade && ! $role_caps_need_sync ) {
			return;
		}

		if ( ! $this->acquire_db_upgrade_lock() ) {
			return;
		}

		$locked_old                  = (string) Services\Settings::get_db_version();
		$locked_plugin_needs_upgrade = version_compare( $locked_old, VERSION, '<' );
		$locked_sync_needs_upgrade   = Sync_Api::SCHEMA_VERSION !== get_option( Sync_Api::SCHEMA_OPTION, null );

		$locked_role_caps_fingerprint = $this->role_caps_fingerprint();
		$locked_role_caps_need_sync   = get_option( 'woocommerce_pos_role_caps_fingerprint' ) !== $locked_role_caps_fingerprint
			|| false === get_option( 'woocommerce_pos_role_caps_synced' );
		if ( ! $locked_plugin_needs_upgrade && ! $locked_sync_needs_upgrade && ! $locked_role_caps_need_sync ) {
			$this->release_db_upgrade_lock();
			return;
		}

		if ( $locked_plugin_needs_upgrade ) {
			Font_Pack_Loader::schedule( true );
			Services\Settings::bump_versions();
		}

		if ( $locked_plugin_needs_upgrade || $locked_role_caps_need_sync ) {
			// Re-run activation to sync role capabilities. add_role() and add_cap()
			// are both idempotent, so this is safe. Without this, capabilities added
			// in newer versions would never reach existing installs because add_role()
			// is a no-op when the role already exists.
			// Deferred to 'init' because create_pos_roles() calls __() which
			// requires translations to be loaded (WordPress 6.7+).
			add_action(
				'init',
				function () {
					$this->single_activate( false, false );
				}
			);
		}

		$lock_released = false;
		$release_lock  = function () use ( &$lock_released ): void {
			if ( $lock_released ) {
				return;
			}

			$lock_released = true;
			$this->release_db_upgrade_lock();
		};

		// Safety net in case woocommerce_init does not fire for this request.
		add_action( 'shutdown', $release_lock );

		// Defer db_upgrade to woocommerce_init when WC is fully loaded.
		// This prevents conflicts with plugins like WC Subscriptions that hook
		// into before_delete_post and assume WC()->order_factory is available.
		add_action(
			'woocommerce_init',
			function () use ( $locked_old, $locked_plugin_needs_upgrade, $locked_sync_needs_upgrade, $release_lock ) {
				try {
					$this->db_upgrade( $locked_old, VERSION );

					// Report the upgrade only once the migration has actually
					// completed — queueing it beside bump_versions() would claim a
					// finished upgrade even when db_upgrade() threw or never ran.
					// Still exactly-once: the version was bumped above, so the
					// upgrade is not re-detected on the next request.
					if ( $locked_plugin_needs_upgrade ) {
						( new Lifecycle_Events() )->record_upgrade( $locked_old, VERSION );
					}
					if ( $locked_sync_needs_upgrade && Sync_Api::SCHEMA_VERSION === get_option( Sync_Api::SCHEMA_OPTION, null ) ) {
						( new Sync_Journal() )->register_hooks();
						( new Integrity_Digest() )->register_hooks();
					}
				} catch ( \RuntimeException $error ) {
					// Re-throwing would fatal every request until the database is fixed. The schema
					// latch keeps its old value on its own; the plugin-version latch was bumped above,
					// so put it back too, or a failed fiscal-store install after the versioned files
					// would never be re-detected and the fiscal schema would stay stale for good.
					if ( $locked_plugin_needs_upgrade ) {
						update_option( 'woocommerce_pos_db_version', $locked_old );
					}
					Logger::error( 'WCPOS database upgrade failed: ' . $error->getMessage() );
				} finally {
					$release_lock();
					remove_action( 'shutdown', $release_lock );
				}
			}
		);
	}

	/**
	 * Acquire the DB upgrade lock.
	 *
	 * @return bool True when this request owns the lock.
	 */
	private function acquire_db_upgrade_lock(): bool {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		return \WP_Upgrader::create_lock( self::DB_UPGRADE_LOCK_NAME, self::DB_UPGRADE_LOCK_TTL );
	}

	/**
	 * Release the DB upgrade lock.
	 */
	private function release_db_upgrade_lock(): void {
		if ( ! class_exists( '\WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		\WP_Upgrader::release_lock( self::DB_UPGRADE_LOCK_NAME );
	}

	/**
	 * Plugin conflicts.
	 *
	 * - NextGEN Gallery is a terrible plugin. It buffers all content on 'init' action, priority -1 and inserts junk code.
	 */
	private function plugin_check(): void {
		// disable NextGEN Gallery resource manager
		// if ( ! \defined( 'NGG_DISABLE_RESOURCE_MANAGER' ) ) {
		// \define( 'NGG_DISABLE_RESOURCE_MANAGER', true );
		// }.
	}

	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted.
	 */
	private function get_blog_ids() {
		global $wpdb;

		// get an array of blog ids.
		$sql = "SELECT blog_id FROM $wpdb->blogs
      WHERE archived = '0' AND spam = '0'
      AND deleted = '0'";

		return $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query, no user input
	}

	/**
	 * Get the role capability definition.
	 *
	 * @return array<string, array<string, bool>|array<int, string>> Role capabilities keyed by role.
	 */
	public static function role_capability_definition(): array {
		// WC 9.9 replaced promote_users with create_customers for customer creation.
		$customer_create_cap = \defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) // @phpstan-ignore-line
			? 'create_customers'
			: 'promote_users';

		// Cashier role.
		$cashier_capabilities = array(
			'manage_woocommerce_pos_cash' => true,
			'view_woocommerce_pos_reports' => true,
			'read'                      => true,
			'read_private_products'     => true,
			'publish_products'          => true,
			'edit_product'              => true,
			'edit_products'             => true,
			'edit_published_products'   => true,
			'edit_private_products'     => true,
			'edit_others_products'      => true,
			'read_private_shop_orders'  => true,
			'publish_shop_orders'       => true,
			'edit_shop_orders'          => true,
			'edit_others_shop_orders'   => true,
			'list_users'                => true,
			$customer_create_cap        => true,
			'edit_users'                => true,
			'read_private_shop_coupons' => true,
			'publish_shop_coupons'      => true,
			'edit_shop_coupons'         => true,
			'edit_published_shop_coupons' => true,
			'edit_private_shop_coupons' => true,
			'edit_others_shop_coupons'  => true,
			'manage_product_terms'      => true,
		);

		return array(
			'cashier'       => $cashier_capabilities,
			'administrator' => array(
				'manage_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
				'access_woocommerce_pos',
				'edit_wcpos_store',
				'read_wcpos_store',
				'delete_wcpos_store',
				'edit_wcpos_stores',
				'edit_others_wcpos_stores',
				'publish_wcpos_stores',
				'read_private_wcpos_stores',
				'delete_wcpos_stores',
				'delete_private_wcpos_stores',
				'delete_published_wcpos_stores',
				'delete_others_wcpos_stores',
				'edit_private_wcpos_stores',
				'edit_published_wcpos_stores',
			),
			'shop_manager'  => array(
				'manage_woocommerce_pos',
				'access_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
			),
		);
	}

	/**
	 * Get the role-capabilities definition fingerprint.
	 */
	private function role_caps_fingerprint(): string {
		return md5( wp_json_encode( self::role_capability_definition() ) );
	}

	/**
	 * Add POS specific roles.
	 *
	 * @param string[]|null $capabilities Capability names to sync onto an existing role, or null for
	 *                                    every default. A missing role is always created with the full set.
	 */
	private function create_pos_roles( ?array $capabilities = null ): void {
		$role_capabilities    = self::role_capability_definition();
		$cashier_capabilities = $role_capabilities['cashier'];

		add_role(
			'cashier',
			/* translators: Plugin activation notice label. */
			__( 'Cashier', 'woocommerce-pos' ),
			// A missing role is created whole, access gate included, whatever
			// subset an incremental upgrade asked to sync.
			array_merge( array( 'access_woocommerce_pos' => true ), $cashier_capabilities )
		);

		$obsolete_customer_create_cap = isset( $cashier_capabilities['create_customers'] ) ? 'promote_users' : 'create_customers';
		$cashier                      = get_role( 'cashier' );
		if ( $cashier ) {
			$cashier->remove_cap( $obsolete_customer_create_cap );
		}

		// Sync the requested capabilities to the role. add_role() is a no-op when
		// the role already exists, so capabilities added in newer versions would
		// never reach existing installs without this.
		$this->add_pos_capability(
			array(
				'cashier' => $capabilities ?? array_merge(
					array( 'access_woocommerce_pos' ),
					array_keys( $cashier_capabilities )
				),
			)
		);
	}

	/**
	 * Add default pos capabilities to administrator and shop_manager roles.
	 *
	 * @param array $roles An array of arrays representing the roles and their POS capabilities.
	 */
	private function add_pos_capability( $roles ): void {
		foreach ( $roles as $slug => $caps ) {
			$role = get_role( $slug );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Upgrade database.
	 *
	 * @param string $old     Old version.
	 * @param string $current Current version.
	 */
	private function db_upgrade( $old, $current ): void {
		$db_updates = array(
			'0.4'          => 'updates/update-0.4.php',
			'0.4.6'        => 'updates/update-0.4.6.php',
			'1.0.0-beta.1' => 'updates/update-1.0.0-beta.1.php',
			'1.6.1'        => 'updates/update-1.6.1.php',
			'1.8.0'        => 'updates/update-1.8.0.php',
			'1.8.7'        => 'updates/update-1.8.7.php',
			'1.8.12'       => 'updates/update-1.8.12.php',
			'1.8.13'       => 'updates/update-1.8.13.php',
			'1.9.0'        => 'updates/update-1.9.0.php',
			'1.10.0'       => 'updates/update-1.10.0.php',
			'2.0.0'        => 'updates/update-2.0.0.php',
		);
		foreach ( $db_updates as $version => $updater ) {
			if ( version_compare( $version, $old, '>' ) &&
			 version_compare( $version, $current, '<=' ) ) {
				include $updater;
			}
		}

		// Bring untouched gallery copies up to the bundled markup. Runs here for immediacy on a
		// normal upgrade, and again from the admin_init hook that self-heals an upgrade which
		// bumped the version without reaching woocommerce_init (see maintain()). Idempotent.
		Templates\Gallery_Update_Status::maintain();

		if ( Sync_Api::SCHEMA_VERSION !== get_option( Sync_Api::SCHEMA_OPTION, null ) ) {
			$this->install_sync_schema();
		}

		$this->install_till_tables();

		try {
			( new Services\Fiscal_Record_Store() )->seed_sale_sequence();
		} catch ( \RuntimeException $error ) {
			Logger::log( 'Fiscal sale sequence seed deferred: ' . $error->getMessage() );
		}

		// Installs that predate 2026-09 wrote the per-request latches with
		// autoload off; every upgrade re-asserts autoload so the flip is
		// idempotent and needs no versioned update file.
		self::autoload_request_latches();
		Admin\Permalink::ensure_default();
		Receipt_Snapshot_Cleanup::arm();
	}

	/**
	 * Every option row that is read on EVERY request and must therefore ride in
	 * alloptions: the three sync latches the Init constructor reads, the permalink
	 * slug Template_Router reads, and each registered settings section that
	 * declares {@see Services\Settings\Abstract_Section::autoload()} — the
	 * sections are the extension point, so Pro's and extensions' sections join
	 * the repair by declaring it, without touching this file.
	 *
	 * Needed because core's update_option() returns early on an unchanged value
	 * WITHOUT touching the autoload column, so a writer alone never repairs a row
	 * an older release wrote with autoload off. Without a persistent object cache
	 * each such row cost one `SELECT option_value` per page load (measured
	 * 2026-09-03 on dev-next and dev-free).
	 *
	 * @return string[]
	 */
	private static function request_option_names(): array {
		$names = array(
			Sync_Api::SCHEMA_OPTION,
			\WCPOS\WooCommercePOS\Sync\Visibility_Observer::SEED_VERSION_OPTION,
			\WCPOS\WooCommercePOS\Sync\Config_Fingerprint::CLEANUP_VERSION_OPTION,
			Admin\Permalink::DB_KEY,
		);
		foreach ( Services\Settings::instance()->sections()->all() as $section ) {
			if ( $section instanceof Services\Settings\Abstract_Section && $section->autoload() ) {
				$names[] = $section->autoload_option_name();
			}
		}
		return $names;
	}

	/**
	 * Flip the per-request rows to autoload in place, and seed the settings
	 * sections that are absent.
	 *
	 * One UPDATE on the flag column: never delete-and-recreate, because
	 * `Sync_Api::SCHEMA_OPTION` gates the sync observers on every request and a
	 * request landing in that gap would run with journaling off. 'yes' is
	 * accepted by every core version (6.6+ maps it alongside 'on'). Idempotent:
	 * already-autoloaded rows match nothing. Latches that were never written
	 * stay absent (their absence is the signal). An autoloaded settings section
	 * that was never saved is seeded as an autoloaded row — an absent option is
	 * queried on every request too. General also persists its migrated consent
	 * so the legacy row does not remain on the read path; other defaults stay
	 * dynamic.
	 */
	public static function autoload_request_latches(): void {
		global $wpdb;
		// The key comes from the section itself (autoload_option_name()): Pro's
		// License section stores under a Pro-prefixed key, so deriving it from
		// id() flipped nothing for that row and seeded a stray free-prefixed one.
		foreach ( Services\Settings::instance()->sections()->all() as $section ) {
			if ( ! $section instanceof Services\Settings\Abstract_Section || ! $section->autoload() ) {
				continue;
			}
			$option_name = $section->autoload_option_name();
			if ( false === get_option( $option_name ) ) {
				$value = $section instanceof Services\Settings\General_Section
					? array( 'tracking_consent' => $section->raw_tracking_consent() )
					: array();
				add_option( $option_name, $value, '', true );
			}
		}
		$options      = self::request_option_names();
		$placeholders = implode( ', ', array_fill( 0, \count( $options ), '%s' ) );
		$flipped      = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the flag column is the target; caches are cleared below.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN ({$placeholders}) AND autoload NOT IN ('yes', 'on', 'auto-on')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated for the prepared values.
				$options
			)
		);
		if ( ! $flipped ) {
			return;
		}
		foreach ( $options as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * If \WCPOS\WooCommercePOSPro\ is installed, check the version is above MIN_PRO_VERSION.
	 */
	private function pro_version_check(): void {
		if ( class_exists( '\WCPOS\WooCommercePOSPro\Activator' ) ) {
			if ( version_compare( \WCPOS\WooCommercePOSPro\VERSION, MIN_PRO_VERSION, '<' ) ) { // @phpstan-ignore-line

				/*
				 * NOTE: the deactivate_plugins function is not available in the frontend or ajax
				 * This is an extreme situation where the Pro plugin could crash the site, so we need to deactivate it
				 */
				if ( ! \function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . '/wp-admin/includes/plugin.php';
				}

				// WCPOS Pro is activated, but the version is too low - use the constant for dynamic folder name.
				deactivate_plugins( \WCPOS\WooCommercePOSPro\PLUGIN_FILE ); // @phpstan-ignore-line

				// Defer __() call to avoid "too early" warning in WordPress 6.7+.
				add_action(
					'admin_init',
					function () {
						$message = \sprintf(
							// translators: 1: WCPOS Pro URL, 2: Minimum Pro version, 3: Plugins URL.
							__( '<strong>WCPOS</strong> requires <a href="%1$s">WCPOS Pro %2$s or higher</a>. Please <a href="%3$s">install and activate WCPOS Pro</a>', 'woocommerce-pos' ),
							'https://wcpos.com/my-account',
							MIN_PRO_VERSION,
							admin_url( 'plugins.php' )
						) . ' &raquo;';

						Admin\Notices::add( $message );
					}
				);
			}
		}
	}
}
