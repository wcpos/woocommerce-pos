#!/bin/bash
# Reviewer mutation checks for the POS-2VT fix. Run from the worktree root on a clean tree.
set -u
F=includes/Sync/Order_Serializer.php
run() {
  ~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
    vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php 2>&1 \
    | grep -E "^[0-9]+\) |^(OK|Tests:)" | sed 's/^/    /'
}
mutate() {
  echo "== $1"
  perl -0pi -e "$2" "$F"
  if git diff --quiet -- "$F"; then echo "    MUTATION DID NOT APPLY"; return; fi
  run
  git checkout -- "$F"
}
mutate "M1 line meta ignores the filter" \
  's/return \x27_woocommerce_pos_uuid\x27 !== \$key && ! in_array\( \$key, \$excluded_meta, true \);/return \x27_woocommerce_pos_uuid\x27 !== \$key;/'
mutate "M2 order meta ignores the filter" \
  's/\n\t+&& ! in_array\( \$key, \$excluded_meta, true \);/;/'
mutate "M3 a non-empty filter drops every order meta row" \
  's/\t&& ! in_array\( \$key, \$excluded_meta, true \);/\t&& empty( \$excluded_meta );/'
mutate "M4 a non-empty filter drops every line meta row" \
  's/return \x27_woocommerce_pos_uuid\x27 !== \$key && ! in_array\( \$key, \$excluded_meta, true \);/return \x27_woocommerce_pos_uuid\x27 !== \$key && empty( \$excluded_meta );/'
git status --short -- "$F"
