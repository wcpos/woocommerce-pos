<?php
/**
 * Test-suite tripwire: plugin code must not touch order meta through the post-meta API.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a PHPUnit failure read in a terminal, never rendered.

use LogicException;

/**
 * Fails any test in which a file under `includes/` reads or writes an ORDER's meta
 * with `get_post_meta()` / `update_post_meta()` / `add_post_meta()` / `delete_post_meta()`.
 *
 * Why this exists: with HPOS authoritative and compatibility sync off (WooCommerce's
 * default for new stores) order meta lives in `wc_orders_meta`. `get_post_meta()` on an
 * order id then reads the empty placeholder post and returns nothing; `update_post_meta()`
 * writes a row the order object never sees. Both fail silently — the code runs, the
 * suite passes on posts storage, and merchants on HPOS lose the feature. #2098 (tax-ID
 * inference), `Cash::payment_details()` and `Fiscal_Receipt_Service` all shipped that way.
 *
 * Scope is deliberately narrow so every trip is a real finding:
 *   - only ORDER ids (post types from `wc_get_order_types()` plus HPOS's placeholder type);
 *   - only when the IMMEDIATE caller of the post-meta function is a file under the
 *     plugin's `includes/` directory. WooCommerce core reading postmeta for its own CPT
 *     data store, and `$order->get_meta()` reaching postmeta through that data store,
 *     are the correct paths and pass through untouched. Test code may read postmeta on
 *     orders directly (e.g. to assert a row is NOT there).
 *
 * The right call is always on the order object: `$order->get_meta()`,
 * `$order->update_meta_data()` / `delete_meta_data()` followed by `$order->save()`.
 * Rule text: `.ai/rules/hpos-order-meta.mdc`.
 */
class Order_Postmeta_Guard {
	/**
	 * HPOS reserves the order id with a post of this type; it carries no order data.
	 */
	const PLACEHOLDER_TYPE = 'shop_order_placehold';

	/**
	 * Directory whose files are policed, with trailing slash. realpath()'d so it matches
	 * the paths PHP reports in backtraces, which are symlink- and `..`-resolved.
	 *
	 * @var string
	 */
	private static $watched_dir = '';

	/**
	 * Further policed directories added with `also_watch()`, in the same form as
	 * `$watched_dir`. A host plugin's suite (Pro) adds its own `includes/` here.
	 *
	 * @var string[]
	 */
	private static $extra_dirs = array();

	/**
	 * Hook the four post-meta filters. Call once from the bootstrap after WP has loaded;
	 * WP_UnitTestCase snapshots hooks per test, so filters present at bootstrap survive
	 * every test's hook restore.
	 */
	public static function install(): void {
		self::watch_directory( \dirname( __DIR__, 2 ) . '/includes' );

		// Priority 1 so the tripwire fires before any filter that could short-circuit the read/write.
		add_filter( 'get_post_metadata', array( __CLASS__, 'on_get' ), 1, 4 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'on_add' ), 1, 5 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'on_update' ), 1, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'on_delete' ), 1, 5 );
	}

	/**
	 * Police a different directory. Returns the previous one so a self-test can
	 * point the guard at a fixture and restore `includes/` afterwards.
	 *
	 * This replaces the primary directory and also forgets every directory added with
	 * `also_watch()`, so a self-test that re-points the guard and restores it leaves only
	 * the restored directory watched. A suite that watches extra directories adds them
	 * again after any `watch_directory()` call.
	 *
	 * @param string $dir Directory to police.
	 *
	 * @return string Previously watched directory.
	 */
	public static function watch_directory( string $dir ): string {
		$previous          = self::$watched_dir;
		self::$watched_dir = self::normalize_dir( $dir );
		self::$extra_dirs  = array();
		return $previous;
	}

	/**
	 * Police one more directory as well as the primary one, e.g. a second plugin's
	 * `includes/` when that plugin's suite installs this guard. While any extra directory
	 * is watched, reported paths carry the plugin directory name
	 * (`woocommerce-pos-pro/includes/Foo.php:12`) so the two plugins can be told apart.
	 *
	 * @param string $dir Directory to police.
	 */
	public static function also_watch( string $dir ): void {
		$dir = self::normalize_dir( $dir );
		if ( $dir !== self::$watched_dir && ! \in_array( $dir, self::$extra_dirs, true ) ) {
			self::$extra_dirs[] = $dir;
		}
	}

	/**
	 * A directory as the backtrace reports it: realpath()'d, with one trailing slash.
	 *
	 * @param string $dir Directory.
	 *
	 * @return string
	 */
	private static function normalize_dir( string $dir ): string {
		$resolved = realpath( $dir );
		return rtrim( false === $resolved ? $dir : $resolved, '/' ) . '/';
	}

	/**
	 * The path to report for a file under a watched directory, or null when it is under none.
	 *
	 * The deepest matching directory wins. The path is relative to that directory's parent
	 * (`includes/Foo.php`), or to its grandparent while extra directories are watched
	 * (`woocommerce-pos/includes/Foo.php`).
	 *
	 * @param string $file Absolute file path from a backtrace frame.
	 *
	 * @return null|string
	 */
	private static function watched_path( string $file ): ?string {
		$match = '';
		foreach ( array_merge( array( self::$watched_dir ), self::$extra_dirs ) as $dir ) {
			if ( '' !== $dir && \strlen( $dir ) > \strlen( $match ) && 0 === strpos( $file, $dir ) ) {
				$match = $dir;
			}
		}
		if ( '' === $match ) {
			return null;
		}
		$base = empty( self::$extra_dirs ) ? \dirname( $match ) : \dirname( $match, 2 );
		return substr( $file, \strlen( $base ) + 1 );
	}

	/**
	 * `get_post_metadata` filter.
	 *
	 * @param mixed  $value     Short-circuit value (null = no short-circuit).
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key ('' for "all keys").
	 * @param bool   $single    Whether a single value was requested.
	 *
	 * @return mixed
	 */
	public static function on_get( $value, $object_id, $meta_key, $single ) {
		self::check( 'get_post_meta', (int) $object_id, (string) $meta_key );
		return $value;
	}

	/**
	 * `add_post_metadata` filter.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param bool   $unique     Whether the key must be unique.
	 *
	 * @return mixed
	 */
	public static function on_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		self::check( 'add_post_meta', (int) $object_id, (string) $meta_key );
		return $check;
	}

	/**
	 * `update_post_metadata` filter.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param mixed  $prev_value Previous value to match.
	 *
	 * @return mixed
	 */
	public static function on_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		self::check( 'update_post_meta', (int) $object_id, (string) $meta_key );
		return $check;
	}

	/**
	 * `delete_post_metadata` filter.
	 *
	 * @param mixed  $delete     Short-circuit value.
	 * @param int    $object_id  Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value to match.
	 * @param bool   $delete_all Whether to delete for all objects.
	 *
	 * @return mixed
	 */
	public static function on_delete( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
		self::check( 'delete_post_meta', (int) $object_id, (string) $meta_key );
		return $delete;
	}

	/**
	 * Throw when plugin code addresses an order through the post-meta API.
	 *
	 * @param string $api       The post-meta function family being used.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 *
	 * @throws LogicException When the immediate caller is plugin code and the id is an order.
	 */
	private static function check( string $api, int $object_id, string $meta_key ): void {
		if ( $object_id <= 0 || ! self::is_order( $object_id ) ) {
			return;
		}

		$caller = self::plugin_caller();
		if ( null === $caller ) {
			return;
		}

		throw new LogicException(
			\sprintf(
				"%s:%d used %s() on order #%d (key '%s'). Order meta is not post meta: on HPOS this reads or writes the empty placeholder post. " .
				'Load the order and use $order->get_meta() / update_meta_data() + save(). See .ai/rules/hpos-order-meta.mdc.',
				$caller['file'],
				$caller['line'],
				$api,
				$object_id,
				$meta_key
			)
		);
	}

	/**
	 * Whether a post id belongs to an order on either storage backend.
	 *
	 * @param int $object_id Post id.
	 *
	 * @return bool
	 */
	private static function is_order( int $object_id ): bool {
		$type = get_post_type( $object_id );
		if ( ! \is_string( $type ) || '' === $type ) {
			return false;
		}
		if ( self::PLACEHOLDER_TYPE === $type ) {
			return true;
		}
		return \function_exists( 'wc_get_order_types' ) && \in_array( $type, wc_get_order_types(), true );
	}

	/**
	 * The first stack frame outside WordPress core, if it is plugin production code.
	 *
	 * Frames run: this guard → apply_filters → get_metadata_raw / add|update|delete_metadata →
	 * get|add|update|delete_post_meta → the caller. Walking past every `wp-includes` frame
	 * (and this file) lands on the caller whatever WP wrapper it went through, e.g.
	 * `get_post_custom()`. A caller inside WooCommerce, another plugin or `tests/` is
	 * not ours to police and yields null.
	 *
	 * @return null|array{file:string,line:int}
	 */
	private static function plugin_caller(): ?array {
		$wp_includes = ABSPATH . WPINC . '/';

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only tripwire.
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}
			$file = $frame['file'];
			if ( __FILE__ === $file || 0 === strpos( $file, $wp_includes ) ) {
				continue;
			}
			$path = self::watched_path( $file );
			if ( null !== $path ) {
				return array(
					'file' => $path,
					'line' => (int) ( $frame['line'] ?? 0 ),
				);
			}
			return null;
		}

		return null;
	}
}
