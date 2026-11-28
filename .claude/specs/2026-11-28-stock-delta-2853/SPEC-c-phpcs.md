# Codex job spec — #2853 follow-up: phpcs layout and docblocks

## Goal

Remove the phpcs errors that the #2853 commits introduced in production code, and add the missing docblocks in the new test file. The behaviour must not change.

## Stakes

Formatting and comments only. No logic changes.

## In scope (the only files you may edit)

- `includes/API/V2/Writers/Variation_Writer.php`
- `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php`

## Out of scope (do not edit)

- `includes/API/V2/Writers/Product_Writer.php`
- `includes/API/V2/Writers/Collection_Writer_Resolver.php`
- Every other file

## Context

phpcs (`.phpcs.xml.dist`) reports these errors at HEAD:

- `Variation_Writer.php:46` reports PEAR.Functions.FunctionCallSignature (ContentAfterOpenBracket, MultipleArguments, CloseBracketLine) on `return $this->defer_stock( $id, array( ... ) );`.
- `Test_Rest_Dispatch_Stock_Delta.php` reports Squiz.Commenting.FunctionComment.MissingParamTag and missing doc comments on its helper methods.

## Interfaces and constraints

- **Variation_Writer.php.** Assign the array to a local `$prepared = array( ... );`, keeping the existing four keys and values and the existing layout. Then `return $this->defer_stock( $id, $prepared );` on one line. Nothing else in the file changes.
- **Test_Rest_Dispatch_Stock_Delta.php.**
  - Give `count_stock_query( $sql )` a docblock: a one-line summary, `@param string $sql Stock update query.` and `@return string`.
  - Add `@param` tags (with types matching the signatures, one short description each) and `@return` tags where a value is returned to the existing docblocks of `sale_in_window`, `push`, `held_revision` and `stock`. Keep their summary lines.
  - Do not reformat arrays, and do not change any test body.

## Do not

- Change logic, names, or assertions
- Edit any other file
- Commit or push
- Create plan, notes or scratch files

## Pre-authorised actions

- Edit the in-scope files
- Run the acceptance commands

## Budget

- Max non-test lines changed: 12
- Max total lines changed: 50

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l includes/API/V2/Writers/Variation_Writer.php
php -l tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php
```

## Reviewer reruns (not for Codex)

```sh
vendor/bin/phpcs includes/API/V2/Writers/Variation_Writer.php   # wp-env, expect 0 errors
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php   # wp-env
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Writers/Test_Variation_Writer.php   # wp-env
```

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
