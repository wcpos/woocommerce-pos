# SPEC — the `woocommerce_pos_reports` registration seam

Ticket: wcpos/roadmap#333. Lane `next`. Branch `feat/reports-registration-seam`.
Authority: `CONTRACT.md` beside this file (extracted verbatim from the wiki). **Where this spec and
`CONTRACT.md` disagree, the contract wins and you should stop and say so.**

Read `CONTRACT.md` first. It holds the document shape, the producer boundary and the Free/Pro line.

---

## Stakes and budget

**Stakes tier: Medium**, with one High edge. A settings/report read endpoint that feeds the WCPOS
client is Medium per `.ai/rules/stakes-tiers.mdc`. The High edge is the **capability and scope gate**
— it is the only thing standing between a blind cashier and the store's takings, so the three checks
and their tests get High-tier care. Everything else is Medium: no locking, no retries, no defensive
layers around ordinary array handling.

**Budget: about 700-850 net non-test lines and 500-650 test lines.** If your plan exceeds ~1,200 net
non-test lines, stop and report rather than building it — that overrun means the spec is wrong.
Lint/style passes may add 50-220 lines on top; that is expected and not part of the budget.

Do not add environment variables. Values that do not change between deployments are named constants
in code with a comment saying why the value.

---

## The one boundary you must not cross

`CONTRACT.md` § "Producers" is the trap in this ticket. Built-ins (`sales`, `cash_movements`)
**register through `woocommerce_pos_reports` but are computed on the device.** Registration is a
*declaration* so the registry, the field tree and the fixtures have one source of truth. **The server
never computes a built-in.**

So: a built-in registration declares identity, scopes, group-by options, columns and tile, and
supplies **no query callable**. Do not write server-side SQL that recomputes Sales or Cash movements.
If you find yourself writing an orders aggregation query, you have misread the contract — stop.

---

## What exists already (do not rebuild these)

| Need | Use this |
|---|---|
| The `report` JSON schema | `Receipt_Data_Schema::get_json_schema( 'report' )` — `includes/Services/Receipt_Data_Schema.php:2104` |
| Validate a report document | `Report_Document_Validator::validate( array $document )` — `includes/Services/Report_Document_Validator.php:19`. Returns `true` or `WP_Error`. It already does the draft-2020-12 → draft-04 downgrade and the cells-in-column-order check. **Call it; do not reimplement or modify it.** |
| The report field tree | `Receipt_Data_Schema::get_report_field_tree()` — `:1912` |
| Store timezone | `Receipt_Store_Resolver::resolve_store_timezone(): DateTimeZone` — `includes/Services/Receipt_Store_Resolver.php:85` |
| Business-day validation | `Closure_Store::is_business_day( $value ): bool` — `includes/Services/Closure_Store.php:26` (regex + `checkdate`, no normalising) |
| Edition detection | `wcpos_is_pro_active()` — guard with `function_exists` as `includes/Services/Analytics.php:435` does |
| Capability, already defined | `view_woocommerce_pos_reports` — `includes/Activator.php:559,589,611` |
| Register a v2 controller | add to `$natives` in `Controller_Registry::v2_map()` — `includes/API/Controller_Registry.php:137` |
| Route shape to copy | `Records_Controller::register_routes()` — `includes/API/V2/Records_Controller.php:34` (the schema-declaring idiom) |
| Permission-callback shape to copy | `Closures_Controller::permissions_check()` — `includes/API/V2/Closures_Controller.php:62-83` |
| Filter docblock house style | `woocommerce_pos_receipt_data` — `includes/Services/Receipt_Data_Builder.php:656-678` |

Envelope construction (`store`, `register`, `cashier`, `software`, `fiscal`, `i18n`) already exists
for closure documents in `Receipt_Data_Builder::build_closure_document()` and the services it calls
(`Receipt_Store_Resolver`, `Receipt_Payload_Assembler::fiscal()`, `Receipt_I18n_Labels`). **Reuse
those services. Do not duplicate envelope construction.** Extract a shared private helper if that is
what reuse needs.

---

## Deliverable 1 — the registration filter and registry

New: `includes/Services/Reports_Registry.php`.

```php
final class Reports_Registry {
    /** The reach cap, in days, on any range a server-computed report may be asked for.
     * 92 days is the contract's HISTORY_DAYS: a quarter is the longest period the Reports
     * page has a reason to compare, and it matches the client's own cap. */
    public const HISTORY_DAYS = 92;

    public const DEFAULT_CAPABILITY = 'view_woocommerce_pos_reports';
}
```

`Reports_Registry::all(): array` applies the filter over the built-in declarations and normalises
every entry. Cache the normalised result in a static for the request; reports are declared on
`init`-time filters and do not change mid-request.

### The registration shape

Keyed by report key. A key is `[a-z0-9_]+` (lowercase, underscores). Reject and log anything else.

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `title` | string | yes | — | Human, translated by the registrant |
| `scopes` | string[] | yes | — | Non-empty subset of `session`, `range` |
| `group_by` | array | no | `array()` | List of `array( 'key' => string, 'label' => string )` |
| `capability` | string | no | `view_woocommerce_pos_reports` | |
| `extras` | array | no | `array()` | Field-tree fragment, receipt-schema shape |
| `template` | string | no | `null` | A default template slug |
| `tile` | array | no | `null` | `array( 'number' => string, 'line' => string )` — the key of the total to show and a caption |
| `callback` | callable | no | `null` | Absent ⇒ device-computed |

Derived, never accepted from the registrant: **`source`** is `'server'` when `callback` is a callable,
`'device'` otherwise. If a registration supplies `source` explicitly, ignore it.

Drop an invalid registration rather than fataling: missing `title`, empty/unknown `scopes`, a
`callback` that is not callable, a malformed key. Log each drop through
`WCPOS\WooCommercePOS\Logger` naming the key and the reason. A broken third-party registration must
not take the Reports page down.

### The filter

```php
/**
 * Filters the registry of POS reports.
 *
 * A plugin registers a whole report by adding an entry keyed by its report key. ...
 * [full hookdoc in the house style of Receipt_Data_Builder.php:656-678: what it runs for,
 *  what each key means, the resolved-scope contract the callable receives, the document it
 *  must return, and that a report without a callback is computed on the device]
 *
 * @since 1.11.0
 * @hook woocommerce_pos_reports
 */
$reports = apply_filters( 'woocommerce_pos_reports', $reports );
```

### Built-in declarations

`sales` and `cash_movements` only, both `source: device`, both with **no callback**. Their column sets
are fixed by `CONTRACT.md` § "Built-in column sets" — copy them exactly; you may not re-base them.

- `sales` — scopes `['range']`, group-by options: payment method, cashier, register, tax rate, item,
  category. (`CONTRACT.md` gives the column set for ungrouped and for each grouping.)
- `cash_movements` — scopes `['session', 'range']`.

The Session report is the `closure` template type, not a `report`, and the Closures room is a screen
with no template — neither is a registry entry.

---

## Deliverable 2 — `GET /wcpos/v2/reports` (the registry route)

New: `includes/API/V2/Reports_Controller.php`, `$namespace = 'wcpos/v2'`, `$rest_base = 'reports'`.
Register it in `Controller_Registry::v2_map()`'s `$natives` under the key `'reports'`.

Response: `array( 'reports' => array( … ) )`, a **list** preserving registration order, each entry
exposing exactly `key`, `title`, `scopes`, `group_by`, `source`, `tile`, `template`.

**The registry lists only reports whose declared capability the caller holds.** A cashier who cannot
open a report must not be given a tile that 403s. (The per-key route still checks the capability
itself — defence in depth, not a substitute.)

Declare `'schema' => array( $this, 'get_public_item_schema' )` and write `get_item_schema()`, as
`Records_Controller` does.

---

## Deliverable 3 — `GET /wcpos/v2/reports/(?P<key>[a-z0-9_]+)` (the document route)

### Request parameters

| Param | Required | Notes |
|---|---|---|
| `mode` | yes | Must be one of the report's declared `scopes` |
| `register_id` | see gate | **UUID string** (`CHAR(36)`) — see "Identifier types" below |
| `store_id` | no | Integer (`BIGINT`) |
| `session_id` | when `mode=session` | UUID string |
| `from`, `to` | when `mode=range` | `YYYY-MM-DD`, store-local business days, validated with `Closure_Store::is_business_day()` |
| `group_by` | no | Must be one of the report's declared `group_by` keys |

Validate every parameter **before** the checks, and the checks before the callable. Reject a
calendar-impossible date (`2026-02-30`) — `is_business_day()` already does this.

### Ordered pipeline

1. **Resolve the report.** Unknown key → `404 wcpos_report_not_found`.
2. **Device-computed report** (`source === 'device'`) → `404 wcpos_report_is_device_computed`, message
   saying the device builds this report locally. There is no server document to serve.
3. **Validate parameters.** Bad mode for this report → `400 wcpos_report_scope_unsupported`. Bad date,
   unknown `group_by` → `400`.
4. **Capability.** `access_woocommerce_pos` is the floor (re-checked here as `Closures_Controller`
   does, deliberately) plus the report's declared capability. Missing → `403 wcpos_report_forbidden`.
5. **Free scope gate** (Deliverable 4). Refusal → `403 wcpos_report_scope_locked`.
6. **Resolve the scope** (Deliverable 5).
7. **Run the callable** as the requesting user — do not switch user, do not elevate.
8. **Build the envelope, merge, filter, validate** (Deliverable 6).

### The failure shape

Wrap the callable in `try { … } catch ( \Throwable $e ) { … }`. A throw, a non-array return, or a
`WP_Error` from `Report_Document_Validator::validate()` all become:

```php
new WP_Error(
    'wcpos_report_failed',
    sprintf(
        /* translators: 1: report key, 2: the failure message from the report's plugin. */
        __( 'Report "%1$s" failed: %2$s', 'woocommerce-pos' ),
        $key,
        $message
    ),
    array( 'status' => 500, 'key' => $key )
);
```

The `key` must be in the error data so the app can show *Report failed · key*. Log the throw with
`Logger` including the exception class; **do not** put a stack trace in the response.

> **Why 403 and not 404 here.** `Records_Controller::get_item()` answers 404 for an out-of-scope
> record so as not to reveal that another store's record exists. The Free gate is the opposite case:
> the tier boundary is the thing the merchant is meant to see, and the app turns it into a *See Pro*
> button. So the gate answers 403 with a message naming what is locked. Do not "fix" this to 404.

---

## Deliverable 4 — the Free scope gate

New: `includes/Services/Report_Scope_Gate.php`. **This does not exist anywhere on `next` today** —
there is no reach constant, no range limiter and no scope gate in Free or Pro. You are building it.

Rules, from `CONTRACT.md`:

- **Free** (`! wcpos_is_pro_active()`): today on this register only.
  - `mode=range` with `from`/`to` not both equal to the store's current business day →
    `403 wcpos_report_scope_locked`, message naming earlier days.
  - a missing `register_id`, or a request across all registers → `403 wcpos_report_scope_locked`.
- **Pro**: any range, any register, any store the cashier is allowed, subject to reach.
- **Reach, both editions**: `from` may not be more than `Reports_Registry::HISTORY_DAYS` days before
  the store's current business day, and `to` may not precede `from` →
  `403 wcpos_report_scope_locked` / `400` respectively.

The store's "today" is the current date **in the store's timezone**, never `date()` and never the
server's timezone. Derive it from `Receipt_Store_Resolver::resolve_store_timezone()`.

Store scoping beyond this is Pro's job through its own seams and is out of scope here.

---

## Deliverable 5 — scope resolution

New: `includes/Services/Report_Scope_Resolver.php`.

### Identifier types (corrected — an earlier draft of this spec had these wrong)

- **`register_id` and `session_id` are UUID strings**, `CHAR(36)`. See `Register_Store::schema_sql()`
  (`includes/Services/Register_Store.php`, `id CHAR(36) NOT NULL`) and
  `Register_Session_Store::schema_sql()` (`id CHAR(36)`, `register_id CHAR(36)`). The report field
  tree inherits the receipt register id, which is a string. Use UUID strings throughout; validate
  with the same `[0-9a-fA-F-]{36}` shape the closures routes use.
- **`store_id` is an integer** (`BIGINT`), as the spec already said.

### `session_number` comes from the closure, and session mode means a *closed* session

There is no session-number column, and that is not an oversight to be patched here. Numbering lives
on the closure: `Closure_Store::schema_sql()` has `number BIGINT NOT NULL` with
`UNIQUE KEY register_number (register_id, number)`, assigned per register at closure creation
(`Closure_Store::create()`, the `$next = ( $previous['number'] ?? 0 ) + 1` branch). The session row
carries `closure_id CHAR(36) NULL`.

So resolve `session_number` as **the `number` of the closure the session points at**, via
`session['closure_id']`.

A session with no closure is still open and has no number — and the `report` schema settles what to
do about it. Its session branch requires `session_id`, `session_number`, `opened_at` **and
`closed_at`** (`Receipt_Data_Schema.php:2133`), and the last two only exist once the session closes.
**The `report` type's session mode is therefore for a closed session by construction.** The open
session's document is the X-report, which is a `closure`-type document and not this route's business.

Accordingly: `mode=session` against a session with no `closure_id` →
`400 wcpos_report_session_not_closed`, with a message saying the report runs on a closed session.
**Do not invent a number** — the register sequence is uniquely indexed and a fabricated value would
either collide with a real closure or write a number that never matches the closure eventually
written.

The callable receives a **resolved scope array and never raw request parameters.** This is an
acceptance criterion; do not pass `WP_REST_Request` through.

```php
array(
    'mode'          => 'range'|'session',
    'store_id'      => int,
    'register_id'   => string|null,   // UUID
    'register_name' => string,
    'business_day'  => 'YYYY-MM-DD',
    'timezone'      => 'Australia/Sydney',
    'group_by'      => string|null,

    // mode=range only:
    'from'          => 'YYYY-MM-DD',          // store-local business day, inclusive
    'to'            => 'YYYY-MM-DD',          // store-local business day, inclusive
    'from_utc'      => 'YYYY-MM-DD HH:MM:SS', // UTC instant, INCLUSIVE
    'to_utc'        => 'YYYY-MM-DD HH:MM:SS', // UTC instant, EXCLUSIVE

    // mode=session only:
    'session_id'     => string,       // UUID
    'session_number' => int,          // the linked closure's number
    'opened_at'      => 'YYYY-MM-DD HH:MM:SS', // UTC
    'closed_at'      => 'YYYY-MM-DD HH:MM:SS', // never null: session mode is a closed session
)
```

**The UTC window is half-open, `[from_utc, to_utc)`.** Build it by constructing
`DateTimeImmutable( $from . ' 00:00:00', $store_tz )` and
`DateTimeImmutable( $to . ' 00:00:00', $store_tz )->modify( '+1 day' )`, then converting both to UTC.
Do **not** use a `23:59:59` end bound — it drops the final second, and it is wrong across a DST
transition. Do not copy `Closure_Store::list()`'s `strtotime( $day . ' UTC' )` shortcut here: that
treats a business day as a UTC boundary, which is a simplification that line can afford and this one
cannot.

A session scope reads its instants and its business day from the stored session row. **The business
day is copied from the session, never recomputed** (`Closure_Store::create()` at `:528` is the
precedent). For a range scope, `business_day` is the store's current business day.

---

## Deliverable 6 — the envelope, the two filters, validation

### The envelope is the plugin's, the core is the callable's

The callable returns the tabular core plus extras. The plugin owns the rest:

- Plugin-built: `store`, `register`, `cashier`, `software`, `fiscal`, `i18n`, and within `report`
  the `key` and the whole `scope` block.
- Callable-supplied: within `report` — `subtitle`, `group_by`, `columns`, `column_count`, `rows`,
  `groups`, `totals`, `count`, `has_groups`, `has_rows`, `generated_at`, `is_partial`,
  `partial_reason`; and **extras as top-level keys beside `report`, never inside it**.

Merge with **plugin-owned keys winning** — a report may not restate its own scope or claim a
different key. `fiscal.document_type` is `report` and `fiscal.is_report_document` is `true`.

`title` comes from the registration, not the callable.

### `woocommerce_pos_report_data`

Runs on **every server-built report document**, after the merge and **before** validation, so a
filtered document that breaks the schema is caught:

```php
/** [hookdoc in house style]
 * @param array  $data  The report document.
 * @param string $key   The report key.
 * @param array  $scope The resolved scope (see Report_Scope_Resolver).
 * @since 1.11.0
 * @hook woocommerce_pos_report_data
 */
$data = (array) apply_filters( 'woocommerce_pos_report_data', $data, $key, $scope );
```

### `woocommerce_pos_receipt_data` on closure and X-report documents

`Receipt_Data_Builder::build_closure_document()` (`includes/Services/Receipt_Data_Builder.php:25`)
currently does **not** apply this filter — the contract requires that it does.

Add it as the last step before returning, with mode `'xreport'` when `$xreport` is true and
`'closure'` otherwise.

**The order argument is an unsaved `WC_Order` (id 0), not `null`.** This is a deliberate, ruled
departure from the contract's wording and you must not "correct" it:

```php
$data = (array) apply_filters(
    'woocommerce_pos_receipt_data',
    $data,
    new \WC_Order(), // No order stands behind a closure; id 0, as the preview path already passes.
    $xreport ? 'xreport' : 'closure'
);
```

The shipped hookdoc at `Receipt_Data_Builder.php:656-678` tells extensions to guard with
`$order->get_id() is 0`, and `Preview_Receipt_Builder.php:527` already passes an unsaved order for
exactly this reason. Passing literal `null` would fatal every extension that wrote the guard we
published. Update that hookdoc to document the two new modes and to say that `closure` and `xreport`
also arrive with an id-0 order.

Follow the existing precedent at `:681-700`: **re-apply the fiscal identity after the filter** so an
extension cannot overwrite a captured identity. Read those lines and mirror the pattern.

### Validation

After the filters, call `Report_Document_Validator::validate( $document )`. A `WP_Error` becomes the
500 in Deliverable 3.

---

## Deliverable 7 — extras in the field tree

`Receipt_Data_Schema::get_report_field_tree()` (`:1912`) must merge each registered report's `extras`
fragment **under the report's title**, so a registered report's fields appear in the template
editor's field picker. Extras are top-level siblings of `report` and stay optional — do not add them
to the `required` list, and do not route them through `require_report_fields()` (`:2084`), which
exists to make everything *under* `report` required.

Check that `format_money_fields()`'s `report` skip at `:219` still behaves: report cells are
preformatted by the producer and must never be reformatted.

---

## Tests

> **You cannot run the PHP test suite, and you should not try.** It runs only inside Docker/wp-env,
> and your sandbox has no Docker and no network. Attempting `wp-env`, `composer install` or
> `docker` will fail and tell you nothing. **Write the tests carefully and leave them; the reviewer
> runs the suite.** Do not weaken, skip or delete a test because you could not run it, and do not
> substitute a local `vendor/bin/phpunit` — that is forbidden by the repo's `CLAUDE.md`.
>
> What you *can* and should do: `php -l` every file you write, and re-read each test against the
> conventions below. Report clearly that the suite was not run.

For the reviewer's reference, the command is:

```
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist <file> --filter <name>
```

Conventions (`CLAUDE.md`): **failing test first**; Arrange / Act / Assert; name
`test_[feature]_[scenario]_[expected_result]`; arguments `( expected, actual )`; prefer `assertSame`,
`assertTrue`/`assertFalse`, `assertArrayHasKey` over loose equality.

Use `WCPOS_REST_Unit_Test_Case` and its `wp_rest_get_request()` so the WCPOS headers are attached.
Apply settings filters **before** `parent::setUp()` — routes capture schema during `rest_api_init`.

> **Lane-coverage gate.** Dispatch with a **literal** `wcpos/v2/...` route string in the test body. A
> route assembled from variables is classified `unresolved` and **fails CI as a new entry**. This bit
> PR #1996 and needed a follow-up fix commit. This surface reads WCPOS-owned tables only, so it
> should not need `install_sync_read_lane()`.

New: `tests/includes/API/V2/Test_Reports_Controller.php`, plus
`tests/includes/Services/Test_Reports_Registry.php` and `Test_Report_Scope_Resolver.php`.

Cover, at minimum:

1. A test plugin registering one `range`-scoped report with a `group_by` and an `extras` fragment
   appears in the registry route and in the report field tree under its title.
2. The per-key route in each scope mode returns a document that passes
   `Report_Document_Validator::validate()`.
3. **The callable receives a resolved scope and never a `WP_REST_Request`** — assert on the actual
   argument the callable was handed, including `from_utc`/`to_utc` as UTC instants and the half-open
   end bound.
4. The three refusals: a cashier without the declared capability → 403; a Free store asking for
   yesterday → 403 `wcpos_report_scope_locked`; a range beyond `HISTORY_DAYS` → 403.
5. **Checks run before the callable** — register a callable that records whether it ran, assert it
   did not run for each refusal. A 403 that still executed the query is the bug this pins.
6. A callable returning a schema-violating document → 500 naming the key; a callable that **throws**
   → 500 naming the key and the message.
7. A device-computed built-in (`sales`) on the per-key route → 404, and the built-ins appear in the
   registry with `source` of `device`.
8. `woocommerce_pos_report_data` fires with `( $data, $key, $scope )` on a server-built document.
9. `woocommerce_pos_receipt_data` fires on both a closure and an X-report document, with mode
   `closure`/`xreport` and an order whose `get_id()` is `0`. Assert `get_id()` — that is the pin that
   stops someone "restoring" `null` later.
10. A malformed registration is dropped without fataling and the rest of the registry still serves.

Lint (`composer run lint-report` / `pnpm run lint:php`) also needs `vendor/`, which you do not have.
Do not try to install it. Instead, match the surrounding style by reading the neighbouring files —
WordPress spacing inside parentheses, Yoda-free comparisons as the file already writes them, and the
existing docblock shape. Do **not** run a blanket formatter over files you did not change.

---

## Out of scope

- Any app/monorepo work. The Sales room (wcpos/roadmap#332) has not landed; there is no tile to build.
- Server-side computation of `sales` or `cash_movements`.
- Pro's store scoping.
- Changing `Report_Document_Validator`, the `report` JSON schema shape, or the built-in column bases.
- A Handlebars migration or any comparison helper in templates — ADR 0039 forbids it.
- Version bumps.

## When you are done

Report: the files added/changed with net line counts, the exact PHPUnit command you ran and its
output, the lint command and its output, and anything in this spec you had to depart from and why.
Do not open a PR.
