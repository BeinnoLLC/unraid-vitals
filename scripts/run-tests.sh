#!/usr/bin/env bash
# Run every node:test suite in the repo.
#
# Why this exists: CI ran `npm run check`, which was lint-only. A behavioural
# regression (floor findings self-disabling when a prompt row got trimmed) sat
# on main and only surfaced by chance through an eval fixture — every suite in
# the tree passed the whole time because nothing ran them. Lint proves a file
# parses; it says nothing about whether the code does what it claims.
#
# Two roots hold suites: ./tests (repo-level) and
# src/.../agent/tests (agent-level). Both run in one invocation so the exit
# code covers everything, and a suite that does not exist is a failure rather
# than a silent skip.
set -euo pipefail

cd "$(dirname "$0")/.."

# -not -path: never collect a suite vendored inside a dependency tree. Today
# node_modules ships none, but a future `npm install` could add one and it
# would run as if it were ours — a failure we do not own and cannot fix.
mapfile -t SUITES < <(find tests src -name '*.test.mjs' -type f -not -path '*/node_modules/*' 2>/dev/null | sort)

if [ "${#SUITES[@]}" -eq 0 ]; then
  echo "run-tests: no *.test.mjs suites found under tests/ or src/ — refusing to report success" >&2
  exit 1
fi

echo "run-tests: ${#SUITES[@]} suite(s)"
printf '  - %s\n' "${SUITES[@]}"

# --test with explicit paths: node 20 (the CI version) supports this.
exec node --test "${SUITES[@]}"
