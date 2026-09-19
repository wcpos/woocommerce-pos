<?php
/**
 * WCPOS REST controller registry.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Logger;

/**
 * Owns both controller maps, their instances, route attribution and classification merge.
 *
 * The v2 service map is the v2-native services plus every v1 entry that serves
 * wcpos/v1 except the nine frozen data controllers (#544); the registry stamps
 * wcpos/v2 on each promoted instance. A derived replacement that registers nothing
 * under wcpos/v2 leaves the lane to the core service, as the hand-kept map did.
 * Attribution takes the tail of the server's route table after each registration,
 * so callback shape and namespace are irrelevant. The 'v2-' registry key prefix is
 * internal, not a route namespace.
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
	 * @param array<string, class-string> $v1 The v1 entries that serve wcpos/v1 (register() passes only those).
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
	 * Instantiate, stamp, register and attribute every controller on both lanes;
	 * merge each controller's classifications.
	 *
	 * @param Route_Classifier $classifier Route permission-gate classifier.
	 */
	public function register( Route_Classifier $classifier ): void {
		$core = self::core_v1_map();
		$v1   = self::v1_map();

		// Only an entry that serves wcpos/v1 is promoted: the v1 filter also carries
		// the sync controllers, which are wcpos/v2-native and register there already.
		$serves_v1 = array();
		foreach ( $v1 as $key => $class ) {
			if ( self::under( $this->register_one( self::V1_NAMESPACE, $key, $class, $classifier ), self::V1_NAMESPACE ) ) {
				$serves_v1[ $key ] = $class;
			}
		}

		foreach ( self::v2_map( $serves_v1 ) as $key => $class ) {
			$routes = $this->register_one( self::V2_NAMESPACE, $key, $class, $classifier );
			// A derived v1 replacement (not a class the v2 filter chose) that registered
			// nothing under wcpos/v2 — a class that hard-codes wcpos/v1, or a plain object
			// with no namespace to stamp — leaves the lane to the core service, as the
			// hand-kept map did.
			$derived_replacement = isset( $serves_v1[ $key ], $core[ $key ] ) && $serves_v1[ $key ] === $class && $core[ $key ] !== $class;
			if ( $derived_replacement && ! self::under( $routes, self::V2_NAMESPACE ) ) {
				$this->register_one( self::V2_NAMESPACE, $key, $core[ $key ], $classifier );
			}
		}
	}

	/**
	 * Instantiate, stamp, register and attribute one controller on one lane.
	 *
	 * @param string           $lane       The lane the map belongs to.
	 * @param string           $key        The map key.
	 * @param string           $class      The controller class.
	 * @param Route_Classifier $classifier Route permission-gate classifier.
	 *
	 * @return string[] The route patterns this registration added, in any namespace.
	 */
	private function register_one( string $lane, string $key, string $class, Route_Classifier $classifier ): array {
		if ( ! class_exists( $class ) ) {
			return array();
		}
		$controller   = new $class();
		$registry_key = $key;
		if ( self::V2_NAMESPACE === $lane ) {
			$registry_key = 'v2-' . $key;
			// Any controller that keeps a namespace is stamped, not only a
			// WP_REST_Controller subclass: the v2 map takes a class name, so a
			// controller written against WP_REST_Server directly is as entitled to
			// the promotion as one that extends core's base.
			$scope = self::namespace_scope( $controller );
			if ( null !== $scope ) {
				self::stamp_namespace( $controller, $scope, $lane );
			}
		}
		$this->controllers[ $registry_key ] = $controller;

		// WordPress appends a new route pattern to the end of its table, so the
		// patterns added since the count taken before registration are this
		// controller's, whatever namespace they are under and whatever shape their
		// callbacks take. A pattern registered twice keeps its first owner. The
		// index route WordPress adds for a namespace's first pattern is the
		// server's, not the controller's.
		$server = rest_get_server();
		$from   = \count( self::endpoints( $server ) );
		$controller->register_routes();
		$routes = array();
		foreach ( \array_slice( self::endpoints( $server ), $from, null, true ) as $route => $entry ) {
			if ( isset( $entry['namespace'] ) && '/' . $entry['namespace'] === $route ) {
				continue;
			}
			$this->routes[ $route ] = $registry_key;
			$routes[]               = $route;
		}

		if ( method_exists( $controller, 'wcpos_route_classifications' ) ) {
			$classifier->merge( $controller->wcpos_route_classifications() );
		} elseif ( isset( self::LEGACY_CLASSIFICATIONS[ $key ] ) ) {
			$classifier->merge( self::legacy_classifications( $key, $lane ) );
		}

		return $routes;
	}

	/**
	 * The server's raw route table, in registration order.
	 *
	 * `WP_REST_Server::$endpoints` is protected and `get_routes()` is its only
	 * reader, but that applies `rest_endpoints` and parses every handler on each
	 * call: measured 2026-09-18, one call per controller made API construction
	 * six times slower. Reading the table itself costs nothing (copy-on-write).
	 *
	 * @param \WP_REST_Server $server The REST server.
	 *
	 * @return array<string, array>
	 */
	private static function endpoints( \WP_REST_Server $server ): array {
		return \Closure::bind(
			function (): array {
				return $this->endpoints;
			},
			$server,
			$server
		)();
	}

	/**
	 * Whether any of the routes is under the lane.
	 *
	 * @param string[] $routes Route patterns.
	 * @param string   $lane   Namespace.
	 */
	private static function under( array $routes, string $lane ): bool {
		foreach ( $routes as $route ) {
			if ( 0 === strpos( $route, '/' . $lane . '/' ) ) {
				return true;
			}
		}

		return false;
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
	 * The class that declares this controller's namespace, if any declares one.
	 *
	 * Walked rather than asked, because property_exists() answers false for a
	 * property a BASE class keeps private — the shape where a naive write would
	 * quietly add a dynamic property to the subclass while the inherited
	 * register_routes() went on reading the original value. A controller that
	 * declares no namespace anywhere has nothing to stamp and registers where its
	 * own register_routes() says, as it did before the map was derived.
	 *
	 * @param object $controller Controller instance.
	 *
	 * @return string|null The declaring class name, or null when there is none.
	 */
	private static function namespace_scope( object $controller ): ?string {
		for ( $class = new \ReflectionClass( $controller ); false !== $class; $class = $class->getParentClass() ) {
			if ( $class->hasProperty( 'namespace' ) ) {
				return $class->getName();
			}
		}

		return null;
	}

	/**
	 * WP_REST_Controller::$namespace is protected with no setter (Paul, 2026-09-18: stamp
	 * every v2 entry here rather than ask each class to opt in, so a v1 replacement from
	 * Pro or a third party reaches wcpos/v2 with no work on its side).
	 *
	 * @param object $controller Controller instance.
	 * @param string $scope      The class that declares the property, so a namespace
	 *                           a base class keeps private is written where it lives.
	 * @param string $namespace  Target namespace.
	 */
	private static function stamp_namespace( object $controller, string $scope, string $namespace ): void {
		try {
			\Closure::bind(
				function () use ( $namespace ): void {
					$this->namespace = $namespace;
				},
				$controller,
				$scope
			)();
		} catch ( \Error $e ) {
			// The controller declared its namespace readonly, so it has already
			// decided it and PHP refuses the write even from inside the class.
			// Left alone it registers where its own register_routes() says, as it
			// did before the map was derived; rethrowing would abort rest_api_init
			// and take every WCPOS route with it. The bound closure does nothing
			// else, so there is no other Error this can swallow.
			Logger::log( 'wcpos/v2 promotion left ' . \get_class( $controller ) . ' on its own namespace: ' . $e->getMessage() );
		}
	}
}
