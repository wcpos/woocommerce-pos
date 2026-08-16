<?php
/**
 * Tax catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WP_REST_Request;

/**
 * Applies tax ID narrowing and POS read permission without a v1 controller.
 */
final class Taxes_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var int[]
	 */
	private $include = array();

	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var int[]
	 */
	private $exclude = array();

	/**
	 * Adjust the params forwarded to wc/v3 for this resource.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		foreach ( array( 'include', 'exclude' ) as $param ) {
			if ( isset( $params[ $param ] ) ) {
				$this->{$param}                   = wp_parse_id_list( $params[ $param ] );
				$params[ 'wcpos_' . $param ] = $this->{$param};
				unset( $params[ $param ] );
			}
		}

		return $params;
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		$bindings  = array();
		$permission = static function ( $allowed, $context, $object_id, $post_type ) {
			if ( ! $allowed && 'settings' === $post_type && 'read' === $context ) {
				$allowed = current_user_can( 'access_woocommerce_pos' );
			}

			return $allowed;
		};
		add_filter( 'woocommerce_rest_check_permissions', $permission, 10, 4 );
		$bindings[] = array( 'woocommerce_rest_check_permissions', $permission, 10 );

		if ( array() === $this->include && array() === $this->exclude ) {
			return $bindings;
		}

		/*
		 * Stays installed for the whole forward, and is unwound by around()'s
		 * finally rather than by removing itself on first match.
		 *
		 * v1's method self-removes, but it removes the array callback
		 * `array( $this, 'wcpos_tax_add_include_exclude_to_sql' )` — while the
		 * proxy lane registered a CLOSURE wrapping that method, so the removal
		 * never matched and the filter went on to narrow the pagination COUNT
		 * query too. Self-removing here really does unhook, which left the
		 * count unfiltered and the reported total too high.
		 * `test_cashier_include_filter_limits_pagination_totals` pins this.
		 *
		 * Re-application is safe: each invocation receives a fresh query string,
		 * and the strpos guard scopes it to tax-rate queries.
		 */
		$query_filter = function ( string $query ): string {
			global $wpdb;

			if ( false === strpos( $query, "{$wpdb->prefix}woocommerce_tax_rates" ) ) {
				return $query;
			}
			if ( array() !== $this->include ) {
				$query = self::insert_where( $query, "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id IN (" . implode( ',', $this->include ) . ')' );
			}
			if ( array() !== $this->exclude ) {
				$query = self::insert_where( $query, "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id NOT IN (" . implode( ',', $this->exclude ) . ')' );
			}

			return $query;
		};
		add_filter( 'query', $query_filter, 10, 1 );
		$bindings[] = array( 'query', $query_filter, 10 );

		return $bindings;
	}

	/**
	 * Insert one condition into the WooCommerce tax-rate SQL.
	 *
	 * @param string $query     SQL query.
	 * @param string $condition WHERE condition.
	 *
	 * @return string
	 */
	private static function insert_where( string $query, string $condition ): string {
		if ( false !== strpos( $query, 'WHERE' ) ) {
			return str_replace( 'WHERE', "WHERE $condition AND", $query );
		}

		$position = strpos( $query, 'ORDER BY' );
		return false !== $position
			? substr_replace( $query, " WHERE $condition ", $position, 0 )
			: $query . " WHERE $condition";
	}
}
