<?php
/**
 * Tests for plugin uninstall cleanup.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Uninstall cleanup tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Uninstall extends WP_UnitTestCase {
	/**
	 * The top-level uninstall script exits, so exercise it in a stubbed
	 * subprocess that models the multisite WordPress functions it calls.
	 */
	public function test_multisite_uninstall_deletes_anon_id_from_each_site(): void {
		$script = <<<'PHP'
<?php
$GLOBALS['current_blog_id'] = 1;
$GLOBALS['deleted'] = array();
$GLOBALS['switched'] = array();

define( 'WP_UNINSTALL_PLUGIN', 'woocommerce-pos/woocommerce-pos.php' );

function is_multisite() {
	return true;
}

function get_sites( $args = array() ) {
	return array( 1, 2, 3 );
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['switched'][]     = $blog_id;
	$GLOBALS['current_blog_id'] = $blog_id;
}

function restore_current_blog() {
	$GLOBALS['current_blog_id'] = 1;
}

function delete_option( $name ) {
	$GLOBALS['deleted'][] = array(
		'blog_id' => $GLOBALS['current_blog_id'],
		'name'    => $name,
	);
}

require getcwd() . '/uninstall.php';

echo json_encode(
	array(
		'deleted'  => $GLOBALS['deleted'],
		'switched' => $GLOBALS['switched'],
	)
);
PHP;

		$process = proc_open(
			PHP_BINARY,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 2 )
		);

		$this->assertIsResource( $process );

		fwrite( $pipes[0], $script );
		fclose( $pipes[0] );

		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		$this->assertSame( 0, $exit_code, $errors );

		$result = json_decode( $output, true );

		$this->assertSame(
			array(
				array(
					'blog_id' => 1,
					'name'    => 'wcpos_anon_id',
				),
				array(
					'blog_id' => 2,
					'name'    => 'wcpos_anon_id',
				),
				array(
					'blog_id' => 3,
					'name'    => 'wcpos_anon_id',
				),
			),
			$result['deleted']
		);
		$this->assertSame( array( 1, 2, 3 ), $result['switched'] );
	}
}
