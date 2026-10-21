<?php
/**
 * PHPUnit extension that resets the re-entrant save tripwire before every test.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use PHPUnit\Runner\BeforeTestHook;

/**
 * Clears `Save_Reentry_Guard`'s per-test state at the test boundary.
 *
 * Registered in `.phpunit.xml.dist` and `.phpunit.multisite.xml.dist`. It runs for every
 * test class, whichever base class it extends, so a trip in one test cannot hide a trip
 * in the next.
 */
final class Save_Reentry_Guard_Reset implements BeforeTestHook {
	/**
	 * Called by PHPUnit before each test.
	 *
	 * @param string $test Test name.
	 */
	public function executeBeforeTest( string $test ): void {
		if ( class_exists( Save_Reentry_Guard::class, false ) ) {
			Save_Reentry_Guard::reset();
		}
	}
}
