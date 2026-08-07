# License-enforcement hardening options for WCPOS Pro

- **Date:** 2026-08-07
- **Ticket:** [wcpos/woocommerce-pos#1534](https://github.com/wcpos/woocommerce-pos/issues/1534) (wayfinder research), feeds decision ticket #1535
- **Scope:** vendor hardening its own product. Defender's threat sketch only — no exploit recipes.
- **Standing constraints:** no obfuscation; never brick a paying customer's POS (offline stores, license-server outages, expired-card churn); the download gate stays the primary control.

---

## 1. Current state

### 1.1 The runtime gate is one boolean in `wp_options`

Every Pro feature gate resolves to a single stored value:

```php
// woocommerce-pos-pro/includes/wcpos-pro-functions.php:17-19
function woocommerce_pos_pro_activated() {
    return woocommerce_pos_get_settings( 'license', 'activated' );
}
```

which reads `get_option( 'woocommerce_pos_pro_settings_license' )['activated']`
(`/Users/kilbot/Projects/woocommerce-pos-pro/includes/Services/Settings.php:24,31-40,92-119`).

Stored fields are `instance`, `key`, `activated`, `product_id` — no expiry, no issued-at, no
signature, no nonce. `platform` and `version` are recomputed at read time.

On the `next` lane this is refactored behind a typed seam but the substance is identical:
`LicenseSettings::from_database()->is_activated()`
(`includes/Services/LicenseSettings.php`, `includes/Services/Settings/License_Section.php`,
`includes/Init.php`). `next` adds an `expires_at` field to the value object — currently unused
for gating, and the natural landing place for anything in section 5.

### 1.2 Activation never touches PHP

The activation request is made **by the admin's browser**, not by the server:

```ts
// woocommerce-pos/packages/settings/src/screens/license/index.tsx:64-115
const url = addQueryArgs('https://wcpos.com', {
  'wc-api': 'am-software-api', request: deactivate ? 'deactivation' : 'activation',
  instance: data?.instance, api_key: key, product_id: data?.product_id, ...
});
const response = await fetch(url, { method: 'GET', credentials: 'omit' }) ...
mutate({ key: deactivate ? '' : key, activated: !!response.activated });
```

`mutate()` POSTs to `wcpos/v1/settings/license`, whose handler merges the request body straight
into the option:

```php
// woocommerce-pos-pro/includes/API/Settings.php:79-85
public function update_license_settings( WP_REST_Request $request ) {
    $previous_settings = woocommerce_pos_get_settings( 'license' );
    $new_settings = array_replace_recursive( $previous_settings, $request->get_json_params() );
    return ProSettingsService::instance()->save_settings( 'license', $new_settings );
}
```

Arg validation is type-only (`is_bool`, `is_string` — `includes/API/Settings.php:46-69`); the
permission callback is `current_user_can( 'manage_woocommerce_pos' )` (`:92-94`). **The server
never verifies the activation with any license server.** It records the boolean the browser
handed it.

Live probe of the activation endpoint (invalid key) confirms the shape the browser trusts:

```
GET https://wcpos.com/?wc-api=am-software-api&request=status&api_key=invalid-probe-key&...
{"success":false,"activated":false,"error":"A customer account does not exist for this API Key.","code":404}
```

### 1.3 What the license actually gates today

| Location | Effect when unlicensed |
|---|---|
| `includes/Init.php` (`init_common`) | `StoresService` not booted |
| `includes/Services/Cloud_Print_Per_Outlet.php:24-30` | store-options filter skipped; per-store assignment filter still registers |
| `includes/Admin.php:58-74`, `includes/Admin/Menu.php:25-32` | Stores admin screens + menu hidden |
| `includes/Services/Settings.php:79-85` | `payment_gateways['pro_enabled']` unset (settings-UI flag only) |
| `woocommerce-pos/includes/Templates.php:465-470,495-502` | `plugin-pro` receipt template resolves to `null` |
| `includes/Admin/Updaters/Pro_Plugin_Updater.php:532-630,790-827` | admin notices |

Everything else loads unconditionally: all Pro REST controllers (`includes/API.php:66-76` —
products, variations, orders, refunds, settings, extensions, stores), the POS frontend templates
(`includes/Init.php` → `new Templates()`), Analytics duck-punching (`includes/API.php:105-112`),
the payment-gateway settings override. Every Pro REST permission callback checks WordPress
capabilities only, never license (`includes/API/Stores.php:181`,
`includes/API/Extensions_Action.php:91`).

**No Pro feature refuses to work with `activated => false`.** Bootstrap has no license
precondition at all: `woocommerce-pos-pro.php` → `Activator::run()` on `plugins_loaded:20` →
`new Init()`, unconditionally.

The POS client is no stronger. `withProAccess` renders the real page behind a removable blur
overlay (`monorepo-v2/packages/core/src/screens/main/components/pro-guard.tsx` — its own comment
says it remounts "to reset any devtools DOM tampering"), and `isPro` is derived from
`!!siteData.license?.key` (`packages/core/src/hooks/use-app-info.ts:215`) — a non-empty string,
not a validated state. The REST index deliberately reports `instance`/`status`/`expires` as empty
strings (`includes/API.php:44-57`), so the client has nothing better to key off.

### 1.4 Standing defect worth confirming: the gate is partly inert on `main`

`Init::init_common()` calls `woocommerce_pos_pro_activated()` **before**
`SettingsService::instance()` registers the `woocommerce_pos_license_settings` filter
(`includes/Init.php`, calls at ~:89 and ~:103). With no callbacks on that filter,
`get_license_settings()` returns `array()`, the `activated` key is missing, and the free
plugin's resolver returns a `WP_Error`:

```php
// woocommerce-pos/includes/Services/Settings.php:219-226
if ( ! isset( $settings[ $key ] ) ) {
    return new WP_Error( 'woocommerce_pos_settings_error', ... );
}
```

`WP_Error` is truthy, so `$pro_activated` is effectively always true on a cold request — meaning
`StoresService` and the cloud-print store options load for unlicensed sites too.
`tests/includes/Test_Init.php:68` passes only because the singleton is warm in the test bootstrap.
**`next` closes this** by registering `License_Section` at plugin-file load
(`woocommerce-pos-pro.php:280` on `origin/next`) rather than inside `init_common`. Worth a
targeted runtime confirmation before treating it as fact, but it is the expected reading of the
ordering.

### 1.5 Defender's threat sketch — where the bar sits

Ranked by how little work each takes. None of these require touching plugin PHP:

1. **Write the option.** `update_option( 'woocommerce_pos_pro_settings_license', [... 'activated' => true] )`, or a `PUT` to `wcpos/v1/settings/license` with `{"activated": true}` from any user who already holds `manage_woocommerce_pos`. One line. Fully licensed.
2. **Filter it.** A one-line mu-plugin on `woocommerce_pos_license_settings`
   (`woocommerce-pos/includes/Services/Settings.php:646`) returns a synthetic license array at
   priority > 10. Survives Pro updates because the filter lives in the free, GPL, WordPress.org-
   distributed plugin. Highest-leverage single hook in the system.
3. **Forge the transients.** `woocommerce_pos_pro_license_status` and
   `woocommerce_pos_pro_update_data` (`Pro_Plugin_Updater.php:37,44`) are plain `wp_options`
   transients with no provenance check — pre-seeding `['activated' => true]` silences every
   admin notice.
4. **Repoint the server.** `$_ENV['WCPOS_PRO_UPDATE_SERVER']` (`Pro_Plugin_Updater.php:71-73`)
   redirects both update and license-status traffic with no allowlist, and
   `wcpos_pro_load_env()` (`woocommerce-pos-pro.php:53-85`) will read a `.env` dropped next to the
   plugin to set it. `$_ENV['DEVELOPMENT']` additionally swaps the download URL to
   `http://localhost:8080` (`:117,143-145`). `validate_api_response()` (`:261-307`) checks only
   HTTP status and JSON shape, so a stub server satisfies it completely.
5. **Edit the PHP.** Always available, and the reason no client-side measure is ever more than a
   cost increase.

There is **no cryptography anywhere in the Pro licensing path**. A grep of `includes/` for
`sodium|openssl|ed25519|hash_hmac|signature|public_key` returns only `md5()` used for transient
key naming (`includes/Payments/Idempotency_Repository.php:193,203`,
`includes/API/Order_Refunds_Controller.php:146`).

**The only genuinely server-side control today is the package download** — the update payload
appends `key` and `instance` to the download URL
(`Pro_Plugin_Updater.php:147-153`) and the updates server refuses the zip without a valid key
(section 2.3). That control is real and it is doing all of the work.

---

## 2. What our own licensing stack can already do

### 2.1 Keygen CE at `license.wcpos.com`

Deployment is a thin wrapper (`/Users/kilbot/Projects/wcpos-keygen`): `FROM keygen/api:latest`
(unpinned), `KEYGEN_EDITION=CE`, `KEYGEN_MODE=singleplayer`, Coolify-deployed, Postgres + shared
Redis. The only real customizations are `start.sh` (supervises sidekiq + puma; creates the
account) and a `sidekiq.yml` overlay adding the `metrics` queue.

Policies were created by hand against the live API; the only record is the README table
(`/Users/kilbot/Projects/wcpos-keygen/README.md`): Pro Yearly (1 year, 2 activations) and Pro
Lifetime (forever, 2 activations). **Nothing in the repo documents `scheme`
(`ED25519_SIGN`), entitlements, `floating`, `requireHeartbeat`, `machineUniquenessStrategy`, or
`expirationStrategy`** — entitlements are not mentioned at all. Each WordPress install supplies
its `instance` as the Keygen machine `fingerprint`, verbatim, with no proof of possession.

**Blocking unknown — check this before planning anything cryptographic.** The Ed25519 keypair is
optional and commented out in `env.example`:

```
# ED25519 Keys for license signing (optional - generate with keygen if needed)
# KEYGEN_ED25519_PRIVATE_KEY=
# KEYGEN_ED25519_PUBLIC_KEY=
```

and `start.sh` creates the account with a **raw SQL `INSERT`**, bypassing the Rails model
callbacks that normally generate the keypair, guarded by `ON CONFLICT (id) DO NOTHING`. If those
env vars were unset at first boot, the account row holds empty-string keys and nothing in the
repo would ever backfill them. The public key is not recorded anywhere in the repo. Verify with
`SELECT ed25519_public_key FROM accounts` against the live DB (an unauthenticated
`GET /v1/accounts/{id}` returns `TOKEN_MISSING`, so this needs the admin token or DB access).
**If the key is empty, every signing-based option below starts with a key-rotation task.**

Also relevant: this instance is not endpoint-compatible with Keygen Cloud's docs — singleplayer
mode drops the `/accounts/{id}` path segment and `POST /v1/licenses/{id}/actions/revoke` returns
`404` while `/actions/validate` answers `200`
(`wiki/architecture/infra.md`, "Keygen CE"). **Probe any action against this host before building
on it.**

### 2.2 The updates-server proxy throws away everything cryptographic

`/Users/kilbot/Projects/updates-server` (Node LTS + Fastify 5, self-hosted on Coolify; Postgres
used only for request logging). Routes in
`/Users/kilbot/Projects/updates-server/src/features/pro/routes.ts`: legacy
`/pro/{update,license/status,license/activate,license/deactivate,download}` plus a clean `/v1/pro/*`
family.

The Keygen client declares the entire set of fields it will read
(`/Users/kilbot/Projects/updates-server/src/dependencies/keygen-client.ts`):

```ts
interface KeygenBody {
  meta?: { valid?: boolean; code?: string; detail?: string }
  data?: { id?: string; attributes?: { status?: string; expiry?: string } }
  errors?: Array<{ code?: string; detail?: string }>
}
```

No `meta.signature`, no `Keygen-Signature`/`Keygen-Digest`/`Date` header handling, no license
file or certificate endpoint, no `?encrypt=1`, no `included` relationships, no entitlements.
Keygen's response headers are discarded — only `response.json()` is read. The whole JSON:API
response collapses to `{ valid, activated, status }`, and `activateLicense` doesn't even relay
Keygen's answer, it synthesizes `{valid:true, activated:true, status:'active'}` after the machine
POST succeeds.

Repo-wide, the proxy does **no signing of any kind**: the only crypto is `timingSafeEqual` for
comparing broadcast/contacts bearer tokens (`src/lib/auth.ts`) and Octokit's GitHub App JWT.

Net: what reaches the plugin is unauthenticated JSON from `updates.wcpos.com`. Confirmed live:

```
GET https://updates.wcpos.com/pro/license/status?key=invalid-probe-key&instance=probe
{"status":404,"error":"Not Found","message":"A customer account does not exist for this API Key."}
```

### 2.3 The download gate — the control that actually works

`streamProDownload` validates against Keygen before a byte moves, then streams a **private GitHub
release asset** through a short-lived GitHub App installation token (never a redirect, so the
GitHub URL is never exposed):

```ts
const license = licenseMode === 'keygen-scoped'
  ? await deps.keygen.validateLicense(key, instance ?? '')   // fingerprint-scoped
  : await deps.keygen.validateKey(key)                        // key-level only
```

Two strengths in play:

- **`/pro/download/*` (legacy, what shipped plugins use):** key-level only. Any valid unexpired
  key downloads from any machine, activation count irrelevant. The route requires `instance` in
  the query string purely for shape-compatibility **and then ignores it** — the in-code comment
  says so explicitly ("Legacy WC AM download checks were key-level ... old installs keep
  downloading exactly as before").
- **`/v1/pro/download/:version`:** fingerprint-scoped and requires activation.

The zip itself is neither signed nor checksummed by the service. There is **no rate limiting on
any `/pro` route** (`rateLimit` is registered `global: false` and only `/profile` and broadcast
opt in).

One behaviour to know about before tightening anything: `readLicenseStatus` silently converts a
status *check* into a machine *create* when Keygen reports valid-but-inactive (a heal for
pre-cutover installs). The only suppressor is an **in-memory** `Set` of explicitly-deactivated
instances that does not survive a restart — so after any redeploy, a deactivated install that
keeps polling gets silently re-activated until it hits `maxMachines: 2`.

---

## 3. Ecosystem practice

| | Revalidation cadence | Grace on outage | What is gated | Signed? | Where the bypass sits |
|---|---|---|---|---|---|
| **EDD Software Licensing** | update info cached ~3h (`edd_sl_<md5>` transient, `strtotime('+3 hours')`); optional `weekly_check` license ping (default true) | Fail-open by omission — `json_decode` fails → `return false`, nothing disabled | **Updates only** in the shipped `EDD_SL_Plugin_Updater`. Feature gating is code each vendor writes against the stored status option | No. Docs mention a `checksum` field; the updater verifies nothing | The stored `edd_license_status` option, or the vendor's own `if ( 'valid' !== $status )`. One line |
| **Freemius SDK** | WP-Cron sync ~daily, only when tracked data changed | Last-known state persists in the serialized `fs_accounts` option — fail-open until the next successful sync | Vendor-configurable per plan: "keep the features, block only updates and support" is a supported setting. Post-*trial* always blocks premium features | HMAC-SHA256 on **requests** and webhooks (shared secret). Not an offline-verifiable license artifact | SDK is GPLv3 plaintext — patch `can_use_premium_code()`, or seed `fs_accounts`. Premium code is fully present in the premium ZIP |
| **Elementor Pro** | remote info transient **12h**; license data transient + **24h fallback option**; 60s in-flight request lock | Explicit and generous — remote failure falls back to the 24h stored copy; only a missing copy yields `http_error`. Concurrent requests return error defaults rather than blocking | `is_license_active()`, `is_license_expired()`, **`is_licence_has_feature()`** against a `features` array — individual features are entitlement-gated, not just updates. On expiry: limited access to Pro features, no updates/support/template library. Editor not bricked | No — HTTP 200 + `json_decode` | Publicly demonstrated: a `pre_http_request` filter in a **separate mu-plugin** returns a hardcoded `features` array. Zero modification of Elementor's own files, because the response is unauthenticated |
| **ACF PRO** | Not published | Not published | Narrowest and deliberately so: cannot **create/edit** PRO fields or use Options Pages / ACF Blocks unlicensed, but "editing and display of field data anywhere outside the ACF admin screens will be unaffected." Runtime/front-end never gated | No | Nulled distributions exist; activation via key, `ACF_PRO_LICENSE` constant, or WP-CLI |

Sources: [EDD SL API](https://easydigitaldownloads.com/docs/software-licensing-api/) ·
[EDD_SL_Plugin_Updater source](https://github.com/publishpress/EDD-SL-Plugin-Updater/blob/master/EDD_SL_Plugin_Updater.php) ·
[EDD updater implementation](https://easydigitaldownloads.com/docs/software-licensing-updater-implementation-for-wordpress-plugins/) ·
[Freemius licensing](https://freemius.com/help/documentation/wordpress-sdk/integration/software-licensing/) ·
[Freemius licensing FAQ](https://freemius.com/help/faq/licensing/) ·
[Freemius data practices](https://freemius.com/privacy/data-practices/) ·
[Freemius API auth](https://freemius.com/help/api/) ·
[Elementor `license/api.php`](https://github.com/yangm97/elementor-pro/blob/master/license/api.php) ·
[Elementor: cancelling Pro](https://elementor.com/help/what-happens-to-my-site-if-i-cancel-elementor-pro/) ·
[elementor-pro-activator](https://github.com/wp-activators/elementor-pro-activator/blob/main/main.php) ·
[ACF license activations](https://www.advancedcustomfields.com/resources/license-activations/)

### 3.1 Nobody signs. Here is why signing still matters, and where it stops

**None of the four verify a server response against a public key embedded in the plugin.** EDD has
an unverified `checksum`; Elementor and EDD `json_decode` and trust; Freemius HMACs *requests*
with a shared secret, which authenticates the caller but is not an offline-verifiable artifact.

The reason signing is not futile is visible in the Elementor bypass: it is a **`pre_http_request`
filter in a separate mu-plugin**, requiring zero edits to Elementor's own files, and it therefore
survives every Elementor update. Signature verification kills that entire class — response
spoofing, DNS/hosts redirection, a fake update server, a filter that fabricates a payload — because
the attacker can no longer produce a payload the plugin accepts.

The reason signing is only a cost increase is equally plain: the verifier is PHP on the
licensee's disk and the public key is a constant in that source. Nobody forges a signature; they
delete the `verify()` call, or swap the embedded public key for one they hold the private half of.
Signing moves the bypass from **"drop in a mu-plugin, done forever"** to **"patch the vendor's PHP
and re-patch on every release."** Nulled release channels re-patch every release as routine, so the
ceiling is unchanged — but the marginal, self-service, survives-updates bypass is gone.

### 3.2 Norms and the ceiling

- **WordPress.org Guideline 1** requires GPL-compatible licensing for anything in the hosted
  directory, and **Guideline 5** forbids trialware outright: "Plugins may not contain
  functionality that is restricted or locked, only to be made available by payment or upgrade"
  ([guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)).
  This is why the free/premium split exists at all, and it constrains what the **free** plugin may
  contain — relevant to us, because our gate currently lives partly in free.
- **PHP in plugins is held to be GPL-derivative**, so redistribution — including by "GPL
  marketplaces" — is legal. Enforcement leverage narrows to trademarks and to the distributor's
  own added malware
  ([analysis](https://gschoppe.com/wordpress/plugins-and-themes-open-source/),
  [GPL marketplaces](https://daan.dev/blog/wordpress/nulled-plugins/)).
- **Vendor consensus is to not spend engineering here.** Freemius' CEO reports a "low success
  rate" for takedowns "due to the GPL/open-source nature of WordPress plugins and themes," puts
  piracy at roughly 0.2% of potential customers, and recommends investing in value-in-the-service
  instead — updates, support, hosted APIs, security patches
  ([Freemius](https://freemius.com/blog/nulled-wordpress-plugins-themes-support-protection/)).
  The security case against nulled copies does the rest of the persuading
  ([Sucuri](https://blog.sucuri.net/2026/03/the-security-risks-of-using-nulled-wordpress-plugins.html)).

### 3.3 Keygen CE gives us more than any of them — for free

CE and EE are **the same codebase**, selected at runtime by `KEYGEN_EDITION`; Keygen Cloud is
itself an EE instance in multiplayer mode
([Keygen](https://keygen.sh/blog/all-your-licensing-are-belong-to-you/),
[self-hosting](https://keygen.sh/docs/self-hosting/)).

**EE-only, complete published list:** request logs, event/audit logs, environments, enterprise
RBAC permissions, Cloud↔EE import/export, the OCI/Docker license-gated registry, SSO/SAML,
dedicated support ([self-hosting](https://keygen.sh/docs/self-hosting/),
[README](https://github.com/keygen-sh/keygen-api/blob/master/README.md)).

**In CE — i.e. everything this ticket asked about:**

- **Ed25519 signing** (also ECDSA P-256, RSA-2048 PSS/v1.5, JWT RS256); signed license keys with
  base64url-embedded datasets ([cryptography](https://keygen.sh/docs/api/cryptography/)).
- **License files / certificates / offline verification** — "All licenses support being checked out
  into a license file"; signed, optionally AES-256-GCM encrypted, verified locally against a
  hardcoded account ID + public key, **no network**
  ([cryptography](https://keygen.sh/docs/api/cryptography/),
  [offline licensing](https://keygen.sh/docs/choosing-a-licensing-model/offline-licenses/)).
- **Signed API responses** — the `Keygen-Signature` response header permits offline verification of
  a validation result's authenticity ([licenses API](https://keygen.sh/docs/api/licenses/)).
- **Entitlements** — Keygen's own docs use "gate CE vs EE editions" as the worked example.
- **Machine activation, `maxMachines`, node-locked and floating, heartbeats**
  ([machines API](https://keygen.sh/docs/api/machines/)).
- **`validate-key`** with `meta.valid` / `meta.code` (`VALID`, `EXPIRED`, `SUSPENDED`,
  `NO_MACHINES`, `FINGERPRINT_SCOPE_MISMATCH`, …) and scoping by product/policy/fingerprint/
  machine/version/checksum ([licenses API](https://keygen.sh/docs/api/licenses/)).

Two accuracy caveats: Keygen publishes no exhaustive CE feature matrix (the above rests on
"same codebase" + the EE list being stated as the complete set EE enables + the relevant docs
carrying no tier notes), and CE/EE gating is **not** the same as Keygen *Cloud* tier gating (free
Cloud caps at 100 active licensed users; the Distribution API is Std/Ent only —
[pricing](https://keygen.sh/pricing/)). Neither applies to a self-hosted CE instance.

**We are paying for none of the primitives we are not using.** Signed validation responses and
offline license files are already sitting in `license.wcpos.com`; the gap is entirely in our proxy
(section 2.2) and our plugin (section 1).

---

## 4. Which Pro features could be server-gated

A check only becomes unnullable when the *capability itself* lives on our infrastructure — the
customer's PHP can lie about the answer, but it cannot manufacture the service.

Today Pro talks to exactly one remote host. A grep of `woocommerce-pos-pro/includes/` for
`https://` yields only `wcpos.com` (marketing/account links), `updates.wcpos.com` (update +
license status), a jsDelivr confetti script, and a WooCommerce docs link. **Pro has essentially
zero runtime server dependency.** That is the structural reason enforcement is weak, and it is
also the lever.

| Feature | Where the work happens | Server-gateable? |
|---|---|---|
| Plugin updates / zip | `updates.wcpos.com` → private GitHub release | **Already gated** (section 2.3). Strongest control we have. |
| Cloud Print relay | `cloudprint.wcpos.com` (Go relay, `wcpos/wcpos-cloudprint`) — merchant printers poll *us*; the relay holds the site registry and forwards to the origin | **Yes, genuinely.** Registration is server-issued (`site_key`, `hint_secret` in the `woocommerce_pos_cloud_print_relay` option, `woocommerce-pos/includes/Services/Cloud_Print_Relay_Service.php`). Refusing to register or forward for an unlicensed site cannot be patched around locally, because the printer firmware needs a TLS endpoint the site cannot provide. **Caveat: the relay is a free-plugin feature and ships on by default** (house rule: merchant services are zero-config and opt-out only by code filter). Gating it on Pro would be a product change, not just an enforcement change. The Pro-only slice — per-outlet print rules (`includes/Services/Cloud_Print_Per_Outlet.php`) — is computed locally and is *not* gateable. |
| Star `stario.online` hosted relay (if built) | Star's cloud, keyed by our `Star-Api-Key` | Yes — the key is ours to issue per-license. |
| Translations | `translations` pipeline / `TRANSLATION_VERSION` | Weakly — withholding translations degrades rather than gates, and it hurts the wrong users. |
| Extension catalog install/activate | `includes/API/Extensions_Action.php` → **public GitHub releases** (`update_plugins_github.com`, `includes/Admin/Updaters/Extensions_Updater.php`) | Not today. Would become gateable if extension zips moved behind the updates server like the Pro zip. |
| Receipt templates, Stores/outlets, refund orchestration, analytics, POS REST controllers | Entirely local PHP | **No.** These are the bulk of Pro and cannot be server-gated without inventing a server dependency that hurts offline stores. |
| POS client (Electron/mobile) sync + login | Merchant's own WordPress | No. |

The honest read: **cloud print is the only meaningful server-gateable Pro-adjacent capability we
have**, and gating it conflicts with an existing product commitment. Anything else would mean
newly routing local work through our servers, which is exactly the offline-hostile design the
constraints rule out.

---

## 5. Menu of hardening options

Ranked by effort. "Bypass cost raised" is measured against today's baseline of *one option write
or one mu-plugin filter, surviving all updates*.

### Tier 0 — server-side only, zero risk to any customer site

These change nothing on a merchant's site, so they cannot brick anyone. Best ratio in the menu.

**O1. Sunset the key-level download gate.** `/pro/download/*` validates the key only and
explicitly ignores the `instance` it demands; `/v1/pro/download/:version` is fingerprint-scoped and
requires activation. Point new Pro builds at `/v1/`, keep legacy registered for old installs, and
put a sunset date on it.
- *Effort:* S (one URL in `Pro_Plugin_Updater::update_plugins()`, plus a release).
- *Defends:* one leaked key feeding unlimited installs — the single most common real-world sharing
  pattern. Makes `maxMachines: 2` mean something at download time.
- *Doesn't defend:* anything after the zip is on disk.

**O2. Rate-limit `/pro/*`.** There is none today (`rateLimit` is `global: false`; only `/profile`
and broadcast opt in).
- *Effort:* S. *Defends:* key enumeration, scripted mass-download. *Doesn't defend:* a single valid key.

**O3. Persist the deactivation set.** `explicitlyDeactivatedLicenseInstances` is an in-memory
`Set`; every redeploy silently re-activates deactivated installs that keep polling.
- *Effort:* S. *Defends:* deactivations actually sticking — currently a correctness bug that reads
  as leniency. *Doesn't defend:* anything else. **Do this regardless of posture.**

**O4. Write the policy configuration down.** Nothing in `wcpos-keygen` records `scheme`,
entitlements, `floating`, `requireHeartbeat`, `machineUniquenessStrategy`, or `expirationStrategy`
— the policies were created by hand and exist only as IDs in a README table.
- *Effort:* S. *Defends:* nothing directly; it is the prerequisite for every option below being
  reasoned about rather than guessed at.

### Tier 1 — make the honest path correct (small, plugin-side, low risk)

**O5. Move activation server-side and make `activated` non-writable by the client.** Today the
admin's *browser* calls `wcpos.com?wc-api=am-software-api` and then POSTs `{"activated": true}` to
WordPress, which stores it verbatim (section 1.2). Instead: the REST route accepts a **key only**,
PHP calls `updates.wcpos.com/v1/pro/license/activate` itself, and derives `activated` from the
response. Reject a client-supplied `activated` outright.
- *Effort:* S–M (one REST handler, one HTTP call, drop one arg; the client screen already has the
  key). Note this also removes a browser→third-party-origin `fetch` from wp-admin.
- *Defends:* the one-`PUT` bypass; makes the stored flag a *cache of a server answer* rather than a
  client assertion. Closes the gap where anyone holding `manage_woocommerce_pos` licenses the site.
- *Doesn't defend:* direct `update_option`, the free-plugin filter, PHP edits.

**O6. Fix the inert gate (section 1.4) and stop gating through a free-plugin filter.**
`woocommerce_pos_license_settings` in the **free, GPL, wordpress.org-distributed** plugin is the
highest-leverage single hook in the system, and on `main` the gate reads it before it is
registered so `$pro_activated` is a truthy `WP_Error`. `next` already registers `License_Section`
at plugin-file load.
- *Effort:* S on `next` (verify + test); M to backport to `main`.
- *Defends:* makes the existing gate actually fire; removes one filter as a supported override
  path. *Doesn't defend:* a filter on whatever replaces it — but a Pro-side seam is at least
  something we control and can sign against.

**O7. Store a coherent record, gate on a derived predicate.** Persist `key`, `instance`, `status`,
`expires_at`, `last_validated_at` (and later the signed blob) together; replace the raw boolean
read with `LicenseSettings::is_active()` that requires internal consistency.
- *Effort:* S on `next` — `LicenseSettings` already carries `expires_at`.
- *Defends:* raises "flip one bool" to "construct a coherent record." Marginal on its own; it is
  the substrate O8/O9 need.

### Tier 2 — cryptographic verification (the real step change)

**O8. Relay and verify Keygen's signature.** Have updates-server pass through
`Keygen-Signature` / `meta.signature` (today it discards every Keygen header and re-serializes to
`{valid, activated, status}` — section 2.2), embed the account Ed25519 public key in Pro, and
verify with `sodium_crypto_sign_verify_detached()` — core PHP since 7.2, no dependency, and Pro
requires 7.4.
- *Effort:* M (proxy passthrough + PHP verify + tests). **Prerequisite:** confirm the live
  `accounts.ed25519_public_key` is not an empty string (section 2.1) — if it is, this starts with
  a key-generation and re-signing task.
- *Defends:* the entire response-spoofing class — `WCPOS_PRO_UPDATE_SERVER`, a `.env` dropped next
  to the plugin, `DEVELOPMENT` mode, DNS/hosts redirection, a `pre_http_request` mu-plugin filter
  (the exact published Elementor bypass), and forged status transients if the transient stores the
  signed blob and re-verifies on read.
- *Doesn't defend:* deleting the verify call, or swapping the embedded public key for one whose
  private half the attacker holds. Both require editing Pro's PHP on every release.

**O9. Offline license file (Keygen checkout).** Check out a signed, expiring license file at
activation; verify it locally on load against the embedded public key; refresh opportunistically
with a long TTL (30 days) and a longer hard expiry.
- *Effort:* M–L (proxy checkout endpoint, storage, verification, refresh scheduling).
- *Defends:* same class as O8, **plus** it is the offline-tolerant answer — a store with no
  internet keeps working for the file's TTL with zero network calls, which no cadence-based scheme
  can promise. It also carries entitlements, so per-plan feature differences become expressible.
- *Doesn't defend:* the same PHP-edit floor. Also adds a new failure mode: a customer whose file
  lapses while offline. Mitigate with a long TTL and a soft-fail grace (O10).

**O10. Revalidate on a cadence with an explicit, generous grace.** Weekly server-side
`check` via WP-Cron plus on-demand. Rules that must hold: a **network failure never downgrades
state** (fail to last-known-good, as Elementor and Freemius both do); only an *authoritative
negative* (`EXPIRED`, `SUSPENDED`) changes anything; and an authoritative negative starts a
**30-day countdown with escalating admin notices**, not an immediate change.
- *Effort:* S–M once O5/O7 exist.
- *Defends:* expired and refund-suspended keys continuing indefinitely — the churn case, which is
  probably a bigger revenue line than piracy.
- *Doesn't defend:* anything local. **Risk to watch:** this is the option most capable of hurting
  a paying customer if the grace logic is wrong. Elementor's 24h fallback + in-flight lock is the
  pattern to copy.

### Tier 3 — gate features (product decision, not just engineering)

**O11. Choose what actually refuses to run.** Today, nothing does (section 1.3). The three
postures in the ecosystem:
- *ACF PRO posture* — block **authoring** in admin, never block runtime/front-end. For us: Stores
  management screens, per-outlet cloud-print configuration, receipt-template editing, the Pro
  receipt template. The till never notices.
- *Elementor posture* — degrade Pro features to limited/read-only after grace, keep the base
  product working.
- *Hard block* — nobody credible does this. For a POS it means a merchant cannot take money.
  **Out of scope under the standing constraint.**
- *Effort:* M–L, and every increment adds a way to hurt a paying customer.
- *Defends:* makes an unlicensed install visibly worse without touching the till.
- *Doesn't defend:* the patcher, who removes the gate.

**O12. Server-gate a cloud-backed capability.** Per section 4, cloud print is the only real
candidate, and it collides with the standing commitment that merchant services ship on by default,
opt-out by code filter only. The defensible slice is *Pro-tier* relay behaviour (per-outlet
routing resolved relay-side, priority, quotas), not the base relay.
- *Effort:* L, spanning `wcpos-cloudprint`, free, and Pro.
- *Defends:* **genuinely unnullable** — the merchant's PHP can lie about the license, but it cannot
  manufacture a relay endpoint that legacy printer firmware can complete a TLS handshake against.
- *Doesn't defend:* the other 90% of Pro, which is local PHP. Also risks degrading a *free* feature
  merchants already depend on.

### Rejected

- **Obfuscation / encoders.** Ruled out by the ticket and correctly so: hostile to debugging,
  trivially decoded, and it makes every support ticket worse for the paying majority.
- **Kill switches / disable-on-failed-check.** Directly violates the never-brick constraint. A POS
  that stops selling because our license server had a bad afternoon is a worse outcome than any
  amount of piracy — and `license.wcpos.com` has already had a 5.5-hour outage
  (`wiki/operations/incidents/2026-05-28-keygen-router-conflict-outage.md`).
- **Client-side (POS app) gating as enforcement.** `withProAccess` is a removable overlay whose own
  comment concedes DOM tampering. Keep it as UX and upsell; never count it as enforcement.
- **Hashing/deriving the key into a checksum the plugin validates locally.** Pure security theatre
  once the algorithm is in the shipped source.

---

## 6. Recommended posture

**Make the honest path correct, put the real enforcement on our own servers, and require an
attacker to edit our PHP on every release. Then stop.**

Concretely, in order:

1. **Do the Tier 0 server work now** (O1–O4). It cannot hurt a customer, it is days of work, and
   O1 alone converts a leaked key from "unlimited installs" to "two." O3 is a plain bug fix.
2. **Make `activated` server-derived** (O5) and land it on the `next` seam, with O6/O7 alongside.
   This is the change that matters most per line of code: it turns a client assertion into a
   cached server answer.
3. **Verify signatures** (O8), gated on confirming the live Ed25519 key exists. Prefer the
   **license-file form** (O9) if the effort delta is small, because it is the only option that is
   *simultaneously* stronger and better for offline stores. This is the step change: it deletes
   the entire mu-plugin/spoofing bypass class that Elementor demonstrably lives with.
4. **Weekly revalidation, 30-day grace, fail-open on network errors** (O10). Copy Elementor's
   fallback-and-lock pattern rather than inventing one.
5. **Gate the ACF PRO surface only** (O11, first posture): updates, Pro admin authoring screens,
   Pro receipt templates. **Never** the REST order/product/refund controllers, never the POS app's
   ability to take a payment, never anything a till touches mid-shift.
6. **Do not** server-gate the base cloud-print relay (O12). Revisit only for genuinely Pro-tier
   relay behaviour, as a product decision.

That lands Pro at roughly Elementor's bar with better cryptography and ACF PRO's blast radius —
proportionate for a product whose main defensible asset is the update stream, not the code.

### What this cannot achieve

**A determined patcher wins, permanently.** The verifier, the public key, and the gate all ship as
readable PHP on the licensee's disk; PHP in WordPress plugins is GPL-derivative, so redistributing
a patched build is legal, and nulled channels re-patch every release as routine. Every option above
raises *marginal* cost — the self-service, survives-updates, one-line bypass disappears — but none
of them makes Pro unrunnable without a license, and any design that tried would brick paying
merchants first. The download gate remains the only control that genuinely holds, which is an
argument for investing in the value of the update stream (O1–O3) ahead of anything on the
merchant's site.

### Verification prerequisites before #1535 decides

- [ ] `SELECT ed25519_public_key FROM accounts` on the live Keygen DB — non-empty? (Blocks O8/O9;
      an unauthenticated `GET /v1/accounts/{id}` returns `TOKEN_MISSING`, so this needs the admin
      token or DB access.)
- [ ] Probe `POST /v1/licenses/{id}/actions/check-out` against `license.wcpos.com` — singleplayer
      CE does not implement every documented action (`/actions/revoke` 404s while `/actions/validate`
      answers 200). Confirm before designing on license files.
- [ ] Confirm the section 1.4 ordering defect at runtime (one assertion that `woocommerce_pos_pro_activated()`
      is `false`, not a `WP_Error`, on a cold `init` with no license set) and confirm `next` fixes it.
- [ ] Record the current policy attributes (`scheme`, `floating`, `requireHeartbeat`,
      `machineUniquenessStrategy`, `expirationStrategy`, entitlements) for both products.
- [ ] Decide the lane: the `next` `LicenseSettings` / `License_Section` /
      `Update_Server_Client\LicenseStatus` seam is where all of this belongs.
