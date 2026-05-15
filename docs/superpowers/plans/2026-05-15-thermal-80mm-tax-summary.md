# 80mm Detailed Thermal Receipt Tax Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development (recommended) or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the truncating, rate-id-leaking tax summary on the 80mm detailed thermal receipt with a compact one-line-per-rate layout, and remove the same rate-id leak from the 58mm thermal and HTML detailed-receipt siblings.

**Architecture:** Pure gallery-template edits — no PHP/JS source or receipt-data-contract changes. The 80mm detailed thermal template's tax summary becomes one `<row>` per rate (rate label + percent, the net base inline after `@`, right-aligned tax amount). The meaningless WooCommerce tax-rate database id (`tax_summary[].code`) is dropped from the 80mm template as part of the new markup, and from the 58mm thermal and HTML detailed-receipt templates.

**Tech Stack:** Mustache logic-less receipt templates (`.xml` thermal, `.html`); Vitest + `@wcpos/thermal-utils` render tests; PHPUnit via Docker/wp-env; PHPCS for PHP lint; ESLint for the TS test.

**Prerequisite:** Run this in a git worktree branched off `main` (the approved spec `docs/superpowers/specs/2026-05-15-thermal-80mm-tax-summary-design.md` and this plan are already committed to `main`). Do not edit template/test files in the main working tree.

**Spec:** `docs/superpowers/specs/2026-05-15-thermal-80mm-tax-summary-design.md`

---

## File Structure

| File | Action | Responsibility |
| --- | --- | --- |
| `packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts` | Create | Render test for the 80mm detailed template's tax summary — compact line per rate, no rate-id, heading branches by display mode |
| `templates/gallery/thermal-detailed-80mm.xml` | Modify | Compact one-line-per-rate tax summary; corrected header comment |
| `templates/gallery/thermal-detailed-58mm.xml` | Modify | Drop the rate-id from the rate line; corrected header comment (58mm stays stacked) |
| `templates/gallery/detailed-receipt.html` | Modify | Drop the rate-id from the tax summary table cell |
| `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php` | Modify | New content-assertion test: tax summaries must not print the internal rate-id |

Background facts (verified against the files this session):

- The thermal renderer (`packages/thermal-utils/src/thermal-renderer.ts`, `renderCol` ~line 399) renders every `<col>` as `flex: 0 0 <width>ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap` — fixed-width columns **truncate**, they do not wrap. This is why the current `width="18"` column clips `Taxable incl. …`.
- `tax_summary[].code` is the WooCommerce tax-rate **database primary key** (`includes/Services/Receipt_Data_Builder.php` ~line 446, `'code' => $rate_id`). `label` + `rate` already identify the line; the id is noise. `code` is also used in `discounts[]` as the **coupon code** (meaningful) — those `{{#code}}` uses are correct and must not be touched.
- `@` already appears in `thermal-detailed-80mm.xml` (line ~139, the line-item discount rows) as a price-reference symbol — reusing it needs no new translatable string.

---

### Task 1: Redesign the 80mm tax summary layout

**Files:**
- Create: `packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts`
- Modify: `templates/gallery/thermal-detailed-80mm.xml` (tax summary block ~lines 183-192; header comment ~lines 26-27)

- [ ] **Step 1: Write the failing render test**

Create `packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts` with exactly this content:

```ts
import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

import { renderThermalPreview } from '@wcpos/thermal-utils';

const galleryDir = path.resolve(__dirname, '../../../../templates/gallery');
const xml = fs.readFileSync(path.join(galleryDir, 'thermal-detailed-80mm.xml'), 'utf8');

// tax_summary[].code is the WooCommerce tax-rate database id. The receipt must
// never print it, so the fixture uses a distinctive sentinel that cannot
// collide with prices, dates, or the order number elsewhere on the receipt.
const RATE_ID_SENTINEL = 'TAXRATEID987654';

const sampleData = {
	store: { name: 'My Store', address_lines: ['123 Main St'] },
	cashier: { name: 'Admin' },
	order: { number: '1234', created: { datetime: '2026-05-08 14:30' } },
	tax: { display_excl: true, display_incl: false },
	lines: [
		{
			name: 'T-Shirt',
			qty: 2,
			unit_price_excl_display: '$20.00',
			line_total_excl_display: '$40.00',
		},
	],
	fees: [],
	shipping: [],
	discounts: [],
	has_tax_summary: true,
	tax_summary: [
		{
			code: RATE_ID_SENTINEL,
			label: 'US',
			rate: 10,
			tax_amount_display: '$4.52',
			taxable_amount_excl_display: '$45.22',
			taxable_amount_incl_display: '$49.74',
		},
		{
			code: RATE_ID_SENTINEL,
			label: 'City',
			rate: 2,
			tax_amount_display: '$0.90',
			taxable_amount_excl_display: '$45.22',
			taxable_amount_incl_display: '$46.12',
		},
	],
	totals: {
		subtotal_excl_display: '$40.00',
		total_excl_display: '$40.00',
		tax_total: 5.42,
		tax_total_display: '$5.42',
		total_incl_display: '$50.64',
	},
	refunds: [],
	payments: [],
	// taxable_excl_short / taxable_incl_short are intentionally provided so the
	// "old layout removed" assertions below are meaningful: the values are in
	// the data, so they would render if the template still referenced them.
	i18n: {
		tax_summary: 'Tax Summary',
		included_tax: 'Tax included',
		taxable_excl_short: 'Taxable excl.',
		taxable_incl_short: 'Taxable incl.',
		total: 'Total',
	},
};

describe('thermal-detailed-80mm tax summary', () => {
	it('renders one compact line per tax rate without the internal rate id', () => {
		const html = renderThermalPreview(xml, sampleData);

		// Heading uses the additive wording in tax-exclusive display mode.
		expect(html).toContain('Tax Summary');

		// Each rate renders its label, percent, inline net base and tax amount.
		expect(html).toContain('US (10%)');
		expect(html).toContain('City (2%)');
		expect(html).toContain('@ $45.22');
		expect(html).toContain('$4.52');
		expect(html).toContain('$0.90');

		// The WooCommerce tax-rate database id must never reach the receipt.
		expect(html).not.toContain(RATE_ID_SENTINEL);

		// The old truncating second row (stacked taxable excl./incl. labels) is gone.
		expect(html).not.toContain('Taxable excl.');
		expect(html).not.toContain('Taxable incl.');
	});

	it('omits the inline net base when a rate has no taxable amount', () => {
		const html = renderThermalPreview(xml, {
			...sampleData,
			tax_summary: [
				{
					code: RATE_ID_SENTINEL,
					label: 'US',
					rate: 10,
					tax_amount_display: '$4.52',
				},
			],
		});

		expect(html).toContain('US (10%)');
		expect(html).toContain('$4.52');
		// With no taxable_amount_excl_display, the "@ <base>" fragment is guarded out.
		expect(html).not.toContain('US (10%) @');
	});

	it('uses included-tax wording when the receipt display mode is tax-inclusive', () => {
		const html = renderThermalPreview(xml, {
			...sampleData,
			tax: { display_excl: false, display_incl: true },
		});

		expect(html).toContain('Tax included');
		expect(html).not.toContain('Tax Summary');
	});
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @wcpos/template-gallery test thermal-detailed-80mm-render`

Expected: FAIL. The first test fails on `expect(html).toContain('@ $45.22')` (the current template has no `@` in the tax summary), on `expect(html).not.toContain(RATE_ID_SENTINEL)` (the current template renders `{{code}}`), and on `expect(html).not.toContain('Taxable excl.')` (the current template still renders the stacked `{{i18n.taxable_excl_short}}` row).

- [ ] **Step 3: Replace the tax summary block in the 80mm template**

In `templates/gallery/thermal-detailed-80mm.xml`, replace this block (the `{{#tax_summary}}` … `{{/tax_summary}}` rows, ~lines 183-192):

```xml
  {{#tax_summary}}
  <row>
    <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}{{#code}} {{code}}{{/code}}</col>
    <col width="14" align="right">{{tax_amount_display}}</col>
  </row>
  <row>
    <col width="*">  {{i18n.taxable_excl_short}} {{taxable_amount_excl_display}}</col>
    <col width="18" align="right">{{i18n.taxable_incl_short}} {{taxable_amount_incl_display}}</col>
  </row>
  {{/tax_summary}}
```

with:

```xml
  {{#tax_summary}}
  <row>
    <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}{{#taxable_amount_excl_display}} @ {{taxable_amount_excl_display}}{{/taxable_amount_excl_display}}</col>
    <col width="14" align="right">{{tax_amount_display}}</col>
  </row>
  {{/tax_summary}}
```

Leave the `{{#has_tax_summary}}` heading block immediately above it untouched.

- [ ] **Step 4: Update the 80mm header comment**

In the same file, in the top comment block's section list, replace these two lines (~lines 26-27):

```text
  - Tax summary — per-rate breakdown with net (taxable excl.) and
    gross (taxable incl.) under each rate.
```

with:

```text
  - Tax summary — one line per tax rate: rate label and percent, the
    net amount it was applied to (after "@"), and the tax charged.
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `pnpm --filter @wcpos/template-gallery test thermal-detailed-80mm-render`

Expected: PASS — all three tests in `thermal-detailed-80mm tax summary` pass.

- [ ] **Step 6: Lint the new test file**

Run: `pnpm --filter @wcpos/template-gallery lint`

Expected: PASS — no ESLint errors. Fix any reported in `thermal-detailed-80mm-render.test.ts` before committing.

- [ ] **Step 7: Commit**

```bash
git add packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts templates/gallery/thermal-detailed-80mm.xml
git commit -m "$(cat <<'EOF'
fix(receipt-templates): redesign 80mm thermal tax summary layout

The side-by-side taxable-amount columns overflowed the fixed 80mm
column widths and truncated with an ellipsis. Replace them with one
compact line per rate — label, percent, the net base inline after
"@", and the tax amount — which also drops the meaningless internal
tax-rate id.
EOF
)"
```

---

### Task 2: Remove the internal rate-id from the sibling templates

**Files:**
- Modify: `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php` (new test method, inserted before `test_detailed_receipt_hides_tax_row_when_order_has_no_tax`)
- Modify: `templates/gallery/thermal-detailed-58mm.xml` (rate line ~line 181; header comment ~lines 22-24)
- Modify: `templates/gallery/detailed-receipt.html` (tax summary table cell ~line 205)

- [ ] **Step 1: Add the failing PHP content-assertion test**

In `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php`, find this existing method header:

```php
	/**
	 * Test detailed-receipt.html omits the Total Tax row when the order has no tax.
	 */
	public function test_detailed_receipt_hides_tax_row_when_order_has_no_tax(): void {
```

and insert the following new method immediately **before** it (so the new method sits between `test_gallery_templates_branch_tax_wording_by_display_mode` and `test_detailed_receipt_hides_tax_row_when_order_has_no_tax`):

```php
	/**
	 * Test the tax summary never prints the internal WooCommerce tax-rate id.
	 *
	 * tax_summary[].code carries the WooCommerce tax-rate database id, which is
	 * meaningless on a customer receipt. Templates must render the label and
	 * rate, never the id.
	 */
	public function test_tax_summary_does_not_print_internal_rate_id(): void {
		// Arrange / Act / Assert — the detailed thermal templates put the rate id
		// directly after the rate via the {{/rate}}{{#code}} adjacency.
		foreach ( array( 'thermal-detailed-58mm.xml', 'thermal-detailed-80mm.xml' ) as $filename ) {
			$content = $this->read_gallery_template( $filename );
			$this->assertStringNotContainsString(
				'{{/rate}}{{#code}}',
				$content,
				sprintf( '%s tax summary must not print the internal tax-rate id after the rate.', $filename )
			);
		}

		// detailed-receipt.html prints it beside the label as "{{#code}} · {{code}}".
		$detailed_html = $this->read_gallery_template( 'detailed-receipt.html' );
		$this->assertStringNotContainsString(
			'{{#code}} · ',
			$detailed_html,
			'detailed-receipt.html tax summary must not print the internal tax-rate id beside the label.'
		);
	}

```

- [ ] **Step 2: Run the test to verify it fails**

Ensure the test environment is running, then run the new test:

```bash
pnpm exec wp-env start
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Receipt_Template_Tax_Display.php \
  --filter test_tax_summary_does_not_print_internal_rate_id
```

Expected: FAIL. `thermal-detailed-58mm.xml` still contains `{{/rate}}{{#code}}`, and `detailed-receipt.html` still contains `{{#code}} ·`. (`thermal-detailed-80mm.xml` already passes its assertion — Task 1 removed the fragment there.)

- [ ] **Step 3: Remove the rate-id from the 58mm template and fix its header comment**

In `templates/gallery/thermal-detailed-58mm.xml`, on the tax summary rate line (~line 181), replace:

```xml
    <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}{{#code}} {{code}}{{/code}}</col>
```

with:

```xml
    <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}</col>
```

Then, in the same file's top comment block, replace these three lines (~lines 22-24):

```text
  - Tax summary — per-rate breakdown with net (taxable excl.) and
    gross (taxable incl.) stacked under each rate (the 80mm sibling
    shows them side-by-side; 32 columns is too narrow for that).
```

with:

```text
  - Tax summary — per-rate breakdown with net (taxable excl.) and
    gross (taxable incl.) stacked under each rate (the 80mm sibling
    shows the net base inline on the rate line; 32 columns keeps
    them stacked).
```

The 58mm stacked three-row layout is otherwise unchanged — it does not truncate on 32 columns.

- [ ] **Step 4: Remove the rate-id from detailed-receipt.html**

In `templates/gallery/detailed-receipt.html`, in the tax summary table cell (~line 205), replace the fragment:

```html
{{label}}{{#code}} · <span style="color: #6b7280;">{{code}}</span>{{/code}}
```

with:

```html
{{label}}
```

(Only that fragment changes; leave the surrounding `<td style="...">…</td>` markup intact.)

- [ ] **Step 5: Run the test to verify it passes**

```bash
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Receipt_Template_Tax_Display.php \
  --filter test_tax_summary_does_not_print_internal_rate_id
```

Expected: PASS — `test_tax_summary_does_not_print_internal_rate_id` passes.

- [ ] **Step 6: Lint the changed PHP file**

Run: `composer run lint -- tests/includes/Templates/Test_Receipt_Template_Tax_Display.php`

Expected: PASS — no PHPCS errors. Fix any reported (PHPCS is strict about docblocks, alignment, and tab indentation) before committing.

- [ ] **Step 7: Commit**

```bash
git add tests/includes/Templates/Test_Receipt_Template_Tax_Display.php templates/gallery/thermal-detailed-58mm.xml templates/gallery/detailed-receipt.html
git commit -m "$(cat <<'EOF'
fix(receipt-templates): stop printing the internal tax-rate id

tax_summary[].code is the WooCommerce tax-rate database id, not a
human-meaningful code. Remove it from the 58mm thermal and the HTML
detailed receipt tax summaries, matching the 80mm redesign.
EOF
)"
```

---

### Task 3: Verify the full affected suites

No code changes or commit in this task — it is a regression gate. If anything fails, return to the relevant task.

- [ ] **Step 1: Run the full template-gallery JS suite**

Run: `pnpm --filter @wcpos/template-gallery test`

Expected: PASS — all template-gallery tests pass, including the existing `thermal-detailed-58mm-render.test.ts` (it asserts `Taxable excl.` / `Taxable incl.`, which the 58mm stacked layout still renders) and `gallery-template-assets.test.ts` (confirms all gallery templates still parse).

- [ ] **Step 2: Run the full PHP receipt-template tax-display suite**

```bash
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Receipt_Template_Tax_Display.php
```

Expected: PASS — every test in the file passes. The existing `test_gallery_templates_branch_tax_wording_by_display_mode` still holds because the `{{#tax.display_excl}}` / `{{#tax.display_incl}}{{i18n.included_tax}}` heading branches are unchanged in all affected templates.

---

## On Completion

All work is committed in the worktree. Use the `/pr` skill to push the branch and open the pull request — do not run `gh pr create` manually. The PR description should summarise the two commits and link the spec (`docs/superpowers/specs/2026-05-15-thermal-80mm-tax-summary-design.md`). Note in the test plan that the JS suite ran locally and the PHP suite ran through wp-env.

---

## Self-Review

**Spec coverage:**

- Compact one-line-per-rate 80mm layout → Task 1, Step 3.
- Drop the rate-id from `thermal-detailed-80mm.xml` → Task 1, Step 3 (inherent in the new markup).
- Drop the rate-id from `thermal-detailed-58mm.xml` → Task 2, Step 3.
- Drop the rate-id from `detailed-receipt.html` → Task 2, Step 4.
- Update the 80mm header comment → Task 1, Step 4.
- Update the 58mm header comment → Task 2, Step 3.
- New `thermal-detailed-80mm-render.test.ts` → Task 1, Step 1.
- Extend `Test_Receipt_Template_Tax_Display.php` with the rate-id content assertion → Task 2, Step 1.
- Keep the tax-summary heading behaviour → preserved in Task 1 Step 3 (heading block untouched) and verified by Task 1's third test and Task 3 Step 2.
- No `Receipt_Data_Builder` / data-contract changes → no task touches PHP source; `code` stays in the data, templates just stop printing it.
- No new translatable strings → the new markup reuses `@`; no `{{i18n.*}}` key added.

**Placeholder scan:** No TBD/TODO/"handle edge cases" — every step has exact file paths, complete code, and exact commands with expected output.

**Type / name consistency:** `RATE_ID_SENTINEL` is defined once and reused across all three `it` blocks. `test_tax_summary_does_not_print_internal_rate_id` and the `read_gallery_template()` helper match the existing test file. The `{{/rate}}{{#code}}` and `{{#code}} ·` assertion strings match the exact fragments removed in Task 1 Step 3, Task 2 Step 3, and Task 2 Step 4.
