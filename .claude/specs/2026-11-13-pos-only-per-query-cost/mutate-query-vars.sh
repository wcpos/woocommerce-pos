#!/bin/bash
# Reviewer mutation checks for the query-vars fix (SPEC-query-vars.md): apply each
# mutation to includes/Products.php, run the product tests, expect a failure,
# restore. Run from the worktree root.
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
mutate "Q1 filtered queries skip post__not_in again (the #2662 regression)" \
  's/(public function hide_pos_only_products\( \\WP_Query \$query \): void \{\n)/$1\t\tif ( ! \$query->get( "suppress_filters" ) ) {\n\t\t\treturn;\n\t\t}\n/'
mutate "Q2 posts_where trusts core NOT IN even when p or post__in is set" \
  's/\$query->get\( .p. \) \|\| ! empty\( \$query->get\( .post__in. \) \) \|\| //'
mutate "Q3 posts_where ignores p only" \
  's/\$query->get\( .p. \) \|\| (! empty\( \$query->get\( .post__in. \) \))/$1/'
mutate "Q4 posts_where ignores post__in only" \
  's/ \|\| ! empty\( \$query->get\( .post__in. \) \)//'
mutate "Q5 posts_where trusts a stripped WHERE (no strpos check)" \
  's/ \|\| false === strpos\( \$where, [^)]*\) \)/ )/'
mutate "Q6 posts_where always appends (list written twice)" \
  's/if \( \$query->get\( .p. \) \|\| [^\n]*\{\n/if ( true ) {\n/'
mutate "Q7 post__in never trimmed" \
  's/\$post__in = \(array\) \$query->get\( .post__in. \);/\$post__in = array();/'
mutate "Q7b post__in trimmed only on suppressed queries (the first query-vars shape)" \
  's/\$post__in = \(array\) \$query->get\( .post__in. \);/\$post__in = \$query->get( "suppress_filters" ) ? (array) \$query->get( "post__in" ) : array();/'
mutate "Q8 pre_get_posts back at priority 10" \
  's/(hide_pos_only_products. \)), PHP_INT_MAX \)/$1 )/'
