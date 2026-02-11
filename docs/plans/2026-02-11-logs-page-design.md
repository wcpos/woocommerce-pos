# Logs Page in POS Settings

**Issue**: https://github.com/wcpos/woocommerce-pos/issues/504
**Date**: 2026-02-11

## Nav Sidebar Changes

The sidebar gets restructured into three groups:

- **Settings**: General, Checkout, Access, Sessions, Extensions
- **Tools**: Logs (hardcoded) + any pro-registered tools pages
- **Account**: License (hardcoded) + any pro-registered account pages

The Logs nav item shows severity-specific badges — a red pill for error count and an amber pill for warning count (since last viewed). Either pill hides when its count is zero. Both hide when everything's been read.

The "last viewed" timestamp is stored in user meta (`_wcpos_logs_last_viewed`). It gets updated when the user visits the Logs page, following the same pattern as the extensions seen-tracking.

The initial unread counts are server-rendered into `window.wcpos.settings` so the badge shows immediately without an extra API call.

## Backend (PHP)

**New REST controller**: `includes/API/Logs.php` extending `WP_REST_Controller`, following the same pattern as `Extensions.php`.

Endpoints under `wcpos/v1/logs`:

- **`GET /logs`** — returns paginated log entries in reverse chronological order. Detects whether the store uses file-based or database logging, then:
  - *File handler*: scans `wc-logs/` for `woocommerce-pos-*.log` files, parses lines into structured objects (`timestamp`, `level`, `message`, `context`)
  - *DB handler*: queries the `woocommerce_log` table filtered by `source = 'woocommerce-pos'`
  - Supports query params: `level` (filter by error/warning/info/etc), `per_page`, `page`
  - Also checks for `fatal-errors` log files and returns a boolean `has_fatal_errors` plus a URL to WooCommerce's log viewer filtered to fatal errors

- **`POST /logs/mark-read`** — stores `current_time('mysql')` in user meta `_wcpos_logs_last_viewed`. Returns the updated timestamp.

The unread counts (broken down by `error` and `warning`) get calculated at page load time in the PHP class that enqueues the settings script, and injected into `window.wcpos.settings.unreadLogCounts` — same approach as `newExtensionsCount`.

**Log parsing** for file-based handler: WC log lines follow `timestamp LEVEL message`. Parse with a regex, split context from message at `| Context:`, and return structured objects.

## Frontend (React)

**New screen**: `packages/settings/src/screens/logs/`

- `index.tsx` — main component. Fetches `GET /logs` via TanStack Query. On mount, calls `markLogsRead()` to reset the badge. Renders the filter controls and entry list.
- `use-unread-log-counts.ts` — same `useSyncExternalStore` pattern as extensions. Exposes `useUnreadLogCounts()` returning `{ error: number, warning: number }`, plus `markLogsRead()` which zeroes counts optimistically then POSTs to `/logs/mark-read`.

**Route**: new `logsRoute` in `router.tsx` at `/logs`, no settings loader (uses its own endpoint).

**UI components**:
- Filter bar at the top — toggle buttons for All / Errors / Warnings. Active filter highlighted.
- Entry list — each row shows: severity badge (red/amber/gray pill), timestamp, and truncated message (~100 chars). Click to expand the full message + context.
- Fatal errors banner — if `has_fatal_errors` is true, show an info bar at the top with a link out to WooCommerce's Status > Logs page. Something like "Fatal errors detected — view in WooCommerce logs."
- Empty state when there are no log entries.
- Pagination at the bottom if entries exceed a page.

**NavItem changes**: extend the badge prop to accept either a number (existing behavior) or an object like `{ error: 3, warning: 1 }` for multi-severity pills. The Logs nav item passes the unread counts object; Extensions keeps passing a plain number.

## Out of Scope

- Log file clear/download — not needed for v1
- Inline fatal error display — link out to WooCommerce's log viewer instead since we can't reliably filter which fatal errors are POS-related
