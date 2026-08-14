<?php
/**
 * Teardown helper for sync observer test classes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Closure;
use ReflectionFunction;

/**
 * Unhooks every callback an observer registered, without naming them.
 *
 * A hand-written `remove_action()` list drifts silently from `register_hooks()`:
 * it keeps passing after the production hook is renamed or re-pointed, and the
 * observer then leaks into every later test in the run. Asking WordPress which
 * callbacks are actually bound to the instance cannot drift.
 */
trait Sync_Observer_Unhook_Trait {
	/**
	 * Remove every hook callback bound to the given observer instances.
	 *
	 * @param array<int, object> $observers Observer objects whose hooks should be removed.
	 */
	protected function remove_observer_callbacks( array $observers ): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( $this->callback_belongs_to_observer( $callback['function'], $observers ) ) {
						remove_filter( $hook_name, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Whether a registered callback is owned by one of the observers.
	 *
	 * Covers the array form (`[ $observer, 'method' ]`) AND closures bound to an
	 * observer — the untrash handlers arm a one-shot closure on
	 * `woocommerce_after_order_object_save`, which would otherwise survive
	 * teardown whenever the restore it waits for never happens.
	 *
	 * @param mixed              $callback  Registered callback.
	 * @param array<int, object> $observers Observer objects being torn down.
	 */
	private function callback_belongs_to_observer( $callback, array $observers ): bool {
		if ( \is_array( $callback ) ) {
			return isset( $callback[0] ) && \in_array( $callback[0], $observers, true );
		}

		if ( $callback instanceof Closure ) {
			$bound = ( new ReflectionFunction( $callback ) )->getClosureThis();

			return null !== $bound && \in_array( $bound, $observers, true );
		}

		return false;
	}
}
