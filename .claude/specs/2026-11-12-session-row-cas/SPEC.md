# Spec — session-row writes compare-and-swap, so overlapping requests cannot lose a session or resurrect a revoked one

## Goal

`includes/Services/Session_Registry.php` keeps every POS session of a user in one user-meta row,
`Session_Registry::META_KEY` (`_woocommerce_pos_refresh_tokens`). Five writers each read the row, change
it in PHP, and write it back with `update_user_meta()`, with no lock and no compare:

| writer | read | write |
|---|---|---|
| `record()` | `read_row()` :135 | `update_user_meta()` :196 |
| `refresh_activity()` | `read_row()` :302 | :309 |
| `record_access_expiry()` | `read_row()` :326 | :338 |
| `revoke()` | `get_user_meta()` :350 | :357 |
| `keep_only()` | `get_user_meta()` :725 | :745 |

When two requests for one user overlap, the later write puts back the row it read and the earlier
request's change is lost:
- a sign-in's session is lost, so its next request gets a 403 `woocommerce_pos_auth_session_revoked`;
- or a revoked session is written back and keeps working.

`tests/includes/Services/Test_Session_Registry_Concurrency.php` reproduces three such overlaps
deterministically: a `query` filter runs a second request just before the first request's UPDATE or
INSERT of the row. All three fail on this branch.

Make every one of the five writers a bounded compare-and-swap on the row, so all of those tests pass,
with the row's storage format, location and contents unchanged.

## Stakes

This is authentication for every POS device of thousands of stores, in a patch line. It is High tier
(`.ai/rules/stakes-tiers.mdc`). The failure that matters is a revoked session that keeps working, or a
working device signed out.

There is no schema change and no new option, table or column (`.ai/rules/db-tables.mdc`). Stores run
PHP 7.4 to 8.4, MySQL 5.7+ or MariaDB 10.4+, with and without a persistent object cache, single site
and multisite.

## In scope (the only files you may edit)

- `includes/Services/Session_Registry.php`
- `tests/includes/Services/Test_Session_Registry_Concurrency.php`
- `.claude/specs/2026-11-12-session-row-cas/SPEC.md`, `accept.sh` and `mutate.sh` (new; byte-for-byte copies)

## Out of scope (do not edit)

- `includes/Services/Auth.php` and every other file in `includes/`
- Every other test file. The existing session and auth tests must pass unchanged.
- `revoke_all()`, which deletes the row and stays as it is.
- `touch()`, the transients, `discard_oversized_row()`, `evict_oldest_sessions()`, `parse_user_agent()`
- CI, composer, phpcs, phpstan and phpunit config; `CHANGELOG.md`; `readme.txt`

## Context

- `read_row()` (:377) reads with `get_user_meta( …, true )`, which returns the value of the
  lowest-`umeta_id` row for the key, served from the `user_meta` object cache. It then drops entries
  that are not arrays or have no `expires`.
- `update_user_meta()` (WordPress `update_metadata()`):
  1. applies `wp_unslash()`, then `sanitize_meta()`, then `maybe_serialize()`;
  2. UPDATEs **every** row for that user and key;
  3. adds a row when there is none;
  4. deletes the `user_meta` cache for the user.
- `meta_value` is `longtext` under the table's `_ci` collation, so a plain `meta_value = %s` comparison
  is case-insensitive. Prior art for an exact compare is `includes/Payments/Contract/Ledger.php:186`
  (`CAST(meta_key AS BINARY) = %s`).
- Why not `update_user_meta( $id, $key, $new, $old )`: its `prev_value` compare is collation-insensitive,
  and WordPress skips the compare entirely when `$old` is `empty()`. An empty array is exactly what the
  row holds after the last session is revoked.
- Why not `GET_LOCK()`: a lock taken twice on one connection is re-entrant, so the in-process
  repro cannot prove it.
- `Logger` is `WCPOS\WooCommercePOS\Logger`, with `Logger::warning( string )`.

## Design (required)

**1. One private helper.**
`private function mutate_row( int $user_id, callable $change ): bool`

`$change` receives the stored value (the `maybe_unserialize()`d `meta_value` of the lowest-`umeta_id`
row, or `null` when there is no row). It returns the full new value to store, or `null` for "no write".
`mutate_row()` returns `true` when the row now reflects `$change`'s last answer (written, or no write
needed), and `false` when it gave up.

Each attempt, up to `MAX_ROW_WRITE_ATTEMPTS`:
1. **Read from the database, not the cache.** Run
   `SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND CAST(meta_key AS BINARY) = %s ORDER BY umeta_id ASC`.
   The first row is the row; any later rows are duplicates.
2. **Call `$change`.** It may run more than once, so it must not have side effects outside its return
   value and the variables it captures by reference, which each attempt overwrites.
3. **Serialise the new value as WordPress does:** `maybe_serialize( sanitize_meta( META_KEY, wp_unslash( $new ), 'user' ) )`.
4. **No-op.** If `$change` returned `null`, or the serialised value is byte-identical (`===`) to the stored
   `meta_value`, write nothing and return `true`. Write this as exactly
   `if ( null === $new_value || $serialized === $stored_value )`, using those variable names; the
   reviewer's mutation script targets that line.
   With no row, `$stored_value` is `null`, so a new value never matches it.
5. **No row.** `$wpdb->insert()` the row (`user_id`, `meta_key`, `meta_value`). Then re-read the rows
   for the key:
   - if the inserted `umeta_id` is the lowest, delete nothing and return `true`;
   - otherwise (another request inserted first), delete the row this call inserted and start the next
     attempt, which then applies `$change` to the winner's row.
6. **Row exists.** Run
   `UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)`,
   with the new value, the row's `umeta_id`, `META_KEY` and the stored `meta_value` read in step 1.
   - 1 row affected: delete the duplicate rows seen in step 1 (by `umeta_id`) and return `true`.
   - 0 rows affected: another request changed the row; start the next attempt.
   - `false` (a database error): stop and log.
7. **Cache.** After every write or delete, `wp_cache_delete( $user_id, 'user_meta' )`.

After the last attempt, or on a database error, call `Logger::warning()` with the user id and the
attempt count, never the row's contents, and return `false`.

`MAX_ROW_WRITE_ATTEMPTS = 5` is a class constant whose comment explains the value: each retry only
follows a competing write that succeeded, so five means five other writes landed for one user within
one request.

**2. The five writers go through it**, each keeping its current behaviour and return contract:
- **`record()`**: `discard_oversized_row()` stays first, outside the helper. The callable applies the
  `read_row()` entry filter to the stored value, then the expiry cleanup, the new entry and
  `evict_oldest_sessions()`. It captures `$evicted` by reference; the value from the attempt that won is
  returned. Session metadata (time, context and device info) is computed once, before the helper.
- **`refresh_activity()`**: `discard_oversized_row()` stays first. If the entry is missing, return `null`
  and the method returns `false`. Otherwise the method returns `true` when the helper returns `true`.
- **`record_access_expiry()`**:
  - early returns as today;
  - a missing entry gives `false`;
  - an expiry that is not later gives `null` and `true`;
  - otherwise the method returns the helper's result.
- **`revoke()`**: the callable sees the raw stored value, as today.
  - A non-array value, or no entry for `$jti`, gives `null`, and the method returns `false`.
  - Otherwise it unsets the entry. The method calls `forget_session_activity( $jti )` and returns
    `true` only after the helper returns `true`.
- **`keep_only()`**: the callable sees the raw stored value.
  - A non-array value gives `null` and `false`.
  - Otherwise it keeps only `$current_jti`. The JTIs it drops are captured by reference, and
    `forget_session_activity()` runs for those of the winning attempt, after the helper returns `true`.

  The method returns the helper's result.

  Changed: today `keep_only()` returns `false` when the row was already only the current session,
  because `update_user_meta()` reports no change. It now returns `true`.
  `Auth::revoke_all_sessions_except()` passes it to the REST handler at `includes/API/V1/Auth.php:410`,
  so "sign out other devices" with no other devices now answers `success: true` instead of a 500
  "Failed to revoke sessions.". That is a fix; the response shape is unchanged. List this under BEHAVIOUR
  CHANGES.

**3. Readers stay on the cache.** `read_row()`, `entries()`, `entry()`, `list()` and `is_live()` keep
`get_user_meta()`. Writes delete the cache, as `update_user_meta()` did.

**4. Hooks.** The helper does not fire the `update_user_metadata` / `add_user_metadata` filters or the
`update_user_meta` / `updated_user_meta` / `added_user_meta` actions for this one internal key. Nothing
in Free or Pro hooks them for it. Say so in one sentence in the helper's docblock.

## Tests (add to `Test_Session_Registry_Concurrency.php`)

The three existing tests stay as they are and must pass. Add:
1. `test_session_row_two_overlapping_first_logins_keep_both_sessions_in_one_row`. There is no row yet,
   and an inner login runs inside the outer login's INSERT. Both access tokens validate. Afterwards
   exactly one database row exists for the key; count with `$wpdb->get_var()`.
2. `test_session_row_concurrent_change_differing_only_in_case_survives`.
   - Set `$_SERVER['HTTP_USER_AGENT'] = 'lowercase-agent'` (restore it in tearDown) and log in.
   - Inside the next row write, run a direct `$wpdb->query()` UPDATE that replaces the stored value with
     the same serialised string where `lowercase-agent` is `LOWERCASE-AGENT` (same length).
   - Act: a second login.
   - Assert: the first session's `user_agent` in `entries()` is `LOWERCASE-AGENT`, and the second session
     exists.
3. `test_session_row_write_reads_the_database_not_a_stale_cache`.
   - Log in twice.
   - Put a stale copy of the user's meta cache in place: `wp_cache_set( $user_id, <the cache array with
     the row holding only the first session>, 'user_meta' )`.
   - A third login.
   - Assert: after `wp_cache_delete()`, all three sessions are in `entries()`.
4. `test_session_row_write_gives_up_after_bounded_attempts_and_returns_false`.
   - Arrange: a `query` filter that, before every UPDATE of the row, changes the row through a direct
     `$wpdb` UPDATE of its `last_active` (guard against recursing into itself).
   - Act: `revoke()` an existing session through `Auth::revoke_refresh_token()`.
   - Assert: it returns `false`; the filter saw exactly `MAX_ROW_WRITE_ATTEMPTS` UPDATEs of the row; the
     session is still in the row.

   Read the constant via reflection, or make it `public` with a comment.
5. `test_session_row_unchanged_write_returns_true_without_an_update`. A single session, then
   `Auth::revoke_all_sessions_except()` for that session. Assert it returns `true` and that no UPDATE of the
   row reached `query`.

Arrange / Act / Assert comments, and `assertSame( expected, actual )`.

## Do not

- Change the row's key, location, serialisation or entry shape.
- Add a table, column, option or index.
- Add `GET_LOCK`, transactions or `FOR UPDATE`.
- Touch `Auth.php`, `revoke_all()`, `discard_oversized_row()` or the readers.
- Fire meta hooks by hand.
- Log row contents, user agents, IPs or tokens.
- Add retries with sleeps.
- Refactor outside the five writers and the helper.
- Create notes or scratch files in the repo.
- Push, open a PR, comment on GitHub, or touch another worktree.

## Pre-authorised actions

**Branch and spec copies**
- In your own worktree: `git checkout -b fix/session-row-cas <BASE>`, where `<BASE>` is the SHA given in
  your launch prompt (the head of `fix/session-registry-race`).
- Copy the three spec files from `/Users/claude/.claude/jobs/77f9299d/tmp/spec-session-cas/` into
  `.claude/specs/2026-11-12-session-row-cas/`.
- Commit them alone first: `docs(spec): session-row writes compare-and-swap`.

**Test environment**
- Create `.wp-env.override.json` (gitignored):
  `{"plugins":["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip","."],"port":8997,"testsPort":8998,"mappings":{"wp-content/plugins/woocommerce-pos":"."}}`.
- Export
  `WPENV=/Users/claude/Projects/woocommerce-pos/.claude/worktrees/file-integrity-stale-version/node_modules/.bin/wp-env`.
- Run `~/.claude/bin/wait-test-slot.sh` as its own command, then `"$WPENV" start`.
- Then `"$WPENV" run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- composer install --no-interaction`.
- No local composer or phpunit.

**Checks**
- Run `accept.sh`, `mutate.sh` and phpcs on the two PHP files. Run `wait-test-slot.sh` as its own
  command before each.
- Everything runs in the foreground.

**Finish**
- Commit as one commit: `fix(auth): overlapping session-row writes no longer lose a sign-in or resurrect a sign-out`.
  End the message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01Pm6LMFZvjFgQvYM2R7xpL5
  ```
- Then `"$WPENV" stop`.

## Budget

- Max non-test lines changed: 160
- Max total lines changed: 500

If you are about to exceed either, STOP and report. Never strip tests or comments to fit.

Wall-clock limit: three hours. At the limit, stop with the worktree committed or clean and report
partial state.

## Acceptance criteria (from the worktree root, `WPENV` exported)

```sh
.claude/specs/2026-11-12-session-row-cas/accept.sh
.claude/specs/2026-11-12-session-row-cas/mutate.sh
```

- `accept.sh` exits 0: the concurrency file (8 tests), every listed session and auth suite, and the
  multisite scope suite pass.
- `mutate.sh` shows every mutation caught by at least one named failing test, and none "DID NOT APPLY".
- Before the fix commit, the 5 new tests are run on the unfixed registry: say which fail and with what
  message. Tests 1–3 must fail there.

## Output expected

End with:
- STATUS
- CHANGED (files, lines)
- ACCEPTANCE (paste summary output)
- FAILING-FIRST (the unfixed run)
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES

If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
