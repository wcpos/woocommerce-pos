<?php
/**
 * Staleness of an installed gallery template against the bundled one.
 *
 * Installing a gallery template copies its markup into a `wcpos_template` post, and the copy is
 * then the merchant's to edit. When a later release fixes the bundled markup, that fix reaches
 * nobody: the fork is frozen. This is the same problem WooCommerce solves with the `@version`
 * docblock it stamps into theme template overrides.
 *
 * Two facts are recorded at install time and compared later:
 *
 * - `_template_gallery_version` — the registry `version` the copy was taken from. Below the
 *   registry's current value means the bundled markup has moved on.
 * - `_template_gallery_source_hash` — a hash of the content as installed. Still matching the
 *   post's content means the merchant has never edited it, so the new markup can simply replace
 *   it and they need never be told. That distinction is one WooCommerce cannot draw, and it is
 *   what keeps this from becoming a notice every merchant learns to ignore.
 *
 * Deliberately does no file I/O: `status_for()` runs on the template list, which the settings
 * screens re-read often, and reading 37 bundled files to answer it would be the same mistake as
 * calling `load_template()` on a poll path. Only `sync_untouched()` touches the filesystem, and
 * only for templates already known to be both outdated and unedited.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WCPOS\WooCommercePOS\Templates;

/**
 * Gallery_Update_Status class.
 */
final class Gallery_Update_Status {

	/**
	 * Post meta holding the hash of the content as installed.
	 */
	public const META_SOURCE_HASH = '_template_gallery_source_hash';

	/**
	 * Post meta holding the gallery key the copy came from.
	 */
	public const META_GALLERY_KEY = '_template_gallery_key';

	/**
	 * Post meta holding the gallery version the copy came from.
	 */
	public const META_GALLERY_VERSION = '_template_gallery_version';

	/**
	 * The copy is at or ahead of the bundled version.
	 */
	public const STATUS_CURRENT = 'current';

	/**
	 * The bundled markup has moved on and the copy is untouched, so it can be replaced silently.
	 */
	public const STATUS_UNTOUCHED = 'outdated-untouched';

	/**
	 * The bundled markup has moved on and the merchant has edited their copy.
	 */
	public const STATUS_EDITED = 'outdated-edited';

	/**
	 * Not instantiable: every member is a pure static function.
	 */
	private function __construct() {}

	/**
	 * Hash a template's content for the unedited comparison.
	 *
	 * Line endings are normalized first. WordPress rewrites them on save and editors disagree
	 * about them, so hashing the raw bytes would report almost every template as edited within a
	 * save or two -- which would make the whole signal worthless in exactly the quiet way that is
	 * hardest to notice.
	 *
	 * @param string $content The template content.
	 *
	 * @return string The hash.
	 */
	public static function content_hash( string $content ): string {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $content );

		return hash( 'sha256', rtrim( $normalized ) );
	}

	/**
	 * Record the installed content's hash against a freshly installed template.
	 *
	 * Reads the content back out of the post rather than hashing what was passed to the save:
	 * the two differ (slashing, line endings), and hashing the pre-save copy would mark every
	 * template edited the moment it was installed.
	 *
	 * @param int $template_id The template post ID.
	 *
	 * @return void
	 */
	public static function record_source_hash( int $template_id ): void {
		$post = get_post( $template_id );
		if ( ! $post ) {
			return;
		}

		update_post_meta( $template_id, self::META_SOURCE_HASH, self::content_hash( $post->post_content ) );
	}

	/**
	 * The update status of one installed template.
	 *
	 * @param int $template_id The template post ID.
	 *
	 * @return array{status: string, installed_version: int, latest_version: int}|null
	 *               Null when the template did not come from the gallery, so has nothing to
	 *               compare against -- a hand-written template is never "out of date".
	 */
	public static function status_for( int $template_id ): ?array {
		$gallery_key = get_post_meta( $template_id, self::META_GALLERY_KEY, true );
		if ( ! \is_string( $gallery_key ) || '' === $gallery_key ) {
			return null;
		}

		$latest = self::registry_version( $gallery_key );
		if ( null === $latest ) {
			// Installed from a gallery entry this build no longer ships. Nothing to offer.
			return null;
		}

		$installed = (int) get_post_meta( $template_id, self::META_GALLERY_VERSION, true );
		$installed = $installed > 0 ? $installed : 1;

		if ( $installed >= $latest ) {
			return array(
				'status'            => self::STATUS_CURRENT,
				'installed_version' => $installed,
				'latest_version'    => $latest,
			);
		}

		return array(
			'status'            => self::is_unedited( $template_id ) ? self::STATUS_UNTOUCHED : self::STATUS_EDITED,
			'installed_version' => $installed,
			'latest_version'    => $latest,
		);
	}

	/**
	 * Whether the merchant's copy still matches the content that was installed.
	 *
	 * A template with no recorded hash is treated as edited. That is the safe default: it is what
	 * every template installed before this shipped looks like, and silently overwriting a
	 * merchant's receipt layout on the strength of a missing value is not a trade worth making.
	 *
	 * @param int $template_id The template post ID.
	 *
	 * @return bool True when the content is provably unchanged since install.
	 */
	public static function is_unedited( int $template_id ): bool {
		$recorded = get_post_meta( $template_id, self::META_SOURCE_HASH, true );
		if ( ! \is_string( $recorded ) || '' === $recorded ) {
			return false;
		}

		$post = get_post( $template_id );
		if ( ! $post ) {
			return false;
		}

		return hash_equals( $recorded, self::content_hash( $post->post_content ) );
	}

	/**
	 * The version the bundled gallery currently ships for a key.
	 *
	 * @param string $gallery_key The gallery template key.
	 *
	 * @return int|null The version, or null when this build does not ship that key.
	 */
	public static function registry_version( string $gallery_key ): ?int {
		$catalogue = Gallery_Registry::all();
		if ( ! isset( $catalogue[ $gallery_key ] ) ) {
			return null;
		}

		$version = $catalogue[ $gallery_key ]['version'] ?? 1;

		return max( 1, (int) $version );
	}

	/**
	 * Give existing gallery copies a source hash, where one can be established safely.
	 *
	 * Every template installed before this shipped has no fingerprint, and without one it counts
	 * as edited forever — so the silent-update path would never fire for the existing install
	 * base, only for templates installed from here on.
	 *
	 * The one case that can be settled after the fact: a copy whose content still matches the
	 * bundled markup *as it stands today* was demonstrably never edited, so it can be fingerprinted
	 * and marked current. Anything else is ambiguous — an edited copy and a copy of an older
	 * bundled version are indistinguishable without the history — and stays unfingerprinted, which
	 * means "offer, never overwrite". Ambiguity resolves toward leaving the merchant's file alone.
	 *
	 * @return int The number of templates fingerprinted.
	 */
	public static function backfill_source_hashes(): int {
		$template_ids = get_posts(
			array(
				'post_type'     => 'wcpos_template',
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => self::META_GALLERY_KEY,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_SOURCE_HASH,
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows' => true,
				'cache_results' => false,
			)
		);

		$bundled_hashes = array();
		$filled         = 0;

		foreach ( $template_ids as $template_id ) {
			$template_id = (int) $template_id;
			$gallery_key = (string) get_post_meta( $template_id, self::META_GALLERY_KEY, true );
			$latest      = self::registry_version( $gallery_key );
			if ( '' === $gallery_key || null === $latest ) {
				continue;
			}

			// One file read per distinct key, not per template.
			if ( ! \array_key_exists( $gallery_key, $bundled_hashes ) ) {
				$bundled                        = Templates::get_gallery_template_by_key( $gallery_key );
				$bundled_hashes[ $gallery_key ] = \is_array( $bundled ) && isset( $bundled['content'] )
					? self::content_hash( Receipt_I18n_Labels::translate_interpolated_phrases( (string) $bundled['content'] ) )
					: null;
			}

			$bundled_hash = $bundled_hashes[ $gallery_key ];
			$post         = get_post( $template_id );
			if ( null === $bundled_hash || ! $post ) {
				continue;
			}

			if ( ! hash_equals( $bundled_hash, self::content_hash( $post->post_content ) ) ) {
				continue;
			}

			update_post_meta( $template_id, self::META_SOURCE_HASH, $bundled_hash );
			update_post_meta( $template_id, self::META_GALLERY_VERSION, $latest );
			++$filled;
		}

		if ( $filled > 0 ) {
			Logger::log( sprintf( 'Fingerprinted %d unedited gallery template(s) for future updates.', $filled ) );
		}

		return $filled;
	}

	/**
	 * Replace the content of every outdated-but-unedited gallery copy with the bundled markup.
	 *
	 * Runs on upgrade. Only touches templates whose content still hashes to what was installed,
	 * so nothing a merchant wrote is ever overwritten; an edited copy is left alone and surfaces
	 * as a notice instead.
	 *
	 * @return int The number of templates updated.
	 */
	public static function sync_untouched(): int {
		$template_ids = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'any',
				'numberposts'    => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_SOURCE_HASH,
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);

		$updated = 0;
		foreach ( $template_ids as $template_id ) {
			$status = self::status_for( (int) $template_id );
			if ( null === $status || self::STATUS_UNTOUCHED !== $status['status'] ) {
				continue;
			}

			if ( self::replace_with_bundled( (int) $template_id ) ) {
				++$updated;
			}
		}

		if ( $updated > 0 ) {
			Logger::log( sprintf( 'Updated %d unedited gallery template(s) to the bundled version.', $updated ) );
		}

		return $updated;
	}

	/**
	 * Move a template's modification time to now.
	 *
	 * Written directly for the same reason the content is: `wp_update_post()` would re-run the
	 * content through wp_kses and strip the XML tags a thermal template is made of.
	 *
	 * @param int $template_id The template post ID.
	 *
	 * @return void
	 */
	private static function touch_modified( int $template_id ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $now,
				'post_modified_gmt' => get_gmt_from_date( $now ),
			),
			array( 'ID' => $template_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		clean_post_cache( $template_id );
	}

	/**
	 * Overwrite one template's content with the bundled markup and re-stamp its provenance.
	 *
	 * Mirrors `Templates::install_gallery_template()` on both counts that decide what the stored
	 * content ends up being: the interpolated phrases are translated first, and offline-capable
	 * engines are written raw because wp_kses strips the XML tags a thermal template is made of.
	 * Diverging on either would rewrite the merchant's template into something that does not match
	 * a fresh install of the same gallery entry.
	 *
	 * @param int $template_id The template post ID.
	 *
	 * @return bool True when the template was updated.
	 */
	private static function replace_with_bundled( int $template_id ): bool {
		$gallery_key = (string) get_post_meta( $template_id, self::META_GALLERY_KEY, true );
		$bundled     = Templates::get_gallery_template_by_key( $gallery_key );

		// `content` is false when the file exists but could not be read, and isset() accepts
		// false. Casting that to a string and writing it would replace a merchant's working
		// template with nothing and stamp it current — the one outcome this whole path exists to
		// avoid. An empty file is refused for the same reason.
		$bundled_content = \is_array( $bundled ) && isset( $bundled['content'] ) ? $bundled['content'] : null;
		if ( ! \is_string( $bundled_content ) || '' === trim( $bundled_content ) ) {
			Logger::log(
				sprintf( 'Skipped updating gallery template %d: bundled content for "%s" could not be read.', $template_id, $gallery_key )
			);

			return false;
		}

		$content = Receipt_I18n_Labels::translate_interpolated_phrases( $bundled_content );
		$engine  = (string) get_post_meta( $template_id, '_template_engine', true );

		if ( \in_array( $engine, Templates::OFFLINE_CAPABLE_ENGINES, true ) ) {
			if ( ! Templates::save_raw_post_content( $template_id, $content ) ) {
				return false;
			}
			// save_raw_post_content writes post_content straight to the table, so the post's
			// modification time does not move. The templates collection serves incremental pulls
			// by `modified_after`, so without this a POS client whose cursor predates the swap
			// never learns the markup changed and keeps printing the old receipt indefinitely.
			self::touch_modified( $template_id );
		} else {
			$result = wp_update_post(
				array(
					'ID'           => $template_id,
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}

		update_post_meta( $template_id, self::META_GALLERY_VERSION, self::registry_version( $gallery_key ) );
		self::record_source_hash( $template_id );

		return true;
	}
}
