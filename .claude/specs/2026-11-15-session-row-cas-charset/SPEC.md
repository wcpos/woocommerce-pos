# Codex job spec — the session-row compare-and-swap compares a database-side hash, so it works on any table charset

## Goal

PR #2656 (`fix/session-row-cas`) makes every write of a user's POS session row a compare-and-swap in
`Session_Registry::mutate_row()` (`includes/Services/Session_Registry.php`, around lines 470–560).
An independent review (2026-11-15) found two bugs in that compare. This job fixes both with one change.

1. **Charset (blocker).**
   - The UPDATE matches the row with `CAST(meta_value AS BINARY) = CAST(%s AS BINARY)`.
   - The left side is the column's bytes in the table's charset. The right side is the value PHP read back, in the connection's charset.
   - On a site whose `wp_usermeta.meta_value` is latin1 and whose connection is utf8/utf8mb4, any non-ASCII byte in the row never matches. A user agent "Café-Till/1.0" is enough.
   - Every write for that user then gives up five times:
     - logins are silently lost;
     - `revoke()` and `keep_only()` fail, so a revoked session keeps working.
2. **Packet size.**
   - The UPDATE carries the serialised row twice: once as the new value, once as the expected value.
   - On MySQL 5.7 with the default 4 MiB `max_allowed_packet`, writes now fail at about half the row size that `update_user_meta()` handled.

**Fix.**
- The SELECT that reads the row also asks the database for `SHA1(meta_value)`.
- The UPDATE compares `SHA1(meta_value)` with that hash, instead of comparing the value.
- Both hashes are computed by the database over the column's own bytes, so they agree whatever the table and connection charsets are.
- The UPDATE now carries the row once, plus 40 hex characters.

## Stakes

This is authentication for every POS device of thousands of stores (High tier). The failures that matter:
- a revoked session that keeps working;
- a device whose sign-in is silently dropped.

There is no schema change and no new option, table or column. Stores run PHP 7.4 to 8.4 and MySQL 5.7+ or MariaDB 10.4+, with and without a persistent object cache, single site and multisite. `SHA1()` exists on every one of them without extra build options; that is why it is used rather than `SHA2()`.

## In scope (the only files you may edit)

- `includes/Services/Session_Registry.php`: only `mutate_row()` and its docblock
- `tests/includes/Services/Test_Session_Registry_Concurrency.php`: add the tests below and the helpers they need

## Out of scope (do not edit)

- Every other method in `includes/Services/Session_Registry.php`, including `discard_oversized_row()`, the readers, `record()`, `revoke()` and `keep_only()`
- `includes/Services/Auth.php` and every other file in `includes/`
- The existing tests in `tests/includes/Services/Test_Session_Registry_Concurrency.php` (do not change them) and every other test file
- `.claude/specs/**` (the spec, `accept.sh` and both `mutate.sh` files are fixed)
- CI, composer, phpcs, phpstan and phpunit config; `CHANGELOG.md`; `readme.txt`

## Required code (exact shape; the mutation script targets these strings)

**1. The SELECT** built in `$select` at the top of `mutate_row()` becomes:

```php
"SELECT umeta_id, meta_value, SHA1(meta_value) AS meta_hash FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND CAST(meta_key AS BINARY) = %s ORDER BY umeta_id ASC",
```

The arguments are unchanged.

**2. The UPDATE** becomes:

```php
"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND meta_key = %s AND SHA1(meta_value) = %s",
$serialized,
(int) $rows[0]->umeta_id,
self::META_KEY,
$rows[0]->meta_hash
```

**3. Nothing else in the method changes:**
- the no-op line `if ( null === $new_value || $serialized === $stored_value )`, where `$stored_value` is still `$rows[0]->meta_value`;
- the insert path;
- the duplicate deletes;
- the cache deletes;
- the retry loop;
- the log line.

**4. Docblock.** In `mutate_row()`'s docblock, replace the clause saying the save happens only if the row "still holds, byte for byte, what that attempt read" with one that says:
- it saves only if the database's SHA-1 of the stored value still equals the SHA-1 that attempt read;
- the database computes both hashes over the column's own bytes, so the compare holds whatever the table's and the connection's character sets are;
- the UPDATE carries the row once.

At most four lines. Change no other comment.

## Tests (add to `Test_Session_Registry_Concurrency.php`, after the last existing test and before the private helpers)

**A latin1 table for one test.** A private helper `use_latin1_usermeta_values()`:
1. Runs, through `$wpdb->query()`:
   ```sql
   CREATE TEMPORARY TABLE {$wpdb->usermeta} (
     umeta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
     user_id bigint(20) unsigned NOT NULL DEFAULT 0,
     meta_key varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
     meta_value longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
     PRIMARY KEY (umeta_id), KEY user_id (user_id), KEY meta_key (meta_key(191)))
   ```
   This shadows the real table for this connection only, as an old site's latin1 table would look.
2. Asserts `$wpdb->last_error` is `''`.
3. Resets wpdb's column-charset caches, so wpdb sees the latin1 column. Use a bound closure, because the properties are protected:
   ```php
   \Closure::bind( function () { $this->col_meta = array(); $this->table_charset = array(); }, $wpdb, \wpdb::class )();
   ```
4. Asserts `$wpdb->get_col_charset( $wpdb->usermeta, 'meta_value' )` is `'latin1'`.
5. Calls `wp_cache_delete( $this->user->ID, 'user_meta' )`.
6. Sets a private flag property.

**Cleanup.** `tearDown()` gains one block, before `parent::tearDown()`, that runs only when the flag is set:
- `DROP TEMPORARY TABLE IF EXISTS {$wpdb->usermeta}`;
- the same cache reset;
- clear the flag.

This way no later test sees a latin1 column.

**Tests:**

1. `test_session_row_writes_on_a_latin1_table_with_a_non_ascii_user_agent_keep_every_session`
   - Arrange:
     - call `use_latin1_usermeta_values()`;
     - set `$_SERVER['HTTP_USER_AGENT'] = "Caf\xC3\xA9-Till/1.0"`;
     - `$first = $this->login()`;
     - arm `run_inside_next_row_write()` with an inner `$this->login()` into `$inner`.
   - Act: `$outer = $this->login()`.
   - Assert:
     - the access tokens of `$first`, `$outer` and `$inner` all validate;
     - `revoke_refresh_token()` of `$first`'s jti returns `true`;
     - `refresh_access_token( $first['refresh_token'] )` is a `WP_Error`.

   This test must FAIL on the current code. Record the failing line.

2. `test_session_row_writes_keep_a_four_byte_user_agent_exactly`
   - Arrange:
     - the real table, no shadowing;
     - `$_SERVER['HTTP_USER_AGENT'] = "Caf\xC3\xA9-Till \xF0\x9F\x8D\x95/1.0"`;
     - `$first = $this->login()`;
     - arm `run_inside_next_row_write()` with an inner `$this->login()`.
   - Act: `$outer = $this->login()`.
   - Assert:
     - all three access tokens validate;
     - revoking `$first` returns `true` and its refresh fails;
     - the `user_agent` of `$outer`'s entry in `Session_Registry` `entries()` is `assertSame()` to the exact user agent string.

     Get the registry the way the existing tests in this file do. If none does, use the same accessor `Test_Session_Registry.php` uses.

   This test may pass on the current code; it guards the hash compare on utf8mb4 with multi-byte and 4-byte characters. Report whether it passed before the fix.

Use Arrange / Act / Assert comments, `assertSame( expected, actual )`, and a one-line docblock on each test and helper in the file's style.

## Do not

- Change the row's key, location, serialisation or entry shape.
- Add a table, column, option or index.
- Add `GET_LOCK`, transactions, `FOR UPDATE`, `CONVERT()` or `COLLATE` clauses.
- Compute the hash in PHP. That is mutation C2, and it must stay red.
- Edit any existing test.
- Edit the spec, `accept.sh` or either `mutate.sh`.
- Create notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch another worktree.
- Start, stop or restart wp-env, or install anything. It is running for this worktree with dependencies installed.

## Pre-authorised actions

- Edit the two in-scope files.
- Run the acceptance commands.
- Commit once, as `fix(auth): the session-row compare matches on a database-side hash, so a latin1 table or a large row cannot block writes`, ending the message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013ukctiKeNUgfwvTdZtDt5d
  ```

## Budget

- Max non-test lines changed: 15
- Max total lines changed: 170

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable, from the worktree root)

First add the two tests and run the concurrency file on the unchanged registry. Record which fail and the failing lines. Test 1 must fail; if it passes, STOP and report under QUESTIONS.

Then make the code change; all of these must hold:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Session_Registry_Concurrency.php
WPENV='pnpm exec wp-env' bash .claude/specs/2026-11-12-session-row-cas/accept.sh
WPENV='pnpm exec wp-env' bash .claude/specs/2026-11-15-session-row-cas-charset/mutate.sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/Session_Registry.php tests/includes/Services/Test_Session_Registry_Concurrency.php
```

- The concurrency file passes: the 9 existing tests plus the 2 new ones.
- `accept.sh` exits 0.
- Every mutation in the new `mutate.sh` applies and turns at least one named test red.
  - C2 is expected to turn the latin1 test red. If C2 stays green, report it under QUESTIONS; do not change the test or the mutation.
  - No mutation may report "DID NOT APPLY".
- phpcs reports no errors on the two files.

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- FAILING-FIRST (the run before the fix, with the failing lines)
- ACCEPTANCE (paste the summary output, including every mutation's result)
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES
