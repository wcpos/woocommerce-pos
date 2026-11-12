#!/bin/bash
# Reviewer mutation checks: apply each to one file, run the matrix and Test_Products, restore.
# Usage: WPENV=<wp-env binary> mutate.sh
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
P=includes/Products.php
Q=tests/includes/Test_Products_Query_Shapes.php
X=tests/fixtures/products-2.0.1.php.txt
for f in "$P" "$Q" "$X"; do cp "$f" "$f.orig"; done
trap 'for f in "$P" "$Q" "$X"; do cp "$f.orig" "$f"; rm -f "$f.orig"; done' EXIT
run() {
	local label=$1 file=$2 expr=$3 t
	for f in "$P" "$Q" "$X"; do cp "$f.orig" "$f"; done
	perl -0pi -e "$expr" "$file"
	if cmp -s "$file" "$file.orig"; then
		echo "== $label: MUTATION DID NOT APPLY"
		return
	fi
	echo "== $label"
	for t in "$Q" tests/includes/Test_Products.php; do
		$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$t" 2>&1 |
			grep -E '^[0-9]+\) |^(OK|Tests:)' | head -12 | sed "s|^|  $(basename "$t" .php): |"
	done
}
run "A1 post__in normalised with intval" "$P" 's/array_flip\( array_map\( .absint., \(array\) \$post__in \) \)/array_flip( array_map( "intval", (array) \$post__in ) )/'
run "A2 post__not_in normalised with intval" "$P" 's/array_flip\( array_map\( .absint., \(array\) \$post__not_in \) \)/array_flip( array_map( "intval", (array) \$post__not_in ) )/'
run "A3 reference copy changed by one byte" "$X" 's/\z/\n/'
run "A4 swap hooks the tree class instead of the reference" "$Q" 's/new \$class\(\)/new \\WCPOS\\WooCommercePOS\\Products()/'
run "M1 single-id branch removed" "$P" 's/\t\tif \( \$single_id > 0 \) \{\n.*?\n\t\t\}\n//s'
run "M2 single-id branch never excludes" "$P" 's/return \\in_array\( \$single_id, \$exclude_ids, true \) \? array\( \$single_id \) : array\(\);/return array();/'
run "M4 post__in checked before p" "$P" 's/\$single_id = absint\( \$query->get\( .p. \) \);/\$single_id = empty( \$query->get( "post__in" ) ) ? absint( \$query->get( "p" ) ) : 0;/'
