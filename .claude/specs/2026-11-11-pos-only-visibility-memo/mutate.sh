#!/bin/bash
# Reviewer mutation checks: apply each mutation to Visibility_Section.php, run its test file, restore.
cd "$(dirname "$0")/../../.." || exit 1
F=includes/Services/Settings/Visibility_Section.php
cp "$F" "$F.orig"
trap 'cp "$F.orig" "$F"; rm -f "$F.orig"' EXIT
run() {
	local label=$1 expr=$2
	cp "$F.orig" "$F"
	perl -0pi -e "$expr" "$F"
	if cmp -s "$F" "$F.orig"; then echo "== $label: MUTATION DID NOT APPLY"; return; fi
	echo "== $label"
	pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Settings/Test_Visibility_Section.php 2>&1 \
		| grep -E '^[0-9]+\) |Failed asserting|^(OK|Tests:)' | head -8
}
run "M1 read_raw not memoized" 's/(protected function read_raw\(\): array \{\n)/$1\t\treturn parent::read_raw();\n/'
run "M2 no flush on update_option" 's/\t\tadd_action\( "update_option_\{\$option\}"[^\n]*\n//'
run "M3 no flush on delete_option" 's/\t\tadd_action\( "delete_option_\{\$option\}"[^\n]*\n//'
run "M4 no flush on add_option" 's/\t\tadd_action\( "add_option_\{\$option\}"[^\n]*\n//'
run "M5 id set reused without identity check" 's/ \|\| \$this->id_sets\[ \$list \]\[.ids.\] !== \$ids//'
