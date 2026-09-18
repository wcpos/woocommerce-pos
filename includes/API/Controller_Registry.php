<?php
/**
 * WCPOS REST controller registry.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

/**
 * Owns both controller maps, their instances, route attribution and classification merge.
 *
 * The v2 service map is the v2-native services plus every v1 entry except the nine
 * frozen data controllers (#544); the registry stamps wcpos/v2 on each promoted
 * instance. A promoted replacement that registers nothing under wcpos/v2 leaves the
 * lane to the core service, as the hand-kept map did. Attribution diffs the route
 * table around each registration, so callback wrapping is irrelevant. The 'v2-'
 * registry key prefix is internal, not a route namespace.
 */
final class Controller_Registry {
	/** The v1 data controllers the sync surface replaced. Never promoted to wcpos/v2 (#544). */
	public const FROZEN_DATA_KEYS = array( 'products', 'product_variations', 'orders', 'customers', 'product_tags', 'product_categories', 'product_brands', 'coupons', 'taxes' );

	/** The frozen lane; every v1 class declares it itself. */
	public const V1_NAMESPACE = 'wcpos/v1';

	/** Namespace every entry of the v2 map is stamped with. */
	public const V2_NAMESPACE = 'wcpos/v2';

	/**
	 * Gate exemptions for a filtered replacement of these three services that
	 * predates `wcpos_route_classifications()` (the gate carried them itself
	 * before 1.10.0). Applied under whichever lane the replacement registers on,
	 * so a legacy auth replacement that now reaches wcpos/v2 keeps anonymous
	 * login there too. Not a list to extend: a service declares its own.
	 *
	 * @var array<string, array<string, string[]>> Map key → classification → route suffixes.
	 */
	private const LEGACY_CLASSIFICATIONS = array(
		'auth'       => array( 'public' => array( '/auth/test', '/auth/refresh' ) ),
		'print_jobs' => array( 'printer_token' => array( '/print-jobs/cloudprnt', '/print-jobs/epson-sdp' ) ),
		'receipts'   => array( 'permission_error_passthrough' => array( '/receipts/' ) ),
	);

	/**
	 * Route pattern to registry key.
	 *
	 * @var array<string, string>
	 */
	private array $routes = array();

	/**
	 * Registry key to controller instance.
	 *
	 * @var array<string, object>
	 */
	private array $controllers = array();

	/**
	 * Get the filtered v1 controller map.
	 *
	 * @return array<string, class-string> The v1 map after woocommerce_pos_rest_api_controllers.
	 */
	public static function v1_map(): array {
		/**
		 * Filter the list of controller classes used in the WCPOS REST API.
		 *
		 * This filter allows customizing or extending the set of controller classes that handle
		 * REST API routes for the WCPOS. By filtering these controllers, plugins can
		 * modify existing endpoints or add new controllers for additional functionality.
		 * Core legacy controllers use their versioned WCPOS\WooCommercePOS\API\V1 FQCNs.
		 *
		 * @since 1.5.0
		 *
		 * @param array $controllers Associative array of controller identifiers to their corresponding class names.
		 *                           - 'auth'                  => Fully qualified name of the class handling authentication.
		 *                           - 'settings'              => Fully qualified name of the class handling settings.
		 *                           - 'cashier'               => Fully qualified name of the class handling cashier management.
		 *                           - 'products'              => Fully qualified name of the class handling products.
		 *                           - 'product_variations'    => Fully qualified name of the class handling product variations.
		 *                           - 'orders'                => Fully qualified name of the class handling orders.
		 *                           - 'customers'             => Fully qualified name of the class handling customers.
		 *                           - 'product_tags'          => Fully qualified name of the class handling product tags.
		 *                           - 'product_categories'    => Fully qualified name of the class handling product categories.
		 *                           - 'taxes'                 => Fully qualified name of the class handling taxes.
		 *                           - 'shipping_methods'      => Fully qualified name of the class handling shipping methods.
		 *                           - 'tax_classes'           => Fully qualified name of the class handling tax classes.
		 *                           - 'order_statuses'        => Fully qualified name of the class handling order statuses.
		 */
		return apply_filters( 'woocommerce_pos_rest_api_controllers', self::core_v1_map() );
	}

	/**
	 * The v1 map as this plugin ships it, before any filter.
	 *
	 * @return array<string, class-string>
	 */
	public static function core_v1_map(): array {
		return array(
			// WCPOS rest api controllers.
			'auth'                  => V1\Auth::class,
			'settings'              => V1\Settings::class,
			'cashier'               => V1\Cashier::class,
			'templates'             => V1\Templates_Controller::class,
			'receipts'              => V1\Receipts_Controller::class,
			'print_jobs'            => V1\Print_Jobs_Controller::class,

			// TODO: remove this?
			'stores'                => V1\Stores::class,
			'extensions'            => V1\Extensions::class,
			'logs'                  => V1\Logs::class,
			'payment_gateways'      => V1\Payment_Gateways::class,
			'gateway_bootstrap'     => V1\Gateway_Bootstrap_Controller::class,
			'checkout'              => V1\Checkout_Controller::class,

			// extend WC REST API controllers.
			'products'              => V1\Products_Controller::class,
			'product_variations'    => V1\Product_Variations_Controller::class,
			'orders'                => V1\Orders_Controller::class,
			'customers'             => V1\Customers_Controller::class,
			'product_tags'          => V1\Product_Tags_Controller::class,
			'product_categories'    => V1\Product_Categories_Controller::class,
			'product_brands'        => V1\Product_Brands_Controller::class,
			'coupons'               => V1\Coupons_Controller::class,
			'taxes'                 => V1\Taxes_Controller::class,
			'shipping_methods'      => V1\Shipping_Methods_Controller::class,
			'tax_classes'           => V1\Tax_Classes_Controller::class,
			'order_statuses'        => V1\Data_Order_Statuses_Controller::class,
		);
	}

	/**
	 * Promote shared services, add v2-native services, then apply the v2 filter.
	 *
	 * @param array<string, class-string> $v1 The v1 map.
	 * @return array<string, class-string> The v2 map.
	 */
	public static function v2_map( array $v1 ): array {
		// The v2-native services come first, as they did in the hand-kept map, and win a key collision.
		$natives = array(
			'ping'        => V2\Ping::class,
			'echo_probe'  => V2\Echo_Probe::class,
			'site'        => V2\Site::class,
			'order_email' => V2\Order_Email_Controller::class,
		);
		$map     = $natives + array_diff_key( $v1, array_flip( self::FROZEN_DATA_KEYS ) );

		/**
		 * Filter the wcpos/v2 service map derived from v1 minus FROZEN_DATA_KEYS.
		 *
		 * This additive filter can add a v2-only service or replace a derived entry
		 * by key. Every entry is instantiated by the registry and stamped with
		 * wcpos/v2, so a replacement needs no $namespace override of its own.
		 *
		 * @since 1.10.0
		 * @since 1.10.19 The map is derived; a v1 replacement now reaches v2 on its own.
		 *
		 * @param array $controllers Associative array of v2 service controller class names.
		 */
		return apply_filters( 'woocommerce_pos_rest_api_v2_controllers', $map );
	}

	/**
	 * Instantiate, stamp, register and attribute both lanes; merge classifications.
	 *
	 * @param Route_Classifier $classifier Route permission-gate classifier.
	 */
	public function register( Route_Classifier $classifier ): void {
		$core = self::core_v1_map();
		$v1   = self::v1_map();
		$maps = array(
			self::V1_NAMESPACE => $v1,
			self::V2_NAMESPACE => self::v2_map( $v1 ),
		);

		foreach ( $maps as $namespace => $map ) {
			foreach ( $map as $key => $class ) {
				$registered = $this->register_one( $namespace, $key, $class, $classifier );
				if ( $registered || self::V2_NAMESPACE !== $namespace || ! isset( $core[ $key ] ) || $core[ $key ] === $class ) {
					continue;
				}
				// A promoted replacement that registered nothing under wcpos/v2 — a class
				// that hard-codes wcpos/v1, or a plain object with no namespace to stamp —
				// leaves the lane to the core service, as the hand-kept map did.
				$this->register_one( $namespace, $key, $core[ $key ], $classifier );
			}
		}
	}

	/**
	 * Instantiate, stamp, register and attribute one controller on one lane.
	 *
	 * @param string           $namespace  The lane.
	 * @param string           $key        The map key.
	 * @param string           $class      The controller class.
	 * @param Route_Classifier $classifier Route permission-gate classifier.
	 *
	 * @return bool Whether the controller registered at least one route under the lane.
	 */
	private function register_one( string $namespace, string $key, string $class, Route_Classifier $classifier ): bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}
		$server       = rest_get_server();
		$controller   = new $class();
		$registry_key = $key;
		if ( self::V2_NAMESPACE === $namespace ) {
			$registry_key = 'v2-' . $key;
			if ( $controller instanceof \WP_REST_Controller ) {
				self::stamp_namespace( $controller, $namespace );
			}
		}
		$this->controllers[ $registry_key ] = $controller;

		// A route this controller added has more handlers after registration than
		// before (WordPress appends on re-registration), so the last registrant
		// owns it — what the old callback lookup did, without looking at callbacks.
		$before = $server->get_routes( $namespace );
		$controller->register_routes();
		$registered = false;
		foreach ( $server->get_routes( $namespace ) as $route => $handlers ) {
			if ( \count( $handlers ) === \count( $before[ $route ] ?? array() ) ) {
				continue;
			}
			$this->routes[ $route ] = $registry_key;
			$registered             = true;
		}

		if ( method_exists( $controller, 'wcpos_route_classifications' ) ) {
			$classifier->merge( $controller->wcpos_route_classifications() );
		} elseif ( isset( self::LEGACY_CLASSIFICATIONS[ $key ] ) ) {
			$classifier->merge( self::legacy_classifications( $key, $namespace ) );
		}

		return $registered;
	}

	/**
	 * The historical gate exemptions under one lane's namespace.
	 *
	 * @param string $key       A key of LEGACY_CLASSIFICATIONS.
	 * @param string $namespace The lane.
	 *
	 * @return array<string, string[]> Classification → routes.
	 */
	private static function legacy_classifications( string $key, string $namespace ): array {
		$classifications = array();
		foreach ( self::LEGACY_CLASSIFICATIONS[ $key ] as $classification => $suffixes ) {
			foreach ( $suffixes as $suffix ) {
				$classifications[ $classification ][] = '/' . $namespace . $suffix;
			}
		}

		return $classifications;
	}

	/**
	 * Find the controller that registered a route.
	 *
	 * @param string $route Route pattern.
	 * @return object|null Controller, or null when no WCPOS controller registered it.
	 */
	public function controller_for_route( string $route ): ?object {
		$key = $this->routes()[ $route ] ?? null;
		return $this->controllers()[ $key ] ?? null;
	}

	/**
	 * Get route attribution.
	 *
	 * @return array<string, string> Route pattern to registry key (test seam).
	 */
	public function routes(): array {
		return $this->routes;
	}

	/**
	 * Get registered instances.
	 *
	 * @return array<string, object> V1 keys and 'v2-' plus v2 keys (test seam).
	 */
	public function controllers(): array {
		return $this->controllers;
	}

	/**
	 * WP_REST_Controller::$namespace is protected with no setter (Paul, 2026-09-18: stamp
	 * every v2 entry here rather than ask each class to opt in, so a v1 replacement from
	 * Pro or a third party reaches wcpos/v2 with no work on its side).
	 *
	 * @param object $controller Controller instance.
	 * @param string $namespace Target namespace.
	 */
	private static function stamp_namespace( object $controller, string $namespace ): void {
		\Closure::bind(
			function () use ( $namespace ): void {
				$this->namespace = $namespace;
			},
			$controller,
			$controller
		)();
	}
}
