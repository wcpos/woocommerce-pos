<?php

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix' => 'WCPOS\\Vendor',

	'finders' => array(
		Finder::create()->files()->in( 'vendor/firebase/php-jwt' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/phpfastcache/phpfastcache' )->name( '*.php' ),
	),

	'patchers' => array(
		function ( string $filePath, string $prefix, string $content ) {
			return $content;
		},
	),

	'whitelist' => array(),
);
