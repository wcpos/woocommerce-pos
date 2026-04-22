<?php
/**
 * Logs REST API controller.
 *
 * Surfaces POS log entries for the settings screen.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Extensions;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Logs controller class.
 */
class Logs extends WP_REST_Controller {

	/**
	 * User meta key for last-viewed timestamp.
	 */
	const LAST_VIEWED_META_KEY = '_wcpos_logs_last_viewed';

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'logs';

	/**
	 * Core log source written to by the free plugin (and Pro, which reuses it).
	 *
	 * @var string
	 */
	const CORE_SOURCE = 'woocommerce-pos';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mark-read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Get log entries.
	 *
	 * Detects whether the store uses file-based or database logging,
	 * then returns parsed entries in reverse chronological order.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$level         = $request->get_param( 'level' );
		$per_page      = max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 50 ) );
		$page          = max( 1, (int) ( $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1 ) );
		$available     = $this->get_available_sources();
		$allowed_slugs = array_column( $available, 'source' );
		$raw_source    = (string) ( $request->get_param( 'source' ) ?? self::CORE_SOURCE );

		// Resolve the requested source against the allowlist. 'all' → every allowed slug.
		if ( 'all' === $raw_source ) {
			$sources = $allowed_slugs;
		} elseif ( \in_array( $raw_source, $allowed_slugs, true ) ) {
			$sources = array( $raw_source );
		} else {
			$sources = array( self::CORE_SOURCE );
		}

		if ( 'database' === $this->get_handler_type() ) {
			$entries = $this->get_db_entries( $level, $sources );
		} else {
			$entries = $this->get_file_entries( $sources );

			// Filter by level if specified (DB does this in SQL).
			if ( $level ) {
				$entries = array_values(
					array_filter(
						$entries,
						function ( $entry ) use ( $level ) {
							return strtolower( $level ) === $entry['level'];
						}
					)
				);
			}
		}

		$total       = count( $entries );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$entries     = array_slice( $entries, $offset, $per_page );

		$response = new WP_REST_Response(
			array(
				'entries'          => $entries,
				'has_fatal_errors' => $this->has_fatal_errors(),
				'fatal_errors_url' => $this->get_fatal_errors_url(),
				'sources'          => $available,
			)
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Build the list of log sources the UI may filter by.
	 *
	 * Always includes the core `woocommerce-pos` source. Each installed
	 * catalog extension that declares a `log_source` adds an entry so its logs
	 * can be inspected alongside the core plugin's.
	 *
	 * @return array<int, array{source: string, name: string, requires_pro: bool, is_core: bool}>
	 */
	private function get_available_sources(): array {
		$sources = array(
			array(
				'source'       => self::CORE_SOURCE,
				'name'         => 'WooCommerce POS',
				'requires_pro' => false,
				'is_core'      => true,
			),
		);

		if ( ! class_exists( Extensions::class ) ) {
			return $sources;
		}

		foreach ( Extensions::instance()->get_extensions() as $entry ) {
			$log_source = $entry['log_source'] ?? '';
			$status     = $entry['status'] ?? 'not_installed';

			if ( ! \is_string( $log_source ) || '' === $log_source || 'not_installed' === $status ) {
				continue;
			}

			if ( self::CORE_SOURCE === $log_source ) {
				continue;
			}

			$sources[] = array(
				'source'       => $log_source,
				'name'         => (string) ( $entry['name'] ?? $log_source ),
				'requires_pro' => (bool) ( $entry['requires_pro'] ?? false ),
				'is_core'      => false,
			);
		}

		return $sources;
	}

	/**
	 * Maximum number of log entries to parse from files to prevent memory exhaustion.
	 */
	const MAX_FILE_ENTRIES = 10000;

	/**
	 * Parse log entries from file-based handler.
	 *
	 * Scans wc-logs/ for {source}-*.log files per requested source and parses
	 * each line. Reads files line-by-line to avoid loading entire files into
	 * memory. Processes newest files first and caps at MAX_FILE_ENTRIES total.
	 *
	 * Note: within each file, lines are read top-to-bottom (oldest first).
	 * If the cap is hit mid-file, the newest entries in that file are lost.
	 * The final result is sorted by timestamp, so ordering is still correct
	 * for the entries that are returned.
	 *
	 * @param array<int, string> $sources Allowed log-source slugs (pre-validated).
	 *
	 * @return array<int, array{timestamp: string, level: string, message: string, context: string, source: string}>
	 */
	private function get_file_entries( array $sources ): array {
		if ( empty( $sources ) ) {
			return array();
		}

		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = array();

		foreach ( $sources as $source ) {
			// WC log filenames: {source}-YYYY-MM-DD-{hash}.log.
			$matched = glob( $log_dir . $source . '-*.log' );
			if ( empty( $matched ) ) {
				continue;
			}

			// Anchor on "{source}-YYYY-MM-DD-" so we don't over-match when one
			// source is a prefix of another (e.g. "woocommerce-pos" vs
			// "woocommerce-pos-foo").
			$pattern = '/^' . preg_quote( $source, '/' ) . '-\d{4}-\d{2}-\d{2}-/';

			foreach ( $matched as $file ) {
				if ( 1 !== preg_match( $pattern, basename( $file ) ) ) {
					continue;
				}
				$files[] = array(
					'path'   => $file,
					'source' => $source,
				);
			}
		}

		if ( empty( $files ) ) {
			return array();
		}

		// Sort files by modification time descending so newest logs are processed first.
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b['path'] ) - filemtime( $a['path'] );
			}
		);

		$entries = array();
		$count   = 0;

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading local log files.
			$handle = fopen( $file['path'], 'r' );
			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$entry = $this->parse_log_line( $line );
				if ( $entry ) {
					$entry['source'] = $file['source'];
					$entries[]       = $entry;
					++$count;

					if ( $count >= self::MAX_FILE_ENTRIES ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $handle );
						break 2;
					}
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}

		// Sort by timestamp descending (newest first).
		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			}
		);

		return $entries;
	}

	/**
	 * Detect which log handler type is active.
	 *
	 * @return string 'file' or 'database'
	 */
	private function get_handler_type(): string {
		/**
		 * Filter the detected log handler type.
		 *
		 * @param string|null $type 'file' or 'database', or null for auto-detection.
		 */
		$type = apply_filters( 'woocommerce_pos_log_handler_type', null );
		if ( $type ) {
			return $type;
		}

		$handler = get_option( 'woocommerce_default_log_handler', '' );

		if ( false !== strpos( $handler, 'DB' ) || false !== strpos( $handler, 'Database' ) ) {
			return 'database';
		}

		return 'file';
	}

	/**
	 * Get log entries from the database handler.
	 *
	 * @param string|null        $level   Optional level filter.
	 * @param array<int, string> $sources Allowed log-source slugs (pre-validated).
	 *
	 * @return array
	 */
	private function get_db_entries( ?string $level, array $sources ): array {
		global $wpdb;

		if ( empty( $sources ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'woocommerce_log';

		// Check table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from count($sources).
		$where = $wpdb->prepare( "WHERE source IN ({$placeholders})", ...$sources );

		if ( $level ) {
			$severity = \WC_Log_Levels::get_level_severity( $level );
			$where   .= $wpdb->prepare( ' AND level = %d', $severity );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is safe prefix, $where is prepared above.
		$sql = "SELECT timestamp, level, message, source FROM {$table} {$where} ORDER BY timestamp DESC LIMIT 10000";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is safely constructed above with $wpdb->prepare().
		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			return array();
		}

		$level_map = array_flip(
			array(
				'emergency' => 800,
				'alert'     => 700,
				'critical'  => 600,
				'error'     => 500,
				'warning'   => 400,
				'notice'    => 300,
				'info'      => 200,
				'debug'     => 100,
			)
		);

		return array_map(
			function ( $row ) use ( $level_map ) {
				$message = $row['message'];
				$context = '';

				$context_pos = strpos( $message, ' | Context: ' );
				if ( false !== $context_pos ) {
					$context = substr( $message, $context_pos + 12 );
					$message = substr( $message, 0, $context_pos );
				}

				return array(
					'timestamp' => $row['timestamp'],
					'level'     => $level_map[ (int) $row['level'] ] ?? 'debug',
					'message'   => $message,
					'context'   => $context,
					'source'    => (string) ( $row['source'] ?? self::CORE_SOURCE ),
				);
			},
			$results
		);
	}

	/**
	 * Parse a single WC log line into a structured entry.
	 *
	 * WC log format: "TIMESTAMP LEVEL message"
	 * Context is appended after " | Context: "
	 *
	 * @param string $line Raw log line.
	 *
	 * @return array{timestamp: string, level: string, message: string, context: string}|null
	 */
	private function parse_log_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}

		// Match: timestamp (ISO 8601), level (word), rest is message.
		if ( ! preg_match( '/^(\S+)\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\s+(.*)$/i', $line, $matches ) ) {
			return null;
		}

		$message = $matches[3];
		$context = '';

		// Split context from message.
		$context_pos = strpos( $message, ' | Context: ' );
		if ( false !== $context_pos ) {
			$context = substr( $message, $context_pos + 12 );
			$message = substr( $message, 0, $context_pos );
		}

		return array(
			'timestamp' => $matches[1],
			'level'     => strtolower( $matches[2] ),
			'message'   => $message,
			'context'   => $context,
		);
	}

	/**
	 * Check if fatal-errors log files exist.
	 *
	 * @return bool
	 */
	private function has_fatal_errors(): bool {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = glob( $log_dir . 'fatal-errors-*.log' );

		return ! empty( $files );
	}

	/**
	 * Get the URL to WooCommerce's log viewer filtered to fatal errors.
	 *
	 * @return string
	 */
	private function get_fatal_errors_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=logs&source=fatal-errors' );
	}

	/**
	 * Mark logs as read for the current user.
	 *
	 * Stores the current timestamp in user meta.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		$timestamp = gmdate( 'c' );
		update_user_meta( get_current_user_id(), self::LAST_VIEWED_META_KEY, $timestamp );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'timestamp' => $timestamp,
			)
		);
	}

	/**
	 * Get unread error/warning counts for a user.
	 *
	 * This is a static method so it can be called from the Settings page
	 * to inject initial counts into the inline script.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array{error: int, warning: int}
	 */
	public static function get_unread_counts( int $user_id ): array {
		$last_viewed    = get_user_meta( $user_id, self::LAST_VIEWED_META_KEY, true );
		$last_viewed_ts = $last_viewed ? strtotime( $last_viewed ) : null;

		$instance = new self();

		if ( 'database' === $instance->get_handler_type() ) {
			return $instance->get_db_unread_counts( $last_viewed_ts );
		}

		return $instance->get_file_unread_counts( $last_viewed_ts );
	}

	/**
	 * Count unread errors/warnings from database handler using SQL aggregation.
	 *
	 * @param int|null $last_viewed_ts Unix timestamp of last viewed, or null if never viewed.
	 *
	 * @return array{error: int, warning: int}
	 */
	private function get_db_unread_counts( ?int $last_viewed_ts ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_log';

		// Check table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return array(
				'error' => 0,
				'warning' => 0,
			);
		}

		// Warning level = 400, error/critical/emergency/alert = 500-800.
		$where = $wpdb->prepare( 'WHERE source = %s AND level >= %d', 'woocommerce-pos', 400 );

		if ( $last_viewed_ts ) {
			$last_viewed_date = gmdate( 'Y-m-d H:i:s', $last_viewed_ts );
			$where           .= $wpdb->prepare( ' AND timestamp > %s', $last_viewed_date );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is safe prefix, $where is prepared above.
		$sql = "SELECT level, COUNT(*) as cnt FROM {$table} {$where} GROUP BY level";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is safely constructed above with $wpdb->prepare().
		$results = $wpdb->get_results( $sql, ARRAY_A );

		$counts = array(
			'error' => 0,
			'warning' => 0,
		);

		foreach ( $results as $row ) {
			$severity = (int) $row['level'];
			// Warning = 400, everything above is error-class.
			if ( 400 === $severity ) {
				$counts['warning'] += (int) $row['cnt'];
			} else {
				$counts['error'] += (int) $row['cnt'];
			}
		}

		return $counts;
	}

	/**
	 * Count unread errors/warnings from file-based handler by streaming.
	 *
	 * Reads log files line-by-line without loading all entries into memory.
	 *
	 * @param int|null $last_viewed_ts Unix timestamp of last viewed, or null if never viewed.
	 *
	 * @return array{error: int, warning: int}
	 */
	private function get_file_unread_counts( ?int $last_viewed_ts ): array {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = glob( $log_dir . 'woocommerce-pos-*.log' );

		$counts       = array(
			'error' => 0,
			'warning' => 0,
		);
		$error_levels = array( 'error', 'critical', 'emergency', 'alert' );

		if ( empty( $files ) ) {
			return $counts;
		}

		// Sort files newest-first so the cap preserves recent entries.
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		$matched = 0;

		foreach ( $files as $file ) {
			// Skip files older than last_viewed based on modification time.
			if ( $last_viewed_ts && filemtime( $file ) <= $last_viewed_ts ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading local log files.
			$handle = fopen( $file, 'r' );
			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				// Quick regex to extract just timestamp and level without full parsing.
				if ( ! preg_match( '/^(\S+)\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING)\s/i', $line, $matches ) ) {
					continue;
				}

				$entry_level = strtolower( $matches[2] );
				$entry_ts    = strtotime( $matches[1] );

				if ( $last_viewed_ts && $entry_ts && $entry_ts <= $last_viewed_ts ) {
					continue;
				}

				$level_key = in_array( $entry_level, $error_levels, true ) ? 'error' : 'warning';
				++$counts[ $level_key ];

				++$matched;
				if ( $matched >= self::MAX_FILE_ENTRIES ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
					break 2;
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}

		return $counts;
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permissions( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view logs.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
