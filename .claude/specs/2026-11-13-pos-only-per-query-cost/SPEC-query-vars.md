# Spec — keep the POS Only exclusion in the query vars (#2662 review fix)

## Goal

#2662 made `Products::hide_pos_only_products()` return early for every filtered query (`includes/Products.php`, the `suppress_filters` early return). For those queries the POS Only ids now live only in the SQL that `posts_where` appends.

Search integrations answer a product query from its query vars through `posts_pre_query` (ElasticPress, Relevanssi, SearchWP, Algolia), and never run that SQL. On such a site a POS Only product or variation is listed on the storefront again. Variations have no catalog-visibility cover behind it.

The fix:
- `pre_get_posts` writes the POS Only ids into `post__not_in` on every storefront product query again. Core builds that `NOT IN` itself, with `absint`.
- `posts_where` appends our own clause only where core's `NOT IN` is not in the WHERE. That is when `p` or `post__in` is set (WordPress then ignores `post__not_in`), or when a later `posts_where` callback has stripped it.
- So a normal query still carries the list once.

## Stakes

Storefront visibility of POS Only products (High: a shopper must not see or buy them). The existing exclusion tests must all stay green.

## In scope (the only files you may edit)

- `includes/Products.php`: `hide_pos_only_products()`, `exclude_pos_only_products_from_where()` and their docblocks.
- `includes/Catalog_Visibility.php`: the class docblock sentence at lines 20–22 only.
- `tests/includes/Test_Products_Query_Exclusion.php`:
  - add two tests;
  - edit the class docblock line "not what the callbacks leave in the query vars".

## Out of scope (do not edit)

- `get_pos_only_excluded_ids()`
- the hook registrations and priorities
- `should_hide_pos_only_products()`
- every other method
- `Test_Products_Direct.php`
- the existing tests' bodies
- the bench and repro scripts
- changelog, readme, version, docs, CI

## Changes

### `hide_pos_only_products( \WP_Query $query )`

- Delete the `if ( ! $query->get( 'suppress_filters' ) ) { return; }` early return.
- Keep the `should_hide_pos_only_products()` and empty-ids returns as they are.
- Wrap the existing `post__in` trim block in `if ( $query->get( 'suppress_filters' ) ) { … }`. Only a suppressed query needs it: for a filtered query, `posts_where` covers `post__in`.
- Keep the `post__not_in` merge unconditional (after the trim block, as now).
- Docblock:
  - It writes the POS Only ids into `post__not_in` on every storefront product query, so code that answers from the query vars (`posts_pre_query` integrations such as ElasticPress, Relevanssi, SearchWP, Algolia) honours them, and WordPress builds the `NOT IN` itself.
  - A query that suppresses filters also has its `post__in` trimmed, because `posts_where` never runs for it.

### `exclude_pos_only_products_from_where( $where, $query )`

- Keep the `should_hide_pos_only_products()` gate.
- Inside it, append `$this->get_pos_only_excluded_ids( $query )['sql']` only when ANY of these holds:
  - `$query->get( 'p' )` is truthy;
  - `$query->get( 'post__in' )` is not empty;
  - `false === strpos( $where, " AND {$wpdb->posts}.ID NOT IN (" )`. Core writes exactly that prefix for `post__not_in`; a later `posts_where` callback may have removed it.
- Add `global $wpdb;`.
- Docblock: the clause is written only where WordPress left `post__not_in` out of the WHERE (`p` or `post__in` set) or a later callback stripped it, so a query carries the list once.

### `includes/Catalog_Visibility.php` class docblock

Replace the sentence "{@see Products::hide_pos_only_products()} keeps POS Only products out of front-end queries by `post__not_in`;" with one saying:
- {@see Products::hide_pos_only_products()} keeps POS Only products out of front-end product queries through `post__not_in`;
- {@see Products::exclude_pos_only_products_from_where()} adds a WHERE clause where WordPress ignores `post__not_in`.

Keep the rest of the paragraph and its wrapping width.

## Tests (two, added to `Test_Products_Query_Exclusion.php`, using its helpers)

1. `test_storefront_filtered_product_query_carries_pos_only_ids_in_query_vars`
   - Enable POS Only, create two POS Only products and one visible product, call `set_pos_only_ids()`, then `new Products()`.
   - Run `$query = new WP_Query( $this->product_query_args() )`.
   - Assert that every POS Only id is in `array_map( 'intval', (array) $query->get( 'post__not_in' ) )`.
   - Also assert `array( $visible ) === ids returned`.
2. `test_storefront_variation_query_carries_pos_only_ids_in_query_vars`
   - Enable POS Only, `ProductHelper::create_variation_product()`, and mark its first child POS Only via `set_pos_only_ids( array(), array( $variation ) )`. Then `new Products()`.
   - Query `product_query_args( array( 'post_type' => 'product_variation' ) )`.
   - Assert that the variation id is in the int-cast `post__not_in` and not in `$query->posts`.

`@covers \WCPOS\WooCommercePOS\Products::hide_pos_only_products` on both. Edit the class docblock so it says the tests pin the SQL, the ids that come back and, for integrations that answer from them, the query vars.

Order of work:
1. Add the two tests.
2. Run the exclusion file and confirm both new tests FAIL. Save the output to `.claude/specs/2026-11-13-pos-only-per-query-cost/repro/red-query-vars.txt`.
3. Implement.
4. Run the whole filter again; everything passes.

## Do not

- Change `get_pos_only_excluded_ids()`, the memo, the hook priorities or the SQL fragment.
- Add a filter, an option, a log line, or caching.
- Touch other tests or refactor anything else.
- Create files beyond `red-query-vars.txt`.
- Commit or push.
- Read GitHub issues, PRs or comments.

## Pre-authorised actions

- Edit the in-scope files and write `red-query-vars.txt`.
- Run the acceptance commands. wp-env is already running for this worktree, and vendor and node_modules are installed: do not install anything, and do not start or stop wp-env.

## Budget

- Max non-test lines changed: 30
- Max total lines changed: 78

If you are about to exceed either, STOP and report why. The budget is a stop rule, not a target. Never strip tests, comments or formatting to fit. Run every acceptance command in the foreground.

## Acceptance (from the worktree root, Bash timeout 700000 ms)

```sh
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --filter 'Test_Products(_Direct|_Query_Exclusion)?::'
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Products.php includes/Catalog_Visibility.php tests/includes/Test_Products_Query_Exclusion.php
```

phpcs: report errors on lines you added. Errors already on main stay.

## Environment

- Network: not needed.
- Wall-clock limit: three hours. At the limit, stop and report partial state.
- Docker is shared: one suite at a time, through `test-slot-run`.

## Output expected

Report:
- STATUS
- CHANGED (`git diff --stat`)
- ACCEPTANCE (each command, its exit code and its last lines)
- the red run
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES
- why you made these changes, in one paragraph

If the spec is ambiguous or wrong, stop and ask under QUESTIONS. Do not guess.
