# Star CloudPRNT Simulator Design

## Goal

Add a repository-local simulator that can act like a Star CloudPRNT printer polling StarIO.Online so WCPOS Star Online printing can be tested without physical printer hardware.

## Architecture

Create a new pnpm workspace package at `packages/star-cloudprnt-simulator`. It is a dependency-free Node CLI and library using Node's built-in `fetch` and `node:test` runner. The package is intentionally separate from the WordPress plugin and settings UI so it can be run by maintainers during manual testing without changing production plugin code.

## Components

- `src/device-profile.mjs` builds the fake printer identity, CloudPRNT request headers, poll payloads, and client-action responses.
- `src/cloudprnt-client.mjs` implements the HTTP CloudPRNT flow: POST poll, optional GET job, optional DELETE confirmation.
- `src/cli.mjs` parses CLI flags/environment variables and runs the polling loop.
- `test/*.test.mjs` verifies protocol behavior with fake fetch functions.

## Data Flow

1. The simulator receives a StarIO.Online CloudPRNT device URL, e.g. `https://eu-device.stario.online/cloudprnt/<group>`.
2. It POSTs a CloudPRNT poll with a fake MAC address and `200%20OK` status.
3. If StarIO.Online returns `jobReady: false`, it waits for the next poll.
4. If StarIO.Online returns `jobReady: true`, it GETs the job using the returned token/media type.
5. It logs the received payload and confirms completion with DELETE using `code=200 OK`.
6. If the server sends CloudPRNT `clientAction` requests, the simulator responds on the next poll with plausible mC-Print3 values.

## Error Handling

Invalid/missing CLI arguments fail fast with a clear message. Network or protocol failures are logged and the polling loop continues, so a transient StarIO.Online error does not stop the simulator. The library exposes structured results for tests and possible future automation.

## Testing

Use Node's built-in test runner. Tests cover device identity defaults, poll payloads, CloudPRNT client-action handling, no-job polling, job fetch/confirmation, and CLI config parsing.
