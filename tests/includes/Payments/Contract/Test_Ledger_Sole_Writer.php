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
			foreach ( self::raw_save_lines( (string) file_get_contents( $file->getPathname() ) ) as $line ) {
				$hits[] = $file->getPathname() . ':' . $line;
			}
		}
		// Assert.
		$this->assertSame( array(), $hits, implode( "\n", $hits ) );
	}

	/** A call split across lines is still a raw save. */
	public function test_scanner_catches_a_multiline_raw_save(): void {
		// Arrange.
		$source = "<?php\n\$rows = array();\nLedger::instance()\n\t->save(\n\t\t\$order,\n\t\t\$rows\n\t);\n\$ledger\n\t->save( \$order, \$rows );\n\$ledger->find( \$order, \$id );\n";
		// Act.
		$lines = self::raw_save_lines( $source );
		// Assert.
		$this->assertSame( array( 3, 8 ), $lines );
	}

	/**
	 * Line numbers of every `$ledger->save(` / `Ledger::instance()->save(` call in a source, across line breaks.
	 *
	 * @param string $source PHP source.
	 * @return int[]
	 */
	private static function raw_save_lines( string $source ): array {
		$lines = array();
		if ( preg_match_all( '/(?:\$ledger|Ledger::instance\(\s*\))\s*->\s*save\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$lines[] = substr_count( $source, "\n", 0, $match[1] ) + 1;
			}
		}
		return $lines;
	}
}
