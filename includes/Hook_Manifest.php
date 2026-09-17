<?php
/**
 * Ordered WCPOS bootstrap hook installation.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/** Installs rows declared by Init::hook_rows(); registrars keep their own internals. */
final class Hook_Manifest {
	/**
	 * Install named hooks, or invoke null-hook registrar callbacks immediately.
	 *
	 * @param array $rows Ordered hook rows.
	 */
	public static function install( array $rows ): void {
		self::validate( $rows );
		foreach ( $rows as $row ) {
			if ( null === $row['hook'] ) {
				( $row['callback'] )();
			} else {
				// WordPress actions and filters share the same registration primitive.
				add_filter( $row['hook'], $row['callback'], $row['priority'], $row['args'] );
			}
		}
	}

	/**
	 * Require the declared metadata, without executing any callbacks.
	 *
	 * @param array $rows Ordered hook rows.
	 * @throws \InvalidArgumentException When required metadata is absent or invalid.
	 */
	public static function validate( array $rows ): void {
		foreach ( $rows as $index => $row ) {
			foreach ( array( 'hook', 'callback', 'priority', 'args', 'reason', 'phase' ) as $field ) {
				if ( ! array_key_exists( $field, $row ) ) {
					throw new \InvalidArgumentException( esc_html( 'Hook manifest row ' . $index . ' is missing ' . $field . '.' ) );
				}
			}
			if ( ! \in_array( $row['phase'], array( 'pre-latch', 'sync-latched', 'post-latch' ), true ) ) {
				throw new \InvalidArgumentException( esc_html( 'Hook manifest row ' . $index . ' requires a valid phase.' ) );
			}
			foreach ( array( 'priority', 'args' ) as $field ) {
				if ( ! \is_int( $row[ $field ] ) ) {
					throw new \InvalidArgumentException( esc_html( 'Hook manifest row ' . $index . ' requires an integer ' . $field . '.' ) );
				}
			}
			if ( ! \is_callable( $row['callback'] ) ) {
				throw new \InvalidArgumentException( esc_html( 'Hook manifest row ' . $index . ' requires a callable callback.' ) );
			}
			if ( ! \is_string( $row['reason'] ) || '' === trim( $row['reason'] ) ) {
				throw new \InvalidArgumentException( esc_html( 'Hook manifest row ' . $index . ' requires a non-empty reason.' ) );
			}
		}
	}
}
