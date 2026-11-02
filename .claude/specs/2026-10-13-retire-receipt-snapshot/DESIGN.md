# Retire the order-meta receipt snapshot

**Date:** 2026-10-13 · **Status:** draft, awaiting Paul's approval · **Lanes:** one PR against `main` (1.10.x), one against `next` (2.0.0), one wiki PR.

## Intent

`_wcpos_receipt_snapshot` is a 10 KB JSON blob written to order meta on every `woocommerce_payment_complete`, web-store orders included. Nothing in the 1.10 line reads it: the POS app requests `mode=live` unconditionally, and PDF, cloud print, thermal and email receipts all build live. On `next` the fiscal records table (`wcpos_fiscal_records`, roadmap#247) already holds the same payload as the `sale` row, so a 2.0 POS sale is stored twice and an online order still gets the meta copy.

The outcome Paul asked for, in his words: do as recommended, "but I don't want to leave that junk as postmeta on people's sites."

So: 2.0 makes the fiscal table the only store for the sale document, the stored document stops carrying the translated label block, and both lanes delete the existing meta rows from merchants' databases rather than leaving them behind.

Research behind this: the 2026-10-13 investigation (sizes measured on wcpos.local, HPOS), issue #713, roadmap#247, and the wiki pages `architecture/client/fiscal-groundwork/*.md` and `architecture/plugin-free/receipt-snapshots-and-adapters.md`.

## Goals

1. A paid online order writes nothing: no snapshot, no receipt number consumed, no order note, no submission-status meta.
2. On 2.0 a POS sale is stored once, in `wcpos_fiscal_records`, and every reader of the frozen sale (live-build identity copy, `mode=fiscal`, closures' late-sale check) reads the table.
3. The stored sale and refund documents no longer carry `i18n`; labels are added when a document is read for rendering.
4. Existing `_wcpos_receipt_*` meta rows, the "POS fiscal receipt snapshot created" order notes, and the `wcpos_receipt_sequence_counter` option are removed from upgraded sites on both lanes, in the background, without touching the orders themselves.
5. The client needs no change.

## Non-goals

- Hash chaining, signing, per-register numbering, country modules. The `series` column and the enrich hook stay as the seams for that work.
- Slimming the four date bundles (`order.created/paid/completed/printed`, each ~520 bytes of formatted strings). That needs every bundle to carry an ISO value, which is a receipt-schema bump mirrored in the client's local receipt builder. It is the next lever (about 3 KB per sale) and gets its own ticket. **This is my call; Paul was told "about 2 KB" and this design lands at roughly 5.5 KB per one-line sale, down from 10.2 KB.**
- Importing 1.10 snapshots into the 2.0 table. Their numbers were never printed (1.10 live receipts carry an empty fiscal identity) and they have no register, session or sale time. Nothing is lost by dropping them.
- Changing the `receipt_default_mode` setting or its UI.

## Anti-goals

- Any cleanup that calls `$order->save()`, directly or via `delete_meta_data()` + save. That rewrites `date_modified` on every paid order and fires `order.updated` webhooks store-wide.
- Any `*_post_meta()` call on an order id (HPOS rule, `.ai/rules/hpos-order-meta.mdc`).
- A one-shot cleanup inside a versioned `update-<version>.php`. Those run at most once and can be skipped permanently; a 100k-row delete must be resumable.
- Blocking a checkout or a sync push on fiscal bookkeeping. A refused insert is logged and surfaced, never thrown into the order write (existing `Fiscal_Record_Writers::write()` behaviour).

## Measured facts the design rests on

| Fact | Value | Source |
|---|---|---|
| One-line sale snapshot | 9.6 to 10.2 KB | wcpos.local, May 2026 rows |
| Of which `i18n` (152 labels, en_US) | 4.8 KB | same |
| Of which four date bundles | 2.1 KB | same |
| Per extra line item | ~650 B | same |
| HPOS `wc_orders_meta.meta_value` | `text`, 64 KB, wpdb truncates silently | WC 10.8.1 source, `class-wpdb.php` |
| Readers of the meta snapshot on `main` | none in practice (client sends `mode=live`) | monorepo-v2 `receipt.tsx:122` |
| Readers on `next` | `Receipt_Data_Builder::build()` live identity copy; `Receipts_Controller` fiscal mode; `Fiscal_Record_Writers::record_sale()` late-sale check | `next` at `3f540426` |

## Part A: `main` (1.10.x patch)

### A1. Stop writing

- Delete `Receipt_Snapshot_Store` and `Fiscal_Receipt_Service`, their tests, and the `Receipt_Snapshot_Store::instance()` line in `Service_Groups::construct_order()`.
- `Receipts_Controller::get_item()`: `mode` keeps accepting `fiscal` and `live`. `fiscal` returns the existing 404 `wcpos_receipt_snapshot_missing` for every order, which is what every order without a snapshot already gets today. The response keeps its shape: `has_snapshot` is always `false`, `submission_status` is always `'pending'`. The response contract does not change in a patch release.
- `tests/includes/Sync/Test_Write_Controller.php::test_offline_completed_create_snapshots_cashier_and_tender_at_payment_complete` loses its snapshot assertions; the cashier and tender stamping it also proves stays.

### A2. Clean up

A new `Services\Receipt_Snapshot_Cleanup` (name is the builder's) that removes:

- order meta keys `_wcpos_receipt_snapshot`, `_wcpos_receipt_snapshot_checksum`, `_wcpos_receipt_sequence`, `_wcpos_receipt_snapshot_created_at_gmt`, `_wcpos_receipt_submission_status`, `_wcpos_receipt_submission_status_updated_at`, from `{prefix}wc_orders_meta` when that table exists and from `{prefix}postmeta` (compat-sync sites hold both);
- order notes whose content is the English string `POS fiscal receipt snapshot created (receipt #%d).` or its translation in the site locale (`comment_type = 'order_note'`, content `LIKE` the sprintf prefix);
- the option `wcpos_receipt_sequence_counter`.

Mechanics:

- Armed from `Activator::db_upgrade()` **and** from an `admin_init` self-heal hook, behind its own option (`wcpos_receipt_snapshot_cleanup`: absent → `pending` → `done`). Arming is idempotent. `main` has no `Gallery_Update_Status::maintain()` (that exists only on `next`), so Part A adds its own `admin_init` hook; on `next` the sync brings that hook across and it can share the existing self-heal path or stay separate, builder's choice.
- The old option `wcpos_receipt_sequence_counter` is deleted only in the cleanup's **final** step, after every meta batch, and on `next` only after `wcpos_fiscal_sequence_sale` exists (B1 seeds it lazily from that option, so the seed must have happened first; the cleanup calls the seed before deleting).
- `tests/includes/Test_Uninstall.php` currently pins that `wcpos_receipt_sequence_counter` survives uninstall by default. That pin goes, and `uninstall.php`'s option list is updated to match.
- Runs in the background: Action Scheduler when its functions exist (the plugin already uses it in `Font_Pack_Loader`), WP-Cron single event otherwise. Each run deletes a bounded batch (1000 rows per key per table is the starting point) and re-schedules itself until a run finds nothing to delete, then marks `done`.
- Deletes are direct, batched SQL on the two meta tables and `wp_comments`. After each batch the builder invalidates the caches for the touched rows (WooCommerce's `OrderCache` for HPOS orders; `post_meta` / `comment` cache groups otherwise). Which invalidation calls, and how to collect touched ids cheaply, is the builder's.
- Multisite: armed per site through the existing network-activation path.

### A3. Tests (Part A)

- A paid POS order and a paid online order (any gateway that calls `payment_complete()`) write none of the six keys, no note, and leave the option untouched.
- The cleanup removes seeded rows from both meta tables, removes the note, leaves unrelated notes and meta, deletes the option, marks `done`, and does not change any order's `date_modified`.
- Cleanup is resumable: with a batch size of 1 and three seeded rows, three runs finish the job.
- `GET /wcpos/v1/receipts/{id}?mode=fiscal` → 404 `wcpos_receipt_snapshot_missing`; `mode=live` unchanged.

## Part B: `next` (2.0.0)

### B1. The table mints the sale

- `Fiscal_Record_Writers` hooks `woocommerce_payment_complete` directly. For a POS order (`wcpos_is_pos_order()`) with no `sale` row it builds `Receipt_Data_Builder::build( $order, 'fiscal' )` and records `type => 'sale'` with the payload in the existing callable form. Inside the callable, in this order: the minted number is written into `fiscal.sequence`, `fiscal.receipt_number` and `fiscal.immutable_id` (`"{order_id}:{number}"`); `i18n` is stripped (B3); then `woocommerce_pos_fiscal_snapshot_enrich( $snapshot, $order_id )` runs, so an enricher sees the number and the exact bytes that will be stored, as it does today. Online orders write nothing, as today.
- **Enrich contract, stated in the hook's docblock and the wiki:** the callable runs under the site-wide `wcpos_fiscal_sequence_lock` (5-second wait, shared by every record type), so an enricher must be local computation only: a hash, a local signature, a QR payload. Submitting to a tax authority is a reporting step that runs after the row exists (the groundwork note already requires async submission). **My call**, forced by the lock that already exists.
- `Fiscal_Record_Store::record()` drops the special case that requires a caller-supplied `number` for `sale`; `sale` uses the per-type option counter like every other type (`wcpos_fiscal_sequence_sale`). The counter is **seeded lazily, under the lock, the first time it is absent**: `max( MAX(number) WHERE type='sale' AND series='', (int) get_option('wcpos_receipt_sequence_counter') )`. Lazy rather than on-upgrade because `next` installs already report version 2.0.0 (dev-next), so a versioned seed would never run there and their first new sale would collide with existing rows on the `(type, series, number)` key.
- The late-sale check in `record_sale()` reads the sale row's `received_at_gmt` instead of `_wcpos_receipt_snapshot_created_at_gmt`.
- `Receipt_Snapshot_Store` and `Fiscal_Receipt_Service` are deleted. `Receipt_Snapshot_Store::checksum()` moves to the record store. `resolve_mode()` / `get_default_mode()` move to `Receipts_Controller` or a small helper; the setting keeps working.

### B2. Readers move to the table

- `Receipt_Data_Builder::build()` live mode copies the frozen identity (`fiscal.*` keys listed in the existing code, plus `register` and `software`) from `Fiscal_Record_Store::find_sale()`.
- `Receipts_Controller::get_item()` fiscal mode returns the thawed sale document (B3) or the existing 404. `has_snapshot` means "a sale row exists". The `submission_status` field and its two meta keys go; nothing in Free, Pro or the client reads them, and submission state belongs to the country modules' own storage. **My call.**
- `Fiscal_Record_Store::resolve_document()` returns thawed documents for `refund:` selectors. Closures are already built live from the closure row and are untouched.

### B3. Freeze and thaw

- `i18n` is stripped **before anything hashes or signs the document**, never after. `build_refund_document()` on `next` already writes `fiscal.hash` as the SHA-256 of the whole document; stripping after that would store a hash of bytes that no longer exist. So: `Fiscal_Record_Store::record()` strips `i18n` from every array payload on write (that is the storage contract), the sale callable strips before enrich (B1) so an enricher sees the stored shape, and `build_refund_document()` computes `fiscal.hash` over a label-free copy while returning the document with its labels, because the gallery-template contract tests and any direct render consume the builder output without going through the store. The row checksum is over the stored bytes.
- Labels on a reprint are the current labels for the stored locale, not the labels that printed at the time of sale. Every regime in scope protects the dataset (numbers, dates, lines, totals, tax, tender), not the wording of a label, and a reprint is already marked as a copy. An extension that changes label wording does so through the label dictionary, not through `woocommerce_pos_receipt_data`, if it wants reprints to match. **My call.**
- On read for rendering, one helper (`Fiscal_Record_Store::document( array $row ): array` or equivalent) re-adds `i18n` from `Receipt_I18n_Labels::get_labels( $payload['presentation_hints']['locale'] ?? '' )`. Every path that hands a stored sale or refund document to a template goes through it: `get_item` fiscal mode, `get_pdf` with a document, `Templates\Receipt::resolve_document_payload()`.
- `GET /wcpos/v2/records/{id}` keeps returning the stored payload as stored. It is the audit view.

### B4. Clean up

Same cleanup service as Part A (it arrives on `next` through the regular `main` → `next` sync; the `next` PR must not duplicate it). On `next` it additionally seeds `wcpos_fiscal_sequence_sale` (B1) **before** deleting `wcpos_receipt_sequence_counter`. `_wcpos_receipt_print_count` is not a snapshot key and stays.

### B5. Tests (Part B)

- A POS `payment_complete` writes exactly one `sale` row with a number from `wcpos_fiscal_sequence_sale`, a correct `immutable_id`, no `i18n` in the stored payload, and no `_wcpos_receipt_*` meta; a replay writes nothing; an online order writes nothing.
- Seeding: with sale rows numbered up to 7 and an old counter of 9, the next sale is 10.
- Live build copies the identity from the table; no row → empty identity.
- `mode=fiscal` returns a document whose `i18n` block equals the labels for the stored locale; `document=refund:{id}` likewise.
- The existing writers, closure and late-sale tests keep passing with the meta reads removed.
- Both `Test_Receipts_Controller` and the builders-contract sync test still pin schema 1.4 (no schema change in this design).

### B6. Sync note

Part A deletes two classes that Part B rewrites around. Land Part B first, then sync `main` into `next`; the merge then sees "deleted on main, deleted on next" for both classes and keeps `next`'s versions of the touched callers. If Part A lands first, the sync PR resolves the two files by taking `next`'s side and re-applying Part B's deletions, per the `extracted-module-sync-drops-next-only-members` note.

## Documentation

- Wiki `architecture/plugin-free/receipt-snapshots-and-adapters.md`: "Immutable fiscal snapshots" and "Fiscal receipt service" sections rewritten around the table; the meta keys removed.
- Wiki `architecture/client/fiscal-groundwork/records-and-closures.md` §1: the "extend, not migrate" paragraph and the `sale` row of the writers table updated.
- Wiki `architecture/client/fiscal-groundwork.md`: the Records row no longer says the meta snapshot stays as a mirror.
- No docs-site change: the user docs do not mention the snapshot.

## Calls I made (Paul can overrule any of them)

1. `main` stops writing entirely rather than gating to POS orders. Deleting the rows removes the only reason to keep writing them.
2. No import of 1.10 snapshots into the 2.0 table.
3. Only `i18n` is stripped from stored documents in this change; date bundles are a follow-up ticket because they touch the receipt schema and the client.
4. `submission_status` leaves the receipts response in 2.0.
5. The snapshot order notes are deleted along with the meta.
6. The cleanup is option-gated and background, not a versioned update file.
7. Part A deletes `woocommerce_pos_fiscal_snapshot_enrich` and `woocommerce_pos_fiscal_submission_retry` from the 1.10 line without a shim. Pro on both branches and the client use neither; the wiki lists the filter as an extension point but the framework it extends has not shipped. The changelog names both hooks as removed, and the filter returns in 2.0 with the contract in B1.
8. Enrich runs after the number is minted and under the record lock, and must not do I/O (B1).
9. Reprints carry current labels, not sale-time labels (B3).
10. The sale counter is seeded lazily on first mint, not by an upgrade step (B1).

## Left to the builder

- Class and option names; batch size; the exact cache invalidation per storage mode; collecting touched order ids without a second full scan.
- Where `resolve_mode()` lives after the snapshot store is gone.
- Whether `Receipt_Print_Counter::mark()` needs to run on the thawed document or before thawing (it only touches `fiscal.*`, so either should work; keep the current order).
- Test fixtures: the HPOS and posts-storage variants of the cleanup test (`Test_Fiscal_Receipt_Service_HPOS` shows the pattern).
