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
 * Fails any test in which a file under `includes/` saves a post, a WooCommerce object or
 * an object's terms, or deletes or trashes a post, while a WordPress or WooCommerce save,
 * status-transition, stock or checkout hook is running.
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
		// wp_trash_post() fire around the write, and the term write they can trigger.
		'save_post',
		'wp_insert_post',
		'wp_after_insert_post',
		'post_updated',
		'pre_post_update',
		'edit_post',
		'transition_post_status',
		'wp_insert_post_data',
		'content_save_pre',
		'delete_post',
		'before_delete_post',
		'wp_trash_post',
		'set_object_terms',

		// WooCommerce object saves driven by the product edit screen.
		'woocommerce_process_product_meta',
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
	);

	/**
	 * Hook families on the re-entry list, as (prefix, suffix) pairs: a hook matches when it
	 * starts with the prefix and ends with the suffix ('' = any).
	 */
	const REENTRY_PATTERNS = array(
		// WordPress post save stack: the per-type save action.
		array( 'save_post_', '' ),

		// WooCommerce object saves: WC_Data::save() and the data stores' create/update events.
		array( 'woocommerce_before_', '_object_save' ),
		array( 'woocommerce_after_', '_object_save' ),
		array( 'woocommerce_new_', '' ),
		array( 'woocommerce_update_', '' ),
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
	 * Per-hook-name answer to "is this on the re-entry list", so the stack test is a
	 * lookup after the first sighting of each name.
	 *
	 * @var array<string, bool>
	 */
	private static $is_reentry_hook = array();

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
		add_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 1, 4 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'on_pre_delete_post' ), 1, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 1, 1 );
	}

	/**
	 * Police a different directory. Returns the previous one so a self-test can
	 * point the guard at a fixture and restore `includes/` afterwards.
	 *
	 * @param string $dir Directory to police.
	 *
	 * @return string Previously watched directory.
	 */
	public static function watch_directory( string $dir ): string {
		$previous          = self::$watched_dir;
		$resolved          = realpath( $dir );
		self::$watched_dir = rtrim( false === $resolved ? $dir : $resolved, '/' ) . '/';
		return $previous;
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
	 * `set_object_terms` action: fires inside wp_set_object_terms().
	 *
	 * @param int    $object_id Object id.
	 * @param array  $terms     Terms as passed.
	 * @param array  $tt_ids    Term taxonomy ids.
	 * @param string $taxonomy  Taxonomy.
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		$type = get_post_type( (int) $object_id );
		self::check( 'saved', ( $type ? $type : 'object' ) . " '" . $taxonomy . "' terms of", (int) $object_id, false );
	}

	/**
	 * `pre_delete_post` filter: fires inside wp_delete_post().
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
	 * frame is the caller of `wp_update_post()` / `wp_insert_post()` / `wp_set_object_terms()`
	 * and friends. A `WC_Data::save()` fires its hook from WooCommerce's own file, so for
	 * those the walk first steps to the `save()` frame (the last of a `parent::save()`
	 * chain), whose file and line are the caller of `$object->save()`. A caller inside
	 * WooCommerce, another plugin or `tests/` is not ours to police and yields null.
	 *
	 * @param bool $wc_data_save Whether the save came through WC_Data::save().
	 *
	 * @return null|array{file:string,line:int,function:string}
	 */
	private static function plugin_caller( bool $wc_data_save ): ?array {
		$wp_includes = ABSPATH . WPINC . '/';
		$frames      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only tripwire.

		if ( $wc_data_save ) {
			$frames = self::from_wc_data_save_frame( $frames );
		}

		foreach ( $frames as $frame ) {
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}
			$file = $frame['file'];
			if ( __FILE__ === $file || 0 === strpos( $file, $wp_includes ) ) {
				continue;
			}
			if ( 0 === strpos( $file, self::$watched_dir ) ) {
				return array(
					'file'     => substr( $file, \strlen( \dirname( self::$watched_dir ) ) + 1 ),
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
