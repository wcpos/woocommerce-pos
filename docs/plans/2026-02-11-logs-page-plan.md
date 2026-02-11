# Logs Page Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Add a Logs tab to POS Settings that surfaces warning/error messages with severity badges in the nav sidebar.

**Architecture:** New REST controller (`Logs.php`) reads from either file-based or DB-based WC log handlers. Frontend screen fetches entries via TanStack Query. Unread counts use the same `useSyncExternalStore` pattern as extensions, with initial values server-rendered into `window.wcpos.settings`.

**Tech Stack:** PHP (WP REST API, WC Logger internals), React, TanStack Query, TanStack Router, Tailwind CSS (prefixed `wcpos:`)

**Worktree:** `/Users/kilbot/Projects/woocommerce-pos/.worktrees/feature-logs-page`

**Test command:** `npx wp-env run --env-cwd='wp-content/plugins/feature-logs-page' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --verbose`

**Lint command:** `composer run lint`

**Design doc:** `docs/plans/2026-02-11-logs-page-design.md`

---

## Task 1: Logs REST Controller — Scaffold & Route Registration

**Files:**
- Create: `includes/API/Logs.php`
- Modify: `includes/API.php:99-125` (add `'logs'` to controller array)
- Create: `tests/includes/API/Test_Logs_Controller.php`

### Step 1: Write the failing test

Create `tests/includes/API/Test_Logs_Controller.php`:

```php
<?php
/**
 * Test Logs Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Logs;

/**
 * Logs Controller test case.
 */
class Test_Logs_Controller extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Logs();
	}

	/**
	 * Test that the routes are registered.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/logs', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/logs/mark-read', $routes );
	}

	/**
	 * Test the namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );
		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test the rest_base property.
	 */
	public function test_rest_base_property(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );
		$this->assertEquals( 'logs', $rest_base );
	}

	/**
	 * Test GET logs requires auth.
	 */
	public function test_get_logs_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test POST mark-read requires auth.
	 */
	public function test_mark_read_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_post_request( '/wcpos/v1/logs/mark-read' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}
}
```

### Step 2: Run test to verify it fails

Run: the test command filtered to `Test_Logs_Controller`
Expected: FAIL — class `WCPOS\WooCommercePOS\API\Logs` not found.

### Step 3: Write the controller scaffold

Create `includes/API/Logs.php`:

```php
<?php
/**
 * Logs REST API controller.
 *
 * Surfaces POS log entries for the settings screen.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

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
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		return new WP_REST_Response( array() );
	}

	/**
	 * Mark logs as read for the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => true ) );
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
```

### Step 4: Register the controller

In `includes/API.php`, add `'logs'` to the controller array (around line 110, after `'extensions'`):

```php
'extensions'            => API\Extensions::class,
'logs'                  => API\Logs::class,
```

### Step 5: Run tests to verify they pass

Run: the test command
Expected: All pass, including the new `Test_Logs_Controller` tests.

### Step 6: Run lint

Run: `composer run lint`
Fix any issues before committing.

### Step 7: Commit

```bash
git add includes/API/Logs.php includes/API.php tests/includes/API/Test_Logs_Controller.php
git commit -m "feat(api): scaffold logs REST controller with route registration (#504)"
```

---

## Task 2: GET /logs — File-Based Log Parsing

**Files:**
- Modify: `includes/API/Logs.php` (implement `get_items` with file parsing)
- Modify: `tests/includes/API/Test_Logs_Controller.php` (add log parsing tests)

### Step 1: Write the failing tests

Add these tests to `Test_Logs_Controller.php`:

```php
/**
 * Test GET logs returns 200 with empty array when no logs exist.
 */
public function test_get_logs_returns_empty_when_no_logs(): void {
	$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$response = $this->server->dispatch( $request );

	$this->assertEquals( 200, $response->get_status() );
	$data = $response->get_data();
	$this->assertArrayHasKey( 'entries', $data );
	$this->assertEmpty( $data['entries'] );
}

/**
 * Test GET logs returns parsed file-based log entries.
 */
public function test_get_logs_parses_file_entries(): void {
	$this->create_test_log_file(
		"2026-02-11T10:00:00+00:00 ERROR Test error message\n" .
		"2026-02-11T09:00:00+00:00 WARNING Test warning | Context: some context\n" .
		"2026-02-11T08:00:00+00:00 INFO Test info message\n"
	);

	$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$response = $this->server->dispatch( $request );

	$this->assertEquals( 200, $response->get_status() );
	$data = $response->get_data();
	$this->assertCount( 3, $data['entries'] );

	// Entries should be in reverse chronological order (newest first).
	$this->assertEquals( 'error', $data['entries'][0]['level'] );
	$this->assertEquals( 'Test error message', $data['entries'][0]['message'] );
	$this->assertEquals( 'warning', $data['entries'][1]['level'] );
	$this->assertEquals( 'Test warning', $data['entries'][1]['message'] );
	$this->assertEquals( 'some context', $data['entries'][1]['context'] );
	$this->assertEquals( 'info', $data['entries'][2]['level'] );
}

/**
 * Test GET logs filters by level.
 */
public function test_get_logs_filters_by_level(): void {
	$this->create_test_log_file(
		"2026-02-11T10:00:00+00:00 ERROR Test error\n" .
		"2026-02-11T09:00:00+00:00 WARNING Test warning\n" .
		"2026-02-11T08:00:00+00:00 INFO Test info\n"
	);

	$request = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$request->set_param( 'level', 'error' );
	$response = $this->server->dispatch( $request );

	$data = $response->get_data();
	$this->assertCount( 1, $data['entries'] );
	$this->assertEquals( 'error', $data['entries'][0]['level'] );
}

/**
 * Test GET logs returns has_fatal_errors flag.
 */
public function test_get_logs_returns_has_fatal_errors(): void {
	$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$response = $this->server->dispatch( $request );

	$data = $response->get_data();
	$this->assertArrayHasKey( 'has_fatal_errors', $data );
	$this->assertIsBool( $data['has_fatal_errors'] );
}

/**
 * Test GET logs supports pagination.
 */
public function test_get_logs_supports_pagination(): void {
	$lines = '';
	for ( $i = 0; $i < 30; $i++ ) {
		$lines .= sprintf( "2026-02-11T%02d:00:00+00:00 INFO Message %d\n", $i % 24, $i );
	}
	$this->create_test_log_file( $lines );

	$request = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$request->set_param( 'per_page', 10 );
	$request->set_param( 'page', 1 );
	$response = $this->server->dispatch( $request );

	$data = $response->get_data();
	$this->assertCount( 10, $data['entries'] );
	$this->assertEquals( '30', $response->get_headers()['X-WP-Total'] );
	$this->assertEquals( '3', $response->get_headers()['X-WP-TotalPages'] );
}
```

Add a helper method to the test class:

```php
/**
 * Create a test log file in the WC logs directory.
 *
 * @param string $content Log file content.
 *
 * @return string File path.
 */
private function create_test_log_file( string $content ): string {
	$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
	if ( ! is_dir( $log_dir ) ) {
		mkdir( $log_dir, 0755, true );
	}
	$file = $log_dir . 'woocommerce-pos-2026-02-11-test.log';
	file_put_contents( $file, $content );

	return $file;
}
```

Also add cleanup to `setUp` and `tearDown`:

```php
public function setUp(): void {
	parent::setUp();
	$this->endpoint = new Logs();
	$this->clean_log_files();
}

public function tearDown(): void {
	$this->clean_log_files();
	parent::tearDown();
}

/**
 * Remove test log files.
 */
private function clean_log_files(): void {
	$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
	$files   = glob( $log_dir . 'woocommerce-pos-*.log' );
	if ( $files ) {
		array_map( 'unlink', $files );
	}
}
```

### Step 2: Run tests to verify they fail

Expected: FAIL — `get_items` returns empty array, no `entries` key.

### Step 3: Implement file-based log parsing in `get_items`

Replace the `get_items` method in `includes/API/Logs.php`:

```php
/**
 * User meta key for last-viewed timestamp.
 *
 * @var string
 */
const LAST_VIEWED_META_KEY = '_wcpos_logs_last_viewed';

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
	$level    = $request->get_param( 'level' );
	$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
	$page     = (int) ( $request->get_param( 'page' ) ?: 1 );

	$entries = $this->get_file_entries();

	// Filter by level if specified.
	if ( $level ) {
		$entries = array_values(
			array_filter(
				$entries,
				function ( $entry ) use ( $level ) {
					return $entry['level'] === strtolower( $level );
				}
			)
		);
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
		)
	);

	$response->header( 'X-WP-Total', (string) $total );
	$response->header( 'X-WP-TotalPages', (string) $total_pages );

	return $response;
}

/**
 * Parse log entries from file-based handler.
 *
 * Scans wc-logs/ for woocommerce-pos-*.log files and parses each line.
 *
 * @return array<int, array{timestamp: string, level: string, message: string, context: string}>
 */
private function get_file_entries(): array {
	$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
	$files   = glob( $log_dir . 'woocommerce-pos-*.log' );

	if ( empty( $files ) ) {
		return array();
	}

	$entries = array();

	foreach ( $files as $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			continue;
		}

		$lines = explode( "\n", trim( $contents ) );
		foreach ( $lines as $line ) {
			$entry = $this->parse_log_line( $line );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}
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
```

### Step 4: Run tests to verify they pass

### Step 5: Run lint and fix

### Step 6: Commit

```bash
git add includes/API/Logs.php tests/includes/API/Test_Logs_Controller.php
git commit -m "feat(api): implement file-based log parsing for GET /logs (#504)"
```

---

## Task 3: GET /logs — Database Log Handler Support

**Files:**
- Modify: `includes/API/Logs.php` (add DB handler support, detect active handler)
- Modify: `tests/includes/API/Test_Logs_Controller.php` (add DB handler tests)

### Step 1: Write the failing tests

```php
/**
 * Test GET logs reads from database when DB handler is active.
 */
public function test_get_logs_reads_from_database(): void {
	$this->insert_db_log_entry( 'error', 'DB error message', 'woocommerce-pos' );
	$this->insert_db_log_entry( 'warning', 'DB warning message', 'woocommerce-pos' );
	$this->insert_db_log_entry( 'info', 'Other source message', 'other-plugin' );

	// Force DB handler detection.
	add_filter( 'woocommerce_pos_log_handler_type', function () {
		return 'database';
	} );

	$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
	$response = $this->server->dispatch( $request );

	$data = $response->get_data();
	$this->assertCount( 2, $data['entries'] );
	$this->assertEquals( 'error', $data['entries'][0]['level'] );
	$this->assertEquals( 'DB error message', $data['entries'][0]['message'] );

	remove_all_filters( 'woocommerce_pos_log_handler_type' );
}
```

Add a helper to insert DB log entries:

```php
/**
 * Insert a log entry into the WC log database table.
 *
 * @param string $level   Log level.
 * @param string $message Log message.
 * @param string $source  Log source.
 */
private function insert_db_log_entry( string $level, string $message, string $source ): void {
	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'woocommerce_log',
		array(
			'timestamp' => current_time( 'mysql', true ),
			'level'     => \WC_Log_Levels::get_level_severity( $level ),
			'message'   => $message,
			'source'    => $source,
		),
		array( '%s', '%d', '%s', '%s' )
	);
}
```

Also add cleanup for DB entries in `tearDown`:

```php
public function tearDown(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_log WHERE source = 'woocommerce-pos'" );
	$this->clean_log_files();
	parent::tearDown();
}
```

### Step 2: Run tests to verify they fail

### Step 3: Implement DB handler support

Add these methods to `includes/API/Logs.php`. Update `get_items` to detect handler:

```php
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
	$level    = $request->get_param( 'level' );
	$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
	$page     = (int) ( $request->get_param( 'page' ) ?: 1 );

	if ( 'database' === $this->get_handler_type() ) {
		$entries = $this->get_db_entries( $level );
	} else {
		$entries = $this->get_file_entries();

		// Filter by level if specified (DB does this in SQL).
		if ( $level ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $level ) {
						return $entry['level'] === strtolower( $level );
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
		)
	);

	$response->header( 'X-WP-Total', (string) $total );
	$response->header( 'X-WP-TotalPages', (string) $total_pages );

	return $response;
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
	 * @param string $type 'file' or 'database'.
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
 * @param string|null $level Optional level filter.
 *
 * @return array
 */
private function get_db_entries( ?string $level = null ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'woocommerce_log';

	// Check table exists.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $table_exists ) {
		return array();
	}

	$where = $wpdb->prepare( 'WHERE source = %s', 'woocommerce-pos' );

	if ( $level ) {
		$severity = \WC_Log_Levels::get_level_severity( $level );
		$where   .= $wpdb->prepare( ' AND level = %d', $severity );
	}

	$results = $wpdb->get_results(
		"SELECT timestamp, level, message FROM {$table} {$where} ORDER BY timestamp DESC",
		ARRAY_A
	);

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
			);
		},
		$results
	);
}
```

### Step 4: Run tests to verify they pass

Note: The DB test may need the `woocommerce_log` table to exist. If WC creates it on activation, it should be present in the wp-env test environment. If the table doesn't exist, the test will need a `CREATE TABLE` in setUp — adjust as needed.

### Step 5: Run lint and fix

### Step 6: Commit

```bash
git add includes/API/Logs.php tests/includes/API/Test_Logs_Controller.php
git commit -m "feat(api): add database log handler support for GET /logs (#504)"
```

---

## Task 4: POST /logs/mark-read & Unread Count Calculation

**Files:**
- Modify: `includes/API/Logs.php` (implement `mark_read`, add `get_unread_counts` static method)
- Modify: `tests/includes/API/Test_Logs_Controller.php`

### Step 1: Write the failing tests

```php
/**
 * Test POST mark-read stores timestamp in user meta.
 */
public function test_mark_read_stores_timestamp(): void {
	$request  = $this->wp_rest_post_request( '/wcpos/v1/logs/mark-read' );
	$response = $this->server->dispatch( $request );

	$this->assertEquals( 200, $response->get_status() );
	$data = $response->get_data();
	$this->assertTrue( $data['success'] );

	$timestamp = get_user_meta( get_current_user_id(), '_wcpos_logs_last_viewed', true );
	$this->assertNotEmpty( $timestamp );
}

/**
 * Test unread counts returns error and warning counts since last viewed.
 */
public function test_get_unread_counts_since_last_viewed(): void {
	// Create log entries.
	$this->create_test_log_file(
		"2026-02-11T10:00:00+00:00 ERROR New error\n" .
		"2026-02-11T09:00:00+00:00 WARNING New warning\n" .
		"2026-02-11T08:00:00+00:00 WARNING Another warning\n" .
		"2026-02-10T08:00:00+00:00 ERROR Old error\n"
	);

	// Set last viewed to between old and new entries.
	update_user_meta(
		get_current_user_id(),
		'_wcpos_logs_last_viewed',
		'2026-02-11T00:00:00+00:00'
	);

	$counts = \WCPOS\WooCommercePOS\API\Logs::get_unread_counts( get_current_user_id() );
	$this->assertEquals( 1, $counts['error'] );
	$this->assertEquals( 2, $counts['warning'] );
}

/**
 * Test unread counts with no last-viewed returns all errors and warnings.
 */
public function test_get_unread_counts_no_last_viewed(): void {
	$this->create_test_log_file(
		"2026-02-11T10:00:00+00:00 ERROR An error\n" .
		"2026-02-11T09:00:00+00:00 INFO Just info\n"
	);

	$counts = \WCPOS\WooCommercePOS\API\Logs::get_unread_counts( get_current_user_id() );
	$this->assertEquals( 1, $counts['error'] );
	$this->assertEquals( 0, $counts['warning'] );
}
```

### Step 2: Run tests to verify they fail

### Step 3: Implement mark_read and get_unread_counts

Update `mark_read` in `includes/API/Logs.php`:

```php
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
	$last_viewed = get_user_meta( $user_id, self::LAST_VIEWED_META_KEY, true );

	$instance = new self();
	$entries  = ( 'database' === $instance->get_handler_type() )
		? $instance->get_db_entries()
		: $instance->get_file_entries();

	$counts = array(
		'error'   => 0,
		'warning' => 0,
	);

	foreach ( $entries as $entry ) {
		if ( ! in_array( $entry['level'], array( 'error', 'warning', 'critical', 'emergency', 'alert' ), true ) ) {
			continue;
		}

		// If no last_viewed, all entries are "unread".
		if ( $last_viewed && $entry['timestamp'] <= $last_viewed ) {
			continue;
		}

		$level_key = in_array( $entry['level'], array( 'error', 'critical', 'emergency', 'alert' ), true )
			? 'error'
			: 'warning';

		++$counts[ $level_key ];
	}

	return $counts;
}
```

### Step 4: Run tests to verify they pass

### Step 5: Run lint and fix

### Step 6: Commit

```bash
git add includes/API/Logs.php tests/includes/API/Test_Logs_Controller.php
git commit -m "feat(api): implement mark-read and unread count calculation (#504)"
```

---

## Task 5: Server-Render Unread Counts in Inline Script

**Files:**
- Modify: `includes/Admin/Settings.php` (add `unreadLogCounts` to inline script)

### Step 1: Write the failing test

No PHPUnit test for this — it's wiring up existing pieces. Verify manually after implementation.

### Step 2: Modify the inline script

In `includes/Admin/Settings.php`, add the `use` statement at the top:

```php
use WCPOS\WooCommercePOS\API\Logs;
```

Update `inline_script()` to include unread counts:

```php
private function inline_script(): string {
	$settings_service = SettingsService::instance();
	$barcodes         = array_values( $settings_service->get_barcodes() );
	$order_statuses   = $settings_service->get_order_statuses();
	$new_ext_count    = $this->get_new_extensions_count();
	$unread_logs      = Logs::get_unread_counts( get_current_user_id() );

	return \sprintf(
		'var wcpos = wcpos || {}; wcpos.settings = {
            barcodes: %s,
            order_statuses: %s,
            newExtensionsCount: %s,
            unreadLogCounts: %s
        }; wcpos.translationVersion = %s;',
		json_encode( $barcodes ),
		json_encode( $order_statuses ),
		json_encode( $new_ext_count ),
		json_encode( $unread_logs ),
		json_encode( TRANSLATION_VERSION )
	);
}
```

### Step 3: Run lint and fix

### Step 4: Commit

```bash
git add includes/Admin/Settings.php
git commit -m "feat: inject unread log counts into settings inline script (#504)"
```

---

## Task 6: Nav Sidebar Restructure — Tools & Account Groups

**Files:**
- Modify: `packages/settings/src/layouts/nav-sidebar.tsx`
- Modify: `packages/settings/src/layouts/nav-item.tsx` (extend badge prop for severity)
- Modify: `packages/settings/src/layouts/root-layout.tsx` (add page titles for new routes)
- Add translations: `packages/settings/src/translations/locales/en/wp-admin-settings.json`

### Step 1: Add translation keys

Add to `wp-admin-settings.json`:

```json
"common.tools": "Tools",
"common.account": "Account",
"common.logs": "Logs",
"common.all": "All",
"common.extensions": "Extensions",
"logs.title": "Logs",
"logs.no_entries": "No log entries found.",
"logs.errors": "Errors",
"logs.warnings": "Warnings",
"logs.fatal_errors_detected": "Fatal errors detected — <link>view in WooCommerce logs</link>.",
"logs.expand": "Show details",
"logs.collapse": "Hide details"
```

Note: `"common.all"` and `"common.extensions"` may already exist — check before adding duplicates.

### Step 2: Extend NavItem badge prop

Update `packages/settings/src/layouts/nav-item.tsx`:

```tsx
import { Link, useMatchRoute } from '@tanstack/react-router';
import classNames from 'classnames';

interface SeverityBadge {
	error?: number;
	warning?: number;
}

interface NavItemProps {
	to: string;
	label: string;
	badge?: number | SeverityBadge;
	onClick?: () => void;
}

export function NavItem({ to, label, badge, onClick }: NavItemProps) {
	const matchRoute = useMatchRoute();
	const isActive = matchRoute({ to });

	const renderBadge = () => {
		if (badge == null) return null;

		// Simple numeric badge (existing behavior).
		if (typeof badge === 'number') {
			if (badge <= 0) return null;
			return (
				<span className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:min-w-5 wcpos:h-5 wcpos:px-1.5 wcpos:rounded-full wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:text-xs wcpos:font-medium wcpos:leading-none">
					{badge}
				</span>
			);
		}

		// Severity badge (error + warning pills).
		const { error = 0, warning = 0 } = badge;
		if (error <= 0 && warning <= 0) return null;

		return (
			<span className="wcpos:inline-flex wcpos:items-center wcpos:gap-1">
				{error > 0 && (
					<span className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:min-w-5 wcpos:h-5 wcpos:px-1.5 wcpos:rounded-full wcpos:bg-red-600 wcpos:text-white wcpos:text-xs wcpos:font-medium wcpos:leading-none">
						{error}
					</span>
				)}
				{warning > 0 && (
					<span className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:min-w-5 wcpos:h-5 wcpos:px-1.5 wcpos:rounded-full wcpos:bg-amber-500 wcpos:text-white wcpos:text-xs wcpos:font-medium wcpos:leading-none">
						{warning}
					</span>
				)}
			</span>
		);
	};

	return (
		<Link
			to={to}
			onClick={onClick}
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:no-underline wcpos:border-l-3 wcpos:transition-colors wcpos:hover:bg-gray-100 wcpos:focus-visible:outline-none wcpos:focus-visible:bg-gray-100',
				isActive
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest wcpos:text-gray-900 wcpos:font-semibold'
					: 'wcpos:border-transparent wcpos:text-gray-600 wcpos:hover:text-gray-900 wcpos:hover:bg-gray-50'
			)}
		>
			{label}
			{renderBadge()}
		</Link>
	);
}
```

### Step 3: Restructure NavSidebar

Update `packages/settings/src/layouts/nav-sidebar.tsx`:

```tsx
import { NavGroup } from './nav-group';
import { NavItem } from './nav-item';
import PosIcon from '../../assets/wcpos-icon.svg';
import { useNewExtensionsCount } from '../screens/extensions/use-new-extensions-count';
import { useUnreadLogCounts } from '../screens/logs/use-unread-log-counts';
import { useRegisteredPages } from '../store/use-registry';
import { t } from '../translations';

interface NavSidebarProps {
	isOpen: boolean;
	onNavItemClick?: () => void;
}

export function NavSidebar({ isOpen, onNavItemClick }: NavSidebarProps) {
	const toolsPages = useRegisteredPages('tools');
	const accountPages = useRegisteredPages('account');
	const newExtensionsCount = useNewExtensionsCount();
	const unreadLogCounts = useUnreadLogCounts();

	return (
		<aside
			aria-hidden={!isOpen}
			className={[
				'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col wcpos:transition-[margin] wcpos:duration-300 wcpos:ease-in-out',
				'wcpos:lg:ml-0',
				isOpen
					? 'wcpos:ml-0'
					: 'wcpos:-ml-56 wcpos:pointer-events-none wcpos:invisible wcpos:lg:visible wcpos:lg:pointer-events-auto',
			].join(' ')}
		>
			{/* Logo + title */}
			<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-4 wcpos:border-b wcpos:border-gray-200 wcpos:h-12">
				<div className="wcpos:w-8">
					<PosIcon />
				</div>
				<span className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900">WCPOS</span>
			</div>

			{/* Nav groups */}
			<div className="wcpos:flex-1 wcpos:overflow-y-auto wcpos:py-2">
				<NavGroup heading={t('common.settings')}>
					<NavItem to="/general" label={t('common.general')} onClick={onNavItemClick} />
					<NavItem to="/checkout" label={t('common.checkout')} onClick={onNavItemClick} />
					<NavItem to="/access" label={t('common.access')} onClick={onNavItemClick} />
					<NavItem to="/sessions" label={t('sessions.sessions')} onClick={onNavItemClick} />
					<NavItem
						to="/extensions"
						label={t('common.extensions', 'Extensions')}
						badge={newExtensionsCount ?? undefined}
						onClick={onNavItemClick}
					/>
				</NavGroup>

				<NavGroup heading={t('common.tools', 'Tools')}>
					<NavItem
						to="/logs"
						label={t('common.logs', 'Logs')}
						badge={unreadLogCounts}
						onClick={onNavItemClick}
					/>
					{toolsPages.map((page) => (
						<NavItem
							key={page.id}
							to={`/${page.id}`}
							label={page.label}
							onClick={onNavItemClick}
						/>
					))}
				</NavGroup>

				<NavGroup heading={t('common.account', 'Account')}>
					<NavItem to="/license" label={t('common.license')} onClick={onNavItemClick} />
					{accountPages.map((page) => (
						<NavItem
							key={page.id}
							to={`/${page.id}`}
							label={page.label}
							onClick={onNavItemClick}
						/>
					))}
				</NavGroup>
			</div>
		</aside>
	);
}
```

### Step 4: Add page title for logs

In `packages/settings/src/layouts/root-layout.tsx`, add to the `pageTitles` map:

```ts
const pageTitles: Record<string, string> = {
	'/general': 'common.general',
	'/checkout': 'common.checkout',
	'/access': 'common.access',
	'/sessions': 'sessions.sessions',
	'/license': 'common.license',
	'/logs': 'common.logs',
};
```

### Step 5: Commit

```bash
git add packages/settings/src/layouts/nav-item.tsx packages/settings/src/layouts/nav-sidebar.tsx packages/settings/src/layouts/root-layout.tsx packages/settings/src/translations/locales/en/wp-admin-settings.json
git commit -m "feat(ui): restructure nav sidebar into Settings/Tools/Account groups (#504)"
```

---

## Task 7: Unread Log Counts Hook (Frontend)

**Files:**
- Create: `packages/settings/src/screens/logs/use-unread-log-counts.ts`

### Step 1: Create the hook

Create `packages/settings/src/screens/logs/use-unread-log-counts.ts`:

```ts
import { useSyncExternalStore } from 'react';

import apiFetch from '@wordpress/api-fetch';

type Listener = () => void;

interface UnreadLogCounts {
	error: number;
	warning: number;
}

let counts: UnreadLogCounts = (window as any)?.wcpos?.settings?.unreadLogCounts ?? {
	error: 0,
	warning: 0,
};

const listeners = new Set<Listener>();

function emitChange() {
	for (const listener of listeners) {
		listener();
	}
}

function subscribe(listener: Listener) {
	listeners.add(listener);
	return () => listeners.delete(listener);
}

function getSnapshot(): UnreadLogCounts {
	return counts;
}

/**
 * React hook that returns unread error/warning counts since last viewed.
 */
export function useUnreadLogCounts(): UnreadLogCounts {
	return useSyncExternalStore(subscribe, getSnapshot);
}

/**
 * POST to the REST endpoint to mark logs as read, then reset counts to zero.
 */
export async function markLogsRead() {
	counts = { error: 0, warning: 0 };
	emitChange();

	await apiFetch({
		path: 'wcpos/v1/logs/mark-read?wcpos=1',
		method: 'POST',
	});
}
```

### Step 2: Commit

```bash
git add packages/settings/src/screens/logs/use-unread-log-counts.ts
git commit -m "feat(ui): add unread log counts hook with useSyncExternalStore (#504)"
```

---

## Task 8: Logs Screen Component & Route

**Files:**
- Create: `packages/settings/src/screens/logs/index.tsx`
- Modify: `packages/settings/src/router.tsx` (add logs route)

### Step 1: Create the logs screen

Create `packages/settings/src/screens/logs/index.tsx`:

```tsx
import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { markLogsRead } from './use-unread-log-counts';
import Notice from '../../components/notice';
import { t } from '../../translations';

interface LogEntry {
	timestamp: string;
	level: string;
	message: string;
	context: string;
}

interface LogsResponse {
	entries: LogEntry[];
	has_fatal_errors: boolean;
	fatal_errors_url: string;
}

const LEVEL_STYLES: Record<string, string> = {
	error: 'wcpos:bg-red-100 wcpos:text-red-800',
	critical: 'wcpos:bg-red-100 wcpos:text-red-800',
	emergency: 'wcpos:bg-red-100 wcpos:text-red-800',
	alert: 'wcpos:bg-red-100 wcpos:text-red-800',
	warning: 'wcpos:bg-amber-100 wcpos:text-amber-800',
	info: 'wcpos:bg-blue-100 wcpos:text-blue-800',
	notice: 'wcpos:bg-blue-100 wcpos:text-blue-800',
	debug: 'wcpos:bg-gray-100 wcpos:text-gray-600',
};

function Logs() {
	const [filter, setFilter] = React.useState<string>('all');
	const [expandedIndex, setExpandedIndex] = React.useState<number | null>(null);
	const [page, setPage] = React.useState(1);

	const levelParam = filter === 'all' ? '' : `&level=${filter}`;

	const { data } = useSuspenseQuery<LogsResponse>({
		queryKey: ['logs', filter, page],
		queryFn: () =>
			apiFetch({
				path: `wcpos/v1/logs?wcpos=1&per_page=50&page=${page}${levelParam}`,
				method: 'GET',
				parse: false,
			}).then(async (response: any) => {
				const json = await response.json();
				return {
					...json,
					_totalPages: parseInt(response.headers.get('X-WP-TotalPages') || '1', 10),
				};
			}),
	});

	const entries = data?.entries ?? [];
	const totalPages = (data as any)?._totalPages ?? 1;

	React.useEffect(() => {
		markLogsRead();
	}, []);

	const filters = [
		{ key: 'all', label: t('common.all', 'All') },
		{ key: 'error', label: t('logs.errors', 'Errors') },
		{ key: 'warning', label: t('logs.warnings', 'Warnings') },
	];

	return (
		<div>
			{data?.has_fatal_errors && (
				<Notice status="warning" isDismissible={false} className="wcpos:mb-4">
					{t('logs.fatal_errors_detected', 'Fatal errors detected —')}{' '}
					<a href={data.fatal_errors_url} target="_blank" rel="noopener noreferrer">
						{t('logs.view_in_wc', 'view in WooCommerce logs')}
					</a>
				</Notice>
			)}

			{/* Filter bar */}
			<div className="wcpos:flex wcpos:gap-2 wcpos:mb-4">
				{filters.map((f) => (
					<button
						key={f.key}
						onClick={() => {
							setFilter(f.key);
							setPage(1);
							setExpandedIndex(null);
						}}
						className={`wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:font-medium wcpos:transition-colors ${
							filter === f.key
								? 'wcpos:bg-wp-admin-theme-color wcpos:text-white'
								: 'wcpos:bg-gray-100 wcpos:text-gray-600 hover:wcpos:bg-gray-200'
						}`}
					>
						{f.label}
					</button>
				))}
			</div>

			{/* Entry list */}
			{entries.length === 0 ? (
				<p className="wcpos:text-sm wcpos:text-gray-500">
					{t('logs.no_entries', 'No log entries found.')}
				</p>
			) : (
				<div className="wcpos:space-y-1">
					{entries.map((entry, index) => {
						const isExpanded = expandedIndex === index;
						const isLong = entry.message.length > 100 || entry.context;
						const displayMessage = isExpanded
							? entry.message
							: entry.message.slice(0, 100) + (entry.message.length > 100 ? '...' : '');

						return (
							<div
								key={`${entry.timestamp}-${index}`}
								className="wcpos:flex wcpos:flex-col wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:px-3 wcpos:py-2"
							>
								<div
									className="wcpos:flex wcpos:items-start wcpos:gap-3 wcpos:cursor-pointer"
									onClick={() => isLong && setExpandedIndex(isExpanded ? null : index)}
								>
									<span
										className={`wcpos:inline-flex wcpos:items-center wcpos:px-2 wcpos:py-0.5 wcpos:rounded wcpos:text-xs wcpos:font-medium wcpos:shrink-0 ${
											LEVEL_STYLES[entry.level] || LEVEL_STYLES.debug
										}`}
									>
										{entry.level}
									</span>
									<span className="wcpos:text-xs wcpos:text-gray-400 wcpos:shrink-0 wcpos:font-mono">
										{entry.timestamp}
									</span>
									<span className="wcpos:text-sm wcpos:text-gray-700 wcpos:break-all">
										{displayMessage}
									</span>
								</div>
								{isExpanded && entry.context && (
									<div className="wcpos:mt-2 wcpos:ml-16 wcpos:p-2 wcpos:bg-gray-50 wcpos:rounded wcpos:text-xs wcpos:text-gray-600 wcpos:font-mono wcpos:whitespace-pre-wrap">
										{entry.context}
									</div>
								)}
							</div>
						);
					})}
				</div>
			)}

			{/* Pagination */}
			{totalPages > 1 && (
				<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:gap-2 wcpos:mt-4">
					<button
						onClick={() => setPage((p) => Math.max(1, p - 1))}
						disabled={page <= 1}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						Previous
					</button>
					<span className="wcpos:text-sm wcpos:text-gray-600">
						{page} / {totalPages}
					</span>
					<button
						onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
						disabled={page >= totalPages}
						className="wcpos:px-3 wcpos:py-1 wcpos:rounded wcpos:text-sm wcpos:border wcpos:border-gray-300 disabled:wcpos:opacity-50"
					>
						Next
					</button>
				</div>
			)}
		</div>
	);
}

export default Logs;
```

### Step 2: Add the route

In `packages/settings/src/router.tsx`, add the import and route:

```tsx
import LogsPage from './screens/logs';
```

Add the route definition after `extensionsRoute`:

```tsx
const logsRoute = createRoute({
	getParentRoute: () => rootRoute,
	path: '/logs',
	component: LogsPage,
});
```

Add `logsRoute` to the route tree:

```tsx
const routeTree = rootRoute.addChildren([
	indexRoute,
	generalRoute,
	checkoutRoute,
	accessRoute,
	sessionsRoute,
	extensionsRoute,
	licenseRoute,
	logsRoute,
]);
```

### Step 3: Commit

```bash
git add packages/settings/src/screens/logs/index.tsx packages/settings/src/router.tsx
git commit -m "feat(ui): add logs screen with filter, pagination, and expandable entries (#504)"
```

---

## Task 9: Final Integration — Test & Lint Everything

### Step 1: Run PHP tests

Run the full test suite and ensure all tests pass, including the new `Test_Logs_Controller`.

### Step 2: Run lint

Run: `composer run lint`
Fix all errors in all files touched.

### Step 3: Run JS build (if applicable)

Check that the TypeScript compiles without errors:
```bash
pnpm run build
```

### Step 4: Manual verification

1. Load the POS settings page in a browser
2. Verify the sidebar shows Settings / Tools / Account groups
3. Verify Logs appears under Tools with severity badges
4. Click Logs and verify log entries appear
5. Verify filter buttons work
6. Verify expanding entries works
7. Refresh the page — badges should be reset

### Step 5: Final commit if any fixes were needed

```bash
git add -A
git commit -m "fix: address lint and integration issues for logs page (#504)"
```
