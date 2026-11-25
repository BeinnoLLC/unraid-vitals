---
version: alpha
name: unraid-vitals design system
description: Contributor reference for the Vitals UI tokens, status levels and known mismatches.
---

# Design system (contributor reference)

## Overview

How to read this file: it is a **descriptive companion** to the normative
[`DESIGN.md`](../DESIGN.md). Values, usage examples and status thresholds below are taken from
the code at the current checkout, not proposed values. These sources were used:

| Source | What it gives |
|---|---|
| `DESIGN.md` (root) | Section order, hard rules, accessibility notes and the UV/Plane mismatch table |
| `docs/design-audit.md` | Read-only mismatch audit of main @ `31de94e` (2026-11-13), with UV → `UNRAIDPLUG-*` IDs |
| `css/vitals.css` | The token block (`css/vitals.css:10-188`) and light-theme overrides (`:193-203`) |
| `js/vitals.js` | `tok()` / `pickColor()` (`:118-132`), `lvl()` (`:47`, `:2377-2381`), `heat()` (`:2419-2424`) |
| `include/store.php` | Alert-engine thresholds and severity strings (`:67-74`, `:651-821`) |

Paths such as `css/vitals.css` and `js/vitals.js` are relative to
`src/usr/local/emhttp/plugins/unraid-vitals/`. Where the root spec or the audit quotes older
line numbers or an older palette helper, this file follows the code in this checkout; it does
not claim Plane ticket state (Plane is the source of truth for that).

## Shared Beinno basics

- The app root is `<div id="vitals-root" class="vitals">`; all tokens are scoped to `.vitals`,
  never `:root`. Text colour and surface come from the active Unraid webGui theme.
- No Beinno logo: the header uses `fa fa-heartbeat v-logo` (`js/vitals.js:1127`). Do not generate
  one. Icon glyphs come from the webGui's FontAwesome 4 (`fa fa-*`); the plugin loads no icon font.
- Family basics not repeated here: no promo video in the plugin UI, no new brand surface on top
  of the Unraid theme, LTR stance for all hard-coded English strings.
- Vendor assets (`js/vendor/*`, `css/uPlot.min.css`) are exempt from the token rules.
- Gates before a PR: `npm run check` (includes `npm run lint:tokens`) and `npm run build`. See
  `scripts/check-design-tokens.sh` for the current literal baseline (`MAX_HITS=19`).

## Colors

The app's UI colours live in the `.vitals { }` token block (`css/vitals.css:10-188`). JavaScript
resolves them at runtime: `tok(name)` reads the computed property from `.vitals` (`js/vitals.js:122-129`,
`getComputedStyle` at `:126`, `getPropertyValue` at `:128`),
and `pickColor(i)` resolves `tok('v-series-' + (i % 20 + 1))` (`:131-132`). There is no defined
second palette object; five stale `PAL` references remain in hardware charts (see HARD RULES #2).
The tables below are the complete `--v-*` inventory; the right column is a **real** call site or
declaration in this checkout. The separate Dashboard tile palette (`--vt-*`) is UV7 /
UNRAIDPLUG-211, and shadows remain literal (see Elevation & Depth).

### Identity, status and filled surfaces

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-accent` | `#6366f1` | Accent: focus ring, hover border, active tab underline, default chart stroke | `css/vitals.css:225`: `outline: 2px solid var(--v-accent); outline-offset: 1px;` |
| `--v-accent-fg` | `#818cf8` | Accent text/icon on tinted surfaces | `css/vitals.css:248`: `.v-logo { … color: var(--v-accent-fg); }` |
| `--v-ok` | `#22c55e` | OK surface colour (thermal tile, success toast edge, run pill) | `css/vitals.css:973`: `.v-toast-success { border-left-color: var(--v-ok); }` |
| `--v-ok-fg` | `#4ade80` | OK text on dark themes | `css/vitals.css:699`: `.vitals .ok { color: var(--v-ok-fg); }` |
| `--v-warn` | `#f59e0b` | Warning fill/border, thermal warn band | `js/vitals.js:2422`: `if (v >= 60) return tok('v-warn');` |
| `--v-warn-fg` | `#fbbf24` | Warning text on dark themes | `css/vitals.css:700`: `.vitals .warn { color: var(--v-warn-fg); }` |
| `--v-bad` | `#ef4444` | Critical border/fill, thermal crit band | `css/vitals.css:294`: `.v-btn.danger { border-color: var(--v-bad); color: var(--v-bad); }` |
| `--v-bad-fg` | `#f87171` | Critical text and chart threshold line | `css/vitals.css:547`: `.v-th-line { … border-top: 1px dashed var(--v-bad-fg); … }` |
| `--v-on-accent` | `#fff` | Text/icon on accent, bad and info fills | `css/vitals.css:267`: `.v-ibtn.on { background: var(--v-accent-fill); color: var(--v-on-accent); }` |
| `--v-on-warn` | `#1a1a1a` | Dark text on warning and OK fills | `css/vitals.css:369`: `.v-chip-sev-high.on { background: var(--v-warn); … color: var(--v-on-warn); }` |
| `--v-accent-fill` | `#4f46e5` | Filled primary controls | `css/vitals.css:267`: `.v-ibtn.on { background: var(--v-accent-fill); … }` |
| `--v-bad-fill` | `#dc2626` | Filled critical chip/badge | `css/vitals.css:368`: `.v-chip-sev-critical.on { background: var(--v-bad-fill); … }` |
| `--v-info-fill` | `#2563eb` | Filled info chip | `css/vitals.css:370`: `.v-chip-sev-medium.on { background: var(--v-info-fill); … }` |
| `--v-ok-fill` | `#4ade80` | Filled low-severity chip (dark text) | `css/vitals.css:371`: `.v-chip-sev-low.on { background: var(--v-ok-fill); … }` |

### Metric and state hues

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-cpu` | `#4f9cf9` | CPU identity, bars and history line | `js/vitals.js:1281`: `{ name: 'CPU %', color: tok('v-cpu'), … }`; `css/vitals.css:697`: `.v-bar > i { … background: var(--v-cpu); }` |
| `--v-mem` | `#b07cf9` | Memory/storage identity | `js/vitals.js:1282`: `{ name: 'Memory %', color: tok('v-mem'), … }` |
| `--v-load` | `#f9a94f` | Load-average identity | `js/vitals.js:1283`: `{ name: 'Load (norm)', color: tok('v-load'), … }` |
| `--v-rx` | `#35c48a` | Network receive direction | `js/vitals.js:1297`: `{ name: 'RX', color: tok('v-rx'), … }` |
| `--v-tx` | `#f97066` | Network transmit direction | `js/vitals.js:1298`: `{ name: 'TX', color: tok('v-tx'), … }` |
| `--v-temp` | `#f97066` | Temperature identity | `js/vitals.js:1337`: `{ label: 'Hottest disk', … color: tok('v-temp'), … }` |
| `--v-gpu` | `#22d3ee` | GPU identity | `js/vitals.js:2284`: `color: tok('v-gpu'), value: pw.gpu_watts.toFixed(1), unit: 'W'` |
| `--v-info` | `#4f9cf9` | Informational markers and chips | `css/vitals.css:553`: `.v-ev-marker { … border-left: 1.5px dashed var(--v-info); … }` |
| `--v-think` | `#2dd4bf` | Model "thinking" capability pill | `css/vitals.css:808`: `background: color-mix(in srgb, var(--v-think) 15%, transparent);` |

### Chart support colours

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-info-fg` | `#60a5fa` | Info text in event colours | `js/vitals.js:636`: `info: tok('v-info-fg')` |
| `--v-warm` | `#eab308` | Thermal 45–60 band | `js/vitals.js:2423`: `if (v >= 45) return tok('v-warm');` |
| `--v-cool` | `#34d399` | Cool thermal band, healthy radar legend | `js/vitals.js:2424`: `return tok('v-cool');` |
| `--v-dim` | `#666` | Dim/no-data line colour | `js/vitals.js:1591`: `color: cur == null ? tok('v-dim') : …` |
| `--v-muted-fg` | `#9ca3af` | Muted spinner stroke | `js/vitals.js:239`: `spinning ? tok('v-info') : tok('v-muted-fg')` |
| `--v-muted-stroke` | `#8b8f9a` | Fallback stroke when a series colour is not a valid hex | `js/vitals.js:162`: `var safeHex = … ? hex : tok('v-muted-stroke');` |
| `--v-axis` | `#8889` | Primary uPlot axis stroke | `js/vitals.js:512`: `{ stroke: tok('v-axis'), grid: { stroke: tok('v-grid'), width: 1 }, … }` |
| `--v-axis-2` | `#8886` | Secondary uPlot axis stroke | `js/vitals.js:543`: `opts.axes.push({ stroke: tok('v-axis-2'), … });` |
| `--v-grid` | `#8882` | uPlot grid stroke | `js/vitals.js:512`: (same axis options object as above) |
| `--v-track` | `rgba(127, 127, 127, .15)` | Gauge background track | `js/vitals.js:295`: `stroke: tok('v-track'), 'stroke-width': th` |

### Series ramp

Declared at `css/vitals.css:67-86`; consumed by `pickColor(i)` (`js/vitals.js:131-132`), so the
"Real usage" column names the call that selects that exact slot.

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-series-1` | `#4f9cf9` | Series slot 1 | `pickColor(0)` → `tok('v-series-1')` |
| `--v-series-2` | `#b07cf9` | Series slot 2 | `pickColor(1)` → `tok('v-series-2')` |
| `--v-series-3` | `#35c48a` | Series slot 3 | `pickColor(2)` → `tok('v-series-3')` |
| `--v-series-4` | `#f97066` | Series slot 4 | `pickColor(3)` → `tok('v-series-4')` |
| `--v-series-5` | `#22d3ee` | Series slot 5 | `pickColor(4)` → `tok('v-series-5')` |
| `--v-series-6` | `#a78bfa` | Series slot 6 | `pickColor(5)` → `tok('v-series-6')` |
| `--v-series-7` | `#f472b6` | Series slot 7 | `pickColor(6)` → `tok('v-series-7')` |
| `--v-series-8` | `#fbbf24` | Series slot 8 | `pickColor(7)` → `tok('v-series-8')` |
| `--v-series-9` | `#34d399` | Series slot 9 | `pickColor(8)` → `tok('v-series-9')` |
| `--v-series-10` | `#60a5fa` | Series slot 10 | `pickColor(9)` → `tok('v-series-10')` |
| `--v-series-11` | `#fb7185` | Series slot 11 | `pickColor(10)` → `tok('v-series-11')` |
| `--v-series-12` | `#facc15` | Series slot 12 | `pickColor(11)` → `tok('v-series-12')` |
| `--v-series-13` | `#4ade80` | Series slot 13 | `pickColor(12)` → `tok('v-series-13')` |
| `--v-series-14` | `#38bdf8` | Series slot 14 | `pickColor(13)` → `tok('v-series-14')` |
| `--v-series-15` | `#e879f9` | Series slot 15 | `pickColor(14)` → `tok('v-series-15')` |
| `--v-series-16` | `#c4b5fd` | Series slot 16 | `pickColor(15)` → `tok('v-series-16')` |
| `--v-series-17` | `#fdba74` | Series slot 17 | `pickColor(16)` → `tok('v-series-17')` |
| `--v-series-18` | `#67e8f9` | Series slot 18 | `pickColor(17)` → `tok('v-series-18')` |
| `--v-series-19` | `#86efac` | Series slot 19 | `pickColor(18)` → `tok('v-series-19')` |
| `--v-series-20` | `#fca5a5` | Series slot 20 | `pickColor(19)` → `tok('v-series-20')` |

### Surfaces and text

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-surface` | `var(--background-color, #1d1b1b)` | Opaque overlay surface (modal, drawer, toast, chart menu) | `css/vitals.css:350`: `.v-modal { background: var(--v-surface); border: 1px solid var(--v-border); … }` |
| `--v-neutral` | `rgb(127, 127, 127)` | Neutral badge tint | `css/vitals.css:488`: `background: color-mix(in srgb, var(--v-neutral) 25%, transparent); color: var(--v-fg);` |
| `--v-border` | `rgba(127, 127, 127, .18)` | Panel, card and control borders | `css/vitals.css:236`: `.v-hero { … border: 1px solid var(--v-border); … }` |
| `--v-card` | `rgba(127, 127, 127, .045)` | Translucent panel/card fill (never overlays) | `css/vitals.css:236`: `.v-hero { background: var(--v-card); … }` |
| `--v-card-hover` | `rgba(127, 127, 127, .085)` | Hover fill for compact controls | `css/vitals.css:266`: `.v-ibtn:hover { background: var(--v-card-hover); color: inherit; }` |
| `--v-fg` | `currentColor` | Explicit foreground override | `css/vitals.css:488`: `color: var(--v-fg);` (one older fallback form remains at `:384`: `var(--v-fg, inherit)`) |
| `--v-muted` | `currentColor` | Muted text base; muted via opacity, not a second grey | `css/vitals.css:261`: `.v-ibtn { … color: var(--v-muted); }` |
| `--v-muted-opacity` | `.72` | Opacity for muted labels and subtext | `css/vitals.css:252`: `.v-sub { … opacity: var(--v-muted-opacity); … }` |

### Status levels from the code

Visual classes map to tokens at `css/vitals.css:699-701`: `.ok { color: var(--v-ok-fg); }`,
`.warn { color: var(--v-warn-fg); }`, `.crit { color: var(--v-bad-fg); }`. The thermal classes use
the fill hues: `.v-therm-ok` → `var(--v-ok)`, `.v-therm-warn` → `var(--v-warn)`,
`.v-therm-crit` → `var(--v-bad)` (`css/vitals.css:991-997`). "bad" in this guide means the
`crit`/`v-bad` visual level — there is no `.bad` CSS class.

The generic comparator is inclusive and ordered (`js/vitals.js:47`):

```js
function lvl(v, w, c) { return v == null ? '' : (v >= c ? 'crit' : v >= w ? 'warn' : 'ok'); }
```

| Call site (`js/vitals.js`) | Entity | warn ≥ | crit ≥ |
|---|---|---:|---:|
| `:760` | container CPU % | 150 | 300 |
| `:917` | process CPU % | 50 | 100 |
| `:950`, `:978` | SMART / NVMe disk temperature °C | 45 | 55 |
| `:957` | disk wear % | 80 | 90 |
| `:981`, `:1442`, `:1630`, `:1632` | filesystem/array fill % | 85 | 95 |
| `:1324` | dashboard CPU % | 80 | 95 |
| `:1329` | dashboard memory % | 80 | 92 |
| `:1339` | hottest disk °C | `alertTemp - 10` | `alertTemp` |
| `:1443` | Pools/cache temperature °C | 45 | 55 |
| `:1737` | NVMe `temp_max` °C | 50 | 58 |
| `:1741` | NVMe `fill_max` % | 85 | 95 |
| `:2000` | CPU core % | 70 | 90 |

`alertTemp` is `parseFloat(settings.ALERT_TEMP) || 55` (`js/vitals.js:1259`); `ALERT_FILL` and
`ALERT_LOAD` default to `90` in the same block, while the PHP defaults are temp 55, fill 90,
load `0` (off) and restarts 3 (`include/store.php:67-74`).

Sensor and thermal views use their own bands, not the generic table (all in `js/vitals.js`):

- `lvl(t)` at `:2377-2381`: chip `crit` → `crit`, else chip `max` → `warn`, else fixed `75` →
  `crit`, `60` → `warn`, otherwise `ok`. Headroom uses the chip `crit` (or `max` if no `crit`)
  minus the reading (`js/vitals.js:2384-2388`).
- `heat(v, t)` at `:2419-2424`: within 92% of chip `crit`/`max`, or ≥75 → `v-bad`; ≥60 →
  `v-warn`; ≥45 → `v-warm`; else `v-cool`.
- Thermal-map class at `:2431`: chip `crit` or ≥75 → `v-therm-crit`; ≥60 → `v-therm-warn`;
  else `v-therm-ok`.
- Health-radar legend at `:2476`: proximity ≥85 → `v-bad`, ≥60 → `v-warn`, else `v-cool`.

Alert-engine severity is a **separate string taxonomy**, not a CSS class:
`include/store.php:67-74` defines thresholds, `:678-702` raises `warning` events for temp/fill/load,
`:756` maps chip `crit` → `alert` and chip `max` → `warning`, and `kb_events.severity` allows
`info|warning|alert|critical` (`:847`). Do not infer a colour class from a severity string without
checking the UI call site.

### Theme inheritance

Base values above are tuned for the dark themes. `css/vitals.css:193-203` overrides six tokens
for `html.Theme--white .vitals, html.Theme--azure .vitals`:

| Token | Dark base | White/azure override |
|---|---|---|
| `--v-ok-fg` | `#4ade80` | `#166534` |
| `--v-warn-fg` | `#fbbf24` | `#92400e` |
| `--v-bad-fg` | `#f87171` | `#b91c1c` |
| `--v-bad` | `#ef4444` | `#b91c1c` |
| `--v-accent-fg` | `#818cf8` | `#4f46e5` |
| `--v-bad-fill` | `#dc2626` | `#b91c1c` |

`--v-surface` follows `var(--background-color, #1d1b1b)`; the four theme backgrounds in the root
spec are black/gray `#1d1b1b`, white `#f2f2f2` and azure `#e8e8e8`. Re-check contrast on all four
themes before changing any status tint.

## Typography

The plugin sets no text family: `.vitals` inherits Unraid's `clear-sans, sans-serif`. Numbers use
`font-variant-numeric: tabular-nums`. Weights in use: 400/500/600, 650 (title, card value),
700 (panel headings, table headers) and 800 (fan-stall badge).

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-font-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | Only non-inherited family; logs and paths | `css/vitals.css:313`: `.v-storage-type-name { font-family: var(--v-font-mono); opacity: .8; }` |
| `--v-fs-2xs` | `10px` | Smallest labels/badges | `css/vitals.css:268`: `.v-ibtn .v-badge { … font-size: var(--v-fs-2xs); }` |
| `--v-fs-xs` | `clamp(10.5px, .5vw + 9px, 11.5px)` | Subtext, chips, table metadata | `css/vitals.css:252`: `.v-sub { … font-size: var(--v-fs-xs); … }` |
| `--v-fs-sm` | `clamp(12px, .55vw + 10px, 13px)` | Buttons, inputs, body-small | `css/vitals.css:279`: `.v-btn { … font-size: var(--v-fs-sm); }` |
| `--v-fs-md` | `clamp(13px, .6vw + 11px, 14.5px)` | Base text | `css/vitals.css:186`: `.vitals { … font-size: var(--v-fs-md); … }` |
| `--v-fs-lg` | `clamp(16px, 1vw + 12px, 19px)` | Logo and large tile values | `css/vitals.css:247`: `.v-logo { … font-size: var(--v-fs-lg); }` |
| `--v-fs-xl` | `clamp(20px, 1.6vw + 14px, 26px)` | Page title | `css/vitals.css:244`: `.v-title h2 { … font-size: var(--v-fs-xl); … }` |

The front matter in `DESIGN.md` records only the `clamp()` ceilings; the exact expressions above
are in `css/vitals.css:178-183`. Phones pin `.vitals` to `13px` at `css/vitals.css:901`.

## Layout

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-gap` | `16px` | Only spacing token; `12px` on phones | `css/vitals.css:235`: `.v-hero { margin-bottom: var(--v-gap); }`; phone override at `:901` |

Current media rules are `min-width: 800px`, `min-width: 1300px`, `max-width: 1023px`,
`max-width: 639px`, `max-width: 380px`, `(hover: none)` and `(prefers-reduced-motion: reduce)`
(`css/vitals.css:596,600,875,889,900,934,939,1026`). The root spec also mentions 860px and
900px breakpoints; those rules are not in the current CSS.

Grids and tables: `.v-cards` and `.v-grid` are `repeat(auto-fit, minmax(…, 1fr))`; tables live in
`.v-scroll` (horizontal scroll + capped height) on narrow screens. Tabs wrap on desktop, scroll
horizontally below 1024px and go icon-only on phones. CSS uses physical directions and all UI
strings are English; future RTL work is tracked as `UNRAIDPLUG-221`.

## Elevation & Depth

Panels and cards rest flat (border + `--v-card` fill). Current shadows are literals, not tokens:
`css/vitals.css:646` `0 10px 24px rgba(0, 0, 0, .16)`, `:741` `0 12px 28px rgba(0,0,0,.35)`,
`:972` `0 8px 24px rgba(0,0,0,.35)` and `:464` `-18px 0 42px rgba(0,0,0,.5)`. Do not add new
ones; the token-lint baseline tolerates the existing set.

z-index is tokenised at `css/vitals.css:127-135`:

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-z-chart-1` | `2` | Threshold line over plot | `css/vitals.css:547`: `.v-th-line { … z-index: var(--v-z-chart-1); }` |
| `--v-z-chart-2` | `3` | Event marker | `css/vitals.css:553`: `.v-ev-marker { … z-index: var(--v-z-chart-2); }` |
| `--v-z-chart-3` | `4` | Chart `⋮` button | `css/vitals.css:730`: `.v-chart-menu-btn { … z-index: var(--v-z-chart-3); }` |
| `--v-z-chart-4` | `9` | Chart-menu backdrop | `css/vitals.css:737`: `.v-chart-menu-backdrop { … z-index: var(--v-z-chart-4); }` |
| `--v-z-popover` | `10` | Chart menu/popover | `css/vitals.css:739`: `.v-chart-menu { … z-index: var(--v-z-popover); }` |
| `--v-z-modal` | `200` | Modal backdrop | `css/vitals.css:349`: `.v-modal-backdrop { … z-index: var(--v-z-modal); }` |
| `--v-z-toast` | `1000` | Toast stack | `css/vitals.css:967`: `.v-toasts { … z-index: var(--v-z-toast); }` |
| `--v-z-drawer-backdrop` | `9998` | Drawer backdrop | `css/vitals.css:460`: `.v-drawer-backdrop { … z-index: var(--v-z-drawer-backdrop); }` |
| `--v-z-drawer` | `9999` | Right-side drawer | `css/vitals.css:464`: `.v-drawer { … z-index: var(--v-z-drawer); }` |

Keep the order: chart overlays < chart menu < modal < toast < drawer.

## Shapes

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-radius` | `12px` (`10px` on phones) | Panels, hero, cards, modal, toast | `css/vitals.css:236`: `.v-hero { … border-radius: var(--v-radius); }` |
| `--v-radius-sm` | `8px` | Buttons, inputs, icon bar, chart menu | `css/vitals.css:259`: `.v-iconbar { … border-radius: var(--v-radius-sm); … }` |
| `--v-radius-xs` | `4px` | Small bars and inline code | `css/vitals.css:390`: `.v-kb-doc-body … code { … border-radius: var(--v-radius-xs); … }` |
| `--v-radius-pill` | `999px` | Chips, pills, dots, rings | `css/vitals.css:360`: `.v-chip { … border-radius: var(--v-radius-pill); … }` |

Circular hardware dots also use `--v-radius-pill` in the current CSS (e.g.
`css/vitals.css:554,572,1021`); do not reintroduce `border-radius: 50%`.

## Components

- **Header:** `.v-hero` + `.v-hero-row` carries `fa-heartbeat`, title, `.v-sub` status line, a
  `.v-seg` time-range group and `.v-iconbar` actions; `.v-tabs` is inside the same hero
  (`js/vitals.js:1124-1159`). The hero is the navigation surface, not a banner above it.
- **Panels/cards:** `.v-panel` with `.v-panel h3` headings, `.v-cards` stat tiles and flat borders.
  Card icons use `.v-card-icon-wrap`/`.v-card-icon-ring` (`css/vitals.css:652-670`).
- **Controls:** `.v-btn`, `.v-btn.primary`, `.v-btn.danger`, `.v-select`, `.v-input`, `.v-seg-btn`,
  `.v-ibtn`, `.v-chip`, `.v-tab`. Active/focus states use `--v-accent` / `--v-accent-fill`.
- **Overlays:** `.v-modal`, `.v-drawer`, `.v-toast` and `.v-chart-menu` all use opaque
  `--v-surface` with `--v-border` (UV1/UV2, `UNRAIDPLUG-207`/`UNRAIDPLUG-201`).
- **Severity chips:** `.v-chip-sev-critical|high|medium|low` in `css/vitals.css:364-371`; the `.on`
  variants are the filled surfaces listed above. Pill classes `.v-pill.run|warn|think` are at
  `:796-809`.
- **Dialogs:** `useDialog()` (`js/vitals.js:779-815`) supplies Escape, focus entry/trap and focus
  return; the log drawer, findings drawer and modal use `role="dialog"` + `aria-modal="true"`
  (`:1224`, `:1515`, `:2147`).

## Do's and Don'ts

- **Do** add every new colour to the token block and read it with `var(--v-*)` in CSS or `tok()`
  in JS. **Don't** add a JS palette or a second source of colour truth (UV6/UNRAIDPLUG-208).
- **Do** keep overlays opaque via `--v-surface`. **Don't** give them `--v-card` translucency.
- **Do** use `--v-fs-*`, `--v-radius*`, `--v-z-*`, `--v-gap` and `--v-dur-fast` for new work.
  **Don't** introduce new families, radii, breakpoints or z-index numbers.
- **Do** pair status colour with text/icon and check all four themes. **Don't** rely on colour
  alone.
- **Do** keep clickable things `<button>`/`<a href>` with visible `:focus-visible`, `aria-label`
  on icon-only controls, and `prefers-reduced-motion` opt-outs. **Don't** add click handlers to
  bare `div`/`span`.
- **Do** keep Vitals LTR and English for now. **Don't** add Arabic strings or a generated logo;
  RTL work is UNRAIDPLUG-221.
- **Don't** edit vendored files, and **don't** add new raw hex/`rgb()`/`hsl()`/`font-family`
  literals outside the token block and theme overrides.

## Motion

| Token | Value | Purpose | Real usage |
|---|---|---|---|
| `--v-dur-fast` | `.14s` | Hover/colour/transform transitions | `css/vitals.css:263`: `transition: background var(--v-dur-fast) ease, color var(--v-dur-fast) ease;` |
| `--v-dur-enter` | `.2s` | Enter duration, defined for future use | `css/vitals.css:139`: `--v-dur-enter: .2s;` — no current `var(--v-dur-enter)` call was found; existing animations use literals (`.22s` at `:972`, `.18s` at `:465`, `.6s` at `:659`) |

`css/vitals.css:1026` has a `prefers-reduced-motion: reduce` block with 11 opt-out rules
(`:1027-1037`): seven `animation: none`, two `transform: none` and two `transition: none`.
Reuse it for any new motion; do not add a new duration literal.

## Accessibility

- The contrast table in root `DESIGN.md` is a 2026-11-14 measurement, including real AA failures
  on filled surfaces and light themes. It is a snapshot, not a claim that current code passes;
  this document did not re-run a browser contrast harness.
- The light-theme overrides in `Colors > Theme inheritance` are the current mitigation for status
  text. Check new text against black, white, azure and gray before merging.
- Status never relies on colour alone: pair it with a label, count, icon or value (e.g. findings
  badge count, `.v-pill` text, gauge number).
- Visible focus rings now cover `.v-btn`, `.v-select`, `.v-input`, `.v-tab` and the shared selector
  `.v-ibtn, .v-seg-btn, .v-chip, .v-chart-menu-btn, .v-tag-badge, .v-price-input`
  (`css/vitals.css:215-227,283,289-291,302,855`). Dialog behaviour is in `useDialog()` (`js/vitals.js:779-815`).
- Keep icon-only buttons labelled and decorative `fa` icons `aria-hidden="true"`; see the header
  markup at `js/vitals.js:1127-1159`. The root spec's old "no Escape handling" note is stale.

## HARD RULES

These are the root `DESIGN.md` rules, annotated with what the code actually does here:

1. No hex/`rgb()`/`hsl()` literal and no `font-family` in `css/vitals.css` outside the token block
   (now `:10-188`) and theme overrides (`:193-203`), or in `js/vitals.js` / `*.page`; vendor files
   are exempt. `scripts/check-design-tokens.sh` is a ratchet (`MAX_HITS=19`, not the stale "170"
   quoted in the root spec).
2. Canvas/SVG colours come from `tok()` / `pickColor()` (`js/vitals.js:118-132`), not a literal
   palette. `SERIES_COLORS` survives only in a CSS comment (`css/vitals.css:65`), but five hardware
   charts still reference an **undefined** `PAL` (`js/vitals.js:2405,2507,2572,2577,2602`; the
   token-name list `PAL_T` is at `:2375`). That drift has no ID in the audit — do not copy those
   calls; no hex at new call sites.
3. Every `var(--v-*)` used must be defined in the token block. No **new** fallbacks: two existing
   forms are `var(--background-color, #1d1b1b)` inside the token block and `var(--v-fg, inherit)`
   at `css/vitals.css:384`.
4. Overlays (modal, drawer, toast, popover) use the opaque `--v-surface`, never `--v-card`.
5. Every clickable element is a `<button>`/`<a href>` with a visible `:focus-visible` ring.
6. Icon-only buttons have `aria-label`; dialogs/drawers have `role="dialog"`, `aria-modal`, Escape,
   focus entry/trap and focus return (`useDialog()`, `js/vitals.js:779-815`).
7. New font sizes use `--v-fs-*`; new radii use `--v-radius`, `--v-radius-sm` or `--v-radius-pill`.
8. No **new** breakpoints beyond the current set: 639/640 and 1023/1024 tiers plus 380, 800 and
   1300 (`css/vitals.css:596,600,875,889,900,934`).
9. Every animation has a `prefers-reduced-motion: reduce` opt-out (`css/vitals.css:1026`).
10. Check text contrast against black, white, azure and gray before merging.

## Known mismatches

Transcribed from `DESIGN.md` § Known mismatches and `docs/design-audit.md` (snapshot @ `31de94e`).
The UV labels and Plane IDs agree across both sources. Audit file:line numbers describe the older
snapshot; several items are visibly implemented in this checkout (e.g. `--v-surface`, `tok()`,
`useDialog()`, tokenised radii/z-index and the reduced-motion block), but this document does not
verify Plane status. The audit marks UV1 fixed on the single branch `fix/uv1-overlay-surface`;
UV2 is marked fixed and rode with UV1 (no separate branch).

| UV | Plane ticket | Mismatch (audit label) |
|---|---|---|
| UV1 | UNRAIDPLUG-207 | Overlay surfaces hard-wired dark — audit: fixed on `fix/uv1-overlay-surface` |
| UV2 | UNRAIDPLUG-201 | Floating surfaces translucent — audit: fixed, rode with UV1 |
| UV3 | UNRAIDPLUG-202 | Status/accent text fails on light themes |
| UV4 | UNRAIDPLUG-204 | Undefined custom properties |
| UV5 | UNRAIDPLUG-205 | Raw status hex and wrong fallbacks in CSS |
| UV6 | UNRAIDPLUG-208 | Duplicate JS palette |
| UV7 | UNRAIDPLUG-211 | Dashboard tile uses its own palette |
| UV8 | UNRAIDPLUG-203 | Keyboard/dialog semantics missing |
| UV9 | UNRAIDPLUG-209 | Missing focus rings on interactive classes |
| UV10 | UNRAIDPLUG-210 | Unlabeled controls and silent updates |
| UV11 | UNRAIDPLUG-212 | Opacity-muted contrast |
| UV12 | UNRAIDPLUG-206 | Filled status surfaces below AA |
| UV13 | UNRAIDPLUG-213 | Type sizes bypass the scale |
| UV14 | UNRAIDPLUG-214 | Radius literals |
| UV15 | UNRAIDPLUG-215 | No z-index scale |
| UV16 | UNRAIDPLUG-216 | Reduced motion covers only some animations |
| UV17 | UNRAIDPLUG-217 | Duplicate components and dead code |
| UV18 | UNRAIDPLUG-218 | Ad-hoc breakpoints |
| UV19 | UNRAIDPLUG-219 | Inline styles in `vitals.js` |
| UV20 | UNRAIDPLUG-220 | Header comment drift |
| UV21 | UNRAIDPLUG-221 | RTL hardening |
