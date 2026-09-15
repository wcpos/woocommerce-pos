<?php
/**
 * Enforce that changing a bundled gallery template bumps its registry version.
 *
 * An installed gallery template is a COPY in the merchant's database. When the bundled markup
 * changes, that copy does not — the only thing that ever tells anyone is the `version` in
 * Gallery_Registry, compared against the `_template_gallery_version` recorded at install.
 *
 * So a version nobody bumps is worse than no version at all: it reports "up to date" on a
 * template that is not, and it does so silently. That is the failure this guard exists to stop,
 * and it is why the rule is mechanical rather than a line in a contributing doc.
 *
 * Deliberately dumb, in the spirit of scripts/lane-coverage.php: it compares two git trees and
 * refuses to be clever about intent. Both sides are read from git rather than from a checked-in
 * baseline, because a baseline can be edited in the same commit that breaks the rule.
 *
 * Usage:
 *   php scripts/gallery-template-versions.php <base-ref> [head-ref]
 *
 * Exit codes: 0 pass, 1 a changed template did not get a version bump, 2 bad invocation.
 *
 * @package WCPOS\WooCommercePOS
 */

declare( strict_types=1 );

const REGISTRY_PATH = 'includes/Templates/Gallery_Registry.php';
const GALLERY_DIR   = 'templates/gallery/';
/** The extensions a gallery template's content file may use (mirrors Templates::GALLERY_CONTENT_EXTENSIONS). */
const CONTENT_EXTENSIONS = array( 'html', 'php', 'xml' );

/**
 * Run a git command and return its stdout, or null when git itself failed.
 *
 * Uses exec() for the exit status rather than shell_exec(), which returns null BOTH for a failed
 * command and for one that simply printed nothing — and "no gallery template changed" is exactly
 * the empty-output case this guard sees most often. Conflating the two made the guard exit 2 on
 * every clean run.
 *
 * @param array<int, string> $args Arguments after `git`.
 *
 * @return string|null Stdout, or null when git exited non-zero.
 */
function git( array $args ): ?string {
	$command = 'git ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>/dev/null';
	$lines   = array();
	$status  = 1;
	exec( $command, $lines, $status );

	return 0 === $status ? implode( "\n", $lines ) : null;
}

/**
 * Keep only the paths that are actually gallery templates.
 *
 * `templates/gallery/preview-data/invoice.json` shares a basename with the `invoice` template but
 * is never copied into a merchant's database. Demanding a bump for it would be a false positive
 * with real consequences: the bump marks every edited invoice copy outdated and silently rewrites
 * every untouched one.
 *
 * @param array<int, string> $paths Repository-relative paths.
 *
 * @return array<int, string> The subset that are top-level gallery content files.
 */
function gallery_content_files( array $paths ): array {
	return array_filter(
		$paths,
		static function ( string $path ): bool {
			if ( '' === $path || 0 !== strpos( $path, GALLERY_DIR ) ) {
				return false;
			}
			// Anything deeper than the gallery directory itself is not a template.
			if ( substr_count( $path, '/' ) !== substr_count( GALLERY_DIR, '/' ) ) {
				return false;
			}

			return \in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), CONTENT_EXTENSIONS, true );
		}
	);
}

/**
 * Extract the `version` of every gallery key from a registry file's source.
 *
 * Parsed rather than executed: the registry calls __() and lives inside the plugin's namespace,
 * so evaluating the historical copy would mean booting WordPress twice per run.
 *
 * @param string $source The Gallery_Registry.php source.
 *
 * @return array<string, int> Version keyed by gallery key.
 */
function registry_versions( string $source ): array {
	// Top-level entries sit at exactly three tabs; the nested arrays are deeper, so this cannot
	// mistake an inner key for a template key.
	$parts = preg_split( "/^\t{3}'([a-z0-9-]+)' *=> *array\(/m", $source, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return array();
	}

	$versions = array();
	for ( $i = 1; $i < count( $parts ); $i += 2 ) {
		$key   = $parts[ $i ];
		$chunk = $parts[ $i + 1 ] ?? '';
		// Anchored at exactly four tabs: the entry's OWN fields. An unanchored match would take
		// the first 'version' anywhere in the chunk, including one nested inside preview_data,
		// and silently compare the wrong number.
		//
		// A key that is present but whose version cannot be read maps to null rather than being
		// dropped. Dropping it made the entry indistinguishable from one deleted outright, and the
		// caller waves deletions through — so losing the version field was a way to change a
		// template with no bump and a green check, which is the exact hole this guard plugs.
		$versions[ $key ] = preg_match( "/^\t{4}'version' *=> *(\d+)/m", $chunk, $m )
			? (int) $m[1]
			: null;
	}

	return $versions;
}

/**
 * Run the guard.
 *
 * Separated from the pure parser above so a test can include this file for `registry_versions()`
 * without the guard running against the test's own working tree.
 *
 * @return int The exit code.
 */
function main(): int {
		global $argv;
		$argv = $_SERVER['argv'];
	if ( count( $argv ) < 2 ) {
		fwrite( STDERR, "usage: php scripts/gallery-template-versions.php <base-ref> [head-ref]\n" );
		return 2;
	}

	$base = $argv[1];
	$head = $argv[2] ?? 'HEAD';

	$changed_raw = git( array( 'diff', '--name-only', $base . '...' . $head, '--', GALLERY_DIR ) );
	if ( null === $changed_raw ) {
		fwrite( STDERR, "error: could not diff {$base}...{$head}. Is the base ref fetched?\n" );
		return 2;
	}

	// Only the top-level content files ARE templates. `templates/gallery/preview-data/invoice.json`
// shares a basename with the `invoice` template but is never copied into a merchant's database,
// so demanding a bump for it would be a false positive with real consequences: the bump would
// mark every edited invoice copy outdated and silently rewrite every untouched one.
$changed = array_values( gallery_content_files( array_map( 'trim', explode( "\n", $changed_raw ) ) ) );
	if ( array() === $changed ) {
		echo "No bundled gallery templates changed.\n";
		return 0;
	}

	$base_registry = git( array( 'show', $base . ':' . REGISTRY_PATH ) );
	$head_registry = git( array( 'show', $head . ':' . REGISTRY_PATH ) );
	if ( null === $base_registry || null === $head_registry ) {
		fwrite( STDERR, "error: could not read " . REGISTRY_PATH . " at both refs.\n" );
		return 2;
	}

	$before = registry_versions( $base_registry );
	$after  = registry_versions( $head_registry );

	$failures = array();
	$passes   = array();

	foreach ( $changed as $file ) {
		$key = pathinfo( $file, PATHINFO_FILENAME );

		// A template added in this change has no "before" and needs no bump.
		if ( ! array_key_exists( $key, $before ) ) {
			$passes[] = "{$key}: new template, no bump required";
			continue;
		}

		// A template deleted in this change has nothing left to version.
		if ( ! array_key_exists( $key, $after ) ) {
			$passes[] = "{$key}: removed from the registry";
			continue;
		}

		// Still registered, but its version cannot be read. At runtime registry_version() falls
		// back to 1 for exactly this entry, so every installed copy would report itself current.
		if ( null === $after[ $key ] ) {
			$failures[] = sprintf(
				"%s\n    %s changed and its 'version' in %s is missing or unreadable.",
				$key,
				$file,
				REGISTRY_PATH
			);
			continue;
		}

		// An entry that had no readable version before cannot be compared against; require one now.
		if ( null === $before[ $key ] ) {
			$passes[] = "{$key}: version now readable ({$after[$key]})";
			continue;
		}

		if ( $after[ $key ] > $before[ $key ] ) {
			$passes[] = "{$key}: {$before[$key]} -> {$after[$key]}";
			continue;
		}

		$failures[] = sprintf(
			"%s\n    %s changed but 'version' is still %d in %s.",
			$key,
			$file,
			$after[ $key ],
			REGISTRY_PATH
		);
	}

	foreach ( $passes as $line ) {
		echo "  ok  {$line}\n";
	}

	if ( array() === $failures ) {
		echo "All changed gallery templates carry a version bump.\n";
		return 0;
	}

	fwrite( STDERR, "\nGallery templates changed without a version bump:\n\n" );
	foreach ( $failures as $line ) {
		fwrite( STDERR, "  FAIL  {$line}\n" );
	}
	fwrite(
		STDERR,
		"\nMerchants hold an editable COPY of every gallery template they installed. The registry\n" .
		"version is the only thing that tells them their copy has fallen behind — leave it alone and\n" .
		"they are told their template is current when it is not.\n\n" .
		"Bump 'version' for each key listed above in " . REGISTRY_PATH . ".\n"
	);
	return 1;
}

// Only run when invoked directly, not when included by the test.
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit( main() );
}
