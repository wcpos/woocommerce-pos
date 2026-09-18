# Investigation: the receipt data contract and its declarations

Read-only. Produce a report; change no files. Answer in Markdown with headed sections matching the numbered questions. Quote line numbers. Be concrete and short; no prose padding. Budget: this is a survey of ~5,000 lines, expect 20–30 minutes.

## Context

WCPOS renders receipts from a JSON "receipt data" payload through logic-less Mustache templates (ADR 0039 in `docs/adr/0039-no-comparison-logic-in-receipt-templates.md`: no template logic, no vendor booleans, plugins extend via the `woocommerce_pos_receipt_data` filter). An architecture review claims the payload's contract is declared four times and drift is caught only by a test:

- live: `includes/Services/Receipt_Data_Builder.php` — `build( WC_Abstract_Order $order, string $mode, $pos_store )`
- preview: `includes/Services/Preview_Receipt_Builder.php` — `build( $pos_store )`, sample data for the template editor
- editor field tree: `includes/Services/Receipt_Data_Schema.php` — `get_field_tree()` (lines 313–1276), `get_json_schema()`, `get_mock_receipt_data()` (line 1599)
- gallery fixtures: `templates/gallery/preview-data/*.json` loaded by `includes/Services/Receipt_Preview_Fixture_Loader.php`
- drift test: `tests/includes/Services/Test_Receipt_Builders_Contract_Sync.php`

Also in play: `Receipt_Payload_Assembler.php`, `Receipt_I18n_Labels.php`, `Receipt_Snapshot_Store.php`, `Receipt_Store_Resolver.php`, `includes/Templates/Receipt.php`, `includes/API/V1/Receipts_Controller.php`, `includes/API/V1/Templates_Controller.php`, `includes/Admin/Templates/Single_Template.php`, `includes/Services/Template_Pdf_Service.php`, `includes/Services/Print_Job_Service.php`.

The proposal under evaluation: one Receipt Data module with a `Receipt_Source` interface and two source adapters (live WooCommerce order; sample/fixture), with the field tree, JSON schema and mock derived as projections of one field declaration, so the sync test becomes redundant by construction.

## Questions

1. **Contract inventory.** List the top-level keys of the payload as each declaration knows them (live builder output, preview builder output, field tree, mock, each gallery fixture). One table, keys as rows, declarations as columns, ✓/✗. Then list every key that is NOT in all five, and say whether the difference looks deliberate (with the evidence: comment, test, docblock) or drift.

2. **What the preview builder actually does.** Classify its 1,267 lines by role: (a) generating sample values (fake store, cashier, customer, products, taxes); (b) re-implementing shaping the live builder also does (money marking, tax summary, taxable bases, refunds, meta pairs, i18n labels, zero-falsy rules, date formatting); (c) preview-only behaviour. Give approximate line counts for each and the main function names in (b) with their live-builder counterparts.

3. **Could the preview be a fake order through the live builder?** Concretely: can a `WC_Order` with items, taxes, coupons, fees, shipping, refunds and customer data be built in memory (no `save()`) so `Receipt_Data_Builder::build()` produces the preview payload? Check what the live builder reads that requires persistence (order id, refunds via `wc_get_orders`, `get_meta`, product objects, tax rate rows, customer user). Name each obstacle with the line. If refunds/products need DB rows, say what the preview shows today for those and whether a fixture-backed `Receipt_Source` interface is the cheaper seam than a fake order. State which approach you would pick and why in three sentences.

4. **The `$mode` parameter** of `Receipt_Data_Builder::build()`: its values, what each changes, and every caller with the value it passes.

5. **Consumers of the schema projections.** Who calls `get_field_tree()`, `get_json_schema()`, `get_mock_receipt_data()`, `format_money_fields()`? For each consumer say what it needs (labels? types? example values? section nesting?) and whether that information already exists in the live builder in some form, or only in the schema file.

6. **The gallery fixtures.** For each `templates/gallery/preview-data/*.json`: what loads it, when, and whether it is used for the admin gallery preview only, for tests, or both. Are they hand-authored copies of the contract, or generated? Is there a test that checks them against the field tree or builders?

7. **The sync test.** What exactly does `Test_Receipt_Builders_Contract_Sync` assert, case by case (one line each)? Which of those checks would survive as useful tests if the contract had one declaration?

8. **Where the filter fires.** Where `woocommerce_pos_receipt_data` and any sibling receipt filters are applied (file:line), in which paths (live, preview, fixture, snapshot), and whether preview output goes through the same filter.

9. **Snapshot store.** What `Receipt_Snapshot_Store` persists and whether stored snapshots pin an older contract shape that a refactor must keep readable.

10. **Change history.** From `git log --oneline -- includes/Services/Receipt_Data_Builder.php includes/Services/Preview_Receipt_Builder.php includes/Services/Receipt_Data_Schema.php` (last 40), which commits touched two or more of the three files, and for three of them (`cd1c834b`, `cf7cba56`, `35eee00f` if present) list the files each touched with `git show --stat`.

11. **Your estimate.** For the proposal as stated, and for the cheapest deepening that would still delete the sync test: expected net production lines, which tests move/delete, and the two biggest risks. Do not propose a design beyond that.
