# Sync write contract — CANONICAL HOME

This directory is the single canonical home of the sync write contract
(`schema.json` + `fixtures/*.json`), as of server-port increment 5
(2026-07-14). Contract changes land HERE first; the golden-route tests in
`tests/includes/Sync/` prove the live write surface against these fixtures.

History: the contract was authored in the replication lab
(`woo-rxdb-replication-lab/contracts/write-contract/`) and vendored here
during the port. That lab copy is now a frozen mirror (see its README for the
two recorded lab-source divergences fixed only in this implementation:
same-second overscan pagination; the replay-reuse guard) and is deleted when
the lab plugin retires.
