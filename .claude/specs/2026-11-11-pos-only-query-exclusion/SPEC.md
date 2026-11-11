# Spec — a storefront product query carries the POS Only id list once, not twice

## Goal

Every storefront `WP_Query` on `product` / `product_variation` currently pays
for the whole POS Only id list three times in PHP and twice in SQL:
`get_pos_only_excluded_ids()` runs in `pre_get_posts` and again in
`posts_where` (each an `array_map('intval')` + `array_merge` over the full
lists), and `posts_where` appends a second `ID NOT IN (...)` of the full list
built with `$wpdb->prepare()` on top of the `post__not_in` clause WordPress
already wrote from the same ids. Measured on a local site (WC 11.2.2, PHP 8.4)
with 20,000 product + 20,000 variation ids: 25 ms per both-type query, 10 ms
per product query, of which `$wpdb->prepare` alone is 4.2 ms. A REST request
that runs a few thousand product queries reaches PHP's 60 s limit (Sentry
POS-313/314/315, POS-30X/30V).

When this is done, `posts_where` appends only the POS Only ids the query's own
clause does not already exclude (normally none), and the excluded id list is
built once per request per post-type selection and reused while the settings
lists are unchanged. Which ids are hidden, and where, does not change.

## Stakes

Storefront visibility of POS Only products (Medium: a POS Only product leaking
onto the storefront is the failure that matters; a performance regression is
the other). The backstop cases (an id list set after our hook, `post__not_in`
replaced after our hook, `get_posts()` suppressing filters) must keep hiding
POS Only ids exactly as today. No schema, option, hook or REST change.

## In scope (the only files you may edit)

- `includes/Products.php`
- `tests/includes/Test_Products.php`

## Out of scope (do not edit)

- `includes/Services/Settings.php`, `includes/Services/Settings/*.php`
  (the settings read is memoized separately in PR #2645; do not touch it)
- `includes/Init.php`, `includes/Catalog_Visibility.php`
- `tests/includes/Test_Products_Direct.php` and every other test file
- Lockfiles, CI config, docs, the changelog, `readme.txt`

## Context

`includes/Products.php` (line numbers on `main` at 82bf7d65):

- `get_pos_only_excluded_ids( \WP_Query $query ): array` (private, L108-137):
  reads `(array) $query->get( 'post_type' )`; for `product` it reads
  `Settings::instance()->get_pos_only_product_visibility_settings()` and, when
  `isset( $settings['ids'] ) && ! empty( $settings['ids'] )`, merges
  `array_map( 'intval', (array) $settings['ids'] )`; the same for
  `product_variation` with `get_pos_only_variations_visibility_settings()`.
- `hide_pos_only_products( \WP_Query $query )` (`pre_get_posts`, L144-168):
  trims `post__in` and sets
  `post__not_in = array_merge( $existing_excludes, $exclude_ids )`.
- `exclude_pos_only_products_from_where( $where, $query )` (`posts_where`,
  L176-186): calls `should_hide_pos_only_products()` and
  `get_pos_only_excluded_ids()` again and appends
  `AND {$wpdb->posts}.ID NOT IN (...)` for the full list via `$wpdb->prepare`.

WordPress `WP_Query::get_posts()` (after `pre_get_posts`, before
`posts_where`) writes `AND {posts}.ID IN (post__in)` when `post__in` is
non-empty, otherwise `AND {posts}.ID NOT IN (post__not_in)` when
`post__not_in` is non-empty. `post__in` = `array( 0 )` (our "emptied list"
marker) is non-empty. `posts_where` receives the query object whose
`get( 'post__in' )` / `get( 'post__not_in' )` are the values that clause was
built from. `get()` returns `''` for a var that was never set, so test with
`empty()` before any `(array)` cast.

Tests: `tests/includes/Test_Products.php` extends `WP_UnitTestCase`; its
private helper `configure_storefront_visibility( array $product_ids, array
$variation_ids = array() )` enables POS Only products, constructs
`new Products()`, writes the visibility option and `go_to()`s the shop page.
Hooks are restored by `WP_UnitTestCase` after each test. Use
`ProductHelper::create_simple_product()` as the file already does.

## Interfaces and constraints

1. **Memo in `get_pos_only_excluded_ids()`** (signature unchanged). Add a
   private property:

   ```php
   /**
    * Excluded id lists already built this request, by post-type selection.
    *
    * Each entry keeps the settings `ids` arrays it was built from and is reused
    * only while they are identical (===), so a settings write or a filter that
    * returns other ids rebuilds it.
    *
    * @var array<string, array{source: array, ids: int[]}>
    */
   private $excluded_ids_memo = array();
   ```

   In the method: compute `$want_products` / `$want_variations` from the post
   types as today; if neither, `return array();` without reading settings.
   Otherwise read the settings that are wanted (only those) and build
   `$source = array( $want_products ? ( $product_settings['ids'] ?? null ) : null,
   $want_variations ? ( $variation_settings['ids'] ?? null ) : null )`.
   Key `$key = ( $want_products ? 'p' : '' ) . ( $want_variations ? 'v' : '' )`.
   If `isset( $this->excluded_ids_memo[ $key ] )` and its `'source' === $source`,
   return its `'ids'`. Otherwise build the list exactly as today (same
   `! empty` guards, `array_map( 'intval', (array) ... )`, products first, then
   variations), store `array( 'source' => $source, 'ids' => $exclude_ids )`
   under `$key`, and return it.

2. **`hide_pos_only_products()`**: replace the final `set` with
   `$query->set( 'post__not_in', empty( $existing_excludes ) ? $exclude_ids : array_merge( $existing_excludes, $exclude_ids ) );`
   so that in the common case `post__not_in` is the memoized array itself.
   Nothing else in this method changes.

3. **New private method** in `Products`:

   ```php
   /**
    * The POS Only ids the query's own id clause does not already exclude.
    *
    * WordPress writes `ID IN (post__in)` when post__in is set and
    * `ID NOT IN (post__not_in)` otherwise; hide_pos_only_products() has
    * normally put every POS Only id into one of them, so this is usually
    * empty. It is not when a later pre_get_posts callback set post__in or
    * replaced post__not_in.
    *
    * @param int[]     $exclude_ids POS Only ids for the query's post types.
    * @param \WP_Query $query       The query whose SQL is being built.
    *
    * @return int[]
    */
   private function ids_not_excluded_by_query_vars( array $exclude_ids, \WP_Query $query ): array
   ```

   Body, in this order:
   - `empty( $exclude_ids )` → `return array();`
   - `$post__in = $query->get( 'post__in' );` if `! empty( $post__in )` →
     `return array_keys( array_intersect_key( array_flip( $exclude_ids ), array_flip( array_map( 'intval', (array) $post__in ) ) ) );`
   - `$post__not_in = $query->get( 'post__not_in' );`
     if `$post__not_in === $exclude_ids` → `return array();`
     (the list `hide_pos_only_products()` set; identical arrays compare in
     O(1) when they are the same array)
   - if `empty( $post__not_in )` → `return $exclude_ids;`
   - otherwise →
     `return array_keys( array_diff_key( array_flip( $exclude_ids ), array_flip( array_map( 'intval', (array) $post__not_in ) ) ) );`

4. **`exclude_pos_only_products_from_where()`** (signature unchanged): after
   computing `$exclude_ids` as today, set
   `$exclude_ids = $this->ids_not_excluded_by_query_vars( $exclude_ids, $query );`
   and keep the existing `$wpdb->prepare` append for whatever is left. Update
   its docblock to say it appends only the ids the query vars do not already
   exclude. `$query` may be typed loosely by callers: keep the parameter
   untyped as today, and only call the new method when
   `$query instanceof \WP_Query` (otherwise behave as today).

Follow `.phpcs.xml.dist` (tabs, Yoda conditions, `array()` syntax, docblocks).

## Do not

- Add an object cache group, a transient, an option, a static, or any
  invalidation hook: the memo is an instance property validated by `===`.
- Change `should_hide_pos_only_products()`, `is_storefront_request()`,
  `wcpos_request()` or any hook registration or priority.
- Change which ids are hidden on any path, or the `get_posts()` /
  `suppress_filters` trimming of `post__in`.
- Add new dependencies, feature flags, compatibility shims or migrations.
- Refactor or reformat code outside the change.
- Add a test that something is absent from the codebase, or a timing test.
- Create plan, notes or scratch files. Commit (see Output) but never push.
- Read GitHub issues, PRs or comments.

## Tests to add (all in `tests/includes/Test_Products.php`, Arrange / Act / Assert)

Capture the SQL with a `posts_request` filter closure that stores the last
request string; count `'.ID NOT IN ('` with `substr_count`.

1. `test_pos_only_products_storefront_query_sql_lists_excluded_ids_once` —
   one visible and three POS Only products; `new WP_Query( array( 'post_type' =>
   'product', 'fields' => 'ids', 'posts_per_page' => -1 ) )`. Assert
   `assertSame( 1, substr_count( $sql, '.ID NOT IN (' ) )` and
   `assertSame( array( $visible_id ), $query->posts )`. (Fails on `main`: 2.)
2. `test_pos_only_products_caller_post_not_in_sql_lists_excluded_ids_once` —
   two visible products A, B and one POS Only product H; query with
   `'post__not_in' => array( B )`. Assert one `'.ID NOT IN ('` and
   `assertSame( array( A ), $query->posts )`.
3. `test_pos_only_products_post_in_set_after_exclusion_hook_still_excludes` —
   visible V, POS Only H; after `configure_storefront_visibility`, add a
   `pre_get_posts` callback at priority 20 that sets `post__in` to
   `array( V, H )`; query `post_type` product, `fields` ids. Assert
   `assertSame( array( V ), $query->posts )`.
4. `test_pos_only_products_post_not_in_replaced_after_exclusion_hook_still_excludes`
   with a data provider `post_not_in_replacements`: `'emptied' => array( false )`
   and `'replaced' => array( true )`. Visible V, other visible O, POS Only H; a
   priority-20 `pre_get_posts` callback sets `post__not_in` to `array()`
   (emptied) or `array( O )` (replaced). Query all products as ids, ordered
   by ID ascending. Assert H is not in `$query->posts` and V is
   (`assertNotContains` / `assertContains`).
5. `test_pos_only_products_visibility_change_mid_request_applies_to_next_query` —
   products A and B; configure with `array( A )`; run a product ids query
   (assert A absent, B present); then `update_option(
   'woocommerce_pos_settings_visibility', ... )` with `array( B )` as the
   product ids (same shape as the helper writes); run the same query again and
   assert A present, B absent.

## Budget

- Max non-test lines changed: 80
- Max total lines changed: 300

If you are about to exceed either, STOP and report why instead of continuing.
The budget is a stop rule, not a target. Never strip tests, comments or
formatting to fit. Run every acceptance command in the foreground.

## Acceptance criteria (runnable, from the worktree root)

Before changing `includes/Products.php`, add the tests and run the first
command once: tests 1 and 2 must fail on the `NOT IN` count (2, expected 1);
record that output. Then implement.

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Products.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Products_Direct.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Catalog_Visibility.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Pos_Visibility.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/API/V2/Test_Catalog_Proxy_Visibility.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/API/V2/Test_Variations_Search.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Collection_Rules_Search_Parity.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Products.php tests/includes/Test_Products.php
```

Prefix each phpunit command with `~/.claude/bin/test-slot-run --wait 900`
(Bash timeout 1,000,000 ms or the 600,000 max with `--wait 500`).

## Environment

- Network: yes (wp-env image and zip downloads).
- `node_modules` and `vendor/` are present in the worktree; do not install.
- `.wp-env.override.json` (untracked, gitignored) is present: WooCommerce
  11.2.2 (CI's Smoke Test version), ports 8993/8994. Start with
  `pnpm exec wp-env start`; stop with `pnpm exec wp-env stop` at the end.
- The Docker VM has 2 GB shared with other sessions: one wp-env at a time.
- Wall-clock limit: three hours. At the limit, stop and report partial state.

## Output expected

Commit the change on the current branch (`fix/pos-only-query-exclusion`) as
one commit, message `fix(visibility): a storefront product query carries the
POS Only list once, not twice`, ending with the two trailer lines given in the
brief. Never push. Report STATUS / CHANGED / ACCEPTANCE (each command, its
exit code, and the before/after output of test 1) / NOT DONE / QUESTIONS /
BEHAVIOUR CHANGES. If the spec is ambiguous or wrong, stop and ask under
QUESTIONS.
