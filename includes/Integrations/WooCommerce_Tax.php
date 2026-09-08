<?php
/**
 * WooCommerce Tax (automated taxes) integration.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Integrations;

use WC_Abstract_Order;
use WC_Order;
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
 * The plugin primes TaxJar rates only from the cart and wp-admin, while POS
 * writes arrive through REST. Prime rates for the current order before WooCommerce
 * matches them, so POS tax does not depend on an earlier checkout at that address.
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
	 * Hook the plugin restores on, and where its callbacks are hooked again.
	 */
	const AFTER_HOOK = 'woocommerce_order_after_calculate_totals';

	/**
	 * Priority the callbacks are hooked again at on AFTER_HOOK.
	 *
	 * WP_Hook runs a callback added during dispatch if its priority has not been
	 * passed yet. Re-adding the plugin's restore at 10 from priority 9 would run
	 * it in the same pass, and a snapshot left over from an earlier bare
	 * calculate_taxes() on the same order would be written over the fresh tax.
	 * Re-adding from the last priority keeps it for the next recalculation.
	 */
	const RESUME_PRIORITY = PHP_INT_MAX;

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
	 * Suspend at priority 9, before the plugin's snapshot callback at 10; resume
	 * from the last priority so nothing re-added runs in the same pass.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'note_requested_status' ), 10, 2 );
		add_action( self::BEFORE_HOOK, array( $this, 'prime_tax_rates' ), 8, 2 );
		add_action( self::BEFORE_HOOK, array( $this, 'suspend_tax_preservation' ), 9, 2 );
		add_action( self::AFTER_HOOK, array( $this, 'resume_tax_preservation' ), self::RESUME_PRIORITY );
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
	 * Prime the plugin's rates for the order before WooCommerce matches them.
	 *
	 * Mirrors the request the plugin's protected get_backend_line_items() builds
	 * (woocommerce-services 3.6.14), with one deliberate difference: items
	 * WooCommerce will not tax are left out. The plugin sends them as exempt and
	 * skips their 0% breakdown line through a private list only its own builders
	 * fill; without that list the 0% would be written over the shared rate row
	 * for the item's tax class. Their rates are never needed here.
	 *
	 * @param array                  $args  Calculation arguments. Unused.
	 * @param WC_Abstract_Order|null $order The order being recalculated.
	 */
	public function prime_tax_rates( $args = array(), $order = null ): void {
		// A leftover suspension would hide the plugin's callbacks from the lookup below.
		$this->restore_suspended();

		if ( ! $order instanceof WC_Abstract_Order || ! \wcpos_request() || ! $this->is_open_pos_order( $order ) ) {
			return;
		}
		$callbacks = $this->find_plugin_callbacks( self::BEFORE_HOOK, self::PLUGIN_CALLBACKS[ self::BEFORE_HOOK ] );
		if ( empty( $callbacks ) ) {
			return;
		}
		$taxjar = $callbacks[0]['callback'][0];

		// get_taxable_location() is public since WooCommerce 7.6. Older stores keep
		// today's behaviour rather than a warning on every save.
		if ( version_compare( WC_VERSION, '7.6.0', '<' ) ) {
			return;
		}

		try {
			$location = $order->get_taxable_location();
			$options  = array(
				'to_country'      => $location['country'] ?? '',
				'to_state'        => $location['state'] ?? '',
				'to_zip'          => $location['postcode'] ?? '',
				'to_city'         => $location['city'] ?? '',
				'to_street'       => $this->street_for_location( $order, $location ),
				'shipping_amount' => $order->get_shipping_total(),
				'line_items'      => array(),
			);
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( 'taxable' !== $item->get_tax_status() ) {
					continue;
				}
				$quantity   = $item->get_quantity();
				$unit_price = empty( $quantity ) ? $item->get_subtotal() : wc_format_decimal( $item->get_subtotal() / $quantity );
				if ( empty( $unit_price ) ) {
					continue;
				}
				$tax_class               = explode( '-', $item->get_tax_class() );
				$options['line_items'][] = array(
					'id'               => (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
					'quantity'         => $quantity,
					'unit_price'       => $unit_price,
					'discount'         => wc_format_decimal( $item->get_subtotal() - $item->get_total() ),
					'product_tax_code' => isset( $tax_class[1] ) && is_numeric( $tax_class[1] ) ? $tax_class[1] : '',
				);
			}
			if ( empty( $options['line_items'] ) && empty( (float) $options['shipping_amount'] ) ) {
				return;
			}
			// WooCommerce does not initialise the customer on REST requests, and the
			// plugin reads it for its VAT-exemption check.
			if ( ! WC()->customer instanceof \WC_Customer ) {
				wc_load_cart();
			}
			if ( false === $taxjar->calculate_tax( $options ) ) {
				\WCPOS\WooCommercePOS\Logger::log( 'WooCommerce Tax returned no rates for the POS order', array( 'order_id' => $order->get_id() ) );
			}
		} catch ( \Throwable $e ) {
			\WCPOS\WooCommercePOS\Logger::warning(
				'WooCommerce Tax rate priming failed',
				array(
					'order_id' => $order->get_id(),
					'error'    => $e->getMessage(),
				)
			);
		}
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
	 * The street line that belongs to the address WooCommerce is taxing.
	 *
	 * The declared basis (the POS meta, else WooCommerce's setting) is tried first
	 * so two addresses that share a country, state, postcode and city are told
	 * apart — including the store's own address, which a local customer's billing
	 * or shipping address can match exactly; the tuple check keeps the street
	 * consistent with the location that was actually resolved, which a filter may
	 * have changed.
	 *
	 * @param WC_Abstract_Order $order    The order.
	 * @param array             $location Country, state, postcode and city from get_taxable_location().
	 *
	 * @return string
	 */
	private function street_for_location( WC_Abstract_Order $order, array $location ): string {
		if ( $order instanceof WC_Order ) {
			$basis = (string) $order->get_meta( '_woocommerce_pos_tax_based_on' );
			if ( '' === $basis ) {
				$basis = (string) get_option( 'woocommerce_tax_based_on', 'shipping' );
			}
			// The store address is a candidate too, but LAST unless it is the
			// declared basis: when a filter moves the taxed location to the other
			// customer address, that address must win over a store that happens
			// to share its country, state, postcode and city.
			$countries  = WC()->countries;
			$candidates = array(
				'billing'  => array( $order->get_billing_address_1(), array( $order->get_billing_country(), $order->get_billing_state(), $order->get_billing_postcode(), $order->get_billing_city() ) ),
				'shipping' => array( $order->get_shipping_address_1(), array( $order->get_shipping_country(), $order->get_shipping_state(), $order->get_shipping_postcode(), $order->get_shipping_city() ) ),
				'base'     => array( $countries->get_base_address(), array( $countries->get_base_country(), $countries->get_base_state(), $countries->get_base_postcode(), $countries->get_base_city() ) ),
			);
			if ( isset( $candidates[ $basis ] ) ) {
				$candidates = array( $basis => $candidates[ $basis ] ) + $candidates;
			}
			$taxed = array( $location['country'] ?? '', $location['state'] ?? '', $location['postcode'] ?? '', $location['city'] ?? '' );
			foreach ( $candidates as $candidate ) {
				if ( $candidate[1] === $taxed ) {
					return (string) $candidate[0];
				}
			}
		}

		return (string) WC()->countries->get_base_address();
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
