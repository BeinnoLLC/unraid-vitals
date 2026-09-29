#!/bin/bash
# unraid-vitals — regenerate docs/phases/ from GitHub (plan 106 P13-08).
#
# docs/phases/index.md used to be hand-maintained and drifted from GitHub
# (it listed 4 phases / 23 tickets while GitHub had grown to 13+ milestones
# and 38+ issues). This script is the fix AND the guard against it drifting
# again: run it any time issues/milestones change, or wire it into CI.
#
# Requires: gh (authenticated), jq.
set -euo pipefail

REPO="BeinnoLLC/unraid-vitals"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/docs/phases"
GH_URL="https://github.com/$REPO"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$OUT"

slug() {
  # lowercase, em/en-dash -> hyphen, everything else non-alnum -> hyphen,
  # trim leading/trailing hyphens.
  echo "$1" | tr '[:upper:]' '[:lower:]' \
    | sed -e 's/[—–]/-/g' -e 's/[^a-z0-9]\+/-/g' -e 's/^-\+//' -e 's/-\+$//'
}

bar() {
  # $1 = percent (0-100) -> a 30-char unicode progress bar.
  local pct=$1
  local n=$(( (pct * 30 + 50) / 100 ))
  local filled empty
  filled=$(printf '█%.0s' $(seq 1 "$n" 2>/dev/null) || true)
  empty=$(printf '░%.0s' $(seq 1 $((30 - n)) 2>/dev/null) || true)
  echo "${filled}${empty}"
}

echo "fetching issues + milestones from $REPO ..." >&2
# gh api --paginate on a plain array endpoint writes one JSON array per page,
# concatenated back to back (not one combined array) — jq -s 'add' on the
# whole stream flattens them into one array. Going through files (not `$()`
# var capture) so a large repo's payload never round-trips through a shell
# variable.
gh api "repos/$REPO/issues?state=all&per_page=100" --paginate > "$WORK/issues_raw.jsonl"
gh api "repos/$REPO/milestones?state=all&per_page=100" --paginate > "$WORK/milestones_raw.jsonl"
jq -s 'add' "$WORK/issues_raw.jsonl" > "$WORK/issues.json"
jq -s 'add' "$WORK/milestones_raw.jsonl" > "$WORK/milestones.json"

grand_total=0
grand_done=0
summary_file="$WORK/summary.md"
: > "$summary_file"

jq -r 'sort_by(.number) | .[].number' "$WORK/milestones.json" > "$WORK/ms_numbers.txt"

while IFS= read -r ms_number; do
  jq --argjson n "$ms_number" '.[] | select(.number == $n)' "$WORK/milestones.json" > "$WORK/ms.json"
  ms_title="$(jq -r '.title' "$WORK/ms.json")"
  ms_slug="$(slug "$ms_title")"

  jq --argjson n "$ms_number" '[.[] | select(.milestone != null and .milestone.number == $n)] | sort_by(.title)' \
    "$WORK/issues.json" > "$WORK/mine.json"
  total="$(jq 'length' "$WORK/mine.json")"
  done_count="$(jq '[.[] | select(.state == "closed")] | length' "$WORK/mine.json")"
  pct=0
  [ "$total" -gt 0 ] && pct=$(( done_count * 100 / total ))
  open_count=$(( total - done_count ))

  grand_total=$(( grand_total + total ))
  grand_done=$(( grand_done + done_count ))

  dir="$OUT/$ms_slug"
  mkdir -p "$dir"

  {
    echo "# $ms_title"
    echo ""
    echo "[All phases](../index.md) · [Milestone on GitHub]($GH_URL/milestone/$ms_number)"
    echo ""
    echo "**Progress:** \`[$(bar "$pct")] ${pct}%\` — **${done_count} / ${total}** tickets done (${open_count} open)"
    echo ""
    echo "> Ticket detail (type, priority, discussion) lives on GitHub. This file is a summary: progress and links only."
    echo ""
    echo "| Ticket | Title | Status |"
    echo "| --- | --- | --- |"
    jq -r '.[] |
      (.title | [capture("^\\[?(?<id>[A-Z]+\\d+-\\d+)\\]?\\s+(?<name>.*)$")]) as $m |
      (if ($m | length) > 0 then $m[0] else {id: "—", name: .title} end) as $t |
      "| `" + $t.id + "` | [" + $t.name + "](" + .html_url + ") | " + (if .state == "closed" then "done" else "open" end) + " |"' \
      "$WORK/mine.json"
    echo ""
  } > "$dir/index.md"

  echo "wrote $dir/index.md  ($done_count/$total)" >&2
  echo "| [$ms_title](./$ms_slug/index.md) | \`[$(bar "$pct")] ${pct}%\` | ${done_count} / ${total} | [#${ms_number}]($GH_URL/milestone/$ms_number) |" >> "$summary_file"
done < "$WORK/ms_numbers.txt"

grand_pct=0
[ "$grand_total" -gt 0 ] && grand_pct=$(( grand_done * 100 / grand_total ))

{
  echo "# unraid-vitals — phases"
  echo ""
  echo "**Overall progress:** \`[$(bar "$grand_pct")] ${grand_pct}%\` — **${grand_done} / ${grand_total}** tickets done"
  echo ""
  echo "> GitHub is the source of truth for tickets. These files summarise progress and link out; they hold no ticket detail."
  echo ""
  echo "| Phase | Progress | Tickets | Milestone |"
  echo "| --- | --- | --- | --- |"
  cat "$summary_file"
} > "$OUT/index.md"

echo "wrote $OUT/index.md  ($grand_done/$grand_total)" >&2

# Acceptance: the index total matches `gh issue list --state all`.
gh_total="$(gh issue list --repo "$REPO" --state all --limit 1000 --json number | jq 'length')"
if [ "$grand_total" -ne "$gh_total" ]; then
  echo "NOTE: docs/phases total ($grand_total) != gh issue list total ($gh_total) — some issues have no milestone and are not summarised here." >&2
fi
echo "gh issue list --state all total: $gh_total" >&2
