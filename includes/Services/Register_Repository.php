<?php
/**
 * Register storage seam.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Where register rows live. Free binds Option_Register_Repository; Pro binds its own
 * through the `woocommerce_pos_register_repository` filter. Rows are unnormalised:
 * `id, name, store_id, default_float, created_at_gmt` (`Y-m-d H:i:s`).
 */
interface Register_Repository {
	/**
	 * Read one register row.
	 *
	 * @param string $id Register UUID.
	 */
	public function get( string $id ): ?array;

	/**
	 * List register rows, honouring a `store_id` filter (int, null, or a narrowed list).
	 * Impure: another request may store a register between two calls.
	 *
	 * @phpstan-impure
	 *
	 * @param array $args Filters.
	 */
	public function list( array $args ): array;

	/**
	 * Store a new register row.
	 *
	 * @param array $row Register row with its id.
	 * @throws \RuntimeException When the row cannot be stored.
	 */
	public function insert( array $row ): void;

	/**
	 * Change admin-owned fields of a stored register.
	 *
	 * @param string $id Register UUID.
	 * @param array  $fields Fields to change.
	 * @throws \RuntimeException When the row cannot be stored.
	 */
	public function update( string $id, array $fields ): void;

	/**
	 * Whether a register is stored.
	 *
	 * @param string $id Register UUID.
	 */
	public function exists( string $id ): bool;

	/**
	 * The name a retired register last had, so history still resolves it.
	 *
	 * @param string $id Register UUID.
	 */
	public function retired_name( string $id ): ?string;
}
