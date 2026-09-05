<?php
/**
 * Payment capture-mode registry.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WCPOS\WooCommercePOS\Logger;

/**
 * Registers and lazily instantiates capture-mode handlers.
 *
 * Keys are a bare mode (`manual`, `webview`) or, for modes several providers
 * share, provider-scoped `<mode>:<provider>` (`device:stripe`, `device:square`)
 * so two terminal integrations never fight over one slot. Dispatch resolves
 * the scoped key first, then the bare mode.
 */
class Capture_Mode_Registry {
	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered handler classes by key (`<mode>` or `<mode>:<provider>`).
	 *
	 * @var array<string, string>
	 */
	private $handlers = array();

	/**
	 * Instantiated handlers by key.
	 *
	 * @var array<string, Capture_Mode_Handler_Interface>
	 */
	private $instances = array();

	/** Register Free's built-in handlers on first use. */
	private function __construct() {
		$this->register( 'manual', Manual_Handler::class );
		$this->register( 'webview', Webview_Handler::class );
	}

	/** Get the shared registry. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a handler class, replacing an earlier class for the mode.
	 *
	 * @param string $mode  Capture mode.
	 * @param string $class Handler class name.
	 */
	public function register( string $mode, string $class ): void {
		if ( ! class_exists( $class ) || ! is_subclass_of( $class, Capture_Mode_Handler_Interface::class ) ) {
			Logger::log( sprintf( 'Ignored invalid WCPOS capture-mode handler "%s" for mode "%s".', $class, $mode ) );
			return;
		}

		if ( isset( $this->handlers[ $mode ] ) ) {
			Logger::log( sprintf( 'Replaced WCPOS capture-mode handler for mode "%s".', $mode ) );
		}

		$this->handlers[ $mode ] = $class;
		unset( $this->instances[ $mode ] );
	}

	/**
	 * Whether a mode has a registered handler.
	 *
	 * @param string $mode Capture mode.
	 */
	public function has( string $mode ): bool {
		return isset( $this->handlers[ $mode ] );
	}

	/**
	 * Get the shared handler instance for a mode.
	 *
	 * @param string $mode Capture mode.
	 */
	public function get( string $mode ): ?Capture_Mode_Handler_Interface {
		if ( ! $this->has( $mode ) ) {
			return null;
		}
		if ( ! isset( $this->instances[ $mode ] ) ) {
			$class = $this->handlers[ $mode ];
			try {
				$this->instances[ $mode ] = new $class();
			} catch ( \Throwable $throwable ) {
				Logger::log( sprintf( 'Could not instantiate WCPOS capture-mode handler for mode "%s".', $mode ) );
				return null;
			}
		}

		return $this->instances[ $mode ];
	}

	/**
	 * Resolve the handler for a mode, preferring a provider-scoped registration.
	 *
	 * @param string      $mode     Capture mode (bare, as the descriptor and the ledger row carry it).
	 * @param string|null $provider Provider family, when known.
	 */
	public function resolve( string $mode, ?string $provider = null ): ?Capture_Mode_Handler_Interface {
		if ( null !== $provider && '' !== $provider && $this->has( $mode . ':' . $provider ) ) {
			return $this->get( $mode . ':' . $provider );
		}

		return $this->get( $mode );
	}

	/** Return registered keys. */
	public function modes(): array {
		return array_keys( $this->handlers );
	}
}
