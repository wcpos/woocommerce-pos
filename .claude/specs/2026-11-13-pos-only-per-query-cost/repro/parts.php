<?php
/**
 * Break one storefront product query's POS Only cost into parts (wp eval-file).
 * Uses the stored visibility option as configured by configure.php.
 */
global $wpdb;
$ids = array_map( 'intval', get_option( 'woocommerce_pos_settings_visibility' )['products']['default']['pos_only']['ids'] );
$n   = count( $ids );
$rep = 50;
$base = "SELECT SQL_CALC_FOUND_ROWS {$wpdb->posts}.ID FROM {$wpdb->posts} WHERE 1=1 AND {$wpdb->posts}.post_type = 'product' AND (({$wpdb->posts}.post_status = 'publish')) %s ORDER BY {$wpdb->posts}.post_date DESC LIMIT 200, 20";
$time = function ( $fn ) use ( $rep ) {
	$t = hrtime( true );
	for ( $i = 0; $i < $rep; $i++ ) {
		$fn();
	}
	return ( hrtime( true ) - $t ) / 1e6 / $rep;
};
$list = implode( ',', $ids );
$once = " AND {$wpdb->posts}.ID NOT IN ($list)";
printf( "n=%d\n", $n );
printf( "mysql none   %.2f ms\n", $time( function () use ( $wpdb, $base ) { $wpdb->get_col( sprintf( $base, '' ) ); } ) );
printf( "mysql once   %.2f ms\n", $time( function () use ( $wpdb, $base, $once ) { $wpdb->get_col( sprintf( $base, $once ) ); } ) );
printf( "mysql twice  %.2f ms\n", $time( function () use ( $wpdb, $base, $once ) { $wpdb->get_col( sprintf( $base, $once . $once ) ); } ) );
printf( "php intval   %.2f ms\n", $time( function () use ( $ids ) { array_map( 'intval', $ids ); } ) );
printf( "php absint+implode (WP core post__not_in) %.2f ms\n", $time( function () use ( $ids ) { implode( ',', array_map( 'absint', $ids ) ); } ) );
printf( "php prepare %%d x n %.2f ms\n", $time( function () use ( $wpdb, $ids ) { $f = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $wpdb->prepare( " AND ID NOT IN ($f)", $ids ); } ) );
printf( "php getallheaders x1 %.4f ms\n", $time( function () { array_change_key_case( getallheaders() ); } ) );
