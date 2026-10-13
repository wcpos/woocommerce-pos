<?php
/**
 * Guard the Ledger's production write boundary.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

/** Production callers must use Ledger verbs rather than raw persistence. */
class Test_Ledger_Sole_Writer extends \WP_UnitTestCase {
	/** Report every raw Ledger save outside the Ledger itself. */
	public function test_no_production_code_outside_the_ledger_calls_save_on_it(): void {
		// Arrange.
		$root = dirname( __DIR__, 4 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		$hits = array();
		// Act.
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || $root . '/Payments/Contract/Ledger.php' === $file->getPathname() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $line => $source ) {
				if ( preg_match( '/(?:\$ledger|Ledger::instance\(\s*\))\s*->\s*save\s*\(/', $source ) ) {
					$hits[] = $file->getPathname() . ':' . ( $line + 1 );
				}
			}
		}
		// Assert.
		$this->assertSame( array(), $hits, implode( "\n", $hits ) );
	}
}
