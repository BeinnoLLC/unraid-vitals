#!/usr/bin/env bash
# Run every standalone PHP acceptance suite in the repo.
#
# Why this exists: `npm run lint:php` only runs `php -l`, and `run-tests.sh`
# only collects *.test.mjs. The twenty-five tests/test-*.php suites therefore
# ran nowhere — not locally, not in CI. They were not dead, they were orphans:
# test-checks.php was genuinely failing (it asserted total finding counts from
# when rootfs_full was the only check registered; eighteen checks later a bare
# snapshot trips several of them, so it broke on every unrelated addition and
# nobody saw it). A suite nothing runs is worse than no suite, because it reads
# as coverage.
#
# Each suite is a plain script that prints PASS/FAIL lines and exits nonzero on
# failure, so the contract here is just its exit code.
#
# Requires the sqlite3 extension: the engine persists findings and events
# through SQLite3 (include/db.php, include/checks.php). Without it the DB
# helpers return null and suites that exercise persistence fail for a reason
# that has nothing to do with the code under test — so check for it up front and
# say so, rather than reporting a wall of bogus failures.
set -uo pipefail

cd "$(dirname "$0")/.."

if ! command -v php >/dev/null 2>&1; then
  echo "run-php-tests: php not found on PATH" >&2
  exit 1
fi

if ! php -m | grep -qi '^sqlite3$'; then
  echo "run-php-tests: the sqlite3 PHP extension is missing — install it (Debian/Ubuntu: php-sqlite3)." >&2
  echo "run-php-tests: without it, suites that exercise persistence fail for the wrong reason." >&2
  exit 1
fi

# -not -path: never collect a suite vendored inside a dependency tree, same
# reasoning as run-tests.sh.
mapfile -t SUITES < <(find tests -name 'test-*.php' -type f -not -path '*/node_modules/*' 2>/dev/null | sort)

if [ "${#SUITES[@]}" -eq 0 ]; then
  echo "run-php-tests: no test-*.php suites found under tests/ — refusing to report success" >&2
  exit 1
fi

echo "run-php-tests: ${#SUITES[@]} suite(s)"
failed=()
for f in "${SUITES[@]}"; do
  if php "$f" >/dev/null 2>&1; then
    printf '  ok   %s\n' "$f"
  else
    printf '  FAIL %s\n' "$f"
    failed+=("$f")
  fi
done

if [ "${#failed[@]}" -gt 0 ]; then
  echo
  echo "run-php-tests: ${#failed[@]} of ${#SUITES[@]} suite(s) failed:"
  # Re-run them with output visible from here on: the first pass only needed the
  # exit code, but the failures are what the reader actually came for.
  for f in "${failed[@]}"; do
    echo "--- $f"
    php "$f" || true
  done
  exit 1
fi

echo "run-php-tests: all ${#SUITES[@]} suite(s) passed"
