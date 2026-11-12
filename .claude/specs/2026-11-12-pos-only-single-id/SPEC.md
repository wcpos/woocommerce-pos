# Spec — a single-id storefront query still hides a POS Only product (#2647 follow-up)

## Goal

#2647 (head `c1d9f7b3e16158362b51d64f96938213b376378c`) stopped repeating the POS Only id list in
`posts_where` when `pre_get_posts` had already put it into `post__not_in`. WordPress ignores
`post__not_in` (and `post__in`) whenever `p` is set, and `page_id` / `attachment_id` are copied into
`p` before `posts_where` runs. So on the #2647 head a storefront `WP_Query( [ 'post_type' => 'product',
'p' => <POS Only id> ] )` returns the POS Only product; on 2.0.1 it returns nothing. Restore 2.0.1's
result for every query shape while keeping #2647's smaller SQL, and prove it with a query-shape matrix
that passes on 2.0.1's `Products.php` and on the fix, and fails on `c1d9f7b3`'s.

## Stakes

Storefront visibility of products a merchant marked POS Only (a privacy/catalogue promise, shipping
in a patch release to thousands of stores). Results must equal 2.0.1 exactly; the only allowed
difference is the SQL size. Concurrency and performance beyond that are out of scope.

## In scope (the only files you may edit)

- `includes/Products.php`
- `tests/includes/Test_Products.php`
- `tests/includes/Test_Products_Query_Shapes.php` (new)
- `.claude/specs/2026-11-12-pos-only-single-id/SPEC.md` (new; a byte-for-byte copy of this file)
- `.claude/specs/2026-11-12-pos-only-single-id/accept.sh` (new; copy)
- `.claude/specs/2026-11-12-pos-only-single-id/parity.sh` (new; copy)
- `.claude/specs/2026-11-12-pos-only-single-id/mutate.sh` (new; copy)

## Out of scope (do not edit)

- `includes/Catalog_Visibility.php`, `includes/Services/Settings.php`, `includes/Admin/**`, `includes/API/**`
- Any other test file, `tests/bootstrap.php`, `tests/Helpers/**`
- `CHANGELOG.md`, `readme.txt`, `woocommerce-pos.php`, composer/pnpm files, CI config
- The two pre-existing gaps the matrix documents (#2651 `post_type` any/unset, #2652 cached
  variation children) and the `suppress_filters` + `p` leak: record them, do not fix them.

## Context

- Branch base: `c1d9f7b3e16158362b51d64f96938213b376378c` (#2647 head). 2.0.1 is
  `82bf7d657a4313798d00f12bdc3284d65f2446ff`; #2647 changes only `includes/Products.php` and tests
  against it, so `git show 82bf7d65:includes/Products.php` dropped into the tree is exactly 2.0.1's
  behaviour.
- `includes/Products.php` on the base:
  - `hide_pos_only_products()` (pre_get_posts, ~:178) trims `post__in` and puts the ids into `post__not_in`.
  - `ids_not_excluded_by_query_vars()` (~:218) decides which ids `posts_where` must still append.
  - `exclude_pos_only_products_from_where()` (~:249) appends `AND ID NOT IN (...)` for them.
  - `should_hide_pos_only_products()` (~:303) is the storefront gate; filter `wcpos_hide_pos_only_products`.
  - `hide_pos_only_variations()` (~:356) acts on `woocommerce_variation_is_visible` only on shop/category/product pages.
- WordPress 7.1 `WP_Query::get_posts()` (`wp-includes/class-wp-query.php`): `attachment_id` is
  copied to `p` (~:2241); then `if ( p ) ID = p elseif ( post__in ) ID IN elseif ( post__not_in ) ID NOT IN`
  (~:2246); then `page_id` sets `p` and REPLACES `$where` with `ID = page_id` unless it is the
  posts page on a static front page (~:2276); `posts_where` runs later unless `suppress_filters`.
  Because `query_vars` is a reference, `$query->get( 'p' )` inside `posts_where` already reflects
  `attachment_id` and `page_id`.
- Real-site visibility writes go through `Settings::instance()->update_visibility_settings( [
  'post_type' => 'products'|'variations', 'visibility' => 'pos_only', 'ids' => [ $id ], 'scope' =>
  'default' ] )`, which also lets `Catalog_Visibility` set the `hidden` catalog-visibility term. Use
  that path in the matrix fixture (see `tests/includes/Test_Catalog_Visibility.php:29-64` for
  setUp/tearDown: `Settings::instance()->reset_sections_for_testing()`, `delete_option(
  Pos_Visibility::OPTION )`, `update_option( 'woocommerce_pos_settings_general', [ 'pos_only_products'
  => true ] )`). Construct `new Products()` after the general option is set so its hooks register.
- Fixtures: `Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product()`
  and `::create_variation_product()` (`tests/Helpers/ProductHelper.php`); `WC_Install::create_pages()`
  and `$this->go_to( wc_get_page_id( 'shop' ) )` for a storefront main query (see
  `tests/includes/Test_Products.php` setup and `configure_storefront_visibility()`).
- REST: extend `WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case`
  (`tests/includes/API/WCPOS_REST_Unit_Test_Case.php`) for a booted REST server. Its setUp signs in an
  administrator; storefront rows must run logged out (`wp_set_current_user( 0 )`), the wc/v3 rows as
  that administrator. Build storefront requests with `new WP_REST_Request( 'GET', $route )`, never
  `wp_rest_get_request()` (that adds `X-WCPOS`, which makes it a POS request). `Products` records the
  route on `rest_pre_dispatch`, so each REST row is its own test (data provider) and never runs a
  WP_Query row after a REST dispatch in the same test.

## The fix (`includes/Products.php`)

In `ids_not_excluded_by_query_vars()`, before the `post__in` branch:

```php
// WordPress ignores post__in and post__not_in when a single post is requested
// (p, or page_id / attachment_id, which it copies into p before this filter).
$single_id = absint( $query->get( 'p' ) );
if ( $single_id > 0 ) {
	return \in_array( $single_id, $exclude_ids, true ) ? array( $single_id ) : array();
}
```

Update that method's docblock to say WordPress prefers `p` over both lists. Nothing else in
`includes/` changes.

## The matrix (`tests/includes/Test_Products_Query_Shapes.php`, new)

Class `Test_Products_Query_Shapes extends WCPOS_REST_Unit_Test_Case`, namespace
`WCPOS\WooCommercePOS\Tests`. One test method driven by one data provider:

`test_query_shape_storefront_result_matches_2_0_1( string $shape, string $subject, bool $expected_found )`

- Fixture (setUp): a POS Only simple product, a visible simple product (control), one variable product
  (`create_variation_product()`) with its first variation POS Only and its second visible (control).
  Give the two simple products unique titles, slugs, SKUs and one shared new `product_cat` term so
  name, search, meta and tax shapes can target them. Mark POS Only through
  `update_visibility_settings()` as above. Then `go_to( wc_get_page_id( 'shop' ) )` and log out.
- Subjects: `pos_only_product`, `visible_product`, `pos_only_variation`, `visible_variation`.
- A private `found( string $shape, int $id ): bool` runs one shape for that subject's id and returns
  whether the subject came back. Default query args unless a shape says otherwise: the subject's own
  `post_type`, `post_status => 'publish'`, `fields => 'ids'`, `posts_per_page => -1`, `no_found_rows => true`.
- Every shape has a row for the POS Only subject AND its visible control of the same kind. A control
  row whose `$expected_found` is not true means the shape cannot reach that kind of post: drop that
  shape for that kind and say so in the report (no vacuous rows).

Shapes (product rows use the simple products, variation rows the variations; "both" = both kinds):

| shape | kind | how |
|---|---|---|
| `p` | both | `p => id` |
| `page_id` | both | `page_id => id` |
| `attachment_id` | both | `attachment_id => id` (keep only if the control is found) |
| `p_with_other_post__in` | both | `p => id, post__in => [ control id ]` (WordPress uses p) |
| `name` | both | `name => slug` |
| `pagename` | product | `pagename => slug` |
| `post_name__in` | both | `post_name__in => [ slug ]` |
| `post__in_with_others` | both | `post__in => [ id, control id ]`; found = id in result |
| `post__in_only` | both | `post__in => [ id ]` |
| `post_parent` | variation | `post_parent => parent id` |
| `post_parent__in` | variation | `post_parent__in => [ parent id ]` |
| `s` | both | `s => unique title` |
| `s_no_post_type` | product | `s => unique title`, no `post_type` key (#2651, documented 2.0.1 leak) |
| `tax_query` | product | `product_cat` term |
| `meta_query` | both | `_sku` = subject SKU |
| `fields_all` | both | `fields => ''` (WP_Post objects) |
| `suppress_filters_list` | both | `suppress_filters => true`, no id var |
| `suppress_filters_p` | both | `suppress_filters => true, p => id` (documented 2.0.1 leak; do not change) |
| `post_type_any_p` | both | `post_type => 'any', p => id` (#2651, documented 2.0.1 leak) |
| `wc_get_products_include` | both | `wc_get_products( [ 'include' => [ id ], 'type' => 'simple'|'variation', 'return' => 'ids', 'limit' => -1 ] )` |
| `wc_get_product_direct` | both | `wc_get_product( id ) instanceof WC_Product` (a direct load is not a listing; by design) |
| `is_visible` | both | product: `wc_get_product( id )->is_visible()`; variation: `->variation_is_visible()` on the shop page |
| `single_product_url` | product | `go_to( get_permalink( id ) )`; found = id in `$GLOBALS['wp_query']->posts` ids and not `is_404()` |
| `feed` | product | `go_to( add_query_arg( [ 'feed' => 'rss2', 'post_type' => 'product' ], home_url( '/' ) ) )`; found = id in main query posts |
| `store_api_single` | both | `rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . id ) )`; found = status 200 |
| `wc_v3_single` | both | as the administrator: `/wc/v3/products/<id>` or `/wc/v3/products/<parent>/variations/<id>`; found = 200 (operator namespace: visible by design) |
| `third_party_post__not_in_after_us` | both | a `pre_get_posts` callback at priority 20 sets `post__not_in => [ PHP_INT_MAX ]` on this query only |
| `third_party_post__in_after_us` | both | priority 20 callback sets `post__in => [ id, control id ]` on this query only |
| `children_built_on_storefront` | variation | delete `wc_product_children_<parent>` transient, then on the storefront `wc_get_product( parent )->get_children()`; found = id in it |
| `children_built_outside_storefront` | variation | delete the transient; with `wcpos_hide_pos_only_products` returning false call `get_children()` once (builds the transient), remove that filter, then read `get_children()` again on the storefront (#2652, documented 2.0.1 leak) |

Expected values: by design every POS Only row is `false` and every control row `true`, except the
rows the table marks "documented 2.0.1 leak" or "by design", which are `true` for the POS Only
subject. Establish the truth on 2.0.1, not by reasoning: run `parity.sh` (below), which swaps in
2.0.1's `Products.php`. Where 2.0.1's result differs from the design value, the expectation is
2.0.1's result, the row gets a one-line comment naming why (or the issue number), and the row is
listed under BEHAVIOUR CHANGES in your report as "2.0.1 behaviour recorded". The final file must
pass unchanged on 2.0.1's `Products.php` and on the fix.

## Test_Products.php additions (SQL size, the intended difference)

Two tests next to the existing SQL-size tests, using `configure_storefront_visibility()` with three
POS Only products and one visible one:

- `test_pos_only_products_single_id_query_for_pos_only_product_sql_excludes_only_that_id`:
  `WP_Query( [ 'post_type' => 'product', 'p' => <a POS Only id> ] )` returns nothing and its
  `->request` contains `NOT IN (<that id>)` and none of the other POS Only ids.
- `test_pos_only_products_single_id_query_for_visible_product_sql_has_no_not_in`:
  the same with `p => <visible id>` returns the visible product and `->request` has no `NOT IN`.

## Interfaces and constraints

- PHP 7.4 syntax (no `match`, no named arguments, no arrow functions with by-ref, no union types).
- `assertSame( expected, actual )` order; Arrange / Act / Assert comments; WordPress coding standards
  (tabs, Yoda conditions, spaces inside parentheses).
- Each data-provider key is `"<shape> <subject>"` so a failure names the row.
- Callbacks added inside a row target only that row's query (compare the query object or a unique
  query var) and are removed after it.

## Do not

- Change `hide_pos_only_products()`, `exclude_pos_only_products_from_where()`, the memo, the gate,
  or any hook registration.
- Fix #2651, #2652 or the `suppress_filters` + `p` leak, or add `post_type => 'any'` handling.
- Add locking, caching, feature flags, compatibility shims or migrations.
- Refactor or reformat code outside the change.
- Create plan, notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch any other worktree.
- Read a GitHub issue, PR or comment raw.

## Pre-authorised actions

- `git checkout -b fix/pos-only-single-id c1d9f7b3e16158362b51d64f96938213b376378c` in your own worktree.
- Copy the four spec files from `/Users/claude/.claude/jobs/77f9299d/tmp/spec2647b/` into
  `.claude/specs/2026-11-12-pos-only-single-id/` and commit them alone first:
  `docs(spec): a single-id storefront query still hides a POS Only product`.
- Create `.wp-env.override.json` (gitignored) with WooCommerce 11.2.2 and ports 8997/8998:
  `{"plugins":["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip","."],"port":8997,"testsPort":8998,"mappings":{"wp-content/plugins/woocommerce-pos":"."}}`.
- Use the wp-env binary at
  `/Users/claude/Projects/woocommerce-pos/.claude/worktrees/file-integrity-stale-version/node_modules/.bin/wp-env`
  from your worktree root (export `WPENV=<that path>`; the scripts read it). Run
  `~/.claude/bin/wait-test-slot.sh` before `"$WPENV" start`, then inside the container
  `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- composer install --no-interaction`.
  No local composer, no local phpunit, no symlinked vendor.
- Run `accept.sh`, `parity.sh`, `mutate.sh` and phpcs on the changed files
  (`"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist <files>`).
  Run `~/.claude/bin/wait-test-slot.sh` as its own command before each of them; everything in the
  foreground; one suite at a time.
- Commit the implementation as one commit:
  `fix(visibility): a single-id storefront query still hides a POS Only product`, ending with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and
  `Claude-Session: https://claude.ai/code/session_01Pm6LMFZvjFgQvYM2R7xpL5`.
- Leave your wp-env running for the reviewer; do not stop or destroy other wp-env stacks.

## Budget

- Max non-test lines changed: 25
- Max total lines changed: 700

If you are about to exceed either, STOP and report why instead of continuing. The budget excludes
the four copied spec files. Wall-clock limit: three hours; at the limit stop, leave the tree clean
(no mutation applied, `includes/Products.php` equal to your fix) and report partial state.

## Acceptance criteria (runnable, from the worktree root, `WPENV` exported)

```sh
bash .claude/specs/2026-11-12-pos-only-single-id/accept.sh     # all eight suites exit 0
bash .claude/specs/2026-11-12-pos-only-single-id/parity.sh     # 2.0.1: OK; fix: OK; c1d9f7b3: FAIL on p/page_id(/attachment_id) POS Only rows only
bash .claude/specs/2026-11-12-pos-only-single-id/mutate.sh     # every mutation fails at least one named test
```

phpcs clean on `includes/Products.php` and the two test files.

## Environment

- Network: yes (wp-env pulls WooCommerce 11.2.2 and composer packages).
- Docker running; other sessions' stacks on 8650, 8950, 8971/8972, 8993/8994 must not be touched.

## Output expected

STATUS / CHANGED (with both commit SHAs) / ACCEPTANCE (the three scripts' output verbatim) /
NOT DONE / QUESTIONS / BEHAVIOUR CHANGES (every row whose expectation came from 2.0.1 rather than the
design value, with its 2.0.1 result) and the full 2.0.1-vs-fix-vs-c1d9f7b3 table printed by `parity.sh`.
