#!/usr/bin/env bash
# Tests for readme-changelog-section.sh. Run: bash .github/scripts/test-readme-changelog-section.sh
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
script="$here/readme-changelog-section.sh"
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$tmp/readme.txt" <<'EOF'
=== WCPOS ===
Stable tag: 1.10.16

== Changelog ==

= 1.10.16 - 2026/09/16 =

- **First entry.** With detail.
- **Second entry.**

= 1.10.15 - 2026/09/15 =

- **Older entry.**

= 1.10.1 - 2026/09/01 =

**Prose entry** without a bullet.

= 1.10.0 =

= 1.9.9 - 2026/08/01 =

- Old.
EOF

# 1. Prints exactly the section body, without the heading or surrounding blank lines.
out=$(bash "$script" 1.10.16 "$tmp/readme.txt")
expected=$'- **First entry.** With detail.\n- **Second entry.**'
[[ "$out" == "$expected" ]] || fail "1.10.16 body mismatch:\n$out"

# 2. A later section is bounded by the next heading, not the end of the file.
out=$(bash "$script" 1.10.15 "$tmp/readme.txt")
[[ "$out" == "- **Older entry.**" ]] || fail "1.10.15 body mismatch: $out"

# 3. Prose (no bullet) still counts as notes.
out=$(bash "$script" 1.10.1 "$tmp/readme.txt")
[[ "$out" == "**Prose entry** without a bullet." ]] || fail "1.10.1 body mismatch: $out"

# 4. Version match is exact: 1.10.1 must not match 1.10.16 or 1.10.15, and dots are literal.
out=$(bash "$script" 1.10.1 "$tmp/readme.txt")
[[ "$out" != *"First entry"* && "$out" != *"Older entry"* ]] || fail "1.10.1 matched a longer version"
if bash "$script" 1x10x16 "$tmp/readme.txt" >/dev/null 2>&1; then fail "dots were treated as wildcards"; fi

# 5. An empty section fails closed (exit 4).
set +e; bash "$script" 1.10.0 "$tmp/readme.txt" >/dev/null 2>"$tmp/err"; rc=$?; set -e
[[ $rc -eq 4 ]] || fail "empty section should exit 4, got $rc"
grep -q 'is empty' "$tmp/err" || fail "empty-section error text missing"

# 6. A missing section fails closed (exit 3).
set +e; bash "$script" 9.9.9 "$tmp/readme.txt" >/dev/null 2>"$tmp/err"; rc=$?; set -e
[[ $rc -eq 3 ]] || fail "missing section should exit 3, got $rc"
grep -q 'no "= 9.9.9' "$tmp/err" || fail "missing-section error text missing"

# 7. No version / unreadable readme are usage errors (exit 2).
set +e; bash "$script" "" "$tmp/readme.txt" >/dev/null 2>&1; rc=$?; set -e
[[ $rc -eq 2 ]] || fail "empty version should exit 2, got $rc"
set +e; bash "$script" 1.10.16 "$tmp/nope.txt" >/dev/null 2>&1; rc=$?; set -e
[[ $rc -eq 2 ]] || fail "unreadable readme should exit 2, got $rc"

echo "ok: readme-changelog-section.sh (7 checks)"
