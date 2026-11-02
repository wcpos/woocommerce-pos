## 1. Claim check

**Observed:** inspected Free `next` at `92b5d970` and released `main` at `9b9f7613`. `Auth.php`, `Session_Registry.php`, `Session_Context.php`, and `Test_Auth_Service.php` are byte-identical between these trees; citations to those files below apply to **both lanes**. Runtime consequences are **Inferred**, not reproduced; no tests were run.

`Client:` means `/Users/kilbot/Projects/monorepo-v2-worktrees/research-issues/`; `Pro:` means `/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/research-issues-next/`.

### The August rulings and cookie test

- **CHANGED — revoke-before-issue no longer exists on page loads.** Commit `2830d8cf` (October 13) replaced rotation with session reuse. `includes/Services/Auth.php:630-647` reuses a live cookie session with more than one day remaining; otherwise it mints another session **without revoking the old one**. Logout still revokes the cookie-named session (`:100-103`, `:1106-1120`).
- **HOLDS — invalid/missing-JTI cookie input silently returns.** `Auth.php:1133-1136` retains this behavior, with a new earlier `headers_sent()` return at `:1129-1130`. However, the review’s “only reachable when a filter strips `jti`” explanation is now **CHANGED**: the reuse branch does not validate its reissued refresh token before passing it to the cookie setter (`:634-655`), so other invalid filtered claims can reach that return.
- **HOLDS — request context uses `$_REQUEST`, not `$_GET`.** `includes/Services/Session_Context.php:95-106`.
- **CHANGED — the skipped web-frontend test is already a real test.** `tests/includes/Services/Test_Auth_Service.php:1052-1057` now checks both tokens and the registry entry. Reuse, unknown cookie, foreign cookie, and near-expiry behavior are covered in source at `:1063-1128`. These tests do **not** establish that an HTTP cookie was emitted.
- **WRONG as an exclusive claim — an injected cookie adapter is not the only test seam.** The present guard already allows lifecycle tests without one. A small overridable header/cookie boundary could test cookie arguments without introducing a read/write adapter class. There is no existing `wp_headers`-style interception in this implementation: `Auth.php:1128-1156` directly calls PHP functions.

### Token-pair failure and orphan sessions

- **CHANGED — “failure revokes the old session and logs the user out.”** Neither fresh-pair failure nor reuse failure now explicitly revokes the old session (`Auth.php:630-650`). The original justification for mint-then-revoke is obsolete.
- **HOLDS — a fresh-pair failure can leave an orphan session entry.** Signing precedes registry insertion (`Auth.php:542`, `:551`), but refresh validation and access-token generation follow insertion (`:588-596`), with no rollback. This is an entry inside the existing user-meta map, not necessarily a separate database row.
- **WRONG — merely deleting the configured secret reliably reproduces mint failure.** Both secret getters regenerate missing/empty secrets (`Auth.php:249-269`). The August fixture suggestion is therefore unsuitable for today’s implementation.

**Minimal failing PHPUnit test, not run:** add `Test_Auth_Service::test_failed_web_token_pair_does_not_add_session` to the existing file. Create the normal shop-manager fixture and valid pair A; put A’s JTI in `wcpos_web_session_jti`; shorten A’s stored expiry to one hour, forcing fresh issuance. Add `woocommerce_pos_jwt_refresh_token_before_sign` returning the payload with `type = 'x'`. Call `get_user_data($user, true)`—the service call made by the frontend request. Assert `[]`, A remains live, and the registry still contains only A. **The last assertion fails by inspection:** new entry B survives `validate_token()` rejecting its type.

A second useful case makes the access-sign filter throw `RuntimeException`, catches that exception in the test, and asserts no new session remains. Today access signing occurs after B was stored (`Auth.php:454-455`, `:594`), and that exception is not caught by token-pair generation.

### Blacklist claims

- **HOLDS, narrowed — all four production `blacklist_token()` call sites accompany registry mutations:**

  | Caller | Registry mutation |
  |---|---|
  | Eviction: `Auth.php:566` | `record()` at `:551`; registry removal/write at `Session_Registry.php:189-191`, `:530-532` |
  | Revoke all: `Auth.php:799` | `revoke_all()` at `:803` |
  | Revoke others: `Auth.php:859` | `keep_only()` at `:863` |
  | Revoke one: `Auth.php:979` | `revoke_session()` at `:974` |

  **Five call sites including tests:** `Test_Auth_Service.php:877` deliberately blacklists an **access-token JTI without any registry mutation**. Thus “every in-tree call” was literally wrong.

- **WRONG — `is_live()` is authoritative on every validation path.**
  - Linked access tokens require it: `Auth.php:343-355`.
  - Access tokens **without** `refresh_jti` skip it. Their own-JTI blacklist check remains effective (`:322-327`).
  - `validate_token(..., 'refresh')` checks neither blacklist nor registry; the actual refresh exchange adds `is_live()` at `:729-734`. Cookie decoding and current-session identification also use that decoding-only path (`:1133`; `includes/API/V1/Auth.php:570-574`).
- **WRONG — all blacklist behavior is redundant.** The public `blacklist_token()` explicitly supports access JTIs (`Auth.php:937-955`), and `Test_Auth_Service.php:869-883` pins that contract. Removing it loses selective revocation, including for unlinked tokens.
- **HOLDS — `03a6e47b` intentionally retained the transient fast path.** Its commit message explicitly preserves unlinked-token behavior. Current production blacklist reads are exactly **two call sites**, `Auth.php:322,332`, through the single `get_transient()` at `:1098`. Other matching reads are test assertions.

**Upgrade handling:** registry extraction did not introduce a new session store. Before `4cfd2442`, `Auth.php:1008-1021` already wrote JTI-keyed entries with `expires` under `_woocommerce_pos_refresh_tokens`; today the registry uses that same key and shape (`Session_Registry.php:21,179-185,372-383`). Old **linked** tokens with missing entries fail closed today; removing transients would not make them live. Old **unlinked** tokens bypass the registry and can still depend on their own-JTI blacklist. Tokens missing today’s required `type` fail earlier (`Auth.php:298-303`).

History since October 12 also contains administrator-permission and query-token fixes, but no subsequent registry change. The decisive change for this issue is `2830d8cf`, already present on both lanes.

## 2. Impact

### Failed issuance: Low residual severity; High-stakes implementation area

**Who:** cashiers attempting login or opening the embedded web POS; administrators viewing leftover sessions.

**Frequency:** unmeasured. Ordinary payloads use locally generated UUIDs, timestamps, and HS256 signing. No production Free/Pro registrations of the token-signing filters were found. Likely triggers are extension/filter mistakes or unusual configuration/storage failures, not routine network instability.

Concrete failure sources:

- Invalid filtered issuer/type/user claims return `WP_Error` (`Auth.php:288-315`).
- Filtered expiry/future timestamps produce JWT exceptions, converted to `WP_Error` during validation (`vendor_prefixed/firebase/php-jwt/src/JWT.php:130-149`; `Auth.php:361-375`).
- Encoding malformed payloads can throw JSON exceptions; a non-array filter result can cause a type error (`JWT.php:168,179-180,335-347`). Encoding occurs outside the validation catch.
- A refresh-sign exception occurs **before** registry insertion and therefore creates no new orphan; access-sign failure occurs afterward.

**What the cashier sees now:**

- `/wcpos-auth/` displays “Failed to generate authentication tokens.” for the returned-error path (`includes/Templates/Auth.php:351-356`).
- Embedded `/pos/` receives empty credentials (`includes/Templates/Frontend.php:78,262`). The supplied client’s initial-props hydration is explicitly fail-soft, retaining the previous session or returning to connection flow when processing fails (`Client:packages/core/src/contexts/app-state/hydration-steps.ts:910-946`). Exact rendered behavior was not exercised.
- There is **no longer a necessary logout of the previous POS session**, and this code does not log the user out of WordPress.

**Orphan cleanup:** no immediate failure-specific cleanup exists. Expired entries are removed on later issuance (`Session_Registry.php:132-138`); idle overflow entries can be evicted after the seven-day idle window (`:50,465-532`); explicit revocation also removes them. Listing hides expired entries without deleting them (`:203-215`). Default refresh expiry is thirty days (`Auth.php:1033-1034`), so a phantom session can remain visible for weeks. No dedicated orphan-cleanup job was found.

This is a real residual storage/UI defect, but the inspected evidence does not establish lost sales, unauthorized access, or frequent cashier disruption caused by the **orphan itself**.

### These are exercised paths, not dead code

- Embedded frontend calls `get_user_data(..., true)` (`Frontend.php:74-78`).
- Native authentication uses the advertised login endpoint (`Client:packages/core/src/hooks/use-wcpos-auth/index.ts:28-35`); the server auth template calls `get_redirect_data()`, which generates a pair (`Templates/Auth.php:351`; `Services/Auth.php:686-689`).
- The client posts refresh tokens to `auth/refresh` and treats rejection statuses as requiring reauthentication (`Client:packages/hooks/src/use-http-client/refresh-access-token.ts:142-155,202-225`).
- The settings session screen lists and deletes sessions (`packages/settings/src/screens/sessions/index.tsx:52,92,115`), so phantom entries are observable.

### Blacklist removal: no demonstrated current benefit; potentially High regression cost

Every normal linked access-token validation performs blacklist lookups, but **no performance baseline was measured**.

Removing those checks can restore access to explicitly blacklisted tokens while their JWT remains valid. Also, paired registry writes are not proof of atomicity: `refresh_activity()` and `record_access_expiry()` read then rewrite the map (`Session_Registry.php:297-304,321-333`). **Inferred:** an overlapping stale write can restore a revoked entry; the existing blacklist would still reject its linked access token during the blacklist TTL. This is an additional reason not to call removal behavior-preserving—not a proposal to add locking here.

## 3. Cost and risk

| Piece | Estimated non-test change | Tests and lane |
|---|---:|---|
| Mint-then-revoke | **0 recommended** | Contradicts current page-load contract; do not implement |
| Fresh-pair failure cleanup | ~60–90 changed lines in `includes/Services/Auth.php` | **main**, then merge forward; invalid-refresh and access-sign-exception cases, successful issuance, existing-session preservation |
| Cookie argument test seam | ~25–40 lines in `Auth.php` | **next** if standalone testability work; cookie JTI/expiry/options, invalid/missing JTI, headers-already-sent behavior |
| Full injected read/write adapter | ~60–90 lines across Auth and a new adapter | **next**; unnecessary read abstraction because tests already supply `$_COOKIE` |
| Wholesale blacklist removal | ~150–200 changed/deleted lines in Auth, plus test changes | **next only**, explicit compatibility decision; not recommended |

Failure cleanup must retain the **original generated JTI**, not trust an invalid or filter-modified JWT to identify what to delete (`Auth.php:515,542,551`). Do not restore an entire pre-mint snapshot: that could erase another request’s session. Preserve existing public signatures, standalone refresh-token issuance, and successful payload/filter behavior. The old “~25 lines” estimate is not sufficiently grounded for both returned errors and thrown failures.

**Pro coupling:** searches of Pro `includes/` and `tests/` found no production subclass/caller of the touched lifecycle/blacklist methods or signing filters. Its permission-matrix tests use Free Auth and mint pairs/access tokens (`Pro:tests/includes/API/Test_Pro_POS_Endpoint_Permissions_Matrix.php:74,166,173,384`). This means no identified Pro override to update—not that Pro is unaffected: its vendored Free authentication changes too.

**Public exposure:** `get_user_data`, token generators, revocation methods, and `blacklist_token` are public. Preserve REST response/error shapes and token-signing filters. Removing the blacklist’s behavior while leaving a no-op method would still break its contract.

**Behavior changes / regressions:**
- Already landed: page loads reuse sessions or mint without revoking; late cookie writes silently return. Do not reverse these.
- Proposed cleanup: failed fresh issuance stops leaving its new entry; existing sessions remain.
- Blacklist deletion would remove selective access-token revocation and existing transient enforcement.
- No compatibility, performance, or passing-test claim is made from this read-only investigation.

## 4. Options

### August Q1: mint-then-revoke?

1. **Keep reuse/no page-load revocation.** Preserves open tabs during background fetches and the explicit tests at `Test_Auth_Service.php:1063-1076,1115-1128`; permits overlapping sessions near expiry.
2. **Mint, then revoke the previous session.** Reduces overlap, but a successful background page fetch would again invalidate the open tab’s credentials—the failure `2830d8cf` was written to prevent.
3. **Keep current lifecycle; clean only failed fresh issuance.** Addresses the remaining orphan without touching another tab’s grant; requires tracking the newly allocated JTI.

**Evidence favors 3, with 1’s lifecycle preserved.** The ordering question is no longer an unresolved product choice. The current code and regression tests already answer it; reinstating rotation would be a regression.

### PR-3: drop blacklist transients?

1. **Keep them.** Preserves public selective revocation and the independent rejection record; retains two lookup opportunities and TTL machinery.
2. **Remove automatic session-blacklist writes but retain the public API/read checks.** Reduces some write/TTL work while preserving direct callers; still retains read cost and loses the extra protection when registry removal fails or is overwritten.
3. **Remove everything.** Simplifies substantially, but breaks the explicitly tested public contract and changes enforcement of existing blacklists. No measured speed benefit offsets that evidence.

**Evidence favors 1.** Whole deletion is not harmless cleanup. Any future retirement requires an explicit breaking-change decision; it is not justified by counting production callers alone.

### Cookie adapter / August Q2

1. **Use existing lifecycle tests; defer cookie-emission testing.** Zero production change, already covers session reuse and isolation; does not pin cookie arguments.
2. **Introduce a narrow overridable header/cookie I/O seam.** Roughly 25–40 lines; lets tests exercise real token decoding and assert exact cookie arguments. Preserve the default header guard; a fake writer alone behind today’s guard would never be reached in affected PHPUnit runs.
3. **Introduce the full injected adapter.** Explicit and independently testable, but its read half duplicates a seam already supplied by `$_COOKIE` fixtures.
4. **Separate-process/header inspection.** Avoids a production seam, but separate-process precedent only proves process isolation (`tests/includes/API/Test_Relay_Integration.php:166-169`), not cookie inspection. The repository itself documents `headers_list()` as empty under CLI (`tests/includes/Test_Template_Router.php:212`). Xdebug-based inspection was not established in this environment.

**Evidence favors 1 now, 2 when cookie behavior is next changed.** Such tests would buy a real contract—not merely coverage—by pinning refresh JTI, expiry, path/domain, `Secure=is_ssl()`, `HttpOnly`, and `SameSite=Lax` (`Auth.php:1139-1154`). They would not prove browser acceptance or transport. The review’s categorical “separate process cannot help because PHPUnit printed output” was stronger than its evidence.

### Is a lifecycle module worthwhile?

1. **Extract another lifecycle module.** Moves sequencing behind a new interface but expands a public, High-stakes area already split into Auth and Session_Registry.
2. **Keep the existing split; address the residual failure path and optional cookie seam locally.** Smaller review surface; existing registry methods already own storage and revocation.
3. **Do nothing further.** Avoids churn, accepting occasional phantom sessions until existing cleanup applies.

**Evidence favors 2, deferred as below.** This is now **one residual failure-cleanup fix plus an optional test seam**, not two fixes requiring a new module.

## 5. Recommendation

**PURSUE LATER — trigger: the next token-pair issuance change, or a merchant report of failed logins accumulating phantom sessions, whichever comes first.**

Do not execute #2251 as originally scoped. Its principal logout defect and skipped lifecycle test were superseded on October 13. The remaining orphan is real but exceptional and partially bounded by existing cleanup; blacklist removal is not behavior-preserving; a new module adds no demonstrated benefit.

**PR 1 on main: remove only the newly allocated session entry when fresh token-pair issuance fails, preserving page-load reuse and existing error/exception behavior (~80 non-test lines; tests: invalid refresh claims, access-sign exception, prior-session preservation, successful pair issuance).**

No cookie adapter, blacklist deletion, public-method deprecation, or registry redesign in that PR. The cookie seam can wait for an actual cookie-policy change.

## 6. Open questions

- **How often do merchants hit these mint failures?** Best guess: rarely, principally through custom signing filters. Auth error logs and a concrete affected extension/configuration would settle this and could justify immediate cleanup.
- **Do external integrations use `blacklist_token()` directly?** None found in Free/Pro production code; the public contract and test demonstrate supported use. An extension inventory would establish usage, but absence would not make deletion a patch-safe change.
- **What exactly renders after empty embedded credentials in deployed bundles?** Supplied client source intends fail-soft recovery; browser verification with and without cached credentials would settle the visible result.
- **Can the supported test environment inspect real cookie headers without a seam?** CLI `headers_list()` is unsuitable; availability and behavior of an Xdebug/server-backed alternative remain unverified. Test that only when cookie-output coverage is scheduled.