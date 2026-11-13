#!/bin/bash
# Reviewer mutation checks: apply each mutation to includes/Products.php, run the
# exclusion tests, expect a failure, restore. Run from the worktree root.
f=includes/Products.php
bak=$(mktemp "${CLAUDE_JOB_DIR:-/tmp}/tmp/Products.php.XXXX")
cp "$f" "$bak"
trap 'cp "$bak" "$f"' EXIT
filter='Test_Products(_Direct|_Query_Exclusion)?::'
run_tests() {
  ~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
    vendor/bin/phpunit -c .phpunit.xml.dist --filter "$filter" 2>&1 | grep -E '^[0-9]+\) |^(OK|FAILURES|Tests:)' | sed 's/^/    /'
}
mutate() {
  name=$1; perl_expr=$2
  cp "$bak" "$f"
  perl -0pi -e "$perl_expr" "$f"
  if cmp -s "$bak" "$f"; then echo "$name: MUTATION DID NOT APPLY"; return; fi
  echo "$name:"; run_tests
}
mutate "M1 pre_get_posts writes post__not_in on filtered queries too" \
  's/\t\tif \( ! \$query->get\( .suppress_filters. \) \) \{\n\t\t\treturn;\n\t\t\}\n//'
mutate "M2 pre_get_posts back at priority 10" \
  's/(hide_pos_only_products. \)), PHP_INT_MAX \)/$1 )/'
mutate "M3 memo never rebuilt" \
  's/ \|\| \$this->pos_only_exclusions\[ \$key \]\[.source.\] !== \$source//'
mutate "M4 posts_where appends nothing" \
  's/\$where \.= \$this->get_pos_only_excluded_ids\( \$query \)\[.sql.\];/\$where .= "";/'
mutate "M5 posts_where back at priority 10" \
  's/(exclude_pos_only_products_from_where. \)), PHP_INT_MAX, 2 \)/$1, 10, 2 )/'
