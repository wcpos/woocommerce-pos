# WCPOS REST API v2

This directory contains the controllers for the `wcpos/v2` sync REST surface.
The sync engine and shared domain services remain under `includes/Sync`.

## Version-skew stance for the sync journal (decided 2026-08-22, free#1560)

**1.10.0 is a hard cutover for this protocol, not a compatibility window.**

The whole surface is new in 1.10.0: tag `v1.9.17` ships no `includes/API/V2`
and no `includes/Sync` at all. No released client has ever called
`/changes/sequence-log`, `/changes/tick` or `/orders/pull`, and no released
client holds a cursor, an epoch or a horizon for them. The wire contract was
therefore free to be designed once, and it was:

- Journal rows carry `deleted: 0|1`. There is **no legacy `type` verb** and the
  server does **not** dual-emit one. A router keyed on a verb string belongs to
  no shipped client.
- `epoch` is present on every envelope from the first release: `/orders/pull`
  exposes it at the top level, while `/changes/sequence-log` and `/changes/tick`
  place it under `checkpoint`. A client therefore either stores an epoch beside
  its cursor or has never synced.
- Order tombstones are pruned on the same 90-day retention as catalogue
  tombstones, and the lossy boundary is served as `horizon`. Any client reading
  the order lane MUST consume `horizon`; the 1.10.0 client does.

Accepted consequences, stated rather than left implicit:

- A pre-release install that carries a cursor written before the unification is
  not migrated. The client treats a non-zero cursor it holds no epoch for as
  unproven and rebaselines, which costs one re-seed, never silent row loss.
- Mixing a 1.10.0 client with a 1.9.x plugin is not a skew case for this
  protocol — the routes 404, exactly as they did before 1.10.0.

**This stance expires at 1.10.0's release.** From the first shipped client
onwards, any change to a journal row's shape, to the `head` / `horizon` /
`epoch` checkpoint fields, or to their meaning needs an explicit compatibility
plan in its PR: a dual-emit window, or a client-minimum gate. "No released
client speaks this yet" stops being true the day 1.10.0 ships.
