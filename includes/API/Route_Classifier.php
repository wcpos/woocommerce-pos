<?php
/**
 * WCPOS REST route classifier.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

/**
 * Classifies WCPOS REST routes without applying authentication or capabilities.
 *
 * WordPress matches REST route regexes case-insensitively (WP_REST_Server adds
 * the `i` flag), so a mixed-case path still dispatches to the real controller.
 * Every predicate here must therefore compare case-insensitively too — a
 * case-sensitive comparison would let /WCPOS/V1/... skip the permission gate.
 */
final class Route_Classifier {
	/**
	 * WCPOS REST namespaces, stored lowercase.
	 *
	 * @var string[]
	 */
	private $namespaces;

	/**
	 * Routes grouped by permission-gate classification, stored lowercase.
	 * Incoming routes are lowercased before every comparison.
	 *
	 * @var array<string, string[]>
	 */
	private $classifications = array(
		'public'                       => array(),
		'printer_token'                => array(),
		'admin_op'                     => array(),
		'permission_error_passthrough' => array(),
		'rewrite_exempt'               => array(),
		'protocol_exempt'              => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param string[] $namespaces WCPOS REST namespaces.
	 */
	public function __construct( array $namespaces ) {
		$this->namespaces = array_map( 'strtolower', array_filter( $namespaces, 'is_string' ) );
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
				array_unique( array_merge( $this->classifications[ $classification ], array_map( 'strtolower', array_filter( $routes, 'is_string' ) ) ) )
			);
		}
	}

	/**
	 * Check whether a route belongs to a registered WCPOS namespace.
	 *
	 * @param string $route REST route.
	 */
	public function in_wcpos_namespace( string $route ): bool {
		$route = strtolower( $route );

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
		return $this->is_exact_match( 'printer_token', $route ) || $this->is_slash_prefix_match( 'printer_token', $route );
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
	 * Check whether a route bypasses the minimum protocol gate.
	 *
	 * One rule: every `public` route and every `printer_token` route is exempt
	 * by derivation — public routes are the pre-login connect probes, and
	 * printer-token routes are polled by printers and the cloud-print relay,
	 * so neither caller carries a client protocol signal by construction and
	 * "update required" would be a lie. Anything else must be declared in
	 * `protocol_exempt`; an entry covers itself and every route below it
	 * (`/wcpos/v2/auth/` exempts the whole auth family). Never declare a
	 * public or printer-token route here by hand — the drift guard pins the
	 * derivation.
	 *
	 * @param string $route REST route.
	 */
	public function is_protocol_exempt( string $route ): bool {
		return $this->is_public( $route )
			|| $this->is_printer_token( $route )
			|| $this->is_exact_match( 'protocol_exempt', $route )
			|| $this->is_slash_prefix_match( 'protocol_exempt', $route );
	}

	/**
	 * Get the routes stored for a classification.
	 *
	 * Exists for the drift guards in `tests/includes/API`, which walk a group
	 * against the live route table; production code asks the predicates.
	 *
	 * @param string $classification Classification key.
	 * @return string[] Classified routes.
	 */
	public function routes( string $classification ): array {
		return $this->classifications[ $classification ] ?? array();
	}

	/**
	 * Check for an exact route classification match.
	 *
	 * @param string $classification Classification key.
	 * @param string $route          REST route.
	 */
	private function is_exact_match( string $classification, string $route ): bool {
		return \in_array( strtolower( $route ), $this->classifications[ $classification ], true );
	}

	/**
	 * Check for a slash-delimited route classification prefix match.
	 *
	 * Stricter than {@see self::is_prefix_match()}: `/wcpos/v2/status` must
	 * not match `/wcpos/v2/status-report`, only routes below it. Every entry
	 * is a base, with or without a trailing slash.
	 *
	 * @param string $classification Classification key.
	 * @param string $route          REST route.
	 */
	private function is_slash_prefix_match( string $classification, string $route ): bool {
		$route = strtolower( $route );

		foreach ( $this->classifications[ $classification ] as $entry ) {
			if ( 0 === strpos( $route, rtrim( $entry, '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for a route classification prefix match.
	 *
	 * @param string $classification Classification key.
	 * @param string $route          REST route.
	 */
	private function is_prefix_match( string $classification, string $route ): bool {
		$route = strtolower( $route );

		foreach ( $this->classifications[ $classification ] as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
