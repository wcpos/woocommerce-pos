## 1. Claim check

**Evidence labels:** Observed = source/history inspection; Inferred = execution consequence, not reproduced. No tests, network calls, Docker commands or file changes were made.

**Citation roots:** `Free:` means the supplied `next` tree; `Main:` the released Free tree; `Client:` the supplied monorepo; `Electron:` `/Users/kilbot/Projects/electron`; `Pro:` the supplied Pro tree. `Core:` means `/Users/kilbot/.wp-env/wp-env-arch-runner-next-64cf9d7c/`.

### D2 — redirect destination and token disclosure

- **HOLDS — arbitrary HTTP(S) destinations accepted.** `Free:includes/Templates/Auth.php:215–238` checks the scheme, then calls `esc_url()` without checking the destination origin. The identical code exists at `Main:includes/Templates/Auth.php:215–238`.
- **HOLDS — both access and refresh tokens leave in the URL query, not the fragment.** `Free:includes/Templates/Auth.php:349–379` obtains both tokens, passes them to `add_query_arg()`, then calls `wp_redirect()`. `Core:WordPress/wp-includes/functions.php:1160–1191` separates an existing fragment before building the query.
- **WRONG if interpreted as “click while logged in steals tokens.”** This template does not automatically authorize the current WordPress user. A GET displays a form; a successful POST requires its nonce, username, password and POS access (`Free:includes/Templates/Auth.php:286–337`; `Free:templates/auth.php:44–67`). Being logged into WordPress is neither necessary nor sufficient.
- **HOLDS — attacker-selected `state` and `auth_session` do not authorize the destination.** The session key is derived from the supplied state and redirect URI; a missing session value creates or retrieves a session (`Free:includes/Templates/Auth.php:245–278`). An attacker can therefore initiate the flow with their own destination. This binding prevents substitution within that flow, not malicious initiation.
- **CHANGED, but not fixed — session reuse.** Since the review, commit `2830d8cf` added no-cache headers and reuse of an existing popup session (`Free:includes/Templates/Auth.php:200–202,248–254`). It did not change destination validation or token delivery.
- **WRONG — #2202 is not a D2 mitigation.** Commit `81ac8d86` restricts incoming `?authorization=` authentication to REST requests (`Free` and `Main:includes/Services/Auth.php:182–198`). D2 sends newly issued `access_token` and `refresh_token` parameters outward. The attacker can subsequently use those credentials against REST.
- **WRONG — `https://localhost/cb` proves a legitimate production web origin.** It is a test-only callback whose redirect is not followed; the probe extracts the token from `Location` (`Client:apps/main/e2e/search-probe.ts:147–179`).
- **HOLDS, with better evidence — legitimate cross-origin web callbacks exist.** The client derives the callback from its own page origin, not the store (`Client:packages/core/src/hooks/use-wcpos-auth/utils.ts:19–28`). The deployment workflow names production `https://wcpos.expo.app` and creates variable preview URLs (`Client:.github/workflows/deploy.yml:145–175`). Same-site plus app schemes alone would reject these.

**Inferred attack trace:** attacker sends a link to the genuine store’s `/wcpos-auth/` with an attacker-controlled HTTPS callback and nonempty state → cashier submits credentials to the genuine store form → server checks password and POS capabilities → redirects both tokens to the attacker’s server. Query parameters are visible to that receiving server without JavaScript. Defaults are 30 minutes for access and 30 days for refresh, subject to expiry filters and session revocation (`Free:includes/Services/Auth.php:686–701,1017–1034`).

**Minimal failing PHPUnit test:** extend existing `Test_Auth_Redirect_Uri` in `tests/includes/Templates/Test_Auth_Redirect_Uri.php`, not the issue’s nonexistent current `Test_Auth_Template`. Its reflection helper already invokes the private validator (`:25–31`). Pass `https://attacker.example/cb`; assert `''`. Today it returns the URL. Add allowed same-origin/production-host rows and preserve existing native-scheme rows (`:41–56`).

### D3 — rate limits and account lockout

- **HOLDS, deployment-dependent — forwarded-header spoofing bypasses the IP counter.** `Free` and `Main:includes/Templates/Auth.php:551–574` prefer `CF-Connecting-IP`, then `X-Forwarded-For`, then `X-Real-IP`, without verifying the connecting peer. Spoofing works wherever those headers reach PHP without being securely overwritten.
- **HOLDS — unauthenticated callers can lock a typed identifier across IPs.** Five failed credential submissions create a lock keyed solely by lowercased input; the lock is checked before password verification (`Free` and `Main:includes/Templates/Auth.php:308–324,483–515`). An anonymous caller can fetch the form’s nonce/session first (`Free:templates/auth.php:64–67`).
- **Qualification — not literally any unauthenticated request.** A bare GET or invalid-nonce POST does not increment failures. The attacker needs valid form parameters and nonce, an empty honeypot and five failing POSTs. No victim interaction or victim password is required.
- **HOLDS — username/email buckets are separate.** Authentication resolves login then email (`Free:includes/Templates/Auth.php:408–412`), while counters hash the typed string (`:488,505,541`). Locking one spelling does not lock the other.
- **Qualification — “any cashier locked out for 15 minutes” means this login path and identifier.** It does not revoke existing JWT sessions or impose a WordPress-wide account lock. Both identifier forms can be attacked; ten failures fit the per-IP threshold (`:33–48,455–473`).
- **HOLDS — deliberate authentication-hook bypass.** Password validation is direct; the WCPOS-specific filter runs afterward (`Free:includes/Templates/Auth.php:400–447`). Commit `504e0efb` introduced the limits alongside that bypass. Removing effective throttling would undo an intentional safeguard.
- **HOLDS — second IP resolver also trusts headers, but serves a different purpose.** `Free:includes/Services/Session_Context.php:123–153` supplies session metadata, recorded at `Free:includes/Services/Session_Registry.php:142,183`. It is not the template’s rate-limit resolver.
- **WRONG — `REMOTE_ADDR`-only mirrors WooCommerce geolocation.** The on-disk `WC_Geolocation::get_ip_address()` prefers `X-Real-IP`, then forwarded-for, then `REMOTE_ADDR` (`Core:woocommerce/includes/class-wc-geolocation.php:82–98`). The nearby filters are `woocommerce_geolocation_ip_lookup_apis` and `woocommerce_geolocation_ip_lookup_api_response`, for external lookup (`:118,133`), not a trusted-proxy policy.
- **Observed WordPress precedent:** core stores session IPs directly from `REMOTE_ADDR` and uses it in password-reset notification text (`Core:WordPress/wp-includes/class-wp-session-tokens.php:132–135`; `Core:WordPress/wp-includes/user.php:3397–3404`). This supports using server-established identity, but is not evidence of a universal plugin rate-limiting standard.

**Minimal failing PHPUnit tests:** proposed `Test_Auth_Template_Rate_Limits`, using restored request globals and isolated transient keys:

1. **Cross-IP lockout:** create a POS-capable user; anonymous GET for nonce/session; five wrong-password POSTs from peer A; correct-password POST from peer B. Attach a sentinel callback to existing `woocommerce_pos_authenticate_user` that records arrival and returns a test `WP_Error`, avoiding redirect/exit. Assert the callback was reached. Today the global identifier lock prevents it.
2. **Header rotation:** one fixed `REMOTE_ADDR`, ten failing POSTs with distinct nonexistent identifiers and rotating valid forwarded IP values; an eleventh request uses another header value. Assert rate-limited. Today each header value selects a fresh bucket.
3. **Alias merging:** one user and peer; three failures by username, two by email; assert both aliases now resolve to the same locked bucket. Today neither bucket reaches five.

**Released-line finding:** both affected files are byte-identical between `9b9f7613` and `92b5d970`; `git diff --exit-code` returned **0**. History since 2026-10-12 shows only `2830d8cf` touching the template and no changes to `Session_Context.php`. **Both fixes belong on `main`, then flow into `next`.**

## 2. Impact

### D2: **Medium severity**, potentially serious credential compromise

- **Who:** a cashier or manager persuaded to complete a genuine-store login initiated by an attacker; the merchant inherits the victim’s permitted API exposure.
- **Frequency:** requires a targeted link and credential submission, not routine browsing. No incident frequency can be established from disk.
- **Consequence:** attacker receives a renewable POS credential pair, not merely a navigation redirect. The prior “LOW” label understates the consequence, although the phishing prerequisite limits likelihood.
- **Reachability:** this is active client authentication. Add User invokes the auth hook and prompt (`Client:packages/core/src/screens/auth/components/add-user-button.tsx:25–33,70–75`); web/native implementations send `redirect_uri` (`…/use-wcpos-auth/index.web.ts:91–104`; `…/index.ts:39–54`).

### D3: **Medium severity**, login denial and weakened guessing controls

- **Who:** cashiers needing initial login or reauthentication; attackers can trigger it anonymously. Existing signed-in tills are not directly logged out.
- **Frequency:** five deliberate bad submissions suffice; ordinary repeated typos also activate it. Real-world frequency is unverified.
- **Consequence:** temporary login denial, renewable through repeated attacks. Header rotation undermines the IP control but does not alone remove the current identifier lock.
- **Reachability:** the same shipped-source auth flow reaches both controls. Electron also builds the URL and opens its auth window through IPC (`Client:…/use-wcpos-auth/index.electron.ts:57–77`; `Electron:src/main/auth-handler.ts:169`).

**Engineering stakes remain High** under `.ai/rules/stakes-tiers.mdc:48–55`; that governs verification rigor, independently of exploit severity. Packaged binaries and live hosting behavior were not evaluated.

## 3. Cost and risk

| Piece | Proposed scope | Rough non-test changes | Lane |
|---|---|---:|---|
| D2 | Origin validation inside `includes/Templates/Auth.php`; retain native schemes; explicit production origin; narrowly scoped developer exception mechanism only if approved | ~60 lines | `main` |
| D3 | Same file: server-established IP, shared canonical account+IP key used by check/record/clear | ~90 lines | `main` |
| Probe adjustment | Replace dummy localhost callback with a same-origin callback; probe already disables redirect following | ~2 lines | client `main` |
| Preview/local enrollment | Depends on approved exception mechanism and actual deployment arrangement | Not yet scoped | relevant client/test infrastructure lane |

Tests: extend `Test_Auth_Redirect_Uri`; add the proposed rate-limit class; retain `Test_Auth_Session_Reuse`. Include wrong-origin ports/schemes, deceptive hostname suffixes/userinfo, correct-password behavior, same-peer thresholds, alias clearing, missing/invalid peer address and header rotation. Run later through wp-env; none were run here.

**Pro coupling:** searches of Pro `includes/` and `tests/` found no callers/subclasses of the template or consumers of its private helpers/auth-template hooks. Pro requires Free (`Pro:composer.json:14–25`), so it inherits behavior. Its permission tests use `AuthService::instance()` (`Pro:tests/includes/API/Test_Pro_POS_Endpoint_Permissions_Matrix.php:11,74`), not these helpers.

**Back compatibility:** no public method or REST response-shape change is necessary. Preserve `woocommerce_pos_authenticate_user` and its argument semantics (`Free:includes/Templates/Auth.php:431–447`). A new exception filter would itself become a public contract and needs explicit approval.

### Behavior changes / regressions

- D2 would reject previously accepted offsite callbacks. Production hosted web must remain allowed; previews, local web/Electron development and third-party clients need an explicit disposition.
- Production Electron uses the `wcpos` scheme; development uses a localhost HTTP origin (`Electron:src/main/window.ts:17–26`).
- D3 account+IP keys remove the global account-wide guessing cap: distributed sources receive separate budgets.
- `REMOTE_ADDR`-only can collapse all clients behind an unnormalized reverse proxy into one bucket. Ten failures could then block unrelated cashiers. This is an operational risk, not a harmless retry.
- Leave `Session_Context` unchanged in this patch: recorded session IPs may remain header-derived and differ from the enforcement IP. Its existing header-precedence tests explicitly pin that behavior (`Free:tests/includes/Services/Test_Session_Context.php:116–158`).
- No claim of broad compatibility or successful mitigation is made without the proposed tests and hosting checks.

## 4. Options

### D2 ruling: which destinations may receive credentials?

**A. Same configured site origin plus existing app schemes only.**

- **Pros:** smallest, zero-config; rejects arbitrary web destinations.
- **Cons:** demonstrably breaks the production hosted client at `https://wcpos.expo.app`, previews and local browser development. Cannot be presented as preserving current clients.

**B. Same configured site origin, exact `https://wcpos.expo.app`, existing app schemes; explicit developer-controlled exceptions.**

- **Pros:** preserves the production paths evidenced on disk while rejecting arbitrary offsite URLs; no merchant settings screen needed.
- **Cons:** requires approving a narrow exception mechanism and arranging preview/local enrollment. Third-party hosted-client inventory remains unknown.
- Compare parsed **scheme, host and effective port**, using configured site URLs—not caller-supplied `Host`, `Origin` or `Referer` as authorization. Do not allow all `*.expo.app` or infer ownership from the preview URL extraction regex.

**C. Keep arbitrary destinations pending a broader authorization-flow redesign.**

- **Pros:** no immediate client breakage.
- **Cons:** leaves verified credential exfiltration behavior; a redesign is much larger than the present issue.

**Evidence favours B.** The list is partly derivable but **not wholly derivable from the store request**. The hosted production origin is now known from source. Whether to support arbitrary third-party hosts and how previews are enrolled remains a real operational/product decision. Merely moving tokens into a fragment would not fix malicious-destination authorization.

### D3 ruling: IP trust and lockout semantics

**A. Canonical account+`REMOTE_ADDR` buckets; retain pre-password checks.**

- **Pros:** no proxy-header selection or proxy allowlist; blocks forged-header bucket rotation; prevents an unrelated peer from locking the victim’s bucket; username/email share one budget.
- **Cons:** distributed guessing gets per-source budgets; shared NAT/proxy peers still share limits. Requires hosts to normalize `REMOTE_ADDR` securely if they want end-user attribution.
- Canonicalize using the same login-then-email lookup order as authentication; use a distinct normalized-input key when no user exists.

**B. Let correct passwords bypass lockout by checking credentials first.**

- **Pros:** fixes the account-lock denial for known correct credentials without relying on IP identity.
- **Cons:** successful guesses also bypass the account lock, so it no longer limits successful brute-force discovery; every attempt incurs password verification. The current earlier IP gate still blocks valid passwords (`Free:includes/Templates/Auth.php:129–138`). Moving only the account check therefore does not solve shared-IP denial.

**C. Keep global account locks; add trusted-proxy-aware IP resolution.**

- **Pros:** preserves the global guessing cap and can retain client attribution behind proxies.
- **Cons:** requires operational proxy/header trust information and additional policy; does not fix anonymous cross-IP account lockout.

**Evidence favours A as the smallest bounded patch**, not an automatic guarantee for every host. Do not combine A and B reflexively: B changes the security policy rather than merely improving the key.

Using `REMOTE_ADDR` avoids implementing a trusted-proxy registry; it does **not** eliminate the need to approve shared-peer behavior. WooCommerce geolocation is not a justification for trusting headers at an authentication boundary.

## 5. Recommendation

**PURSUE NOW**, targeting **`main`**, with the two policy choices above explicitly approved before implementation.

The carrying evidence is unchanged released-line code, active client reachability, renewable credentials sent to arbitrary origins, and anonymous cross-IP login denial. Neither should remain deferred solely because the original review lacked client or core-source evidence.

- **PR 1 on main:** scope lockouts to canonical account+server-established IP; ignore forwarded headers for enforcement; retain pre-password throttling and current thresholds (~90 non-test lines, tests: cross-IP isolation, header rotation, alias merging/clearing and threshold behavior).
- **PR 2 on main:** restrict HTTP(S) callbacks to configured site origin(s) and exact production hosted origin, preserving native schemes; add only the explicitly approved developer exception mechanism (~60 non-test lines, tests: origin rejection/acceptance matrix and existing app-scheme cases).
- **Companion client patch on main:** replace the probe’s dummy localhost callback (~2 non-test lines, tests: existing search-probe authentication flow).

**Release conditions:** confirm representative proxy deployments expose a usable `REMOTE_ADDR`; approve and exercise preview/local callback handling before deploying D2 to their shared test stores. Do not add a proxy framework, settings UI, token-exchange redesign or IP-resolver consolidation to these patches.

## 6. Open questions

- **Are additional production hosted/custom web origins supported?** Best guess: the configured store origin and `https://wcpos.expo.app` cover official production web paths; custom deployments remain possible. Paul’s supported-origin inventory settles this.
- **May development/preview origins use a narrow code-level allowlist extension?** Best guess: preferable to a merchant option or public-domain wildcard. Approval plus an exact preview/local deployment mechanism settles it.
- **Do supported hosts normalize `REMOTE_ADDR` before PHP?** Disk cannot establish live Cloudflare/load-balancer configuration. Best guess: mixed deployments. Inspect server configuration and sanitized peer/header observations on representative hosts; this decides whether option A can ship without expanding login denial.
- **What happens in packaged clients and real HTTP redirects?** Source establishes the paths, not runtime compatibility. Later wp-env PHPUnit execution plus web/native/Electron auth smoke checks would settle it.