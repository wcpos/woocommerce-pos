#!/bin/bash
# Run the query-shape matrix with three Products.php as the tree: 2.0.1, the fix, and #2647's 37dfea28.
# The matrix itself runs every row against the 2.0.1 reference copy too, so a row passes only when the
# tree gives 2.0.1's result. Prints one table row per data set: <data set>  2.0.1  fix  37dfea28.
# Usage: WPENV=<wp-env binary> parity.sh
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
F=includes/Products.php
T=tests/includes/Test_Products_Query_Shapes.php
work=$(mktemp -d "${TMPDIR:-/tmp}/parity.XXXXXX")
cp "$F" "$work/fix.php"
trap 'cp "$work/fix.php" "$F"; rm -rf "$work"' EXIT
git show 82bf7d657a4313798d00f12bdc3284d65f2446ff:$F > "$work/201.php" || exit 1
git show 37dfea28ab72b8dd59fd051925ba82e9546468d5:$F > "$work/37df.php" || exit 1
for v in 201 fix 37df; do
	cp "$work/$v.php" "$F"
	$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --colors=never --testdox --filter 'matches_2_0_1_reference' "$T" > "$work/$v.out" 2>&1
	echo "$v exit=$? $(grep -E '^(OK|Tests:)' "$work/$v.out" | tail -1)"
	sed -n -E 's/^ *(✔|✘|↩|∅) .*data set "([^"]+)".*/\2	\1/p' "$work/$v.out" | sort > "$work/$v.tsv"
	[ -s "$work/$v.tsv" ] || { echo "no testdox rows parsed for $v; raw output:"; head -40 "$work/$v.out"; }
done
printf '\n%-56s %-6s %-6s %-8s\n' 'data set' '2.0.1' 'fix' '37dfea28'
join -t '	' "$work/201.tsv" "$work/fix.tsv" | join -t '	' - "$work/37df.tsv" |
	awk -F'\t' '{ s = ""; for (i = 2; i <= 4; i++) s = s sprintf(" %-6s", ($i == "✔") ? "pass" : "FAIL"); printf "%-56s%s\n", $1, s }'
echo
echo "rows: 2.0.1=$(wc -l < "$work/201.tsv") fix=$(wc -l < "$work/fix.tsv") 37dfea28=$(wc -l < "$work/37df.tsv")"
grep -E 'tree differs from 2.0.1|2.0.1 reference' "$work/37df.out" | sort | uniq -c
