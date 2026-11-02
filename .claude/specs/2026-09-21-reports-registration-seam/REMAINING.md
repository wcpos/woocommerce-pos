# Remaining work — completion pass

A previous run crashed partway (a Codex-side crash, not your fault and not a problem with the spec).
`SPEC.md` beside this file is still the authority and is unchanged. This file only says what is
already done and what is left, so you do not redo finished work.

## Already on disk — do not rewrite these

- `includes/Services/Reports_Registry.php` — **complete and reviewed. Leave it alone.** It holds
  `HISTORY_DAYS`, `DEFAULT_CAPABILITY`, `all()`, the `woocommerce_pos_reports` filter with its
  hookdoc, registration validation/normalisation, and the `sales` / `cash_movements` built-in
  declarations with their contract column sets.
- `tests/includes/API/V2/Test_Reports_Controller.php`
- `tests/includes/Services/Test_Reports_Registry.php`
- `tests/includes/Services/Test_Report_Scope_Resolver.php`

The three test files were written **before** the classes they exercise. Read them first: they are
your specification of the method signatures you must now provide. Where a test and `SPEC.md`
disagree, `SPEC.md` wins — fix the test.

## Still to do

1. **`includes/Services/Report_Scope_Resolver.php`** — Deliverable 5. Note the corrected identifier
   types and the closed-session rule.
2. **`includes/Services/Report_Scope_Gate.php`** — Deliverable 4.
3. **`includes/API/V2/Reports_Controller.php`** — Deliverables 2 and 3: the registry route, the
   per-key document route, the ordered pipeline and the failure shape.
4. **`includes/API/Controller_Registry.php`** — add `'reports' => V2\Reports_Controller::class` to
   `$natives` in `v2_map()`. One line.
5. **`includes/Services/Receipt_Data_Builder.php`** — apply `woocommerce_pos_receipt_data` at the end
   of `build_closure_document()` with an **unsaved `WC_Order` (id 0), never `null`**, mode
   `closure`/`xreport`; re-apply the fiscal identity after the filter as the live path does at
   `:681-700`; update the hookdoc at `:656-678` to document the two new modes.
6. **`includes/Services/Receipt_Data_Schema.php`** — merge registered `extras` fragments into
   `get_report_field_tree()` under each report's title. Deliverable 7.
7. **Finish the tests** so they cover the numbered list in `SPEC.md` § Tests, including the two that
   matter most: the callable receives a resolved scope and never a `WP_REST_Request`, and **the
   checks run before the callable** (a refusal must leave the callable un-run).

## Budget for this pass

About 550-700 net non-test lines remain, plus test completion. The same stop rule applies: if you are
about to exceed it, stop and report why.

## Reminders

- No Docker, no network — do not attempt the test suite, `composer install`, or `wp-env`. `php -l`
  each file you write.
- Do not `git commit`.
- Do not write server-side computation of `sales` or `cash_movements`. They are device-computed
  declarations; the server never computes a built-in.
