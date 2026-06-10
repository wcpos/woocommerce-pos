<?php
/**
 * Abstract option-backed Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;
use WP_Error;

/**
 * Base class for option-backed Settings Sections.
 *
 * Owns the read/write template: read = option + migrate + defaults merge +
 * compose + filter + redact (pure, no DB writes); write = sanitize + stamp +
 * pre_save filter + update_option + saved action. Sections override the
 * protected hooks; non-option-backed sections (access, license) override
 * read()/write() wholesale.
 */
abstract class Abstract_Section implements Settings_Section_Interface {
	/**
	 * Prefix for the wp_options table, identical to the legacy
	 * Services\Settings::$db_prefix. Persisted option names are frozen
	 * public interface — never change this.
	 *
	 * @var string
	 */
	const DB_PREFIX = 'woocommerce_pos_settings_';

	/**
	 * Section id.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * The full default shape for this section.
	 *
	 * @return array
	 */
	abstract public function defaults(): array;

	/**
	 * Migrate legacy stored shapes, in memory only. Runs on the raw option
	 * value BEFORE defaults are merged. Must be idempotent and must not
	 * write to the database.
	 *
	 * @param array $raw Raw option value.
	 *
	 * @return array
	 */
	protected function migrate( array $raw ): array {
		return $raw;
	}

	/**
	 * Sanitize settings before persisting.
	 *
	 * @param array $settings Settings about to be saved.
	 *
	 * @return array
	 */
	protected function sanitize( array $settings ): array {
		return $settings;
	}

	/**
	 * Append computed, read-only view fields (e.g. resolved fallbacks) after
	 * defaults merge and before the section filter.
	 *
	 * @param array $settings Merged settings.
	 *
	 * @return array
	 */
	protected function compose( array $settings ): array {
		return $settings;
	}

	/**
	 * Strip secrets from the public view. Runs last in read().
	 *
	 * @param array $settings Filtered settings.
	 *
	 * @return array
	 */
	protected function redact( array $settings ): array {
		return $settings;
	}

	/**
	 * Default PATCH merge for REST updates.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 *
	 * @return array
	 */
	public function merge( array $existing, array $patch ): array {
		return array_replace_recursive( $existing, $patch );
	}

	/**
	 * REST endpoint args. Default: none.
	 *
	 * @return array
	 */
	public function endpoint_args(): array {
		return array();
	}

	/**
	 * The wp_options key backing this section.
	 *
	 * @return string
	 */
	protected function option_name(): string {
		return self::DB_PREFIX . $this->id();
	}

	/**
	 * Read the raw option value, coerced to array.
	 *
	 * @return array
	 */
	protected function read_raw(): array {
		$raw = get_option( $this->option_name(), array() );

		return \is_array( $raw ) ? $raw : array();
	}

	/**
	 * Read the section's public view. Pure — never writes to the database.
	 *
	 * @return array
	 */
	public function read(): array {
		$settings = $this->migrate( $this->read_raw() );

		foreach ( $this->defaults() as $key => $value ) {
			if ( ! \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		$settings = $this->compose( $settings );

		/**
		 * Filters a Settings Section's read view.
		 *
		 * The dynamic portion of the hook name, `$this->id()`, refers to the
		 * section id, e.g. 'general' or 'checkout'.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings The section settings.
		 *
		 * @hook woocommerce_pos_{$id}_settings
		 */
		$settings = apply_filters( "woocommerce_pos_{$this->id()}_settings", $settings );

		return $this->redact( $settings );
	}

	/**
	 * Persist a full settings array.
	 *
	 * Behaviour is byte-compatible with the legacy
	 * Services\Settings::save_settings(): sanitize, stamp date_modified_gmt,
	 * apply the pre-save filter, update_option (autoload off), detect
	 * unchanged-value no-ops, fire the saved action, return the post-save
	 * read.
	 *
	 * @param array $settings The full settings array to persist.
	 *
	 * @return array|WP_Error
	 */
	public function write( array $settings ) {
		$settings = $this->sanitize( $settings );

		$settings = array_merge(
			$settings,
			array( 'date_modified_gmt' => current_time( 'mysql', true ) )
		);

		/**
		 * Filters the settings before they are saved.
		 *
		 * @since 1.4.12
		 *
		 * @param array  $settings The settings array about to be saved.
		 * @param string $id       The ID of the settings section being saved.
		 *
		 * @hook woocommerce_pos_pre_save_{$id}_settings
		 */
		$settings = apply_filters( "woocommerce_pos_pre_save_{$this->id()}_settings", $settings, $this->id() );

		$option_name    = $this->option_name();
		$previous_value = get_option( $option_name, null );
		$success        = update_option( $option_name, $settings, false );

		if ( ! $success ) {
			// update_option() returns false both when the value is unchanged (no DB
			// write) and on actual failure. Use the value read *before* the write
			// attempt to avoid a post-write race.
			$is_noop = null !== $previous_value
				&& maybe_serialize( $previous_value ) === maybe_serialize( $settings );

			if ( ! $is_noop ) {
				return new WP_Error(
					'woocommerce_pos_settings_error',
					// translators: %s: Settings group id, ie: 'general' or 'checkout'.
					\sprintf( __( 'Can not save settings with id %s', 'woocommerce-pos' ), $this->id() ),
					array( 'status' => 400 )
				);
			}
		}

		$saved_settings = $this->read();

		if ( $success ) {
			/**
			 * Fires after settings for a specific section are successfully saved.
			 *
			 * @since 1.4.12
			 *
			 * @param array  $saved_settings The settings array that was just saved.
			 * @param string $id             The ID of the settings section that was saved.
			 *
			 * @hook woocommerce_pos_saved_{$id}_settings
			 */
			do_action( "woocommerce_pos_saved_{$this->id()}_settings", $saved_settings, $this->id() );
		}

		return $saved_settings;
	}
}
