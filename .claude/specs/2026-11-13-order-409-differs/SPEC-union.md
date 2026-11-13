# Codex job spec — `canonical_diff` walks the union of top-level keys

## Goal

`Order_Serializer::canonical_diff( $client, $server )` names the top-level fields where the two canonical forms differ. Its top-level loop walks only the client's keys (`foreach ( $a as $field => $value )`). `canonical_form()` keeps only keys the payload actually carries, so a partial push that omits a field the server has never names that field. A note-only push whose note matches the server returns `[]` even when the server's `status` moved. Walk the union of both sides' keys, as `list_diff()` already does one level down (`array_keys( $a + $b )`).

## Stakes

This is diagnostics only: `differs` on an order-update 409. It does not affect the hash, the compare or the 409/200 decision.

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php` (only the body of `canonical_diff()`, around lines 388-401)
- `tests/includes/Sync/Test_Order_Serializer.php` (add one test method)

## Out of scope (do not edit)

- every other file, including `list_diff()`, `keyed()`, `form()` and `includes/API/V2/Write_Controller.php`

## Interfaces and constraints

- In `canonical_diff()`, iterate `array_keys( $a + $b )`. Read each side with `?? null`: `$a[ $field ] ?? null` and `$b[ $field ] ?? null`. Keep everything else as it is: the list fields still go through `self::list_diff()`, scalars still give `array( (string) $field )`, and the result is still unique and sorted with `SORT_STRING`.
- `list_diff()` already returns `array( $path )` when either side is not an array, so a list field present on only one side is named as its bare field name (for example `line_items`). That is intended.
- PHP 7.4 compatible. WordPress coding standards (tabs, spaces inside parentheses), matching the surrounding code.
- Add this test method to `Test_Order_Serializer` (place it after the last existing test):

  ```php
  public function test_canonical_diff_names_a_field_only_the_server_carries(): void {
  	$server = array(
  		'status'        => 'processing',
  		'customer_note' => 'same',
  	);
  	$client = array( 'customer_note' => 'same' );

  	$this->assertSame( array( 'status' ), Order_Serializer::canonical_diff( $client, $server ) );
  	$this->assertSame( array( 'status' ), Order_Serializer::canonical_diff( $server, $client ) );
  }
  ```

  Give it a one-line docblock like the other tests in the file (`/** A field only one side carries is still named. */`). The file already imports `Order_Serializer` with `use`.

## Do not

- Change `list_diff()`, `keyed()`, `form()` or any comment outside `canonical_diff()`.
- Rename or edit any existing test.
- Commit or push. Do not start or stop wp-env, and install nothing.

## Pre-authorised actions

- Edit the two in-scope files and run the acceptance commands.

## Budget

- Max non-test lines changed: 8
- Max total lines changed: 25

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

Run these first: before your edit, the new test must FAIL on its first assertion (`[]` instead of `['status']`). Then make the edit; all of these must pass:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Order_Serializer.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Sync/Order_Serializer.php tests/includes/Sync/Test_Order_Serializer.php
```

## Environment

- Network: yes, to reach the local wp-env containers (Docker).
- Writable outside the worktree: `~/.wp-env/wp-env-order-revision-allow-list-3526dcd1` (wp-env's cache; granted with --writable).
- wp-env is running for this worktree, with dependencies installed.

## Output expected

End with the STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES report, and quote the red run's failure line.
