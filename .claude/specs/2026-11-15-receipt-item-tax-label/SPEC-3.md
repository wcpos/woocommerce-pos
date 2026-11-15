# Codex job spec — PHPStan: narrow the stored tax items to WC_Order_Item_Tax

## Goal

CI's PHPStan job on PR #2675 fails with:

```
includes/Services/Receipt_Data_Builder.php:1710 Method WCPOS\WooCommercePOS\Services\Receipt_Data_Builder::get_charged_tax_items() should return array<int, WC_Order_Item_Tax> but returns array<int, WC_Order_Item>.
```

`get_items( 'tax' )` is typed as returning `WC_Order_Item[]`. Make `get_charged_tax_items()` keep only `\WC_Order_Item_Tax` items, so the declared return type holds.

## Stakes

Static analysis only; no behaviour change (WooCommerce returns only `WC_Order_Item_Tax` for `'tax'`).

## In scope (the only files you may edit)

- `includes/Services/Receipt_Data_Builder.php`

## Out of scope (do not edit)

- Every other file, including the tests, `phpstan.neon` and any PHPStan baseline

## Context

The inner loop of `get_charged_tax_items()` (around line 1705-1707) is:

```php
foreach ( $source instanceof WC_Abstract_Order ? $source->get_items( 'tax' ) : array() as $tax_item ) {
	$charged[ (int) $tax_item->get_rate_id() ] = $tax_item;
}
```

## Interfaces and constraints

- Inside that inner loop, assign only when `$tax_item instanceof \WC_Order_Item_Tax`, for example by wrapping the assignment in `if ( $tax_item instanceof \WC_Order_Item_Tax ) { … }`.
- Keep the docblock's `@return array<int, \WC_Order_Item_Tax>` unchanged.
- Change nothing else.

## Do not

- Change the `@return` type, add `@phpstan-ignore`, or edit the baseline or config
- Touch any other method
- Create plan, notes or scratch files
- Commit or push
- Start, stop or destroy wp-env, or run local composer, phpunit or phpstan

## Pre-authorised actions

- Edit the in-scope file
- Run the acceptance commands below (wp-env is already running from this worktree)

## Budget

- Max non-test lines changed: 4
- Max total lines changed: 4

If you are about to exceed either, STOP and report why instead of continuing.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpstan analyse --memory-limit=2G --no-progress includes/Services/Receipt_Data_Builder.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/Receipt_Data_Builder.php
```

- PHPStan reports no errors for the file; PHPUnit runs 100 tests, all passing; phpcs reports no errors.

## Environment

- Network: yes, to reach the local wp-env containers (Docker)
- Writable outside the worktree: `~/.wp-env/wp-env-order-revision-allow-list-3526dcd1`
- wp-env is running for this worktree; do not start or stop it

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
