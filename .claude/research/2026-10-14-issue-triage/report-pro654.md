## 1. Claim check

**Evidence:** source inspection only; no tests, Docker, network or `gh` used. `F:` denotes Free `next` (`92b5d970`); `M:` Free `main` (`9b9f7613`); `P:` Pro `next` (`82cb93e`); `C:` the supplied client tree; `W:` the supplied wiki. Pro released-line comparisons use the local `origin/main` ref (`67ccce4`), not a network fetch. Runtime conclusions below are **Inferred**, not reproduced.

### Pro-only uninstall

**HOLDS — Pro installs Free-owned storage without requiring standalone Free.**  
`P:woocommerce-pos-pro.php:43,306-314` loads the bundled helpers and constructs Free’s Activator; `F:includes/Activator.php:237-244` installs three sync stores and invokes the five-table till installer at `:225-231`. Pro’s dependency is `dev-next` (`P:composer.json:25`).

**HOLDS — Pro uninstall does not remove those tables or Free’s general operational options.**  
`P:uninstall.php:19-22` explicitly excludes Free data; the actual deletion patterns at `:124-160` match Pro/display prefixes, not the shared core footprint. The Free-presence gate at `:205-245` covers shared logs, translations **and their cache transients**, not tables or general options.

The exact table footprint left by Pro uninstall, where present, is:

| Table suffix; each uses the site prefix | Free’s intended treatment |
|---|---|
| `wcpos_sync_journal` | Always drop |
| `wcpos_sync_stored_digest` | Always drop |
| `wcpos_sync_mutations` | Always drop |
| `wcpos_registers` | Full wipe only |
| `wcpos_register_sessions` | Full wipe only |
| `wcpos_cash_movements` | Full wipe only |
| `wcpos_closures` | Full wipe only |

The eighth current table is **`wcpos_fiscal_records`**, also full-wipe-only. Free additionally drops legacy `wcpos_sync_change_log` and `wcpos_sync_order_index` if present; these are not two additional tables created by a fresh activation. Sources: `F:uninstall.php:48-73,434-446`.

Thus **leaving five bookkeeping tables on a default uninstall is intentional**, not the defect. Leaving the three operational tables, or ignoring an explicit full wipe, is the discrepancy.

**HOLDS, with qualification — Pro uninstall clears none of Free’s scheduled hooks.**  
Free’s current uninstall list is (`F:uninstall.php:80-98`):

- `wcpos_sync_journal_purge`
- `wcpos_print_job_purge`
- `wcpos_integrity_digest_rebuild`
- `wcpos_cloud_print_submit`
- `wcpos_relay_reregister`
- `wcpos_analytics_group_refresh`
- `wcpos_payments_sweep`
- `wcpos_install_font_packs`
- `wcpos_file_integrity_check`
- `wcpos_receipt_snapshot_cleanup`
- Legacy `wcpos_change_log_purge`

Free also cancels Action Scheduler font-install actions and receipt-cleanup actions in group `wcpos` (`F:uninstall.php:429-431`).

**WRONG if interpreted as “all these remain after an ordinary deactivate/delete.”** Pro also constructs Free’s Deactivator (`P:woocommerce-pos-pro.php:314`). That clears print purge, journal purge, payments sweep and integrity checks; its lifecycle call clears analytics refresh (`F:includes/Deactivator.php:54-77`; `F:includes/Services/Lifecycle_Events.php:406-407`). Other pending hooks/actions remain candidates for orphaning. Scheduling is feature-dependent; not every install has every event.

**HOLDS — Free option cleanup is bypassed.** The precise scope is a pattern-based sweep, not a finite option-name list:

- Free targets `wcpos_*`, `woocommerce_pos_*`, and their `_transient_…` / `_transient_timeout_…` families (`F:uninstall.php:479-486`).
- Pro removes its own families, display families, and shared translation-version transients when Free is absent (`P:uninstall.php:126-139,223-229`).
- Remaining Free operational examples include `woocommerce_pos_db_version`, `wcpos_sync_schema_version`, `woocommerce_pos_uuid`, `woocommerce_pos_uuid_home`, `woocommerce_pos_secret_key`, `woocommerce_pos_refresh_secret_key`, sync epoch/backfill/watermarks, and `wcpos_landing_profile`. Sources: `F:tests/includes/Test_Uninstall.php:657-682`; `F:includes/Services/Auth.php:250-268`; `F:includes/Sync/Sync_Journal.php:19-22,60`.
- Free preserves `woocommerce_pos_settings_*`, `wcpos_active_template_*`, `wcpos_template_order_*`, `wcpos_disabled_virtual_templates_*`, and `wcpos_fiscal_sequence_*` **only by default**. Pro leaves these even on full wipe (`F:uninstall.php:508-518`; `P:uninstall.php:124-160`).
- Free always excludes Pro ownership and WooCommerce’s four POS options: `woocommerce_pos_store_name`, `woocommerce_pos_store_phone`, `woocommerce_pos_store_email`, `woocommerce_pos_refund_returns_policy` (`F:uninstall.php:488-502`). These are not cleanup defects.

Pro also bypasses Free’s print-job/template cleanup, POS user-meta cleanup and network integrity-option cleanup (`F:uninstall.php:146-157,449-469,608-610`; compare `P:uninstall.php:249-269`).

**CHANGED around D4, not fixed:** since October 12, Free added payments-sweep cleanup (`e9867049`), the `Owned_Table` consolidation and discovered-footprint tests (`2a5e4fd7`), plus receipt/integrity cleanup changes. Current structural checks are `F:tests/includes/Test_Uninstall.php:287-332`. Pro’s `uninstall.php` has no commits since October 12. Do not re-propose the already-landed registry/drift work.

**HOLDS on the released line, with a smaller footprint.**  
`M:includes/Activator.php:211-217` installs the three sync tables; `M:uninstall.php:48-75,390-410` defines their cleanup. Pro local `origin/main:woocommerce-pos-pro.php:304-312` boots Free, while `origin/main:uninstall.php:19-22,124-135,245-266` retains the same omission. The five till tables are a `next` addition, not grounds to defer the existing operational leak to 2.0.

**Minimal failing PHPUnit repro — proposed, not run:** extend `P:tests/includes/Test_Uninstall.php`’s existing `Test_Uninstall` class. Pin Free absent through its existing helper (`:56-58`); create `wcpos_sync_journal`, seed `woocommerce_pos_db_version`, settings and a scheduled `wcpos_integrity_digest_rebuild`; invoke Pro’s existing uninstall helper (`:46-48`). Assert the sync table, version option and event are gone, while settings survive. Today all three cleanup assertions are inferred to fail. This is an uninstall invocation, **not a REST request**.

Add a full-wipe variant with `wcpos_remove_all_data=yes`, a receipt template and, on `next`, bookkeeping fixtures; assert their removal. Revise the existing blanket “never deletes Free data” test (`P:tests/includes/Test_Uninstall.php:121-135`) to explicitly pin **Free installed**.

### StoreInterface

**HOLDS — no read-side consumer found; WRONG if phrased as “nothing implements it.”**  
Free’s Store implements it now (`F:includes/Abstracts/Store.php:10,25-27`). `git log --follow` dates introduction to `d2623db3`, December 18, 2025; that commit immediately added the implementation. The interface is 384 lines (`F:includes/Interfaces/StoreInterface.php:1-384`).

**CHANGED — Pro’s redundant declaration has already gone.**  
Pro now simply extends Free’s Store (`P:includes/Abstracts/Store.php:13,22`), inheriting interface membership. Commit `1f1fb35`, merged as `c4c211e` on October 13, removed the duplicate declaration and getters. No subsequent Free interface changes were found.

**HOLDS — no type-hint, `instanceof`, reflection or other consuming reference found in the searched first-party source.**  
Search covered Free, Pro, client `apps/` and `packages/`, every supplied `wcpos-*` project and every `*-for-woocommerce` extension, including ignored source files but excluding dependency/build/Git directories. The additional hit was an older Pro implementation snapshot at `/Users/kilbot/Projects/wcpos-org-review-guidelines/wcpos__woocommerce-pos-pro/includes/Abstracts/Store.php:13-23`, not a consumer.

This included ATUM, BTCPay, CloudPrint, translation integrations, coupon integrations and the terminal extensions. It is **not proof of zero private merchant integrations or dynamic references**.

**HOLDS — no advertised extension-point obligation found.**  
Free README/readme searches found no StoreInterface contract; Pro has no root README/readme. Wiki mentions describe a removed field (`W:architecture/plugin-free/receipt-presentation-and-i18n.md:26-34`) and explicitly say receipts do **not** require the interface (`W:architecture/plugin-free/receipt-payload.md:91-101`). Neither advertises it as an extension seam.

**Released line:** the interface file is byte-identical on Free `main`; its Store also implements it at `M:includes/Abstracts/Store.php:27`. Removal therefore belongs to **2.0/`next`**, not a patch.

## 2. Impact

- **Uninstall: Medium.** Merchants/operators deleting a Pro-only installation retain operational tables, options, credentials and potentially scheduled work; an explicit full wipe is incomplete. This occurs at removal, not every checkout. No observed attacker exploit or direct payment loss. Installation counts and retained-data sizes are unknown.
- The path is genuinely reachable through WordPress’s plugin-delete lifecycle, not a speculative REST route (`P:uninstall.php:3,249-269`; `W:architecture/plugin-pro/uninstall-and-data-cleanup.md:9-12`). The supplied client/admin source searches found no full-wipe UI; the wiki deliberately specifies a constant/option rather than an admin toggle (`W:architecture/plugin-free/security-hardening.md:157-159`).
- **Implementation stakes: High**, despite Medium defect severity. A bad ownership guard could drop the remaining Free installation’s tables or fiscal records. This is why presence and ordering tests are necessary—not generic “hardening.”
- **StoreInterface: Low.** No current merchant/cashier failure is established. Removal reduces an unused public type surface; the risk is an unknown PHP extension relying on it.
- The client consumes Store **data**, not this PHP interface: `C:packages/database/src/collections/schemas/stores.ts:1-19`; `C:packages/core/src/utils/merge-stores.ts:182-215`. Server serialization calls `$store->get_data()` (`F:includes/API/V1/Stores.php:131-146`); receipts probe individual methods (`F:includes/Services/Receipt_Store_Resolver.php:40-41`). Removing only interface membership need not change either payload.

## 3. Cost and risk

### Uninstall

**Estimated scope:** approximately **100–140 changed non-test lines across Free and Pro**, excluding mechanical formatting; existing `uninstall.php` files, release notes and the two existing `Test_Uninstall.php` classes. Reuse Free’s cleanup rather than copying another table/option registry.

Required safeguards:

1. **Skip all shared-core cleanup whenever standalone Free remains installed**, active **or inactive**, including network activation.
2. Strengthen the existing canonical-path detector before using it for destructive cleanup. It currently checks only `woocommerce-pos/woocommerce-pos.php` (`P:uninstall.php:43-48`). Pro already documents real GitHub-zip directory names and matches the entry filename in bootstrap (`P:woocommerce-pos-pro.php:166-185`). Check installed plugin entries as well as site/network activation entries.
3. Make Free’s standalone cleanup safely callable without booting plugin classes or unintentionally starting a second network-wide sweep.
4. Handle definitions already loaded from standalone Free. `require_once` of the **different vendored pathname** alone does not prevent global-function redeclaration.
5. Preserve each site’s full-wipe choice through both plugins’ cleanup. Free’s prefix sweep currently deletes `wcpos_remove_all_data` (`F:uninstall.php:416-418,479-525`); running Free first can otherwise erase the choice before Pro reads it.
6. Account for Pro still existing on disk during its uninstall: Free’s Pro-presence detector would otherwise preserve fonts/shared caches (`F:uninstall.php:126-135,503-506,562-564`). The existing detection filter permits a narrowly scoped override once Free absence is established.

Tests: absent/present Free; active/inactive/network-installed/renamed Free; default/option/constant opt-in; multisite differing opt-ins; both deletion orders in one request; previously loaded Free functions; shared files and unrelated WooCommerce data preserved appropriately. Use Free’s existing DDL fixture hygiene (`F:tests/includes/Test_Uninstall.php:65-110`).

**Pro coupling:** direct and intentional. Its build installs and packages vendored Free (`P:.github/workflows/release.yml:153-172`). That vendor tree is absent from this research checkout, so packaged inclusion and the exact bundled revision remain unverified.

**Lane:** fix the released operational omission on **`main`**, then merge forward and exercise the additional `next` footprint. Do not backport till tables.

### StoreInterface

Delete the 384-line interface, Free’s import/comment/`implements` clause, and add a changelog entry: approximately **389 changed non-test lines**, overwhelmingly mechanical deletion. No Pro source edit is needed on the supplied tip.

Existing verification targets include `F:tests/includes/API/Test_Stores_API.php:95` for returned fields and Pro’s Store behavior tests. Compare old/new Store responses; do not add a permanent test merely asserting that a deleted interface is absent.

**Back-compat:** class names, getters and REST shapes stay; PHP type identity changes. Private implementations, type hints and `instanceof` users could break. No broad compatibility claim is justified by this disk search.

### Behavior changes / regressions

- Proposed uninstall change removes operational state previously retained on Pro-only deletion; full wipe additionally removes shared configuration/bookkeeping.
- Proposed interface deletion removes a public PHP symbol and inherited interface membership.
- No behavior was changed or runtime regression evaluated during this research.

## 4. Options

### D4 — who owns cleanup on Pro-only sites?

**A. Keep current ownership separation.**  
Pros: no destructive change; preserves existing blanket tests and wiki wording.  
Cons: Pro creates the footprint but cannot clean it; full-wipe intent is unmet. The omission already affects the released line.

**B. Reuse Free cleanup only when standalone Free is absent — favoured.**  
Pros: one cleanup contract, existing default/full-wipe distinction and drift tests; includes user-meta, scheduler and file cleanup rather than fixing tables alone.  
Cons: requires the presence/loading/ordering safeguards above and a coordinated Free/Pro release.

**C. Duplicate Free’s lists and deletion logic in Pro.**  
Pros: independent standalone script; avoids loading Free’s uninstall file.  
Cons: immediately adds another footprint registry; recent font, receipt, integrity and payments changes demonstrate concrete drift pressure. Partial duplication would leave other operational state behind.

**Ruling:** adopt B. The preservation policy is already settled by both plugins’ opt-in switches and Free’s treatment of merchant-authored records. This is mainly an ownership correction, not a new product policy.

**Would an unconditional include be safe while Free remains installed? No.** Free’s table deletion does not check whether Free remains installed; its Pro-presence check protects only selected shared surfaces (`F:uninstall.php:434-446,472-474`). The guard must surround the **entire** delegated cleanup.

**WordPress guidance:** from memory, the Plugin Handbook describes uninstall as the place to clean plugin options/tables and distinguishes it from deactivation. I did not verify current WordPress.org wording offline and would not claim a directory rule mandates indiscriminate destruction of merchant-authored data. The concrete authority here is WCPOS’s documented opt-in contract (`W:architecture/plugin-free/security-hardening.md:142-162`).

### StoreInterface — delete or maintain a public contract?

**A. Delete in 2.0 with a changelog line — favoured.**  
Pros: matches the requested ruling; no known consumer or advertised extension contract; the real Store hierarchy/getters remain.  
Cons: deliberately breaks any unknown external dependence on the PHP interface.

**B. Keep unchanged as a public contract.**  
Pros: zero external type-compatibility risk and no implementation work.  
Cons: retains 384 lines of duplicated signatures with no known polymorphic consumer; creates an ongoing maintenance promise.

**C. Deprecate in 2.0, remove later.**  
Pros: gives unknown consumers notice.  
Cons: requires another removal decision/release despite no identified consumer; delays the simplification without evidence of benefit.

**Ruling:** A. The on-disk evidence satisfies the user’s stated condition. Do not remove getters, change serialization or redesign Store alongside it.

## 5. Recommendation

**PURSUE NOW.**

Prioritize uninstall: it is a demonstrated source-level omission on the released line, and 2.0 adds merchant bookkeeping that makes preserving the default/full-wipe distinction especially important. Complete interface removal before 2.0 as a separate, explicitly announced breaking cleanup—not as a prerequisite for the uninstall fix.

- **PR 1 on main, Free:** make the existing standalone cleanup reusable without duplicate execution and retain the wipe choice while Pro still needs it (~40–60 non-test lines; tests: Free uninstall loading, opt-in and ordering cases).
- **PR 2 on main, Pro:** invoke that cleanup only when no standalone Free installation remains; strengthen presence detection and preserve per-site decisions (~60–80 non-test lines; tests: Pro-only cleanup, retained Free, renamed/network installs, both deletion orders). Bundle the companion Free change; merge both forward to `next`.
- **PR 3 on next, Free:** delete StoreInterface and its sole current declaration dependency; announce the PHP-symbol removal (~389 non-test lines, mostly deletion; tests: existing Store/API and Pro behavior coverage, old/new payload comparison).

No new cleanup subsystem, registry, feature flag, retry layer or admin control is warranted.

## 6. Open questions

- **Does the release ZIP contain the expected Free uninstall implementation?** Best guess: yes, from the Composer/build pipeline; the supplied Pro worktree has no vendor directory. Inspect the built artifact and run the proposed wp-env integration tests before release.
- **Does a private merchant extension depend on StoreInterface?** Best guess: uncommon; none of the searched first-party sources does. Maintainer knowledge or an extension-author report would settle it. This is residual compatibility risk, not evidence for retaining the interface indefinitely.
- **Exact runtime deletion-order behavior:** source establishes the hazards, but same-request bulk deletion and multisite were not executed. The proposed integration cases must settle function loading, flag lifetime and final shared-file cleanup before calling the fix safe.