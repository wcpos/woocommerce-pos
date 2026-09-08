<?php
/**
 * Test double for the WooCommerce Tax (woocommerce-services) TaxJar integration.
 *
 * WooCommerce Tax 3.6.8+ preserves an order's recorded taxes across any
 * recalculation that happens outside the cart and admin-AJAX flows: it snapshots
 * the tax lines on `woocommerce_order_before_calculate_taxes` and writes them back
 * on `woocommerce_order_after_calculate_totals`, rebasing the order total on the
 * preserved tax. This stub reproduces that pair (class name, method names, hook
 * priorities, gates and restore arithmetic) closely enough that an order update
 * through WC's REST `save_object()` restores the pre-update tax — the behaviour
 * behind #1896 — so the suite can pin the WCPOS work-around without the plugin.
 *
 * Mirrors woocommerce-services 3.6.14, classes/class-wc-connect-taxjar-integration.php.
 * Re-read that file when the plugin ships a new major or minor: the test pins
 * WCPOS against this model, so upstream drift is invisible to the suite.
 *
 * Kept in the global namespace on purpose: the integration matches the callback by
 * the plugin's class name.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Mirrors the third-party class name.

if ( ! class_exists( 'WC_Connect_TaxJar_Integration', false ) ) {
	/**
	 * Minimal WC_Connect_TaxJar_Integration.
	 */
	class WC_Connect_TaxJar_Integration {
		/**
		 * Number of snapshots taken. Exposed so tests can prove whether the
		 * preserve callback ran for a given recalculation.
		 *
		 * @var int
		 */
		public $snapshots_taken = 0;

		/**
		 * Number of snapshots restored.
		 *
		 * @var int
		 */
		public $restores_applied = 0;

		/**
		 * Snapshots keyed by order id.
		 *
		 * @var array<int, array>
		 */
		private $pre_recalculation_tax_snapshots = array();

		/**
		 * Rate ids populated by the cart/checkout flow in the real plugin. Always
		 * empty here, as it is for any REST order write.
		 *
		 * @var array
		 */
		private $response_rate_ids = array();

		/**
		 * Recorded priming options.
		 *
		 * @var array
		 */
		public $calculate_tax_calls = array();
		/**
		 * Rates available from TaxJar, keyed by postcode.
		 *
		 * @var array
		 */
		public $rates_by_postcode = array();
		/**
		 * Whether priming throws.
		 *
		 * @var bool
		 */
		public $calculate_tax_throws = false;
		/**
		 * Inserted rate ids, keyed by postcode.
		 *
		 * @var array
		 */
		private $inserted_rate_ids = array();

		/**
		 * Prime WooCommerce's rate table as the real calculate_tax() does.
		 *
		 * @param array $options TaxJar request options.
		 * @return array|false
		 * @throws \RuntimeException When configured to simulate TaxJar failure.
		 */
		public function calculate_tax( $options = array() ) {
			$this->calculate_tax_calls[] = $options;
			if ( $this->calculate_tax_throws ) {
				throw new \RuntimeException( 'TaxJar unavailable' );
			}
			$postcode = $options['to_zip'];
			if ( ! isset( $this->rates_by_postcode[ $postcode ] ) ) {
				return false;
			}
			if ( ! isset( $this->inserted_rate_ids[ $postcode ] ) ) {
				$rate = $this->rates_by_postcode[ $postcode ];
				$id   = WC_Tax::_insert_tax_rate(
					array(
						'tax_rate_country'  => $options['to_country'],
						'tax_rate_state'    => $rate['state'],
						'tax_rate'          => $rate['rate'],
						'tax_rate_name'     => $rate['name'],
						'tax_rate_priority' => 1,
						'tax_rate_compound' => 0,
						'tax_rate_shipping' => 1,
						'tax_rate_class'    => '',
					)
				);
				WC_Tax::_update_tax_rate_postcodes( $id, $postcode );
				$this->inserted_rate_ids[ $postcode ] = $id;
			}
			return array( 'has_nexus' => 1 );
		}

		/**
		 * Register the preservation hook the way the plugin's init() does.
		 */
		public function init(): void {
			add_action( 'woocommerce_order_before_calculate_taxes', array( $this, 'preserve_order_taxes_on_recalculation' ), 10, 2 );
		}

		/**
		 * Unhook everything this stub registered.
		 */
		public function teardown(): void {
			remove_action( 'woocommerce_order_before_calculate_taxes', array( $this, 'preserve_order_taxes_on_recalculation' ), 10 );
			remove_action( 'woocommerce_order_after_calculate_totals', array( $this, 'restore_order_taxes_after_recalculation' ), 10 );
			$this->pre_recalculation_tax_snapshots = array();
		}

		/**
		 * Snapshot an existing order's taxes before WC recalculates them.
		 *
		 * @param array              $args  Args passed to calculate_taxes(). Unused.
		 * @param \WC_Abstract_Order $order The order being recalculated.
		 */
		public function preserve_order_taxes_on_recalculation( $args, $order ): void {
			if ( ! empty( $this->response_rate_ids ) ) {
				return;
			}
			if ( wp_doing_ajax() ) {
				return;
			}
			if ( ! $order->get_id() ) {
				return;
			}

			$snapshot = $this->snapshot_order_taxes( $order );
			if ( empty( $snapshot['tax_lines'] ) ) {
				return;
			}

			++$this->snapshots_taken;
			$this->pre_recalculation_tax_snapshots[ (int) $order->get_id() ] = $snapshot;

			remove_action( 'woocommerce_order_after_calculate_totals', array( $this, 'restore_order_taxes_after_recalculation' ), 10 );
			add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'restore_order_taxes_after_recalculation' ), 10, 2 );
		}

		/**
		 * Restore the snapshot after WC has recalculated the order's totals.
		 *
		 * @param bool               $and_taxes Whether taxes were recalculated. Unused.
		 * @param \WC_Abstract_Order $order     The order whose totals were recalculated.
		 */
		public function restore_order_taxes_after_recalculation( $and_taxes, $order ): void {
			$order_id = (int) $order->get_id();
			if ( ! isset( $this->pre_recalculation_tax_snapshots[ $order_id ] ) ) {
				return;
			}

			$snapshot = $this->pre_recalculation_tax_snapshots[ $order_id ];
			unset( $this->pre_recalculation_tax_snapshots[ $order_id ] );

			++$this->restores_applied;
			$this->restore_order_taxes( $order, $snapshot );
		}

		/**
		 * Capture tax lines, per-item taxes and order tax totals.
		 *
		 * @param \WC_Abstract_Order $order The order to snapshot.
		 * @return array
		 */
		private function snapshot_order_taxes( $order ): array {
			$snapshot = array(
				'tax_lines'    => array(),
				'item_taxes'   => array(),
				'cart_tax'     => $order->get_cart_tax(),
				'shipping_tax' => $order->get_shipping_tax(),
				'discount_tax' => $order->get_discount_tax(),
			);

			foreach ( $order->get_taxes() as $item_id => $tax_item ) {
				$snapshot['tax_lines'][ $item_id ] = array(
					'rate_id'            => $tax_item->get_rate_id(),
					'rate_code'          => $tax_item->get_rate_code(),
					'label'              => $tax_item->get_label(),
					'rate_percent'       => $tax_item->get_rate_percent(),
					'compound'           => $tax_item->get_compound(),
					'tax_total'          => $tax_item->get_tax_total(),
					'shipping_tax_total' => $tax_item->get_shipping_tax_total(),
				);
			}

			foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item_id => $item ) {
				$snapshot['item_taxes'][ $item_id ] = $item->get_taxes();
			}

			foreach ( $order->get_shipping_methods() as $item_id => $item ) {
				$snapshot['item_taxes'][ 'shipping_' . $item_id ] = $item->get_taxes();
			}

			return $snapshot;
		}

		/**
		 * Put the snapshot back and rebase the order total on the preserved tax.
		 *
		 * @param \WC_Abstract_Order $order    The order to restore into.
		 * @param array              $snapshot Snapshot from snapshot_order_taxes().
		 */
		private function restore_order_taxes( $order, array $snapshot ): void {
			if ( empty( $snapshot['tax_lines'] ) ) {
				return;
			}

			foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item_id => $item ) {
				if ( isset( $snapshot['item_taxes'][ $item_id ] ) ) {
					$item->set_taxes( $snapshot['item_taxes'][ $item_id ] );
				}
			}

			foreach ( $order->get_shipping_methods() as $item_id => $item ) {
				if ( isset( $snapshot['item_taxes'][ 'shipping_' . $item_id ] ) ) {
					$item->set_taxes( $snapshot['item_taxes'][ 'shipping_' . $item_id ] );
				}
			}

			foreach ( $order->get_taxes() as $tax_item ) {
				$order->remove_item( $tax_item->get_id() );
			}

			foreach ( $snapshot['tax_lines'] as $tax_data ) {
				$tax_item = new \WC_Order_Item_Tax();
				$tax_item->set_rate_id( $tax_data['rate_id'] );
				$tax_item->set_rate_code( $tax_data['rate_code'] );
				$tax_item->set_label( $tax_data['label'] );
				$tax_item->set_rate_percent( $tax_data['rate_percent'] );
				$tax_item->set_compound( $tax_data['compound'] );
				$tax_item->set_tax_total( $tax_data['tax_total'] );
				$tax_item->set_shipping_tax_total( $tax_data['shipping_tax_total'] );
				$order->add_item( $tax_item );
			}

			$non_tax_total = (float) $order->get_total() - (float) $order->get_cart_tax() - (float) $order->get_shipping_tax();

			$order->set_cart_tax( $snapshot['cart_tax'] );
			$order->set_shipping_tax( $snapshot['shipping_tax'] );
			$order->set_discount_tax( $snapshot['discount_tax'] );
			$order->set_total( round( $non_tax_total + (float) $snapshot['cart_tax'] + (float) $snapshot['shipping_tax'], wc_get_price_decimals() ) );

			$order->save();
		}
	}
}
