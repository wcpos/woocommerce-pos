# Codex job spec — literal v2 route in the v5 push test (REST lane coverage)

## Goal

CI's "REST lane coverage" check (`scripts/lane-coverage.php`) fails all five cases in
`tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php` as "unresolved route": the test builds the
route at runtime as `'/' . Api::ROUTE_NAMESPACE . '/push/orders'`, which the scanner cannot read
(`tests/lane-coverage/README.md`: route literals are strings containing `wcpos/v2`). Use the literal route.

## Stakes

Test only; no runtime change.

## In scope (the only files you may edit)

- `tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php`

## Out of scope (do not edit)

- Every other file, including `scripts/lane-coverage.php` and `tests/lane-coverage/*`

## Interfaces and constraints

- In `push_order_create()`, replace `$this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' )`
  with `$this->wp_rest_post_request( '/wcpos/v2/push/orders' )`.
- Remove the then-unused `use WCPOS\WooCommercePOS\Sync\Api;` line.
- Nothing else.

## Do not

- Change any test case, assertion or fixture
- Commit or push

## Pre-authorised actions

- Edit the in-scope file; run the acceptance commands

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 4

If you are about to exceed either, STOP and report why instead of continuing.
The budget is a stop rule, not a target.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php
grep -c "'/wcpos/v2/push/orders'" tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php   # prints 1
! grep -q "ROUTE_NAMESPACE" tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php
```

### Reviewer reruns (not for Codex)

```sh
php scripts/lane-coverage.php --json --root=<base worktree> > base.json && php scripts/lane-coverage.php --compare=base.json
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php
```

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES).
