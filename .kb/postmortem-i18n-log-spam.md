# Postmortem: i18n Translation Download Spam (March 2026)

## What Happened

Client sites running v1.8.9–v1.8.13 with locales not available on the CDN (e.g., `es_CL`) experienced hundreds of 5MB log files filling disk, crashing their WordPress installations with memory exhaustion errors.

## Impact

- POS settings page: fatal error (256MB memory limit exhausted reading log files)
- POS frontend: slow/broken (every REST API request made blocking HTTP calls to jsdelivr)
- Server: disk space exhaustion from hundreds of 5MB log files
- At least one client site was "very slow, almost broken"

## Root Cause

The original `i18n.php` (pre-v1.8.14) had **no negative caching**. The `set_transient()` call only ran on successful downloads. When a download failed (404, timeout, write error), nothing was cached — the code simply tried again on the next request.

```php
// v1.8.9 code — the problem
if ( $needs_download ) {
    $downloaded = $this->download_translation( $locale, $file );
    if ( $downloaded ) {
        set_transient( ... ); // ← ONLY on success. Failures: try again forever.
    }
}
```

With the POS app firing 10-15 concurrent REST API requests per page load, and both `woocommerce-pos` and `woocommerce-pos-pro` each running their own i18n instance:
- ~4 log lines per request (download fail + "no translation available" x 2 text domains)
- ~13 requests/second from concurrent POS API calls
- = ~770 log entries/minute, sustained indefinitely
- WooCommerce rotates log files at 5MB, creating hundreds of files

## Why We Missed It

1. **All i18n tests mocked HTTP responses.** No test used a real locale that 404s on the CDN.
2. **No concurrent load testing.** Single-request tests can't reveal thundering herd issues.
3. **No Logger guardrails.** The Logger was a straight pass-through to WC_Logger — no rate limiting, no size checks, no duplicate suppression.
4. **No monitoring for log file growth.** We had no way to know log files were growing unboundedly on client sites until they crashed.

## Fix Timeline

### v1.8.14 (partial fix)
- `0224bc49` — Added locale fallback (`es_CL` -> `es`) and missing-locale transient cache
- `b0f784ab` — Only cache missing locales for definitive 404s (not timeouts/errors)
- `41e240f5` — Write location fallback and write-failure caching
- `a3e8a433` — Self-healing for corrupt translation files

### v1.9.0 (complete fix, PR #702)
- **Logger duplicate suppression** — consecutive identical messages suppressed with "repeated N times" summary
- **i18n download lock** — 30-second transient prevents concurrent requests from all downloading simultaneously (thundering herd fix)
- **Log file cleanup** — update script deletes all `woocommerce-pos-*.log` files to reclaim disk space
- **Memory-safe log reading** — streaming `fopen()`/`fgets()` instead of `file_get_contents()`, 10K entry cap, efficient SQL counting

## Lessons

### 1. Always cache negative results
Any code that makes external HTTP calls must cache failures, not just successes. A 404 today will be a 404 tomorrow — retrying on every request is a denial-of-service against your own infrastructure.

### 2. Test the failure path under concurrent load
Single-request mocks can't reveal race conditions. When the POS app fires 10+ concurrent API requests, any shared state (transients, files, database) becomes a concurrency problem.

### 3. Defense in depth for logging
The Logger itself should be a safety net. No single log source should be able to generate unbounded output. The duplicate suppression added in v1.9.0 ensures this.

### 4. Consider the cascade
When one system fails (i18n downloads), it can cascade: log files grow -> disk fills -> transient writes fail -> more retries -> more logs -> site crashes. Design for graceful degradation at each layer.

### 5. External HTTP calls in constructors are dangerous
`new i18n()` runs on every request via `Init::init_common()`. A blocking HTTP call with a 10-second timeout in a constructor means every page load is potentially 10 seconds slower when the CDN is down.
