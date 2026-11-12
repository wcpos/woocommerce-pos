#!/bin/bash
# Reviewer mutation checks on the single-id branch: apply each, run the matrix and Test_Products, restore.
# Usage: WPENV=<wp-env binary> mutate.sh
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
F=includes/Products.php
cp "$F" "$F.orig"
trap 'cp "$F.orig" "$F"; rm -f "$F.orig"' EXIT
run() {
	local label=$1 expr=$2 t
	cp "$F.orig" "$F"
	perl -0pi -e "$expr" "$F"
	if cmp -s "$F" "$F.orig"; then
		echo "== $label: MUTATION DID NOT APPLY"
		return
	fi
	echo "== $label"
	for t in tests/includes/Test_Products_Query_Shapes.php tests/includes/Test_Products.php; do
		$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$t" 2>&1 |
			grep -E '^[0-9]+\) |^(OK|Tests:)' | head -12 | sed "s|^|  $(basename "$t" .php): |"
	done
}
run "M1 single-id branch removed" 's/\t\tif \( \$single_id > 0 \) \{\n.*?\n\t\t\}\n//s'
run "M2 single-id branch never excludes" 's/return \\in_array\( \$single_id, \$exclude_ids, true \) \? array\( \$single_id \) : array\(\);/return array();/'
run "M3 single-id branch repeats the full list" 's/return \\in_array\( \$single_id, \$exclude_ids, true \) \? array\( \$single_id \) : array\(\);/return \$exclude_ids;/'
run "M4 post__in checked before p" 's/\$single_id = absint\( \$query->get\( .p. \) \);/\$single_id = empty( \$query->get( "post__in" ) ) ? absint( \$query->get( "p" ) ) : 0;/'
run "M5 caller args read instead of resolved p" 's/\$single_id = absint\( \$query->get\( .p. \) \);/\$single_id = absint( \$query->query["p"] ?? 0 );/'
