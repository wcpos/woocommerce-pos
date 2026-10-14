## 1. **Claim check**

**Evidence:** Observed source/history only; no tests, network or Docker used. Runtime reproduction remains **Unverified**. No files changed.

Citation prefixes:
- `P:` Pro `next`, supplied checkout, `82cb93e`.
- `PM:` released Pro checkout, observed `241c3cb`.
- `F:` Free `next`, `92b5d970`; `FM:` released Free, `9b9f7613`.
- `C:` supplied client checkout; `Wiki:` supplied wiki.
- `WP:` `/Users/kilbot/.wp-env/wp-env-arch-runner-next-0522a9ca/WordPress`.
- `WC:` `/Users/kilbot/.wp-env/wp-env-arch-runner-next-0522a9ca/woocommerce`.

### Claims and corrections

1. **HOLDS — the early translation call remains.**  
   `P:includes/Activator.php:40,46–48,101–125,184–200` → `P:includes/updates/update-2.0.0.php:10` → `P:includes/Registers.php:43–54,62–77`. A version below 2.0.0 runs the backfill synchronously at `plugins_loaded@20`; a published store without an active register reaches `__( 'Register', 'woocommerce-pos-pro' )` at line 74.

2. **WRONG — “on every upgraded Pro site.”**  
   The call requires a published `wcpos_store` lacking an active register (`P:includes/Registers.php:44–53,65–74`). The diagnostic additionally requires an unloaded domain and a resolvable translation path; WordPress returns before the warning when that path is absent (`WP:wp-includes/l10n.php:1430–1445,1481–1494`). English/no-catalogue installations, stores already provisioned, and domains previously loaded need not emit it.

3. **WRONG — the exact warning boundary is “before init.”**  
   The inspected core checks **neither doing nor having completed `after_setup_theme`**, although its message recommends `init`; the diagnostic identifies its introduction as 6.7.0 (`WP:wp-includes/l10n.php:1444–1454`). `plugins_loaded` precedes both hooks (`WP:wp-settings.php:630,757,779`), so the reported Pro path is nevertheless early. The local core identifies itself as 7.1; no 6.7 runtime was exercised (`WP:wp-includes/version.php:19`).

4. **WRONG — Pro merely relies on WordPress automatic loading.**  
   Pro explicitly constructs its translation loader at **`init@20`** (`P:includes/Init.php:42,51–52,169–172`). It extends Free’s CDN/file loader (`P:includes/i18n.php:22–31`), which uses `determine_locale()`, supports uploads-directory fallback, and calls `load_textdomain()` (`F:includes/i18n.php:150–186,197–216,338–367,389–392`). The wiki confirms PHP translations come from `wcpos/translations`, not the former GlotPress pipeline (`Wiki:support/international.md:19–28`).

5. **HOLDS, with correction — the string becomes persistent, user-visible data; “site locale” is not guaranteed.**  
   Pro supplies the translated name; Free inserts it unchanged (`P:includes/Registers.php:72–76`; `F:includes/Services/Register_Store.php:81–90`). The admin displays and edits `row.name` (`F:packages/settings/src/screens/registers/index.tsx:195–203`). WordPress chooses user locale for admin/certain JSON requests and otherwise falls back to site locale, with filters on both paths (`WP:wp-includes/l10n.php:124–176`). There is no explicit site-locale selection here.

6. **HOLDS — Free already defers its upgrade ladder; the rationale needs distinguishing.**  
   Free’s ladder waits for `woocommerce_init` to avoid callbacks running before WooCommerce services exist (`F:includes/Activator.php:485–492`; `FM:includes/Activator.php:401–408`). Its separate role-activation deferral explicitly cites translations (`F:includes/Activator.php:457–469`). No direct `__()`, `_x()`, `esc_html__()` or `esc_attr__()` calls were found in either Free upgrade directory. Free does indirectly translate its default register during table installation (`F:includes/Activator.php:225–231,744`; `F:includes/Services/Register_Store.php:113–117`).

7. **WRONG — PHPUnit cannot test this because bootstrap already fired the hooks.**  
   Bootstrap does load WordPress before test methods (`P:tests/bootstrap.php:43–50`), but `did_action()` reads mutable `$wp_actions` (`WP:wp-includes/plugin.php:686–693`). A narrowly isolated test can save/reset/restore the relevant counters and translation state. Clearing **only `init`** would falsely pass because `after_setup_theme` remains recorded.

8. **WRONG — the migration lacks any execution coverage.**  
   There is no dedicated `Test_Update_2_0_0.php`, but `P:tests/includes/Test_Registers.php:111–128` calls `db_upgrade( '1.10.0', '2.0.0' )`, exercising the file and repeat/activation behavior. What is missing is **pre-init translation timing coverage**.

9. **HOLDS — this belongs to `next`, not `main`.**  
   Released Pro retains the early ladder but has neither the 2.0.0 entry nor activation backfill (`PM:includes/Activator.php:94–95,184–200`). Its upgrade files contain no direct translation calls in the searched forms. This specific defect is unreleased; this is not a claim that every indirect callback in historical upgrades is translation-safe.

**History since October 12:** Pro’s relevant history contains `a6ae9a5`, changing register authorization/reach, not the backfill; the added import shifts the translation to line 74. No timing fix landed in Pro’s Activator/update/i18n/Init files. Free’s `e9867049` and `da63df47` added deferred-upgrade error handling and version-latch restoration, now visible at `F:includes/Activator.php:506–517`; they do not fix Pro.

### Minimal failing PHPUnit test — proposed, not run

Add proposed class `Test_Update_2_0_0 extends WP_UnitTestCase` in Pro’s `tests/includes/updates/Test_Update_2_0_0.php`.

- **Fixture:** install register storage; create one published store, then remove its automatically seeded register; include a draft store and a published store with an existing named register.
- Save the `init`/`after_setup_theme` action counters, `init` hook, Pro-domain `$l10n`/`$l10n_unloaded` entries, and textdomain registry. Clear both counters and Pro-domain entries.
- Use a cloned registry with a known Pro-domain/locale path (`WP:wp-includes/class-wp-textdomain-registry.php:140–143`). A domain-scoped `pre_load_textdomain` filter returning false avoids catalogue I/O **after** the real JIT warning check (`WP:wp-includes/l10n.php:1444–1466,736–755`).
- **Request/act:** directly include the update file, representing its `plugins_loaded` invocation. Collect `doing_it_wrong_run` events matching `_load_textdomain_just_in_time` and the Pro domain.
- **Assertions:** no matching diagnostic and no newly seeded register before `init`. **Both fail on today’s source under this fixture.**
- Mark `after_setup_theme` completed; run an isolated `init` hook containing the migration callback. Assert exactly one active register for the missing published store, none for the draft, and the existing name unchanged.
- Restore all saved state and hooks in `finally`. Do not replay the application’s entire bootstrap.

Add checks for direct execution after `init`, version-write timing, and the Free-default ordering described below. These are test designs, not claimed passing results.

## 2. **Impact**

**Severity: Low.** It is a conditional, normally one-upgrade-request diagnostic plus a potentially untranslated default name—not demonstrated payment loss, authorization exposure or recurring outage.

- **Merchant/developer:** debug output/log noise during the first request crossing the 2.0.0 migration. The version is subsequently advanced, so successful upgrades do not repeat this migration (`P:includes/Activator.php:102–104,121–124`).
- **Cashier:** potentially affected if their request triggers the upgrade and displayed diagnostics contaminate its response. This remains **Inferred**, not reproduced. The client already attempts recovery from text surrounding JSON (`C:packages/core/src/screens/main/hooks/use-rest-http-client/index.ts:283–295`); no guaranteed recovery or failure is claimed.
- **Attacker:** no distinct exploit demonstrated. Normal public traffic can trigger the upgrade because `run()` has no admin gate (`P:includes/Activator.php:46–48`).
- **Logging correction:** `WP_DEBUG_LOG` alone does not enable the diagnostic. `_doing_it_wrong`’s PHP error requires `WP_DEBUG`; display/log behavior then depends on configuration (`WP:wp-includes/functions.php:6123–6136`; `WP:wp-includes/load.php:549–570`).

**Reachability:** no register REST request is necessary—the migration runs during ordinary bootstrap. The supplied client makes WCPOS requests through its v2 base (`C:packages/core/src/screens/main/hooks/use-rest-http-client/index.ts:238–255`). Searches found no `/registers`, `register_id`, or `register.name` consumers in its non-test app/package source. That does **not** make the migration unreachable.

The `next` admin definitely consumes the resulting records: GET `/wcpos/v2/registers?status=all`, then displays/edit-saves names (`F:packages/settings/src/screens/registers/index.tsx:36–53,195–203`). Binary release deployment was not evaluated.

## 3. **Cost and risk**

**Smallest supported patch:** Pro’s update file only, approximately **8–12 non-test lines**, plus approximately 100–160 test lines. Keep synchronous execution when `init` has already fired; otherwise schedule the backfill at **`init@-1`**, not the default priority.

**Why priority matters — an overlooked behavior change:**  
Free runs its upgrade during WooCommerce initialization, which the inspected WooCommerce hooks at `init@0` (`WC:includes/class-woocommerce.php:310,900`). Free then creates an unassigned default register if **no active register anywhere** exists (`F:includes/Activator.php:744`; `F:includes/Services/Register_Store.php:113–117`).

Today, Pro’s store backfill happens first and can prevent that Free default. Moving it to ordinary `init@10`, after Pro translations at `init@20`, or behind Free on `woocommerce_init` can instead create **an extra unassigned register** on a combined upgrade. This is **Inferred directly from ordering**, not runtime-tested. `init@-1` preserves Pro-before-Free seeding while passing WordPress’s translation-safety boundary.

**Pro coupling and compatibility:**
- Pro uses Free’s `Register_Store`; no Free implementation or public signature needs changing.
- Leave `Registers::backfill_store_registers()` synchronous for existing direct callers and activation (`P:includes/Activator.php:94–95`; `P:tests/includes/Test_Registers.php:119–127`).
- No REST shape, public filter, schema or stored-name rewrite is required.
- Migration infrastructure is explicitly **High-tier** in the review (`F:.claude/research/2026-10-12-arch-review-next/verdict-lifecycle.md:32–35`), despite this defect’s Low severity. Avoid changing unrelated migration/locking behavior.

**Behavior changes / regressions**
- The proposed callback runs later in the same normal request, before WooCommerce’s inspected `init@0` initialization.
- The actual version key is **`woocommerce_pos_pro_db_version`**, not `wcpos_pro_version` (`P:includes/Activator.php:22`). With step-only deferral, its write and lock release occur **before the backfill executes** (`:121–124`).
- Consequently, a request ending before `init` can permanently skip this default seeding. Existing code already permits manual repair and catches register-storage exceptions (`P:includes/Registers.php:79–81`); accepting this bounded missing-default risk needs to be explicit. No retry/lock subsystem is proposed.
- Request-locale resolution can differ later in bootstrap; existing register names remain untouched.
- `init@-1` removes the early-load diagnostic but **does not wait for Pro’s CDN/uploads translation loader at `init@20`**. It therefore does not promise to cure every English default on a multilingual site.

All proposed implementation belongs to **Pro `next`**. No `main` patch or Free companion change is supported for the narrow fix.

## 4. **Options**

### Ruling: defer this backfill or move the entire ladder?

| Option | Concrete benefits | Concrete costs |
|---|---|---|
| **A. Defer this step to `init@-1`, with an already-initialized branch** | ~8–12 lines; removes the actual early JIT condition; preserves Pro-before-Free register seeding; leaves historical migrations alone. | Backfill runs outside the migration lock and after the version write; custom Pro translation loader still runs later. |
| **B. Defer this step until after Pro’s `init@20` loader** | ~8–12 lines initially; cached uploads/CDN translations can participate in naming. | Extra unassigned Free register on a combined empty-register upgrade; same early version-write problem; avoiding that changes Free/Pro seeding policy and exceeds this issue’s floor. |
| **C. Schedule the whole `version_check()` on `woocommerce_init`, retaining its internal lock/write order** | ~10–20 lines; successful ladder completion still precedes its version write; missing WooCommerce hook leaves the old version for a later request. | Moves every historical migration; normally follows Free’s earlier-registered callback and changes default-register seeding; still precedes Pro’s `init@20` loader. Requires broader lifecycle tests. |
| **D. Use literal English `Register` in the backfill** | ~1 line; preserves all scheduling and lock ordering; no translation load. | Removes existing localization intentionally; affects naming behavior shared with activation/publication if changed in `Registers.php`. Poor trade without an explicit English-name policy. |

**Evidence favours A**, provided the bounded missed-seeding risk is accepted and the priority is tested. The generic “defer to init” suggestion is incomplete; using default priority changes database contents.

Moving the whole ladder changes:
- historical store post-type/capability migration (`P:includes/updates/update-1.7.5.php:29–65`);
- Free template deletion invoked through Pro’s 1.8.7 update (`P:includes/updates/update-1.8.7.php:18–21`; `FM:includes/updates/update-1.8.7.php:40–61`);
- product/order metadata deduplication (`P:includes/updates/update-1.8.11.php:40–54`; `update-1.8.14.php:31–69`);
- when Pro initialization can observe migrated data, since currently `version_check()` precedes `new Init()` (`P:includes/Activator.php:46–48`).

No inspected historical step explicitly requires `plugins_loaded`, but those side effects make whole-ladder movement broader than a translation-notice fix. Do not copy Free’s early version bump into Pro; its existing tests explicitly require bump-after-migration (`P:tests/includes/Test_Activator.php:50–61,112–129`).

### Ruling: which locale should a persisted default name use?

- **Retain current request-locale semantics:** smallest change; aligns with existing `__()` usage and preserves merchant edits. Names remain dependent on the triggering request and available translations.
- **Pin migration names to site locale:** potentially more consistent across administrators with different UI languages. Requires explicit locale/load/restore behavior and multilingual coverage; “site locale” still does not define per-store naming on every multilingual installation.

**Evidence favours retaining current semantics for this PR.** Deferral is not intrinsically an improvement or regression in locale selection: it can allow later locale filters to act, but does not itself select a locale. A site-wide persisted-name policy is a genuine separate product decision; the inspected wiki does not settle it.

## 5. **Recommendation**

**PURSUE NOW**, narrowly on `next`, before 2.0 migration release.

The early call still exists, its WordPress diagnostic conditions are identifiable from disk, and a small targeted scheduling change addresses it. Do **not** describe it as affecting every upgraded site, promise site-locale names, or bundle a migration-runner refactor.

**PR 1 on next: defer only Pro’s 2.0.0 register backfill to `init@-1`, retaining immediate execution after init (~10 non-test lines, tests: pre-theme JIT diagnostic regression, deferred/immediate execution, existing/draft-store preservation, version-write timing, and Pro-before-Free default-register ordering).**

The ordering test must establish that the proposed change does not introduce an extra unassigned register under the same combined-upgrade fixture. Accept the documented missed-default risk rather than adding durable jobs, retries or new locks.

## 6. **Open questions**

- **Actual affected installation count:** best guess is a subset with published stores and discoverable Pro translations, not all upgraders. A representative localized upgrade fixture and merchant logs would settle prevalence.
- **Multilingual naming policy:** best guess is that editable default names need no new locale policy for this fix. Paul’s decision between request locale and site locale would settle the intended long-term contract.
- **Runtime verification:** the proposed test has not been executed, nor have old/new implementations been compared. Docker/wp-env execution during implementation must confirm the diagnostic, hook ordering and register counts.
- **Acceptance of latch-before-backfill:** the existing manual-repair behavior suggests this is proportionate for default-register seeding. If Paul requires completed backfill before advancing the version, reject option A and separately scope the broader timing change rather than silently weakening that requirement.