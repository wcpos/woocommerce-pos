# i18n: Fix write failures with fallback write locations

**Issue:** [#565](https://github.com/wcpos/woocommerce-pos/issues/565)

## Problem

Translation files download fine from the CDN but fail to write to `PLUGIN_PATH/languages/` on managed hosts where the plugins directory is read-only. Because the write failure isn't cached, the system retries the full download cycle on every request — log spam, wasted CDN hits, slight perf cost.

## Root Cause

`download_translation()` returns `false` for write failures with `last_download_status_code` still `null` (HTTP succeeded). Back in `load_translations()`, the missing-locale transient only gets set when all candidates return 404. A `null` status code doesn't count as 404, so nothing is cached and the cycle repeats.

## Solution

Three changes: fix the default write location, add a fallback chain, and cache failures as a last resort.

### 1. Write location fallback chain

Instead of writing to `PLUGIN_PATH/languages/` (often read-only on managed hosts), use a fallback chain that tries progressively more permissive directories:

1. **`WP_LANG_DIR/plugins/`** (primary) — where WordPress itself stores plugin translations. Writable on ~99% of hosts.
2. **`wp_upload_dir()['basedir']/wcpos-languages/`** (fallback) — the uploads directory is guaranteed writable on any functioning WordPress install. If it's not, media uploads are broken and the site has bigger problems.
3. **Cache the failure** (last resort) — if both locations fail, set a transient and stop retrying.

The fallback happens inside `download_translation()`: if `put_contents()` fails at the primary path, immediately retry at the uploads path. If the uploads write succeeds, update `$this->languages_path` so `load_translation_file()` reads from the right place.

#### Why not `PLUGIN_PATH/languages/` anymore?

We control the translation source (the CDN repo). There's no need for users to drop custom files into the plugin directory. Anyone who wants to override a string can use WordPress's standard `gettext` filters — which is the proper way to do it.

### 2. Constructor changes

- Change the default `$languages_path` from `PLUGIN_PATH . 'languages/'` to `WP_LANG_DIR . '/plugins/'`
- Keep `$languages_path` as a constructor parameter for testability (tests pass a temp dir)
- Pro plugin drops its `PLUGIN_PATH . 'languages/'` argument — inherits the new default

### 3. Cache write failures as a safety net

When both write locations fail, cache the failure so we don't retry every request.

- New property: `$this->last_write_failed` (mirrors the existing `$last_download_status_code` pattern)
- Set to `true` in `download_translation()` when writes fail at both locations
- After the download loop in `load_translations()`, if a write failure occurred, set transient `{transient_key}_write_failed` with 1-hour TTL
- Before the download loop, check this transient — skip downloads if set
- When skipping due to cached write failure, still load any stale file if available

#### Why 1 hour?

Short enough that if permissions get fixed (deploy, config change), it recovers reasonably fast. Long enough to stop log spam and CDN hammering. The missing-locale cache uses 1 day because that situation changes less often (requires a new CDN release).

## Write flow (updated)

```
download_translation($locale, $file)
  ├─ HTTP GET from CDN
  ├─ Success (200 + body)?
  │   ├─ Try put_contents() at WP_LANG_DIR/plugins/
  │   │   ├─ Success → return true
  │   │   └─ Fail → try uploads fallback
  │   │       ├─ put_contents() at uploads/wcpos-languages/
  │   │       │   ├─ Success → update languages_path, return true
  │   │       │   └─ Fail → set last_write_failed = true, return false
  │   └─ ...
  └─ Fail (404, error, empty) → existing behavior unchanged
```

## Files Changed

| File | Change |
|------|--------|
| `includes/i18n.php` | New default path, uploads fallback in `download_translation()`, `$last_write_failed` property, write-failure transient logic |
| `woocommerce-pos-pro/includes/i18n.php` | Remove `PLUGIN_PATH . 'languages/'` from constructor call |
| `tests/includes/Test_i18n.php` | New tests for write fallback and failure caching, updated setup/teardown |

## New Tests

| Test | What it verifies |
|------|-----------------|
| `test_default_languages_path_is_wp_lang_dir_plugins` | Constructor uses `WP_LANG_DIR/plugins/` when no path argument given |
| `test_write_falls_back_to_uploads_dir` | When primary path write fails, file is written to `uploads/wcpos-languages/` |
| `test_uploads_fallback_updates_languages_path` | After fallback write succeeds, subsequent file reads use the uploads path |
| `test_write_failure_at_both_locations_is_cached` | When both write locations fail, a write-failed transient is set |
| `test_cached_write_failure_skips_download` | When write-failed transient exists, no HTTP requests are made |
| `test_write_failure_cache_still_loads_stale_file` | Stale translation file is loaded even when write failure is cached |
| `test_write_failure_cache_expires_and_retries` | After transient expires, download is attempted again |
| `test_write_failure_does_not_set_missing_locale_cache` | Write failures don't pollute the missing-locale transient (different problem) |
| `test_successful_write_clears_write_failure_cache` | A successful download+write removes any stale write-failed transient |
| `test_primary_write_succeeds_no_fallback_attempted` | When primary path works, uploads dir is never touched |
