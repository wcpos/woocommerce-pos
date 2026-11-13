<?php
/**
 * Storefront-context benchmark of the POS Only query path.
 *
 * Run as plain PHP (not wp eval), so WP_CLI is undefined, is_admin() is false
 * and Products::is_storefront_request() is true, as on a cron, feed or
 * front-end request:
 *   php .claude/repro/bench.php <scenario> [K]
 * Scenarios: wpq, both, gp, wcgp, children, visible.
 */
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/shop/';
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

global $wpdb;
$scenario = $argv[1] ?? 'wpq';
$k        = (int) ( $argv[2] ?? 200 );

$hook_t  = 0.0;
$t_start = array();
$sql_len = 0;
$queries = 0;
foreach ( array( 'pre_get_posts', 'posts_where' ) as $h ) {
	add_filter( $h, function ( $v ) use ( $h, &$t_start ) { $t_start[ $h ] = hrtime( true ); return $v; }, 9 );
	add_filter( $h, function ( $v ) use ( $h, &$t_start, &$hook_t ) { if ( isset( $t_start[ $h ] ) ) { $hook_t += ( hrtime( true ) - $t_start[ $h ] ) / 1e6; } return $v; }, 11 );
}
add_filter( 'posts_request', function ( $sql ) use ( &$sql_len, &$queries ) { $sql_len += strlen( $sql ); ++$queries; return $sql; }, PHP_INT_MAX );

$t0 = hrtime( true );
switch ( $scenario ) {
	case 'wpq':
		for ( $p = 1; $p <= $k; $p++ ) {
			new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 20, 'paged' => $p, 'fields' => 'ids' ) );
		}
		break;
	case 'both':
		for ( $p = 1; $p <= $k; $p++ ) {
			new WP_Query( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'publish', 'posts_per_page' => 20, 'paged' => $p, 'fields' => 'ids' ) );
		}
		break;
	case 'gp':
		// get_posts() suppresses filters: only pre_get_posts runs.
		for ( $p = 1; $p <= $k; $p++ ) {
			get_posts( array( 'post_type' => 'product', 'posts_per_page' => 20, 'paged' => $p, 'fields' => 'ids' ) );
		}
		break;
	case 'wcgp':
		for ( $p = 1; $p <= $k; $p++ ) {
			wc_get_products( array( 'status' => 'publish', 'limit' => 20, 'page' => $p ) );
		}
		break;
	case 'children':
		$parents = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE 'Variable %%' ORDER BY ID LIMIT %d", $k ) );
		foreach ( $parents as $pid ) {
			$product = wc_get_product( $pid );
			$product->get_children();
		}
		break;
	case 'visible':
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID DESC LIMIT %d", $k ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->is_visible();
			}
		}
		break;
}
$total = ( hrtime( true ) - $t0 ) / 1e6;
printf(
	"%s k=%d total=%.0fms queries=%d per_query=%.2fms hooks=%.0fms (%.2fms/q) sql_avg=%dB peak_mem=%dMB\n",
	$scenario, $k, $total, $queries, $queries ? $total / $queries : 0, $hook_t, $queries ? $hook_t / $queries : 0, $queries ? (int) ( $sql_len / $queries ) : 0, memory_get_peak_usage( true ) >> 20
);
