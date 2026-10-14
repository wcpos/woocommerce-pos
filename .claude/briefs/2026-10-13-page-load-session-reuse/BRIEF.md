# Brief: a POS page load reuses the browser's live session instead of revoking it

Read this whole file, then implement it in this checkout. Follow the existing code style
(`.phpcs.xml.dist`, WordPress conventions, `Logger` not `error_log`).

## The defect

`Auth::get_user_data( $user, true )` (`includes/Services/Auth.php`) is called on every load of the
POS page (`includes/Templates/Frontend.php::get_template`). It calls
`cleanup_previous_web_session()`, which REVOKES the session named by the browser's
`wcpos_web_session_jti` cookie, then mints a brand-new refresh+access pair and sets the cookie to
the new session.

Since 1.10.21 `validate_token()` refuses any access token whose session is no longer live
(`Session_Registry::is_live`). So any request for the POS page that carries the browser's cookies
but never runs the app (a prefetch, a prerender, a browser extension or security suite re-fetching
the URL, a `fetch('/pos/')`) kills the session the OPEN tab is using. The tab's next request is
refused `woocommerce_pos_auth_session_revoked`, its refresh is refused too (same jti), and it is
dead until the page is reloaded. Reproduced live on 2026-10-13: one
`fetch('/pos/', {credentials:'include'})` from DevTools revoked the open tab's session.

The popup (`includes/Templates/Auth.php`) has the same shape: a GET of the popup URL without
`auth_session` always generates a new value and overwrites the transient, so a second GET of the
same URL invalidates the form the cashier is looking at ("Invalid auth session").

## Required behaviour

### 1. `Auth::get_user_data( $user, true )` reuses a live cookie session

- Read the `wcpos_web_session_jti` cookie (sanitize as `cleanup_previous_web_session` does).
- If that jti is live for THIS user (`$this->sessions->is_live( $user->ID, $jti )`) and the
  stored row's `expires` is more than `WEB_SESSION_REUSE_MIN_REMAINING` seconds away
  (new class constant, `DAY_IN_SECONDS`, with a comment: a session about to expire is replaced
  so the page is not handed a refresh token that dies within the hour), then REUSE it:
  - Build a refresh JWT with the SAME `jti`, `iat` = now, `exp` = the stored row's `expires`,
    the same `iss`/`type`/`data.user.id` shape and the same
    `woocommerce_pos_jwt_refresh_token_before_sign` filter as `generate_refresh_token()`.
    Factor the token-array build so `generate_refresh_token()` and the reissue share it; do NOT
    duplicate the array literal.
  - Do NOT call `Session_Registry::record()` for a reused session (the row entry already
    exists). Call `$this->sessions->touch( $user->ID, $jti )`.
  - Mint a new access token linked to that jti with `generate_access_token_data()`.
  - Re-set the cookie with the same jti (the existing `set_web_session_cookie()` path is fine).
- Otherwise (no cookie, unknown jti, another user's jti, expired or nearly expired): mint a new
  pair exactly as today, and set the cookie. Do NOT revoke anything on a page load: a dead
  session needs no revoking, another user's session is not this user's to revoke, and a nearly
  expired one expires on its own.
- Remove the `cleanup_previous_web_session()` call from `get_user_data()`. Keep the method: the
  `wp_logout` hook (`revoke_web_session_on_logout`) still uses it, and that behaviour is correct
  (logging out of WordPress ends this browser's POS session).
- `set_web_session_cookie()`: return early when `headers_sent()` is true. Production always
  reaches it before output; the guard is what lets PHPUnit exercise the web-frontend path without
  "headers already sent" warnings.

### 2. Popup: a GET without `auth_session` reuses an existing transient

In `Templates\Auth::validate_or_create_auth_session()`: when `$this->auth_session` is empty, first
`get_transient( $session_key )`; if a value exists, adopt it (`$this->auth_session = $stored`)
and return true without re-setting the transient. Only generate and store a new value when none
exists. A second GET of the same popup URL inside the ten-minute window then renders the same
hidden value the first page carries, and the first form still submits.

### 3. Cache headers

- `Templates\Auth::get_template()`: call `nocache_headers()` before including the template (guard
  with `headers_sent()`). The popup page currently ships with only `max-age=0`.
- `Templates\Frontend::no_cache()`: also call `nocache_headers()` (guarded the same way).

## Tests (PHPUnit, `tests/includes/...`, `Test_*.php`, same style as the neighbours)

In `tests/includes/Services/Test_Auth_Service.php`:
- Replace the skipped `test_direct_get_user_data_web_frontend` with a real test: with no cookie,
  `get_user_data( $user, true )` returns tokens and the user's session row has exactly one entry.
- `test_web_frontend_page_load_reuses_live_cookie_session`: mint a pair with
  `generate_token_pair( $user )`, decode the refresh token to get its jti, set
  `$_COOKIE['wcpos_web_session_jti']` to it, call `get_user_data( $user, true )`; assert the
  returned refresh token decodes to the SAME jti, the returned access token passes
  `validate_token()`, the ORIGINAL access token still passes `validate_token()`, and the row still
  has exactly one entry.
- `test_web_frontend_page_load_mints_when_cookie_session_is_unknown`: cookie set to a random
  uuid; a new session is minted; the row has one entry; no error.
- `test_web_frontend_page_load_mints_when_cookie_session_belongs_to_another_user`: cookie names
  a live session of a second user; the first user gets a new session and the second user's
  session is still live (`is_live` true).
- `test_web_frontend_page_load_replaces_a_nearly_expired_cookie_session`: write the stored
  row's `expires` for the cookie's jti to `time() + HOUR_IN_SECONDS`; a different jti comes back.
- Clean `$_COOKIE` in `tearDown`.

In `tests/includes/Templates/` (new file, e.g. `Test_Auth_Session_Reuse.php`, constructing
`Templates\Auth` with `$_REQUEST['redirect_uri']` and `$_REQUEST['state']` set, as
`Test_Auth_Redirect_Uri.php` does via reflection where needed):
- `test_second_get_without_auth_session_reuses_the_stored_value`: two instances, same state and
  redirect_uri, no `auth_session`; `get_auth_session()` returns the same non-empty value and
  `get_error()` is empty on both.
- `test_post_with_the_first_value_after_a_second_get_is_still_valid`: after the two GETs above,
  a third instance with `$_REQUEST['auth_session']` = the first value has an empty `get_error()`.

Existing tests that pin the old revoke-on-page-load behaviour must be updated to the new
behaviour, not deleted. `Test_Session_Revocation_On_Account_Events.php` (logout hook) must still
pass unchanged.

## Verification you can run here

- `php -l` on every changed PHP file.
- `vendor/bin/phpcs --standard=.phpcs.xml.dist <changed files>` and fix what it reports.
- Do NOT start wp-env or Docker in this checkout: another stack is already running on this
  machine and the Docker VM cannot hold two. PHPUnit runs in CI after the orchestrator pushes.
  Write the tests carefully against the existing helpers; read the neighbouring tests first.

## Stakes

Auth, High tier. A regression here logs a cashier out, or keeps a session alive that a page load
used to end. It cannot lose or corrupt order data. Treat "a session row is read and written
without a lock" as an accepted, pre-existing condition, not a defect: do not add locking,
transactions, retries, or new validation layers.

## Out of scope

- No new REST endpoints, options, settings, filters or env vars.
- No change to `refresh_access_token()`, the blacklist, `evict_oldest_sessions()`, the logout or
  password hooks, or `get_redirect_data()`.
- No change to how the client reads tokens; no JS.
- No plan files, no README changes, no changelog edit.

## Budget

Scope permission: up to 90 changed non-test source lines and 320 changed lines in total
(tests included). If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work into more files does not raise this budget.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test
was already failing before you started. A test you wrote failing first is expected, not a
surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR. Do not commit; the orchestrator
commits. When done, write a short report to `.claude/briefs/2026-10-13-page-load-session-reuse/REPORT.md`:
files changed, line counts, what you ran and its exit status, anything you were unsure about.
