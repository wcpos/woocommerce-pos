<?php
/**
 * WCPOS REST route classifier.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

/**
 * Classifies WCPOS REST routes without applying authentication or capabilities.
 */
final class Route_Classifier {
	/**
	 * WCPOS REST namespaces.
	 *
	 * @var string[]
	 */
	private $namespaces;

	/**
	 * Routes grouped by permission-gate classification.
	 *
	 * @var array<string, string[]>
	 */
	private $classifications = array(
		'public'                       => array(),
		'printer_token'                => array(),
		'admin_op'                     => array(),
		'permission_error_passthrough' => array(),
		'rewrite_exempt'               => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param string[] $namespaces WCPOS REST namespaces.
	 */
	public function __construct( array $namespaces ) {
		$this->namespaces = $namespaces;
	}

	/**
	 * Merge route classifications discovered during registration.
	 *
	 * @param array<string, string[]> $classifications Route classifications.
	 */
	public function merge( array $classifications ): void {
		foreach ( $classifications as $classification => $routes ) {
			if ( ! isset( $this->classifications[ $classification ] ) || ! \is_array( $routes ) ) {
				continue;
			}

			$this->classifications[ $classification ] = array_values(
				array_unique( array_merge( $this->classifications[ $classification ], $routes ) )
			);
		}
	}

	/**
	 * Check whether a route belongs to a registered WCPOS namespace.
	 *
	 * @param string $route REST route.
	 */
	public function in_wcpos_namespace( string $route ): bool {
		foreach ( $this->namespaces as $namespace ) {
			if ( 0 === strpos( $route, '/' . $namespace . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a route is public.
	 *
	 * @param string $route REST route.
	 */
	public function is_public( string $route ): bool {
		return $this->is_exact_match( 'public', $route );
	}

	/**
	 * Check whether a route authenticates with a printer token.
	 *
	 * Matches the registered route exactly or as a slash-delimited prefix:
	 * printer polls also arrive on path-credential URLs such as
	 * cloudprnt/<printer_id>/<pt> (Star URL-encodes query strings), and those
	 * concrete routes must stay exempt from the capability gate without
	 * widening the match to sibling routes that merely share a name prefix.
	 *
	 * @param string $route REST route.
	 */
	public function is_printer_token( string $route ): bool {
		if ( $this->is_exact_match( 'printer_token', $route ) ) {
			return true;
		}

		foreach ( $this->classifications['printer_token'] as $base ) {
			if ( 0 === strpos( $route, $base . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a route is an out-of-band admin operation.
	 *
	 * @param string $route REST route.
	 */
	public function is_admin_op( string $route ): bool {
		return $this->is_exact_match( 'admin_op', $route );
	}

	/**
	 * Check whether a route supplies its own permission error.
	 *
	 * @param string $route REST route.
	 */
	public function is_permission_error_passthrough( string $route ): bool {
		return $this->is_prefix_match( 'permission_error_passthrough', $route );
	}

	/**
	 * Check whether a route bypasses include/exclude rewriting.
	 *
	 * @param string $route REST route.
	 */
	public function is_rewrite_exempt( string $route ): bool {
		return $this->is_prefix_match( 'rewrite_exempt', $route );
	}

	/**
	 * Check for an exact route classification match.
	 *
	 * @param string $classification Classification key.
	 * @param string $route          REST route.
	 */
	private function is_exact_match( string $classification, string $route ): bool {
		return \in_array( $route, $this->classifications[ $classification ], true );
	}

	/**
	 * Check for a route classification prefix match.
	 *
	 * @param string $classification Classification key.
	 * @param string $route          REST route.
	 */
	private function is_prefix_match( string $classification, string $route ): bool {
		foreach ( $this->classifications[ $classification ] as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
