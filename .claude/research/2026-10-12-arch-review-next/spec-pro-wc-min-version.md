# Spec — Pro fix PR: Pro must not lower Free's WooCommerce minimum (D1)

Tree: Pro `/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next` (`05f06c4`, lane `next`; Pro `composer.json:25` vendors Free `dev-next`).

## Goal
Pro defines Free's requirement constants before Free's classes load, because Pro never runs Free's main file. It still says WooCommerce 5.3, while Free `next` requires 9.0 (`woocommerce-pos.php:18, 67-68`, raised in #1994 because the 2.0 till reads `GET /wc/v3/refunds`). Bring Pro to 9.0. Add a guard test so the mirrored requirement constants can never drift from the bundled Free bootstrap again.

## Stakes
Medium. With the drift, a Pro merchant on WooCommerce 5.3–8.x passes `Activator::woocommerce_check()` (Free `includes/Activator.php:369-370`) and gets a till that fails at refund reads, instead of the admin notice. A failure of this PR costs a red test. Accepted risk: none needed.

## Out of scope (do NOT add)
- No new constants, version gates, runtime checks or admin notices.
- No change to Pro's `PHP_MIN_VERSION` or `MIN_PRO_VERSION` values: they already match Free.
- Do not touch `.github/scripts/test-sync-tested-up-to.sh`. Its `5.3` is a self-contained fixture asserting the sync script leaves the header alone.
- No env vars and no plan files.

## Exact changes
1. `woocommerce-pos-pro.php:17`: ` * WC requires at least: 5.3` → ` * WC requires at least: 9.0`.
2. `woocommerce-pos-pro.php:134`: `\define( 'WCPOS\WooCommercePOS\WC_MIN_VERSION', '5.3' );` → `'9.0'`.
3. `phpstan-constants.php:52`: `'5.3'` → `'9.0'`.
4. Add a test to `tests/includes/Test_Bootstrap_Parity.php` (below).

If Pro's `readme.txt` or `README.md` states a WooCommerce minimum (grep `WooCommerce 5.3` / `requires at least`), update it to 9.0 in the same way. At `05f06c4`, a grep found none.

## Rulings to preserve verbatim
- Pro defines Free's constants only when they are undefined (the `if ( ! \defined( … ) )` guards at `woocommerce-pos-pro.php:110-141`). Keep the guard shape.
- Every require in Free's bootstrap is loaded by Pro. Pinned by `Test_Bootstrap_Parity::test_free_bootstrap_require_targets_are_loaded_by_pro`. Untouched.

## Tests (write first, watch it fail)
Add `test_free_requirement_constants_match_bundled_free_bootstrap()` to `tests/includes/Test_Bootstrap_Parity.php`. Follow that file's Arrange/Act/Assert comments and `assertSame( expected, actual )` order.
- Arrange:
  - Read `FREE_PLUGIN_PATH . 'woocommerce-pos.php'`, already imported at `:12`.
  - With `preg_match`, extract the literal value of each of `WC_MIN_VERSION`, `PHP_MIN_VERSION` and `MIN_PRO_VERSION` from Free's `\define( __NAMESPACE__ . '\NAME', 'value' )` lines.
  - Assert each match succeeded, with a message telling the reader to update the test if Free's define style changed.
- Act: read the live constants `\WCPOS\WooCommercePOS\WC_MIN_VERSION`, `PHP_MIN_VERSION` and `MIN_PRO_VERSION`.
- Assert:
  - `assertSame( $free_value, $live_value, "Pro's bootstrap must mirror free's {NAME}" )` for each of the three.
  - `get_file_data( FREE_PLUGIN_PATH . 'woocommerce-pos.php', array( 'wc' => 'WC requires at least' ) )['wc']` equals the same field read from Pro's own main file, `\WCPOS\WooCommercePOSPro\PLUGIN_PATH . 'woocommerce-pos-pro.php'`.

Before the fix the test fails on `WC_MIN_VERSION` (`'9.0'` vs `'5.3'`); after the fix it passes. Delete no tests.

## Budget
- Non-test ceiling: **3 lines changed, 0 added**.
- Total-diff ceiling including the test and lint: **60 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
```
php -l /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/woocommerce-pos-pro.php
php -l /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/phpstan-constants.php
php -l /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/tests/includes/Test_Bootstrap_Parity.php
cd /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist woocommerce-pos-pro.php tests/includes/Test_Bootstrap_Parity.php
```
PHPUnit runs afterwards in Pro wp-env, mounting Free `next` at the vendor path: `tests/includes/Test_Bootstrap_Parity.php`, `tests/includes/Test_Activator.php`.

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
