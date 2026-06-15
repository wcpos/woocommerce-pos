<?php
/**
 * Settings Section interface.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Interfaces;

use WP_Error;

/**
 * A Settings Section owns one settings group end to end: schema, defaults,
 * sanitization, secret redaction, and merge strategy. Sections are registered
 * with the Section Registry (see Services\Settings\Section_Registry); the
 * Settings facade and the REST controller call sections through this
 * interface only.
 */
interface Settings_Section_Interface {
	/**
	 * The section id, e.g. 'general'. Also the option-name suffix for
	 * option-backed sections (woocommerce_pos_settings_{id}).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * The full default shape for this section (used for typed-accessor
	 * fallback). Non-option-backed sections may return an empty array.
	 *
	 * @return array
	 */
	public function defaults(): array;

	/**
	 * Read the section's public view: defaults merged, legacy shapes migrated
	 * in memory, the woocommerce_pos_{id}_settings filter applied where the
	 * section uses the option-backed template (wholesale-override sections may
	 * skip it), secrets redacted. Reads are pure — they never write to the
	 * database.
	 *
	 * @return array
	 */
	public function read(): array;

	/**
	 * Persist a full settings array (not a patch — callers merge first).
	 *
	 * @param array $settings The full settings array to persist.
	 *
	 * @return array|WP_Error The section's post-save response on success — typically the post-save read; sections may return a bespoke shape (e.g. cloud_print includes one-time generated poll tokens).
	 */
	public function write( array $settings );

	/**
	 * PATCH semantics for REST updates: merge an incoming partial payload
	 * over the existing view. Default is array_replace_recursive; sections
	 * with full-replacement keys (e.g. tax_ids write_map) override this.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 *
	 * @return array
	 */
	public function merge( array $existing, array $patch ): array;

	/**
	 * REST endpoint args (validation schema) for the section's update route.
	 *
	 * @return array
	 */
	public function endpoint_args(): array;
}
