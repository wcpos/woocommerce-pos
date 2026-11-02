# Contract extract — the authority for this work

Source: wcpos/wiki `architecture/client/reports-and-closures/document-and-seam.md`
(hub: `architecture/client/reports-and-closures.md`; spokes: `page-and-catalogue.md`, `time-refunds-and-reach.md`)

## The seam (verbatim, the paragraph that governs)

> One filter, `woocommerce_pos_reports`, returns the registry keyed by report key; built-ins
> register through it too, so the picker, the field tree and the fixtures have one source of
> truth, and the device knows which keys it computes locally. A registration declares title,
> scopes (`session`, `range`), optional group-by options, capability (default
> `view_woocommerce_pos_reports`), optional extras fragment, optional default template, an
> optional tile declaration (one number and one line for the Sales room; a report without one
> shows its name and a chevron), and a query callable that receives a **resolved scope object**
> (mode, store, register, and either the session or the range as store-timezone day bounds
> already converted to UTC instants, plus the business day) and returns the tabular core plus
> extras. The plugin exposes the registry and a per-key document route under `wcpos/v2/reports`.
> Before the callable runs the plugin checks `access_woocommerce_pos`, the declared capability
> and the Free scope gate; the callable runs as the requesting user; the returned document is
> validated against the `report` JSON schema, and an invalid or throwing report is a 500 naming
> the key and the plugin's message, shown as *Report failed* with the key, never a blank print.
> Scoping the query to store and register is the report's responsibility. The seam lives in the
> Free plugin like every extension point; a registered report shows on Free under the scope gate.

> `woocommerce_pos_receipt_data` is applied to closure and X-report documents with a null order
> and a mode of `closure` or `xreport`; `woocommerce_pos_report_data( $data, $key, $scope )` runs
> on every server-built report document. PHP extras reach only server-built documents; an offline
> render shows the core and a template guards on the missing key.

## The document shape (the `report` type, landed by #328 / woocommerce-pos#1996)

Top-level keys mirror the closure document: `report`, `store`, `register`, `cashier`, `software`,
`fiscal` (`document_type` is `report`, `is_report_document` true), `i18n`.

`report` holds:
- `key`, `title`, `subtitle`
- `scope`: `mode` (`session`|`range`), human `label`, store and register ids and name,
  `business_day`, and either the session's id/number/opened/closed instants, or the range's
  `from`/`to` as store-timezone day bounds with the receipt date-field set
- `group_by`
- `columns` (key, label, type of `text`·`number`·`money`·`percent`·`datetime`, align) and
  `column_count` — **the producer states the count**, because a logic-less template cannot count
  an array and a group heading must span the table
- `rows`, whose `cells` are an array **in column order** of key, raw `value`, `formatted`, and the
  column's `align` copied onto the cell
- `groups` with subtotals when grouped; `totals`; `count`; `has_groups`, `has_rows`;
  `generated_at`; `is_partial` with `partial_reason`

**Key rule:** row, group, column and cell keys never start with an underscore — the renderers'
sanitiser treats such keys as private metadata and drops them.

**Why cells are in column order:** ADR 0039 forbids logic in templates. One logic-less template
renders any report without knowing its columns, and `formatted` is produced by whoever builds the
document, never by a renderer.

**Extras** are named keys **beside `report`, never inside it**, declared as field-tree fragments in
the receipt schema's shape. The `report` field tree is the tabular core plus the union of every
registered report's extras under the report's title.

## Built-in column sets (fixed by the contract; a landing may reorder or relabel, never re-base)

- Sales ungrouped: sales · gross · refunds · net · tax
- by payment method: method · payments · taken · refunded · net
- by cashier or register: name · sales · gross · refunds · net · tax
- by tax rate: rate · sales · taxable base · tax · gross
- by item: item · qty sold · qty refunded · net
- by category: category · qty · net · tax
- Cash movements: time · type · reason · actor · amount, with in · out · net totals

## Producers — who computes what

Built-ins are **device-computed** from local sales, payment rows, refunds and movements, and
rendered offline. **The server never computes a built-in**; where it renders one (cloud print, a
PDF) it takes the device-built document as input.

**Registered reports are server-computed** by their callable and fetched by the device.

> This is the single most important boundary in the ticket. Built-ins register through
> `woocommerce_pos_reports` **so that the registry, the field tree and the fixtures have one
> source of truth, and the device knows which keys it computes locally** — registration is a
> *declaration*, not a server-side implementation. A built-in's registration therefore declares
> its identity, scopes, group-by options, columns and tile, and is marked as device-computed; it
> does not supply a server query callable that recomputes it. Do not write server-side
> recomputation of the built-ins.

## The Free scope gate, and where it does and does not bite

From `page-and-catalogue.md`:

> Server-side, Pro scopes the closures and sessions lists to the caller's stores through
> `woocommerce_pos_closures_list_args`; the Free plugin's route serves the caller's own records
> whatever the register, so the Free gate is the app's scope control, not a server refusal.

That sentence is about the **closures and sessions list routes**. It does **not** govern this
ticket: the seam paragraph states plainly that before a registered report's callable runs the
plugin checks "the declared capability and the Free scope gate", and the ticket's acceptance
criteria require "a Free store asking for yesterday gets the scope gate's refusal". The two are
consistent because built-ins are device-computed (there is no server route to gate), whereas a
registered report is server-computed and so the gate must exist server-side.

Free's scope: **today, on this register.** ("Free opens the same page scoped to today on this
register"; "Free's 'today' is the sessions whose business day is today"; "every registered report
for today".) Pro: any range within reach, any register, any store the cashier is allowed.

Reach is one constant, `HISTORY_DAYS = 92`, capping how far any range may go back.

## Blind cashiers

> Blind cashiers, holders of no `view_woocommerce_pos_reports`, cannot open Reports at all: the
> server refuses the reads.

## Business day and the store's clock

A day boundary is computed in the **store's** timezone. Timezone source, chosen once: the site's
`timezone_string`, then `gmt_offset`, then the device clock, then UTC. A closure belongs to the
business day its session **opened** in; the business day is stamped on the session at open and the
closure copies the session's stamp.

The resolved scope hands the callable day bounds **already converted to UTC instants**, plus the
business day — so a report's query never does timezone arithmetic itself.
