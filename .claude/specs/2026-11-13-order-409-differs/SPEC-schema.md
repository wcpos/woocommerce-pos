# Codex job spec — document `differs` in the write-contract conflict schema

## Goal

The order push 409 now carries an optional `differs` field: a list of path-name strings, present on order-update conflicts only. The plugin's copy of the write wire contract forbids extra keys on `conflictResponse` (`additionalProperties: false`). Add `differs` there as an optional property, so the documented contract matches the server.

## Stakes

This is test documentation only: no runtime code reads this file. A JSON syntax error is the only real failure.

## In scope (the only files you may edit)

- `tests/includes/Sync/write-contract/schema.json`

## Out of scope (do not edit)

- `tests/includes/Sync/write-contract/fixtures/responses.json`
- every other file

## Context

`$defs.conflictResponse` (around lines 115-144) has:
- `required`: `code`, `message`, `current`, `currentRevision`;
- `properties` for those four;
- `additionalProperties: false`.

## Interfaces and constraints

- Add to `$defs.conflictResponse.properties`, after `currentRevision`:

  ```json
  "differs": {
    "type": "array",
    "items": { "type": "string" }
  }
  ```

  Match the file's existing indentation and its one-key-per-line style. Keep the `items` object on separate lines if that is how the file writes nested objects.
- Do NOT add it to `required`.
- Keep `additionalProperties: false`.
- Do not change `contractVersion`.

## Do not

- Touch any other definition, fixture or file.
- Reformat the file.
- Commit or push.

## Pre-authorised actions

- Edit the in-scope file and run the acceptance command.

## Budget

- Max non-test lines changed: 10
- Max total lines changed: 10

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
jq -e '."$defs".conflictResponse.properties.differs.items.type == "string" and (."$defs".conflictResponse.required | index("differs") | not)' tests/includes/Sync/write-contract/schema.json
```

## Environment

- Network: no.
- Writable outside the worktree: none.
- No test suite runs.

## Output expected

End with the STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES report.
