<?php
/**
 * Test-suite tripwire: plugin code must not save from inside a save or transition hook.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a PHPUnit failure read in a terminal, never rendered.

use LogicException;
use WC_Data;

/**
 * Fails any test in which a file under `includes/` saves a post or a WooCommerce object,
 * or deletes or trashes a post, while a WordPress or WooCommerce save, status-transition,
 * stock or checkout hook is running.
 *
 * Term assignment (`wp_set_object_terms()`) is out of scope: setting terms on the post
 * being saved from its own `save_post_{type}` handler is WordPress's meta-box pattern, and
 * it does not re-run the post save stack.
 *
 * Why this exists: #2371. WCPOS listened on `woocommerce_product_set_stock` and called
 * `wp_update_post()` on the product. That hook fires inside WooCommerce's stock reduction,
 * which runs inside `WC_Order::update_status()` during a web checkout. The nested post
 * save re-ran every `save_post` listener and the kses filters for a guest; a third-party
 * listener threw, `WC_Order::status_transition()` swallowed the exception, the
 * `_reduced_stock` stamps were never written, and the next status change reduced stock
 * again. Nothing in the suite asserted that such a save must not happen.
 *
 * A trip is reported twice, because WooCommerce catches exceptions on exactly the paths
 * this guard watches (`status_transition()`, `payment_complete()`, `WC_Abstract_Order::save()`)
 * and turns them into an order note:
 *   - a `LogicException`, which fails the test when nothing swallows it;
 *   - a `_doing_it_wrong()` notice under the name `Save_Reentry_Guard`, which
 *     `WP_UnitTestCase` turns into a failure after the test even when WooCommerce caught
 *     the exception.
 *
 * Scope mirrors `Order_Postmeta_Guard`: only saves whose immediate caller is a file under
 * the plugin's `includes/` directory. WooCommerce core, other plugins and `tests/` saving
 * inside these hooks pass. Plugin code saving at a request entry point (`init`,
 * `rest_api_init`, our own REST route callbacks, `admin_post_*`, `wp_ajax_*`, a WP-CLI
 * command, a cron action) passes because none of those is on the re-entry list.
 *
 * There is no exemption mechanism, on purpose. Rule text:
 * `.ai/rules/no-saves-inside-save-hooks.mdc`.
 */
class Save_Reentry_Guard {
	/**
	 * Name passed to `_doing_it_wrong()` so a swallowed trip still fails the test, and so
	 * the guard's own self-test can declare it with `setExpectedIncorrectUsage()`.
	 */
	const NOTICE = 'Save_Reentry_Guard';

	/**
	 * WooCommerce object types whose `WC_Data::save()` is watched, via
	 * `woocommerce_before_{type}_object_save`.
	 */
	const WC_DATA_TYPES = array( 'product', 'product_variation', 'order', 'order_refund', 'coupon', 'customer' );

	/**
	 * Hooks that, when running, make a save from plugin code a re-entrant save. Exact names.
	 */
	const REENTRY_HOOKS = array(
		// WordPress post save stack: everything wp_insert_post(), wp_delete_post() and
		// wp_trash_post() fire around the write.
		'save_post',
		'wp_insert_post',
		'wp_after_insert_post',
		'post_updated',
		'pre_post_update',
		'edit_post',
		'transition_post_status',
		'wp_insert_post_data',
		'content_save_pre',
		'pre_delete_post',
		'pre_delete_attachment',
		'delete_post',
		'before_delete_post',
		'wp_trash_post',

		// Product edit screen staging hooks: they hand over the object BEFORE WooCommerce's
		// own save, so a save here is a double save. The meta-box persistence hooks
		// (woocommerce_process_product_meta, woocommerce_process_shop_order_meta) are not
		// listed: WooCommerce's own meta boxes save the object inside them, so a handler
		// saving the data it owns there follows WooCommerce's contract.
		'woocommerce_admin_process_product_object',
		'woocommerce_admin_process_variation_object',

		// Order life-cycle: payment, stock, refunds and checkout.
		'woocommerce_payment_complete',
		'woocommerce_pre_payment_complete',
		'woocommerce_reduce_order_stock',
		'woocommerce_restore_order_stock',
		'woocommerce_reduce_order_item_stock',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_before_set_stock',
		'woocommerce_variation_before_set_stock',
		'woocommerce_order_refunded',
		'woocommerce_refund_created',
		'woocommerce_checkout_order_processed',
		'woocommerce_checkout_create_order',
		'woocommerce_checkout_update_order_meta',
		'woocommerce_checkout_order_created',

		// Checkout order creation: the item and customer writes WC_Checkout makes while it
		// builds the order. Listed one by one; the non-write checkout filters
		// (woocommerce_checkout_fields and friends) stay off.
		'woocommerce_checkout_create_order_line_item',
		'woocommerce_checkout_create_order_fee_item',
		'woocommerce_checkout_create_order_shipping_item',
		'woocommerce_checkout_create_order_tax_item',
		'woocommerce_checkout_create_order_coupon_item',
		'woocommerce_checkout_update_customer',

		// WooCommerce data store create/update events, per object type. Exact names, not a
		// woocommerce_new_ / woocommerce_update_ prefix: that would also catch settings saves
		// (woocommerce_update_options_{tab}, woocommerce_update_option), which run at a
		// request entry point.
		'woocommerce_new_product',
		'woocommerce_update_product',
		'woocommerce_new_product_variation',
		'woocommerce_update_product_variation',
		'woocommerce_new_order',
		'woocommerce_update_order',
		'woocommerce_new_order_refund',
		'woocommerce_update_order_refund',
		'woocommerce_new_order_item',
		'woocommerce_update_order_item',
		'woocommerce_new_coupon',
		'woocommerce_update_coupon',
		'woocommerce_new_customer',
		'woocommerce_update_customer',
		'woocommerce_new_webhook',
		'woocommerce_update_webhook',
		'woocommerce_new_payment_token',
		'woocommerce_update_payment_token',
		'woocommerce_new_shipping_zone',
		'woocommerce_update_shipping_zone',
	);

	/**
	 * Hook families on the re-entry list, as (prefix, suffix) pairs: a hook matches when it
	 * starts with the prefix and ends with the suffix ('' = any).
	 */
	const REENTRY_PATTERNS = array(
		// WordPress post save stack: the per-type save action.
		array( 'save_post_', '' ),

		// WooCommerce object saves: WC_Data::save() and the data stores' updated-props event.
		array( 'woocommerce_before_', '_object_save' ),
		array( 'woocommerce_after_', '_object_save' ),
		array( 'woocommerce_', '_object_updated_props' ),

		// Order life-cycle: every status pair, woocommerce_order_status_changed, and the
		// payment-complete status actions.
		array( 'woocommerce_order_status_', '' ),
		array( 'woocommerce_payment_complete_order_status_', '' ),

		// REST writes by WooCommerce and WordPress core controllers.
		array( 'woocommerce_rest_insert_', '' ),
		array( 'woocommerce_rest_pre_insert_', '' ),
		array( 'rest_insert_', '' ),
		array( 'rest_after_insert_', '' ),
	);

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
	 * Global functions that dispatch a hook. In a backtrace, a frame for one of these
	 * marks a hook firing; its file is the code that fired it.
	 */
	const DISPATCH_FUNCTIONS = array( 'apply_filters', 'apply_filters_ref_array', 'do_action', 'do_action_ref_array' );

	/**
	 * Per-hook-name answer to "is this on the re-entry list", so the stack test is a
	 * lookup after the first sighting of each name.
	 *
	 * @var array<string, bool>
	 */
	private static $is_reentry_hook = array();

	/**
	 * Directories under a watched one that the guard does NOT police, normalized like
	 * `$watched_dir`. Each entry is a ruled, tracked exemption, never a convenience: the
	 * bootstrap names the issue that removes it.
	 *
	 * @var string[]
	 */
	private static $excepted_dirs = array();

	/**
	 * Hook the save entry points. Call once from the bootstrap after WP has loaded;
	 * WP_UnitTestCase snapshots hooks per test, so hooks present at bootstrap survive
	 * every test's hook restore.
	 */
	public static function install(): void {
		self::watch_directory( \dirname( __DIR__, 2 ) . '/includes' );

		// Priority 1 so the tripwire fires before any listener that could act on the write.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'on_insert_post_data' ), 1, 2 );
		foreach ( self::WC_DATA_TYPES as $type ) {
			add_action( 'woocommerce_before_' . $type . '_object_save', array( __CLASS__, 'on_wc_data_save' ), 1, 1 );
		}
		add_filter( 'pre_delete_post', array( __CLASS__, 'on_pre_delete_post' ), 1, 2 );
		// wp_delete_post() hands attachments to wp_delete_attachment(), which fires this instead.
		add_filter( 'pre_delete_attachment', array( __CLASS__, 'on_pre_delete_post' ), 1, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 1, 1 );
	}

	/**
	 * The per-test boundary, called before every test by `Save_Reentry_Guard_Reset`, the
	 * PHPUnit extension Free's and Pro's suite configs register. The guard keeps no per-test
	 * state any more: `live_stack()` reads which hooks are running from the call stack.
	 */
	public static function reset(): void {
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
	 * Stop policing one directory under a watched one. A save from a file under it is
	 * neither reported nor thrown. Survives `watch_directory()` (a self-test that re-points
	 * the guard at a fixture never trips on an excepted plugin directory anyway).
	 *
	 * @param string $dir Directory to leave alone.
	 */
	public static function except( string $dir ): void {
		$dir = self::normalize_dir( $dir );
		if ( ! \in_array( $dir, self::$excepted_dirs, true ) ) {
			self::$excepted_dirs[] = $dir;
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
		foreach ( self::$excepted_dirs as $dir ) {
			if ( 0 === strpos( $file, $dir ) ) {
				return null;
			}
		}
		$base = empty( self::$extra_dirs ) ? \dirname( $match ) : \dirname( $match, 2 );
		return substr( $file, \strlen( $base ) + 1 );
	}

	/**
	 * `wp_insert_post_data` filter: fires inside wp_insert_post(), so wp_update_post() too.
	 *
	 * @param array $data    Sanitized post data about to be written.
	 * @param array $postarr Post data as passed to wp_insert_post().
	 *
	 * @return array
	 */
	public static function on_insert_post_data( $data, $postarr ) {
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$type    = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
		self::check( 'saved', $type, $post_id, false );
		return $data;
	}

	/**
	 * `woocommerce_before_{type}_object_save` action: the WC_Data::save() entry point.
	 *
	 * @param mixed $data_object The object being saved.
	 */
	public static function on_wc_data_save( $data_object ): void {
		if ( ! $data_object instanceof WC_Data ) {
			return;
		}
		$hook = (string) current_filter();
		$type = substr( $hook, \strlen( 'woocommerce_before_' ), - \strlen( '_object_save' ) );
		self::check( 'saved', $type, (int) $data_object->get_id(), true );
	}

	/**
	 * `pre_delete_post` filter, fired inside wp_delete_post(), and `pre_delete_attachment`,
	 * fired inside wp_delete_attachment() (where wp_delete_post() sends attachments).
	 *
	 * @param mixed    $delete Short-circuit value.
	 * @param \WP_Post $post   Post being deleted.
	 *
	 * @return mixed
	 */
	public static function on_pre_delete_post( $delete, $post ) {
		if ( $post instanceof \WP_Post ) {
			self::check( 'deleted', $post->post_type, (int) $post->ID, false );
		}
		return $delete;
	}

	/**
	 * `wp_trash_post` action: fires inside wp_trash_post().
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_trash_post( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		self::check( 'trashed', $type ? $type : 'post', (int) $post_id, false );
	}

	/**
	 * Throw when plugin code performs this save while a re-entry hook is running.
	 *
	 * The hook stack is tested first because it is a plain array walk; the backtrace is
	 * taken only when a listed hook is running.
	 *
	 * @param string $verb         'saved', 'deleted' or 'trashed'.
	 * @param string $object_type  Post type or WooCommerce object type.
	 * @param int    $object_id    Object id (0 for a new object).
	 * @param bool   $wc_data_save Whether the save came through WC_Data::save().
	 *
	 * @throws LogicException When plugin code saved inside a listed hook.
	 */
	private static function check( string $verb, string $object_type, int $object_id, bool $wc_data_save ): void {
		$hook = self::running_reentry_hook();
		if ( null === $hook ) {
			return;
		}

		$caller = self::plugin_caller( $wc_data_save );
		if ( null === $caller ) {
			return;
		}

		$message = \sprintf(
			"%s:%d %s %s %s (%s) inside '%s'. " .
			"A save inside a WooCommerce or WordPress save or transition hook re-runs the whole post-save stack and every other plugin's listeners from inside the original write; #2371 double-reduced stock this way. " .
			'Do the write at the request entry point, or defer it (schedule an action, mark the object for a later save), or record the fact in your own table. ' .
			'See .ai/rules/no-saves-inside-save-hooks.mdc.',
			$caller['file'],
			$caller['line'],
			$verb,
			$object_type,
			$object_id > 0 ? '#' . $object_id : '(new)',
			$caller['function'],
			$hook
		);

		// WooCommerce swallows exceptions on the status, payment and order-save paths; the
		// notice fails the test after it ends even when the exception below is caught.
		_doing_it_wrong( self::NOTICE, $message, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only tripwire, never rendered.

		// A swallowed exception usually aborts the plugin handler, so the test then fails on
		// a downstream assertion before WP_UnitTestCase reports the notice. Say it here too.
		fwrite( STDERR, "\n[Save_Reentry_Guard] " . $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- test-only tripwire.

		throw new LogicException( $message );
	}

	/**
	 * The innermost running hook that is on the re-entry list, ignoring the hook the
	 * guard itself is running on (the last entry of the stack).
	 *
	 * @return null|string
	 */
	private static function running_reentry_hook(): ?string {
		$stack = $GLOBALS['wp_current_filter'] ?? array();
		if ( null !== self::innermost_reentry_hook( $stack ) ) {
			$stack = self::live_stack( $stack );
		}
		return self::innermost_reentry_hook( $stack );
	}

	/**
	 * The innermost hook on the list in a stack, ignoring its last entry (the guard's own hook).
	 *
	 * @param string[] $stack Hook stack, outermost first.
	 *
	 * @return null|string
	 */
	private static function innermost_reentry_hook( array $stack ): ?string {
		for ( $i = \count( $stack ) - 2; $i >= 0; $i-- ) {
			$name = (string) $stack[ $i ];
			if ( ! isset( self::$is_reentry_hook[ $name ] ) ) {
				self::$is_reentry_hook[ $name ] = self::is_reentry_hook( $name );
			}
			if ( self::$is_reentry_hook[ $name ] ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * `$wp_current_filter` without the hooks that are no longer running.
	 *
	 * WordPress pops a hook from `$wp_current_filter` only when its callbacks return. When a
	 * listener throws and WooCommerce catches the exception (status transitions, payment
	 * complete, order saves: a guard trip, a keeper refusal, any third-party listener), every
	 * hook it unwound stays on the stack until the test's hook restore, and the next plugin
	 * save in that test would be blamed on them. A hook is running only while its dispatch
	 * (`do_action()`, `apply_filters()` and their `_ref_array` forms) is on the call stack, so
	 * each stack entry is kept only when the next live dispatch, outermost first, names it.
	 *
	 * @param string[] $stack `$wp_current_filter`.
	 *
	 * @return string[]
	 */
	private static function live_stack( array $stack ): array {
		$dispatched = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only tripwire; args carry the hook names.
		foreach ( array_reverse( debug_backtrace( 0 ) ) as $frame ) {
			if ( ! isset( $frame['class'] ) && \in_array( $frame['function'], self::DISPATCH_FUNCTIONS, true ) && isset( $frame['args'][0] ) ) {
				$dispatched[] = (string) $frame['args'][0];
			}
		}
		$live = array();
		foreach ( $stack as $name ) {
			if ( isset( $dispatched[ \count( $live ) ] ) && $dispatched[ \count( $live ) ] === (string) $name ) {
				$live[] = (string) $name;
			}
		}
		return $live;
	}

	/**
	 * Whether a hook name is on the re-entry list.
	 *
	 * @param string $name Hook name.
	 *
	 * @return bool
	 */
	private static function is_reentry_hook( string $name ): bool {
		if ( \in_array( $name, self::REENTRY_HOOKS, true ) ) {
			return true;
		}
		foreach ( self::REENTRY_PATTERNS as $pattern ) {
			list( $prefix, $suffix ) = $pattern;
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( '' === $suffix || ( \strlen( $name ) > \strlen( $prefix ) + \strlen( $suffix ) && substr( $name, - \strlen( $suffix ) ) === $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The first stack frame outside WordPress core, if it is plugin production code.
	 *
	 * The walk is `Order_Postmeta_Guard::plugin_caller()`'s: skip this file and every
	 * `wp-includes` frame, and judge the first frame left. For a post or term save that
	 * frame is the caller of `wp_update_post()` / `wp_insert_post()` / `wp_delete_post()`
	 * and friends. A `WC_Data::save()` fires its hook from WooCommerce's own file, so for
	 * those the walk first steps to the `save()` frame (the last of a `parent::save()`
	 * chain), whose file and line are the caller of `$object->save()`. A caller inside
	 * WooCommerce, another plugin or `tests/` is not ours to police and yields null.
	 *
	 * One addition: if the walk crosses a hook dispatch inside `wp-includes` other than the
	 * one that ran this guard, the save was made by a core hook callback (for example
	 * `wp_save_post_revision()` on `post_updated`, inside a plugin's own `wp_update_post()`),
	 * so its caller is core and the walk yields null. The plugin's outer save is judged by
	 * its own check.
	 *
	 * @param bool $wc_data_save Whether the save came through WC_Data::save().
	 *
	 * @return null|array{file:string,line:int,function:string}
	 */
	private static function plugin_caller( bool $wc_data_save ): ?array {
		$wp_includes = ABSPATH . WPINC . '/';
		$frames      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only tripwire.

		// The guard's own hook dispatch is in the frames unless the walk starts at save().
		$dispatches_left = 1;
		if ( $wc_data_save ) {
			$frames          = self::from_wc_data_save_frame( $frames );
			$dispatches_left = 0;
		}

		foreach ( $frames as $frame ) {
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}
			$file = $frame['file'];
			if ( __FILE__ === $file ) {
				continue;
			}
			if ( 0 === strpos( $file, $wp_includes ) ) {
				if ( ! isset( $frame['class'] ) && \in_array( $frame['function'], self::DISPATCH_FUNCTIONS, true ) && --$dispatches_left < 0 ) {
					return null;
				}
				continue;
			}
			$path = self::watched_path( $file );
			if ( null !== $path ) {
				return array(
					'file'     => $path,
					'line'     => (int) ( $frame['line'] ?? 0 ),
					'function' => ( isset( $frame['class'] ) ? $frame['class'] . '::' : '' ) . $frame['function'] . '()',
				);
			}
			return null;
		}

		return null;
	}

	/**
	 * Drop the frames above the outermost `save()` of a WC_Data `parent::save()` chain.
	 *
	 * @param array $frames Backtrace, innermost first.
	 *
	 * @return array Frames starting at the `save()` call made by the object's caller, or
	 *               none when there is no such frame.
	 */
	private static function from_wc_data_save_frame( array $frames ): array {
		$found = null;
		foreach ( $frames as $i => $frame ) {
			$is_save = 'save' === ( $frame['function'] ?? '' ) && isset( $frame['class'] ) && is_a( $frame['class'], WC_Data::class, true );
			if ( $is_save ) {
				$found = $i;
			} elseif ( null !== $found ) {
				break;
			}
		}
		return null === $found ? array() : \array_slice( $frames, $found );
	}
}
