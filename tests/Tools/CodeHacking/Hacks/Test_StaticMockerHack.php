<?php
/**
 * Tests for StaticMockerHack.
 *
 * @package WCPOS\WooCommercePOS\Tests\Tools\CodeHacking
 */

namespace WCPOS\WooCommercePOS\Tests\Tools\CodeHacking\Hacks;

use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\StaticMockerHack;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Static method mock rewrite tests.
 */
class Test_StaticMockerHack extends WP_UnitTestCase {
	/**
	 * PHP 8+ emits a single T_NAME_FULLY_QUALIFIED token, which must remain intact.
	 */
	public function test_hack_preserves_php8_fully_qualified_static_call(): void {
		$source = '<?php \\WCPOS\\WooCommercePOS\\Sync\\Pos_Uuid::register_hooks();';
		$hack   = StaticMockerHack::get_hack_instance();

		$this->assertSame( $source, $hack->hack( $source, 'fixture.php' ) );
	}

	/**
	 * PHP 7.4 splits a qualified name into T_NS_SEPARATOR/T_STRING tokens. The
	 * terminal mockable short name must not be rewritten inside that chain.
	 */
	public function test_hack_preserves_php74_qualified_static_call_tokens(): void {
		$tokens = array(
			array( T_OPEN_TAG, '<?php ', 1 ),
			array( T_NS_SEPARATOR, '\\', 1 ),
			array( T_STRING, 'WCPOS', 1 ),
			array( T_NS_SEPARATOR, '\\', 1 ),
			array( T_STRING, 'WooCommercePOS', 1 ),
			array( T_NS_SEPARATOR, '\\', 1 ),
			array( T_STRING, 'Sync', 1 ),
			array( T_NS_SEPARATOR, '\\', 1 ),
			array( T_STRING, 'Pos_Uuid', 1 ),
			array( T_DOUBLE_COLON, '::', 1 ),
			array( T_STRING, 'register_hooks', 1 ),
			'(',
			')',
			';',
		);
		$hack   = StaticMockerHack::get_hack_instance();
		$method = new ReflectionMethod( $hack, 'hack_tokens' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame(
			'<?php \\WCPOS\\WooCommercePOS\\Sync\\Pos_Uuid::register_hooks();',
			$method->invoke( $hack, $tokens )
		);
	}

	/**
	 * Skipping qualified names must not disable the short-name mock seam.
	 */
	public function test_hack_still_rewrites_unqualified_mockable_static_call_tokens(): void {
		$tokens = array(
			array( T_OPEN_TAG, '<?php ', 1 ),
			array( T_STRING, 'Pos_Uuid', 1 ),
			array( T_DOUBLE_COLON, '::', 1 ),
			array( T_STRING, 'ensure_uuid', 1 ),
			'(',
			')',
			';',
		);
		$hack   = StaticMockerHack::get_hack_instance();
		$method = new ReflectionMethod( $hack, 'hack_tokens' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame(
			'<?php \\Automattic\\WooCommerce\\Testing\\Tools\\CodeHacking\\Hacks\\StaticMockerHack::invoke__ensure_uuid__for__Pos_Uuid();',
			$method->invoke( $hack, $tokens )
		);
	}
}
