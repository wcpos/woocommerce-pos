#!/bin/bash
# Acceptance rerun: the #2647 suites plus the query-shape matrix. One summary line per suite.
# Usage: WPENV=<wp-env binary> accept.sh [test-file ...]   (WPENV defaults to `pnpm exec wp-env`)
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
files=("$@")
[ ${#files[@]} -eq 0 ] && files=(
	tests/includes/Test_Products_Query_Shapes.php
	tests/includes/Test_Products.php
	tests/includes/Test_Products_Direct.php
	tests/includes/Test_Catalog_Visibility.php
	tests/includes/Sync/Test_Pos_Visibility.php
	tests/includes/API/V2/Test_Catalog_Proxy_Visibility.php
	tests/includes/API/V2/Test_Variations_Search.php
	tests/includes/Sync/Test_Collection_Rules_Search_Parity.php
)
rc=0
if ! cmp -s tests/fixtures/products-2.0.1.php.txt <(git show v2.0.1:includes/Products.php); then
	echo "tests/fixtures/products-2.0.1.php.txt is not byte-identical to v2.0.1:includes/Products.php"
	rc=1
fi
for f in "${files[@]}"; do
	out=$($WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$f" 2>&1)
	code=$?
	[ $code -ne 0 ] && rc=1
	printf '%s exit=%s %s\n' "$f" "$code" "$(printf '%s\n' "$out" | grep -E '^(OK|Tests:|FAILURES)' | tail -1)"
	[ $code -ne 0 ] && printf '%s\n' "$out" | grep -E 'Failed asserting|^[0-9]+\) ' | head -10
done
exit $rc
