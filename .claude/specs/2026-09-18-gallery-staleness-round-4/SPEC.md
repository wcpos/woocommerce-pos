# PR #1969 review round 4: six CodeRabbit threads

Branch `feat/gallery-template-staleness` (this worktree), targeting `next`. `origin/next` has just been merged in (ca180f41); build on that. You are implementing decisions already made; do not re-litigate them and do not ask questions. If a decision below is impossible as written, do the nearest thing that keeps its intent and say so in your final summary.

## Ground rules

- WordPress coding standards per `.phpcs.xml.dist`. Verify with the host phpcs: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`. Fix only findings in lines you touched; do not sweep pre-existing files.
- Do not add or expand docblocks on code you did not change. Do not rename anything.
- Budget: net +60 to +130 lines across all files including tests. Stop and report if you are heading past +180.
- Stakes tier: Medium (`.ai/rules/stakes-tiers.mdc`). `sync_untouched()` writes merchant templates, but only copies proven unedited; keep every existing guard.
- You cannot run PHPUnit here (needs Docker). You CAN run `php scripts/tests/test-gallery-template-versions.php` (plain PHP, no WordPress) and phpcs. Run both before you finish.
- Do not commit. Leave the changes in the working tree.

## The six items

### 1. Workflow path filter (`.github/workflows/gallery-template-versions.yml`)

Add `"scripts/tests/test-gallery-template-versions.php"` to the `pull_request.paths` list, after the script entry. Nothing else.

### 2 and 3. Catalogue rebuilt per call (`includes/Templates/Gallery_Update_Status.php`)

Both threads are the same cost: `registry_signature()` and `registry_version()` each call `Gallery_Registry::all()`, which builds and translates 36 entries and applies `woocommerce_pos_gallery_templates`. `Templates::get_enabled_templates()` reaches `registry_version()` once per installed gallery copy, and `maintain()` reaches `registry_signature()` on every admin request before its option gate.

Decision: memoise only the **key → version map** (locale-invariant; titles are translated, versions are not) for the length of the request, in WordPress's object cache under a **non-persistent** group so it never reaches Redis/Memcached and is flushed by the test framework between tests.

- Add a private static `registry_versions(): array` returning `array<string,int>` (key → `max( 1, (int) version )`), built from `Gallery_Registry::all()` exactly as `registry_signature()` builds `$versions` today, cached with `wp_cache_get`/`wp_cache_set` under group `wcpos-gallery`, key `registry-versions`. Register the group non-persistent once (`wp_cache_add_non_persistent_groups( 'wcpos-gallery' )`) at the top of that method; calling it repeatedly is harmless.
- `registry_signature()` and `registry_version()` read from `registry_versions()`. `registry_version()` returns null when the key is absent, as now.
- Docblock on `registry_versions()` (short): the memo lasts one request; a plugin that filters the catalogue must do so before the first admin_init read, which every normal `plugins_loaded` registration satisfies.
- Tests: `tests/includes/Templates/Gallery_Update_Status_Test.php` adds `woocommerce_pos_gallery_templates` filters mid-test at three places (around lines 39, 296, 362). After each `add_filter`/`remove_filter` that changes a version, call `wp_cache_delete( 'registry-versions', 'wcpos-gallery' )` so the test sees the change, with a one-line comment. Add one new test: `test_registry_version_is_memoised_for_the_request` — count filter invocations across two `registry_version()` calls and assert the filter ran once.
- Do NOT change `Admin.php`. `maintain()` keeps running on every non-AJAX admin request including `admin-post.php`; that is the self-heal the docblock explains, and with the memo its pre-gate cost is one catalogue build per request.

### 4. Locale stamped from a locale-invariant match (`backfill_source_hashes()`)

Today the backfill stamps `META_SOURCE_LOCALE = determine_locale()` whenever the stored content hashes equal to the bundled markup translated in the current locale. When translation does not change the markup (a template with no interpolated phrases, or an `en_US` site), the match proves nothing about the install locale.

Decision, "record the best fact available, and say which":

- Compute `$translated = Receipt_I18n_Labels::translate_interpolated_phrases( $bundled_content )` once per key (already done for the hash). Also remember whether `$translated !== $bundled_content` per key (`$locale_proven[ $gallery_key ]`).
- On a match: if the translation changed the markup, stamp `determine_locale()` as now (the copy provably rendered in this locale). Otherwise stamp `get_locale()` (the site language), not the requesting admin's language, because receipts are customer-facing and a single-language store's site locale is what its receipts were installed in. Replace the existing two-line comment with one explaining exactly that split in two or three lines.
- Tests: add `test_backfill_stamps_the_request_locale_when_the_match_proves_it` and `test_backfill_stamps_the_site_locale_when_the_match_is_locale_invariant`. Use the existing backfill test fixtures as the model (line ~450). To make translation change the markup in a test, filter `gettext` (or `gettext_with_context`, whichever `translate_interpolated_phrases` goes through; read it) for one phrase the bundled fixture contains, and use `switch_to_locale`/`determine_locale` filters the existing locale tests already use (see `test_install_records_the_source_locale`, ~line 434, and `test_replacement_does_not_overwrite_the_recorded_locale`, ~391).

### 5. Textdomain after `switch_to_locale()` (`translate_in_source_locale()`)

Mirror `Receipt_I18n_Labels::get_labels()` (`includes/Services/Receipt_I18n_Labels.php:31-40`): after a successful `switch_to_locale( $locale )`, construct `new \WCPOS\WooCommercePOS\i18n()` before translating, so the plugin textdomain is reloaded in the target locale (on WordPress 5.6 to 6.0 the switch unloads it and just-in-time loading does not bring it back). Add a `use WCPOS\WooCommercePOS\i18n;` import if the file does not have one. One-line comment pointing at `get_labels()` as the precedent. No new test: the existing locale tests cover the path and the textdomain behaviour is version-specific.

### 6. Creation and deletion guards (`scripts/gallery-template-versions.php`)

Lines ~172-179 treat "key absent from `$before`" as a new template and "absent from `$after`" as removed. When `registry_versions()` fails to parse an entry whose file exists in that tree, the guard waives the very check it exists for.

Decision: presence in the Git tree decides, not presence in the parse result.

- Extract the per-file decision into a pure function `classify_change( string $key, bool $in_base, bool $in_head, ?int $before, ?int $after, bool $had_before, bool $had_after ): array{status: 'pass'|'fail', message: string}` or a shape equally testable (you choose; keep it a pure function with no git calls). `$had_before`/`$had_after` say whether the key appeared in the parse at all; `$before`/`$after` are the parsed versions (null = unreadable).
- Rules, in order: file absent at base and present at head → pass "new template"; present at base, absent at head → pass "removed"; present in a tree but the key missing from that tree's parse → **fail** ("registered in `Gallery_Registry.php` at <ref> but its entry could not be parsed"); then the existing version rules unchanged (unreadable after → fail; unreadable before → pass "version now readable"; bump required).
- Presence via `git cat-file -e <ref>:<file>` through the existing `git()` helper (null = absent).
- Tests in `scripts/tests/test-gallery-template-versions.php`, same `assert_same` style: one case per branch of `classify_change` (at least: new, removed, present-but-unparsed at base, present-but-unparsed at head, bump missing, bump present). Keep the existing parser cases.
- Run `php scripts/tests/test-gallery-template-versions.php` and make sure it exits 0.

## Finish

Final summary: per item, what changed and the net line count; phpcs result; the script test output line count; anything you could not do as written.
