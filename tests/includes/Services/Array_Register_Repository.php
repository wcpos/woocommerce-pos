<?php
/**
 * In-memory register repository for tests that need more than one register.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Option_Register_Repository;
use WCPOS\WooCommercePOS\Services\Register_Repository;

/** Holds any number of registers for one test, the way Pro's repository does. */
final class Array_Register_Repository implements Register_Repository {
	/**
	 * Rows by id.
	 *
	 * @var array<string,array>
	 */
	public $rows = array();

	/**
	 * Retired names by id.
	 *
	 * @var array<string,string>
	 */
	public $retired = array();

	/**
	 * Read one row.
	 *
	 * @param string $id Register UUID.
	 */
	public function get( string $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * List rows in the requested store scope.
	 *
	 * @param array $args Filters.
	 */
	public function list( array $args ): array {
		return array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $args ) {
					return Option_Register_Repository::in_scope( $row, $args );
				}
			)
		);
	}

	/**
	 * Store a row.
	 *
	 * @param array $row Register row.
	 */
	public function insert( array $row ): void {
		$this->rows[ $row['id'] ] = $row;
	}

	/**
	 * Change fields, store included.
	 *
	 * @param string $id Register UUID.
	 * @param array  $fields Fields.
	 */
	public function update( string $id, array $fields ): void {
		$this->rows[ $id ] = array_replace( $this->rows[ $id ], $fields );
	}

	/**
	 * Whether a row is held.
	 *
	 * @param string $id Register UUID.
	 */
	public function exists( string $id ): bool {
		return isset( $this->rows[ $id ] );
	}

	/**
	 * A retired name.
	 *
	 * @param string $id Register UUID.
	 */
	public function retired_name( string $id ): ?string {
		return $this->retired[ $id ] ?? null;
	}

	/** Retired ids. */
	public function retired_ids(): array {
		return array_keys( $this->retired );
	}

	/**
	 * Remove a register the way a deactivated Pro leaves its registers unknown to Free.
	 *
	 * @param string      $id Register UUID.
	 * @param string|null $retired_name Name kept in the retired map, if any.
	 */
	public function forget( string $id, ?string $retired_name = null ): void {
		unset( $this->rows[ $id ] );
		if ( null !== $retired_name ) {
			$this->retired[ $id ] = $retired_name;
		}
	}
}
