<?php
/**
 * Owned table installation tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Store;

/** Tests request-local installation checks across sites. */
class Test_Owned_Table extends \WP_UnitTestCase {
	/** The first site's memo must not skip the second site's table check. */
	public function test_ensure_installed_checks_each_site_table_once(): void {
		global $wpdb;

		// Arrange: memoise the real table, then intercept the second site's probe.
		$store = new Register_Store();
		$store->ensure_installed();
		$real_prefix = $wpdb->prefix;
		$alt_table   = $real_prefix . 'site2' . Register_Store::TABLE;
		$seen        = array();
		$filter      = static function ( $query ) use ( &$seen, $alt_table ) {
			if ( false !== strpos( $query, 'site2' ) ) {
				$seen[] = $query;
				return "SELECT '" . $alt_table . "'";
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$wpdb->prefix = $real_prefix . 'site2';

			// Act.
			$store->ensure_installed();
			$store->ensure_installed();
		} finally {
			$wpdb->prefix = $real_prefix;
			remove_filter( 'query', $filter );
		}

		// Assert: checked once, neither skipped nor repeated.
		$this->assertCount( 1, $seen );
	}
}
