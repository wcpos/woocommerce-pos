#!/bin/bash
# Reviewer mutation checks: apply each mutation to Products.php, run Test_Products.php, restore.
cd "$(dirname "$0")/../../.." || exit 1
F=includes/Products.php
cp "$F" "$F.orig"
trap 'cp "$F.orig" "$F"; rm -f "$F.orig"' EXIT
run() {
	local label=$1 expr=$2
	cp "$F.orig" "$F"
	perl -0pi -e "$expr" "$F"
	if cmp -s "$F" "$F.orig"; then echo "== $label: MUTATION DID NOT APPLY"; return; fi
	echo "== $label"
	pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Products.php 2>&1 \
		| grep -E '^[0-9]+\) |Failed asserting|^(OK|Tests:)' | head -8
}
run "M1 posts_where repeats the full list" 's/\t\t\t\$exclude_ids = \$this->ids_not_excluded_by_query_vars\( \$exclude_ids, \$query \);\n//'
run "M2 post__in branch dropped" 's/(\$post__in = \$query->get\( .post__in. \);\n\t\tif \( ! empty\( \$post__in \) )\)/$1 \&\& false)/'
run "M3 emptied post__not_in treated as excluded" 's/(if \( empty\( \$post__not_in \) \) \{\n\t\t\treturn )\$exclude_ids;/$1array();/'
run "M4 replaced post__not_in treated as excluded" 's/return array_keys\( array_diff_key\([^\n]*\n/return array();\n/'
run "M5 memo reused without source check" 's/ && \$this->excluded_ids_memo\[ \$key \]\[.source.\] === \$source//'
