<?php
/**
 * Fired when the plugin is uninstalled (deleted via the WordPress admin).
 *
 * WCPOS distinguishes three kinds of data:
 *
 * - Derived/operational state — sync tables (rebuildable from WooCommerce
 *   data on reinstall), cron events, transients, the print-job queue, JWT
 *   secrets/tokens, sync watermarks and version latches, plugin-owned upload
 *   directories and log files. Always removed.
 *
 * - User-authored configuration — POS settings, receipt templates (and their
 *   revisions, taxonomies, and options), the receipt sequence counter, the
 *   cashier role. Preserved by default (a delete/reinstall cycle must not
 *   destroy a merchant's receipt designs or numbering continuity). Removed
 *   only when a full wipe is requested via the WCPOS_REMOVE_ALL_DATA
 *   constant (wp-config.php) or the wcpos_remove_all_data option.
 *
 * - Data owned by others — WCPOS Pro's options (woocommerce_pos_pro_*,
 *   wcpos_stores_migrated) are NEVER touched, even on a full wipe: they
 *   belong to a different plugin. POS metadata on orders/products
 *   (_woocommerce_pos_* post/order meta) is part of WooCommerce's order
 *   history and is also never touched.
 *
 * This file runs standalone — the plugin is NOT loaded — so table, option,
 * hook, and post-type names are hardcoded. Test_Uninstall guards them
 * against drift from the class constants.
 *
 * There is deliberately no is-this-really-an-uninstall guard at the top:
 * the file only defines functions, and the sweep at the bottom is gated on
 * WP_UNINSTALL_PLUGIN. Loading the definitions (tests, direct access) has
 * no side effects.
 *
 * @author  Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     https://wcpos.com
 * @package WooCommercePOS
 */

/**
 * Plugin table names (without the site prefix) dropped on uninstall.
 *
 * Includes the two pre-1.10 legacy tables in case the site skipped the
 * upgrade that removes them.
 *
 * @return string[] Table name suffixes.
 */
function woocommerce_pos_uninstall_table_suffixes(): array {
	return array(
		'wcpos_sync_journal',
		'wcpos_sync_stored_digest',
		'wcpos_sync_mutations',
		// Legacy (pre-unified-journal) tables.
		'wcpos_sync_change_log',
		'wcpos_sync_order_index',
	);
}

/**
 * Cron hooks whose scheduled events are cleared on uninstall.
 *
 * @return string[] Hook names.
 */
function woocommerce_pos_uninstall_cron_hooks(): array {
	return array(
		'wcpos_sync_journal_purge',
		'wcpos_print_job_purge',
		'wcpos_integrity_digest_rebuild',
		'wcpos_cloud_print_submit',
		'wcpos_relay_reregister',
		'wcpos_analytics_group_refresh',
		// Legacy (pre-unified-journal) purge hook.
		'wcpos_change_log_purge',
	);
}

/**
 * Whether the user opted into removing user-authored configuration too.
 *
 * The constant accepts booleans and boolean-ish strings; a pasted
 * define( 'WCPOS_REMOVE_ALL_DATA', 'no' ) must NOT trigger the wipe, so
 * both paths validate rather than truthiness-cast.
 */
function woocommerce_pos_uninstall_remove_all_data(): bool {
	if ( \defined( 'WCPOS_REMOVE_ALL_DATA' ) ) {
		return (bool) filter_var( WCPOS_REMOVE_ALL_DATA, FILTER_VALIDATE_BOOLEAN );
	}

	return (bool) filter_var( get_option( 'wcpos_remove_all_data', 'no' ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Whether WCPOS Pro is present on this install (active or merely on disk).
 *
 * Pro shares the free plugin's log source and translation namespace, so log
 * and translation cleanup is skipped while Pro may still be using them. The
 * detection result passes through a filter so tests can pin either state
 * deterministically, regardless of what exists on the checkout's disk —
 * during a real uninstall no plugin code is loaded, so the filter is a
 * pass-through.
 */
function woocommerce_pos_uninstall_pro_installed(): bool {
	$pro_plugin = 'woocommerce-pos-pro/woocommerce-pos-pro.php';
	$installed  = file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pro_plugin )
		|| in_array( $pro_plugin, (array) get_option( 'active_plugins', array() ), true );
	if ( ! $installed && \function_exists( 'is_multisite' ) && is_multisite() ) {
		$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		$installed       = isset( $network_plugins[ $pro_plugin ] );
	}

	return (bool) apply_filters( 'woocommerce_pos_uninstall_pro_installed', $installed );
}

/**
 * Delete POS session/token/preference user meta.
 *
 * Network-wide by nature: wp_usermeta is shared across a multisite network
 * and these keys are not blog-prefixed, so this runs ONCE, not per site.
 * Prefix sweep rather than a key list — every key the plugin writes uses
 * one of these prefixes, so new keys are covered automatically.
 */
function woocommerce_pos_uninstall_user_meta(): void {
	global $wpdb;

	$patterns = array(
		$wpdb->esc_like( '_woocommerce_pos_' ) . '%',
		$wpdb->esc_like( '_wcpos_' ) . '%',
		$wpdb->esc_like( 'wcpos_' ) . '%',
	);
	$where    = implode( ' OR ', array_fill( 0, \count( $patterns ), 'meta_key LIKE %s' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is placeholders only, prepared here; prefix-scoped uninstall sweep.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE {$where}", $patterns ) );
}

/**
 * Recursively delete a plugin-owned directory.
 *
 * Refuses anything whose basename does not start with the plugin prefix, so
 * a corrupted path can never delete outside plugin-owned directories.
 *
 * @param string $dir Absolute directory path.
 */
function woocommerce_pos_uninstall_rmdir( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) || 0 !== strpos( basename( $dir ), 'wcpos-' ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- WP_Filesystem is not initialised during uninstall.
			rmdir( $item->getPathname() );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $item->getPathname() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions
	rmdir( $dir );
}

/**
 * Delete every post of a type, plus its revisions, meta, and term relationships.
 *
 * Bulk SQL instead of wp_delete_post() per row — the print-job queue can be
 * large. Children (revisions and their meta, term relationships) go first
 * because the joins need the parent rows.
 *
 * @param string $post_type Post type to delete.
 */
function woocommerce_pos_uninstall_post_type( string $post_type ): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bulk delete at uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} r ON r.ID = pm.post_id INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.post_type = %s",
			$post_type
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE r FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.post_type = %s",
			$post_type
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = %s",
			$post_type
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s",
			$post_type
		)
	);
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

/**
 * Delete a plugin taxonomy's terms, term meta, and term-taxonomy rows.
 *
 * @param string $taxonomy Taxonomy slug.
 */
function woocommerce_pos_uninstall_taxonomy( string $taxonomy ): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bulk delete at uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE tm FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = %s",
			$taxonomy
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE t FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
			$taxonomy
		)
	);
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

/**
 * Map a raw count onto its reporting band.
 *
 * Mirrors Analytics_Profile::COUNT_BANDS, which cannot be used here because no
 * plugin code is loaded during uninstall. Test_Uninstall pins the two together.
 *
 * @param int $count The raw count.
 *
 * @return string The band label.
 */
function woocommerce_pos_uninstall_count_band( int $count ): string {
	foreach ( array(
		'0'        => 0,
		'1-10'     => 10,
		'11-100'   => 100,
		'101-1000' => 1000,
	) as $label => $upper_bound ) {
		if ( $count <= $upper_bound ) {
			return $label;
		}
	}

	return '1000+';
}

/**
 * Report the uninstall to product analytics, if the user opted into tracking.
 *
 * The plugin is not loaded during uninstall, so this cannot use the Analytics
 * service and hardcodes the option names and endpoint instead — the same
 * doctrine the rest of this file follows. Test_Uninstall pins these against
 * the class constants they mirror.
 *
 * Deliberately narrow: consent is read from the stored settings and anything
 * other than an explicit "allowed" sends nothing. The payload carries no
 * store data beyond what the deactivation event already reports.
 *
 * Must run BEFORE the option sweep, which deletes the consent setting and the
 * site UUID this depends on.
 */
function woocommerce_pos_uninstall_report(): void {
	$settings = get_option( 'woocommerce_pos_settings_general', array() );
	$consent  = \is_array( $settings ) && isset( $settings['tracking_consent'] )
		? $settings['tracking_consent']
		: null;

	// Sites that answered before the setting moved to `general` still hold it in
	// the legacy `tools` option. General_Section::migrate() resolves that in
	// memory and deliberately never writes it back, so reading `general` alone
	// would treat an explicitly opted-in legacy site as undecided and silently
	// drop its event. Mirror the same fallback, same precedence.
	if ( null === $consent ) {
		$legacy_tools = get_option( 'woocommerce_pos_settings_tools', array() );
		$consent      = \is_array( $legacy_tools ) && isset( $legacy_tools['tracking_consent'] )
			? $legacy_tools['tracking_consent']
			: 'undecided';
	}

	if ( 'allowed' !== $consent ) {
		return;
	}

	$site_uuid = get_option( 'woocommerce_pos_uuid', '' );
	if ( ! \is_string( $site_uuid ) || '' === $site_uuid ) {
		return;
	}

	// Prefer the acting user's UUID so the event joins the rest of their
	// history; fall back to the site so an uninstall run by WP-CLI (no current
	// user) is still counted.
	$distinct_id = '';
	if ( \function_exists( 'get_current_user_id' ) ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$distinct_id = (string) get_user_meta( $user_id, '_woocommerce_pos_uuid', true );
		}
	}
	if ( '' === $distinct_id ) {
		$distinct_id = 'site_' . $site_uuid;
	}

	// Read the release being deleted from its plugin header. The persisted db
	// version can be stale when updated files are deleted before version_check().
	$plugin_data    = get_file_data( __DIR__ . '/woocommerce-pos.php', array( 'version' => 'Version' ), 'plugin' );
	$plugin_version = $plugin_data['version'] ?? '';

	$installed_at = (int) get_option( 'woocommerce_pos_installed_at', 0 );
	$properties   = array(
		'$groups'        => array( 'site' => $site_uuid ),
		'plugin_version' => \is_string( $plugin_version ) ? $plugin_version : '',
		'locale'         => get_locale(),
	);

	if ( $installed_at > 0 ) {
		$properties['days_since_install'] = max( 0, (int) floor( ( time() - $installed_at ) / DAY_IN_SECONDS ) );
	}

	// Banded, matching Analytics_Profile — an exact order count never leaves.
	// The group refresh persists the band precisely so this does not depend on a
	// warm cache; the hourly landing-profile transient is only a fallback for
	// sites that have not refreshed since this shipped. Neither is present on a
	// site that never consented, which never reaches this line anyway.
	$band = get_option( 'woocommerce_pos_analytics_order_band', '' );
	if ( \is_string( $band ) && '' !== $band ) {
		$properties['order_count_band'] = $band;
	} else {
		$profile = get_transient( 'wcpos_landing_profile' );
		if ( \is_array( $profile ) && isset( $profile['order_count'] ) ) {
			$properties['order_count_band'] = woocommerce_pos_uninstall_count_band( (int) $profile['order_count'] );
		}
	}

	// Mirror Analytics::get_token() / get_host(): constant first, then the
	// filter. The plugin is not loaded, but a mu-plugin or wp-config define can
	// still point a self-hosted deployment at its own project, and sending its
	// uninstall events to the default project instead would be wrong twice over.
	$token = \defined( 'WCPOS_POSTHOG_TOKEN' ) ? (string) WCPOS_POSTHOG_TOKEN : 'phc_BhTJzZ7fXMqcD4MiaUJQsQqPkEpu94yoSAthXFBWemvd';
	$token = (string) apply_filters( 'woocommerce_pos_posthog_token', $token );

	$body = wp_json_encode(
		array(
			'api_key'     => $token,
			'event'       => 'wcpos_uninstalled',
			'distinct_id' => $distinct_id,
			'properties'  => $properties,
			'timestamp'   => gmdate( 'c' ),
		)
	);

	if ( false === $body ) {
		return;
	}

	$host = \defined( 'WCPOS_POSTHOG_HOST' ) ? (string) WCPOS_POSTHOG_HOST : 'https://ph.wcpos.com';
	$host = (string) apply_filters( 'woocommerce_pos_posthog_host', $host );

	wp_remote_post(
		untrailingslashit( $host ) . '/capture/',
		array(
			'blocking' => false,
			'timeout'  => 2.0,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => $body,
		)
	);
}

/**
 * Remove WCPOS data for the current site.
 *
 * User meta and the object-cache flush are handled once at the network
 * level (see the bottom of this file), not here.
 *
 * @param bool|null $remove_all Also remove user-authored configuration
 *                              (settings, templates, receipt sequence, role).
 *                              Defaults to woocommerce_pos_uninstall_remove_all_data().
 */
function woocommerce_pos_uninstall_site( ?bool $remove_all = null ): void {
	global $wpdb;

	// Read the opt-in BEFORE the option sweep below deletes it.
	if ( null === $remove_all ) {
		$remove_all = woocommerce_pos_uninstall_remove_all_data();
	}

	// 1. Clear scheduled events (all events per hook, regardless of args).
	foreach ( woocommerce_pos_uninstall_cron_hooks() as $hook ) {
		wp_unschedule_hook( $hook );
	}

	// 2. Drop plugin tables. All are derived from WooCommerce data and are
	// rebuilt on reinstall.
	foreach ( woocommerce_pos_uninstall_table_suffixes() as $suffix ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Known plugin table names; uninstall context.
	}

	// 3. Delete plugin post types: the print-job queue always; receipt
	// templates (including revisions and taxonomies) only on a full wipe.
	woocommerce_pos_uninstall_post_type( 'wcpos_print_job' );
	if ( $remove_all ) {
		woocommerce_pos_uninstall_post_type( 'wcpos_template' );
		woocommerce_pos_uninstall_taxonomy( 'wcpos_template_type' );
		woocommerce_pos_uninstall_taxonomy( 'wcpos_template_category' );
	}

	// 4. Roles and capabilities. Kept by default: removing the cashier role
	// would strand every user assigned to it, so that only happens on a full
	// wipe. (Deactivation already strips the two access caps.)
	if ( $remove_all && \function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( false !== strpos( $cap, 'woocommerce_pos' ) || false !== strpos( $cap, 'wcpos_store' ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		remove_role( 'cashier' );
	}

	// Pro bundles the free core, so artifacts shared with it (translations,
	// their cache transients, WooCommerce logs) stay while Pro is installed.
	$pro_installed = woocommerce_pos_uninstall_pro_installed();

	// 5. Delete plugin options and transients. Both plugin prefixes are
	// swept; WCPOS Pro's data is ALWAYS excluded (it belongs to a different
	// plugin), and user-authored configuration is excluded unless $remove_all.
	$patterns = array(
		$wpdb->esc_like( 'wcpos_' ) . '%',
		$wpdb->esc_like( 'woocommerce_pos_' ) . '%',
		$wpdb->esc_like( '_transient_wcpos_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wcpos_' ) . '%',
		$wpdb->esc_like( '_transient_woocommerce_pos_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woocommerce_pos_' ) . '%',
	);

	$preserved = array(
		// WCPOS Pro's data — never ours to delete.
		$wpdb->esc_like( 'woocommerce_pos_pro_' ) . '%',
		$wpdb->esc_like( 'wcpos_pro_' ) . '%',
		$wpdb->esc_like( 'wcpos_stores_migrated' ),
		$wpdb->esc_like( '_transient_woocommerce_pos_pro_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woocommerce_pos_pro_' ) . '%',
		$wpdb->esc_like( '_transient_wcpos_i18n_woocommerce-pos-pro_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wcpos_i18n_woocommerce-pos-pro_' ) . '%',
		// WooCommerce core POS settings — owned by WooCommerce 10.5+.
		$wpdb->esc_like( 'woocommerce_pos_store_name' ),
		$wpdb->esc_like( 'woocommerce_pos_store_phone' ),
		$wpdb->esc_like( 'woocommerce_pos_store_email' ),
		$wpdb->esc_like( 'woocommerce_pos_refund_returns_policy' ),
	);
	if ( $pro_installed ) {
		// Version cache for the shared `woocommerce-pos` translations kept below.
		$preserved[] = $wpdb->esc_like( '_transient_wcpos_i18n_woocommerce-pos_' ) . '%';
		$preserved[] = $wpdb->esc_like( '_transient_timeout_wcpos_i18n_woocommerce-pos_' ) . '%';
	}
	if ( ! $remove_all ) {
		$preserved = array_merge(
			$preserved,
			array(
				$wpdb->esc_like( 'woocommerce_pos_settings_' ) . '%',
				$wpdb->esc_like( 'wcpos_active_template_' ) . '%',
				$wpdb->esc_like( 'wcpos_template_order_' ) . '%',
				$wpdb->esc_like( 'wcpos_disabled_virtual_templates_' ) . '%',
				$wpdb->esc_like( 'wcpos_receipt_sequence_counter' ),
			)
		);
	}

	$where  = '(' . implode( ' OR ', array_fill( 0, \count( $patterns ), 'option_name LIKE %s' ) ) . ')';
	$where .= str_repeat( ' AND option_name NOT LIKE %s', \count( $preserved ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is placeholders only, prepared here; prefix-scoped uninstall sweep.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE {$where}", array_merge( $patterns, $preserved ) ) );

	// 6. Plugin-owned files: downloaded translations, template render cache,
	// dompdf scratch, and this plugin's unshared WooCommerce logs.
	$log_table = $wpdb->prefix . 'woocommerce_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's known per-site log table; uninstall context.
	$log_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log_table ) ) );
	if ( ! $pro_installed && $log_table === $log_table_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removing exact-source operational logs at uninstall.
		$wpdb->delete( $log_table, array( 'source' => 'woocommerce-pos' ), array( '%s' ) );
	}

	// The custom i18n service downloads only .l10n.php files; WordPress owns
	// any core-managed .mo and hashed .json artifacts in this directory.
	// The `woocommerce-pos` text domain is shared with Pro's bundled core, so
	// its translations stay in place while Pro remains installed.
	if ( ! $pro_installed ) {
		$language_files = glob( trailingslashit( WP_LANG_DIR ) . 'plugins/woocommerce-pos-*.l10n.php' );
		foreach ( is_array( $language_files ) ? $language_files : array() as $language_file ) {
			if ( 1 !== preg_match( '/^woocommerce-pos-[a-z]{2,3}(?:_[A-Za-z0-9]+)*(?:@[A-Za-z0-9]+)?\.l10n\.php$/', basename( $language_file ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- WP_Filesystem is not initialised during uninstall.
			unlink( $language_file );
		}
	}

	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		// The fallback language directory also holds Pro's own downloads.
		if ( ! $pro_installed ) {
			woocommerce_pos_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'wcpos-languages' );
		}
		woocommerce_pos_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'wcpos-templates' );

		if ( ! $pro_installed ) {
			$log_files = glob( trailingslashit( $uploads['basedir'] ) . 'wc-logs/woocommerce-pos-*.log' );
			foreach ( \is_array( $log_files ) ? $log_files : array() as $log_file ) {
				if ( 1 !== preg_match( '/^woocommerce-pos-\d{4}-\d{2}-\d{2}-/', basename( $log_file ) ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- WP_Filesystem is not initialised during uninstall.
				unlink( $log_file );
			}
		}
	}
	woocommerce_pos_uninstall_rmdir( rtrim( get_temp_dir(), '/\\' ) . '/wcpos-dompdf' );
}

// Run the sweep only when WordPress is actually uninstalling the plugin.
if ( \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	// Report churn ONCE, before anything is deleted: the report needs the
	// consent setting, the site UUID and the user meta that the sweep removes,
	// and firing it per site would put a network request in front of every blog
	// of a large multisite uninstall.
	woocommerce_pos_uninstall_report();

	if ( \function_exists( 'is_multisite' ) && is_multisite() ) {
		// number => 0 removes WP_Site_Query's default 100-site cap.
		$woocommerce_pos_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $woocommerce_pos_sites as $woocommerce_pos_site_id ) {
			switch_to_blog( (int) $woocommerce_pos_site_id );
			woocommerce_pos_uninstall_site();
			restore_current_blog();
		}
	} else {
		woocommerce_pos_uninstall_site();
	}

	// Network-wide surfaces, once: user meta is a shared table, and the
	// object-cache flush is global.
	woocommerce_pos_uninstall_user_meta();
	wp_cache_flush();
}
