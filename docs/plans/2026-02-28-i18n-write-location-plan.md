# i18n Write Location Fallback — Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Fix translation write failures on managed hosts by using `WP_LANG_DIR/plugins/` as primary path with `wp_upload_dir()/wcpos-languages/` fallback, and cache write failures to stop log spam.

**Architecture:** Change default write location from `PLUGIN_PATH/languages/` to `WP_LANG_DIR/plugins/`. Extract write logic into a helper method that tries primary then fallback. Cache total write failures with a 1-hour transient. Remember which path worked via a transient so subsequent requests go straight there.

**Tech Stack:** PHP, WordPress `WP_Filesystem`, WordPress transients API, PHPUnit via `WC_Unit_Test_Case`

**Design:** See `docs/plans/2026-02-28-i18n-write-location-design.md`

---

## Testing write failures

The test suite runs inside Docker (wp-env) where the process may run as root, making `chmod` unreliable for simulating permission errors. Instead, use the "blocker file" trick: create a regular file, then use a path _inside_ that file as the languages directory. Since you can't create subdirectories inside a file, `wp_mkdir_p()` fails and the write fails — reliably, on any platform, as any user.

```php
// Create a regular file that blocks directory creation
$blocker = $this->temp_lang_dir . 'blocked';
file_put_contents( $blocker, 'x' );
$unwritable_dir = $blocker . '/lang/';
// wp_mkdir_p() will fail → put_contents() will fail
```

To make the uploads fallback also fail (for testing total write failure), use the `upload_dir` filter:

```php
add_filter( 'upload_dir', function ( $dirs ) use ( $blocker ) {
    $dirs['basedir'] = $blocker . '/uploads';
    return $dirs;
} );
```

---

## Task 1: Extract `write_translation_file()` helper and add fallback path

Pure refactor of the write logic into a reusable method, plus the fallback path infrastructure. No behavior change for existing tests — all writes to the temp dir still succeed at the primary path.

**Files:**
- Modify: `includes/i18n.php:25-306`
- Test: `tests/includes/Test_i18n.php`

### Step 1: Write the failing test — primary write failure falls back to uploads

Add to `tests/includes/Test_i18n.php`, inside the `Test_i18n` class. Also update `setUp()` to clear new transient keys and `tearDown()` to clean up the fallback directory.

First, update `setUp()` to clear new transients:

```php
public function setUp(): void {
    parent::setUp();

    $this->temp_lang_dir = sys_get_temp_dir() . '/wcpos-i18n-test-' . uniqid() . '/';
    wp_mkdir_p( $this->temp_lang_dir );

    // Clear any cached transients from previous tests.
    delete_transient( 'wcpos_i18n_woocommerce-pos_de_DE' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_da' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_da_DK' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_fr' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_missing_da_DK' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_write_failed' );
    delete_transient( 'wcpos_i18n_woocommerce-pos_active_path' );
    delete_transient( 'wcpos_i18n_woocommerce-pos-pro_write_failed' );
    delete_transient( 'wcpos_i18n_woocommerce-pos-pro_active_path' );
}
```

Update `tearDown()` to clean up fallback dir:

```php
public function tearDown(): void {
    // Clean up temp language files.
    $files = glob( $this->temp_lang_dir . '*' );
    if ( $files ) {
        array_map( 'unlink', $files );
    }
    if ( is_dir( $this->temp_lang_dir ) ) {
        rmdir( $this->temp_lang_dir );
    }

    // Clean up fallback languages dir if created.
    $upload_dir    = wp_upload_dir();
    $fallback_dir  = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
    $fallback_files = glob( $fallback_dir . '*' );
    if ( $fallback_files ) {
        array_map( 'unlink', $fallback_files );
    }
    if ( is_dir( $fallback_dir ) ) {
        rmdir( $fallback_dir );
    }

    // Remove locale and upload_dir filters if set.
    remove_all_filters( 'locale' );
    remove_all_filters( 'upload_dir' );

    parent::tearDown();
}
```

Now write the test:

```php
/**
 * @covers ::download_translation
 */
public function test_write_falls_back_to_uploads_dir(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array('Hello' => 'Hallo'));",
        );
    };

    // Block primary path by placing a file where a directory is expected.
    $blocker        = $this->temp_lang_dir . 'blocked';
    file_put_contents( $blocker, 'x' );
    $unwritable_dir = $blocker . '/lang/';

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

    // File should exist at the uploads fallback path.
    $upload_dir   = wp_upload_dir();
    $fallback_dir = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
    $fallback_file = $fallback_dir . 'woocommerce-pos-de_DE.l10n.php';
    $this->assertFileExists( $fallback_file, 'Translation file should be written to uploads fallback path' );

    // Primary unwritable path should not have the file.
    $primary_file = $unwritable_dir . 'woocommerce-pos-de_DE.l10n.php';
    $this->assertFileDoesNotExist( $primary_file, 'Translation file should not exist at unwritable primary path' );

    // Clean up blocker file.
    unlink( $blocker );
}
```

### Step 2: Run test to verify it fails

Run: `pnpm run test -- --filter=test_write_falls_back_to_uploads_dir`
Expected: FAIL — `write_translation_file()` and `get_fallback_languages_path()` don't exist yet.

### Step 3: Implement `write_translation_file()`, `get_fallback_languages_path()`, and fallback logic

In `includes/i18n.php`, add a new constant and property after the existing ones (after line 28):

```php
private const WRITE_FAILED_CACHE_TTL = HOUR_IN_SECONDS;
```

Add a new property after `$last_download_status_code` (after line 65):

```php
/**
 * Whether the last download attempt failed due to filesystem write errors.
 *
 * @var bool
 */
protected bool $last_write_failed = false;
```

Add the `get_fallback_languages_path()` method after `get_missing_locale_transient_key()` (after line 204):

```php
/**
 * Get the fallback languages path using the uploads directory.
 *
 * Used when the primary languages path (WP_LANG_DIR/plugins/) is not writable.
 * The uploads directory is writable on any functioning WordPress install.
 *
 * @return string
 */
protected function get_fallback_languages_path(): string {
    $upload_dir = wp_upload_dir();

    return trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
}
```

Add the `get_write_failed_transient_key()` method after that:

```php
/**
 * Build the transient key used for write-failure caching.
 *
 * @return string
 */
protected function get_write_failed_transient_key(): string {
    return $this->transient_key . '_write_failed';
}
```

Add the `write_translation_file()` method after `maybe_convert_file_format()` (after line 233):

```php
/**
 * Write translation content to a file using WP_Filesystem.
 *
 * @param string $file The target file path.
 * @param string $body The file content to write.
 *
 * @return bool Whether the write was successful.
 */
protected function write_translation_file( string $file, string $body ): bool {
    $dir = dirname( $file );
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . '/wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
        return false;
    }

    return $wp_filesystem->put_contents( $file, $body, FS_CHMOD_FILE );
}
```

Replace the entire `download_translation()` method (lines 244-305) with:

```php
/**
 * Download a translation file from jsDelivr.
 *
 * Tries writing to the primary languages path first. If that fails,
 * falls back to the uploads directory. If both fail, sets
 * $last_write_failed so the caller can cache the failure.
 *
 * @param string $locale            The locale code (e.g., de_DE).
 * @param string $file              The target file path.
 * @param bool   $suppress_404_logs Suppress 404 logging for fallback attempts.
 *
 * @return bool Whether the download and write was successful.
 */
protected function download_translation( string $locale, string $file, bool $suppress_404_logs = false ): bool {
    $url = sprintf( self::CDN_BASE_URL, $this->version, $locale, $this->text_domain, $locale );
    $this->last_download_status_code = null;
    $this->last_write_failed         = false;

    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 10,
        )
    );

    if ( is_wp_error( $response ) ) {
        Logger::log( sprintf( 'i18n: Failed to download %s translation - HTTP error: %s', $locale, $response->get_error_message() ) );

        return false;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status_code ) {
        $this->last_download_status_code = $status_code;

        if ( ! ( $suppress_404_logs && 404 === $status_code ) ) {
            Logger::log( sprintf( 'i18n: Failed to download %s translation - HTTP %d from %s', $locale, $status_code, $url ) );
        }

        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        Logger::log( sprintf( 'i18n: Failed to download %s translation - empty response body from %s', $locale, $url ) );

        return false;
    }

    // Try writing to primary path.
    if ( $this->write_translation_file( $file, $body ) ) {
        return true;
    }

    // Primary write failed — try uploads fallback.
    $fallback_path = $this->get_fallback_languages_path();
    if ( $fallback_path !== $this->languages_path ) {
        $fallback_file = $fallback_path . basename( $file );
        if ( $this->write_translation_file( $fallback_file, $body ) ) {
            Logger::log( sprintf( 'i18n: Primary path not writable, using fallback for %s translations: %s', $locale, $fallback_path ) );
            $this->languages_path = $fallback_path;
            set_transient( $this->transient_key . '_active_path', 'uploads', MONTH_IN_SECONDS );

            return true;
        }
    }

    // Both paths failed (or already at fallback and it failed).
    $this->last_write_failed = true;
    Logger::log( sprintf( 'i18n: Failed to write %s translation to any writable location', $locale ) );

    return false;
}
```

### Step 4: Run test to verify it passes

Run: `pnpm run test -- --filter=test_write_falls_back_to_uploads_dir`
Expected: PASS

### Step 5: Run full test suite to verify no regressions

Run: `pnpm run test -- --filter=Test_i18n`
Expected: All existing tests PASS

### Step 6: Write failing test — fallback path remembered via transient

```php
/**
 * @covers ::download_translation
 */
public function test_fallback_path_sets_active_path_transient(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    $blocker        = $this->temp_lang_dir . 'blocked';
    file_put_contents( $blocker, 'x' );
    $unwritable_dir = $blocker . '/lang/';

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

    $this->assertEquals(
        'uploads',
        get_transient( 'wcpos_i18n_woocommerce-pos_active_path' ),
        'Active path transient should be set to "uploads" after fallback write succeeds'
    );

    unlink( $blocker );
}
```

### Step 7: Run test to verify it passes (should pass from step 3 implementation)

Run: `pnpm run test -- --filter=test_fallback_path_sets_active_path_transient`
Expected: PASS (already implemented in step 3)

### Step 8: Write failing test — primary write success does not set fallback transient

```php
/**
 * @covers ::download_translation
 */
public function test_primary_write_success_does_not_set_fallback_transient(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    // temp_lang_dir is writable — primary write should succeed.
    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

    $this->assertFalse(
        get_transient( 'wcpos_i18n_woocommerce-pos_active_path' ),
        'Active path transient should not be set when primary write succeeds'
    );

    // Uploads fallback dir should not exist.
    $upload_dir   = wp_upload_dir();
    $fallback_dir = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
    $this->assertDirectoryDoesNotExist( $fallback_dir, 'Fallback directory should not be created when primary write succeeds' );
}
```

### Step 9: Run test to verify it passes

Run: `pnpm run test -- --filter=test_primary_write_success_does_not_set_fallback_transient`
Expected: PASS

### Step 10: Commit

```bash
git add includes/i18n.php tests/includes/Test_i18n.php
git commit -m "feat(i18n): extract write helper and add uploads fallback path (#565)

When put_contents() fails at the primary languages path, retry at
wp_upload_dir()/wcpos-languages/. Remember which path worked via a
transient so subsequent requests go straight there."
```

---

## Task 2: Write-failure caching in `load_translations()`

When both write locations fail, cache the failure with a 1-hour transient so we stop retrying on every request.

**Files:**
- Modify: `includes/i18n.php` (load_translations method)
- Test: `tests/includes/Test_i18n.php`

### Step 1: Write failing test — write failure at both locations sets transient

```php
/**
 * @covers ::load_translations
 * @covers ::download_translation
 */
public function test_write_failure_at_both_locations_is_cached(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    // Block both primary and fallback paths.
    $blocker = $this->temp_lang_dir . 'blocked';
    file_put_contents( $blocker, 'x' );
    $unwritable_dir = $blocker . '/lang/';

    add_filter( 'upload_dir', function ( $dirs ) use ( $blocker ) {
        $dirs['basedir'] = $blocker . '/uploads';
        return $dirs;
    } );

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

    $this->assertEquals(
        '1.8.7',
        get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
        'Write failure transient should be set when both locations fail'
    );

    unlink( $blocker );
}
```

### Step 2: Run test to verify it fails

Run: `pnpm run test -- --filter=test_write_failure_at_both_locations_is_cached`
Expected: FAIL — write-failure transient logic not yet in `load_translations()`.

### Step 3: Implement write-failure transient set/check in `load_translations()`

In `load_translations()`, add a write-failure check **after** the missing-locale check (after the `return` on line 124) and **before** the download loop:

```php
// Avoid repeated download attempts when filesystem is not writable.
if ( get_transient( $this->get_write_failed_transient_key() ) === $this->version ) {
    if ( $stale_file && $stale_locale ) {
        $this->load_translation_file( $stale_locale, $stale_file );
    }

    return;
}
```

In the download loop, after a successful download (the `if ( $downloaded )` block), re-derive `$file` in case the path switched to fallback, and clear the write-failure transient:

```php
if ( $downloaded ) {
    // Re-derive file path in case download_translation() switched to fallback.
    $file = $this->languages_path . $this->text_domain . '-' . $candidate_locale . '.l10n.php';
    set_transient( $this->transient_key . '_' . $candidate_locale, $this->version, WEEK_IN_SECONDS );
    delete_transient( $this->get_missing_locale_transient_key( $requested_locale ) );
    delete_transient( $this->get_write_failed_transient_key() );
    $this->load_translation_file( $candidate_locale, $file );

    return;
}
```

After the download loop, add the write-failure transient alongside the existing 404 cache logic. Replace the `if ( $all_candidates_404 )` block with:

```php
if ( $all_candidates_404 ) {
    set_transient( $this->get_missing_locale_transient_key( $requested_locale ), $this->version, self::MISSING_LOCALE_CACHE_TTL );
} elseif ( $this->last_write_failed ) {
    set_transient( $this->get_write_failed_transient_key(), $this->version, self::WRITE_FAILED_CACHE_TTL );
}
```

### Step 4: Run test to verify it passes

Run: `pnpm run test -- --filter=test_write_failure_at_both_locations_is_cached`
Expected: PASS

### Step 5: Write failing test — cached write failure skips download

```php
/**
 * @covers ::load_translations
 */
public function test_cached_write_failure_skips_download(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    // Pre-set the write-failure transient.
    set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.7' );

    $this->http_responder = function () {
        $this->fail( 'No HTTP request should be made when write failure is cached' );
        return false;
    };

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

    $this->assertEmpty( $this->http_requests, 'No downloads should occur when write failure is cached' );
}
```

### Step 6: Run test — should pass from step 3 implementation

Run: `pnpm run test -- --filter=test_cached_write_failure_skips_download`
Expected: PASS

### Step 7: Write failing test — cached write failure still loads stale file

```php
/**
 * @covers ::load_translations
 */
public function test_cached_write_failure_still_loads_stale_file(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    // Create a stale file (no matching version transient).
    $stale_file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
    file_put_contents( $stale_file, "<?php\nreturn array('messages' => array('Hello' => 'Hallo'));" );

    // Pre-set the write-failure transient.
    set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.7' );

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

    $this->assertEmpty( $this->http_requests, 'No downloads should occur' );
    // Verify the text domain was loaded (stale file used).
    $this->assertTrue( is_textdomain_loaded( 'woocommerce-pos' ), 'Stale translation file should be loaded when write failure is cached' );
}
```

### Step 8: Run test — should pass from step 3 implementation

Run: `pnpm run test -- --filter=test_cached_write_failure_still_loads_stale_file`
Expected: PASS

### Step 9: Write failing test — write failure cache expires and retries

```php
/**
 * @covers ::load_translations
 */
public function test_write_failure_cache_expires_allows_retry(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    // No write-failure transient set (simulates expiry).
    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

    $this->assertNotEmpty( $this->http_requests, 'Download should be attempted when write failure cache has expired' );
}
```

### Step 10: Run test — should pass (this is essentially existing behavior)

Run: `pnpm run test -- --filter=test_write_failure_cache_expires_allows_retry`
Expected: PASS

### Step 11: Write failing test — successful download clears write-failure cache

```php
/**
 * @covers ::load_translations
 */
public function test_successful_download_clears_write_failure_cache(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    // Pre-set the write-failure transient from a previous version.
    set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.6' );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    // Version 1.8.7 won't match the 1.8.6 transient, so download proceeds.
    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

    $this->assertFalse(
        get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
        'Write failure transient should be cleared after successful download'
    );
}
```

### Step 12: Run test — should pass from step 3 implementation

Run: `pnpm run test -- --filter=test_successful_download_clears_write_failure_cache`
Expected: PASS

### Step 13: Write failing test — write failure does not pollute missing-locale cache

```php
/**
 * @covers ::load_translations
 */
public function test_write_failure_does_not_set_missing_locale_cache(): void {
    add_filter( 'locale', function () {
        return 'de_DE';
    } );

    $this->http_responder = function () {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => "<?php\nreturn array('messages' => array());",
        );
    };

    // Block both paths.
    $blocker = $this->temp_lang_dir . 'blocked';
    file_put_contents( $blocker, 'x' );
    $unwritable_dir = $blocker . '/lang/';

    add_filter( 'upload_dir', function ( $dirs ) use ( $blocker ) {
        $dirs['basedir'] = $blocker . '/uploads';
        return $dirs;
    } );

    $i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

    $this->assertFalse(
        get_transient( 'wcpos_i18n_woocommerce-pos_missing_de_DE' ),
        'Missing-locale transient should NOT be set when failure is a write error, not a 404'
    );
    $this->assertEquals(
        '1.8.7',
        get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
        'Write failure transient should be set instead'
    );

    unlink( $blocker );
}
```

### Step 14: Run test

Run: `pnpm run test -- --filter=test_write_failure_does_not_set_missing_locale_cache`
Expected: PASS

### Step 15: Run full test suite

Run: `pnpm run test -- --filter=Test_i18n`
Expected: All tests PASS

### Step 16: Commit

```bash
git add includes/i18n.php tests/includes/Test_i18n.php
git commit -m "feat(i18n): cache write failures to stop log spam and CDN hammering (#565)

When both primary (WP_LANG_DIR/plugins/) and fallback (uploads/) paths
fail, set a 1-hour transient to skip download attempts. Still loads any
stale translation file as a fallback. Clears on successful download or
version change."
```

---

## Task 3: Change default path to `WP_LANG_DIR/plugins/`

Change the constructor default from `PLUGIN_PATH/languages/` to `WP_LANG_DIR/plugins/`, with transient-based path memory so that if the uploads fallback was used previously, it goes straight there on next request.

**Files:**
- Modify: `includes/i18n.php` (constructor, new `resolve_languages_path()`)
- Test: `tests/includes/Test_i18n.php`

### Step 1: Write the failing test — default path is WP_LANG_DIR/plugins/

```php
/**
 * @covers ::__construct
 */
public function test_default_languages_path_is_wp_lang_dir_plugins(): void {
    // Use en_US to skip translation loading (avoids HTTP requests).
    add_filter( 'locale', function () {
        return 'en_US';
    } );

    $i18n = new i18n( 'woocommerce-pos', '1.8.7' );

    $reflection = new \ReflectionProperty( i18n::class, 'languages_path' );
    $reflection->setAccessible( true );

    $this->assertEquals(
        WP_LANG_DIR . '/plugins/',
        $reflection->getValue( $i18n ),
        'Default languages path should be WP_LANG_DIR/plugins/'
    );
}
```

### Step 2: Run test to verify it fails

Run: `pnpm run test -- --filter=test_default_languages_path_is_wp_lang_dir_plugins`
Expected: FAIL — constructor still defaults to `PLUGIN_PATH . 'languages/'`

### Step 3: Write failing test — transient-remembered path used on construction

```php
/**
 * @covers ::__construct
 */
public function test_remembered_fallback_path_used_on_construction(): void {
    add_filter( 'locale', function () {
        return 'en_US';
    } );

    // Simulate a previous session that fell back to uploads.
    set_transient( 'wcpos_i18n_woocommerce-pos_active_path', 'uploads' );

    $i18n = new i18n( 'woocommerce-pos', '1.8.7' );

    $reflection = new \ReflectionProperty( i18n::class, 'languages_path' );
    $reflection->setAccessible( true );

    $upload_dir    = wp_upload_dir();
    $expected_path = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';

    $this->assertEquals(
        $expected_path,
        $reflection->getValue( $i18n ),
        'Constructor should use uploads fallback when active_path transient is set'
    );
}
```

### Step 4: Implement `resolve_languages_path()` and update constructor

In `includes/i18n.php`, add the `resolve_languages_path()` method after `get_write_failed_transient_key()`:

```php
/**
 * Determine the languages path to use.
 *
 * Checks if a previous session fell back to the uploads directory and
 * returns that path if so. Otherwise returns the standard WordPress
 * languages/plugins/ directory.
 *
 * @return string
 */
protected function resolve_languages_path(): string {
    $active = get_transient( $this->transient_key . '_active_path' );
    if ( 'uploads' === $active ) {
        return $this->get_fallback_languages_path();
    }

    return WP_LANG_DIR . '/plugins/';
}
```

Update the constructor to set `transient_key` before `languages_path` (since `resolve_languages_path()` needs it), and change the default. Replace the existing constructor (lines 74-81):

```php
public function __construct( ?string $text_domain = null, ?string $version = null, ?string $languages_path = null ) {
    $this->text_domain    = $text_domain ?? 'woocommerce-pos';
    $this->version        = $version ?? TRANSLATION_VERSION;
    $this->transient_key  = 'wcpos_i18n_' . $this->text_domain;
    $this->languages_path = $languages_path ?? $this->resolve_languages_path();

    $this->load_translations();
}
```

Also remove the `use const WCPOS\WooCommercePOS\PLUGIN_PATH;` import at the top of the file (line 17) since PLUGIN_PATH is no longer used.

### Step 5: Run both tests to verify they pass

Run: `pnpm run test -- --filter="test_default_languages_path_is_wp_lang_dir_plugins|test_remembered_fallback_path_used_on_construction"`
Expected: PASS

### Step 6: Run full test suite

Run: `pnpm run test -- --filter=Test_i18n`
Expected: All tests PASS

### Step 7: Commit

```bash
git add includes/i18n.php tests/includes/Test_i18n.php
git commit -m "feat(i18n): change default path to WP_LANG_DIR/plugins/ (#565)

Default write location is now WP_LANG_DIR/plugins/ instead of
PLUGIN_PATH/languages/. This directory is where WordPress stores its
own plugin translations and is writable on virtually all hosts.

Uses a transient to remember if a previous session fell back to the
uploads directory, going straight there on subsequent requests."
```

---

## Task 4: Update pro plugin

The pro plugin currently passes `PLUGIN_PATH . 'languages/'` to the parent constructor. Since the default is now `WP_LANG_DIR/plugins/`, remove the explicit path argument.

**Files:**
- Modify: `woocommerce-pos-pro/includes/i18n.php:27-32`

### Step 1: Update the pro constructor

Replace the existing constructor in `woocommerce-pos-pro/includes/i18n.php` (lines 27-33):

```php
public function __construct() {
    parent::__construct(
        'woocommerce-pos-pro',
        TRANSLATION_VERSION
    );
}
```

Also remove the `PLUGIN_PATH` constant import if it was only used here. Check the rest of the file — if nothing else uses `PLUGIN_PATH`, remove:
```php
use const WCPOS\WooCommercePOSPro\PLUGIN_PATH;
```

### Step 2: Run the full free plugin test suite to verify no regressions

Run: `pnpm run test -- --filter=Test_i18n`
Expected: All tests PASS

### Step 3: Commit

```bash
git add woocommerce-pos-pro/includes/i18n.php
git commit -m "feat(i18n): simplify pro constructor to use new default path (#565)"
```

---

## Task 5: Lint and final verification

### Step 1: Run linter on changed files

Run: `composer run lint -- includes/i18n.php tests/includes/Test_i18n.php`

Fix any PHPCS errors. Common issues:
- Param alignment spacing in docblocks
- Missing short descriptions on new methods
- Line length

### Step 2: Run full test suite

Run: `pnpm run test`
Expected: All tests PASS

### Step 3: Fix any lint or test issues, then commit

```bash
git add -u
git commit -m "style: fix lint issues in i18n changes"
```
