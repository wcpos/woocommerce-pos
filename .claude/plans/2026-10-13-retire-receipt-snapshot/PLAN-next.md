# Fiscal table as the only sale store on `next` (2.0.0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On 2.0 a POS sale is recorded once, in `wcpos_fiscal_records`, minted by the record store on `woocommerce_payment_complete`; every reader of the frozen sale reads the table; stored sale and refund documents carry no `i18n` block and get it back when read for rendering; the order-meta snapshot and its services are gone.

**Architecture:** `Fiscal_Record_Writers` gains the `payment_complete` observer and builds the sale payload inside the store's callable, where the minted number is known; `Fiscal_Record_Store` mints `sale` from its own per-type counter (seeded lazily from existing rows and the legacy option) and owns `checksum()` and a `document()` thaw helper. The live builder, the receipts route and the refund document resolver switch from meta to `find_sale()` / `document()`. The cleanup service arrives later through the `main` → `next` sync and is extended to seed before it deletes.

**Tech Stack:** PHP WordPress plugin, WooCommerce HPOS, PHPUnit through wp-env, MySQL advisory locks (existing).

**Spec:** `.claude/specs/2026-10-13-retire-receipt-snapshot/DESIGN.md` (Part B, "Documentation", "Calls I made").

**Lane:** branch from `origin/next`, PR targets `next`. Lands **before** the `main` PR (spec B6). File references below are to `origin/next` at `3f540426`.

## Global Constraints

- Same standards as PLAN-main (PHPCS, `Logger`, HPOS order-meta rule, test naming and assertion rules, one PHPUnit invocation at a time).
- New REST tests dispatch `/wcpos/v2/...` routes (the lane-coverage gate rejects new `wcpos/v1` literals; `Test_Receipts_Controller` on `next` already uses v2 paths).
- `wcpos_fiscal_records` is write-once: no code path issues `UPDATE` or `DELETE` on it. Tests that need a row's `received_at_gmt` or `payload` changed do it with `$wpdb->update` in Arrange only.
- `woocommerce_pos_fiscal_snapshot_enrich( array $snapshot, int $order_id )` keeps its name and argument order; its docblock states the contract: runs under `wcpos_fiscal_sequence_lock`, local computation only, no network I/O.
- Receipt Data schema stays **1.4**; `Receipt_Payload_Assembler::assemble()` still adds `i18n` to live builds.
- No new environment variables.

## Review Focus

1. A `next` site that already has `sale` rows (dev-next) and no `wcpos_fiscal_sequence_sale` option: the first new sale must not collide on `(type, series, number)`. Pinned in Task 1 (`test_sale_sequence_is_seeded_from_existing_rows_and_legacy_counter`).
2. An enrich filter that returns a non-array must be ignored, not stored. Pinned in Task 2 (`test_enrich_filter_returning_non_array_is_ignored`).
3. A refund document read back must verify: `fiscal.hash` equals the SHA-256 of the stored bytes with `hash` blanked, and `i18n` is absent from those bytes. Pinned in Task 3 (`test_refund_document_hash_covers_stored_bytes_without_i18n`).
4. A stored document whose locale differs from the site locale must thaw with that locale's labels. Pinned in Task 3 (`test_document_thaws_labels_for_the_stored_locale`).
5. A POS order whose `payment_complete()` comes from a storefront-lane webhook must still get a sale row. Pinned in Task 2 (`test_payment_complete_from_storefront_lane_writes_sale`).

---

### Task 1: The store mints `sale` numbers and owns the checksum

**Files:**
- Modify: `includes/Services/Fiscal_Record_Store.php` (`record()` ~lines 95-170)
- Modify: `includes/Services/Receipt_Data_Builder.php:1032` (use `Fiscal_Record_Store::checksum`)
- Modify: `tests/includes/Services/Test_Fiscal_Record_Store.php:11,43`

**Interfaces:**
- Produces: `Fiscal_Record_Store::checksum( string $json ): string` (sha256, moved from the snapshot store); `record()` accepts `type => 'sale'` **without** `number` and mints it from option `wcpos_fiscal_sequence_sale`; `public function seed_sale_sequence(): int` — under `SEQUENCE_LOCK` (re-entrant from `record()`, so split the locked body from the lock acquisition), when the option is absent sets it to `max( (int) MAX(number) WHERE type='sale' AND series='', (int) get_option( 'wcpos_receipt_sequence_counter', 0 ) )` and returns the value; when present returns the stored value unchanged.

- [ ] **Step 1: Write the failing tests** in `Test_Fiscal_Record_Store`:

```php
public function test_sale_number_is_minted_from_its_own_sequence(): void {
	$store = new Fiscal_Record_Store();
	$first  = $store->record( array( 'type' => 'sale', 'order_id' => 11, 'payload' => array( 'a' => 1 ) ) );
	$second = $store->record( array( 'type' => 'sale', 'order_id' => 12, 'payload' => array( 'a' => 2 ) ) );
	$this->assertSame( 1, $first['number'] );
	$this->assertSame( 2, $second['number'] );
	$this->assertSame( 2, (int) get_option( 'wcpos_fiscal_sequence_sale' ) );
}

public function test_sale_sequence_is_seeded_from_existing_rows_and_legacy_counter(): void {
	global $wpdb;
	$store = new Fiscal_Record_Store();
	$store->ensure_installed();
	// Arrange: rows numbered 5 and 7 exist (direct insert), legacy counter says 9, no sale option.
	foreach ( array( 5, 7 ) as $n ) { $wpdb->insert( $store->table_name(), array( 'type' => 'sale', 'series' => '', 'number' => $n, 'order_id' => $n, 'payload' => '{}', 'checksum' => str_repeat( 'a', 64 ), 'received_at_gmt' => '2026-01-01 00:00:00' ) ); }
	update_option( 'wcpos_receipt_sequence_counter', 9, false );
	delete_option( 'wcpos_fiscal_sequence_sale' );
	// Act.
	$row = $store->record( array( 'type' => 'sale', 'order_id' => 99, 'payload' => array() ) );
	// Assert.
	$this->assertSame( 10, $row['number'] );
}
```

Also change line 43 to `Fiscal_Record_Store::checksum( … )` and drop the `Receipt_Snapshot_Store` import.

- [ ] **Step 2: Run to verify failure**

Run: `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Fiscal_Record_Store.php`
Expected: FAIL — `record()` returns null for a `sale` without `number`.

- [ ] **Step 3: Implement** per Interfaces: delete the `'sale' === $type && empty( $fields['number'] )` guard and the `if ( 'sale' === $type )` branch; before incrementing, when `$type === 'sale'` and `get_option( 'wcpos_fiscal_sequence_sale' ) === false`, call the seed; add `checksum()`; builder line 1032 uses it.

- [ ] **Step 4: Run the file; PASS. Commit** `feat(fiscal): the record store mints sale numbers from its own seeded sequence`.

---

### Task 2: The writers record the sale on `payment_complete`; the snapshot services go

**Files:**
- Modify: `includes/Services/Fiscal_Record_Writers.php` (constructor ~line 30, `record_sale()` ~lines 70-135)
- Delete: `includes/Services/Receipt_Snapshot_Store.php`, `includes/Services/Fiscal_Receipt_Service.php`, `tests/includes/Services/Test_Receipt_Snapshot_Store.php`, `tests/includes/Services/Test_Fiscal_Receipt_Service.php`, `tests/includes/Services/Test_Fiscal_Receipt_Service_HPOS.php`
- Create: `includes/Services/Receipt_Mode.php`, `tests/includes/Services/Test_Receipt_Mode.php`
- Modify: `includes/Services/Service_Groups.php:191`
- Modify: `tests/includes/Services/Test_Fiscal_Record_Writers.php:13,28,58-92,115,381,438`
- Modify: `tests/includes/API/V2/Test_Closures_Controller.php:959-961`
- Modify: `tests/includes/Sync/Test_Write_Controller.php:1204-1209,1270` (comments)

**Interfaces:**
- Consumes: Task 1's `record()` and `checksum()`.
- Produces:
  - `Fiscal_Record_Writers::handle_payment_complete( int $order_id ): void` hooked on `woocommerce_payment_complete` in the constructor; returns unless `wcpos_is_pos_order( $order )`.
  - `Fiscal_Record_Writers::record_sale( WC_Order $order ): void` (signature change: no `$snapshot`, no `$sequence`). Records `type => 'sale'` with provenance from `provenance_from_order()` and `payload` as a callable `function ( int $number ) use ( $order ): array` that: builds `( new Receipt_Data_Builder() )->build( $order, 'fiscal' )`; sets `fiscal.sequence = $number`, `fiscal.receipt_number = (string) $number`, `fiscal.immutable_id = $order->get_id() . ':' . $number`; `unset( $snapshot['i18n'] )`; applies `woocommerce_pos_fiscal_snapshot_enrich` and keeps the result only when it is an array. The late-sale branch keeps its logic but reads `$sale['received_at_gmt']` where it read `META_KEY_CREATED_AT`.
  - `Receipt_Mode::resolve( ?string $requested ): string` — `fiscal`/`live` pass through; otherwise `Settings::instance()->receipt_default_mode()` when it is one of the two, else `'fiscal'`.

- [ ] **Step 1: Write the failing tests** in `Test_Fiscal_Record_Writers` (remove the line-28 snapshot hook; the writers now hook themselves). Replace the two sale tests at 58-92 with:

```php
public function test_payment_complete_writes_sale_once_without_meta_or_i18n(): void {
	$order = $this->order();
	$order->payment_complete();
	$order = wc_get_order( $order->get_id() );
	$store = new Fiscal_Record_Store();
	$sale = $store->find_sale( $order->get_id() );
	$this->assertIsArray( $sale );
	$this->assertSame( (int) get_option( 'wcpos_fiscal_sequence_sale' ), $sale['number'] );
	$this->assertSame( $order->get_id() . ':' . $sale['number'], $sale['payload']['fiscal']['immutable_id'] );
	$this->assertSame( (string) $sale['number'], $sale['payload']['fiscal']['receipt_number'] );
	$this->assertArrayNotHasKey( 'i18n', $sale['payload'] );
	$this->assertSame( '', $order->get_meta( '_wcpos_receipt_snapshot', true ) );
	$this->assertSame( '', $order->get_meta( '_wcpos_receipt_sequence', true ) );
	$this->assertSame( $order->get_meta( '_wcpos_register' ), $sale['register_id'] );
	do_action( 'woocommerce_payment_complete', $order->get_id() );
	$this->assertSame( array( $sale ), $store->list( array( 'order_id' => $order->get_id() ) ) );
}

public function test_enrich_filter_sees_minted_number_and_no_i18n(): void
// add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', fn( $s, $id ) => $s + record what it saw ); after payment_complete assert the filter saw fiscal.sequence === $sale['number'], no 'i18n' key, and $sale['payload']['fiscal']['qr_payload'] === 'ENRICHED'.

public function test_enrich_filter_returning_non_array_is_ignored(): void
// filter returns 'oops'; sale row still written with the unenriched payload.

public function test_payment_complete_from_storefront_lane_writes_sale(): void
// Service_Groups::reset(); Service_Groups::arm_order_group(); POS order; payment_complete(); find_sale() is an array.
```

Lines 115, 381, 438: replace `persist_snapshot(...)` / `META_KEY_CREATED_AT` seeding with `Fiscal_Record_Writers::instance()->record_sale( $order )` followed, where the test needs a specific timestamp, by `$wpdb->update( $table, array( 'received_at_gmt' => $ts ), array( 'id' => $sale['id'] ) )`. `Test_Closures_Controller:959-961` likewise (`record_sale( $order )` then the `$wpdb->update`).

Create `Test_Receipt_Mode` with the body of the old `test_resolve_mode_behavior` (setting → fallback; request value wins; junk → `'fiscal'`).

- [ ] **Step 2: Run `Test_Fiscal_Record_Writers.php`; expect FAIL** (`handle_payment_complete` undefined / meta still written).

- [ ] **Step 3: Implement** per Interfaces; delete the five files; `Service_Groups::construct_order()` line 191 becomes `Fiscal_Record_Writers::instance();` (remove any second construction of the writers elsewhere so the hooks register once); add the enrich contract docblock.

- [ ] **Step 4: Run** `Test_Fiscal_Record_Writers.php`, `Test_Receipt_Mode.php`, `API/V2/Test_Closures_Controller.php`, `Sync/Test_Write_Controller.php`, one at a time; PASS.

- [ ] **Step 5: Commit** `feat(fiscal): the fiscal table records the sale on payment_complete; the order-meta snapshot store is retired`.

---

### Task 3: Readers move to the table; thaw adds labels

**Files:**
- Modify: `includes/Services/Fiscal_Record_Store.php` (`record()` guard, `resolve_document()` refund branch, new `document()`)
- Modify: `includes/Services/Receipt_Data_Builder.php:858-872` (identity copy), `:1027-1032` (refund hash)
- Modify: `includes/API/V1/Receipts_Controller.php:17-18,201-245`
- Modify: `tests/includes/Services/Test_Receipt_Data_Builder.php:14,146-192`; `tests/includes/API/Test_Receipts_Controller.php:12,50-67,318-372`; `tests/includes/Services/Print_Job_Service_Render_Test.php:20-24`; `tests/includes/Services/Template_Pdf_Service_Test.php:23-27`; `tests/includes/Templates/Test_Receipt.php:216-220`; `tests/includes/Templates/Thermal/Thermal_Renderer_Test.php:22-26`; `tests/includes/API/V2/Test_Records_Controller.php` (add one test)

**Interfaces:**
- Consumes: Task 2's `record_sale( WC_Order )`.
- Produces: `Fiscal_Record_Store::document( array $row ): array` — `$row['payload']` with `i18n` set to `Receipt_I18n_Labels::get_labels( $row['payload']['presentation_hints']['locale'] ?? '' )`. `record()` logs and strips a stray `i18n` key before encoding. `resolve_document()` returns `document( $record )` for `refund:` selectors. `Receipts_Controller::get_item()` fiscal mode returns `document( find_sale() )` or the 404; response has no `submission_status`; `has_snapshot` is `null !== find_sale()`.

- [ ] **Step 1: Write the failing tests**

In the four render tests (Print_Job, PDF, Receipt, Thermal) replace the five Arrange lines with:

```php
$order->set_created_via( 'woocommerce-pos' ); $order->save();
$enrich = static function ( array $s ) { $s['fiscal']['qr_payload'] = 'FROZEN-QR-243'; return $s; };
add_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich );
try { \WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers::instance()->record_sale( $order ); } finally { remove_filter( 'woocommerce_pos_fiscal_snapshot_enrich', $enrich ); }
$number = ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->find_sale( $order->get_id() )['payload']['fiscal']['receipt_number'];
```

`Test_Receipt_Data_Builder:146-192`: same seeding through the enrich filter (set `qr_payload`, `extra_fields`); the "1.3 snapshot without a key" tail at 185-192 becomes a `$wpdb->update` of the row's `payload` with those keys removed. Assertions unchanged.

`Test_Receipts_Controller`: `handle_payment_complete` calls become `Fiscal_Record_Writers::instance()->handle_payment_complete( $order->get_id() )` on a POS order; the line-362 equality check compares `find_sale()['payload']` before and after. Add:

```php
public function test_get_item_fiscal_mode_returns_document_with_labels(): void
// POS order, payment_complete; GET /wcpos/v2/receipts/{id}?mode=fiscal → 200; data['i18n'] === Receipt_I18n_Labels::get_labels( data['presentation_hints']['locale'] ); assertArrayNotHasKey( 'submission_status', response ); has_snapshot true; order without a sale row → 404 wcpos_receipt_snapshot_missing and has_snapshot false on live.

public function test_document_thaws_labels_for_the_stored_locale(): void
// $wpdb->update the sale row payload's presentation_hints.locale to 'fr_FR' (fixtures already switch locale elsewhere in this file or Test_Receipt_I18n_Labels); fiscal read returns labels equal to Receipt_I18n_Labels::get_labels( 'fr_FR' ).
```

`Test_Fiscal_Record_Writers` (Task 2 file) add:

```php
public function test_refund_document_hash_covers_stored_bytes_without_i18n(): void
// wc_create_refund on a POS sale; $row = find_refund(); $stored = $row['payload']; assertArrayNotHasKey( 'i18n', $stored ); $copy = $stored; $copy['fiscal']['hash'] = ''; assertSame( Fiscal_Record_Store::checksum( wp_json_encode( $copy ) ), $stored['fiscal']['hash'] ).
```

`Test_Records_Controller` add `test_record_route_returns_stored_payload_without_i18n` (GET `/wcpos/v2/records/{id}` → `payload` has no `i18n`).

- [ ] **Step 2: Run `Test_Receipts_Controller.php`; expect FAIL** (`submission_status` present, `i18n` absent in fiscal mode once Task 2 strips it).

- [ ] **Step 3: Implement** per Interfaces. In `build_refund_document()` compute the hash over a copy with `i18n` removed and return the document with its labels intact (the gallery template tests render it directly); `record()` strips `i18n` on write. In `build()` replace the `get_snapshot()` read with `find_sale()['payload'] ?? null`.

- [ ] **Step 4: Run** each touched file one at a time, then `pnpm run test:unit:php`. PASS; total = previous − 7 (snapshot store) − the two fiscal-service files + the new tests.

- [ ] **Step 5: Commit** `feat(receipts): frozen identity and fiscal mode read the fiscal table; stored documents drop i18n and regain it on read`.

---

### Task 4: Uninstall, changelog, PR

- [ ] **Step 1:** `uninstall.php:511`: remove the `wcpos_receipt_sequence_counter` preserved pattern (keep `wcpos_fiscal_sequence_%` as it is). `Test_Uninstall.php:671,688`: assert the counter is deleted.
- [ ] **Step 2:** `CHANGELOG.md` on `next`, under the 2.0.0 section: `- Changed: POS sales are recorded once, in the fiscal records table, when payment completes; the duplicate receipt snapshot in order meta is no longer written and existing rows are removed after upgrading. Stored sale and refund documents no longer embed the translated label block; labels are added when a document is rendered. The receipts endpoint no longer returns submission_status.`
- [ ] **Step 3:** Lint, push, open a ready-for-review PR against `next` titled `feat(fiscal): the fiscal records table is the only store for the sale document`. Body: spec path, the land-order note (merge before the `main` PR), test commands and totals. Arm the PR watcher on the first push.

---

### Task 5: After the `main` → `next` sync lands (separate small PR on `next`)

**Files:** `includes/Services/Receipt_Snapshot_Cleanup.php` (arrives from `main`), `tests/includes/Services/Test_Receipt_Snapshot_Cleanup.php`

- [ ] **Step 1: Failing test** `test_finish_seeds_sale_sequence_before_deleting_legacy_counter`: option `wcpos_receipt_sequence_counter` = 9, no sale rows, no `wcpos_fiscal_sequence_sale`; `Receipt_Snapshot_Cleanup::run()` on an empty site; assert `get_option( 'wcpos_fiscal_sequence_sale' ) === 9` and the legacy option is gone.
- [ ] **Step 2:** `finish()` calls `( new Fiscal_Record_Store() )->seed_sale_sequence()` before `delete_option( 'wcpos_receipt_sequence_counter' )`.
- [ ] **Step 3:** Run the file; PASS; commit `fix(fiscal): the snapshot cleanup seeds the sale sequence before it drops the legacy counter`; PR against `next`.

---

### Task 6: Wiki (repo `wcpos/wiki`, one PR)

- [ ] `architecture/plugin-free/receipt-snapshots-and-adapters.md`: rewrite "Immutable fiscal snapshots" around `wcpos_fiscal_records` (`sale` minted on `payment_complete` for POS orders, number from `wcpos_fiscal_sequence_sale`, checksum over stored bytes, no `i18n` stored, thawed on read); delete "Fiscal receipt service"; update "Receipt modes" (`fiscal` = the sale row's document); remove the six meta keys wherever listed.
- [ ] `architecture/client/fiscal-groundwork/records-and-closures.md` §1: replace the "extend, not migrate" paragraph with "the table is the only store; the 1.10 meta snapshot is removed on upgrade"; the `sale` row of the writers table: trigger `woocommerce_payment_complete` in `Fiscal_Record_Writers`, payload "the fiscal build, minus `i18n`, enriched under the lock".
- [ ] `architecture/client/fiscal-groundwork.md` Records row: drop "The sale snapshot in order meta stays as the compatibility mirror."
- [ ] Add the enrich contract (B1) under the hook wherever the wiki documents `woocommerce_pos_fiscal_snapshot_enrich`.
