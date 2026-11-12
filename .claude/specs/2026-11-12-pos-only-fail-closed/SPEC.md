# Spec — #2647 skips the POS Only list only on id values WordPress provably reads the same way

## Goal

#2647 (head `37dfea28ab72b8dd59fd051925ba82e9546468d5`) appends to its `posts_where` `NOT IN` only those
POS Only ids that the query's own id clause does not already exclude. `ids_not_excluded_by_query_vars()`
makes that decision.

The helper reads `p` with `absint`. WordPress sanitises `p` and `page_id` only in `parse_query()`, which
runs before `pre_get_posts`. Afterwards it writes the raw value into the SQL:
- `p` at `class-wp-query.php` :2247 (`" AND ID = " . $query_vars['p']`);
- `page_id` at :2276–2279, which copies the raw value into `p` and writes `" AND ID = " . page_id`.

So a `pre_get_posts` callback after ours can run `$q->set( 'p', '0x0C' )`. MySQL reads that as 12, while
the helper reads 0 and falls through to the list checks. With the default list already in
`post__not_in`, the helper appends nothing, and POS Only product 12 comes back. 2.0.1 hid it, because it
always appended the full list.

**The rule:** the helper may skip appending only on values it can prove WordPress turns into the same
SQL as the helper reads them. On anything else it appends the full list, as 2.0.1 did.

## Stakes

This is the storefront visibility of products a merchant marked POS Only, shipping in patch 2.0.2 to
thousands of stores. Results must equal 2.0.1 exactly. The only intended difference is that the SQL
carries the list once instead of twice.

Under-exclusion is the failure that matters: a POS Only item shown where 2.0.1 hid it. Extra exclusion
of a POS Only id is harmless.

## In scope (the only files you may edit)

- `includes/Products.php`, only `ids_not_excluded_by_query_vars()` and its docblock
- `tests/includes/Test_Products.php`
- `tests/includes/Test_Products_Query_Shapes.php`
- `.claude/specs/2026-11-12-pos-only-fail-closed/SPEC.md` (new; a byte-for-byte copy of this file)
- `.claude/specs/2026-11-12-pos-only-fail-closed/accept.sh`, `parity.sh` and `mutate.sh` (new; copies)

## Out of scope (do not edit)

- Every other function in `includes/Products.php`, every other file in `includes/`
- `tests/fixtures/products-2.0.1.php.txt` (it must stay byte-identical to v2.0.1)
- Every other test file, `tests/bootstrap.php`, `tests/Helpers/**`, phpunit, phpcs and phpstan config,
  `composer.json`
- CI config (`.github/**`), `CHANGELOG.md`, `readme.txt`, `woocommerce-pos.php`
- The documented gaps: #2651, #2652, #2653, and the `suppress_filters` + `p` leak

## Context

**Branch base.** Use `37dfea28ab72b8dd59fd051925ba82e9546468d5` (branch `fix/pos-only-query-exclusion`,
PR #2647). The tag `v2.0.1` is `82bf7d657a4313798d00f12bdc3284d65f2446ff`.

**The existing matrix** is `tests/includes/Test_Products_Query_Shapes.php` (181 rows). Each row runs:
- against a byte-identical copy of 2.0.1's `Products.php`, loaded into its own namespace;
- against the tree, through `use_products()`.

It asserts the expected 2.0.1 result, then that the tree equals 2.0.1. Its helpers are:
- `found()`, for shapes;
- `found_outside_wp_query()`;
- `ids_with_late_callback( $args, $vars )`, for a priority-20 `pre_get_posts` callback that sets vars on that
  one query;
- `mark_pos_only()`;
- `set_pos_only_flag_on_next_request()`.

Keep every existing row and its expected value. The fixture rules, REST rules and data-provider key format
(`"<shape> <subject>"`) of `.claude/specs/2026-11-12-pos-only-absint-parity/SPEC.md` still apply.

**WordPress 7.1.4 `get_posts()`.**
- :1919: `$query_vars` is a reference to `$this->query_vars`, so `posts_where` sees what WordPress
  wrote back.
- :2241: `attachment_id` is copied into `p` with `absint`.
- :2246: `if ( $query_vars['p'] )` writes `ID = <raw p>`.
- Otherwise `elseif ( post__in )` writes `absint` of each element.
- Otherwise `elseif ( post__not_in )` first runs **`sort( $query_vars['post__not_in'] )` in place**
  (:2256), then writes `absint` of each element.
- :2276: when `page_id` is set (and is not the posts page of a static front page), its raw value is
  copied into `p` and `$where` is replaced with `ID = <raw page_id>`.
- `posts_where` runs later, at :2796.

So when the helper runs:
- `p` already holds a copied `page_id`;
- `post__not_in` is sorted ascending.

Every fixture today has one POS Only id per type, so the `$post__not_in === $exclude_ids` shortcut
always hits. Its miss path is never exercised.

## 1. The fix (`includes/Products.php`)

Replace the body of `ids_not_excluded_by_query_vars()` with exactly this (tabs, WordPress style):

```php
		if ( empty( $exclude_ids ) ) {
			return array();
		}

		// WordPress ignores post__in and post__not_in when a single post is requested
		// (p, or page_id / attachment_id, which it copies into p before this filter),
		// and writes p into the SQL as it is. Only an int or a digit-only string is
		// provably the id MySQL reads; anything else ('0x0C', '0b1100', ' 12', 12.0)
		// keeps the full list, as 2.0.1 did.
		$single_id = $query->get( 'p' );
		if ( $single_id ) {
			if ( ! \is_int( $single_id ) && ! ( \is_string( $single_id ) && ctype_digit( $single_id ) ) ) {
				return $exclude_ids;
			}

			return \in_array( (int) $single_id, $exclude_ids, true ) ? array( (int) $single_id ) : array();
		}

		$post__in = $query->get( 'post__in' );
		if ( ! empty( $post__in ) ) {
			if ( ! \is_array( $post__in ) ) {
				return $exclude_ids;
			}

			return array_keys( array_intersect_key( array_flip( $exclude_ids ), array_flip( array_map( 'absint', $post__in ) ) ) );
		}

		$post__not_in = $query->get( 'post__not_in' );
		if ( $post__not_in === $exclude_ids ) {
			return array();
		}
		if ( empty( $post__not_in ) || ! \is_array( $post__not_in ) ) {
			return $exclude_ids;
		}

		return array_keys( array_diff_key( array_flip( $exclude_ids ), array_flip( array_map( 'absint', $post__not_in ) ) ) );
```

Notes on the code:
- `if ( $single_id )` is WordPress's own truthiness test, so `'0'`, `0` and `''` fall through as they do in
  WordPress.
- `'00'` is truthy and digit-only: WordPress writes `ID = 00`, which finds nothing, and the helper
  returns nothing. That is safe.
- A negative int finds nothing in either.

**Docblock.** Rewrite the last two sentences of the docblock to say:
- the helper skips ids only when it can prove WordPress reads the value as it does: `p` must be an int or
  a digit-only string, and each list must be an array, whose elements both sides normalise with `absint`;
- anything else returns the full list, as 2.0.1 did.

Nothing else in `includes/` changes.

## 2. New matrix rows (`Test_Products_Query_Shapes.php`)

Expected values come from running the 2.0.1 reference, not from reasoning. Each shape needs:
- a POS Only row and a control row of the same kind;
- a control row that is found on 2.0.1.

If the control is not found on 2.0.1 (for example the database does not read the literal), drop that
shape and kind and say so in the report.

**Must rows**

| shape | kind | how |
|---|---|---|
| `third_party_p_hex_after_us` | both | late callback sets `p => sprintf( '0x%X', id )` |
| `third_party_p_binary_after_us` | both | late callback sets `p => '0b' . decbin( id )` |
| `third_party_page_id_hex_after_us` | both | late callback sets `page_id => sprintf( '0x%X', id )` |
| `third_party_page_id_binary_after_us` | both | late callback sets `page_id => '0b' . decbin( id )` |
| `unsorted_list_default` | both | several POS Only ids stored unsorted (below); the default query |
| `unsorted_list_post__in` | both | stored unsorted; `post__in => [ id, control id ]` |
| `unsorted_list_post__not_in_other` | both | stored unsorted; `post__not_in => [ PHP_INT_MAX ]` |
| `unsorted_list_late_post__not_in` | both | stored unsorted; late callback sets `post__not_in => [ PHP_INT_MAX ]` |

**Nice rows** (add them if each costs under about 15 lines; otherwise skip and say so)

| shape | kind | how |
|---|---|---|
| `third_party_page_id_after_us` | both | late callback sets `page_id => id` |
| `query_run_twice` | both | `$q = new WP_Query( $args )`, then `$q->get_posts()` again on the same object; found = id in the second result |
| `unsorted_list_query_run_twice` | both | stored unsorted; the same as `query_run_twice` |
| `sticky_posts` | product | `sticky_posts` = `[ id ]`, query `post_type => [ 'post', 'product' ]` without `ignore_sticky_posts`; assert `is_home()` on the query as a precondition, or drop the shape and say why |

**"Stored unsorted"** means:
- the subject's type has at least three POS Only ids, stored so that the list is not in ascending order;
- for a POS Only subject, the subject is one of them, not the first;
- for a control subject, the fixture's POS Only item plus at least two extra POS Only items of the same
  type (extra simple products for products; extra variations, for example of a second variable product,
  for variations);
- create the extras once per row, not once per run, so both runs see the same ids;
- assert as a precondition, before the Act, that the stored ids the tree reads (through
  `Settings::instance()`, after `reset_sections_for_testing()`) are not in ascending order. If the
  settings API sorts on write, write the option the way `Test_Products::configure_storefront_visibility()`
  does.

## 3. `Test_Products.php` additions

Add two tests:

1. **`test_pos_only_products_unsorted_list_sql_lists_each_pos_only_id_once`**
   - Arrange: three POS Only products, stored in non-ascending order through
     `configure_storefront_visibility()`. Assert that order as a precondition.
   - Act: the default storefront product query.
   - Assert: each POS Only id appears in `->request` exactly once, and none is returned.
   - This is the test that exercises the shortcut's miss path.
2. **`test_pos_only_products_third_party_hex_p_keeps_the_full_list`**
   - Arrange: one POS Only product; a priority-20 `pre_get_posts` callback sets `p => sprintf( '0x%X', id )`
     on that query only.
   - Assert: the product is not returned, and `->request` contains `NOT IN (` with the id.

## Interfaces and constraints

- PHP 7.4 syntax.
- `assertSame( expected, actual )` order, Arrange / Act / Assert comments, WordPress coding standards.

## Do not

- Change `hide_pos_only_products()`, `exclude_pos_only_products_from_where()`, `get_pos_only_excluded_ids()`,
  the memo, the gate, or any hook registration.
- Edit `tests/fixtures/products-2.0.1.php.txt`.
- Fix #2651, #2652, #2653 or the `suppress_filters` + `p` leak.
- Add CI jobs, config, locking, caching, flags or shims.
- Refactor or reformat code outside the change.
- Create plan, notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch any other worktree.
- Read a GitHub issue, PR or comment raw.

## Pre-authorised actions

**Branch and spec copies**
- `git checkout -b fix/pos-only-fail-closed 37dfea28ab72b8dd59fd051925ba82e9546468d5` in your own worktree.
- Copy the four files from `/Users/claude/.claude/jobs/77f9299d/tmp/spec2647d/` into
  `.claude/specs/2026-11-12-pos-only-fail-closed/`.
- Commit them alone first: `docs(spec): #2647 skips the POS Only list only on provable id values`.

**Test environment**
- Create `.wp-env.override.json` (gitignored):
  `{"plugins":["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip","."],"port":8997,"testsPort":8998,"mappings":{"wp-content/plugins/woocommerce-pos":"."}}`.
- Export `WPENV=/Users/claude/Projects/woocommerce-pos/.claude/worktrees/file-integrity-stale-version/node_modules/.bin/wp-env`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command, then `"$WPENV" start` from your worktree root.
- Then `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- composer install --no-interaction`.
- No local composer, no local phpunit, no symlinked vendor.

**Failing first.** Before changing `includes/Products.php`, run the matrix with the new rows and record
which rows fail on 37dfea28's code. The hex and binary POS Only rows must fail with
"tree differs from 2.0.1". Paste the failing lines in the report.

**Checks**
- Run `accept.sh`, `parity.sh`, `mutate.sh` and phpcs on the changed PHP files:
  `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist <files>`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command before each.
- Everything runs in the foreground.

**Finish**
- Commit the work as one commit:
  `fix(visibility): an id value WordPress may read differently keeps the full POS Only list`. End the
  message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01Pm6LMFZvjFgQvYM2R7xpL5
  ```
- Then `"$WPENV" stop`.

## Budget

- Max non-test lines changed: 25
- Max total lines changed: 500

If you are about to exceed either, STOP and report why. The budget is a stop rule, not a target. Never strip
tests or comments to fit.

Wall-clock limit: three hours. At the limit, stop with the worktree committed or clean and report partial
state.

## Acceptance criteria (runnable, from the worktree root, `WPENV` exported)

```sh
cmp tests/fixtures/products-2.0.1.php.txt <(git show v2.0.1:includes/Products.php)
.claude/specs/2026-11-12-pos-only-fail-closed/accept.sh
.claude/specs/2026-11-12-pos-only-fail-closed/parity.sh
.claude/specs/2026-11-12-pos-only-fail-closed/mutate.sh
```

What each must show:
- `accept.sh` exits 0, and the two new `Test_Products` tests pass.
- `parity.sh`:
  - with 2.0.1's file as the tree, every row passes;
  - on the fix, every row passes;
  - on `37dfea28`, the hex and binary POS Only rows FAIL. List any other row that fails on `37dfea28`.
- `mutate.sh`: every mutation is caught (each a failing named test), and none says "DID NOT APPLY".
  M7 is expected to be caught only by the unsorted SQL test.

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- FAILING-FIRST (the lines)
- ACCEPTANCE (each command's summary output)
- NOT DONE (the nice rows skipped, and why)
- QUESTIONS
- BEHAVIOUR CHANGES: every dropped shape and kind with the reason, and every new row where 2.0.1 shows a
  POS Only item.

If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
