# Codex job spec — the session-row compare hashes with MD5 and treats a NULL row as absent

## Goal

PR #2656 (`fix/session-row-cas`) makes every write of a user's POS session row a compare-and-swap in `Session_Registry::mutate_row()` (`includes/Services/Session_Registry.php`, around lines 472–560). At head 59a31613 the compare reads `SHA1(meta_value)` in the SELECT and matches it in the UPDATE.

The independent delta review (2026-11-15) found two bugs. This job fixes both.

1. **SQLite (B1).** The SQLite Database Integration driver, used by WordPress Playground and Studio, has no `SHA1()`, and neither does SQLite itself. Every SELECT in `mutate_row()` fails with `no such function: SHA1`. Every session write then gives up, so every POS login there gets "Session has been revoked". The driver does define `md5`, and MySQL and MariaDB have `MD5()` built in.
2. **NULL row (B2).** If the row for the key holds SQL NULL (for example after `update_user_meta( $id, key, null )` by third-party code), `$stored_value` is null and the write takes the INSERT path. The re-select then finds the NULL row lowest, so the write deletes its own row and retries. After five attempts it gives up, and every later login for that user does the same. The code on `main` overwrote the NULL row and recovered.

**Fix.**
- Hash with `MD5()`.
- Wrap the hash in `COALESCE(..., '')`, so a NULL row hashes to `''`. No real value has an empty MD5, so a NULL row can still only match itself.
- Take the INSERT path only when there is no row at all (`empty( $rows )`).

A NULL row is then updated in place, and `$change` receives `null` for it, just as for an absent row.

MD5 is used here only to detect whether one row changed between a read and a write. The row's contents are server-generated (jtis, timestamps) plus a user agent string, and the worst a collision could cause is one lost update in a race. It is not a security boundary.

## Stakes

This is authentication for every POS device of thousands of stores (High tier). The failures that matter:
- a revoked session that keeps working;
- a device whose sign-in is silently dropped.

There is no schema change and no new option, table or column.

## In scope (the only files you may edit)

- `includes/Services/Session_Registry.php`: only `mutate_row()` and its docblock
- `tests/includes/Services/Test_Session_Registry_Concurrency.php`: add the one test below

## Out of scope (do not edit)

- Every other method in `includes/Services/Session_Registry.php`
- `includes/Services/Auth.php` and every other file in `includes/`
- The existing tests and helpers in `Test_Session_Registry_Concurrency.php`, and every other test file
- `.claude/specs/**`
- CI, composer, phpcs, phpstan and phpunit config; `CHANGELOG.md`; `readme.txt`

## Required code (exact shape; the mutation script targets these strings)

**1. The SELECT** in `$select` becomes:

```php
"SELECT umeta_id, meta_value, COALESCE(MD5(meta_value), '') AS meta_hash FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND CAST(meta_key AS BINARY) = %s ORDER BY umeta_id ASC",
```

**2. The INSERT-path condition** `if ( null === $stored_value ) {` becomes:

```php
if ( empty( $rows ) ) {
```

**3. The UPDATE** query string becomes:

```php
"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND meta_key = %s AND COALESCE(MD5(meta_value), '') = %s",
```

The arguments are unchanged: `$serialized, (int) $rows[0]->umeta_id, self::META_KEY, $rows[0]->meta_hash`.

**4. Nothing else changes:**
- the `$stored_value` line;
- the `$change` call;
- the no-op line;
- the insert body;
- the duplicate deletes;
- the cache deletes;
- the retry loop;
- the log line.

**5. Docblock.**
- In `mutate_row()`'s docblock, change "SHA-1" to "MD5" wherever it names the hash.
- Add one sentence: a row that holds NULL counts as an empty row and is updated in place.
- Change no other comment. The docblock may grow by at most two lines.

## Test (add to `Test_Session_Registry_Concurrency.php` after `test_session_row_writes_keep_a_four_byte_user_agent_exactly` and before the private helpers)

`test_session_row_write_over_a_null_row_keeps_the_new_sessions`

- Arrange:
  1. Insert the row with `$wpdb->insert()` into `$wpdb->usermeta` with `user_id` `$this->user->ID`, `meta_key` `Session_Registry::META_KEY` and `meta_value` `null`. Use the same phpcs ignore comments as `test_session_row_write_removes_duplicate_rows`.
  2. Keep `$null_id = (int) $wpdb->insert_id`.
  3. Assert, through `$wpdb->get_var( $wpdb->prepare( ... ) )`, that exactly one row for this user and key has `meta_value IS NULL`: `assertSame( '1', ... )`.
  4. Call `wp_cache_delete( $this->user->ID, 'user_meta' )`.
- Act: `$first = $this->login()`, then `$second = $this->login()`.
- Assert:
  - the access tokens of `$first` and `$second` both validate, checked the way the other tests in this file check them;
  - exactly one row exists for this user and key, and its `umeta_id` is `$null_id` (the NULL row was updated in place);
  - its unserialised value has both refresh jtis as keys (use `refresh_jti()`);
  - `revoke_refresh_token()` of `$first`'s jti returns `true`;
  - `refresh_access_token( $first['refresh_token'] )` is a `WP_Error`.

This test must FAIL on the current code (head 59a31613). Record the failing line.

Use Arrange / Act / Assert comments, `assertSame( expected, actual )`, and a one-line docblock in the file's style.

There is deliberately no PHPUnit test for SQLite: the suite runs only on MariaDB. The SQLite fix is proven by an engine run that the reviewer does, not by you.

## Do not

- Change the row's key, location, serialisation or entry shape.
- Add a table, column, option or index.
- Add `GET_LOCK`, transactions, `FOR UPDATE`, `CONVERT()`, `COLLATE` or `IS NULL` clauses to the CAS queries.
- Compute the hash in PHP. That is mutation C2, and it must stay red.
- Edit any existing test or helper.
- Edit the spec or any `.claude/specs` script.
- Create notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch another worktree.
- Start, stop or restart wp-env, or install anything. It is running for this worktree with dependencies installed.

## Pre-authorised actions

- Edit the two in-scope files.
- Run the acceptance commands.
- Commit once, as `fix(auth): the session-row compare hashes with MD5 and treats a NULL row as absent, so SQLite sites and a NULL row keep their sessions`, ending the message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013ukctiKeNUgfwvTdZtDt5d
  ```

## Budget

- Max non-test lines changed: 10
- Max total lines changed: 80

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable, from the worktree root)

First add the test and run the concurrency file on the unchanged registry. The new test must fail; if it passes, STOP and report under QUESTIONS.

Then make the code change; all of these must hold:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Session_Registry_Concurrency.php
WPENV='pnpm exec wp-env' bash .claude/specs/2026-11-12-session-row-cas/accept.sh
WPENV='pnpm exec wp-env' bash .claude/specs/2026-11-15-session-row-cas-md5/mutate.sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/Session_Registry.php tests/includes/Services/Test_Session_Registry_Concurrency.php
```

- The concurrency file passes: 12 tests.
- `accept.sh` exits 0, and every suite reports OK.
- Every mutation in the new `mutate.sh` applies and turns at least one test red. N1 and N2 are expected to turn the new NULL-row test red. No mutation may report "DID NOT APPLY".
- phpcs reports no errors on the two files.

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- FAILING-FIRST (the run before the fix, with the failing line)
- ACCEPTANCE (paste the summary output, including every mutation's result)
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES
