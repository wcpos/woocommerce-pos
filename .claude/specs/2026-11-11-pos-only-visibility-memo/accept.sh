#!/bin/bash
# Reviewer rerun of the spec's acceptance suites. Prints one summary line per suite.
# Usage: accept.sh [test-file ...] (default: the six spec suites)
cd "$(dirname "$0")/../../.." || exit 1
files=("$@")
[ ${#files[@]} -eq 0 ] && files=(
	tests/includes/Services/Settings/Test_Visibility_Section.php
	tests/includes/Test_Init_Hook_Wiring.php
	tests/includes/Test_Catalog_Visibility.php
	tests/includes/Test_Products.php
	tests/includes/Sync/Test_Pos_Visibility.php
	tests/includes/Sync/Test_Sync_Visibility_Change_Signal.php
)
rc=0
for f in "${files[@]}"; do
	out=$(pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$f" 2>&1)
	code=$?
	[ $code -ne 0 ] && rc=1
	printf '%s exit=%s %s\n' "$f" "$code" "$(printf '%s\n' "$out" | grep -E '^(OK|Tests:|FAILURES)' | tail -1)"
	[ $code -ne 0 ] && printf '%s\n' "$out" | grep -E 'Failed asserting|^[0-9]+\) ' | head -6
done
exit $rc
