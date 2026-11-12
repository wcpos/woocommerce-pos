#!/bin/bash
# Acceptance rerun for the session-row compare-and-swap. One summary line per suite.
# Usage: WPENV=<wp-env binary> accept.sh [test-file ...]
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
files=("$@")
[ ${#files[@]} -eq 0 ] && files=(
	tests/includes/Services/Test_Session_Registry_Concurrency.php
	tests/includes/Services/Test_Session_Registry.php
	tests/includes/Services/Test_Auth_Service.php
	tests/includes/Services/Test_Session_Revocation_On_Account_Events.php
	tests/includes/Services/Test_Auth_Revocation_Survives_Cache_Eviction.php
	tests/includes/API/Test_Auth_API.php
	tests/includes/API/Test_Auth_Refusal_Reason.php
	tests/includes/API/Test_Auth_Refresh_Status.php
	tests/includes/API/Test_Cashier_Core_User_Routes.php
	tests/includes/API/V2/Test_Sessions_Controller.php
	tests/includes/API/V2/Test_Auth_Sessions_Scope.php
	tests/includes/Test_Uninstall.php
)
rc=0
run() {
	local config=$1 f=$2 out code
	out=$($WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c "$config" "$f" 2>&1)
	code=$?
	[ $code -ne 0 ] && rc=1
	printf '%s [%s] exit=%s %s\n' "$f" "$config" "$code" "$(printf '%s\n' "$out" | grep -E '^(OK|Tests:|FAILURES)' | tail -1)"
	[ $code -ne 0 ] && printf '%s\n' "$out" | grep -E 'Failed asserting|^[0-9]+\) ' | head -10
}
for f in "${files[@]}"; do
	run .phpunit.xml.dist "$f"
done
[ $# -eq 0 ] && run .phpunit.multisite.xml.dist tests/includes/API/V2/Test_Auth_Sessions_Scope_Multisite.php
exit $rc
