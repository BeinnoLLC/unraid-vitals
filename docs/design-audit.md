# unraid-vitals: code vs. DESIGN.md mismatches

Source: read-only audit of `/home/hazemhagrass/workspace/unraid-vitals` @ `31de94e` (main, clean
tree) on 2026-11-13. Paths are relative to `src/usr/local/emhttp/plugins/unraid-vitals/`.
`css` = `css/vitals.css`, `js` = `js/vitals.js`, `tile` = `Vitals.Dashboard.page`.
Vendor files (`js/vendor/*`, `css/uPlot.min.css`) are out of scope.

Each item is one candidate Plane ticket: **ID title** — where (file:line, count) — token or
pattern it should use — severity (High / Medium / Low) — one-PR fix scope. IDs match "Known
mismatches" in `DESIGN.md`. Contrast numbers come from WCAG 2.x luminance against upstream webGui
theme backgrounds: black/gray `#1d1b1b`, white `#f2f2f2`, azure `#e8e8e8`.

## Top 10 (fix in this order)

1. **UV1 Overlay surfaces are hard-wired dark, so the modal is unreadable on light themes** —
   `css:166` `.v-modal { background: var(--v-panel, #1a1a1a) }`, `css:267` `.v-drawer { background:
   var(--v-bg, #161616) }` (2 places, 3 components: share browser, log drawer, findings drawer).
   `--v-panel` and `--v-bg` are never defined, so the fallbacks always apply. The text is inherited:
   on white it is `#1d1b1b` on `#1a1a1a` = 1.02:1. A harness render confirmed the white-theme
   modal content is invisible. → new token `--v-surface: var(--background-color, #1d1b1b)`
   (Unraid's own theme var, the fallback kept only inside the token block) used by modal and
   drawer. — **High** — PR: add `--v-surface` to the token block, swap the 2 declarations, and
   screenshot all 4 themes.
2. **UV2 Floating surfaces are translucent** — `css:551` `.v-chart-menu` and `css:795` `.v-toast`
   use `var(--v-card)` (`rgba(127,127,127,.045)`, computed `rgba(127,127,127,0.043)`). The chart or page
   shows through the legend popover and the toasts. The toast fallback `#1b1e24` is dead. →
   `--v-surface` from UV1, keep `--v-border` + shadow. — **High** — PR: 2 declarations (can ride
   with UV1 if kept to these 4 overlay rules).
3. **UV3 Status and accent text tints fail on white and azure** — `--v-ok-fg` 1.56/1.42,
   `--v-warn-fg` 1.49/1.36, `--v-bad-fg` 2.47/2.26, `--v-accent-fg` 2.66/2.43, `--v-bad` 3.36/3.07
   (white/azure). They are used by `.ok/.warn/.crit` (`css:509-511`), pills, active tab (`css:673`),
   card deltas and sensor values. → theme-scoped overrides in the token section:
   `html.Theme--white .vitals, html.Theme--azure .vitals { --v-ok-fg:#166534; --v-warn-fg:#92400e;
   --v-bad-fg:#b91c1c; --v-bad:#b91c1c; --v-accent-fg:#4f46e5 }` (6.37 / 6.33 / 5.78 / 5.78 / 5.62
   on white, ≥5.13 on azure). Values are a proposal and need the owner's OK. — **High** —
   PR: one CSS block + 4-theme screenshots. JS `PAL` picks the new values up through
   `resolvePalette()` once the vars change.
4. **UV8 Keyboard and dialog semantics missing** — clickable `span`/`div`: KB chips `js:2866,
   2869, 2877, 2879, 2884, 2886`, tag badge `js:2900`, share-browser rows `js:2077, 2085`, toast
   `js:2667` (10 interactive non-buttons; the 3 backdrops `js:723, 1155, 1441` and `js:2070-2071` are
   fine as pointer-only extras). No `keydown`/Escape handler anywhere in `js`. Modal and drawers
   have no `role="dialog"`/`aria-modal`, no focus move or return. → `<button>` elements, plus one
   shared `useDialog()` hook (Escape, focus first control, restore focus, `role="dialog"`,
   `aria-modal`, `aria-labelledby`). — **High** — PR 1: chips/rows/toast → buttons. PR 2: the
   dialog hook for modal + 2 drawers.
5. **UV4 Undefined custom properties** — `--v-fg` ×8 (`css:200, 292, 307, 321, 333, 547, 559,
   573`), `--v-bg2` (`css:135`), `--v-panel` (`css:166`), `--v-bg` (`css:267`), `--v-panel-bg`
   (`css:674`): 12 uses, 0 definitions. `color: var(--v-fg)` falls back to inherit by luck, while
   `css:333` `color-mix(… var(--v-fg) 4% …)` is invalid, so the chart-stat chip loses its fill.
   `.v-price-input` gets no background. → define `--v-fg: currentColor` in the token block,
   replace `--v-bg2` with `--v-card`, and use `--v-surface` (UV1) for `--v-panel`/`--v-bg`/`--v-panel-bg`.
   — **Medium** — PR: token block + 12 replacements.
6. **UV5 Raw status hex and wrong fallbacks in vitals.css** — 95 lint hits in `css` outside the
   token block (see the lint section). Largest groups: `#ef4444` ×18 (11 are `var(--v-bad,
   #ef4444)` fallbacks, plus `css:819-820` raw), `#f59e0b` ×13 (`css:181, 185, 243, 244, 259, 295,
   297, 371, 817, 818`) → `--v-warn`/`--v-warn-fg`; `#fff` ×10 (`css:90, 107, 119, 177, 184,
   186, 223, 294, 296, 386`) → new `--v-on-accent`; `#4f9cf9` ×6 (`css:182, 186, 296, 369`) →
   new `--v-info` (or `--v-cpu`); `#1a1a1a` ×4 (`css:166, 185, 187, 295`) → new `--v-on-warn`;
   `#2dd4bf` ×3 (`css:625-626`) → new `--v-think`; `#34d399` ×3 + `#f87171` ×3 + `#f87171aa`
   + `#60a5fa` (`css:351, 352, 357, 502, 798, 799, 816`) → `--v-ok`, `--v-bad-fg`, `--v-info`;
   `rgba(127,127,127,.08–.4)` ×10 → `--v-card`/`--v-card-hover`/`--v-border` or a new
   `--v-track`. Five fallbacks disagree with their own token: `var(--v-bad-fg, #ef4444)` ×4
   (token `#f87171`), `var(--v-warn, #fbbf24)` ×2 (`#f59e0b`), `var(--v-ok, #34d399)` ×2
   (`#22c55e`), `var(--v-ok-fg, #22c55e)` ×1 (`#4ade80`). → drop every `var(--v-*, #…)` fallback.
   — **Medium** — PR: 4 new tokens (`--v-on-accent`, `--v-on-warn`, `--v-info`, `--v-think`) +
   mechanical replacement. Rendering changes only where a raw value differed from its token.
7. **UV12 Filled status surfaces below AA** — white on `--v-accent` 4.47:1 (primary button
   `css:107`, `.v-ibtn.on` :90, `.v-btn.xs.active` :119, `.v-chip.on` :177, `.v-seg-btn.active`
   :223); white on `#ef4444` 3.76 (critical badge :294, fan badge :386); white on `#4f9cf9` 2.82
   (info badge :296, medium chip on :186); white on `#f87171` 2.77 (critical chip on :184). →
   darken the fill for text use (`--v-accent` → `#4f46e5` gives 6.29, critical fill `#dc2626`
   gives 4.83, info fill `#2563eb` gives 5.17), or put `--v-on-warn` dark text on the light fills
   (6.29 / 6.18). — **Medium** — PR: after UV5, change the 3 token values + screenshot.
8. **UV6 Duplicate colour system in vitals.js** — 71 lint hits in `js`. `PAL` `js:116-120`
   restates the token block, and its `'v-accent'` is `#818cf8`, which is the CSS `--v-accent-fg`.
   `TONE_COLOR` `js:171`, fan tone `js:251`, `evColor` `js:639`, temperature ramps `js:2342-2346`,
   `2398`, `2417` (adds a one-off `#eab308`), thresholds `'#f87171'`/`'#f8717166'` `js:632, 1355,
   1566, 2439`, neutrals `'#666'` `js:1517`, `'#8b8f9a'` `js:162, 2214`, `'#9ca3af'` `js:171, 251`,
   axes `'#8889'/'#8882'/'#8886'` `js:515, 518, 546`, SVG tracks `rgba(127,127,127,.15)` `js:307,
   340`, inline `style:'color:#c00'` `js:2800`. → extend `PAL` keys to every status/neutral token
   (`v-ok`, `v-warn`, `v-bad`, `v-muted-stroke`, `v-axis`, `v-grid`), add those tokens to the CSS
   block, and make the ramps read `PAL`. Replace `#c00` with `.v-btn.xs.danger`.
   `SERIES_COLORS` (`js:129-132`, 20 hues) stays as a documented chart palette exception or
   moves to `--v-series-1..20`. — **Medium** — PR: one JS refactor with no visual change
   intended, except `#c00` → `--v-bad`.
9. **UV9 No visible focus on 5 interactive classes** — `.v-ibtn` (`css:83`), `.v-seg-btn`
   (`:218`), `.v-chip` (`:176`), `.v-chart-menu-btn` (`:540`), `.v-tag-badge` (`:826`) have no
   `:focus-visible` rule. Only `.v-btn`, `.v-select`, `.v-input` and `.v-tab` have one (`:106, 117, 677`).
   → one shared rule: `.vitals :is(.v-ibtn,.v-seg-btn,.v-chip,.v-chart-menu-btn,.v-tag-badge):focus-visible
   { outline: 2px solid var(--v-accent); outline-offset: 1px }`. — **Medium** — PR: 1 rule
   (pairs naturally with UV8 PR 1).
10. **UV10 Unlabeled controls and silent updates** — icon-only buttons with `title` but no
    `aria-label`: `js:804, 807, 811, 824, 827, 830, 834, 1079, 1083`, bell `js:1422`; modal close
    `js:2075` has neither; drawer close buttons `js:1161, 1447` are a bare `'✕'` glyph. Phone tabs hide their
    `<span>` label (`css:732`) with no `aria-label`. Tab strip has no `role="tablist"`/`aria-selected`
    (`js:1087-1092`). 29 `input/select/textarea` vs 4 `<label>` (`js:2249` is not tied to its input)
    and 0 input `aria-label`s, plus 7 placeholders acting as labels. `fa` icons have no `aria-hidden`.
    Toasts (`js:2665`) have no `role="status"`/`aria-live`. → add `aria-label` (reuse the title text),
    tab roles, `aria-hidden="true"` on decorative `<i>`, and `aria-live="polite"` on `.v-toasts`.
    — **Medium** — PR: attributes only, no visual change.

## Remaining items

- **UV7 Dashboard tile uses its own palette** — `tile:36, 39, 41, 42, 43, 44, 45` (7 hits: `#888`
  ×2, `#666`, `#b8860b` ×2, `#c0392b` ×2). `#666` is 2.99:1 on black/gray and `#888` is 3.17:1 on
  white. → keep the tile self-contained but declare `--vt-muted: var(--alt-text-color)`,
  `--vt-warn`, `--vt-crit` once at the top of its `<style>` (with theme-scoped values from UV3),
  and use them. — **Medium** — PR: tile-only.
- **UV11 Muted text through opacity fails contrast** — `.muted` .55 (`css:512`): 3.72 white, 2.03
  azure, 1.88 gray. `.v-foot` .45 (`:651`): 2.81–4.12, which fails everywhere. `th` .58 (`:591`),
  `.v-card-label` .6 (`:483`), `.v-sub`/`.v-tab` .62 (`:75, 664`) fail on azure/gray. Azure and
  gray also fail at full opacity, because Unraid's own `#606e7f` text gives 4.25 / 3.29. → a
  `--v-muted-opacity: .72` token (≥4.5 on black and white), use it for all text muting, and record
  azure/gray as a host limitation. — **Medium** — PR: token + 6 rules.
- **UV13 Type sizes bypass the scale** — 73 `font-size: <n>px` in `css` vs 21
  `var(--v-fs-*)` (13px×10, 11px×10, 11.5px×9, 12.5px×8, 12px×7, 10px×7, 10.5px×5, 9.5px×4, …), and
  8 inline `font-size` in `js` (`js:1452, 3119, 3164, 3678, 3696, 3722, 3743, 3748`). Px sizes also
  ignore Unraid's Display font-size setting. → map onto `--v-fs-xs/sm/md`, and add `--v-fs-2xs`
  (9.5–10px) for badges. — **Low** — PR: per section of css (split into 2–3 PRs).
- **UV14 Radius literals** — 6px ×7, 10px ×5, 3px ×5, 2px ×5, 8px ×3 (== `--v-radius-sm`), 4px ×3,
  9px, 7px. → `--v-radius-sm`, `--v-radius`, and new `--v-radius-xs: 4px` / `--v-radius-pill: 999px`. —
  **Low** — PR: mechanical.
- **UV15 No z-index scale** — `css:165` (200), `264` (9998), `268` (9999), `351` (2), `357` (3),
  `541` (4), `548` (9), `550` (10), `792` (1000). → `--v-z-chart-1..3`, `--v-z-popover`,
  `--v-z-modal`, `--v-z-toast`, `--v-z-drawer`, keeping today's order. — **Low** — PR: token +
  9 swaps.
- **UV16 Reduced motion covers 2 of 8 animations** — covered: `css:391` fan blades, `:457`
  card lift. Not covered: `v-pulse` (`:77`), `v-fan-stall-pulse` (`:365, 366, 821`),
  `v-drawer-in` (`:269`), `v-toast-in` (`:797`), therm hover lift (`:814`), bar width
  transitions (`:309, 507`). 10 distinct durations. → one reduced-motion block that sets
  `animation: none; transition: none` for these, plus `--v-dur-fast: .14s` and
  `--v-dur-enter: .2s` tokens. — **Low** — PR: 1 media block + tokens.
- **UV17 Duplicate components and dead code** — three red labels: `.v-badge-critical`
  (`css:294`), `.warn-badge` (`:644`), `.v-warnbadge` (`:690`); filter tags: `.v-chip` (`:176`)
  vs `.v-tag-badge` (`:826`); five tile families: `.v-sensor` (`:238`), `.v-gauge-tile` (`:252`),
  `.v-therm` (`:811`), `.v-core` (`:140`), `.v-fan` (`:362`); three segmented controls: `.v-seg`,
  `.v-iconbar`, `.v-tabs-mini` + `.v-btn.xs.active`; mixed state classes (`.on` vs `.active`);
  three monospace stacks (`css:128, 276, 599`). Dead code: `StatTile()` `js:172` (no caller, no
  CSS). Orphan CSS: `.v-legend*` (`css:560-565`), `.v-field` (`:227`), `.v-sensor-grid` (`:237`),
  `.v-stamp` (`:519`), `.v-fan-bar` (`:368-369`). Repeated rule heads: `.vitals` (`:9`, `:263`),
  `.v-chart` (`:346`, `:530`), `.v-span2` reset at 900px (`:417`) and 1023px (`:704`). → merge
  badges into `.v-badge` + modifiers, merge chip/tag-badge, pick `.on` for state, use one
  `--v-font-mono` token, delete the dead code. — **Low** — PR per family (badges; mono + dead code;
  state class rename).
- **UV18 Ad-hoc breakpoints** — documented tiers are 639/1023. Extras: `css:130` (860), `:404`
  (800), `:407` (1300), `:417` (900), `:759` (380). → fold 860/900 into 1023; keep 380 (cards)
  and 800/1300 (cards-4 column steps) as documented exceptions, or move them to container queries.
  — **Low** — PR: 2 media queries.
- **UV19 Inline styles in vitals.js** — 111 `style:` props (margin-top ×24, margin-bottom ×21,
  margin-left ×19, …). Spacing and layout one-offs bypass the stylesheet. → utility classes
  (`.v-mt-sm`, `.v-ml-auto`) or component classes, area by area. — **Low** — PR: per tab.
- **UV20 Header comment drift** — `css:5-7` says it is "Built on Unraid's own CSS variables (--text,
  --background, --primary-bg colour ramps)". The plugin references 0 non-`--v-*` variables. → fix the
  comment (or close it via UV1/UV3/UV7). — **Low** — PR: comment only.
- **UV21 RTL hardening** — LTR-only stance (DESIGN.md). 41 physical-direction declarations in
  `css` and 19 inline `margin-left` in `js`. Upstream webGui can emit `dir` on `<html>`
  (`$display['rtl']`). → set `dir="ltr"` on `#vitals-root` (`Vitals.page:19`) until strings are
  localised, and use logical properties in new CSS. — **Low** — PR: 1 attribute.

## Lint guard proposal (`scripts/check-design-tokens.sh`)

Fails on a hex / `rgb[a]()` / `hsl[a]()` literal or a `font-family`/`fontFamily` anywhere in the
plugin UI (`*.css`, `*.js`, `*.page`) except inside the `.vitals { }` token block of
`css/vitals.css`. The block is found dynamically: the first line that is exactly `.vitals {`
through the next line that is `}`, currently 9-48. Note that the token block is `.vitals`, not
`:root`, because the variables are scoped to the app root. Skips `js/vendor/*`, `uPlot.min.css`,
`agent/` (Node back end, no UI) and `node_modules`.

```bash
#!/usr/bin/env bash
# check-design-tokens.sh — fail on raw colour literals or font-family declarations
# outside the token block of css/vitals.css (the `.vitals { ... }` rule at the top).
# Usage: scripts/check-design-tokens.sh [plugin_dir]   (exit 1 when hits > 0)
set -euo pipefail
ROOT="${1:-src/usr/local/emhttp/plugins/unraid-vitals}"
CSS="$ROOT/css/vitals.css"
COLOR_RE='#[0-9a-fA-F]{3,8}\b|\b(rgba?|hsla?)\('
FONT_RE='font-family|fontFamily'
PATTERN="($COLOR_RE)|($FONT_RE)"

read -r TOK_START TOK_END < <(awk '
  /^\.vitals[[:space:]]*\{[[:space:]]*$/ && !s { s=NR; next }
  s && !e && /^\}[[:space:]]*$/ { e=NR }
  END { print (s?s:0), (e?e:0) }' "$CSS")
if [ "$TOK_START" -eq 0 ] || [ "$TOK_END" -eq 0 ]; then
  echo "check-design-tokens: token block '.vitals { ... }' not found in $CSS" >&2; exit 2
fi

hits=$(
  {
    awk -v s="$TOK_START" -v e="$TOK_END" -v f="$CSS" \
      'NR<s || NR>e { print f ":" NR ":" $0 }' "$CSS"
    find "$ROOT" -type f \( -name '*.css' -o -name '*.js' -o -name '*.page' \) \
      -not -path "$ROOT/js/vendor/*" -not -name 'uPlot.min.css' -not -path "$CSS" \
      -not -path '*/node_modules/*' -not -path "$ROOT/agent/*" -print0 \
      | xargs -0 -r grep -nH ''
  } | grep -E "$PATTERN" || true
)

if [ -z "$hits" ]; then
  echo "check-design-tokens: OK (0 hits; token block $CSS:$TOK_START-$TOK_END)"; exit 0
fi
lines=$(printf '%s\n' "$hits" | wc -l)
occ=$(printf '%s\n' "$hits" | cut -d: -f3- | grep -oE "$PATTERN" | wc -l)
printf '%s\n' "$hits" | cut -c1-200
echo "check-design-tokens: FAIL — $occ literal(s) on $lines line(s) outside $CSS:$TOK_START-$TOK_END" >&2
printf '%s\n' "$hits" | cut -d: -f1 | sort | uniq -c | sed 's/^/  per-file lines: /' >&2
exit 1
```

### Current result (run from the repo root, 2026-11-13, `31de94e`)

```
check-design-tokens: FAIL — 173 literal(s) on 111 line(s) outside src/usr/local/emhttp/plugins/unraid-vitals/css/vitals.css:9-48
  per-file lines:      72 src/usr/local/emhttp/plugins/unraid-vitals/css/vitals.css
  per-file lines:      32 src/usr/local/emhttp/plugins/unraid-vitals/js/vitals.js
  per-file lines:       7 src/usr/local/emhttp/plugins/unraid-vitals/Vitals.Dashboard.page
exit=1
```

**173 hits** (occurrences) on 111 lines: 145 hex + 24 `rgb[a](` + 4 `font-family`. By file:
`css` 95, `js` 71, `tile` 7.

Rollout (the build must stay green): add the script with a **baseline** first (`MAX_HITS=173`;
fail only when the count rises), wire it into `npm run check` as `lint:tokens`, and lower the
baseline in each UV5/UV6/UV7 PR until it reaches 0. Then drop the baseline. Expected allowlist
once clean: `SERIES_COLORS` (`js:129-132`) and `withAlpha()`'s `'rgba('` builder (`js:139`).
Either add a `// design-token-ok` line marker that the script skips, or move both behind
`resolvePalette()`. `font-family: inherit` (`css:284`) stays a hit until mono moves to a
`--v-font-mono` token, which is intentional.

## Not mismatches (checked, OK)

- Neutral surfaces/borders (`rgba(127,127,127,α)`) do adapt to both light and dark hosts, as intended.
- `resolvePalette()` (`js:121`, called `js:985`) already keeps the 11 metric colours in `PAL` synced
  from CSS, so changing a metric token in CSS updates the charts.
- `#1a1a1a` on amber (8.10) and on `--v-ok-fg` (9.99) pass.
- Touch devices get 36px targets (`css:764-768`), and tables pan instead of widening the page.
- `.v-btn`, `.v-select`, `.v-input` and `.v-tab` have a 2px accent `:focus-visible` ring.
- The chart `⋮` button has an `aria-label` (`js:720`), and the time-range group has `role="group"` +
  `aria-label` (`js:1073`).
