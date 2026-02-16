<?php
/**
 * Safe repair helpers for duplicated postmeta rows.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Metadata repair service.
 */
class Meta_Data_Repair {
	/**
	 * Remove exact duplicate postmeta rows from POS-touched products/variations.
	 *
	 * This is intentionally conservative:
	 * - scopes to selected post types (defaults: product + variation),
	 * - scopes to POS-touched posts (requires _woocommerce_pos_uuid by default),
	 * - deduplicates only exact (post_id, meta_key, meta_value) matches.
	 *
	 * @param array $args Repair options: dry_run, batch_size, max_batches,
	 *                    post_types, and require_pos_uuid.
	 *
	 * @return array{
	 *     groups_processed:int,
	 *     rows_deleted:int,
	 *     rows_would_delete:int,
	 *     batches_processed:int,
	 *     hit_batch_limit:bool,
	 *     errors:int
	 * }
	 */
	public static function remove_exact_duplicate_postmeta_rows( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'dry_run'          => false,
				'batch_size'       => 500,
				'max_batches'      => 200,
				'post_types'       => array( 'product', 'product_variation' ),
				'require_pos_uuid' => true,
			)
		);

		$batch_size  = max( 1, (int) $args['batch_size'] );
		$max_batches = max( 1, (int) $args['max_batches'] );
		$post_types  = array_values(
			array_filter(
				(array) $args['post_types'],
				'strlen'
			)
		);

		$summary = array(
			'groups_processed' => 0,
			'rows_deleted'     => 0,
			'rows_would_delete' => 0,
			'batches_processed' => 0,
			'hit_batch_limit'  => false,
			'errors'           => 0,
		);

		if ( empty( $post_types ) ) {
			return $summary;
		}

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$summary['batches_processed'] = $batch + 1;

			$groups = self::get_duplicate_groups(
				$post_types,
				$batch_size,
				(bool) $args['require_pos_uuid']
			);

			if ( empty( $groups ) ) {
				return $summary;
			}

			foreach ( $groups as $group ) {
				$summary['groups_processed']++;
				$rows_for_group = max( 0, (int) $group->row_count - 1 );

				if ( $args['dry_run'] ) {
					$summary['rows_would_delete'] += $rows_for_group;
					continue;
				}

				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->postmeta}
						WHERE post_id = %d
							AND meta_key = %s
							AND meta_value = %s
							AND meta_id <> %d",
						(int) $group->post_id,
						(string) $group->meta_key,
						(string) $group->meta_value,
						(int) $group->keep_id
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct repair query.

				if ( false === $deleted ) {
					$summary['errors']++;
				} else {
					$summary['rows_deleted'] += (int) $deleted;
				}
			}

			if ( \count( $groups ) < $batch_size ) {
				return $summary;
			}
		}

		$summary['hit_batch_limit'] = true;

		return $summary;
	}

	/**
	 * Fetch duplicate groups for the current batch.
	 *
	 * @param array $post_types       Post types to process.
	 * @param int   $batch_size       Max groups to return.
	 * @param bool  $require_pos_uuid Require POS UUID marker on post.
	 *
	 * @return array<\stdClass>
	 */
	private static function get_duplicate_groups( array $post_types, int $batch_size, bool $require_pos_uuid ): array {
		global $wpdb;

		$post_type_placeholders = implode( ', ', array_fill( 0, \count( $post_types ), '%s' ) );

		$sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value, COUNT(*) AS row_count, MAX(pm.meta_id) AS keep_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
			WHERE posts.post_type IN ({$post_type_placeholders})";

		if ( $require_pos_uuid ) {
			$sql .= " AND EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} pu
				WHERE pu.post_id = pm.post_id
					AND pu.meta_key = '_woocommerce_pos_uuid'
			)";
		}

		$sql .= '
			GROUP BY pm.post_id, pm.meta_key, pm.meta_value
			HAVING COUNT(*) > 1
			ORDER BY pm.post_id ASC, pm.meta_key ASC
			LIMIT %d';

		$params   = array_merge( $post_types, array( $batch_size ) );
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders prepared in the same call; direct repair query.
			$wpdb->prepare( $sql, $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared in this call.
		);

		return \is_array( $results ) ? $results : array();
	}
}
