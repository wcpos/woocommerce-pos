#!/bin/bash
# Run the bench matrix in the wp-env cli container (dev site, seeded by seed.php).
# usage: run-matrix.sh <label> "<sizes: off | prod:var ...>" "<scenarios>"
label=$1
sizes=${2:-"off 0:0 5000:5000 20000:20000 50000:50000"}
scen=${3:-"wpq wcgp children visible"}
here=.claude/specs/2026-11-13-pos-only-per-query-cost/repro
cd "$(dirname "$0")/../../../.." || exit 1
run() { pnpm exec wp-env run cli --env-cwd='wp-content/plugins/woocommerce-pos' -- "$@" 2>/dev/null | grep -v '^ℹ\|^✔'; }
for s in $sizes; do
  if [ "$s" = off ]; then run wp eval-file $here/configure.php enabled=0 prod=0 var=0
  else run wp eval-file $here/configure.php enabled=1 prod=${s%%:*} var=${s##*:}; fi
  for sc in $scen; do
    printf '%s %s ' "$label" "$s"; run php $here/bench.php "$sc" 200
  done
done
