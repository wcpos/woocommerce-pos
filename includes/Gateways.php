<?php
/**
 * Loads the POS Payment Gateways.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 * Gateways class.
 */
class Gateways {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'available_payment_gateways' ), 99 );
	}

	/**
	 * Add POS gateways
	 * BEWARE: some gateways/themes/plugins call this very early on every page!!
	 * We cannot guarantee that $wp is set, so we cannot use woocommerce_pos_request.
	 *
	 * @param array $gateways The registered payment gateways.
	 *
	 * @return array
	 */
	public function payment_gateways( array $gateways ) {
		global $plugin_page;

		// Early exit for WooCommerce settings, ie: don't show POS gateways.
		if ( self::should_suppress_pos_gateways( is_admin(), $plugin_page ) ) {
			return $gateways;
		}

		// All other cases, the default POS gateways are added.
		return array_merge(
			$gateways,
			array(
				'WCPOS\WooCommercePOS\Gateways\Cash',
				'WCPOS\WooCommercePOS\Gateways\Card',
			)
		);
	}

	/**
	 * Decide whether the POS gateways should be withheld from registration.
	 *
	 * Pure policy function: all ambient state is passed in, so it makes no
	 * WordPress calls and reads no globals.
	 *
	 * NOTE: the loose `==` comparison is carried over verbatim from the inline
	 * implementation this was extracted from. `$plugin_page` is a WordPress
	 * global that is commonly `null`, and on PHP 7.4 a loose comparison against
	 * a non-empty string behaves differently from PHP 8 for some falsy values,
	 * so the comparison operator is preserved rather than tightened.
	 *
	 * @param bool  $is_admin    Result of is_admin() for the current request.
	 * @param mixed $plugin_page The WordPress `$plugin_page` global.
	 *
	 * @return bool True when the POS gateways must not be registered.
	 */
	public static function should_suppress_pos_gateways( bool $is_admin, $plugin_page ): bool {
		return $is_admin && 'wc-settings' == $plugin_page;
	}

	/**
	 * Get available payment POS gateways,
	 * - Order and set default order
	 * - Also going to remove icons from the gateways.
	 *
	 * - NOTE: lots of plugins/themes call this filter and I can't guarantee that $gateways is an array
	 *
	 * @param null|array $gateways The available payment gateways.
	 *
	 * @return null|array The available payment gateways.
	 */
	public function available_payment_gateways( ?array $gateways ): ?array {
		// early exit.
		if ( ! woocommerce_pos_request() ) {
			return $gateways;
		}

		// use POS settings.
		$settings = woocommerce_pos_get_settings( 'payment_gateways' );

		/*
		 * Get all payment gateways.
		 *
		 * NOTE: this reads the public `payment_gateways` property directly rather
		 * than calling the `payment_gateways()` accessor. Preserved verbatim - the
		 * two are not interchangeable in every WooCommerce version.
		 */
		$all_gateways = WC()->payment_gateways->payment_gateways;

		return self::order_gateways( $all_gateways, $settings );
	}

	/**
	 * Apply the POS gateway availability, presentation and ordering policy.
	 *
	 * Selects the gateways enabled in the POS `payment_gateways` settings,
	 * overrides each one's title, blanks its icon, forces it enabled and marks the
	 * configured `default_gateway` as chosen, then sorts the result by the
	 * per-gateway `order` setting.
	 *
	 * Free of ambient state - it makes no WordPress calls and reads no globals, so
	 * it can be exercised directly in unit tests. It is NOT side-effect free: the
	 * gateway objects are mutated in place, which in production means the live
	 * `WC()->payment_gateways->payment_gateways` singletons. That is the existing
	 * mechanism (Admin\Orders\Single_Order::add_available_gateways() relies on the
	 * same singleton mutation) and is preserved deliberately.
	 *
	 * NOTE: neither parameter carries a native typehint. Lots of plugins/themes
	 * call the `woocommerce_available_payment_gateways` filter and neither the
	 * gateway list nor the settings shape is guaranteed; a native `array` here
	 * would turn today's warning into an uncatchable TypeError on the payment
	 * path. Behaviour for unexpected shapes is preserved exactly as it was inline.
	 *
	 * @param array $gateways All registered payment gateway objects.
	 * @param array $settings The POS `payment_gateways` settings blob.
	 *
	 * @return array The enabled gateways, keyed by gateway id, in settings order.
	 */
	public static function order_gateways( $gateways, $settings ): array {
		$_available_gateways = array();

		foreach ( $gateways as $gateway ) {
			if ( isset( $settings['gateways'][ $gateway->id ] ) && $settings['gateways'][ $gateway->id ]['enabled'] ) {
				if ( isset( $settings['gateways'][ $gateway->id ]['title'] ) ) {
					$gateway->title = $settings['gateways'][ $gateway->id ]['title'];
				}

				/*
				 * There is an issue over-writing the description field because some gateways use this for info,
				 * eg: Account Funds uses it to show the current balance.
				 */
				// if ( isset( $settings['gateways'][ $gateway->id ]['description'] ) ) {
				// $gateway->description = $settings['gateways'][ $gateway->id ]['description'];
				// }.

				$gateway->icon    = '';
				$gateway->enabled = 'yes';
				$gateway->chosen  = $gateway->id === $settings['default_gateway'];

				$_available_gateways[ $gateway->id ] = $gateway;
			}
		}

		/*
		 * Order the available gateways according to the settings.
		 *
		 * KNOWN ISSUE (pre-existing, deliberately preserved): the `order` key is
		 * read unguarded, so a gateway configured without one emits an undefined
		 * index warning and sorts as if its order were null. This is characterised
		 * by Test_Gateways::test_order_gateways_missing_order_key_warns_and_sorts_null_first().
		 */
		uksort(
			$_available_gateways,
			function ( $a, $b ) use ( $settings ) {
				return $settings['gateways'][ $a ]['order'] <=> $settings['gateways'][ $b ]['order'];
			}
		);

		return $_available_gateways;
	}
}
