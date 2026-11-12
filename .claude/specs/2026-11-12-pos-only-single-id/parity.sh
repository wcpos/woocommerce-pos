#!/bin/bash
# Run the query-shape matrix against three Products.php: 2.0.1, the fix in the tree, and #2647's c1d9f7b3.
# Prints one table row per data set: <data set>  2.0.1  fix  c1d9f7b3  (pass/FAIL).
# Usage: WPENV=<wp-env binary> [P201=<file>] [PC1D9=<file>] parity.sh
# P201 / PC1D9 default to `git show <sha>:includes/Products.php`.
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
F=includes/Products.php
T=tests/includes/Test_Products_Query_Shapes.php
work=$(mktemp -d "${TMPDIR:-/tmp}/parity.XXXXXX")
cp "$F" "$work/fix.php"
trap 'cp "$work/fix.php" "$F"; rm -rf "$work"' EXIT
if [ -n "${P201:-}" ]; then
	cp "$P201" "$work/201.php"
else
	git show 82bf7d657a4313798d00f12bdc3284d65f2446ff:$F > "$work/201.php" || exit 1
fi
if [ -n "${PC1D9:-}" ]; then
	cp "$PC1D9" "$work/c1d9.php"
else
	git show c1d9f7b3e16158362b51d64f96938213b376378c:$F > "$work/c1d9.php" || exit 1
fi
for v in 201 fix c1d9; do
	cp "$work/$v.php" "$F"
	$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --colors=never --testdox "$T" > "$work/$v.out" 2>&1
	echo "$v exit=$? $(grep -E '^(OK|Tests:)' "$work/$v.out" | tail -1)"
	# testdox lines look like: " ✔ Query shape ... with data set "<name>"" (✘ on failure).
	sed -n -E 's/^ *(✔|✘|↩|∅) .*data set "([^"]+)".*/\2	\1/p' "$work/$v.out" | sort > "$work/$v.tsv"
	[ -s "$work/$v.tsv" ] || { echo "no testdox rows parsed for $v; raw output:"; head -40 "$work/$v.out"; }
done
printf '\n%-52s %-6s %-6s %-6s\n' 'data set' '2.0.1' 'fix' 'c1d9'
join -t '	' "$work/201.tsv" "$work/fix.tsv" | join -t '	' - "$work/c1d9.tsv" |
	awk -F'\t' '{ s = ""; for (i = 2; i <= 4; i++) s = s sprintf(" %-6s", ($i == "✔") ? "pass" : "FAIL"); printf "%-52s%s\n", $1, s }'
