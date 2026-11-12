# Spec — #2647 normalises ids as WordPress does, and the matrix proves 2.0.1 parity in every run

## Goal

#2647 (head `2e15958bdc3771d4c1a8d5497e15287825fa8136`) leaves out of its `posts_where` `NOT IN` any POS
Only id the query's own id clause already excludes. To decide that, `ids_not_excluded_by_query_vars()`
normalises `post__in` and `post__not_in` with `intval`. WordPress builds the SQL with `absint`
(`wp-includes/class-wp-query.php`: `array_unique( array_map( 'absint', post__in ) )` for `ID IN`,
`array_map( 'absint', post__not_in )` for `ID NOT IN`).

So a storefront `new WP_Query( [ 'post_type' => 'product', 'post__in' => [ -12 ] ] )` with 12 POS Only:
- on 2.0.1 returns nothing (its `posts_where` always appends the full list);
- on the head returns product 12, because WordPress reads `-12` as `IN (12)` while the head reads it
  as an id that is not POS Only.

`'-12'` behaves the same, for example `[products ids="-12"]`.

To fix this:
1. Normalise exactly as WordPress does.
2. Make the matrix prove parity with 2.0.1 in every run: it runs every shape against a byte-identical
   copy of 2.0.1's `Products.php` and against the tree, and asserts both give the same result.
3. Add the shapes the review named.

## Stakes

This is the storefront visibility of products a merchant marked POS Only, shipping in a patch release
to thousands of stores. Results must equal 2.0.1 exactly. The only intended difference is that the
list appears in the SQL once instead of twice.

Under-exclusion (a POS Only item shown where 2.0.1 hid it) is the failure that matters. Extra
exclusion of a POS Only id is harmless.

## In scope (the only files you may edit)

- `includes/Products.php`
- `tests/includes/Test_Products.php`
- `tests/includes/Test_Products_Query_Shapes.php`
- `tests/fixtures/products-2.0.1.php.txt` (new; byte-identical to `git show v2.0.1:includes/Products.php`)
- `.claude/specs/2026-11-12-pos-only-absint-parity/SPEC.md` (new; a byte-for-byte copy of this file)
- `.claude/specs/2026-11-12-pos-only-absint-parity/accept.sh` (new; copy)
- `.claude/specs/2026-11-12-pos-only-absint-parity/parity.sh` (new; copy)
- `.claude/specs/2026-11-12-pos-only-absint-parity/mutate.sh` (new; copy)

## Out of scope (do not edit)

- `includes/Catalog_Visibility.php`, `includes/Services/Settings.php`, `includes/Admin/**`, `includes/API/**`
- Every other test file, `tests/bootstrap.php`, `tests/Helpers/**`, `.phpunit.xml.dist`, `.phpcs.xml.dist`,
  `composer.json`, `phpstan.neon`
- CI config (`.github/**`), `CHANGELOG.md`, `readme.txt`, `woocommerce-pos.php`
- The gaps the matrix documents: #2651 (`post_type` any or unset), #2652 (cached variation children),
  #2653 (Store API by-id route), and the `suppress_filters` + `p` leak. Record them; do not fix them.

## Context

- **Branch base.** `2e15958bdc3771d4c1a8d5497e15287825fa8136`. The tag `v2.0.1` is
  `82bf7d657a4313798d00f12bdc3284d65f2446ff`. `git show v2.0.1:includes/Products.php | shasum -a 1` is
  `507aa31d2cbedc025fc15eb88e416d5ff6cbde1a`.
- **The function.** `includes/Products.php` `ids_not_excluded_by_query_vars()` (~:218–250):
  - the `p` branch uses `absint` and stays as it is;
  - the `post__in` branch is `array_flip( array_map( 'intval', (array) $post__in ) )` (~:234);
  - the `post__not_in` branch is `array_flip( array_map( 'intval', (array) $post__not_in ) )` (~:245).
- **Core, WordPress 7.1** (`get_posts()`, ~:2240–2258):
  ```php
  if ( $query_vars['attachment_id'] ) { $query_vars['p'] = absint( $query_vars['attachment_id'] ); }
  if ( $query_vars['p'] ) {
      $where .= " AND {$wpdb->posts}.ID = " . $query_vars['p'];
  } elseif ( $query_vars['post__in'] ) {
      $post__in_for_where = array_unique( array_map( 'absint', $query_vars['post__in'] ) );
      ... " AND ID IN (...)"
  } elseif ( $query_vars['post__not_in'] ) {
      ... implode( ',', array_map( 'absint', $query_vars['post__not_in'] ) ) ... " AND ID NOT IN (...)"
  }
  ```
  - `page_id` is copied into `p` later (~:2276), unless it equals `page_for_posts` with
    `show_on_front = page`.
  - `parse_query()` turns a negative or non-scalar `p` into 0 (~:832).
  - `pre_get_posts` runs after `parse_query()`, and only `fill_query_vars()` follows it, so a late
    callback's values reach the SQL un-normalised except through the `absint` above.
  - Consequences of `absint`: `0` and non-numeric strings become 0, which is never a product; `'-12'`
    and `-12` become 12; duplicates do not matter.
- **The existing matrix.** `tests/includes/Test_Products_Query_Shapes.php` (98 rows):
  - `found()` builds the default args;
  - `found_outside_wp_query()` handles non-WP_Query shapes;
  - `ids_with_late_callback()` covers late `pre_get_posts` callbacks;
  - `setUp()` builds the fixture and calls `new Products()`.

  Keep every existing row and its expected value.
- **The previous spec.** `.claude/specs/2026-11-12-pos-only-single-id/SPEC.md` holds the fixture rules,
  the REST rules (logged out for storefront rows; never `wp_rest_get_request()` for a storefront row)
  and the data-provider key format. They all still apply.

## 1. The fix (`includes/Products.php`)

In `ids_not_excluded_by_query_vars()`, change `'intval'` to `'absint'` on the `post__in` line and the
`post__not_in` line.

Add one sentence to the docblock: ids are normalised with `absint`, as WordPress does when it writes
the clause, so `-12` and `'12'` are 12.

Nothing else in `includes/` changes.

## 2. The 2.0.1 reference inside the matrix

**The copy.** Create `tests/fixtures/products-2.0.1.php.txt` with
`git show v2.0.1:includes/Products.php > tests/fixtures/products-2.0.1.php.txt`. It must be
byte-identical; do not edit it. The `.txt` extension keeps PHPUnit, phpcs, phpstan and the
autoloader away from it.

**The loader**, a private static method in `Test_Products_Query_Shapes`:
1. Read the file and check `sha1( $contents ) === '507aa31d2cbedc025fc15eb88e416d5ff6cbde1a'`. Hold the
   hash in a class constant with a comment naming the tag. If it does not match, `$this->fail()`.
2. Replace `namespace WCPOS\WooCommercePOS;` with
   `namespace WCPOS\WooCommercePOS\Tests\Reference_2_0_1;`. Fail unless exactly one replacement
   happened.
3. Write the result to a file under `get_temp_dir()` and `require_once` it, once per process (guard
   with `class_exists`).

The copy uses `Settings` through a `use` line and no unqualified sibling class. If loading fails on an
unqualified name anyway, stop and report; do not edit the copy.

**Swapping which code is hooked.** Add a private method `use_products( string $class ): void`:
1. Remove, from every hook in `$GLOBALS['wp_filter']`, each callback whose function is an array whose
   first element is an instance of `\WCPOS\WooCommercePOS\Products` or of the reference class.
2. Run `Settings::instance()->reset_sections_for_testing()`.
3. Run `new $class()`.

`WP_UnitTestCase` restores hooks in `tearDown()`, so nothing leaks between tests.

**The test method.** It stays one method with the same data provider. Rename it
`test_query_shape_storefront_result_matches_2_0_1_reference( string $shape, string $subject,
bool $expected_on_2_0_1 )`.
- Arrange: the subject id.
- Act:
  1. `use_products( reference )`, then `$on_2_0_1 = $this->found( $shape, $id )`;
  2. reset the per-row state (`wp_set_current_user( 0 )`, then the same storefront navigation `setUp()`
     does);
  3. `use_products( Products::class )`, then `$on_tree = $this->found( $shape, $id )`.
- Assert:
  1. `assertSame( $expected_on_2_0_1, $on_2_0_1, '2.0.1 reference' )`;
  2. `assertSame( $on_2_0_1, $on_tree, 'tree differs from 2.0.1' )`.

The typed value now documents behaviour. The reference run is what proves it.

**Proving the reference is real.** Add one separate test,
`test_reference_2_0_1_writes_the_pos_only_list_twice_and_the_tree_once`. Run the default
`[ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids' ]` query on the storefront
under each class, and count `<pos only product id>` in `->request`:
- the reference must give 2;
- the tree must give 1.

This fails when the loader hooks the tree's class instead of 2.0.1's.

## 3. New matrix rows

Each shape needs:
- a POS Only row and a control row of the same kind;
- a control row that is found. If the control is not found on 2.0.1, drop the shape for that kind and
  say so in the report.

Expected values come from running the 2.0.1 reference, not from reasoning. Where 2.0.1 shows a POS Only
item, the row's comment names why or the issue number.

| shape | kind | how |
|---|---|---|
| `post__in_negative` | both | `post__in => [ -id ]` |
| `post__in_negative_string` | both | `post__in => [ '-' . id ]` |
| `post__in_numeric_string` | both | `post__in => [ (string) id ]` |
| `post__in_duplicate` | both | `post__in => [ id, id ]` |
| `post__in_zero_and_id` | both | `post__in => [ 0, id ]` |
| `post__in_non_numeric_and_id` | both | `post__in => [ 'abc', id ]` |
| `third_party_post__in_negative_after_us` | both | a priority-20 callback sets `post__in => [ -id ]` |
| `third_party_post__not_in_negative_after_us` | both | a priority-20 callback sets `post__not_in => [ '-' . control id ]` |
| `third_party_p_after_us` | both | a priority-20 callback sets `p => id` on a query with no id var |
| `third_party_post_type_after_us` | both | the query starts with `post_type => 'post'`; a priority-20 callback sets `post_type` to the subject's type |
| `third_party_post_type_any_after_us` | both | a priority-20 callback sets `post_type => 'any'` and `p => id` (#2651 expected) |
| `post_type_product_and_variation` | both | `post_type => [ 'product', 'product_variation' ]`, `post__in => [ id, control id ]` |
| `post_type_with_post` | both | `post_type => [ 'post', <subject type> ]`, `post__in => [ id, control id ]` |
| `page_id_is_page_for_posts` | both | `show_on_front = page`, `page_on_front` = a new page, `page_for_posts` = id, then `page_id => id` (WordPress does not copy it into `p`) |
| `pagename` | product | `pagename => slug` |
| `title` | both | `title => exact title` |
| `sitemap_products` | product | `wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'product' )`; found = `get_permalink( id )` is among the `loc` values |
| `store_api_list_include` | both | `GET /wc/store/v1/products` with `include=id` (variations: add `type=variation`); found = id among the response ids |
| `store_api_list_exclude_control` | both | the same list with `exclude=<control id>`, `per_page=100`; found = id among the response ids |
| `shortcode_products_ids` | product | `do_shortcode( '[products ids="' . id . '"]' )`; found = the output contains `post-<id>` (check that the control's markup really has it) |
| `shortcode_products_ids_negative` | product | `do_shortcode( '[products ids="-' . id . '"]' )` |
| `block_product_query` | product | one block that renders a storefront product query, through `do_blocks()`. Prefer the core `core/query` block with `{"query":{"postType":"product","include":[id]}}` and a `core/post-title` inner block, or else `woocommerce/handpicked-products` with `{"products":[id]}`. Found = the output contains the subject's title. Name the block you chose in the report |
| `flag_off_on_next_request` | both | hide on, `post__in_only` query; then set `pos_only_products` false; then a new request (`use_products()` with the class under test, no cache flush); found = the result of `post__in_only` |
| `flag_on_again_on_next_request` | both | on, then off (new request), then on (new request); found = the result of `post__in_only` after the last |

Notes on the rows:
- Late callbacks target only that row's query; reuse or extend `ids_with_late_callback()`.
- For the two `flag_*` shapes, `found()` must know which class is under test. Pass it through, or keep
  it in a property `use_products()` sets.
- "Object cache on": the test suite's object cache lives for the whole PHP process, the way a
  persistent cache lives across requests. Do not flush it between requests, and say this in the report.

## 4. `Test_Products.php` addition (SQL size)

Add `test_pos_only_products_third_party_negative_post__not_in_sql_lists_each_pos_only_id_once`, using
`configure_storefront_visibility()` with three POS Only products:
1. A priority-20 `pre_get_posts` callback replaces `post__not_in` with the three POS Only ids as
   negative strings (`'-<id>'`).
2. The query is the default storefront product query.
3. Each POS Only id appears in `->request` exactly once, and none of the POS Only products is
   returned.

## Interfaces and constraints

- PHP 7.4 syntax.
- `assertSame( expected, actual )` order, Arrange / Act / Assert comments, WordPress coding standards.
- Data-provider keys stay `"<shape> <subject>"`.

## Do not

- Change `hide_pos_only_products()`, `exclude_pos_only_products_from_where()`, the `p` branch, the
  memo, the gate, or any hook registration.
- Edit `tests/fixtures/products-2.0.1.php.txt` after creating it.
- Fix #2651, #2652, #2653 or the `suppress_filters` + `p` leak.
- Add CI jobs, workflow files or config changes.
- Add locking, caching, feature flags or shims.
- Refactor or reformat code outside the change.
- Create plan, notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch any other worktree.
- Read a GitHub issue, PR or comment raw.

## Pre-authorised actions

**Branch and spec copies**
- `git checkout -b fix/pos-only-absint-parity 2e15958bdc3771d4c1a8d5497e15287825fa8136` in your own
  worktree.
- Copy the four spec files from `/Users/claude/.claude/jobs/77f9299d/tmp/spec2647c/` into
  `.claude/specs/2026-11-12-pos-only-absint-parity/`.
- Commit them alone first: `docs(spec): #2647 normalises ids as WordPress does; the matrix proves 2.0.1 parity`.

**Test environment**
- Create `.wp-env.override.json` (gitignored):
  `{"plugins":["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip","."],"port":8997,"testsPort":8998,"mappings":{"wp-content/plugins/woocommerce-pos":"."}}`.
- Export
  `WPENV=/Users/claude/Projects/woocommerce-pos/.claude/worktrees/file-integrity-stale-version/node_modules/.bin/wp-env`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command, then `"$WPENV" start` from your worktree
  root.
- Then
  `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- composer install --no-interaction`.
- No local composer, no local phpunit, no symlinked vendor.

**Checks**
- Run `accept.sh`, `parity.sh`, `mutate.sh` and phpcs on the changed PHP files:
  `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist <files>`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command before each of them.
- Everything runs in the foreground.

**Finish**
- Commit the work as one commit:
  `fix(visibility): a storefront product query normalises ids as WordPress does`. End the message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01Pm6LMFZvjFgQvYM2R7xpL5
  ```
- Then `"$WPENV" stop`.

## Budget

- Max non-test lines changed: 10
- Max total lines changed: 1300 (the byte-identical 2.0.1 copy is 385 lines of that)

If you are about to exceed either, STOP and report why instead of continuing. The budget is a stop
rule, not a target. Never strip tests or comments to fit.

Wall-clock limit: three hours. At the limit, stop with the worktree committed or clean and report
partial state.

## Acceptance criteria (runnable, from the worktree root, `WPENV` exported)

```sh
cmp tests/fixtures/products-2.0.1.php.txt <(git show v2.0.1:includes/Products.php)
.claude/specs/2026-11-12-pos-only-absint-parity/accept.sh
.claude/specs/2026-11-12-pos-only-absint-parity/parity.sh
.claude/specs/2026-11-12-pos-only-absint-parity/mutate.sh
```

What each must show:
- `accept.sh` exits 0. The matrix row count is printed, and both new tests named above pass.
- `parity.sh` prints its table. With 2.0.1's file as the tree, every row passes. On the fix, every row
  passes. On `2e15958b`, the `post__in_negative*` and `third_party_post__in_negative_after_us` POS Only
  rows FAIL with "tree differs from 2.0.1". If other rows fail on `2e15958b`, list them.
- `mutate.sh` prints every mutation as caught (each a failing named test), none "DID NOT APPLY".

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- ACCEPTANCE (paste each command's summary output)
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES. List every dropped shape and kind with the reason, every row where 2.0.1 shows a
  POS Only item, and the block you chose.

If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
