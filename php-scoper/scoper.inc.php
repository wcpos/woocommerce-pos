<?php

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix' => 'WCPOS\\Vendor',

	'finders' => array(
		Finder::create()->files()->in( 'vendor/firebase/php-jwt' )->name( '*.php' ),
		Finder::create()->files()->in( 'vendor/phpfastcache/phpfastcache' )->name( '*.php' ),

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
			$namespaces = 'Dompdf|FontLib|Svg';
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

			return $content;
		},
	),

	'whitelist' => array(),
);
