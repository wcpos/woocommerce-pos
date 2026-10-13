# Verdict — auth, POS sessions, permissions, REST gate, settings

Read on Free `837e985f` (origin/next) and Pro `05f06c4`. Lane check: `git rev-list --left-right --count origin/main...origin/next` = `0 458`, so `next` is live. **D4 and D8 are on `origin/main` too, at the same lines** (`git show origin/main:includes/Services/Auth.php` :805-823, `…/Form_Handler.php` :138-168). They are fixes to the released line, so they go to `main` and reach `next` by the normal sync. Pro grep for every Free symbol below covered `includes/` and `tests/`. The only hit was `tests/includes/API/Test_Pro_POS_Endpoint_Permissions_Matrix.php:11,74`, which uses `Auth::instance()` to mint tokens. Pro has no auth coupling in `includes/`.

## 1. One module for the POS session lifecycle (Part B, cookie adapter) — VERDICT: DO-RESHAPED
Strength (explorer): Strong · Risk tier: High (JWT issue/revoke)
- The August spec's three rulings still hold on this tree:
  - Revoke-before-issue: `Services/Auth.php:564-566` revokes the old session, `:568` mints, `:569-571` returns `[]` on failure.
  - Silent cookie failure: `:1022-1026`.
  - `$_REQUEST`, not `$_GET`: `Session_Context.php:98`.
- The silent-failure branch is reachable only through a `woocommerce_pos_jwt_refresh_token_before_sign` filter that strips `jti`. An invalid refresh token already fails at `:520-523`, before `:575` runs. It degrades, it does not guard; keep it.
- New on this tree: the token-failure path also leaves an orphan session row. `generate_refresh_token` records the session at `:483` before `generate_token_pair` fails at `:520`. So a failure revokes the old session and keeps an unusable new one.
- The skip is still there (`Test_Auth_Service.php:977-979`). Nothing in `tests/` calls `cleanup_previous_web_session`, `set_web_session_cookie` or `wcpos_web_session_jti`.
- `@runInSeparateProcess` has precedent (`Test_Relay_Integration.php:153`), but it does not help. PHPUnit has already printed output, so `setcookie()` warns, and `.phpunit.xml.dist:7` turns warnings into exceptions. The injected cookie adapter is the only way to test this, which settles August Q2 against the separate-process route.
- The explorer is right that Auth's revoke, list and activity methods are pass-throughs (`:690-692, 733-735, 745-747, 794-796`). The real depth is the blacklist policy (`:826-988`). It is redundant since 03a6e47b made `Session_Registry::is_live` authoritative (`:292-305`), because every in-tree `blacklist_token` call passes a refresh JTI (`:498, 719, 779, 868`). 03a6e47b kept the transient on purpose as a fast path, so removing it is Paul's call.

Scope (`wc -l` regions): `Services/Auth.php`:
- `get_user_data` 552-597 (46 lines)
- revoke cluster 682-872 (191)
- blacklist and TTL helpers 874-988 (115)
- web session 990-1046 (57)

`Templates/Frontend.php:83-87`.

The work splits into three PRs:
- **PR-1:** a `Web_Session_Cookie` adapter (`read(): ?string`, `write( string $jti, int $expires ): void`) injected through an optional `Auth::__construct` parameter, plus characterization tests. About 60 non-test lines and 250 total.
- **PR-2:** mint-then-revoke, plus deleting the orphan row on failure. About 25 non-test lines and 120 total.
- **PR-3 (optional):** drop the blacklist. About -150 non-test lines and -250 total.

Pro impact: none (grepped `includes/`, `tests/` for `get_user_data`, `blacklist_token`, `revoke_*`, `Session_`).

Tests:
- Pinning today: `Test_Auth_Service`, `Test_Session_Registry`, `Test_Session_Revocation_On_Account_Events`, `Test_Auth_Revocation_Survives_Cache_Eviction`, `Test_Auth_Refresh_Status`, `Test_Auth_Refusal_Reason`.
- Add `tests/includes/Services/Test_Auth_Web_Session.php`:
  - `test_get_user_data_web_frontend_token_failure_revokes_previous_session`. Writable today with no seam: a refresh-sign filter sets `type` to `'x'`. It pins the bug and gets inverted in PR-2.
  - `…_cookie_names_previous_session_revokes_it`
  - `…_no_cookie_revokes_nothing`
  - `…_empty_cookie_revokes_nothing`
  - `…_writes_cookie_with_new_jti_and_refresh_expiry`
  - `…_refresh_payload_without_jti_writes_no_cookie`
- Delete the skipped `test_direct_get_user_data_web_frontend` (replaced). Blacklist assertions in the five files above go only if PR-3 lands.

ADR / CONTEXT.md: no conflicts. Add these terms:
> **POS Session**: One refresh-token grant for one user on one device: an entry in the user's `_woocommerce_pos_refresh_tokens` row, keyed by its refresh JTI. Access tokens carry that JTI as `refresh_jti`. The session is live while its entry exists and is unexpired, and ending it ends every access token minted against it. _Avoid_: refresh token (for the row), login, device.
>
> **Web Session**: The POS Session a browser holds for the web POS. It is re-minted on every page load and named by the `wcpos_web_session_jti` cookie, so the next load can end the previous one. _Avoid_: cookie session, WP session.

Sequencing: PR-1 first (tests before behaviour). PR-2 needs August Q1 answered. Each is one PR.
Tonight? **NO.** August Q1 (mint-then-revoke) and the blacklist removal are both open High-tier rulings, and PR-1 adds a production seam before characterization, which Paul was asked to sanction and has not.

## 2. One request-identity resolver instead of five header readers — VERDICT: DO-RESHAPED (dedup now, resolver deferred)
Strength (explorer): Strong · Risk tier: High (security boundary)
- The real readers:
  - `Services/Auth.php:177-194`, used by `authenticate_request` `:152-165` from `Init.php:391-403` and `API.php:595-606`
  - a byte-identical copy at `Core_Order_Audit_Guard.php:305-319`, whose docblock `:300` cites a non-existent `Init::get_auth_header_early()`
  - `V1/Auth.php:555-590`, which uses `get_param`, so it also reads POST bodies
  - `Form_Handler.php:73-88` (`wcpos_jwt`/`token`, a different credential)
  - `Echo_Probe.php:52`, a diagnostic that only measures length
- Double decode confirmed by trace. When `determine_current_user` runs before `rest_api_init`, `API::$is_auth_checked` stays false. `rest_authentication_errors` `:210` then calls `ensure_authenticated_via_wcpos` → `authenticate` → a second `validate_token`. The audit guard decodes a third time on core write routes (`:289`). Each decode costs 2 blacklist `get_transient` calls plus `is_live` plus `touch` (`Auth.php:274-307`). The cost is small and the clarity cost is real.
- A memoized request-identity record (user id, provenance, error) would let the audit guard read provenance instead of re-validating. But `determine_current_user` ordering is pinned as a security boundary (`Test_Init_Hook_Wiring.php:185-210, 412-423`), and the WC #26847 fallback (`API.php:144-146, 209-213`) has no test that reproduces the cache case. Don't build it unsupervised.

Scope: the dedup is `Core_Order_Audit_Guard.php:297-319` (23 lines) replaced by `Auth::instance()->get_auth_header()`. Net about -20 non-test lines and +30 test lines, under 80 total. The full resolver touches `API.php:47-69, 132-282, 588-606`, `Init.php:375-403`, the audit guard `:36-61, 257-319` and `V1/Auth.php:548-590`, for about 250 lines changed.
Pro impact: none.
Tests: `Test_Core_Order_Audit_Guard` (header path only, `:49-55`). Add `test_audit_guard_jwt_query_param_order_update_with_audit_key_returns_forbidden` to pin the `$_GET['authorization']` branch the dedup must keep.
ADR / CONTEXT.md: none.
Sequencing: the dedup is independent and can go to `next`. The resolver waits on D5's product ruling, because which channels count is part of its interface.
Tonight? **YES for the dedup only** (spec-audit-guard-header-reader-dedup.md). It changes no behaviour, the copy is byte-identical, and a new test pins the one unpinned branch.

## 3. Settings policy outside the Section — VERDICT: DO-RESHAPED (gateway rule only)
Strength (explorer): Strong for the gateway rule · Risk tier: High (payment gateway availability)
- The rule is installed only in `V1/Settings.php:115-117` and applied at `:413-424`, with no array guard on `['gateways']` or `['default_gateway']`.
- Trace of the business defect: `Gateways.php:91` → `wcpos_get_settings` (`wcpos-functions.php:227-230`) → `Settings::get_settings` `:132-138` → `Payment_Gateways_Section::read` `:151` → `Abstract_Section::read_raw` `:167` → `get_option`, which is filtered only if `V1\Settings` was constructed. A wcpos-checkout template request never fires `rest_api_init`, so a stored `bacs.enabled = true` is offered there. `Admin/Orders/Single_Order.php:39-45` sees it too.
- How a free site gets that value: Pro deactivated after configuring gateways, or a direct `POST /settings/payment_gateways`. `write( merge( read(), patch ) )` stores the patch.
- Pro lifts the rule at `Init.php:44, 73-77` with `pre_option_` + `remove_all_filters`. That is unconditional, with no license check (Pro `Activator.php:48`), so an unlicensed Pro install also lifts it. That is a product question.
- Where the rule belongs: `Payment_Gateways_Section` (CONTEXT.md: the Section owns its group end to end). **BC for Pro:** keep it as an `option_woocommerce_pos_settings_payment_gateways` filter and only move its *registration* to plugin boot. Pro's `remove_all_filters` then still lifts it. Moving the logic into `read()` would silently break Pro's lift.
- License writers: the delegated read found two writers by design (`License_Section::write` for user writes, `LicenseSettings::save_to_database` for the first-run mint inside a read). `Pro License_Cache.php:52-57` catches both through `pre_update_option_`. Collapsing them would fire the saved hook on GETs. Drop that part.
- The per-section permission callbacks and legacy slugs (`V1/Settings.php:46-80`) are frozen wire parity. Drop that part.

Scope: `V1/Settings.php:112-117, 408-424` moves into `Payment_Gateways_Section` as a static `free_tier_rule( $options )`, registered once from the always-on boot. About 25 non-test lines and 120 total. Pro's own dead `ProSettings::save_settings` (`:86-88`) and `Settings::save_settings` (`:150-158`, tests only) can be deleted in Pro as an optional Low-tier PR.
Pro impact: none if the filter name stays. Pro's `Init.php:73-77` keeps working.
Tests: `Test_Settings_API`, `Test_Gateways`, `tests/includes/Services/Settings/`. Add `Test_Gateways::test_available_payment_gateways_free_site_stored_bacs_enabled_is_excluded`, which needs no API construction. Expect fallout in any test that stores a non-cash/card gateway as enabled without `remove_all_filters` (memory: `Test_Orders` escapes today only because it never builds the API).
ADR / CONTEXT.md: sharpen **Settings Section** with this line: "A tier rule on a group (e.g. free sites enable only `pos_cash`/`pos_card`) is part of the Section and applies to every reader, not only the REST projection."
Tonight? **NO.** It changes what free-site tills offer at checkout (High), test fallout is unknown, and the unlicensed-Pro question is Paul's.

## 4. "May the actor act on this other user", written four times — VERDICT: DO-RESHAPED (folded into D4)
Strength (explorer): Worth exploring · Risk tier: High (capability checks)
- These are four different questions, not one rule:
  - sessions: `Auth.php:805-824`
  - read a cashier profile: `Cashier.php:273-281`, read-only, `manage_woocommerce`, fine
  - list everyone's sessions: `V1/Auth.php:543-546`
  - edit or delete a user: `Permission_Rules.php:345-363`
- Reusing `can_modify` for sessions would be wrong. It protects every `edit_users` holder (`:328`), the Cashier role holds `edit_users` (`Activator.php:581`), so shop managers could no longer sign out cashiers.
- The one real defect is D4. The fix is "a non-administrator may not touch an administrator's sessions", reused by the listing.

Scope / Pro / Tests: see D4 and spec-session-manager-cannot-touch-admin-sessions.md.
Tonight? YES, as D4.

## 5. Pro_Plugin_Updater is four modules — VERDICT: DEFER
Strength (explorer): Speculative · Risk tier: Low (admin display)
- The explorer's regions hold (delegated read of Pro `includes/Admin/Updaters/Pro_Plugin_Updater.php`, 1141 lines): update check 111-273, plugin information 285-816, license notices 822-1029, logging 1036-1141. The regions share no state, and the size is mostly HTML/CSS strings.
- The duplication is real: `:830-844` and `:1000-1014` are identical. `check_license_status` (`:963-985`) always returns an array, so the `is_object` branches `:826, 996` are dead, and `$active` `:1000` is unused.
- One real inconsistency: the Plugins row checks active first (`:846`) and the update payload checks inactive then expired (`:1016-1022`). For activated + expired, the two screens disagree.

Scope: a 20-line private `classify_license_status( array ): array` helper. No module split.
Tests: `tests/includes/Admin/Updaters/Test_Pro_Plugin_Updater.php` (33 tests, nothing direct on `license_status_notice`).
Tonight? **NO.** Pick it up when the file is next touched. The precedence fix needs a one-word ruling (see Needs Paul).

---

## D1. Login tokens posted to `window.top` with `'*'` — VERIFIED
Trace:
- `Template_Router.php:75, 210` route `/wcpos-login/` → `Templates/Login.php:42` `wp_signon` → `:51-52` `login_success` → `:86-93` `window.top.postMessage({…tokens}, '*')`.
- Framing is allowed: `Login.php:23` removes WordPress's frame header, `Init.php:553-558` strips X-Frame-Options for `wcpos-login`, and `Template_Router.php:270-296` strips CSP.
- An attacker page that frames `/wcpos-login/` receives the access and refresh tokens once the victim logs in inside the frame. A password manager will autofill there, because the frame's origin is genuine.

Who still listens: the current client does not. `monorepo-v2` b2487f32f (2026-01-07) deleted the `wcpos-wp-credentials` listener, and since d65636e24 (2025-09) clients log in through `/wcpos-auth/`. Native uses `ReactNativeWebView` (`:97-101`), which is unaffected. The legacy web/Electron modal was an iframe inside the app renderer (`packages/components/src/webview/index.web.tsx` at b2487f32f^, `:87-93`), so its top origin is the renderer, not the store, for Electron and for any cross-origin-hosted web build.

Minimal fix: `Login.php:93`, change `}, '*');` to `}, window.location.origin);`. That keeps same-origin web POS and native working, and breaks re-login in pre-Sept-2025 Electron and cross-origin web builds.

Failing test: new `tests/includes/Templates/Test_Login_Template.php::test_login_success_posts_credentials_only_to_same_origin`.
- Arrange: an administrator.
- Act: invoke private `login_success` through `ReflectionMethod`, inside `ob_start`.
- Assert: `assertStringContainsString( 'window.location.origin)', $out )` and `assertStringNotContainsString( "}, '*')", $out )`.

Severity: Medium-Low. The fix is one line in one file, but the pin is a string match and the change drops legacy clients.
Standalone PR tonight? **NO.** Paul must accept breaking legacy Electron re-login, or retire `/wcpos-login/`.

## D2. Open redirect with tokens in the URL — VERIFIED (memory: LOW)
`Templates/Auth.php:106` → `validate_redirect_uri` `:211-234` accepts any `http(s)` host → `:349-368` appends tokens and calls `wp_redirect`. The `auth_session` binding `:241-268` keys on the attacker's own URI, so it does not defend.
A host allowlist would break legitimate web clients that redirect to their own origin (`monorepo-v2 apps/main/e2e/search-probe.ts:147` uses `https://localhost/cb`).
Failing test (when ruled): `Test_Auth_Template::test_validate_redirect_uri_offsite_https_returns_empty`, plus app-scheme and same-host rows.
Belongs to candidate 1's lane. Not tonight; it needs the list of allowed web hosts.

## D3. Rate limit bypassable / lockout abusable — VERIFIED
- The IP comes from client-supplied `CF-Connecting-IP`/`X-Forwarded-For` (`Templates/Auth.php:540-564`), so the per-IP limit `:444-463` is bypassable.
- The lockout `:472-516` is keyed only on the typed identifier, and it is checked before the password (`:301`). Anyone can lock any cashier out for 15 minutes.
- Username and email forms get separate buckets.
- The username lockout still caps brute force at about 10 guesses per account per 15 minutes.
- The template deliberately skips `wp_authenticate` (`:183-185`), so 2FA and security plugins don't apply.

Severity: Low-Medium (DoS). The fix needs a trusted-proxy decision, so DEFER.

## D4. Shop managers can see and end administrators' sessions — VERIFIED
Trace:
- `V1/Auth.php:99, 120, 147` → `check_session_permissions` `:518-534` → `Auth::can_manage_user_sessions` `:818-820` returns true for any `manage_woocommerce` actor, whatever the target. GET returns IPs and user agents; DELETE revokes.
- `get_all_users_sessions` `:450-509` (gated by `:543-546`) lists every user's sessions, administrators included.
- It is reachable on v2: `packages/settings/src/screens/sessions/index.tsx:52, 92, 115`.
- WooCommerce and the staff rule (`Permission_Rules.php:345-363`, #1918) both refuse a manager acting on an administrator.

No test covers a manager targeting an administrator (`Test_Auth_API.php:455-462` targets a subscriber).
Fix: `Auth.php:818-820` becomes `return ! user_can( $target_user_id, 'manage_options' );` inside the `manage_woocommerce` branch, and `V1/Auth.php` `get_all_users_sessions` skips rows the same predicate refuses. About 6 lines.
Severity: Medium (DoS against admins, plus disclosure of their IPs and user agents).
Standalone PR tonight? **YES, to `main`**: spec-session-manager-cannot-touch-admin-sessions.md.

## D5. Query-string tokens always accepted; `use_jwt_as_param` only advertised — VERIFIED as described, NOT A DEFECT to fix as implied
- `Auth.php:189-191` accepts `?authorization=` on every request. `API.php:308` and `V2/Site.php:62` advertise `Tools_Section` `use_jwt_as_param`, which defaults to false (`:26`), and no settings UI exposes it (`packages/` grep is empty).
- The client never reads the server flag. It probes and stores its own: `monorepo-v2 packages/core/src/contexts/app-state/hydration-steps.ts:622-631, 1092-1113` ("the embedded payload never carries these local flags").
- Query-param auth is the deliberate fallback for hosts that strip `Authorization` (af8d05f7, `Init.php:378-381`).

The literal minimal fix would be `Auth.php:189`: `if ( ! empty( $_GET['authorization'] ) && Settings::instance()->use_jwt_as_param_enabled() )`, with `Test_Auth_Service::test_get_auth_header_query_param_setting_disabled_returns_false`. **Do not ship it.** Every Authorization-stripping store would lose authentication, because nothing ever sets the option.

The honest residuals:
- the advertised flag is vestigial (Low cleanup)
- the parameter is also honoured on storefront and admin page loads, not just REST (`Init.php:391-403`)

Standalone PR tonight? **NO.** Product ruling needed (see Needs Paul).

## D6. Order-pay trusts any valid token — VERIFIED, Low
`Form_Handler.php:44-100`: any live access token (`:88`) from any user, with no capability re-check, plus the order key (`:61`), switches to the order's customer at `:100`. The order key is the real secret (WooCommerce accepts it alone for guest pay), so the token adds only "some POS user". The token sits in the URL (`wcpos_jwt`). DEFER, and pair with D5's ruling.

## D7. Access tokens without `refresh_jti` cannot be revoked for 30 minutes — NOT A DEFECT (accepted)
Nothing in-tree mints unlinked tokens: the only callers of the public `generate_access_token( $user )` (`Auth.php:339`) and the deprecated `generate_token` (`:548`) are tests, in Free and Pro. 03a6e47b accepted this explicitly. Only third-party callers, or the `before_sign` filter at `:406`, can create such a token.

## D8 (new). Coupon form on the order-pay page has no order-key check — LIKELY / NEEDS REPRO
`Form_Handler.php:138-168` needs only `woocommerce_pos_request()`, which `?wcpos=1` satisfies (`wcpos-functions.php:163`, `Request_Lane.php:105`), plus a `pos_coupon_action` nonce, then calls `apply_coupon` / `remove_coupon` on `order-pay=<any id>`.
- `Templates/Payment.php:441` mints that nonce for the current user before the key check (`:174`). A logged-in customer can open the pay page for their own unpaid order, take the nonce, and post to another order's id.
- `pay_action` checks the key at `:59-69`; `coupon_action` does not.
- A missing order fatals at `:156`.

Fix: the same key check plus an order guard, about 8 lines. The legitimate form posts to `action=""`, so `?key=` survives (`Payment.php:388`).
Severity: Low-Medium (tampering with in-progress POS orders).
Standalone PR tonight? **YES, to `main`, failing test first**: spec-order-pay-coupon-key-check.md.

## Business defect. Free-tier gateway rule skipped on non-REST readers — VERIFIED by trace
See candidate 3 for the trace and where the rule belongs. Severity: Low-Medium (revenue/tier leak; no money lost). Not tonight.

---

## Recommended tonight order
1. **D4** to `main`: spec-session-manager-cannot-touch-admin-sessions.md
2. **D8** to `main`: spec-order-pay-coupon-key-check.md (stop if the failing test cannot be made to fail)
3. **Audit-guard header dedup** to `next`: spec-audit-guard-header-reader-dedup.md

Overlap note: `spec-dead-code.md` (on `next`) edits `API/V1/Auth.php:268-280` and `Templates/Login.php`. D4 edits `get_all_users_sessions` (`:450-509`) on `main`. The regions are disjoint, so expect at most a trivial hunk at the main→next sync.

## Needs Paul
- **D1:** may `/wcpos-login/` stop posting tokens to cross-origin parents (`window.location.origin`), dropping JWT-expired re-login for pre-Sept-2025 Electron builds, or should the route be retired?
- **D5:** keep query-param auth as the stripping-host fallback, and delete the vestigial `use_jwt_as_param` server flag? Or restrict `?authorization=` to REST requests?
- **Candidate 1, August Q1:** mint-then-revoke for web sessions (briefly two live sessions instead of zero on failure)?
- **Candidate 1, PR-3:** drop the blacklist transients now that `is_live` is authoritative (reverses 03a6e47b's "transient stays as the fast path")?
- **Candidate 3:** should an unlicensed Pro install lift the free-tier cash/card rule? And should free sites with stored BACS (e.g. after Pro removal) lose it at the till?
- **D2:** which `https` hosts may receive `/wcpos-auth/` tokens (the hosted web app's origin)?
- **Candidate 5:** for an activated *and* expired license, which message wins: "valid and active" or "expired"?
- **Peer managers (D4 follow-up):** may one shop manager sign out another? The spec keeps today's yes.
