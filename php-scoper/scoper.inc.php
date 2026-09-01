<?php

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix' => 'WCPOS\\Vendor',

	'finders' => array(
		Finder::create()->files()->in( 'vendor/firebase/php-jwt' )->name( '*.php' ),

		// Dompdf (PDF rendering) — prefix the PHP sources and COPY the runtime
		// resources Dompdf loads by path (fonts, res/, VERSION, lib/Cpdf.php).
		// A *.php-only finder would silently drop the fonts/CSS and break rendering.
		Finder::create()->files()->in( 'vendor/dompdf/dompdf/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/dompdf/dompdf/lib' ), // Cpdf.php (php) + fonts/ + res/ (copied verbatim).
		Finder::create()->files()->in( 'vendor/dompdf/dompdf' )->depth( '== 0' )->name( 'VERSION' ),

		// php-font-lib — PHP sources plus the encoding maps it reads by path.
		Finder::create()->files()->in( 'vendor/dompdf/php-font-lib/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/dompdf/php-font-lib/maps' ),

		// php-svg-lib, HTML5 parser, CSS parser — pure PHP.
		Finder::create()->files()->in( 'vendor/dompdf/php-svg-lib/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/masterminds/html5/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/sabberworm/php-css-parser/src' )->name( '*.php' ),

		// Barcode (1D → SVG) — pure PHP.
		Finder::create()->files()->in( 'vendor/picqer/php-barcode-generator/src' )->name( '*.php' ),

		// QR code (→ SVG) — pure PHP.
		Finder::create()->files()->in( 'vendor/chillerlan/php-qrcode/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/chillerlan/php-settings-container/src' )->name( '*.php' ),

		// Sentry PHP SDK (consent-gated error reporting) + its runtime deps.
		// sentry/sentry 4.x transports over ext-curl directly — no HTTP client
		// abstraction packages must ever be added here.
		Finder::create()->files()->in( 'vendor/sentry/sentry/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/guzzlehttp/psr7/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/psr/http-message/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/psr/http-factory/src' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/psr/log/Psr/Log' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/symfony/options-resolver' )->name( '*.php' ),
		// Defines the global trigger_deprecation() that OptionsResolver calls; the
		// scoper prefixes both the calls and this guarded definition, and
		// woocommerce-pos.php requires the file eagerly (the generated classmap
		// autoloader cannot load function-only files).
		Finder::create()->files()->in( 'vendor/symfony/deprecation-contracts' )->name( 'function.php' ),
		// Deliberately NOT shipped:
		// - jean85/pretty-package-versions: referenced only by Sentry's
		//   ModulesIntegration (never enabled here — default_integrations is
		//   false), and it reads Composer\InstalledVersions, which does not
		//   exist in a scoped build.
		// - symfony/polyfill-php82: guzzle psr7 3.x needs it only for the
		//   #[\SensitiveParameter] attribute, which PHP 7.4 parses as a comment
		//   and newer PHP never reflects, so the class is never autoloaded.
	),

	'patchers' => array(
		/**
		 * Re-prefix namespace tokens inside dynamically-built class-name strings.
		 *
		 * php-scoper rewrites declared/`use`/static FQCN references, but it cannot
		 * rewrite class names assembled at runtime from string fragments, e.g.
		 * Dompdf's `"Dompdf\\FrameDecorator\\{$decorator}"` or php-font-lib's
		 * `"FontLib\\{$type}\\TableDirectoryEntry"`. Those literals survive
		 * unprefixed and fatal at render time ("Class Dompdf\FrameDecorator\Block
		 * not found"). Prepend the prefix to any such leftover token, guarding
		 * against the already-prefixed occurrences via a negative lookbehind.
		 */
		function ( string $filePath, string $prefix, string $content ) {
			// Sentry rides the same patcher: FrameBuilder marks frames whose
			// function name starts with the literal 'Sentry\\' as not-in-app, and
			// Options validates 'integrations' against the string type
			// 'Sentry\\Integration\\IntegrationInterface[]' — both are
			// double-backslash string fragments the scoper cannot rewrite.
			$namespaces = 'Dompdf|FontLib|Svg|Sentry';
			$pattern    = '/(?<!Vendor\\\\\\\\)(' . $namespaces . ')\\\\\\\\/';

			$patched = preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( $prefix ) {
					$prefix_double = str_replace( '\\', '\\\\', $prefix );

					return $prefix_double . '\\\\' . $matches[1] . '\\\\';
				},
				$content
			);
			if ( null !== $patched ) {
				$content = $patched;
			}

			// php-font-lib's Font::load() builds class names by concatenating a
			// RELATIVE sub-namespace fragment ("TrueType\\File") with the outer
			// "FontLib\\{$class}". php-scoper wrongly treats the bare fragment as a
			// root FQCN and prefixes it (→ "WCPOS\\Vendor\\TrueType\\File"), which
			// — once the outer concat is (correctly) re-prefixed above — yields a
			// double-prefixed, non-existent class. Strip the erroneous prefix from
			// those relative fragments so the outer concat assembles the real FQCN.
			if ( false !== strpos( $filePath, 'php-font-lib/src/FontLib/Font.php' ) ) {
				$prefix_double = str_replace( '\\', '\\\\', $prefix );
				foreach ( array( 'TrueType', 'OpenType', 'WOFF', 'EOT' ) as $sub ) {
					$content = str_replace(
						'"' . $prefix_double . '\\\\' . $sub . '\\\\',
						'"' . $sub . '\\\\',
						$content
					);
				}
			}

			// php-font-lib's getFontType() reads the font subtype from a FIXED
			// namespace index: explode('\\', get_class($this))[1]. Prefixing adds
			// two leading segments (WCPOS\Vendor), so [1] becomes "Vendor" instead
			// of "TrueType"/"OpenType". Rewrite it to the prefix-independent
			// penultimate segment (always the subtype, before the trailing class).
			if ( false !== strpos( $filePath, 'php-font-lib/src/FontLib/TrueType/File.php' ) ) {
				$content = str_replace(
					'return $class_parts[1];',
					'return $class_parts[count($class_parts) - 2];',
					$content
				);
			}

			// OptionsResolver's trigger_deprecation() calls survive the scoper
			// unqualified, so PHP's namespace fallback resolves them to the
			// GLOBAL function — defined only when some other plugin happens to
			// ship symfony/deprecation-contracts. Fully qualify them against the
			// prefixed definition (vendor_prefixed/symfony/deprecation-contracts/
			// function.php, required eagerly by woocommerce-pos.php) so the
			// deprecation paths can never fatal. The definition file itself is
			// left alone: the negative lookbehind skips declarations and
			// already-qualified calls.
			if ( false !== strpos( $filePath, 'symfony/options-resolver' ) ) {
				$patched = preg_replace(
					'/(?<![\\\\\\w])trigger_deprecation\\(/',
					'\\WCPOS\\Vendor\\trigger_deprecation(',
					$content
				);
				if ( null !== $patched ) {
					$content = $patched;
				}
			}

			return $content;
		},
	),

	'whitelist' => array(),

	// PHP 8.4 added the native http_get_last_response_headers() /
	// http_clear_last_response_headers() functions, but php-scoper 0.16's
	// bundled symbol list predates PHP 8.4 so it doesn't recognise them as
	// built-ins and prefixes Dompdf's calls into WCPOS\Vendor\ — where no such
	// functions exist — fataling on PHP 8.4+. Declare them internal so calls
	// resolve to the global functions; Dompdf only invokes them behind a
	// version_compare( PHP_VERSION, '8.4.0', '>=' ) guard, so they are always
	// defined where they run.
	'exclude-functions' => array(
		'http_get_last_response_headers',
		'http_clear_last_response_headers',
	),

	// Same story for constants: IMAGETYPE_SVG is new in PHP 8.5, so the stale
	// symbol list lets the scoper rewrite Dompdf's defined('IMAGETYPE_SVG')
	// check into the WCPOS\Vendor namespace, where it is always false — which
	// silently disables Dompdf's native SVG getimagesize() handling.
	'exclude-constants' => array(
		'IMAGETYPE_SVG',
	),
);
