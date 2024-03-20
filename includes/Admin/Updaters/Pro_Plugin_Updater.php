<?php
/**
 * Pro plugin updater.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS\Updater
 */

namespace WCPOS\WooCommercePOS\Admin\Updaters;

use Parsedown;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;

/**
 * Handles the update checking for the Pro plugin
 */
class Pro_Plugin_Updater {
	/**
	 * The Pro plugin slug
	 *
	 * @var string $pro_plugin_slug
	 */
	private $pro_plugin_slug = 'woocommerce-pos-pro';

	/**
	 * The update server URL
	 *
	 * @var string $update_server
	 */
	private $update_server = 'https://updates.wcpos.com/pro';

	/**
	 * Transient key for the update data
	 *
	 * @var string $update_data_transient_key
	 */
	public static $update_data_transient_key = 'woocommerce_pos_pro_update_data';

	/**
	 * Transient key for the update data
	 *
	 * @var string $license_status_transient_key
	 */
	public static $license_status_transient_key = 'woocommerce_pos_pro_license_status';

	/**
	 * Installed Pro plugins
	 * Note: Generally this would be an array of 1 installed Pro plugins but
	 * it's possible an older version of the plugin is installed in a different directory.
	 *
	 * @var array $installed_pro_plugins
	 */
	private $installed_pro_plugins = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * First we need to check if the Pro plugin is installed (one or more versions)
		 */
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_path => $plugin_data ) {
			$plugin_dir = dirname( $plugin_path );
			if ( strpos( $plugin_dir, $this->pro_plugin_slug ) === 0 ) {
				if ( isset( $plugin_data['UpdateURI'] ) && $plugin_data['UpdateURI'] == $this->update_server ) {
					$this->installed_pro_plugins[ $plugin_path ] = $plugin_data;
				}
			}
		}

		// Allow the update server to be overridden for development.
		if ( isset( $_ENV['WCPOS_PRO_UPDATE_SERVER'] ) ) {
			$this->update_server = $_ENV['WCPOS_PRO_UPDATE_SERVER'];
		}

		if ( count( $this->installed_pro_plugins ) > 0 ) {
			add_filter( 'update_plugins_updates.wcpos.com', array( $this, 'update_plugins' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( $this, 'after_plugin_update' ), 10, 2 );
			add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 4 );
			add_action( 'install_plugins_pre_plugin-information', array( $this, 'plugin_information' ), 5 );
			add_action( 'admin_enqueue_scripts', array( $this, 'license_status_styles' ) );

			// loop through the installed Pro plugins.
			foreach ( $this->installed_pro_plugins as $plugin_path => $plugin_data ) {
				add_action( 'in_plugin_update_message-' . $plugin_path, array( $this, 'plugin_update_message' ), 10, 2 );
				add_action( 'after_plugin_row_' . $plugin_path, array( $this, 'license_status_notice' ), 99 );

				/**
				 * This is a hack to manually trigger Pro update for version < 1.4.0
				 * TODO: remove this after 1.4.0 is released for a while
				 */
				if ( isset( $plugin_data['Version'] ) && version_compare( $plugin_data['Version'], '1.4.0', '<' ) ) {
						$this->remove_this_hack_for_older_versions( $plugin_path );
				}
			}
		}
	}

	/**
	 * Filters the update response for a given plugin hostname.
	 *
	 * @param array|false $update {
	 *     The plugin update data with the latest details. Default false.
	 *
	 *     @type string $id           Optional. ID of the plugin for update purposes, should be a URI
	 *                                specified in the `Update URI` header field.
	 *     @type string $slug         Slug of the plugin.
	 *     @type string $version      The version of the plugin.
	 *     @type string $url          The URL for details of the plugin.
	 *     @type string $package      Optional. The update ZIP for the plugin.
	 *     @type string $tested       Optional. The version of WordPress the plugin is tested against.
	 *     @type string $requires_php Optional. The version of PHP which the plugin requires.
	 *     @type bool   $autoupdate   Optional. Whether the plugin should automatically update.
	 *     @type array  $icons        Optional. Array of plugin icons.
	 *     @type array  $banners      Optional. Array of plugin banners.
	 *     @type array  $banners_rtl  Optional. Array of plugin RTL banners.
	 *     @type array  $translations {
	 *         Optional. List of translation updates for the plugin.
	 *
	 *         @type string $language   The language the translation update is for.
	 *         @type string $version    The version of the plugin this translation is for.
	 *                                  This is not the version of the language file.
	 *         @type string $updated    The update timestamp of the translation file.
	 *                                  Should be a date in the `YYYY-MM-DD HH:MM:SS` format.
	 *         @type string $package    The ZIP location containing the translation update.
	 *         @type string $autoupdate Whether the translation should be automatically installed.
	 *     }
	 * }
	 * @param array       $plugin_data      Plugin headers.
	 * @param string      $plugin_file      Plugin filename.
	 * @param string[]    $locales          Installed locales to look up translations for.
	 */
	public function update_plugins( $update, $plugin_data, $plugin_file, $locales ) {
		$current_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '1';
		$update_data = $this->check_pro_plugin_updates( $current_version );
		$is_development = isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'];

		// Check if $update_data is an object and convert to an array if so.
		if ( is_object( $update_data ) ) {
			$update_data = get_object_vars( $update_data );
		}

		// Check if update data is valid and if a new version is available.
		if ( is_array( $update_data ) && isset( $update_data['version'] ) && version_compare( $current_version, $update_data['version'], '<' ) ) {
			$license_settings = $this->get_license_settings();
			$key = isset( $license_settings['key'] ) ? $license_settings['key'] : '';
			$instance = isset( $license_settings['instance'] ) ? $license_settings['instance'] : '';

			// Construct the download URL, taking into account the environment
			$download_url = $is_development
				? 'http://localhost:8080/pro/download/1.4.0'
				: $update_data['download_url'];

			$package_url = add_query_arg(
				array(
					'key' => urlencode( $key ),
					'instance' => urlencode( $instance ),
				),
				$download_url
			);

			$update = array(
				'id'           => 'https://updates.wcpos.com',
				'slug'         => 'woocommerce-pos-pro',
				'plugin'       => $plugin_file,
				'version'      => $update_data['version'],
				'url'          => 'https://wcpos.com/pro',
				'package'      => $package_url,
				'requires'     => '5.6',
				'tested'       => '6.5',
				'requires_php' => '7.4',
				'icons' => array(
					'1x' => 'https://wcpos.com/wp-content/uploads/2014/06/woopos-pro.png',
				),
				'upgrade_notice' => $this->maybe_add_upgrade_notice(),
			);
		}

		return $update;
	}

	/**
	 * Check for updates to the Pro plugin
	 *
	 * @param  string $version The current plugin version.
	 * @param  bool   $force Force an update check.
	 */
	public function check_pro_plugin_updates( $version = '1', $force = false ) {
		$update_data = get_transient( self::$update_data_transient_key );

		if ( empty( $update_data ) || $force ) {
			$expiration = 60 * 60 * 12; // 12 hours.
			$url = $this->update_server . '/update/' . $version;

			// make the api call.
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => wp_doing_cron() ? 10 : 5,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			$data = $this->validate_api_response( $response );
			$error = isset( $data['is_error'] ) && $data['is_error'] ? $data : false;

			// Ensure $data is an associative array and has version, download_url, and notes.
			if ( ! $error && is_array( $data ) ) {
				$expected_properties = array( 'version', 'download_url', 'notes' );
				foreach ( $expected_properties as $property ) {
					if ( ! array_key_exists( $property, $data ) ) {
						$data = new WP_Error( 'invalid_response_structure', "Missing expected property: $property" );
						break;
					}
				}
			} else {
				Logger::log( $data );
				$expiration = 60 * 60 * 1; // try again in an hour if error.
			}

			$success = set_transient( self::$update_data_transient_key, $data, $expiration );
			if ( ! $success ) {
				Logger::log( 'Failed to set update data transient' );
			}

			return $data;
		}

		return $update_data;
	}

	/**
	 * Check the license status
	 *
	 * @param  bool $force Force an update check.
	 */
	private function check_license_status( $force = false ) {
		$license_status = get_transient( self::$license_status_transient_key );
		$expiration = 60 * 60 * 12; // 12 hours.

		/**
		 * TODO: How to allow for multisite?
		 */
		// if ( is_multisite() ) {
		// $error = array(
		// 'code' => 'multisite_update',
		// 'message' => 'Please go to http://wcpos.com/my-account to download update.',
		// );
		// set_transient( $this->license_status_transient_key, $error, $expiration );
		// return $error;
		// }

		$license_settings = $this->get_license_settings();
		$key = isset( $license_settings['key'] ) ? $license_settings['key'] : '';
		$instance = isset( $license_settings['instance'] ) ? $license_settings['instance'] : '';

		/**
		 * If the Pro plugin is not activated, add a notice
		 */
		if ( empty( $key ) || empty( $instance ) ) {
			// set the transient to an error.
			$error = array(
				'is_error' => true,
				'code' => 'missing_license_key',
				'message' => 'License key is not activated.',
			);
			set_transient( self::$license_status_transient_key, $error, $expiration );
			return $error;
		}

		if ( empty( $license_status ) || $force ) {
			// build the request.
			$url = add_query_arg(
				array(
					'key' => urlencode( $key ),
					'instance' => urlencode( $instance ),
				),
				$this->update_server . '/license/status'
			);

			// make the api call.
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => wp_doing_cron() ? 10 : 5,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			$data = $this->validate_api_response( $response );
			$error = isset( $data['is_error'] ) && $data['is_error'] ? $data : false;

			if ( $error ) {
				Logger::log( $error );
			}

			$success = set_transient( self::$license_status_transient_key, $data, $expiration );
			if ( ! $success ) {
				Logger::log( 'Failed to set license status transient' );
			}

			return $data;
		}

		return $license_status;
	}

	/**
	 * Validate the API response
	 *
	 * @param  array|WP_Error $response The API response.
	 *
	 * @return object|WP_Error $response The validated API response.
	 */
	public function validate_api_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'is_error' => true,
				'code' => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);
		}

		$decoded_response = json_decode( $response['body'], true ); // Decode as associative array.
		if ( null === $decoded_response ) {
			return array(
				'is_error' => true,
				'code' => 'invalid_json',
				'message' => 'Invalid JSON in response',
				'data' => $response['body'],
			);
		}

		if ( $response['response']['code'] === 403 ) {
			return array(
				'is_error' => true,
				'expired' => true,
				'code' => 'license_expired',
				'message' => isset( $decoded_response['error'] ) ? $decoded_response['error'] : 'License expired.',
			);
		}

		if ( $response['response']['code'] !== 200 ) {
			return array(
				'is_error' => true,
				'code' => 'invalid_response_code',
				'message' => isset( $decoded_response['error'] ) ? $decoded_response['error'] : 'No error message returned from server.',
			);
		}

		// Ensure $decoded_response has the expected structure.
		if ( ! isset( $decoded_response['data'] ) ) {
			return array(
				'is_error' => true,
				'code' => 'invalid_response_structure',
				'message' => 'Missing expected property: data',
			);
		}

		return $decoded_response['data'];
	}

	/**
	 * Force a check for updates on the Pro plugin after the free plugin has been updated
	 *
	 * @param  object $upgrader The upgrader object.
	 * @param  array  $options  The upgrader options.
	 */
	public function after_plugin_update( $upgrader, $options ) {
		if ( $options['action'] == 'update' && $options['type'] == 'plugin' ) {
			if ( isset( $options['plugins'] ) && in_array( 'woocommerce-pos/woocommerce-pos.php', $options['plugins'] ) ) {
				delete_transient( self::$update_data_transient_key );
			}
		}
	}

	/**
	 * Filters the array of row meta for each plugin in the Plugins list table.
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $plugin_meta An array of the plugin's metadata, including
	 *                              the version, author, author URI, and plugin URI.
	 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array    $plugin_data {
	 *     An array of plugin data.
	 *
	 *     @type string   $id               Plugin ID, e.g. `w.org/plugins/[plugin-name]`.
	 *     @type string   $slug             Plugin slug.
	 *     @type string   $plugin           Plugin basename.
	 *     @type string   $new_version      New plugin version.
	 *     @type string   $url              Plugin URL.
	 *     @type string   $package          Plugin update package URL.
	 *     @type string[] $icons            An array of plugin icon URLs.
	 *     @type string[] $banners          An array of plugin banner URLs.
	 *     @type string[] $banners_rtl      An array of plugin RTL banner URLs.
	 *     @type string   $requires         The version of WordPress which the plugin requires.
	 *     @type string   $tested           The version of WordPress the plugin is tested against.
	 *     @type string   $requires_php     The version of PHP which the plugin requires.
	 *     @type string   $upgrade_notice   The upgrade notice for the new plugin version.
	 *     @type bool     $update-supported Whether the plugin supports updates.
	 *     @type string   $Name             The human-readable name of the plugin.
	 *     @type string   $PluginURI        Plugin URI.
	 *     @type string   $Version          Plugin version.
	 *     @type string   $Description      Plugin description.
	 *     @type string   $Author           Plugin author.
	 *     @type string   $AuthorURI        Plugin author URI.
	 *     @type string   $TextDomain       Plugin textdomain.
	 *     @type string   $DomainPath       Relative path to the plugin's .mo file(s).
	 *     @type bool     $Network          Whether the plugin can only be activated network-wide.
	 *     @type string   $RequiresWP       The version of WordPress which the plugin requires.
	 *     @type string   $RequiresPHP      The version of PHP which the plugin requires.
	 *     @type string   $UpdateURI        ID of the plugin for update purposes, should be a URI.
	 *     @type string   $Title            The human-readable title of the plugin.
	 *     @type string   $AuthorName       Plugin author's name.
	 *     @type bool     $update           Whether there's an available update. Default null.
	 * }
	 * @param string   $status      Status filter currently applied to the plugin list. Possible
	 *                              values are: 'all', 'active', 'inactive', 'recently_activated',
	 *                              'upgrade', 'mustuse', 'dropins', 'search', 'paused',
	 *                              'auto-update-enabled', 'auto-update-disabled'.
	 *
	 * @return string[] An array of row meta.
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data, $status ) {
		// $license_status = 'Your license status here'; // Fetch or generate your license status message
		// $plugin_meta[] = '<span style="color: #d63638;">' . esc_html( $license_status ) . '</span>';

		return $plugin_meta;
	}

	/**
	 * Fires at the end of the update message container in each
	 * row of the plugins list table.
	 *
	 * The dynamic portion of the hook name, `$file`, refers to the path
	 * of the plugin's primary file relative to the plugins directory.
	 *
	 * @since 2.8.0
	 *
	 * @param array  $plugin_data An array of plugin metadata. See get_plugin_data()
	 *                            and the {@see 'plugin_row_meta'} filter for the list
	 *                            of possible values.
	 * @param object $response {
	 *     An object of metadata about the available plugin update.
	 *
	 *     @type string   $id           Plugin ID, e.g. `w.org/plugins/[plugin-name]`.
	 *     @type string   $slug         Plugin slug.
	 *     @type string   $plugin       Plugin basename.
	 *     @type string   $new_version  New plugin version.
	 *     @type string   $url          Plugin URL.
	 *     @type string   $package      Plugin update package URL.
	 *     @type string[] $icons        An array of plugin icon URLs.
	 *     @type string[] $banners      An array of plugin banner URLs.
	 *     @type string[] $banners_rtl  An array of plugin RTL banner URLs.
	 *     @type string   $requires     The version of WordPress which the plugin requires.
	 *     @type string   $tested       The version of WordPress the plugin is tested against.
	 *     @type string   $requires_php The version of PHP which the plugin requires.
	 * }
	 */
	public function plugin_update_message( $plugin_data, $response ) {
		// Nothing at the moment.

		// $message = 'Your license has expired. <a href="http://wcpos.com/my-account/">Please renew</> to update.';

		// if ( $license_status->get_error_code() === 'missing_license_key' ) {
		// $message = $license_status->get_error_message();
		// }

		// echo '<br /><span style="color: #d63638;">' . wp_kses_post( $message ) . '</span>';
	}

	/**
	 * Display the plugin information iframe for the Pro plugin
	 */
	public function plugin_information() {
		global $tab;

		if ( empty( $_REQUEST['plugin'] ) || $this->pro_plugin_slug !== $_REQUEST['plugin'] ) {
			return;
		}

		$update_data = get_transient( self::$update_data_transient_key );
		$message = esc_html__( 'Something went wrong. Please try again later.', 'woocommerce-pos' );

		if ( is_wp_error( $update_data ) ) {
			$message = $update_data->get_error_message();
		}

		if ( is_object( $update_data ) && isset( $update_data->notes ) ) {
			$parsedown = new Parsedown();
			$message = $parsedown->text( $update_data->notes );
		}

		/* translators: WordPress */
		iframe_header( __( 'Plugin Installation' ) );

		/**
		 * Output some custom CSS to make the release notes look nice.
		 */
		echo '<style>
			h1 {padding-bottom:20px;margin-bottom:20px;border-bottom:1px solid #dcdcde;}
			h2 {font-size:1.6em;margin:0;padding:20px 0 10px;}
			.plugin-install-php h3 {margin:0;padding:10px 0 5px;}
			img {max-width:100%;}
		</style>';

		/**
		 * Output release notes from GitHub, markdown -> HTML
		 */
		echo '<div id="plugin-information-scrollable">';
		echo '<div style="padding:10px 26px">';
		echo wp_kses_post( $message );
		echo '</div>';
		echo "</div>\n";

		/**
		 * Make sure any links in the content open in a new tab.
		 */
		echo "<script>
			var links = document.getElementsByTagName('a');
			for (var i = 0; i < links.length; i++) {
					links[i].setAttribute('target', '_blank');
			}
		</script>";

		iframe_footer();
		exit;
	}

	/**
	 * Maybe add a notice to the plugin update table on Dashboard > Updates
	 */
	private function maybe_add_upgrade_notice() {
		$license_status = $this->check_license_status();

		// Check if $update_data is an object and convert to an array if so.
		if ( is_object( $license_status ) ) {
			$license_status = get_object_vars( $license_status );
		}

		$active = isset( $license_status['activated'] ) && $license_status['activated'];
		$inactive = isset( $license_status['activated'] ) && ! $license_status['activated'];
		$expired = isset( $license_status['expired'] ) && $license_status['expired'];

		if ( isset( $license_status['is_error'] ) && $license_status['is_error'] ) {
			$inactive = isset( $license_status['code'] ) &&
				in_array(
					$license_status['code'],
					array(
						'missing_license_key', // no license key is set.
						'invalid_response_code', // usually the key or instance is wrong length.
					)
				);
		}

		if ( $inactive ) {
			return esc_html__( 'Your WooCommerce Pro license is inactive', 'woocommerce-pos' );
		}

		if ( $expired ) {
			return esc_html__( 'Your WooCommerce Pro license has expired', 'woocommerce-pos' );
		}

		if ( isset( $license_status['is_error'] ) && $license_status['is_error'] ) {
			return $license_status['message'];
		}
	}

	/**
	 * Add a notice to the plugin update table on Dashboard > Updates
	 */
	public function license_status_notice() {
		$license_status = $this->check_license_status();

		// Check if $update_data is an object and convert to an array if so.
		if ( is_object( $license_status ) ) {
			$license_status = get_object_vars( $license_status );
		}

		$active = isset( $license_status['activated'] ) && $license_status['activated'];
		$inactive = isset( $license_status['activated'] ) && ! $license_status['activated'];
		$expired = isset( $license_status['expired'] ) && $license_status['expired'];

		if ( isset( $license_status['is_error'] ) && $license_status['is_error'] ) {
			$inactive = isset( $license_status['code'] ) &&
				in_array(
					$license_status['code'],
					array(
						'missing_license_key', // no license key is set.
						'invalid_response_code', // usually the key or instance is wrong length.
					)
				);
		}

		if ( $active ) {
			echo '<tr class="plugin-update-tr installer-plugin-update-tr woocommerce-pos-pro-license">
				<td colspan="4" class="plugin-update colspanchange">
					<div class="update-message notice inline wcpos-active" style="margin:0;border:0;border-bottom:1px solid #DCDCDE;border-left:4px solid #5D9B5C;background-color:#F8FFF1;">
						<p class="installer-q-icon">' .
						esc_html__( 'Your WooCommerce Pro license is valid and active.', 'woocommerce-pos' ) .
						' ' .
						esc_html__( 'You are receiving plugin updates.', 'woocommerce-pos' )
						. '</p>
					</div>
				</td>
			</tr>';
			return;
		}

		if ( $inactive ) {
			echo '<tr class="plugin-update-tr installer-plugin-update-tr woocommerce-pos-pro-license">
				<td colspan="4" class="plugin-update colspanchange">
					<div class="update-message notice inline wcpos-inactive" style="margin:0;border:0;border-bottom:1px solid #DCDCDE;border-left:4px solid #BD5858;background-color:#FFF8E1;">
						<p class="installer-q-icon">' .
						esc_html__( 'Your WooCommerce Pro license is inactive.', 'woocommerce-pos' ) .
						' ' .
						sprintf(
							wp_kses(
								__( '<a href="%s">Click here</a> to activate your license key.', 'woocommerce-pos' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'admin.php?page=woocommerce-pos-settings#license' ) )
						)
						. '</p>
					</div>
				</td>
			</tr>';
			return;
		}

		if ( $expired ) {
			echo '<tr class="plugin-update-tr installer-plugin-update-tr woocommerce-pos-pro-license">
				<td colspan="4" class="plugin-update colspanchange">
					<div class="update-message notice inline wcpos-expired" style="margin:0;border:0;border-bottom:1px solid #DCDCDE;border-left:4px solid #D63638;background-color:#FFF7F7;">
						<p class="installer-q-icon">' .
						esc_html__( 'Your WooCommerce Pro license has expired.', 'woocommerce-pos' ) .
						' ' .
						sprintf(
							wp_kses(
								__( '<a href="%s">Please renew</a> to receive updates.', 'woocommerce-pos' ),
								array( 'a' => array( 'href' => array() ) )
							),
							'https://wcpos.com/my-account/'
						)
						. '</p>
					</div>
				</td>
			</tr>';
			return;
		}

		$message = esc_html__( 'Something went wrong. Please try again later.', 'woocommerce-pos' );
		if ( isset( $license_status['is_error'] ) && $license_status['is_error'] ) {
			$message = $license_status['message'];
		}
		echo '<tr class="plugin-update-tr installer-plugin-update-tr woocommerce-pos-pro-license">
			<td colspan="4" class="plugin-update colspanchange">
				<div class="update-message notice inline wcpos-expired" style="margin:0;border:0;border-bottom:1px solid #DCDCDE;border-left:4px solid #D63638;background-color:#FFF7F7;">
					<p class="installer-q-icon">' . $message . '</p>
				</div>
			</td>
		</tr>';
	}

	/**
	 * Add some styles for the license status notice
	 *
	 * @param string $hook The current admin page.
	 */
	public function license_status_styles( $hook ) {
		if ( 'plugins.php' === $hook ) {
			wp_register_style( 'woocommerce-pos-styles', false );
			wp_enqueue_style( 'woocommerce-pos-styles' );

			$css = '
				.woocommerce-pos-pro-license .wcpos-active p::before {
					content: "\f12a";
    			color: #4d7d4c;
    			width: 25px;
				}
				.woocommerce-pos-pro-license .wcpos-inactive p::before {
					content: "\f112";
					color: #bd5858;
					width: 25px;
				}
				.woocommerce-pos-pro-license .wcpos-expired p::before {
					width: 25px;
				}
			';
			wp_add_inline_style( 'woocommerce-pos-styles', $css );
		}
	}

	/**
	 * Get the license settings
	 *
	 * NOTE: it's possible the Pro plugin is not activated, so we need to add a bit of a hack to get the settings.
	 */
	private function get_license_settings() {
		// first check if any Pro plugins are active.
		foreach ( $this->installed_pro_plugins as $plugin_path => $plugin_data ) {
			if ( is_plugin_active( $plugin_path ) ) {
				return woocommerce_pos_get_settings( 'license' );
			}
		}

		// else, try and get the settings from the first Pro plugin.
		$first_folder = dirname( array_key_first( $this->installed_pro_plugins ) );
		if ( file_exists( WP_PLUGIN_DIR . '/' . $first_folder . '/includes/Services/Settings.php' ) ) {
			include_once WP_PLUGIN_DIR . '/' . $first_folder . '/includes/Services/Settings.php';
			if ( method_exists( '\WCPOS\WooCommercePOSPro\Services\Settings', '_get_inactive_license_settings' ) ) {
				return \WCPOS\WooCommercePOSPro\Services\Settings::_get_inactive_license_settings();
			}
		}
	}

	/**
	 * Temporary fix for older versions of the Pro plugin
	 *
	 * @param string $plugin_path The plugin path.
	 */
	private function remove_this_hack_for_older_versions( $plugin_path ) {
		// Manually the update_plugins transient
		$update_plugins = get_site_transient( 'update_plugins' );
		if ( ! is_object( $update_plugins ) ) {
				$update_plugins = new stdClass();
				$update_plugins->response = array();
		}

		$license_settings = $this->get_license_settings();
		$key = isset( $license_settings['key'] ) ? $license_settings['key'] : '';
		$instance = isset( $license_settings['instance'] ) ? $license_settings['instance'] : '';

		// Construct the download URL, taking into account the environment.
		$package_url = add_query_arg(
			array(
				'key' => urlencode( $key ),
				'instance' => urlencode( $instance ),
			),
			'https://updates.wcpos.com/pro/download/1.4.5'
		);

		$update = array(
			'id'             => 'https://updates.wcpos.com',
			'slug'           => 'woocommerce-pos-pro',
			'plugin'         => $plugin_path,
			'new_version'    => '1.4.5',
			'url'            => 'https://wcpos.com/pro',
			'package'        => $package_url,
			'requires'       => '5.6',
			'tested'         => '6.5',
			'requires_php'   => '7.4',
			'icons'          => array(
				'1x' => 'https://wcpos.com/wp-content/uploads/2014/06/woopos-pro.png',
			),
			'upgrade_notice' => $this->maybe_add_upgrade_notice(),
		);

		$update_plugins->response[ $plugin_path ] = (object) $update;

		set_site_transient( 'update_plugins', $update_plugins );
	}
}
