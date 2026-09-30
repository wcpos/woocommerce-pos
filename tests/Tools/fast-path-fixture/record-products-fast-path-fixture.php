<?php
/**
 * Dev tool: record the wcpos/v2 products fast-path contract fixture.
 *
 * Creates five products (published managed, published unmanaged, published with no lookup row,
 * published unmanaged with a leftover `_stock`, and a draft), requests
 * `GET /wcpos/v2/products?per_page=-1&_fields=id,date_modified_gmt,stock_quantity,stock_status`
 * as an administrator with the POS headers, and writes the request and response to
 * out/products-fast-path-fixture.json. Everything runs inside one database transaction that is
 * always rolled back, so the dev site is left as it was.
 *
 * Run inside wp-env (the `cli` container is the dev site):
 *   pnpm exec wp-env run cli --env-cwd='wp-content/plugins/<dir>' \
 *     wp eval-file tests/Tools/fast-path-fixture/record-products-fast-path-fixture.php
 *
 * Ids and dates depend on the database (auto-increment and the time of the run); any other
 * published product on the site is listed too.
 *
 * phpcs:ignoreFile
 */

use const WCPOS\WooCommercePOS\VERSION;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The plugin registers its routes on rest_api_init only for a marked request: mark this
// process and drop any REST server built during bootstrap, so rest_api_init runs again.
$_SERVER['HTTP_X_WCPOS']          = '1';
$_SERVER['HTTP_X_WCPOS_PROTOCOL'] = '2';
$GLOBALS['wp_rest_server']        = null;

global $wpdb;

$out_dir  = __DIR__ . '/out';
$out_file = $out_dir . '/products-fast-path-fixture.json';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

$wpdb->query( 'START TRANSACTION' );
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( empty( $admins ) ) {
		throw new RuntimeException( 'No administrator on this site.' );
	}
	wp_set_current_user( (int) $admins[0] );

	$make = static function ( string $name, string $date, array $props, string $status = 'publish' ): int {
		$product = new WC_Product_Simple();
		$product->set_props( array_merge( array( 'name' => $name, 'regular_price' => '10', 'status' => $status, 'date_created' => $date ), $props ) );

		return $product->save();
	};

	$ids = array(
		'a' => $make( 'Fixture A', '2026-09-01 10:00:00', array( 'manage_stock' => true, 'stock_quantity' => 5 ) ),
		'b' => $make( 'Fixture B', '2026-09-02 10:00:00', array( 'manage_stock' => false ) ),
		'c' => $make( 'Fixture C', '2026-09-03 10:00:00', array( 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) ),
		'd' => $make( 'Fixture D', '2026-09-04 10:00:00', array( 'manage_stock' => false ) ),
		'e' => $make( 'Fixture E', '2026-09-05 10:00:00', array( 'manage_stock' => true, 'stock_quantity' => 2 ), 'draft' ),
	);
	$wpdb->delete( $wpdb->wc_product_meta_lookup, array( 'product_id' => $ids['c'] ) );
	update_post_meta( $ids['d'], '_stock', '7' );

	$query   = array(
		'per_page' => '-1',
		'_fields'  => 'id,date_modified_gmt,stock_quantity,stock_status',
	);
	$headers = array(
		'X-WCPOS'          => '1',
		'X-WCPOS-Protocol' => '2',
	);
	$request = new WP_REST_Request( 'GET', '/wcpos/v2/products' );
	$request->set_query_params( $query );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	$response = rest_do_request( $request );

	$contract    = array();
	$environment = array();
	foreach ( $response->get_headers() as $name => $value ) {
		if ( in_array( $name, array( 'X-WP-Total', 'X-WP-TotalPages' ), true ) ) {
			$contract[ $name ] = $value;
		} else {
			$environment[ $name ] = $value;
		}
	}

	$fixture = array(
		'source'              => array(
			'script'      => 'tests/Tools/fast-path-fixture/record-products-fast-path-fixture.php',
			'plugin'      => VERSION,
			'wordpress'   => get_bloginfo( 'version' ),
			'woocommerce' => WC()->version,
		),
		'request'             => array(
			'method'  => 'GET',
			'path'    => '/wp-json/wcpos/v2/products',
			'query'   => $query,
			'headers' => $headers,
		),
		'response'            => array(
			'status'  => $response->get_status(),
			'headers' => $contract,
			'body'    => rest_get_server()->response_to_data( $response, false ),
		),
		'environment_headers' => array(
			'_note'   => 'Not part of the contract: these depend on the site and plugins. Do not assert on them.',
			'headers' => $environment,
		),
		'products'            => array(
			$ids['a'] => 'published, managed stock 5: listed, stock_quantity 5, instock',
			$ids['b'] => 'published, unmanaged: listed, stock_quantity null, instock',
			$ids['c'] => 'published, managed stock 0, outofstock, lookup row deleted: listed from postmeta',
			$ids['d'] => 'published, unmanaged with a leftover _stock of 7: listed, stock_quantity 7',
			$ids['e'] => 'draft, managed stock 2: not listed',
		),
	);

	file_put_contents( $out_file, wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

WP_CLI::line( $out_file );
