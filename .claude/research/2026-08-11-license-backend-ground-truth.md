# Where WCPOS Pro license activations actually live — ground truth (#1564)

Research note — 2026-08-11
Child of wayfinder map #1527. Investigated against primary sources: `wcpos-com`, `updates-server`, `wcpos-keygen`, `wcpos-infra`, `wcpos-medusa`, `woocommerce-pos-pro` local clones, plus two safe live probes (DNS + a bogus-key status call). Every claim carries a file:line citation; open live checks are listed in §6.

---

## TL;DR

1. **`wcpos.com/?wc-api=am-software-api` is served by the Next.js marketing site on Vercel — not WordPress, not the WC API Manager plugin.** Middleware rewrites the query to a compatibility shim (`wcpos-com/src/middleware.ts:98-104` → `src/app/api/legacy/wc-am/route.ts`) which forwards to `updates.wcpos.com/pro/license/{activate,deactivate,status}`. **Nothing is recorded on wcpos.com itself** — the activation datastore is Keygen (a machine record), plus a fire-and-forget PostHog attribution event. Live-verified 2026-08-11: the production URL answers with the WC-AM envelope carrying updates-server's exact 404 message text (§1.4).
2. **updates-server validates everything against Keygen CE at `license.wcpos.com` — the WC API Manager store is gone.** `/pro/license/*` and `/pro/download/*` all resolve through `keygen-client.ts` (`validate-key` action, machine create/delete), with a frozen 2,288-entry alias map translating legacy per-order WC AM keys to Keygen master keys. The Pro repo's `license.wcpos.com` claim is not wrong so much as mislabelled: that host is the Keygen CE instance itself, which plugins never call directly (`updates-server/src/config/env.ts:44`, `wcpos-infra/ARCHITECTURE.md:132`).
3. **Yes — Keygen machine records exist and are being created continuously for current licenses, and the #1530 "lazy backfill from pings" is *already implemented*** in updates-server: a status poll for a valid-but-machineless license auto-creates the machine (`updates-server/src/features/pro/routes.ts:176-232`). Three production creation paths: explicit activation, status-poll lazy heal, and the one-off WooCommerce migration which loaded historical WC AM activations as machines. #1530's remaining work is enforcement/limits, not the backfill mechanism.
4. **The Ed25519 keypair question cannot be settled from code — it hinges on env vars at first boot.** `wcpos-keygen/start.sh:63-75` seeds the `accounts` row via raw SQL (bypassing the Rails model callbacks that normally generate the keypair) and inserts `$KEYGEN_ED25519_PRIVATE_KEY` / `$KEYGEN_ED25519_PUBLIC_KEY` verbatim — **empty strings if the optional env vars were unset** (`env.example:32-35`), and `ON CONFLICT DO NOTHING` means they are never backfilled. No current flow verifies response signatures, so empty keys would not have surfaced. Exact live check in §6.
5. **The Medusa↔Keygen mapping (relevant to #1563): license references live in Medusa order metadata** (`order.metadata.licenses[] = {license_id, license_key}`), written by the `order-completed` subscriber that creates Keygen licenses at purchase time, plus a legacy `customer.metadata.wc_master_api_key`. There is no dedicated mapping table; wcpos-com resolves account→licenses by walking the customer's orders.

---

## 1. What serves `?wc-api=am-software-api` on wcpos.com (Q1)

### 1.1 The caller

The free plugin's license screen builds a browser GET against the retired WC API Manager protocol:

- `woocommerce-pos/packages/settings/src/screens/license/index.tsx:64-74` — `addQueryArgs('https://wcpos.com', { 'wc-api': 'am-software-api', request: activation|deactivation, instance, api_key, … })`, fetched cross-origin with `credentials: 'omit'` (`:76-79`).

### 1.2 The server: Next.js on Vercel, WordPress fully retired

`/Users/kilbot/Projects/wcpos-com` is a Next.js 16 app (`next.config.ts`, `src/middleware.ts`), and it is what production `wcpos.com` runs:

- `wcpos-infra/ARCHITECTURE.md:18` — `wcpos/wcpos-com` = "Marketing site (Next.js)" at `wcpos.com` (Vercel); `:128` repeats it in the domain table.
- DNS (live, 2026-08-11): `wcpos.com` → `216.150.1.1` (Vercel), while `updates.wcpos.com` and `license.wcpos.com` → `213.239.218.130` (Hetzner).
- The middleware even hard-404s WordPress paths (`/wp-login.php`, `/xmlrpc.php`, `/wp-admin/index.php`) — `wcpos-com/src/middleware.ts:90-92`.

The shim's own doc comment states the history explicitly: the old **WPEngine WordPress site** created WC AM activation records; after the Keygen cutover the URL "just returns the marketing homepage, so activations silently do nothing", and the shim was added to bridge the deployed fleet — `wcpos-com/src/app/api/legacy/wc-am/route.ts:4-23`.

### 1.3 The handling path

1. Middleware rewrite: `wc-api=am-software-api` in the query → rewrite to `/api/legacy/wc-am` (`wcpos-com/src/middleware.ts:94-104`).
2. Shim translates WC-AM verbs to updates-server calls (`wcpos-com/src/app/api/legacy/wc-am/route.ts`):
   - `request=activation` → `POST https://updates.wcpos.com/pro/license/activate` (`:25`, `:136-146`)
   - `request=deactivation` → `POST …/pro/license/deactivate` (`:123-133`)
   - anything else → `GET …/pro/license/status?key=…&instance=…` (`:136-146`)
   - Replies always HTTP 200 with the `{ success, activated }` WC-AM envelope the plugin branches on (`:50-54`).

### 1.4 Where an activation is recorded

**Nowhere on wcpos.com.** The shim is stateless; the durable record is the **Keygen machine** created by updates-server (§2/§3). The only local side effect is a fire-and-forget PostHog `license_activated` attribution event when the plugin forwarded an `anon_id` (`route.ts:92-105`, `:151-153`). No WP postmeta/options, no Vercel Postgres write.

Live probe (2026-08-11), bogus key:

```
GET https://wcpos.com/?wc-api=am-software-api&request=status&api_key=RESEARCH-TEST-BOGUS&instance=research-test
→ {"success":false,"activated":false,"error":"A customer account does not exist for this API Key.","code":404}

GET https://updates.wcpos.com/pro/license/status?key=RESEARCH-TEST-BOGUS&instance=research-test
→ {"status":404,"error":"Not Found","message":"A customer account does not exist for this API Key."}
```

The message text is generated only by updates-server's legacy-shape mapper (`updates-server/src/features/pro/routes.ts:270-271`), proving the production chain wcpos.com → shim → updates-server end to end.

One curiosity, resolved: `wcpos-com/src/middleware.ts:106-127` also contains an `updates.wcpos.com` hostname branch (restrict to `/api/*`, 301 the rest to wcpos.com). DNS shows that hostname points at Hetzner, not Vercel, so this branch is dormant/defensive — had it been live, the shim's `/pro/*` forwards would 301 into the marketing site and break.

---

## 2. What updates-server validates against (Q2)

Everything is Keygen; there is no API Manager fallback anywhere.

### 2.1 Configuration

- `updates-server/src/config/env.ts:44-46` — `keygenUrl` default **`https://license.wcpos.com`**, `keygenAccountId`, optional privileged `keygenApiToken`.
- `license.wcpos.com` is the self-hosted **Keygen CE** Rails app (`wcpos-keygen` repo; `wcpos-infra/ARCHITECTURE.md:22,64,132`). So the Pro docs' "license.wcpos.com" refers to the Keygen host — real, but a backend the plugin never calls directly.
- Legacy WC AM keys: a frozen alias map (`data/legacy-key-map.json`, **2,288 aliases**, generated 2026-07-01) resolves per-order keys to Keygen master keys before any Keygen call; a missing/corrupt map **fails boot** (`src/dependencies/legacy-key-map.ts:5-18,45-61`), wired at `src/app.ts:86` via `withLegacyKeyAliases` (`src/dependencies/keygen-alias.ts:12-23`).

### 2.2 Per-route behaviour (`src/features/pro/routes.ts`)

| Route | Validation | Citation |
|---|---|---|
| `GET /pro/license/status` (+ `/v1/`) | Keygen `validate-key` scoped to fingerprint; **lazy-heals a missing machine** (§3) | `routes.ts:46-52,78-84,176-232`; `keygen-client.ts:119-131` |
| `POST /pro/license/activate` (+ `/v1/`) | Keygen `validate-key` then `POST /machines` with `Authorization: License <key>` — comment: "the old WC AM proxy only ever answered 501 here, so there is nothing to fall back to" | `routes.ts:54-64` (comment `:57-58`); `keygen-client.ts:133-194` (machine create `:152-168`) |
| `POST /pro/license/deactivate` (+ `/v1/`) | Keygen machine lookup by fingerprint, then `DELETE /machines/:id` | `routes.ts:66-76`; `keygen-client.ts:196-212` |
| `GET /pro/download/latest`, `/pro/download/:version` | **Key-level** Keygen check (`validateKey`, no machine required) — deliberately matching the old WC AM key-level download check | `routes.ts:106-120` (comment `:116-119`), `streamProDownload:130-174` |
| `GET /v1/pro/download/:version` | **Machine-scoped** Keygen check (`keygen-scoped`: valid + activated + active required) | `routes.ts:122-127`, `rejectInvalidLicense:295-314` |
| `GET /pro/update/:version` (+ `/v1/`) | No license check — release metadata from GitHub; the download URL it hands out is the gated one | `routes.ts:22-44`, `service.ts:43-87` |

Downloads stream the private `woocommerce-pos-pro` GitHub release asset using a GitHub App installation token (`routes.ts:146-173`; `service.ts:89-121`).

### 2.3 Who calls these routes

- The wc-am shim (§1) — the pre-1.9.7 fleet path.
- The Pro plugin's updater directly: `woocommerce-pos-pro/includes/Admin/Updaters/Pro_Plugin_Updater.php:50` (`$update_server = 'https://updates.wcpos.com/pro'`), status poll at `:708` (`/license/status` with key + instance + site metadata, `:696-707`, `:765`).

---

## 3. Do Keygen machines exist for current licenses? (Q3 — feasibility of #1530)

**Yes — three production paths create machine records, and the lazy backfill decided in #1530 already exists in code.**

1. **Explicit activation** (shim or plugin) — `keygen-client.ts:152-171` `POST /machines` (fingerprint = plugin `instance` hash, name = domain/site URL, metadata = site descriptors + `source: 'updates-server'` marker, `keygen-client.ts:295-302`).
2. **Status-poll lazy heal** — `routes.ts:176-232` `readLicenseStatus()`: when `validate-key` says valid + active but `inactive` for this fingerprint (no machine), it calls `activateLicense` once, so "pre-cutover installs … recover without a manual re-activate" (doc comment `:178-192`). Since the Pro fleet polls `/pro/license/status` from the WordPress updater on every update check (`Pro_Plugin_Updater.php:708`), **every currently-polling valid install either has a machine or gets one on its next ping.** This is precisely #1530's "auto-register machines from update-check pings"; what does *not* exist yet is instance-limit enforcement on top of it.
3. **The WooCommerce migration loaded historical activations as machines** — `wcpos-medusa/src/scripts/migrate-woocommerce/index.ts:177` (`loadMachines`), creating them at `POST /v1/machines` (`load-keygen.ts:152-180`); the inline comment "(POST /v1/licenses/{id}/machines is list-only and returns 404 — live-verified.)" (`load-keygen.ts:173`) indicates the script ran against the live Keygen.

Machines are also *maintained*: status polls refresh machine metadata/`lastSeenAt` (privileged-token path) or at least the display name (`routes.ts:207-221`; `keygen-client.ts:214-262`), and the wcpos-com account UI lists/deactivates them (`wcpos-com/src/app/api/account/licenses/[licenseId]/machines/[machineId]/route.ts:72`; `license-client.ts:307-329,373-381`). `activateMachine` exists in wcpos-com's client (`license-client.ts:336-366`) but has no production caller — activation is updates-server's job.

**Caveats for #1530 enforcement design:**
- The "explicitly deactivated → don't re-heal" guard is an **in-memory `Set`** (`routes.ts:19`, `:223`) — it resets on every deploy/restart, so a deactivated instance that keeps polling will be silently re-activated after a restart. Fine for healing; wrong once limits are enforced.
- The heal politely gives up when the machine limit is already reached (`routes.ts:189-192`, `:225-231`) — pre-existing over-limit fleets keep an honest `inactive` status.
- Coverage (how many valid licenses still lack machines) is a live question — §6.

---

## 4. Does the Keygen account's Ed25519 keypair exist? (Q4)

**Code cannot prove it either way; the seeding path makes "empty" entirely possible.**

- `wcpos-keygen/start.sh:56-78` — on first boot, if the account row is missing, it is created **via raw SQL**: `INSERT INTO accounts (id, name, slug, ed25519_private_key, ed25519_public_key, …) VALUES (…, '$KEYGEN_ED25519_PRIVATE_KEY', '$KEYGEN_ED25519_PUBLIC_KEY', …) ON CONFLICT (id) DO NOTHING` (`:63-75`). This bypasses the Rails `Account` model creation path (which is what normally generates an Ed25519 keypair for a new account) — consistent with #1534's finding.
- If the env vars were unset at first boot, the columns hold **empty strings**, and `ON CONFLICT DO NOTHING` means later runs (even with the vars set) never repair the row.
- `wcpos-keygen/env.example:32-35` marks both vars optional and ships them commented out; `wcpos-infra/services/keygen/README.md:34-36` documents the account env vars **without** the ED25519 pair. No other seeding script, SQL file, or infra config mentions `ed25519` anywhere in `updates-server`, `wcpos-infra`, or `wcpos-keygen` (repo-wide grep, 2026-08-11).
- Nothing in the current stack would have noticed empty keys: neither updates-server's client nor the plugins verify Keygen's `Keygen-Signature` response headers or signed license files — all trust is plain HTTPS JSON (`keygen-client.ts` has no signature handling; same for `wcpos-com/src/services/core/external/license-client.ts`).

**Exact live check needed** (either suffices):
1. On the Hetzner Postgres (via SSH per `reference_dev_server`): `SELECT ed25519_public_key IS NOT NULL AND ed25519_public_key <> '' AS has_key, length(ed25519_public_key) FROM accounts WHERE id = '<KEYGEN_ACCOUNT_ID>';`
2. Or inspect the Coolify env for the `wcpos-keygen` service for non-empty `KEYGEN_ED25519_PRIVATE_KEY` / `KEYGEN_ED25519_PUBLIC_KEY` **and** confirm they were present before first boot (the SQL never backfills, so env-now ≠ DB-now).

---

## 5. Other license datastores found (→ #1563)

The **Medusa-account↔Keygen-license mapping is order metadata, not a module table**:

- **Creation**: `wcpos-medusa/src/subscribers/order-completed.ts:103-104` — on order completion, resolve the Keygen policy from the product (`:271-272`), create the license via `KeygenClient.createLicense` (`wcpos-medusa/src/modules/keygen/keygen-client.ts:125-132`, `POST /v1/licenses`), and store the result in **`order.metadata.licenses`** (idempotency check `order-completed.ts:179-184`; also `license_processing_status` markers `:187-191`).
- **Resolution (wcpos-com account area)**: `wcpos-com/src/lib/licenses.ts:98-133` extracts `{license_id, license_key}` references from `order.metadata.licenses` / `license` / `license_data` and per-item metadata, skipping canceled orders (`:118-124`); `src/lib/customer-licenses.ts:297-317` walks the authed customer's Medusa orders and resolves each reference against Keygen `validate-key` (`:89-142`), with a legacy fallback reference from **`customer.metadata.wc_master_api_key`** (`:37-43`).
- **Legacy alias table**: `updates-server/data/legacy-key-map.json` (2,288 WC-AM-era alias keys → master keys; §2.1) is the third datastore-ish artifact — frozen, committed to the private repo.
- Keygen license `metadata` itself carries Discord-access grants (`wcpos-com/src/app/api/account/licenses/route.ts:26-32`) and whatever the migration stamped — i.e., Keygen is also used as a small key-value store per license.

So for #1563: the authoritative "which licenses does this Medusa account own" query = orders' metadata references (+ legacy customer metadata), resolved live against Keygen. Anything that rewrites order metadata (refunds, migrations) silently changes license ownership.

---

## 6. Remaining live verifications

| # | Question | Check |
|---|---|---|
| 1 | Ed25519 keys present on the `accounts` row? | §4 SQL against Keygen Postgres, or Coolify env + first-boot history |
| 2 | Machine coverage: how many valid licenses still have zero machines (sizing #1530 enforcement)? | `GET /v1/accounts/<id>/licenses` + machines relationship counts with the admin/product token (paging pattern already exists in `wcpos-com/src/services/core/external/license-client.ts:238-300`) |
| 3 | Did the migration's `loadMachines` populate prod (vs only licenses)? | Same machine listing; migration machines lack the `source: 'updates-server'` metadata marker (`keygen-client.ts:300`) |
| 4 | Keygen env values (`KEYGEN_ACCOUNT_ID`, token scope) actually deployed to updates-server | Coolify env for `updates-server` service |

Verified live already (2026-08-11, this note): DNS for `wcpos.com` / `updates.wcpos.com` / `license.wcpos.com`, and the production wc-am shim → updates-server → Keygen chain via a bogus-key status probe (§1.4).
