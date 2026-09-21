<?php
/**
 * Construct the POS service groups for the current request.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Emails;
use WCPOS\WooCommercePOS\Gateways;
use WCPOS\WooCommercePOS\i18n;
use WCPOS\WooCommercePOS\Orders;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\Templates;

/**
 * One module for "which POS services does this request need, and do they exist yet?".
 *
 * Three groups. `always` is what WooCommerce consults on a plain shopper page
 * before any order exists; `order` is the order-event observers; `pos` is what
 * only POS, admin, REST, cron and CLI requests use. `Init::init_common()` picks
 * the groups for the lane {@see Request_Lane} reports, and the order group is
 * additionally armed on the first order write of ANY request — so the lane is an
 * optimisation, never a correctness gate.
 *
 * Each group is constructed at most once per request, and a group is marked
 * constructed BEFORE its services are built: a service that writes an order
 * while constructing must not re-enter its own group.
 *
 * `Templates` straddles the order group's boundary on purpose. It is built here
 * with the order group, but its post type and taxonomy are also read from My
 * Account on the storefront lane, where that group does not exist. Its own
 * {@see Templates::ensure_registered()} hatch covers that read. The hatch is the
 * right shape for a service needed in part on every lane — not a symptom to fix
 * by moving `Templates` into the always group, which would put its registration
 * back on every shop page.
 */
final class Service_Groups {
	public const ALWAYS = 'always';
	public const ORDER  = 'order';
	public const POS    = 'pos';

	/**
	 * Groups constructed so far this request, in construction order.
	 *
	 * @var array<string, bool>
	 */
	private static array $constructed = array();

	/**
	 * Whether this request has installed the late order-write trigger.
	 *
	 * @var bool
	 */
	private static bool $armed = false;

	/**
	 * Construct a group's services exactly once per request.
	 *
	 * Groups are independent: ensuring one never implies another. An unknown
	 * group name is a no-op and is not recorded as constructed.
	 *
	 * @param string $group One of the class constants.
	 */
	public static function ensure( string $group ): void {
		if ( isset( self::$constructed[ $group ] ) ) {
			return;
		}
		if ( ! \in_array( $group, array( self::ALWAYS, self::ORDER, self::POS ), true ) ) {
			return;
		}

		// Marked before constructing, so a service that writes an order during
		// its own construction re-enters this method and returns immediately.
		self::$constructed[ $group ] = true;

		switch ( $group ) {
			case self::ALWAYS:
				self::construct_always();
				break;
			case self::ORDER:
				self::construct_order();
				break;
			case self::POS:
				self::construct_pos();
				break;
		}
	}

	/**
	 * Hook the order group to the first order write of the request.
	 *
	 * Every WooCommerce order write — create, update, status transition,
	 * `payment_complete()`, refund — goes through `WC_Abstract_Order::save()`,
	 * which fires `woocommerce_before_order_object_save` before the data store
	 * writes and before `woocommerce_new_order` / `woocommerce_order_status_changed`
	 * / `woocommerce_payment_complete` fire. Priority 0 there means every
	 * observer exists before any order is written — on a webhook, a cron
	 * spawned from a page view, a third-party plugin creating an order on
	 * `template_redirect`, or a lane the classifier got wrong. Nothing in the
	 * order group listens to trash or delete, so those need no arming.
	 *
	 * Refunds need their own hook. `save()` derives that action from the object
	 * type, so a `WC_Order_Refund` fires `woocommerce_before_order_refund_object_save`
	 * instead. `wc_create_refund()` does save the parent order too, but only AFTER
	 * WooCommerce has decided whether to send the customer refunded-order email —
	 * and that decision reads `Emails::manage_customer_emails`, which belongs to
	 * this group. Arming the parent save alone left the filter unregistered at the
	 * moment it was consulted, so a merchant with POS customer emails switched off
	 * still had the shopper emailed on a storefront-lane refund (a gateway refund
	 * webhook on `?wc-api=` is that lane). The refund save runs before the email
	 * decision, so arming it is early enough.
	 */
	public static function arm_order_group(): void {
		self::$armed = true;
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'ensure_order_group' ), 0, 0 );
		add_action( 'woocommerce_before_order_refund_object_save', array( self::class, 'ensure_order_group' ), 0, 0 );
	}

	/**
	 * Construct the order group. Public because it is the armed hook's callback.
	 */
	public static function ensure_order_group(): void {
		self::ensure( self::ORDER );
	}

	/**
	 * Whether the late order-write trigger is installed on this request.
	 */
	public static function armed(): bool {
		return self::$armed;
	}

	/**
	 * Which groups this request has constructed, in construction order.
	 *
	 * @internal Test seam.
	 *
	 * @return string[]
	 */
	public static function constructed(): array {
		return array_keys( self::$constructed );
	}

	/**
	 * Forget this request's construction state. Tests only; hooks are restored separately.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$constructed = array();
		self::$armed       = false;
	}

	/**
	 * Services every lane needs, including a plain storefront page.
	 *
	 * These are the ones WooCommerce consults before any order exists:
	 * translations, the product visibility filters, the order statuses and the
	 * read-side order filters (My Account renders POS orders), the gateway
	 * registration (WooCommerce builds its gateway list on cart pages) and the
	 * reserved-stock filter (POS drafts must reduce online availability at
	 * add-to-cart time).
	 */
	private static function construct_always(): void {
		// init the Services.
		Settings::instance();
		Auth::instance();

		new i18n();
		new Gateways();
		new Products();
		new Orders();
		Stock_Validator::instance();
		Order_Write_Intent::register();
	}

	/**
	 * The order-event observers, plus the action that lets others join them.
	 */
	private static function construct_order(): void {
		Receipt_Snapshot_Store::instance();
		new Emails();
		new Templates();
		new Print_Job_Service();
		new Cloud_Print_Trigger_Service();
		new Cloud_Print_Submit_Service();
		new Cloud_Print_Relay_Service();

		/**
		 * Fires once per request when the POS order-event services exist:
		 * eagerly on POS, admin, REST, cron and CLI requests (from this
		 * plugin's `init` callback at priority 10), and on a storefront
		 * request the moment the first order is about to be written.
		 *
		 * Because the eager firing happens at `init` priority 10, a listener
		 * added later than that (for example from another plugin's `init`
		 * callback at priority 20) must check `did_action()` first and
		 * construct immediately when the action has already fired.
		 *
		 * @since 1.10.8
		 */
		do_action( 'woocommerce_pos_order_services_ready' );
	}

	/**
	 * Services only POS, admin, REST, cron and CLI requests use.
	 */
	private static function construct_pos(): void {
		Extensions::instance();
		new Decimal_Quantities();
		new Customer_Meta_Parity();
	}
}
