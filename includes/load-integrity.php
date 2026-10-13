<?php
/**
 * Registers the load-integrity fallback autoloader.
 *
 * Loaded through Composer `files`, so it runs inside whichever vendor/autoload.php
 * boots WCPOS: the free plugin's own, or Pro's merged one with this plugin vendored.
 * __DIR__ is that copy's includes/, so the fallback resolves against the files that
 * are actually running. Free's bootstrap only reaches its autoloader after the
 * Pro-is-active bail-out, so the two copies never both register.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

if ( ! class_exists( Load_Integrity::class, false ) ) {
	require_once __DIR__ . '/Load_Integrity.php';
}
Load_Integrity::register( __DIR__ );
