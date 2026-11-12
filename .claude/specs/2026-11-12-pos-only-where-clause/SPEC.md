# Spec — #2647 skips POS Only ids only when the WHERE clause still holds WordPress's own id clause

## Goal

#2647 (head `b918ef6b5618f7110dee944c761a8ab70d144cbb`) appends to its `posts_where` `NOT IN` only those
POS Only ids that the query's own id clause does not already exclude. `ids_not_excluded_by_query_vars()`
decides which, from the query vars `p`, `post__in` and `post__not_in`.

But WordPress wrote its id clause into `$where` from those vars *before* `posts_where` runs. A third-party
`posts_where` callback that runs before ours (priority < 10) can change the vars or the clause afterwards:
- set `p` to 0, or to another id: WordPress's `ID = 12` stays in the SQL, the helper reads the new value,
  appends nothing, and POS Only product 12 leaks;
- clear or replace `post__in`: the same leak through the list branch;
- strip WordPress's own `ID NOT IN (...)` from `$where`, or replace `post__not_in`: the helper trusts the
  var and appends nothing.

2.0.1 appended the full list every time, so this is a lost backstop.

**The rule:** each branch may skip or narrow the list only when `$where` itself still contains the exact
clause WordPress builds from the value the branch reads. Clause absent: return the full list, as 2.0.1
did. The type guards from the fail-closed round stay.

## Stakes

This is the storefront visibility of products a merchant marked POS Only, shipping in patch 2.0.2 to
thousands of stores. Results must equal 2.0.1 exactly. The only intended difference is that the SQL
carries the list once instead of twice.

Under-exclusion is the failure that matters: a POS Only item shown where 2.0.1 hid it. Extra exclusion
of a POS Only id is harmless.

## In scope (the only files you may edit)

- `includes/Products.php`: only `ids_not_excluded_by_query_vars()` with its docblock, and the one line in
  `exclude_pos_only_products_from_where()` that calls it
- `tests/includes/Test_Products.php`
- `.claude/specs/2026-11-12-pos-only-where-clause/SPEC.md` (new; a byte-for-byte copy of this file)
- `.claude/specs/2026-11-12-pos-only-where-clause/accept.sh`, `parity.sh` and `mutate.sh` (new; copies)

## Out of scope (do not edit)

- Every other function in `includes/Products.php`, every other file in `includes/`
- `tests/includes/Test_Products_Query_Shapes.php` (the matrix stays as it is; its parity with 2.0.1 must
  not change)
- `tests/fixtures/products-2.0.1.php.txt` (it must stay byte-identical to v2.0.1)
- Every other test file, `tests/bootstrap.php`, `tests/Helpers/**`, phpunit, phpcs and phpstan config,
  `composer.json`
- CI config (`.github/**`), `CHANGELOG.md`, `readme.txt`, `woocommerce-pos.php`
- The documented gaps: #2651, #2652, #2653, and the `suppress_filters` + `p` leak
- The earlier specs under `.claude/specs/`

## Context

**Branch base.** `b918ef6b5618f7110dee944c761a8ab70d144cbb` (branch `fix/pos-only-query-exclusion`,
PR #2647). The tag `v2.0.1` is `82bf7d657a4313798d00f12bdc3284d65f2446ff`.

**WordPress 7.1.4 `class-wp-query.php`, `get_posts()`, lines 2241–2280, verbatim:**

```php
		// If an attachment is requested by number, let it supersede any post number.
		if ( $query_vars['attachment_id'] ) {
			$query_vars['p'] = absint( $query_vars['attachment_id'] );
		}

		// If a post number is specified, load that post.
		if ( $query_vars['p'] ) {
			$where .= " AND {$wpdb->posts}.ID = " . $query_vars['p'];
		} elseif ( $query_vars['post__in'] ) {
			// Duplicate array before sorting to allow for the orderby clause.
			$post__in_for_where = $query_vars['post__in'];
			$post__in_for_where = array_unique( array_map( 'absint', $post__in_for_where ) );
			sort( $post__in_for_where );
			$post__in = implode( ',', array_map( 'absint', $post__in_for_where ) );
			$where   .= " AND {$wpdb->posts}.ID IN ($post__in)";
		} elseif ( $query_vars['post__not_in'] ) {
			sort( $query_vars['post__not_in'] );
			$post__not_in = implode( ',', array_map( 'absint', $query_vars['post__not_in'] ) );
			$where       .= " AND {$wpdb->posts}.ID NOT IN ($post__not_in)";
		}
		// ... post_parent clauses ...
		if ( $query_vars['page_id'] ) {
			if ( ( 'page' !== get_option( 'show_on_front' ) ) || ( get_option( 'page_for_posts' ) != $query_vars['page_id'] ) ) {
				$query_vars['p'] = $query_vars['page_id'];
				$where           = " AND {$wpdb->posts}.ID = " . $query_vars['page_id'];
			}
		}
```

`$query_vars` is a reference to `$this->query_vars` (:1919), so:
- `p` already holds a copied `attachment_id` or `page_id` when `posts_where` runs;
- `post__not_in` is already sorted in place, so the var's current order is the clause's order;
- `post__in` is NOT changed: the clause is written from a de-duplicated, sorted `absint` copy, so the
  needle must be built the same way (the var's own order is not the clause's order).

After `p`, WordPress writes nothing in that clause, so `" AND {posts}.ID = 1"` would also match inside
`" AND {posts}.ID = 12"`. The `p` check therefore needs a boundary: the next character must not be a digit,
letter, `_` or `.`.

The CI matrix also runs WordPress 7.0.4 (the minimum). If its clause differs, results stay correct (the
helper fails closed and appends the full list) but the SQL-once tests fail there. Report it under
QUESTIONS if you see any sign of that; do not add a second clause shape.

## 1. The fix (`includes/Products.php`)

Replace `ids_not_excluded_by_query_vars()`, docblock included, with exactly this (tabs, WordPress style):

```php
	/**
	 * The POS Only ids the query's own id clause does not already exclude.
	 *
	 * WordPress prefers `p` over both id lists: it writes `ID = p` when p is
	 * set (page_id and attachment_id are copied into p first), else
	 * `ID IN (post__in)` when post__in is set, else
	 * `ID NOT IN (post__not_in)`; hide_pos_only_products() has normally put
	 * every POS Only id into one of the lists, so this is usually empty. It is
	 * not for a single-id query, nor when a later pre_get_posts callback set
	 * post__in or replaced post__not_in. Ids are skipped only when WordPress
	 * provably reads the value as this method does (p must be an int or a
	 * digit-only string, each list an array, normalised with absint on both
	 * sides), and only when $where still holds the exact clause WordPress
	 * built from that value: a posts_where callback that runs before this one
	 * can change the query vars or the clause after WordPress wrote it.
	 * Anything else returns the full list, as 2.0.1 did.
	 *
	 * @param int[]     $exclude_ids POS Only ids for the query's post types.
	 * @param \WP_Query $query       The query whose SQL is being built.
	 * @param string    $where       The WHERE clause as this filter receives it.
	 *
	 * @return int[]
	 */
	private function ids_not_excluded_by_query_vars( array $exclude_ids, \WP_Query $query, string $where ): array {
		global $wpdb;

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
			// Nothing follows p in WordPress's clause; the lookahead stops "= 1" matching inside "= 12".
			if ( ! preg_match( '/' . preg_quote( " AND {$wpdb->posts}.ID = " . $single_id, '/' ) . '(?![0-9A-Za-z_.])/', $where ) ) {
				return $exclude_ids;
			}

			return \in_array( (int) $single_id, $exclude_ids, true ) ? array( (int) $single_id ) : array();
		}

		$post__in = $query->get( 'post__in' );
		if ( ! empty( $post__in ) ) {
			if ( ! \is_array( $post__in ) ) {
				return $exclude_ids;
			}
			// WordPress writes a de-duplicated, sorted absint copy of post__in.
			$in_ids = array_unique( array_map( 'absint', $post__in ) );
			sort( $in_ids );
			if ( false === strpos( $where, " AND {$wpdb->posts}.ID IN (" . implode( ',', $in_ids ) . ')' ) ) {
				return $exclude_ids;
			}

			return array_keys( array_intersect_key( array_flip( $exclude_ids ), array_flip( $in_ids ) ) );
		}

		// WordPress sorts post__not_in in place before writing it, so the var holds the clause's order.
		$post__not_in = $query->get( 'post__not_in' );
		if ( empty( $post__not_in ) || ! \is_array( $post__not_in ) ) {
			return $exclude_ids;
		}
		if ( false === strpos( $where, " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', array_map( 'absint', $post__not_in ) ) . ')' ) ) {
			return $exclude_ids;
		}

		return array_keys( array_diff_key( array_flip( $exclude_ids ), array_flip( array_map( 'absint', $post__not_in ) ) ) );
	}
```

Notes on the code:
- The `$post__not_in === $exclude_ids` shortcut is gone on purpose. The clause check must come first, and
  after it `array_diff_key()` gives the same empty result, so the shortcut only added a branch.
- In `exclude_pos_only_products_from_where()`, change the call to
  `$exclude_ids = $this->ids_not_excluded_by_query_vars( $exclude_ids, $query, (string) $where );`.
  Nothing else in that function changes.

Nothing else in `includes/` changes. If phpcs objects to a line of the code above, fix only what it names
and say so in the report.

## 2. `Test_Products.php` additions

**Tamper hook.** Every new test below except the last uses an earlier `posts_where` callback at priority 5
(ours runs at 10), registered with `add_filter( 'posts_where', function ( $where, $query ) { ... }, 5, 2 )`.
It acts only on the query under test, which carries a custom query arg `'wcpos_test_tamper' => true`
(check `$query->get( 'wcpos_test_tamper' )`). It changes only what the test names and returns `$where`.

**Full-list assertion.** Add one private helper,
`assert_sql_appends_every_pos_only_id( array $pos_only_ids, string $sql ): void`. It:
- matches every `/\.ID NOT IN \(([0-9,]+)\)/` in the SQL;
- asserts there is at least one;
- takes the last match (ours is appended last), explodes it, casts to int and sorts;
- asserts `assertSame( <sorted $pos_only_ids>, <that list> )`.

Read the SQL from `$query->request`.

Use three POS Only simple products (`$hidden`) and at least one visible product in every test, through
`configure_storefront_visibility( $hidden )`. Use `'post_type' => 'product'`, `'fields' => 'ids'`,
`'posts_per_page' => -1` unless the test says otherwise.

Tests 1–7 must FAIL on b918ef6b's `Products.php` (a POS Only id leaks or the list is missing) and pass
on the fix. Test 8 passes on both: it guards the `post__in` clause shape.

| # | name | query | tamper (priority-5 `posts_where`) | assert |
|---|---|---|---|---|
| 1 | `test_pos_only_products_earlier_posts_where_zeroing_p_keeps_the_full_list` | `p => $hidden[1]` | `$query->set( 'p', 0 )` | `$hidden[1]` not returned; full list |
| 2 | `test_pos_only_products_earlier_posts_where_changing_p_keeps_the_full_list` | `p => $hidden[1]` | `$query->set( 'p', <visible id> )` | as 1 |
| 3 | `test_pos_only_products_earlier_posts_where_changing_p_to_its_digit_prefix_keeps_the_full_list` | `p => $hidden[1]` | `$query->set( 'p', intdiv( $hidden[1], 10 ) )`; precondition: `$hidden[1] >= 10` and the prefix is not in `$hidden` | as 1 |
| 4 | `test_pos_only_products_earlier_posts_where_clearing_post__in_keeps_the_full_list` | `post__in => array( '-' . $hidden[1] )` | `$query->set( 'post__in', array() )` | as 1 |
| 5 | `test_pos_only_products_earlier_posts_where_replacing_post__in_keeps_the_full_list` | `post__in => array( '-' . $hidden[1] )` | `$query->set( 'post__in', array( <visible id> ) )` | as 1 |
| 6 | `test_pos_only_products_earlier_posts_where_removing_the_not_in_clause_keeps_the_full_list` | the default query | remove WordPress's `" AND {$wpdb->posts}.ID NOT IN (...)"` from `$where` (`preg_replace` with limit 1 and a count); precondition: the count is 1 | none of `$hidden` returned; full list |
| 7 | `test_pos_only_products_earlier_posts_where_replacing_post__not_in_keeps_the_full_list` (data provider, below) | the default query, plus a priority-20 `pre_get_posts` callback on the same query that sets `post__not_in => array( <other visible id> )` | `$query->set( 'post__not_in', <provider value> )` | none of `$hidden` returned; full list |
| 8 | `test_pos_only_products_unsorted_duplicate_post__in_sql_has_no_not_in` | `post__in => array( $v2, $v1, $v2 )` with two visible ids, `$v2 > $v1`; no tamper | — | returns exactly `$v1` and `$v2`; `->request` contains no `NOT IN` |

Data provider for test 7 (`post_not_in_tamper_values`), each returning a closure or a flag the test turns
into the value:
- `'the stored POS Only list'`: `$hidden` as stored;
- `'the stored list reversed, plus another id'`: `array_merge( array_reverse( $hidden ), array( <a fourth id> ) )`.

Test 4 and 5 use `'-<id>'` on purpose. `hide_pos_only_products()` strips POS Only ids from `post__in` with
`intval`, which keeps `'-12'`; WordPress writes `absint`, so the clause still reads `ID IN (12)`. These are
the reviewer's cases.

Keep every existing test unchanged. The existing SQL-once tests (`..._sql_lists_excluded_ids_once`,
`..._caller_post_not_in_sql_lists_excluded_ids_once`, `..._single_id_query_for_pos_only_product_sql_excludes_only_that_id`,
`..._single_id_query_for_visible_product_sql_has_no_not_in`, `..._third_party_negative_post__not_in_sql_lists_each_pos_only_id_once`,
`..._unsorted_list_sql_lists_each_pos_only_id_once`) must still pass: they prove the clause check finds
WordPress's clause when nothing tampered with it.

## Interfaces and constraints

- PHP 7.4 syntax (`strpos`, not `str_contains`).
- `assertSame( expected, actual )` order, Arrange / Act / Assert comments, WordPress coding standards.
- A one-line docblock on each new test saying what it guards.

## Do not

- Change `hide_pos_only_products()`, `get_pos_only_excluded_ids()`, the memo, the gate, or any hook
  registration; in `exclude_pos_only_products_from_where()` change only the call line.
- Edit `tests/includes/Test_Products_Query_Shapes.php` or `tests/fixtures/products-2.0.1.php.txt`.
- Accept more than one clause shape per branch, or add any tolerance beyond the code above.
- Fix #2651, #2652, #2653 or the `suppress_filters` + `p` leak.
- Add CI jobs, config, locking, caching, flags or shims.
- Refactor or reformat code outside the change.
- Create plan, notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch any other worktree.
- Read a GitHub issue, PR or comment raw.

## Pre-authorised actions

**Branch and spec copies**
- `git checkout -b fix/pos-only-where-clause b918ef6b5618f7110dee944c761a8ab70d144cbb` in your own worktree.
- Copy the four files from `/Users/claude/.claude/jobs/77f9299d/tmp/spec2647e/` into
  `.claude/specs/2026-11-12-pos-only-where-clause/` and `chmod +x` the three scripts.
- Commit them alone first: `docs(spec): #2647 trusts the id vars only while the WHERE clause still holds them`.

**Test environment**
- Create `.wp-env.override.json` (gitignored):
  `{"plugins":["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip","."],"port":8997,"testsPort":8998,"mappings":{"wp-content/plugins/woocommerce-pos":"."}}`.
- Export `WPENV=/Users/claude/Projects/woocommerce-pos/.claude/worktrees/session-registry-race/node_modules/.bin/wp-env`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command, then `"$WPENV" start` from your worktree root.
- Then `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- composer install --no-interaction`.
- No local composer, no local phpunit, no symlinked vendor.
- The Docker VM has about 1.9 GB. If MariaDB is killed (exit 137, "MySQL server has gone away", or a
  connection refused mid-run), `docker start` the tests-mysql container of your env, wait for it to be
  healthy, and rerun that one suite. Say in the report if it happened.

**Failing first.** Add the tests before changing `includes/Products.php`. Run `Test_Products.php` on
b918ef6b's code and record the result: tests 1–7 (all eight cases) must fail, test 8 must pass. Paste the
failing lines in the report.

**Checks**
- Run `accept.sh`, `parity.sh`, `mutate.sh`, and phpcs on the changed PHP files:
  `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist <files>`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command before each.
- Everything runs in the foreground.

**Finish**
- Commit the work as one commit:
  `fix(visibility): skip POS Only ids only while the WHERE clause still holds WordPress's id clause`.
  End the message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01Pm6LMFZvjFgQvYM2R7xpL5
  ```
- Then `"$WPENV" stop`.

## Budget

- Max non-test lines changed: 30
- Max total lines changed: 500

If you are about to exceed either, STOP and report why. The budget is a stop rule, not a target. Never strip
tests or comments to fit.

Wall-clock limit: three hours. At the limit, stop with the worktree committed or clean and report partial
state.

## Acceptance criteria (runnable, from the worktree root, `WPENV` exported)

```sh
cmp tests/fixtures/products-2.0.1.php.txt <(git show v2.0.1:includes/Products.php)
git diff --quiet b918ef6b5618f7110dee944c761a8ab70d144cbb -- tests/includes/Test_Products_Query_Shapes.php
.claude/specs/2026-11-12-pos-only-where-clause/accept.sh
.claude/specs/2026-11-12-pos-only-where-clause/parity.sh
.claude/specs/2026-11-12-pos-only-where-clause/mutate.sh
```

What each must show:
- `accept.sh` exits 0, and the new `Test_Products` tests pass.
- `parity.sh`: every row passes for all three columns (2.0.1, fix, b918ef6b); the row counts are equal.
- `mutate.sh`: every mutation is caught (each a failing named test), and none says "DID NOT APPLY".
  Expected catchers: F1 by tests 2 and 3; F2 by test 3; F3 by test 5; F4 by tests 6 and 7; F5 and F6 by
  test 8; M7 by the existing SQL-once tests. Report any mutation caught only by a test you did not expect.

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- FAILING-FIRST (the lines)
- ACCEPTANCE (each command's summary output)
- QUESTIONS
- BEHAVIOUR CHANGES: any row or test where the fix's result differs from 2.0.1's.

If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
