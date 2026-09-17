# Lift the Session Registry out of the Auth module

Branch `codex/session-registry`, cut from `origin/main` (2de2bc37). PR targets `main`.
Candidate 14 of the 2026-09-17 architecture review (tracking issue #2007, closed; Paul picked
this card on 2026-09-18). **High-stakes area** (authentication, `.ai/rules/stakes-tiers.mdc`):
this is a pure move with no behaviour change, no wire change, no new option, no change to what
is stored or when. Every ordering rule below is load-bearing and was settled by a fix; keep each
one where the spec says and keep its comment with it.

## The problem, in the code as it is

`includes/Services/Auth.php` (1,556 lines) is two modules in one class. Lines 700–1254 and the
helpers around them are a **session registry**: the per-user meta row
`_woocommerce_pos_refresh_tokens` (one entry per refresh-token JTI: `expires`, `created`,
`last_active`, `ip_address`, `user_agent`, `device_info`, optional `access_expires`), the
activity transient `wcpos_session_seen_<jti>` with its five-minute throttle, the per-user cap
with idle-aware eviction, the byte ceiling that discards an unreadable row, and the user-agent
parser that fills `device_info`. The rest of the class is **token policy**: mint, validate,
refresh, blacklist, cookies. Four consecutive August fixes (07b2948c, 13e73ad7, 4aafdf9e,
a1c259f0) were all about that one row. Three constants are public on `Auth` only so tests can
see them, the tests reach the row through 28 direct `get_user_meta` reads and ten transient
pokes, and `API\V1\Auth::get_all_users_sessions()` runs raw SQL against the meta key because no
method offers "which users have sessions".

## Goal

A new `Services\Session_Registry` owns the row, the activity transient, the cap/eviction, the
byte ceiling and the user-agent parser. `Auth` keeps mint / validate / refresh / blacklist /
cookies and holds one registry instance. Every public method on `Auth` keeps its name and
signature and delegates; `API\V1\Auth` asks the registry for the user list instead of running
SQL. Callers outside `Auth` never construct the registry: `Auth::instance()->sessions()`
returns it.

## The module

`includes/Services/Session_Registry.php`, `final class Session_Registry` in
`WCPOS\WooCommercePOS\Services`. Style: match `Auth` (docblocks, `\is_array`, WordPress
coding standards). Constants:

- `META_KEY = '_woocommerce_pos_refresh_tokens'` (public; the one place the key is written).
- `MAX_SESSIONS_PER_USER = 200`, `SESSION_EVICTION_IDLE_SECONDS = 7 * DAY_IN_SECONDS`,
  `MAX_SESSIONS_ROW_BYTES = 6291456` (public, moved from `Auth` with their docblocks).
- `SESSION_ACTIVITY_REFRESH_SECONDS`, `SESSION_SEEN_TRANSIENT_PREFIX` (private, moved).

`Auth` keeps `MAX_SESSIONS_PER_USER`, `SESSION_EVICTION_IDLE_SECONDS` and
`MAX_SESSIONS_ROW_BYTES` as public aliases (`= Session_Registry::…`) with a one-line
`@deprecated` pointer, so nothing that reads them breaks.

Construction: `public function __construct( callable $access_token_expire )` — the policy
`Auth::get_access_token_expire( int $issued_at ): int` (a filterable value, so it stays on
`Auth`); the registry needs it only for the legacy horizon fallback in eviction. `Auth::__construct`
builds `$this->sessions = new Session_Registry( function ( int $t ): int { return $this->get_access_token_expire( $t ); } )`
and exposes `public function sessions(): Session_Registry`.

Public surface (each is today's code moved, with its comments; the names are new, the bodies
are not):

| Registry method | Moved from `Auth` | Notes |
|---|---|---|
| `record( int $user_id, string $jti, int $expires, Session_Context $context ): array` | `store_refresh_token_jti()` + `evict_oldest_sessions()` + `session_last_seen()` + `session_row_last_seen()` + `parse_user_agent()` | Byte-ceiling guard first, prune expired, build the entry, cap. **Returns the evicted entries** (`jti => token_data`) instead of blacklisting them; `Auth::generate_refresh_token()` blacklists each returned entry whose `access_token_horizon()` is still ahead, exactly as `evict_oldest_sessions()` does today, and the registry has already forgotten their activity transients. `access_token_horizon()` stays on `Auth` (token lifetime is policy). Keep the "never evict a live session" rule and its comment inside the registry. |
| `entries( int $user_id ): array` | the `get_user_meta` read that every method starts with | Raw `jti => token_data` map, `array()` when absent or not an array. **No** byte-ceiling guard here (a plain read). |
| `entry( int $user_id, string $jti ): array` | the `$refresh_tokens[ $jti ] ?? array()` lookups | For `revoke_session_with_blacklist()`'s TTL calculation. |
| `list( int $user_id ): array` | `get_user_sessions()` | The public session shape (expired skipped, sorted by `last_active` desc). |
| `is_live( int $user_id, string $jti ): bool` | `is_refresh_token_valid()` | Entry present and `expires` in the future. |
| `touch( int $user_id, string $jti ): void` | `touch_session_activity()` | The throttled transient. Never touches the row; keep the comment that says so. |
| `refresh_activity( int $user_id, string $jti ): bool` | `update_session_activity()` | Byte-ceiling guard first, then the row write. |
| `record_access_expiry( int $user_id, string $jti, int $access_expires ): bool` | `store_access_token_expiry()` | Only ever moves the stored value forward. |
| `revoke( int $user_id, string $jti ): bool` | `revoke_refresh_token()` | Removes the entry and forgets its activity. |
| `revoke_all( int $user_id ): bool` | the `delete_user_meta` in `revoke_all_refresh_tokens()` plus forgetting every activity transient | `Auth::revoke_all_refresh_tokens()` blacklists each entry first (from `entries()`), then calls this. |
| `keep_only( int $user_id, string $jti ): bool` | the `array_filter` + `update_user_meta` in `revoke_all_sessions_except()` plus forgetting the others' activity | `Auth::revoke_all_sessions_except()` blacklists the others first, then calls this. |
| `users_with_sessions(): array` | the raw SQL in `API\V1\Auth::get_all_users_sessions()` | `int[]` of user ids that have the row, via `$wpdb->prepare` with `META_KEY`. |
| private `discard_oversized_row( int $user_id ): void` | `discard_oversized_session_row()` | Keep the `#1776` docblock and the `wp_cache_delete` line. Called from `record()` and `refresh_activity()`, and by `Auth::refresh_access_token()` through the public wrapper below. |
| `guard_row( int $user_id ): void` | the call at `Auth::refresh_access_token():607` | Public wrapper over the guard so the refresh path keeps guarding **before** `is_live()`, as its comment explains. Do not fold the guard into `is_live()` / `entries()`: validating an access token must never touch the row, and the refresh path's comment says why the guard runs where it does. |

`Auth` after the move: `get_user_sessions()`, `revoke_session()`, `revoke_refresh_token()`,
`update_session_activity()`, `revoke_all_sessions_except()`, `revoke_all_refresh_tokens()`,
`revoke_session_with_blacklist()`, `can_manage_user_sessions()` (unchanged), `blacklist_token()`
(unchanged), `generate_refresh_token()`, `refresh_access_token()`, `validate_token()` and
`generate_access_token_data()` all keep their signatures and call the registry. Delete from
`Auth` everything the table moves. `cleanup_previous_web_session()` and the cookie stay on `Auth`.

`API\V1\Auth::get_all_users_sessions()`: replace the `$wpdb->get_col` with
`$auth_service->sessions()->users_with_sessions()`; drop `global $wpdb` if nothing else in the
method uses it.

## Ordering rules that must survive (read each site before moving it)

1. `refresh_access_token()`: `guard_row()` → `is_live()` → user lookup → `refresh_activity()` →
   mint. The guard comment ("a refresh loads the whole session row … validating an ACCESS
   token needs no such guard") stays at the call site.
2. `validate_token()` for an access token: blacklist checks, then `touch()`. No row read on
   this path. `test_validating_an_access_token_does_not_write_the_session_row` pins it.
3. `record()`: guard → read → prune expired → add entry → cap. Eviction candidates are ordered
   by row activity then insertion index; a candidate seen within the idle window (row **or**
   transient) is never evicted; the newly stored JTI is never evicted.
4. Blacklisting on eviction is bounded to the access-token horizon and skipped for a dead
   session; on `revoke_all*` it uses `get_access_token_blacklist_ttl()`. Both stay in `Auth`.
5. The byte-ceiling guard runs before the first row read on login, refresh and the public
   `update_session_activity()`, and after deleting it clears the user meta cache.

## Behavioural changes

None. If moving a piece would force one, stop and report rather than adapt.

## Tests

- New `tests/includes/Services/Test_Session_Registry.php`: move, one to one, every
  `Test_Auth_Service` case whose subject is the session row, its cap, its activity transient or
  its byte ceiling — `test_session_tracking`, `test_multiple_sessions`, `test_device_info_parsing`,
  `test_public_update_session_activity`, `test_direct_get_user_sessions_empty`,
  `test_direct_update_session_activity_nonexistent`, the `test_store_session_*` cases
  (1227–1441), and everything from `test_store_refresh_token_beyond_cap_*` through
  `test_login_keeps_a_session_row_under_the_byte_ceiling` (1441–1812). Drive them through
  `Auth::instance()` where the case exercises login/refresh/validate (those paths are the
  contract) and through `Auth::instance()->sessions()` where the case only inspects state.
  Replace direct `get_user_meta( $id, '_woocommerce_pos_refresh_tokens', true )` assertions
  with `->sessions()->entries( $id )`; replace `Auth::MAX_*` with `Session_Registry::MAX_*`.
  Cases that deliberately plant a raw row (the byte-ceiling ones write an oversized value)
  keep writing raw meta — that is the point of those cases — but name the key through
  `Session_Registry::META_KEY`. Keep every assertion.
- Cases that stay in `Test_Auth_Service` (mint/validate/refresh/blacklist/revoke-with-blacklist,
  `test_revoke_*`, `test_two_*`, `test_expired_*`, capabilities) are untouched except for the
  same constant/key substitutions where they read the row.
- `tests/includes/API/Test_Auth_API.php` (`test_get_all_users_sessions` covers the SQL
  replacement) and `tests/includes/Test_Uninstall.php` (plants the meta key by literal; leave
  it, uninstall must not depend on the class) must stay green unchanged.

Run nothing through local PHPUnit (the sandbox has no Docker and no wp-env). Paul's agent runs
`Test_Session_Registry`, `Test_Auth_Service`, `Test_Auth_API`, `Test_Uninstall` and then the
full suite on the wp-env runner after you finish. Conventions: Arrange / Act / Assert,
`assertSame` for new lines, `( expected, actual )`.

## Budget and rules

- NET production change: about +40 lines (the registry gains a class header, a constructor and
  method docblocks; `Auth` loses the same bodies; `API\V1\Auth` loses the SQL). Tests: about
  +40 net (a new file header). Report the real numbers per file.
- No new options, env vars, filters, hooks, or parameters on existing public methods. No
  `error_log()`. The meta key, transient names, TTLs and every constant value are unchanged.
- WordPress coding standards: run the host linter on every PHP file you change —
  `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`
  (readable from the sandbox; the worktree has no vendor). Zero errors before you finish.
- After the move, grep `includes/` and `tests/` for `'_woocommerce_pos_refresh_tokens'` (the
  literal): the only remaining hits must be `Session_Registry::META_KEY`'s definition,
  `uninstall.php` and `Test_Uninstall.php`.
- Git is READABLE from the sandbox; writes are not. Do not commit; Paul's agent commits with
  explicit paths.
- Proceed; do not stop to ask. If a stated assumption is wrong, make the smallest reasonable
  choice, record it in your final report, and continue.

## Final report

List: the production diff summary with net line counts per file; which test cases moved and
which stayed; phpcs result per file; every place the ordering rules above forced a choice;
anything you chose differently from this spec and why.
