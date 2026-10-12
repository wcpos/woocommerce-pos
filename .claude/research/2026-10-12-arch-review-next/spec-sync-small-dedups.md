# Spec — sync small dedups: one phone-fragment predicate, one home for the product-space SQL constants

## Goal
Two independent, behaviour-neutral dedups. Make each its own commit-sized change.
- **(A)** The "is this search term a phone fragment?" rule is copied in three search executors. It moves into one pure function.
- **(B)** The product-space SQL constants are copied in three classes. They get one home on `Digest_Index`.

## Stakes
- **Tier:** Medium (search and sync).
- **What failure costs:** a search or integrity query that returns different rows. Existing parity suites on both storages pin both.
- **Accepted risk:** none expected; both parts are pure moves.

## Out of scope
- Moving the posts-orders phone meta keys (`'_billing_phone','_shipping_phone'`, `Order_Search.php:103`) into the declaration.
- Touching the `carriers` lists.
- Changing the two deliberate status scopes: "live" (`NOT IN ('trash','auto-draft')`) versus "published".
- Registry changes, new filters, options, env vars or flags.

## Exact changes

### A. One phone-fragment predicate
**File `includes/Sync/Order_Search.php`.** Add this public static method next to `phone_digits_expression()`:
```php
/**
 * The digits of a phone-fragment search term, or '' when the term is not one.
 *
 * A term with at least one digit and no letters is a phone fragment: it is compared
 * against stored phones with their punctuation stripped (see phone_digits_expression()),
 * so `0412` finds `(04) 1234 5678`. The POS client applies the same digits-only rule.
 * Malformed UTF-8 is never a phone fragment.
 *
 * @param string $term One search term.
 * @return string
 */
public static function phone_digits( string $term ): string {
	$digits = (string) preg_replace( '/\D+/', '', $term );

	return '' !== $digits && 0 === preg_match( '/\p{L}/u', $term ) ? $digits : '';
}
```

Replace the three copies of
```php
$digits = preg_replace( '/\D+/', '', $term );
if ( '' !== $digits && 0 === preg_match( '/\p{L}/u', $term ) ) {
```
with
```php
$digits = self::phone_digits( $term );   // Order_Search::phone_digits( $term ) in Customer_Search
if ( '' !== $digits ) {
```
The copies are at:
- `includes/Sync/Order_Search.php`, `hpos_where()` (about `:61-62`);
- `includes/Sync/Order_Search.php`, `posts_where()` (about `:101-102`);
- `includes/Sync/Customer_Search.php`, `where()` (about `:62-63`).

Drop the now-redundant inline comment in `Customer_Search` (`:59-60`), because the method docblock carries it. The bodies inside each `if` stay byte-identical.

### B. Product-space SQL constants
**`includes/Sync/Digest_Index.php`** (about `:101-102`). Change `private const PRODUCT_POST_TYPES_SQL` and `private const EXCLUDED_POST_STATUSES_SQL` to `public const`, and give each a one-line docblock:
- `PRODUCT_POST_TYPES_SQL`: "The wp_posts types that make up the product id-space, as an SQL list."
- `EXCLUDED_POST_STATUSES_SQL`: "Statuses outside the live product (and order) set, as an SQL list. The published-only scope is published_product_predicate_sql()."

**`includes/API/V2/Changes_Controller.php`:**
- Delete the two private constants (about `:47-48`).
- Add `use WCPOS\WooCommercePOS\Sync\Digest_Index;`.
- Replace all six `self::PRODUCT_POST_TYPES_SQL` / `self::EXCLUDED_POST_STATUSES_SQL` uses with `Digest_Index::…`.

**`includes/API/V2/Resolve_Controller.php`:**
- Delete `PRODUCT_POST_TYPES_SQL` (about `:39`).
- Add the same `use`.
- Replace its two uses with `Digest_Index::PRODUCT_POST_TYPES_SQL`.
- Keep `BARCODE_META_KEYS_SQL` where it is.

## Rulings to preserve verbatim
- **Phone digits match both shapes on both order storages:** `Catalog_Proxy_Order_Search_Tests::test_order_search_matches_phone_digits`, via `Test_Catalog_Proxy_Orders` and `Test_Catalog_Proxy_Orders_HPOS`.
- **Customer phone search on both lanes:** `Customers_Lane_Parity_Tests` via `Test_Customers_Lane_Parity`, and `Test_Collection_Rules_Customer_Search`.
- **Live versus publish scopes:**
  - `Test_Sync_Read_Controllers` (range-checksum membership `:790`, and the bucket listing and revision-hash cases);
  - `Test_Catalog_Proxy_Products_Fast_Path`;
  - `Test_Changes_Unsupported_Collection`.
- **Barcode resolve:** the `Resolve_Controller` tests (`grep -rln "resolve/barcode" tests/includes`).

## Tests
Add to `tests/includes/Sync/Test_Collection_Rules_Order_Search.php` (pure `WP_UnitTestCase`; add `use WCPOS\WooCommercePOS\Sync\Order_Search;`). Each test is Arrange/Act/Assert and uses `assertSame( expected, actual )`:
- `test_phone_digits_strips_punctuation_from_a_digit_term`: `'(04) 1234-5678'` gives `'0412345678'`, and `'+61.412'` gives `'61412'`.
- `test_phone_digits_rejects_a_term_with_letters`: `'A12'` gives `''`.
- `test_phone_digits_rejects_a_term_without_digits`: `'-+()'` gives `''`.
- `test_phone_digits_rejects_malformed_utf8`: `"\xff12"` gives `''`.

Part B adds no tests; it is a pure constant move pinned by the suites above.

Delete no tests.

## Budget
- **Non-test:** ≤ 40 lines changed in `includes/` (net ≤ +15).
- **Total diff:** ≤ 120 lines, including tests and the lint budget.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification Codex can run (no Docker)
- `php -l` on the five changed `includes/` files and the test file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Sync/Order_Search.php includes/Sync/Customer_Search.php includes/Sync/Digest_Index.php includes/API/V2/Changes_Controller.php includes/API/V2/Resolve_Controller.php tests/includes/Sync/Test_Collection_Rules_Order_Search.php`
- `grep -rn "preg_match( '/\\\\p{L}/u'" includes/Sync` should show only `Order_Search::phone_digits`.
- `grep -rn "PRODUCT_POST_TYPES_SQL =" includes` should show only `Digest_Index.php`.

Afterwards the orchestrator runs these in wp-env, one file per call:
- `Test_Collection_Rules_Order_Search`
- `Test_Catalog_Proxy_Orders`
- `Test_Catalog_Proxy_Orders_HPOS`
- `Test_Customers_Lane_Parity`
- `Test_Sync_Read_Controllers`
- `Test_Catalog_Proxy_Products_Fast_Path`

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
