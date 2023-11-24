<?php

use Isolated\Symfony\Component\Finder\Finder;

return array(
	// Define the namespace prefix to use.
	'prefix' => 'WCPOS\Vendor',

	// Finders: Locate files that need to be scoped.
	'finders' => array(
		// Finder for the firebase/php-jwt library.
		Finder::create()->files()
				->in( 'vendor/firebase/php-jwt' )
				->name( '*.php' ), // Scope only PHP files.

		// Add Finder for yahnis-elsts/plugin-update-checker
		Finder::create()->files()
				->in( 'vendor/yahnis-elsts/plugin-update-checker' )
				->name( '*.php' ), // Scope only PHP files.

		// Add Finder for ramsey/uuid
		// NOTE: UUID has too many dependencies to scope, so we'll just leave it alone.
		// Finder::create()->files()
		// ->in( 'vendor/ramsey/uuid' )
		// ->name( '*.php' ), // Scope only PHP files.
	),

	// 'patchers' are used to transform the code after it has been scoped.
	// Define any necessary patchers below. For a minimal setup, this might not be needed.
		'patchers' => array(
			// Example patcher (you can modify or remove this)
			function ( string $filePath, string $prefix, string $content ) {
				// Modify $content as needed or return it unchanged.
				return $content;
			},
		),

	// 'whitelist' can be used to specify classes, functions, and constants
	// that should not be prefixed (i.e., left in the global scope).
	'whitelist' => array(
		// Example: 'YourNamespacePrefix\Firebase\JWT\*',
	),
);
