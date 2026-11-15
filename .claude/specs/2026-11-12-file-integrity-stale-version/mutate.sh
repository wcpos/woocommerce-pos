#!/bin/bash
# Reviewer mutation checks: apply each mutation to File_Integrity.php, run its test file, restore.
cd "$(dirname "$0")/../../.." || exit 1
F=includes/Services/File_Integrity.php
T=tests/includes/Services/Test_File_Integrity.php
cp "$F" "$F.orig"
trap 'cp "$F.orig" "$F"; rm -f "$F.orig"' EXIT
run() {
	local label=$1 expr=$2
	cp "$F.orig" "$F"
	perl -0pi -e "$expr" "$F"
	if cmp -s "$F" "$F.orig"; then echo "== $label: MUTATION DID NOT APPLY"; return; fi
	echo "== $label"
	pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist "$T" 2>&1 \
		| grep -E '^[0-9]+\) |Failed asserting|^(OK|Tests:)' | head -8
}
run "M1 stale-code guard removed" 's/if \( null !== \$on_disk && \$on_disk !== \(string\) \$target\[.version.\] \)/if ( false )/'
run "M2 guard skips whenever a header exists" 's/\$on_disk !== \(string\) \$target\[.version.\]/true/'
run "M3 free target names no main file" "s/\t\t\t'file'    => PLUGIN_PATH \. basename\( PLUGIN_FILE \),\n//"
run "M4 daily reuse tests the resolved target" 's/null === \$seam/null === \$target/'
