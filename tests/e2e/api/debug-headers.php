<?php
/**
 * Debug script to inspect HTTP headers as received by PHP.
 * Used in CI to diagnose Authorization header issues.
 */
header('Content-Type: application/json');

$keys = array(
	'HTTP_AUTHORIZATION',
	'REDIRECT_HTTP_AUTHORIZATION',
	'HTTP_X_WCPOS',
	'PHP_AUTH_USER',
	'PHP_AUTH_PW',
);

$result = array();
foreach ( $keys as $k ) {
	$result[ $k ] = isset( $_SERVER[ $k ] ) ? $_SERVER[ $k ] : 'NOT_SET';
}

$result['getallheaders'] = function_exists( 'getallheaders' ) ? getallheaders() : 'NOT_AVAILABLE';

echo json_encode( $result, JSON_PRETTY_PRINT );
