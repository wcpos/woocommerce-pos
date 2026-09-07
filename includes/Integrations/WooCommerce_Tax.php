<?php
/**
 * WooCommerce Tax (automated taxes) integration.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Integrations;

use WC_Abstract_Order;
use WP_REST_Request;

/**
 * Keep WooCommerce Tax from restoring stale tax lines onto open POS orders.
 *
 * WooCommerce Tax (wp.org slug `woocommerce-services`, formerly WooCommerce
 * Shipping & Tax) 3.6.8 and later snapshots an order's tax lines on
 * `woocommerce_order_before_calculate_taxes` and writes them back on
 * `woocommerce_order_after_calculate_totals`, rebasing the order total on the
 * old tax. Its premise is that a placed order's tax is a record of what was
 * charged. An open till order is not: every WCPOS save of a `pos-open` order can
 * change the line items, and WooCommerce's REST controller recalculates totals
 * for them. With the snapshot restored, the server answers with the previous
 * save's tax while the POS has computed tax for the current lines, and the
 * client shows the totals-disagree banner. See
 * https://github.com/wcpos/woocommerce-pos/issues/1896.
 *
 * The plugin has no filter and no status gate, and its integration object is
 * held privately by its loader, so its callbacks are located on the hooks
 * themselves. When a WCPOS request recalculates an order that is open, or that
 * the request is putting back into an open status, both of the plugin's
 * callbacks are unhooked for that one recalculation and hooked again as soon as
 * it finishes. Paid orders and non-POS requests keep the plugin's behaviour.
 */
class WooCommerce_Tax {
	/**
	 * The plugin's integration class.
	 */
	const TAXJAR_CLASS = 'WC_Connect_TaxJar_Integration';

	/**
	 * Hook the plugin snapshots on.
	 */
	const BEFORE_HOOK = 'woocommerce_order_before_calculate_taxes';

	/**
	 * Hook the plugin restores on, and the earliest point its callbacks can
	 * safely be hooked again.
	 */
	const AFTER_HOOK = 'woocommerce_order_after_calculate_totals';

	/**
	 * The plugin's callbacks, by hook. Both are suspended together so a snapshot
	 * taken by the first can never be written back by the second.
	 *
	 * @var array<string, string>
	 */
	const PLUGIN_CALLBACKS = array(
		self::BEFORE_HOOK => 'preserve_order_taxes_on_recalculation',
		self::AFTER_HOOK  => 'restore_order_taxes_after_recalculation',
	);

	/**
	 * Statuses whose tax is not yet a record of what was charged.
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array( 'pending', 'pos-open', 'pos-partial' );

	/**
	 * Plugin callbacks removed for the recalculation in progress.
	 *
	 * @var array<int, array{hook: string, callback: array, priority: int, accepted_args: int}>
	 */
	private $suspended = array();

	/**
	 * The order a WCPOS REST write is about to save, and the status it asked for.
	 *
	 * WooCommerce's REST controller recalculates totals before it applies the
	 * requested status, so a request that reopens a paid order and changes its
	 * lines recalculates while the persisted status is still paid.
	 *
	 * @var array{order: WC_Abstract_Order, status: string}|null
	 */
	private $requested = null;

	/**
	 * Constructor.
	 *
	 * Priority 9 on both order hooks: before the plugin's callbacks at 10.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'note_requested_status' ), 10, 2 );
		add_action( self::BEFORE_HOOK, array( $this, 'suspend_tax_preservation' ), 9, 2 );
		add_action( self::AFTER_HOOK, array( $this, 'resume_tax_preservation' ), 9 );
	}

	/**
	 * Remember the status a REST write asked for, for the recalculation it triggers.
	 *
	 * @param mixed                $order   The order about to be saved.
	 * @param WP_REST_Request|null $request The request.
	 *
	 * @return mixed The order, unchanged.
	 */
	public function note_requested_status( $order, $request = null ) {
		$this->requested = null;
		if ( $order instanceof WC_Abstract_Order && $request instanceof WP_REST_Request ) {
			$this->requested = array(
				'order'  => $order,
				'status' => (string) $request->get_param( 'status' ),
			);
		}

		return $order;
	}

	/**
	 * Unhook the plugin's callbacks for an open POS order.
	 *
	 * @param array                  $args  Args passed to calculate_taxes(). Unused.
	 * @param WC_Abstract_Order|null $order The order being recalculated.
	 */
	public function suspend_tax_preservation( $args = array(), $order = null ): void {
		// A recalculation that never reached AFTER_HOOK (a direct calculate_taxes()
		// call) leaves the callbacks suspended. Hook them back before deciding
		// about this order, so a suspension never outlives one recalculation.
		$this->restore_suspended();

		if ( ! $order instanceof WC_Abstract_Order || ! $this->is_open_pos_order( $order ) || ! \wcpos_request() ) {
			return;
		}

		foreach ( self::PLUGIN_CALLBACKS as $hook => $method ) {
			foreach ( $this->find_plugin_callbacks( $hook, $method ) as $entry ) {
				remove_action( $hook, $entry['callback'], $entry['priority'] );
				$this->suspended[] = $entry;
			}
		}
	}

	/**
	 * Hook the plugin's callbacks again once the recalculation is done.
	 */
	public function resume_tax_preservation(): void {
		$this->restore_suspended();
	}

	/**
	 * Re-add every suspended callback where it was.
	 */
	private function restore_suspended(): void {
		foreach ( $this->suspended as $entry ) {
			add_action( $entry['hook'], $entry['callback'], $entry['priority'], $entry['accepted_args'] );
		}
		$this->suspended = array();
	}

	/**
	 * Whether the order is, or is being put back to, still being built up at the till.
	 *
	 * @param WC_Abstract_Order $order The order.
	 *
	 * @return bool
	 */
	private function is_open_pos_order( WC_Abstract_Order $order ): bool {
		if ( \in_array( $order->get_status(), self::OPEN_STATUSES, true ) ) {
			return true;
		}

		return null !== $this->requested
			&& $this->requested['order'] === $order
			&& \in_array( $this->requested['status'], self::OPEN_STATUSES, true );
	}

	/**
	 * Locate the plugin's callback for a method on a hook, at whatever priority.
	 *
	 * @param string $hook   The hook.
	 * @param string $method The plugin method name.
	 *
	 * @return array<int, array{hook: string, callback: array, priority: int, accepted_args: int}>
	 */
	private function find_plugin_callbacks( string $hook, string $method ): array {
		global $wp_filter;
		$found = array();
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $found;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( \is_array( $callback )
					&& isset( $callback[0], $callback[1] )
					&& \is_object( $callback[0] )
					&& is_a( $callback[0], self::TAXJAR_CLASS )
					&& $method === $callback[1] ) {
					$found[] = array(
						'hook'          => $hook,
						'callback'      => $callback,
						'priority'      => (int) $priority,
						'accepted_args' => (int) $entry['accepted_args'],
					);
				}
			}
		}

		return $found;
	}
}
