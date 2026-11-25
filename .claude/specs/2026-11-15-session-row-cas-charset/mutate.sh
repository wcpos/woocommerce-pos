#!/bin/bash
# Mutation checks on Session_Registry.php after the charset-safe compare: apply each, run the concurrency
# and registry suites, restore. "DID NOT APPLY" means the code differs from the spec's required shape.
# C* are the original review's mutations (C1 and C2 rewritten for the hash compare); M* are the
# independent review's (2026-11-15). Every one must turn at least one test red.
# Usage: WPENV=<wp-env binary> mutate.sh [--dry]   (--dry only reports whether each mutation applies)
cd "$(dirname "$0")/../../.." || exit 1
WPENV=${WPENV:-pnpm exec wp-env}
DRY=${1:-}
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
	[ "$DRY" = --dry ] && return
	for t in tests/includes/Services/Test_Session_Registry_Concurrency.php tests/includes/Services/Test_Session_Registry.php; do
		$WPENV run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$t" 2>&1 |
			grep -E '^[0-9]+\) |^(OK|Tests:)' | head -12 | sed "s|^|  $(basename "$t" .php): |"
	done
}
run "C1 UPDATE without the row compare" 's/ AND SHA1\(meta_value\) = %s"/ AND %s <> \x27\x27"/'
run "C2 hash taken in PHP from the connection's copy" 's/\$rows\[0\]->meta_hash/sha1( \$stored_value )/'
run "C3 one attempt only" 's/(MAX_ROW_WRITE_ATTEMPTS\s*=\s*)5/${1}1/'
run "C5 no cache delete after a write" 's/wp_cache_delete\( \$user_id, .user_meta. \);//g'
run "C6 no no-op skip" 's/if \( null === \$new_value \|\| \$serialized === \$stored_value \)/if ( null === \$new_value )/'
run "M1 highest umeta_id is the row" 's/ORDER BY umeta_id ASC/ORDER BY umeta_id DESC/'
run "M2 insert always claims the win" 's/if \( ! empty\( \$rows \) && \(int\) \$rows\[0\]->umeta_id === \$inserted_id \)/if ( true )/'
run "M3 a lost swap counts as success" 's/if \( 1 === \$updated \)/if ( false !== \$updated )/'
run "M4 retries reuse the first read" 's/\$rows = \$wpdb->get_results\( \$select \);(\s+if \( .. !== \$wpdb->last_error)/\$rows = \$rows ?? \$wpdb->get_results( \$select );$1/'
run "M5 no cache delete after a winning UPDATE" 's/(\t\t\t\t\}\n)\t\t\t\twp_cache_delete\( \$user_id, .user_meta. \);\n(\n\t\t\t\treturn true;)/$1$2/'
