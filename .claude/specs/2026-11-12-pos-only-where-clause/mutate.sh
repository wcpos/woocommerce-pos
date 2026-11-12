#!/bin/bash
# Reviewer mutation checks: apply each to one file, run the matrix and Test_Products, restore.
# Usage: WPENV=<wp-env binary> mutate.sh [label-prefix ...]   (no prefix runs every mutation)
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
P=includes/Products.php
Q=tests/includes/Test_Products_Query_Shapes.php
X=tests/fixtures/products-2.0.1.php.txt
only=("$@")
for f in "$P" "$Q" "$X"; do cp "$f" "$f.orig"; done
trap 'for f in "$P" "$Q" "$X"; do cp "$f.orig" "$f"; rm -f "$f.orig"; done' EXIT
run() {
	local label=$1 file=$2 expr=$3 t p want=${#only[@]}
	for p in "${only[@]}"; do [ "${label%% *}" = "$p" ] && want=0; done
	[ "$want" -ne 0 ] && return
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
# Kept from the fail-closed round.
run "A2 post__not_in normalised with intval" "$P" 's/array_flip\( array_map\( .absint., \$post__not_in \) \)/array_flip( array_map( "intval", \$post__not_in ) )/'
run "A3 reference copy changed by one byte" "$X" 's/\z/\n/'
run "A4 swap hooks the tree class instead of the reference" "$Q" 's/new \$class\(\)/new \\WCPOS\\WooCommercePOS\\Products()/'
run "M1 single-id branch removed" "$P" 's/\t\tif \( \$single_id \) \{\n.*?\n\t\t\}\n//s'
run "M2 single-id branch never excludes" "$P" 's/return \\in_array\( \(int\) \$single_id, \$exclude_ids, true \) \? array\( \(int\) \$single_id \) : array\(\);/return array();/'
run "M6 unprovable p trusted (type guard removed)" "$P" 's/\t\t\tif \( ! \\is_int\( \$single_id \).*?\n\t\t\t\}\n\n?//s'
run "M7 post__not_in path appends the full list" "$P" 's/return array_keys\( array_diff_key\( array_flip\( \$exclude_ids \), array_flip\( array_map\( .absint., \$post__not_in \) \) \) \);/return \$exclude_ids;/'
run "M8 post__not_in path appends nothing" "$P" 's/return array_keys\( array_diff_key\( array_flip\( \$exclude_ids \), array_flip\( array_map\( .absint., \$post__not_in \) \) \) \);/return array();/'
# New in the where-clause round.
run "F1 p clause check removed" "$P" 's/\t\t\tif \( ! preg_match\( [^\n]*\n\t\t\t\treturn \$exclude_ids;\n\t\t\t\}\n//'
run "F2 p clause boundary removed" "$P" 's/ \. \x27\(\?!\[0-9A-Za-z_\.\]\)\/\x27/ . \x27\/\x27/'
run "F3 post__in clause check removed" "$P" 's/\t\t\tif \( false === strpos\( \$where, " AND \{\$wpdb->posts\}\.ID IN \([^\n]*\n\t\t\t\treturn \$exclude_ids;\n\t\t\t\}\n//'
run "F4 post__not_in clause check removed" "$P" 's/\t\tif \( false === strpos\( \$where, " AND \{\$wpdb->posts\}\.ID NOT IN \([^\n]*\n\t\t\treturn \$exclude_ids;\n\t\t\}\n//'
run "F5 post__in clause built without sort" "$P" 's/\t\t\tsort\( \$in_ids \);\n//'
run "F6 post__in clause built without array_unique" "$P" 's/array_unique\( array_map\( .absint., \$post__in \) \)/array_map( "absint", \$post__in )/'
