<?php
/**
 * Tests for gallery template staleness.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates;
use WCPOS\WooCommercePOS\Templates\Gallery_Update_Status;
use WP_UnitTestCase;

/**
 * Gallery_Update_Status_Test class.
 */
class Gallery_Update_Status_Test extends WP_UnitTestCase {

	/**
	 * The gallery key used across these tests.
	 *
	 * @var string
	 */
	private $gallery_key = 'thermal-simple-80mm';

	/**
	 * Bump the bundled version of the test's gallery key.
	 *
	 * Every shipped entry is at version 1, so without this there is no way to be behind and the
	 * comparison could not be exercised until the first real bump — which is precisely when a
	 * mistake here would reach merchants.
	 *
	 * @param int $version The version to report.
	 *
	 * @return void
	 */
	private function set_bundled_version( int $version ): void {
		add_filter(
			'woocommerce_pos_gallery_templates',
			function ( $catalogue ) use ( $version ) {
				if ( isset( $catalogue[ $this->gallery_key ] ) ) {
					$catalogue[ $this->gallery_key ]['version'] = $version;
				}

				return $catalogue;
			}
		);
	}

	/**
	 * Install the gallery template under test.
	 *
	 * @return int The template post ID.
	 */
	private function install(): int {
		$template_id = Templates::install_gallery_template( $this->gallery_key );
		$this->assertIsInt( $template_id, 'Fixture install failed.' );

		return $template_id;
	}

	/**
	 * A hand-written template has no bundled original, so it is never out of date.
	 *
	 * @return void
	 */
	public function test_status_for_template_without_gallery_key_returns_null(): void {
		// Arrange.
		$template_id = wp_insert_post(
			array(
				'post_title'   => 'Hand written',
				'post_content' => '<receipt></receipt>',
				'post_status'  => 'publish',
				'post_type'    => 'wcpos_template',
			)
		);

		// Act.
		$status = Gallery_Update_Status::status_for( (int) $template_id );

		// Assert.
		$this->assertNull( $status );
	}

	/**
	 * A freshly installed template is recorded as current.
	 *
	 * @return void
	 */
	public function test_status_for_freshly_installed_template_is_current(): void {
		// Arrange.
		$template_id = $this->install();

		// Act.
		$status = Gallery_Update_Status::status_for( $template_id );

		// Assert.
		$this->assertIsArray( $status );
		$this->assertSame( Gallery_Update_Status::STATUS_CURRENT, $status['status'] );
	}

	/**
	 * Installing records a fingerprint of the content, so "unedited" is answerable later.
	 *
	 * @return void
	 */
	public function test_install_records_source_hash_matching_stored_content(): void {
		// Arrange.
		$template_id = $this->install();

		// Act.
		$recorded = get_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_HASH, true );

		// Assert.
		$this->assertNotSame( '', $recorded );
		$this->assertTrue( Gallery_Update_Status::is_unedited( $template_id ) );
	}

	/**
	 * An untouched copy behind the bundled version can be updated in place.
	 *
	 * @return void
	 */
	public function test_status_for_untouched_copy_behind_bundled_version_is_untouched(): void {
		// Arrange.
		$template_id = $this->install();
		$this->set_bundled_version( 2 );

		// Act.
		$status = Gallery_Update_Status::status_for( $template_id );

		// Assert.
		$this->assertSame( Gallery_Update_Status::STATUS_UNTOUCHED, $status['status'] );
		$this->assertSame( 1, $status['installed_version'] );
		$this->assertSame( 2, $status['latest_version'] );
	}

	/**
	 * An edited copy behind the bundled version is only ever offered, never replaced.
	 *
	 * @return void
	 */
	public function test_status_for_edited_copy_behind_bundled_version_is_edited(): void {
		// Arrange.
		$template_id = $this->install();
		Templates::save_raw_post_content( $template_id, '<receipt><text>Mine now</text></receipt>' );
		$this->set_bundled_version( 2 );

		// Act.
		$status = Gallery_Update_Status::status_for( $template_id );

		// Assert.
		$this->assertSame( Gallery_Update_Status::STATUS_EDITED, $status['status'] );
	}

	/**
	 * A copy with no fingerprint counts as edited — the safe reading for pre-existing installs.
	 *
	 * @return void
	 */
	public function test_status_for_copy_without_source_hash_is_edited(): void {
		// Arrange.
		$template_id = $this->install();
		delete_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_HASH );
		$this->set_bundled_version( 2 );

		// Act.
		$status = Gallery_Update_Status::status_for( $template_id );

		// Assert.
		$this->assertSame( Gallery_Update_Status::STATUS_EDITED, $status['status'] );
	}

	/**
	 * Line-ending differences alone do not count as an edit.
	 *
	 * WordPress rewrites line endings on save, so hashing raw bytes would report almost every
	 * template as edited and the silent-update path would never fire for anyone.
	 *
	 * @return void
	 */
	public function test_content_hash_ignores_line_ending_differences(): void {
		// Arrange.
		$lf   = "<receipt>\n<text>A</text>\n</receipt>";
		$crlf = "<receipt>\r\n<text>A</text>\r\n</receipt>";

		// Act / Assert.
		$this->assertSame(
			Gallery_Update_Status::content_hash( $lf ),
			Gallery_Update_Status::content_hash( $crlf )
		);
	}

	/**
	 * The sync replaces an untouched copy and leaves an edited one alone.
	 *
	 * @return void
	 */
	public function test_sync_untouched_updates_only_the_unedited_copy(): void {
		// Arrange.
		$untouched_id = $this->install();
		$edited_id    = $this->install();
		$edited_body  = '<receipt><text>Mine now</text></receipt>';
		Templates::save_raw_post_content( $edited_id, $edited_body );
		$this->set_bundled_version( 2 );

		// Act.
		$updated = Gallery_Update_Status::sync_untouched();

		// Assert.
		$this->assertSame( 1, $updated );
		$this->assertSame(
			Gallery_Update_Status::STATUS_CURRENT,
			Gallery_Update_Status::status_for( $untouched_id )['status']
		);
		$this->assertSame( 2, (int) get_post_meta( $untouched_id, Gallery_Update_Status::META_GALLERY_VERSION, true ) );

		// The merchant's content is untouched, and it still reports as theirs.
		$this->assertSame( $edited_body, get_post( $edited_id )->post_content );
		$this->assertSame(
			Gallery_Update_Status::STATUS_EDITED,
			Gallery_Update_Status::status_for( $edited_id )['status']
		);
	}

	/**
	 * Re-running the sync changes nothing.
	 *
	 * Activation runs the same pass as the upgrade, so this fires twice on a normal update.
	 *
	 * @return void
	 */
	public function test_sync_untouched_is_idempotent(): void {
		// Arrange.
		$this->install();
		$this->set_bundled_version( 2 );

		// Act.
		$first  = Gallery_Update_Status::sync_untouched();
		$second = Gallery_Update_Status::sync_untouched();

		// Assert.
		$this->assertSame( 1, $first );
		$this->assertSame( 0, $second );
	}

	/**
	 * Replacing the content moves the modification time.
	 *
	 * `save_raw_post_content()` writes `post_content` straight to the table, so nothing moves
	 * `post_modified` on its own. The templates collection serves incremental pulls by
	 * `modified_after`, so without this a POS client whose cursor predates the swap keeps printing
	 * the old receipt forever. Raised by Codex review on #1969.
	 *
	 * @return void
	 */
	public function test_sync_untouched_advances_the_modification_time(): void {
		// Arrange.
		$template_id = $this->install();
		$stale       = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->update(
			$GLOBALS['wpdb']->posts,
			array(
				'post_modified'     => $stale,
				'post_modified_gmt' => $stale,
			),
			array( 'ID' => $template_id )
		);
		clean_post_cache( $template_id );
		$this->set_bundled_version( 2 );

		// Act.
		Gallery_Update_Status::sync_untouched();

		// Assert.
		$this->assertNotSame( $stale, get_post( $template_id )->post_modified );
		$this->assertNotSame( $stale, get_post( $template_id )->post_modified_gmt );
	}

	/**
	 * Unusable bundled content never overwrites a working template.
	 *
	 * `file_get_contents()` returns false on an unreadable file and `isset()` accepts false, so an
	 * unguarded cast would blank the merchant's template and stamp it current. An empty file takes
	 * the same branch. Raised by Codex review on #1969.
	 *
	 * @return void
	 */
	public function test_sync_untouched_refuses_empty_bundled_content(): void {
		// Arrange.
		$template_id = $this->install();
		$original    = get_post( $template_id )->post_content;
		$empty_file  = wp_tempnam( 'wcpos-empty-gallery' );
		file_put_contents( $empty_file, '' );
		add_filter(
			'woocommerce_pos_gallery_templates',
			function ( $catalogue ) use ( $empty_file ) {
				if ( isset( $catalogue[ $this->gallery_key ] ) ) {
					$catalogue[ $this->gallery_key ]['version']      = 2;
					$catalogue[ $this->gallery_key ]['content_file'] = $empty_file;
				}

				return $catalogue;
			}
		);

		// Act.
		$updated = Gallery_Update_Status::sync_untouched();

		// Assert: nothing written, and the merchant's content is exactly as it was.
		$this->assertSame( 0, $updated );
		$this->assertSame( $original, get_post( $template_id )->post_content );

		unlink( $empty_file );
	}

	/**
	 * Maintenance runs once per plugin version and then stops.
	 *
	 * It is gated on an option rather than living only in a versioned migration, because an
	 * upgrade that bumps the version without reaching woocommerce_init never queues db_upgrade()
	 * again — Activator::version_check() documents that miss as permanent. Raised by Codex review.
	 *
	 * @return void
	 */
	public function test_maintain_runs_once_per_registry_change_and_then_no_ops(): void {
		// Arrange.
		delete_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE );
		$template_id = $this->install();
		$this->set_bundled_version( 2 );

		// Act.
		Gallery_Update_Status::maintain();
		$after_first = (int) get_post_meta( $template_id, Gallery_Update_Status::META_GALLERY_VERSION, true );
		$signature   = get_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE );

		// A second call is gated off by the recorded signature.
		Gallery_Update_Status::maintain();

		// Assert.
		$this->assertSame( 2, $after_first );
		$this->assertNotEmpty( $signature );
		$this->assertSame( $signature, get_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE ) );
	}

	/**
	 * A failed replacement leaves maintenance pending so the next admin load retries.
	 *
	 * Stamping regardless would end the retries for good: the gate would short-circuit every
	 * later load while the UI hides `outdated-untouched`, so the merchant would print stale
	 * receipts with nothing anywhere saying why. Raised by Codex review on #1969.
	 *
	 * @return void
	 */
	public function test_maintain_leaves_the_signature_unset_when_a_replacement_fails(): void {
		// Arrange: an eligible copy whose bundled content cannot be used.
		delete_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE );
		$this->install();
		$empty_file = wp_tempnam( 'wcpos-empty-gallery' );
		file_put_contents( $empty_file, '' );
		add_filter(
			'woocommerce_pos_gallery_templates',
			function ( $catalogue ) use ( $empty_file ) {
				if ( isset( $catalogue[ $this->gallery_key ] ) ) {
					$catalogue[ $this->gallery_key ]['version']      = 2;
					$catalogue[ $this->gallery_key ]['content_file'] = $empty_file;
				}

				return $catalogue;
			}
		);

		// Act.
		Gallery_Update_Status::maintain();

		// Assert: nothing stamped, so the next admin load tries again.
		$this->assertFalse( get_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE ) );

		unlink( $empty_file );
	}

	/**
	 * A replacement keeps the locale the template was installed in.
	 *
	 * `translate_in_source_locale()` restores the request locale before the fingerprint is
	 * refreshed, so re-reading the locale there stamped the upgrading admin's language onto the
	 * template and would have silently translated it on the NEXT update. Raised by Codex review.
	 *
	 * @return void
	 */
	public function test_replacement_does_not_overwrite_the_recorded_locale(): void {
		// Arrange.
		$template_id = $this->install();
		update_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_LOCALE, 'fr_FR' );
		Gallery_Update_Status::record_source_hash( $template_id );
		$this->set_bundled_version( 2 );

		// Act.
		Gallery_Update_Status::sync_untouched();

		// Assert.
		$this->assertSame(
			'fr_FR',
			get_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_LOCALE, true )
		);
	}

	/**
	 * Maintenance repairs itself after a missed upgrade.
	 *
	 * @return void
	 */
	public function test_maintain_runs_again_once_a_registry_version_moves(): void {
		// Arrange: a previous release completed maintenance.
		update_option( Gallery_Update_Status::OPTION_SYNCED_SIGNATURE, 'stale-signature' );
		$template_id = $this->install();
		$this->set_bundled_version( 2 );

		// Act.
		Gallery_Update_Status::maintain();

		// Assert.
		$this->assertSame( 2, (int) get_post_meta( $template_id, Gallery_Update_Status::META_GALLERY_VERSION, true ) );
	}

	/**
	 * Installing records the locale the phrases were translated in.
	 *
	 * Without it the automatic replacement re-translates in whatever locale the upgrading request
	 * carries, and an untouched French template silently becomes English. Raised by Codex review.
	 *
	 * @return void
	 */
	public function test_install_records_the_source_locale(): void {
		// Arrange / Act.
		$template_id = $this->install();

		// Assert.
		$this->assertSame(
			determine_locale(),
			get_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_LOCALE, true )
		);
	}

	/**
	 * The backfill fingerprints a copy that still matches the bundled markup.
	 *
	 * @return void
	 */
	public function test_backfill_fingerprints_a_copy_matching_the_bundled_markup(): void {
		// Arrange.
		$template_id = $this->install();
		delete_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_HASH );

		// Act.
		$filled = Gallery_Update_Status::backfill_source_hashes();

		// Assert.
		$this->assertSame( 1, $filled );
		$this->assertTrue( Gallery_Update_Status::is_unedited( $template_id ) );
	}

	/**
	 * The backfill leaves an edited copy unfingerprinted, so it is never silently replaced.
	 *
	 * @return void
	 */
	public function test_backfill_skips_a_copy_that_differs_from_the_bundled_markup(): void {
		// Arrange.
		$template_id = $this->install();
		delete_post_meta( $template_id, Gallery_Update_Status::META_SOURCE_HASH );
		Templates::save_raw_post_content( $template_id, '<receipt><text>Mine now</text></receipt>' );

		// Act.
		$filled = Gallery_Update_Status::backfill_source_hashes();

		// Assert.
		$this->assertSame( 0, $filled );
		$this->assertFalse( Gallery_Update_Status::is_unedited( $template_id ) );
	}
}
