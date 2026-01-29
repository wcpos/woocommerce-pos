<?php
/**
 * Generate JWT tokens for e2e API tests.
 *
 * Usage: wp eval-file generate-jwt.php
 */

$auth = WCPOS\WooCommercePOS\Services\Auth::instance();
$user = get_user_by( 'login', 'admin' );

if ( ! $user ) {
	echo json_encode( array( 'error' => 'Admin user not found' ) );
	exit( 1 );
}

$tokens = $auth->generate_token_pair( $user );

if ( is_wp_error( $tokens ) ) {
	echo json_encode( array( 'error' => $tokens->get_error_message() ) );
	exit( 1 );
}

echo json_encode( $tokens );
