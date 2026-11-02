# Export every closure as CSV from POS Settings (Free)

Ticket: wcpos/roadmap#330. Lane: `next`. Repo: `woocommerce-pos` only.
Branch: `feat/export-closures-csv` (already created off `origin/next`).

**Stakes tier: Medium.** This is a read-only admin export. It is Medium and not Low
because it serves fiscal figures that the existing closure routes deliberately redact
from cashiers, so the capability gate is load-bearing. It is not High: nothing here
writes, and nothing is irreversible. Do not add retries, locking, transactions,
circuit breakers, caching layers, or background jobs. Do not add an environment
variable for anything — if a value needs a name, write a named PHP constant with a
comment saying why that value.

**Budget: roughly 320–420 net non-test lines, plus tests.** That is PHP controller +
CSV writer + the settings screen + nav wiring. If your plan exceeds ~3x that, stop and
say why instead of writing it.

---

## What a merchant gets

WP Admin › POS › Settings grows one entry in the **Tools** group called
**Export closures**. It is a small landing page: a heading, one short paragraph that
says what the file contains, and one button that downloads a CSV of every closure the
store has ever written. One row per closure. No filters, no date range, no register
picker — the filtered browsing list is Pro's Closures room and this is explicitly not
that.

---

## Server

### The route

Add `GET /closures/export` to the existing
`includes/API/V2/Closures_Controller.php`.

Register it inside that class's existing `register_routes()` loop, which today maps
routes to methods and points every one at `dispatch()` and `permissions_check()`.
Add `'/closures/export' => 'GET'` to that array and give `dispatch()` a branch for it.

Two ordering hazards, both real:

1. The UUID route is `'/closures/(?P<closure_id>[0-9a-fA-F-]{36})'`. `export` is six
   characters so it cannot match the 36-character pattern. No reordering needed, but
   confirm by test that `/closures/export` reaches the export branch and not the
   single-document branch.
2. `'/closures/last'` is already a sibling literal route, so a second literal sibling
   is an established shape here. Follow it exactly.

### Protocol exemption — verify this, do not assume

`Closures_Controller::wcpos_route_classifications()` currently returns
`array( 'protocol_exempt' => array( '/wcpos/v2/closures' ) )`.

Read `includes/API/Route_Classifier.php` and `includes/API.php` and determine whether
`protocol_exempt` matches **exactly** or by **prefix**. The repo has an exact-match
convention for route classification elsewhere, so assume nothing.

- If it is exact-match, `/wcpos/v2/closures/export` is **not** exempt and a
  cookie-authenticated wp-admin request without an `X-WCPOS-Protocol` header will be
  rejected. In that case add `'/wcpos/v2/closures/export'` to the `protocol_exempt`
  array.
- If it is prefix-match, change nothing.

Either way, add a test that dispatches the export route the way the admin screen
actually calls it (see "Client" below) and asserts it succeeds.

### Permissions

`permissions_check()` today requires `access_woocommerce_pos` for everything and adds
`view_woocommerce_pos_reports` for GETs.

The export branch requires **all three**:

- `access_woocommerce_pos`
- `manage_woocommerce_pos` — the ticket's stated gate, and the capability that owns the
  Settings screen this button lives on.
- `view_woocommerce_pos_reports` — non-negotiable. The CSV contains `expected`,
  `till_expected` and `variance`, which `Closures_Controller::visible()` strips from a
  caller lacking this capability. Gating the export on `manage_woocommerce_pos` alone
  would make it a hole straight through that redaction. `administrator` and
  `shop_manager` both hold all three, so no real role loses the button.

A caller missing any of the three gets the normal `rest_forbidden` 403 shape.

### Reading the rows

**Do not write a new query.** Reuse `Closure_Store::list()`, called in a loop, paging
to completion:

- `per_page` = 100 (the store's own hard cap — read it from the existing constant or
  clamp rather than hardcoding a second copy).
- Start at `page` 1 and increment until a page returns fewer rows than `per_page`.
- Add a hard stop at a named constant (e.g. `MAX_EXPORT_PAGES`) with a comment, so a
  pathological store cannot spin forever. Choose a value that covers a realistic
  lifetime of closures and say in the comment why.

Order: **by register, then by closure number ascending.** The store's default order is
`closed_at_gmt DESC, number DESC, id DESC`, which is wrong for this file. `list()`
already has an internal `number_order` switch, so extend that same mechanism with an
ascending register+number ordering rather than bolting on a separate query. Adding an
ordering option to the existing method is still "the list route paged to completion";
a second hand-written `SELECT` is not.

`Closure_Store::with_correction_counts()` already runs on the list path and puts
`corrections_count` on every row with one grouped query per page. Use it. Do **not**
call `corrections_for()` per closure — that is N queries plus N user lookups.

### Presentation values are frozen — read them off the row

This is the single most important correctness rule in this spec.

`Closure_Store::create()` stamps the closure's own presentation into `breakdowns` at
write time: `breakdowns.currency`, `breakdowns.timezone`, `breakdowns.money_format.*`,
and `breakdowns.labels.*` (including `register_name`, `closed_by_name`). An old closure
is meant to render in the timezone and currency it was closed in.

So the CSV **must** read those frozen values from each row. Never resolve the store's
current timezone, currency or money format while writing the file. A merchant who
changed timezone or currency must get an export that agrees with the reprint of that
same closure, not one that retroactively re-states history.

Money stays as the four-place decimal strings the store already produces. Do not cast
to float, do not round, do not re-format with thousands separators. A CSV consumed by a
spreadsheet wants the raw decimal.

### Legacy rows

`Activator::upgrade_business_days()` backfills `business_day` on legacy rows in batches
of 100, so a store mid-upgrade has both stamped and unstamped closures. `list()` already
handles that duality (business day where stamped, `closed_at_gmt` fallback otherwise).
A whole-set export is exactly the population where this shows up, so do not add your own
business-day handling — let `list()` do it, and emit an empty `business_day` cell where
the row has none rather than inventing one.

### Columns

One header row, then one row per closure. UTF-8. Emit a UTF-8 BOM so Excel on Windows
does not mangle accented store names — this is the one formatting concession, and it
belongs in the writer with a comment.

Columns, in this order:

| Column | Source |
|---|---|
| `closure_number` | `number` |
| `register_id` | `register_id` |
| `register_name` | `breakdowns.labels.register_name` (frozen) |
| `store_id` | `store_id` |
| `store_name` | `breakdowns.store.name` (frozen) |
| `business_day` | `business_day` (may be empty on legacy rows) |
| `opened_at_gmt` | `opened_at_gmt` |
| `closed_at_gmt` | `closed_at_gmt` |
| `closed_by` | `closed_by` (user id) |
| `closed_by_name` | `breakdowns.labels.closed_by_name` (frozen) |
| `currency` | `breakdowns.currency` (frozen) |
| `timezone` | `breakdowns.timezone` (frozen) |
| `float_expected` | `breakdowns.opening_float.expected` |
| `float_counted` | `breakdowns.opening_float.counted` |
| `float_variance` | `breakdowns.opening_float.variance` |
| `counted_<tender>` | flattened from `counted` |
| `expected_<tender>` | flattened from `expected` |
| `variance_<tender>` | flattened from `variance` |
| `tender_<method>_sales` / `tender_<method>_refunds` | flattened from `breakdowns.payment_methods` |
| `tax_<name>_net` / `tax_<name>_tax` / `tax_<name>_gross` | flattened from `breakdowns.tax_rates` |
| `period_sales_total`, `period_refunds_total` | the grand-total pair |
| `perpetual_sales_total`, `perpetual_refunds_total` | the perpetual counters |
| `first_sale_counter`, `last_sale_counter` | perpetual counters |
| `unsynced_count`, `unsynced_total` | as recorded |
| `corrections_count` | from `with_correction_counts()` |

**Flattening is the hard part and needs one deliberate decision.** Tender keys, payment
method keys and tax-rate names vary per closure, but a CSV needs one stable header row
for the whole file. Therefore:

- Make **two passes**. First page through every closure collecting the union of all
  tender keys, payment-method keys and tax-rate names. Then page again writing rows
  against that fixed header. Buffering every row in memory instead is also acceptable
  **only if** you cap it — say so either way and explain the choice in the PR.
- Sort each flattened key group deterministically (alphabetically) so the header is
  stable across runs. A column order that depends on which closure happened to be first
  is a bug.
- Sanitise each key into a safe column suffix (lowercase, non-alphanumerics to `_`), and
  if two distinct keys collide after sanitising, keep them distinct — do not silently
  merge two tax rates into one column.
- A closure with no value for a column gets an empty cell, not `0`. Zero and absent are
  different facts in a fiscal export.

Corrections: `corrections_count` only. The ticket is explicit that the recorded figures
are exported as recorded and the settled figures are the Closures room's job. **Do not**
apply corrections to any exported figure.

### Emitting the file

Follow the existing file-download precedent exactly: `includes/API/V1/Raw_Response.php`,
as used by `includes/API/V1/Receipts_Controller.php`. It exists to write a raw body
without WordPress re-encoding it, and it already handles the `rest_pre_serve_request`
double-serve and CORS-ordering traps — do not reimplement those.

```
Content-Type:        text/csv; charset=utf-8
Content-Disposition: attachment; filename="wcpos-closures-<site>-<YYYY-MM-DD>.csv"
Content-Length:      <bytes>
Cache-Control:       no-store
```

`Content-Disposition` is already CORS-allowlisted in `includes/Rest_Cors.php` and pinned
by `tests/includes/API/Test_Cors_Contract.php`; do not change that list.

Build the CSV with `fputcsv()` into a memory stream so quoting, embedded commas,
newlines and quotes are handled by PHP rather than by hand-rolled string concatenation.

**One security note that is easy to miss:** a cell whose text begins `=`, `+`, `-` or
`@` is executed as a formula when the file is opened in Excel or Sheets. Store names and
correction reasons are merchant-controlled text. Prefix any such cell with a single
quote, and cover it with a test. This is a real, known CSV-injection class and it is
cheap to prevent here.

---

## Client

Package `packages/settings` (Vite, IIFE bundle, built to `assets/`; the built bundle is
gitignored, so the PR carries source only).

Three files, following the existing pattern exactly:

1. `packages/settings/src/router.tsx` — add a route at `/export-closures` with **no
   loader** (it is not a settings-section screen). Model it on `sessionsRoute` /
   `registersRoute`, which carry the comment explaining why they have no loader.
2. `packages/settings/src/layouts/nav-sidebar.tsx` — add a `<NavItem>` inside the
   existing **Tools** `<NavGroup>`.
3. `packages/settings/src/layouts/root-layout.tsx` — add the entry to `pageTitles` and
   `pageSkeletons`.

Plus the screen itself, `packages/settings/src/screens/export-closures/index.tsx`.
Model it on `screens/registers/index.tsx` (plain component, `@wordpress/api-fetch`,
`Button` from the local `../../components/ui`, notices via `useNotices()`).

**Copy.** The paragraph must say what the file holds, per the acceptance criteria. Use
`t(key, fallback)` from `packages/settings/src/translations` for every visible string —
and note the house i18n interpolation uses **single** braces, not double. Suggested,
adjust for tone consistency with neighbouring screens:

- Title: `Export closures`
- Body: `Download every closure this store has recorded as a CSV file — one row per
  closure, with its number, register, business day, float, counted and expected totals,
  variance, tender and tax breakdowns, and running totals. Figures are exported exactly
  as they were recorded at the time of closing.`
- Button: `Download CSV`

**How the download is triggered.** There is no Blob/`createObjectURL` precedent anywhere
in this repo and this is not the place to introduce one. Navigate the browser to the
REST URL and let `Content-Disposition` do the work:

- Build the URL from `wpApiSettings.root` with `addQueryArgs` from `@wordpress/url`
  (already an external in this bundle).
- Include `wcpos=1` — the settings SPA's established marker, used by `settingsLoader`.
- Include `_wpnonce: wpApiSettings.nonce` so cookie authentication is accepted.
- Then assign `window.location`.

Disable the button and show a pending label while the navigation is in flight, and
surface a notice if the URL cannot be built (missing `wpApiSettings`).

**The admin bundle's cachebust is the plugin `VERSION`** (`includes/Admin/Settings.php`
enqueues with `VERSION`). Do **not** bump `VERSION` in this PR — version bumps are not
ours to make. Just note in the PR body that the screen ships to browsers on the next
version bump.

---

## Tests

PHPUnit, through wp-env only. Never local composer or `vendor/bin/phpunit` directly.

**Add the new cases to the existing
`tests/includes/API/V2/Test_Closures_Controller.php`.** This is deliberate and required:

- Its private `get()` / `post()` helpers already build routes from the literal
  `'/wcpos/v2/'`, which satisfies the REST lane-coverage gate at class scope.
- It already `use`s the `Closure_Test_Fixture` trait, whose `tearDown()` is **required**
  because closures commit real transactions.
- Its class-scope imports contain no `\API\V1\` reference. **Do not import
  `Raw_Response` into the test file** — an `\API\V1\` import at class scope is a v1 lane
  signal that would taint every case in the class. Assert on the response object's
  headers and body instead.

The gate (`scripts/lane-coverage.php`, CI workflow `lane-coverage.yml`) fails the build
for any new case that has no current-lane signal. Do not add
`install_sync_read_lane()` — closures are served from an owned table, not through the
sync augmentation pipeline, and cargo-culting it does nothing for classification.

Required cases, named `test_[feature]_[scenario]_[expected_result]`, Arrange/Act/Assert,
`assertSame` over `assertEquals`, arguments `( expected, actual )`:

1. Empty store exports a header row and nothing else.
2. Three closures across two registers export three data rows, ordered by register then
   ascending closure number. Assert the actual order, not just the count.
3. A closure with corrections exports one row whose `corrections_count` matches, and
   whose recorded figures are unchanged by those corrections.
4. A user without `manage_woocommerce_pos` is refused (403).
5. A user with `manage_woocommerce_pos` but without `view_woocommerce_pos_reports` is
   refused. This is the redaction-hole guard and it must exist.
6. The response carries `text/csv` and an `attachment` `Content-Disposition`.
7. A store name containing a comma, a quote and a leading `=` round-trips safely —
   properly quoted, and the formula prefixed.
8. Frozen presentation: a closure written under one timezone/currency still exports
   those values after the store's current settings are changed.

Run them one file at a time, capped:

```
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist \
  tests/includes/API/V2/Test_Closures_Controller.php
```

Note `phpunit` does not accept `--maxWorkers`; it is single-process already. Do not try
to run several test files in one wp-env invocation — only the first actually runs.

Also run, and report the exact output of, the lane gate:

```
php scripts/lane-coverage.php --write
php scripts/lane-coverage.php --warnings
```

JS side: `pnpm --filter=@wcpos/settings lint` and `pnpm --filter=@wcpos/settings test`.

---

## Definition of done

- Every acceptance criterion in wcpos/roadmap#330 is met.
- The PHPUnit cases above pass, and you have pasted the real runner output.
- The lane-coverage gate adds no new unresolved or v1-only case.
- `pnpm --filter=@wcpos/settings lint` is clean.
- No `VERSION` bump, no new environment variable, no new dependency.
- If you could not do something, say so plainly and name the command and the error.
  Do not describe an unrun check as passing.
