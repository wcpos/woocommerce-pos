# Spec — Pro cleanup PR: delete the nine Store overrides identical to Free's (§4)

Tree: Pro `/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next` (`05f06c4`, lane `next`). Free (read only): `/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next`.

This PR may also carry the Pro section ("PR 2") of `spec-dead-code.md`. Both are pure Pro deletions. If you do both, the budgets add up.

## Goal
`WCPOS\WooCommercePOSPro\Abstracts\Store` extends Free's `WCPOS\WooCommercePOS\Abstracts\Store` and re-declares nine getters whose bodies are byte-identical to the parent's. It also re-implements `StoreInterface`, which the parent already implements. Delete the copies so the getters exist once, in Free.

## Stakes
Medium: store data feeds receipts and the client's store document. A failure costs a wrong or empty store field, and the existing getter tests catch it. Inheritance is exact:
- Every deleted body is `return $this->get_prop( '<key>', $context );`. For `get_timezone` it is the same two-line `is_string` guard.
- `get_prop()` is `WC_Data`'s protected method, so no private-property shadowing can change what it reads.
- The prop keys and `$extra_data` defaults stay declared in Pro (`:76-92`), and `load_data()` still sets them.

## Out of scope (do NOT add)
- Do not touch Free.
- Do not delete `Interfaces/StoreInterface.php` (that is a BC question for Paul).
- Do not move the methods that genuinely differ: `__construct`, `get_data`, `get_formatted_address`, `get_logo_image_src`, `get_tax_id`, `get_tax_ids`.
- No new abstract, trait or helper, no docblock rewrites elsewhere, and no plan files.

## Exact changes — `includes/Abstracts/Store.php` (Pro, 780 lines)
1. Delete lines **263-335**: the docblocks and bodies of `get_url`, `get_phone`, `get_email`, `get_opening_hours`, `get_opening_hours_notes`, `get_personal_notes`, `get_policies_and_conditions` and `get_footer_imprint`, plus one of the two blank lines that follow (`:334-335`).
   - Keep exactly one blank line between `get_meta_field_value()`'s closing brace (`:261`) and the `get_register_sessions()` docblock.
2. Delete lines **391-403**: the `get_timezone()` docblock, the body, and the blank line after it.
3. Line 24: `class Store extends DefaultStore implements StoreInterface {` → `class Store extends DefaultStore {`.
4. Delete `use WCPOS\WooCommercePOS\Interfaces\StoreInterface;` (line 14). First grep the file for any other `StoreInterface` use; at `05f06c4` there is none besides `:14`, `:22` and `:24`.
5. Delete the docblock sentence `Explicitly implements StoreInterface to ensure API consistency.` (line 22).

Before deleting, confirm with `diff` that each Pro method body equals the Free body (Free `includes/Abstracts/Store.php`: `get_url :545`, `get_phone :555`, `get_email :565`, `get_opening_hours :575`, `get_opening_hours_notes :585`, `get_personal_notes :595`, `get_policies_and_conditions :605`, `get_footer_imprint :615`, `get_timezone :229`). If any body differs, keep that method and report it.

## Rulings to preserve verbatim
- `( new Store( $id ) ) instanceof \WCPOS\WooCommercePOS\Interfaces\StoreInterface` stays true through the parent.
- `get_opening_hours()` returns the decoded legacy JSON array. Pinned by `tests/includes/Abstracts/Test_Store.php::test_get_opening_hours_decodes_legacy_json_meta` (`:285`).
- `get_opening_hours_notes()` reads `_opening_hours_notes`. Pinned by `Test_Store::test_get_opening_hours_notes` (`:277`).
- The store REST payload fields: `includes/API/V1/Stores.php:526-529` reads `get_opening_hours_notes`, `get_policies_and_conditions` and `get_footer_imprint`. Pinned by `tests/includes/API/Test_Stores.php` and `tests/includes/API/Test_Default_Store.php`.

## Tests
Add `test_inherited_contact_getters_read_store_meta()` to `tests/includes/Abstracts/Test_Store.php`, using the `$this->store_id` fixture from `setUp()`. Write it before deleting anything and confirm it passes against the unmodified class.
- Arrange: `update_post_meta( $this->store_id, … )` for:
  - `_url` = `'https://example.test'`
  - `_phone` = `'+1 555 0100'`
  - `_email` = `'shop@example.test'`
  - `_personal_notes` = `'Ask for Sam'`
  - `_policies_conditions` = `'No refunds after 30 days'`
  - `_footer_imprint` = `'Example GmbH'`
  - `_timezone` = `'Europe/Berlin'`

  Then `$store = new Store( $this->store_id );`.
- Assert: `assertSame( expected, actual )` for `get_url()`, `get_phone()`, `get_email()`, `get_personal_notes()`, `get_policies_and_conditions()`, `get_footer_imprint()` and `get_timezone()`.
- It must pass again after the deletion. If an assertion cannot pass against the unmodified class (for example, `load_data()` does not map that meta), drop that one assertion and report it. Do not change production code to make it pass.

Delete no tests.

## Budget
- Non-test ceiling: **0 lines added**; about 89 deleted, plus the `implements` edit.
- Total-diff ceiling including the test and lint: **140 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
```
php -l /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/includes/Abstracts/Store.php
php -l /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/tests/includes/Abstracts/Test_Store.php
cd /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Abstracts/Store.php tests/includes/Abstracts/Test_Store.php
```
PHPUnit runs afterwards in Pro wp-env, one file per call:
- `tests/includes/Abstracts/Test_Store.php`
- `tests/includes/Abstracts/Test_Store_Meta_Seam.php`
- `tests/includes/API/Test_Stores.php`
- `tests/includes/API/Test_Default_Store.php`
- `tests/includes/Test_Store_Pricing_V2.php`

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
