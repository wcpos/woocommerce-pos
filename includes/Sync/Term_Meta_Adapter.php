<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Adapts a WordPress TERM (category / brand — taxonomy product_cat / product_brand)
 * to the small WC_Data meta contract that Pos_Uuid::ensure_uuid and
 * prune_duplicate_uuid_meta duck-type on (get_meta_data / update_meta_data /
 * save_meta_data / delete_meta_data_by_mid). WP_Term is NOT a WC_Data object and
 * get_term_meta returns bare scalar values with no meta-id, so the uuid stamping
 * logic cannot be reused on a term directly — this thin adapter over wp_termmeta
 * lets the audited ensure_uuid/dedup path stamp terms unchanged, exactly as
 * WC_Customer (which IS WC_Data) let it stamp customers.
 *
 * get_meta_data surfaces the per-row meta_id (queried from wp_termmeta), which
 * prune_duplicate_uuid_meta needs to delete duplicate uuid metas by mid.
 * update_meta_data has WC_Data's replace-or-add semantics (a single key holds one
 * value), applied on save via update_term_meta — so re-keying a collided term
 * REPLACES its uuid rather than leaving a second one behind.
 */
final class Term_Meta_Adapter {
	/**
	 * @var array<string,mixed> key => value, applied (replace-or-add) on save
	 */
	private array $pending = array();

	/**
	 * @var int
	 */
	private $term_id;

	public function __construct( int $term_id ) {
		$this->term_id = $term_id;
	}

	public function get_id(): int {
		return $this->term_id;
	}

	/** The term's _woocommerce_pos_uuid metas as objects carrying ->id (meta_id),
	 * ->key, ->value — meta_id ASC so first-valid-wins matches the bulk read. */
	public function get_meta_data(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC",
				$this->term_id,
				Pos_Uuid::META_KEY
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}
			$out[] = (object) array(
				'id'    => (int) ( $row['meta_id'] ?? 0 ),
				'key'   => Pos_Uuid::META_KEY,
				'value' => $row['meta_value'] ?? '',
			);
		}

		return $out;
	}

	public function update_meta_data( string $key, $value ): void {
		$this->pending[ $key ] = $value; // replace-or-add (matches WC_Data)
	}

	public function save_meta_data(): void {
		if ( \function_exists( 'update_term_meta' ) ) {
			foreach ( $this->pending as $key => $value ) {
				// update_term_meta() rewrites EVERY matching row to the new value
				// without collapsing extras — a collision re-key on a term with
				// duplicate uuid rows would keep the duplicates. Enforce the
				// one-row contract: delete all rows for the key, then add one.
				if ( \function_exists( 'get_term_meta' ) && \function_exists( 'delete_term_meta' )
					&& \count( (array) get_term_meta( $this->term_id, $key, false ) ) > 1 ) {
					delete_term_meta( $this->term_id, $key );
					add_term_meta( $this->term_id, $key, $value, true );
					continue;
				}
				update_term_meta( $this->term_id, $key, $value );
			}
		}
		$this->pending = array();
	}

	public function delete_meta_data_by_mid( $mid ): void {
		if ( $mid && \function_exists( 'delete_metadata_by_mid' ) ) {
			delete_metadata_by_mid( 'term', $mid );
		}
	}
}
