#!/usr/bin/env bash
# check-design-tokens.sh — report raw colour literals or font-family declarations
# outside the token section of css/vitals.css: the `.vitals { ... }` rule at the
# top plus theme-scoped token-override blocks (`html.Theme--* .vitals { ... }`),
# which are token definitions too and may carry literals. See DESIGN.md and
# docs/design-audit.md.
#
# Usage: scripts/check-design-tokens.sh [plugin_dir]
#   MAX_HITS=<n>  baseline of tolerated literals (default 19, the count after UV6:
#                 18 css/tile hits from UV4/UV7/UV14 + withAlpha()'s rgba string
#                 builder in js). Exit 1 only when hits > MAX_HITS. Lower it as
#                 literals are migrated to tokens; MAX_HITS=0 makes the check strict.
set -euo pipefail
ROOT="${1:-src/usr/local/emhttp/plugins/unraid-vitals}"
CSS="$ROOT/css/vitals.css"
MAX_HITS="${MAX_HITS:-19}"
COLOR_RE='#[0-9a-fA-F]{3,8}\b|\b(rgba?|hsla?)\('
FONT_RE='font-family|fontFamily'
PATTERN="($COLOR_RE)|($FONT_RE)"

if ! [[ "$MAX_HITS" =~ ^[0-9]+$ ]]; then
  echo "check-design-tokens: MAX_HITS must be a non-negative integer (got '$MAX_HITS')" >&2; exit 2
fi

read -r TOK_START TOK_END < <(awk '
  /^\.vitals[[:space:]]*\{[[:space:]]*$/ && !s { s=NR; next }
  s && !e && /^\}[[:space:]]*$/ { e=NR }
  END { print (s?s:0), (e?e:0) }' "$CSS")
if [ "$TOK_START" -eq 0 ] || [ "$TOK_END" -eq 0 ]; then
  echo "check-design-tokens: token block '.vitals { ... }' not found in $CSS" >&2; exit 2
fi

# Theme-scoped token-override block(s), e.g. html.Theme--white .vitals,
# html.Theme--azure .vitals { ... } (UV3) — token definitions as well, so the
# literals on their declaration lines must not count. Empty when absent.
TOK_OVR=$(awk '
  !inb && /^html\.Theme--.*\.vitals[[:space:]]*\{[[:space:]]*$/ { s=NR; inb=1; next }
  inb && /^\}[[:space:]]*$/ { printf "%s%d-%d", (n++ ? " " : ""), s, NR; inb=0 }' "$CSS")

TOK_DESC="$TOK_START-$TOK_END"
if [ -n "$TOK_OVR" ]; then
  TOK_DESC="$TOK_DESC, $TOK_OVR"
fi

hits=$(
  {
    awk -v s="$TOK_START" -v e="$TOK_END" -v ovr="$TOK_OVR" -v f="$CSS" \
      'BEGIN { n=split(ovr, R, " "); for (i=1; i<=n; i++) { split(R[i], a, "-"); for (j=a[1]; j<=a[2]; j++) skip[j]=1 } }
       (NR>=s && NR<=e) || skip[NR] { next }
       { print f ":" NR ":" $0 }' "$CSS"
    find "$ROOT" -type f \( -name '*.css' -o -name '*.js' -o -name '*.page' \) \
      -not -path "$ROOT/js/vendor/*" -not -name 'uPlot.min.css' -not -path "$CSS" \
      -not -path '*/node_modules/*' -not -path "$ROOT/agent/*" -print0 \
      | xargs -0 -r grep -nH ''
  } | grep -E "$PATTERN" || true
)

if [ -z "$hits" ]; then
  echo "check-design-tokens: OK (0 hits; token section $CSS:$TOK_DESC)"; exit 0
fi
lines=$(printf '%s\n' "$hits" | wc -l)
occ=$(printf '%s\n' "$hits" | cut -d: -f3- | grep -oE "$PATTERN" | wc -l)

if [ "$occ" -le "$MAX_HITS" ]; then
  echo "check-design-tokens: OK — $occ literal(s) on $lines line(s) outside the token section $CSS:$TOK_DESC (baseline MAX_HITS=$MAX_HITS)"
  if [ "$occ" -lt "$MAX_HITS" ]; then
    echo "check-design-tokens: below baseline — lower MAX_HITS to $occ in scripts/check-design-tokens.sh"
  fi
  exit 0
fi

printf '%s\n' "$hits" | cut -c1-200
echo "check-design-tokens: FAIL — $occ literal(s) on $lines line(s) outside the token section $CSS:$TOK_DESC (baseline MAX_HITS=$MAX_HITS, +$((occ - MAX_HITS)))" >&2
printf '%s\n' "$hits" | cut -d: -f1 | sort | uniq -c | sed 's/^/  per-file lines: /' >&2
echo "check-design-tokens: use the --v-* tokens from the token section $CSS:$TOK_DESC instead of raw hex/rgb/font-family (see DESIGN.md)" >&2
exit 1
