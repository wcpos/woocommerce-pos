<?php
/**
 * Capture mode registry tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use ReflectionClass;
use WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Handler_Interface;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WP_UnitTestCase;

/** Capture mode registry tests. */
class Test_Capture_Mode_Registry extends WP_UnitTestCase {
	/** Restore the process-global registry after every test. */
	public function tearDown(): void {
		$reflection = new ReflectionClass( Capture_Mode_Registry::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		parent::tearDown();
	}

	/** Free registers its built-in modes lazily. */
	public function test_registry_registers_free_modes_by_default(): void {
		// Arrange / Act.
		$registry = Capture_Mode_Registry::instance();

		// Assert.
		$this->assertTrue( $registry->has( 'manual' ) );
		$this->assertTrue( $registry->has( 'webview' ) );
		$this->assertInstanceOf( Capture_Mode_Handler_Interface::class, $registry->get( 'manual' ) );
		$this->assertInstanceOf( Capture_Mode_Handler_Interface::class, $registry->get( 'webview' ) );
	}

	/** A later registration replaces the earlier handler. */
	public function test_registry_last_registration_wins(): void {
		// Arrange / Act.
		wcpos_register_capture_mode( 'manual', Registry_Test_Handler::class );

		// Assert.
		$this->assertInstanceOf( Registry_Test_Handler::class, Capture_Mode_Registry::instance()->get( 'manual' ) );
	}

	/** Invalid handler classes are ignored. */
	public function test_provider_scoped_keys_coexist_and_resolve_to_their_own_handler(): void {
		// Arrange.
		$registry = Capture_Mode_Registry::instance();
		wcpos_register_capture_mode( 'device:stripe', Registry_Test_Handler::class );
		wcpos_register_capture_mode( 'device:square', Registry_Second_Test_Handler::class );

		// Act / Assert.
		$this->assertTrue( $registry->has( 'device:stripe' ) );
		$this->assertTrue( $registry->has( 'device:square' ) );
		$this->assertInstanceOf( Registry_Test_Handler::class, $registry->resolve( 'device', 'stripe' ) );
		$this->assertInstanceOf( Registry_Second_Test_Handler::class, $registry->resolve( 'device', 'square' ) );
	}

	public function test_resolve_falls_back_to_the_bare_mode_or_null(): void {
		// Arrange.
		$registry = Capture_Mode_Registry::instance();
		wcpos_register_capture_mode( 'device:stripe', Registry_Test_Handler::class );

		// Act / Assert: no bare `device` → an unknown provider resolves to nothing.
		$this->assertNull( $registry->resolve( 'device', 'unknown' ) );
		$this->assertNull( $registry->resolve( 'device' ) );

		// A bare registration is the fallback for any provider without its own.
		wcpos_register_capture_mode( 'device', Registry_Second_Test_Handler::class );
		$this->assertInstanceOf( Registry_Second_Test_Handler::class, $registry->resolve( 'device', 'unknown' ) );
		$this->assertInstanceOf( Registry_Test_Handler::class, $registry->resolve( 'device', 'stripe' ) );

		// Free's modes resolve without a provider.
		$this->assertInstanceOf( Capture_Mode_Handler_Interface::class, $registry->resolve( 'manual' ) );
	}

	public function test_registry_ignores_class_without_interface(): void {
		// Arrange / Act.
		Capture_Mode_Registry::instance()->register( 'invalid', \stdClass::class );

		// Assert.
		$this->assertFalse( Capture_Mode_Registry::instance()->has( 'invalid' ) );
	}
}

/** Test-only capture mode handler. */
class Registry_Test_Handler extends Abstract_Capture_Mode_Handler {
	/** Return the minimal descriptor fragments needed by the interface. */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array();
	}
}

class Registry_Second_Test_Handler extends Abstract_Capture_Mode_Handler {
	/** Return the minimal descriptor fragments needed by the interface. */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array();
	}
}
