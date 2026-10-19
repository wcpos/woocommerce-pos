<?php
/**
 * Free's one register, held in an option.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Free holds exactly one register, with no store, in the autoloaded `wcpos_register`
 * option: `{ register: row, retired: { uuid: row } }`. Retired rows keep the names of
 * registers that sessions, closures and receipts still reference.
 */
final class Option_Register_Repository implements Register_Repository {
	/** Option name. */
	public const OPTION = 'wcpos_register';

	/**
	 * Read one register row.
	 *
	 * @param string $id Register UUID.
	 */
	public function get( string $id ): ?array {
		$row = self::read()['register'] ?? null;
		return is_array( $row ) && ( $row['id'] ?? null ) === $id ? $row : null;
	}

	/**
	 * List the register when it is in the requested store scope.
	 *
	 * @param array $args Filters.
	 */
	public function list( array $args ): array {
		$row = self::read()['register'] ?? null;
		return is_array( $row ) && self::in_scope( $row, $args ) ? array( $row ) : array();
	}

	/**
	 * Store the register; a second, different register is refused.
	 *
	 * @param array $row Register row with its id.
	 * @throws \RuntimeException When the option holds another register or the write fails.
	 */
	public function insert( array $row ): void {
		$option = self::read();
		$held = $option['register']['id'] ?? null;
		if ( null !== $held && $held !== $row['id'] ) {
			Logger::warning(
				'Register create refused: Free holds one register',
				array(
					'register_id' => $held,
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Free holds one register.' );
		}
		$option['register'] = array(
			'id' => $row['id'],
			'name' => $row['name'],
			// Free's register has no store; the app filters out a register in another store.
			'store_id' => null,
			'default_float' => $row['default_float'] ?? null,
			'created_at_gmt' => $row['created_at_gmt'],
		);
		self::write( $option );
	}

	/**
	 * Change the name or default float; store and status are not Free's to set.
	 *
	 * @param string $id Register UUID.
	 * @param array  $fields Fields to change.
	 * @throws \RuntimeException When the register is not held or the write fails.
	 */
	public function update( string $id, array $fields ): void {
		$option = self::read();
		if ( null === $this->get( $id ) ) {
			throw new \RuntimeException( 'Register write failed.' );
		}
		$option['register'] = array_replace( $option['register'], array_intersect_key( $fields, array_flip( array( 'name', 'default_float' ) ) ) );
		self::write( $option );
	}

	/**
	 * Whether the option holds this register.
	 *
	 * @param string $id Register UUID.
	 */
	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * The name a retired register last had.
	 *
	 * @param string $id Register UUID.
	 */
	public function retired_name( string $id ): ?string {
		$name = self::read()['retired'][ $id ]['name'] ?? null;
		return is_string( $name ) ? $name : null;
	}

	/**
	 * Whether a row matches a `store_id` filter: an int or null, or a list narrowed by
	 * Store_Reach where ints are stores and a null member is the unassigned rows.
	 *
	 * @param array $row Register row.
	 * @param array $args Filters.
	 */
	public static function in_scope( array $row, array $args ): bool {
		if ( ! array_key_exists( 'store_id', $args ) ) {
			return true;
		}
		$store = empty( $row['store_id'] ) ? null : (int) $row['store_id'];
		if ( ! is_array( $args['store_id'] ) ) {
			return ( null === $args['store_id'] ? null : (int) $args['store_id'] ) === $store;
		}
		return null === $store ? in_array( null, $args['store_id'], true ) : in_array( $store, array_map( 'intval', array_filter( $args['store_id'], 'is_scalar' ) ), true );
	}

	/** Drop the cached option so the next read sees what another request wrote. */
	public static function forget_cached(): void {
		foreach ( array( self::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}

	/** Read the option as an array. */
	private static function read(): array {
		$option = get_option( self::OPTION, array() );
		return is_array( $option ) ? $option : array();
	}

	/**
	 * Write the option, autoloaded, and confirm it reads back.
	 *
	 * @param array $option Whole option value.
	 * @throws \RuntimeException When the write fails.
	 */
	private static function write( array $option ): void {
		// False also means unchanged, so read back rather than trust the return.
		if ( ! update_option( self::OPTION, $option, true ) && self::read() !== $option ) {
			Logger::warning(
				'Register write refused: storage operation failed',
				array(
					'register_id' => $option['register']['id'] ?? null,
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Register write failed.' );
		}
	}
}
