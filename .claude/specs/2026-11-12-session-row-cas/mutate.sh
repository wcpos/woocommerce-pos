#!/bin/bash
# Reviewer mutation checks on Session_Registry.php: apply each, run the concurrency and registry suites, restore.
# Each pattern targets the shape the spec requires; "DID NOT APPLY" means the code differs from the spec and the
# reviewer must write the equivalent mutation by hand.
# Usage: WPENV=<wp-env binary> mutate.sh
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
R=includes/Services/Session_Registry.php
cp "$R" "$R.orig"
trap 'cp "$R.orig" "$R"; rm -f "$R.orig"' EXIT
run() {
	local label=$1 expr=$2 t
	cp "$R.orig" "$R"
	perl -0pi -e "$expr" "$R"
	if cmp -s "$R" "$R.orig"; then
		echo "== $label: MUTATION DID NOT APPLY"
		return
	fi
	echo "== $label"
	for t in tests/includes/Services/Test_Session_Registry_Concurrency.php tests/includes/Services/Test_Session_Registry.php; do
		$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$t" 2>&1 |
			grep -E '^[0-9]+\) |^(OK|Tests:)' | head -12 | sed "s|^|  $(basename "$t" .php): |"
	done
}
run "C1 UPDATE without the value compare" 's/ AND CAST\(meta_value AS BINARY\) = CAST\(%s AS BINARY\)//'
run "C2 value compare under the column collation" 's/CAST\(meta_value AS BINARY\) = CAST\(%s AS BINARY\)/meta_value = %s/'
run "C3 one attempt only" 's/(MAX_ROW_WRITE_ATTEMPTS\s*=\s*)5/${1}1/'
run "C5 no cache delete after a write" 's/wp_cache_delete\( \$user_id, .user_meta. \);//g'
run "C6 no no-op skip" 's/if \( null === \$new_value \|\| \$serialized === \$stored_value \)/if ( null === \$new_value )/'
