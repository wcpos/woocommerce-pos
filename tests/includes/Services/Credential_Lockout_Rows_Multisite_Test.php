<?php
/**
 * Credential-attempt live-row ceiling on a network.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Credential_Lockout;
use WP_UnitTestCase;

/**
 * The live-row ceiling is counted in each site's own options table.
 *
 * @group multisite
 * @expectedDeprecated wpmu_new_blog
 */
class Credential_Lockout_Rows_Multisite_Test extends WP_UnitTestCase {
	/**
	 * Original REMOTE_ADDR, or null when it was unset.
	 *
	 * @var string|null
	 */
	private $remote_addr;

	/** Set the connecting address. */
	public function setUp(): void {
		parent::setUp();
		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test-owned request fixture.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.41';
	}

	/** Restore the connecting address. */
	public function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tearDown();
	}

	/**
	 * Each site's rows are counted against its own ceiling.
	 */
	public function test_rows_ceiling_is_counted_per_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network: run with .phpunit.multisite.xml.dist.' );
		}

		// Arrange.
		Credential_Lockout_Rows_Test::fill_live( Credential_Lockout_Rows_Test::constant( 'MAX_LIVE_ROWS' ) );
		$site2 = self::factory()->blog->create();

		// Act / Assert: site 2 has room.
		switch_to_blog( $site2 );
		$admitted = Credential_Lockout::reserve_attempt( 'unknown-d' );
		restore_current_blog();
		$this->assertNotNull( $admitted );

		// Act / Assert: site 1 is full.
		$this->assertNull( Credential_Lockout::reserve_attempt( 'unknown-e' ) );
	}
}
