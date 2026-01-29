<?php
/**
 * Write .htaccess with Authorization passthrough and WordPress rewrite rules.
 *
 * This script handles two issues in CI:
 * 1. Apache strips the Authorization header — SetEnvIf passes it to PHP.
 * 2. `wp rewrite flush --hard` may fail to write .htaccess in wp-env Docker,
 *    so we ensure WordPress rewrite rules are present for pretty permalinks.
 *
 * We use a PHP file instead of shell commands to avoid $1 backreference
 * being eaten by multiple layers of shell escaping (YAML → wp-env → bash).
 *
 * Usage: wp eval-file fix-htaccess.php
 */

$htaccess_path = ABSPATH . '.htaccess';

$htaccess = <<<'HTACCESS'
# Pass Authorization header to PHP
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS;

file_put_contents( $htaccess_path, $htaccess . "\n" );

echo "Written .htaccess:\n";
echo file_get_contents( $htaccess_path );
