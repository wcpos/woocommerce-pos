# Receipt Schema Source of Truth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the receipt schema v1 contract canonical, expose `store.id`, remove scalar `store.tax_id` / `customer.tax_id`, and keep Template Editor / generated schema / Template Studio consumers synchronized by tests.

**Architecture:** PHP `Receipt_Data_Schema::get_field_tree()` remains the canonical in-plugin source for the v1 receipt contract, and `get_json_schema()` plus generated `@wcpos/receipt-schema` artifacts derive from it. WP Admin Template Editor renders the canonical field tree with dotted sections grouped under their parent sections, so `store.tax_ids` is discoverable under Store without duplicating fields. Template Studio/printer fixtures are updated to the same v1 policy and locked by tests.

**Tech Stack:** PHP/WordPress, PHPUnit/wp-env, pnpm workspaces, TypeScript, React, Vitest, generated JSON schema artifacts.

---

### Task 1: Lock receipt schema v1 policy with failing tests

**Files:**
- Modify: `tests/includes/Services/Test_Receipt_Data_Schema.php`
- Create: `packages/template-editor/src/components/field-picker.test.tsx`

- [x] **Step 1: Add PHP tests asserting v1 policy**
  - Assert `store.fields.id` exists and is numeric.
  - Assert `store.fields.tax_id` is absent.
  - Assert `customer.fields.tax_id` is absent.
  - Assert JSON schema has `store.properties.id`.
  - Assert JSON schema omits `store.properties.tax_id` and `customer.properties.tax_id`.
  - Assert preview data includes `store.id` and structured tax IDs, but omits scalar tax ID shortcuts.

- [x] **Step 2: Add Template Editor UI test**
  - Render `FieldPicker` with `store` and `store.tax_ids` sections.
  - Assert `Store Tax IDs` is hidden until Store is expanded.
  - Expand Store and assert `Store Tax IDs`, `Type`, and `Value` become discoverable below Store.

- [x] **Step 3: Verify RED**
  - `pnpm --filter @wcpos/template-editor exec vitest run src/components/field-picker.test.tsx` fails because dotted sections are currently rendered flat.
  - Static PHP harness fails because `store.tax_id` is still present.

### Task 2: Update canonical PHP receipt contract

**Files:**
- Modify: `includes/Services/Receipt_Data_Schema.php`
- Modify: `includes/Services/Receipt_Data_Builder.php`
- Modify: `includes/Services/Preview_Receipt_Builder.php`
- Optionally modify comments only: `includes/Abstracts/Store.php`, `includes/Interfaces/StoreInterface.php`

- [ ] **Step 1: Add `store.id` to schema and fixtures**
  - Add numeric `id` field under `store` in `get_field_tree()`.
  - Add `id` to `get_mock_receipt_data()` store fixture.
  - Populate `store.id` from the POS store object in real and preview builders.

- [ ] **Step 2: Remove scalar tax ID shortcuts from receipt payload**
  - Remove `tax_id` field from `store` and `customer` field tree sections.
  - Stop emitting `store.tax_id` from `Receipt_Data_Builder` and `Preview_Receipt_Builder`.
  - Stop emitting `customer.tax_id` from `Receipt_Data_Builder` and `Preview_Receipt_Builder`.
  - Leave internal `get_tax_id()` helpers alone if other non-template code/tests still use them; update comments to say they are internal primary-value helpers, not v1 receipt schema fields.

- [ ] **Step 3: Run targeted checks**
  - Static PHP harness for field tree policy.
  - `php -l` on changed PHP files.
  - wp-env PHPUnit if Docker is available; otherwise document Docker daemon failure exactly.

### Task 3: Make WP Admin field picker group dotted sections under parents

**Files:**
- Modify: `packages/template-editor/src/types.ts`
- Modify: `packages/template-editor/src/components/field-picker.tsx`
- Modify: `packages/template-editor/src/components/field-tree-node.tsx`
- Test: `packages/template-editor/src/components/field-picker.test.tsx`

- [ ] **Step 1: Extend section props with child sections**
  - Add `children?: Array<{ sectionKey: string; section: SectionInfo }>` to the React component layer.
  - Keep public `FieldSchema` compatible with the PHP payload.

- [ ] **Step 2: Group dotted sections**
  - Build top-level entries from schema keys without dots.
  - Attach one-level dotted sections like `store.tax_ids` and `store.address` as children of `store`.
  - Leave orphan dotted sections top-level as a safety fallback.

- [ ] **Step 3: Render child sections only when parent is expanded or search is active**
  - Child sections keep their full section key, so array blocks insert `{{#store.tax_ids}}...{{/store.tax_ids}}`.
  - Array child fields still insert `{{type}}`, `{{value}}`, etc. inside the block.

- [ ] **Step 4: Verify GREEN**
  - `pnpm --filter @wcpos/template-editor exec vitest run src/components/field-picker.test.tsx` passes.

### Task 4: Regenerate generated receipt schema artifacts

**Files:**
- Modify: `packages/receipt-schema/src/receipt-data.schema.json`
- Modify: `packages/receipt-schema/src/receipt-data.types.ts` if generated output changes

- [ ] **Step 1: Run generator**
  - `pnpm --filter @wcpos/receipt-schema build`

- [ ] **Step 2: Verify artifacts current**
  - `pnpm --filter @wcpos/receipt-schema check`

### Task 5: Update Template Studio / printer consumers in monorepo PR

**Files:**
- Modify relevant Template Studio randomizer/schema tests in `/Users/kilbot/Projects/monorepo-v2/.worktrees/fix-template-field-sync-230238`
- Modify printer schema if it currently exposes scalar tax ID shortcuts.

- [ ] **Step 1: Audit consumer fields**
  - Search for `store.tax_id`, `customer.tax_id`, and store object fixtures.
  - Confirm `store.id` is present and tax IDs are structured arrays only.

- [ ] **Step 2: Add/update tests first**
  - Template Studio generated sample data includes `store.id`.
  - Template Studio generated sample data omits `store.tax_id` and `customer.tax_id`.
  - Printer schema omits scalar tax ID shortcuts and includes structured arrays.

- [ ] **Step 3: Implement minimal consumer changes**
  - Remove scalar tax ID shortcuts.
  - Add `store.id`.

- [ ] **Step 4: Validate monorepo packages**
  - Run targeted Vitest for changed tests.
  - Run package lint/build commands used by the existing PR.

### Task 6: Final validation and PR update

**Files:**
- Existing PR #925 branch in `woocommerce-pos`
- Existing monorepo PR #411 branch in `monorepo`

- [ ] **Step 1: Run changed package validations**
  - PHP syntax.
  - receipt-schema check.
  - template-editor tests/build/lint where available.
  - Template Studio/printer tests/build/lint where changed.

- [ ] **Step 2: Commit and push**
  - Commit WCPOS repo changes to `fix/template-field-sync`.
  - Commit monorepo changes to `fix/template-field-sync`.
  - Push both branches after pre-push checks.

- [ ] **Step 3: Comment on PRs**
  - Explain v1 policy: no scalar `store.tax_id`/`customer.tax_id`, yes `store.id`, structured tax IDs only.
  - Include validation evidence and any blocked wp-env/Docker checks.
