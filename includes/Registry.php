<?php
/**
 * Global registry for WCPOS.
 *
 * - stores instances of classes that may be required to remove_action or remove_filter.
 *
 * @author  Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Registry {
	/**
	 * Singleton instance.
	 *
	 * @var Registry
	 */
	private static $instance = null;

	/**
	 * Storage for the registry.
	 *
	 * @var array
	 */
	private $storage = array();

	/**
	 * Gets the singleton instance of the registry.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers a new instance.
	 *
	 * @param string $key
	 * @param object $object
	 */
	public function set( $key, $object ): void {
		$this->storage[ $key ] = $object;
	}

	/**
	 * Retrieves an instance by key.
	 *
	 * @param string $key
	 */
	public function get( $key ) {
		return $this->storage[ $key ] ?? null;
	}
}
