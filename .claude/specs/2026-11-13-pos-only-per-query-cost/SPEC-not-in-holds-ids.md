# Spec: append the POS Only clause when post__not_in no longer holds every POS Only id (#2662, review lap 2)

## Goal

`exclude_pos_only_products_from_where()` in `includes/Products.php` skips its own clause whenever the WHERE contains any ` AND wp_posts.ID NOT IN (`, without checking that the list holds the POS Only ids. Two shapes leak POS Only products that v2.0.2 hides:
- **(a)** A later `pre_get_posts` callback at `PHP_INT_MAX`, added after ours, replaces `post__not_in` with its own list. Core then writes a `NOT IN` without our ids, and we skip ours.
- **(b)** Another plugin's `posts_where` filter adds its own ` AND wp_posts.ID NOT IN (…)`, and `post__not_in` is cleared after our `pre_get_posts`. Core writes no `NOT IN`, the foreign one makes us skip ours.

Fix: also append the clause when the query vars no longer hold every POS Only id.

## Stakes

Core-plugin storefront visibility: a leak shows POS Only products to shoppers. A normal storefront query must still carry the list exactly once (the existing `writes_one_not_in` tests pin that).

## In scope (the only files you may edit)

- `includes/Products.php`
- `tests/includes/Test_Products_Query_Exclusion.php`
- `tests/includes/Test_Products_Direct.php`

## Out of scope

Everything else. No version, no changelog, no docs, no other refactor.

## The change

### `includes/Products.php`, `exclude_pos_only_products_from_where()`

Replace the body's `if` so it reads exactly like this (the reviewer tested this shape):

```php
if ( $this->should_hide_pos_only_products( $query ) ) {
	$exclusion = $this->get_pos_only_excluded_ids( $query );
	if ( $query->get( 'p' ) || ! empty( $query->get( 'post__in' ) )
		|| array_diff( $exclusion['ids'], array_map( 'absint', (array) $query->get( 'post__not_in' ) ) )
		|| false === strpos( $where, " AND {$wpdb->posts}.ID NOT IN (" ) ) {
		$where .= $exclusion['sql'];
	}
}
```

Format it to pass the repo's phpcs (WordPress standard: a multi-line condition is fine as long as phpcs is clean). Update the method docblock in one sentence: the clause is also written when `post__not_in` no longer holds every POS Only id (a later callback replaced or cleared it).

### `tests/includes/Test_Products_Query_Exclusion.php`: two new tests, red first

Model both on `test_late_posts_where_callback_cannot_strip_exclusion()` (line 225): `enable_pos_only_products()`, two products from `ProductHelper::create_simple_product()`, `set_pos_only_ids( array( $pos_only ) )`, `new Products()`, then a `WP_Query( $this->product_query_args() )`, and assert `array( $visible ) === array_map( 'intval', $query->posts )` with `assertSame`.

1. `test_late_pre_get_posts_callback_replacing_post_not_in_excludes_pos_only_ids`. Case (a).
   - After `new Products()`, add a `pre_get_posts` action at `PHP_INT_MAX` that calls `$query->set( 'post__not_in', array( 999999 ) )`.
   - It must be added after `new Products()`, so it runs after ours at the same priority.
2. `test_foreign_not_in_clause_with_cleared_post_not_in_excludes_pos_only_ids`. Case (b).
   - After `new Products()`, add a `pre_get_posts` action at `PHP_INT_MAX` that calls `$query->set( 'post__not_in', array() )`.
   - Add a `posts_where` filter at priority 10 that appends `" AND {$wpdb->posts}.ID NOT IN (999999)"` (use `global $wpdb` inside the closure).

Each test gets a one-line docblock and the `@covers \WCPOS\WooCommercePOS\Products::exclude_pos_only_products_from_where` tag, like its neighbours.

**Red first:**
- Write both tests.
- Run them against the UNCHANGED `Products.php` and keep the failing output.
- Then apply the change and run them again.

Put both outputs, trimmed to the failure lines, in your report.

### `tests/includes/Test_Products_Direct.php`: optional tidy

Delete the three leftover `$query->set( 'suppress_filters', true );` lines, at lines 139, 191 and 248. Do this only if the Products filter below still passes without them. If any test fails without its line, keep all three and say so.

## Do not

- Change anything else in `Products.php`: no priority, the memo or `hide_pos_only_products()`.
- Add helpers, refactors or other tests.
- Commit or push.

## Budget

- Under 60 changed lines in total, across the three files.
- If you are about to exceed it, stop and report.

## Acceptance (run each in the foreground, Bash timeout 700000 ms, from the worktree root)

wp-env is already running from this worktree. Do not start or stop it.

```sh
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --filter 'Test_Products(_Direct|_Query_Exclusion)?::'
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs includes/Products.php tests/includes/Test_Products_Query_Exclusion.php
```

Both must exit 0. Do not use local composer or phpunit.

## Limits

- Wall clock: three hours; at the limit, stop and report partial state.
- One test command at a time, in the foreground. Never `run_in_background`.
- Never leave a file half-edited when you stop.

## Report

Report these:
- the red output;
- the green output;
- phpcs exit code;
- `git diff --stat`;
- whether the Direct tidy was kept or dropped.
