<?php
/**
 * Add SetEnvIf Authorization rule to .htaccess.
 *
 * Apache strips the Authorization header by default. This rule passes it
 * through to PHP as $_SERVER['HTTP_AUTHORIZATION'].
 *
 * We use a PHP file instead of shell commands to avoid $1 backreference
 * being eaten by multiple layers of shell escaping (YAML → wp-env → bash).
 *
 * Usage: wp eval-file fix-htaccess.php
 */

$htaccess_path = ABSPATH . '.htaccess';
$htaccess      = file_exists( $htaccess_path ) ? file_get_contents( $htaccess_path ) : '';
$rule          = 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1';

if ( strpos( $htaccess, 'SetEnvIf Authorization' ) !== false ) {
	// Remove any existing (possibly broken) SetEnvIf Authorization line
	$htaccess = preg_replace( '/^SetEnvIf Authorization.*$/m', '', $htaccess );
	$htaccess = preg_replace( '/^\s*#\s*Pass Authorization header.*$/m', '', $htaccess );
	$htaccess = ltrim( $htaccess );
}

// Prepend the rule
$htaccess = "# Pass Authorization header to PHP\n" . $rule . "\n\n" . $htaccess;

file_put_contents( $htaccess_path, $htaccess );

echo "Updated .htaccess with SetEnvIf Authorization rule\n";
echo file_get_contents( $htaccess_path );
