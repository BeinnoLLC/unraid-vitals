---
version: alpha
name: unraid-vitals
description: Unraid plugin that adds a "Vitals" nav tab (Preact + uPlot monitoring app) and a compact Stock-Dashboard tile. One accent/status palette on `.vitals`, translucent neutral surfaces, and text colour inherited from the active Unraid webGui theme (black / white / azure / gray).
colors:
  # Source: src/usr/local/emhttp/plugins/unraid-vitals/css/vitals.css:9-48 (the `.vitals { }`
  # token block — scoped to the app root, NOT :root). Key == CSS custom property without `--`.
  # There are no -dark siblings: the plugin ships ONE palette for every Unraid theme
  # (see "Colors > Theme inheritance" — the -fg tints are dark-theme tuned).
  v-accent: "#6366f1"
  v-accent-fg: "#818cf8"
  v-ok: "#22c55e"
  v-ok-fg: "#4ade80"
  v-warn: "#f59e0b"
  v-warn-fg: "#fbbf24"
  v-bad: "#ef4444"
  v-bad-fg: "#f87171"
  # Metric / series identity colours (also mirrored in vitals.js PAL, line 116)
  v-cpu: "#4f9cf9"
  v-mem: "#b07cf9"
  v-load: "#f9a94f"
  v-rx: "#35c48a"
  v-tx: "#f97066"
  v-temp: "#f97066"
  v-gpu: "#22d3ee"
  # Neutral surfaces: mid-grey at low alpha, so they tint whatever Unraid background sits behind.
  v-border: "rgba(127, 127, 127, .18)"
  v-card: "rgba(127, 127, 127, .045)"
  v-card-hover: "rgba(127, 127, 127, .085)"
  # --v-muted is `currentColor` (inherits the Unraid theme text colour; muting is done with opacity),
  # so it is documented in prose, not as a colour token.
  # Fixed contrast colours used on filled status surfaces (raw literals today, see docs/design-audit.md UV5).
  on-accent: "#ffffff"
  on-warn: "#1a1a1a"
  # Unraid webGui theme references (NOT defined by the plugin; html.Theme--<name> :root vars
  # from unraid/webgui (formerly limetech/webgui) emhttp/plugins/dynamix/styles/themes/*.css,
  # upstream master 2026-11-13).
  unraid-black-background: "#1d1b1b"
  unraid-black-text: "#f2f2f2"
  unraid-white-background: "#f2f2f2"
  unraid-white-text: "#1d1b1b"
  unraid-azure-background: "#e8e8e8"
  unraid-azure-text: "#606e7f"
  unraid-gray-background: "#1d1b1b"
  unraid-gray-text: "#606e7f"
typography:
  # fontSize = the clamp() ceiling (value at wide viewports); the exact clamp is in each comment.
  # Font family is NOT set by the plugin: it inherits Unraid's `html { font-family: clear-sans, sans-serif }`
  # (dynamix/styles/default-base.css). Sizes come from the fluid clamp() scale on `.vitals`.
  v-fs-xs:
    fontSize: 11.5px  # code: clamp(10.5px, .5vw + 9px, 11.5px)
  v-fs-sm:
    fontSize: 13px  # code: clamp(12px, .55vw + 10px, 13px)
  v-fs-md:
    fontSize: 14.5px  # code: clamp(13px, .6vw + 11px, 14.5px)
    lineHeight: 1.5
  v-fs-lg:
    fontSize: 19px  # code: clamp(16px, 1vw + 12px, 19px)
  v-fs-xl:
    fontSize: 26px  # code: clamp(20px, 1.6vw + 14px, 26px)
    fontWeight: 650
    lineHeight: 1.15
    letterSpacing: "-.02em"
  panel-title:
    fontSize: "{typography.v-fs-xs.fontSize}"
    fontWeight: 700
    letterSpacing: ".07em"
  card-value:
    fontSize: 21px  # code: clamp(17px, 1.7vw, 21px)
    fontWeight: 650
    lineHeight: 1.1
    letterSpacing: "-.01em"
  mono:
    fontFamily: "ui-monospace, Menlo, Consolas, monospace"
    fontSize: "{typography.v-fs-xs.fontSize}"
spacing:
  v-gap: 16px
  v-gap-phone: 12px
  panel-padding: "16px 18px"
  card-padding: "11px 13px"
rounded:
  v-radius: 12px
  v-radius-phone: 10px
  v-radius-sm: 8px
  pill: 999px
components:
  button-default:
    backgroundColor: "{colors.v-card}"
    rounded: "{rounded.v-radius-sm}"
    padding: "6px 12px"
    typography: "{typography.v-fs-sm}"
  button-default-hover:
    backgroundColor: "{colors.v-card-hover}"
  button-primary:
    backgroundColor: "{colors.v-accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.v-radius-sm}"
    padding: "6px 12px"
  button-danger:
    backgroundColor: "{colors.v-card}"
    textColor: "{colors.v-bad}"
    rounded: "{rounded.v-radius-sm}"
  input:
    backgroundColor: "{colors.v-card}"
    rounded: "{rounded.v-radius-sm}"
    padding: "5px 9px"
    typography: "{typography.v-fs-sm}"
  panel:
    backgroundColor: "{colors.v-card}"
    rounded: "{rounded.v-radius}"
    padding: "{spacing.panel-padding}"
  stat-card:
    backgroundColor: "{colors.v-card}"
    rounded: "{rounded.v-radius}"
    padding: "{spacing.card-padding}"
  stat-card-hover:
    backgroundColor: "{colors.v-card-hover}"
  tab-active:
    textColor: "{colors.v-accent-fg}"
    backgroundColor: "{colors.v-card}"
  pill:
    backgroundColor: "{colors.v-card}"
    rounded: "{rounded.pill}"
    padding: "2px 9px"
    typography: "{typography.v-fs-xs}"
  pill-run:
    textColor: "{colors.v-ok-fg}"
  pill-warn:
    textColor: "{colors.v-warn-fg}"
  badge-critical:
    backgroundColor: "{colors.v-bad}"
    textColor: "{colors.on-accent}"
    rounded: 9px
  badge-warning:
    backgroundColor: "{colors.v-warn}"
    textColor: "{colors.on-warn}"
    rounded: 9px
  chip-on:
    backgroundColor: "{colors.v-accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.pill}"
---

# unraid-vitals design system

## Overview

unraid-vitals is an Unraid OS plugin for home-lab and small-server owners: a "Vitals" top-nav
tab (`Vitals.page`, `Menu="Tasks:65"`) with a 12-tab monitoring app (Dashboard, Array & Disks,
Docker, Network, System, Shares, Hardware, Power, Knowledge, Research, Cleanup, Settings), plus a
5-number tile on Unraid's stock Dashboard (`Vitals.Dashboard.page`). Tone: dense, calm,
Grafana/Homepage-style ops UI. Colour carries meaning (status, metric identity), not decoration.

Stack (all paths relative to `src/usr/local/emhttp/plugins/unraid-vitals/`):

| Concern | Where | Notes |
|---|---|---|
| Mount + assets | `Vitals.page` | `<div id="vitals-root" class="vitals">`; loads `css/uPlot.min.css`, `css/vitals.css`, `js/vendor/{preact.min.js, preact-hooks.umd.min.js, uPlot.iife.min.js}`, `js/vitals.js` |
| Tokens | `css/vitals.css:9-48` | the `.vitals { }` rule. This is the only token block. |
| Components + styles | `css/vitals.css` (849 lines), `js/vitals.js` (3770 lines, Preact `h()` without JSX) | BEM-ish `v-*` classes, all scoped under `.vitals` |
| Dashboard tile | `Vitals.Dashboard.page` | self-contained: own inline `<style>` (`vt-*` classes) and vanilla JS. It does not load vitals.css. |
| Charts | uPlot (vendored), wrapper `Chart()` at `js/vitals.js:451` | canvas colours are concrete hex values (`PAL`, `SERIES_COLORS`) |
| Icons | FontAwesome 4 glyphs supplied by the Unraid webGui (`fa fa-*`) | the plugin does not load its own icon font |

Token naming: keys in the front matter are the CSS custom property names without `--`
(`v-accent` == `--v-accent`). `unraid-*` keys are reference values from the host webGui, not
plugin tokens, and `on-accent` / `on-warn` name colours that are raw literals in code today
(see Known mismatches).

### Shared Beinno basics

Follows the family basics in the `beinno-design-system` skill (not copied here).
- Logo: none. The app header uses the FontAwesome `fa-heartbeat` glyph in `.v-logo`
  (`js/vitals.js:1064`, css `:68`), the Dashboard tile uses the same glyph
  (`Vitals.Dashboard.page:19`), and the `.plg` icon is `fa-heartbeat` too
  (`plugins/unraid-vitals.plg:13`). Do not create or AI-generate a Beinno logo for it.
- Promo videos: none yet. When made, they follow the shared basics: an English and an Egyptian
  Arabic version, built from real screenshots, through `beinno-video`. The plugin UI itself stays
  LTR (see Layout).

## Colors

### Token block (`css/vitals.css:9-48`)

| Token | Value | Role |
|---|---|---|
| `--v-accent` | `#6366f1` | brand accent: primary button, active seg/chip/icon button fill, focus outline, tab underline, info pill |
| `--v-accent-fg` | `#818cf8` | accent as text (active tab label, logo glyph) |
| `--v-ok` / `--v-ok-fg` | `#22c55e` / `#4ade80` | healthy: fill+border tints / text |
| `--v-warn` / `--v-warn-fg` | `#f59e0b` / `#fbbf24` | warning: fills / text |
| `--v-bad` / `--v-bad-fg` | `#ef4444` / `#f87171` | critical: fills, danger button text / text |
| `--v-cpu` `--v-mem` `--v-load` `--v-rx` `--v-tx` `--v-temp` `--v-gpu` | `#4f9cf9` `#b07cf9` `#f9a94f` `#35c48a` `#f97066` `#f97066` `#22d3ee` | metric identity (series strokes, card icon tints, bars). `--v-tx` and `--v-temp` share a value. |
| `--v-border` | `rgba(127,127,127,.18)` | every hairline: panels, tables, inputs, tab strip |
| `--v-card` | `rgba(127,127,127,.045)` | resting surface of panels, cards, inputs, buttons |
| `--v-card-hover` | `rgba(127,127,127,.085)` | hover surface |
| `--v-muted` | `currentColor` | "secondary" text. It is NOT a grey: it resolves to the inherited theme text colour, and muting comes from `opacity` (`.muted` .55, `.v-sub` .62, `th` .58, `.v-foot` .45). |

Pattern for tinted status surfaces (pills, warn notes, delta chips, tag badges):
`background: color-mix(in srgb, <status> 15%, transparent); border-color: color-mix(in srgb, <status> 45%, transparent); color: <status>-fg`.
Use that recipe. Do not invent a new alpha.

Text helpers: `.ok` → `--v-ok-fg`, `.warn` → `--v-warn-fg`, `.crit` → `--v-bad-fg`, `.muted` → opacity .55.

### Chart palette (`js/vitals.js:116-132`)

uPlot draws on a canvas and needs concrete colours. `PAL` mirrors the metric tokens and is
refreshed from `getComputedStyle(.vitals)` by `resolvePalette()` (called at `vitals.js:985`),
so the CSS token stays the source of truth for those 11 keys. `SERIES_COLORS` (20 hues,
`pickColor(i)`) colours per-container / per-interface / per-sensor series, and it has no CSS
counterpart. Axis stroke `#8889`, grid `#8882`, and y2 axis `#8886` are 50%-grey with alpha, so they
work on both light and dark themes.

### Theme inheritance (black / white / azure / gray)

The plugin does **not** read any Unraid CSS variable. Count of `var(--<non-v>)` in vitals.css and
vitals.js: 0. The header comment at `vitals.css:5-7` says it does, which is drift (UV20).
It follows the active theme only through:

1. `color: inherit` on `.vitals` (body text = Unraid `--text-color`).
2. Translucent mid-grey surfaces and borders (`--v-card`, `--v-border`) that tint either a light
   or a dark page.
3. `--v-muted: currentColor` plus opacity.

Host theme values (upstream `unraid/webgui` master, formerly `limetech/webgui`;
`html.Theme--<name>` + `:root` vars, fetched 2026-11-13; not re-verified on a device):

| Theme | `--background-color` | `--text-color` | Kind |
|---|---|---|---|
| black | `#1d1b1b` | `#f2f2f2` | dark |
| white | `#f2f2f2` | `#1d1b1b` | light |
| azure | `#e8e8e8` | `#606e7f` | light |
| gray | `#1d1b1b` | `#606e7f` | dark |

Dark/light handling: there is **no theme switch inside the plugin** (no `.Theme--*` selectors, no
`prefers-color-scheme`). The status `-fg` tints and the overlay fallbacks (`#1a1a1a`, `#161616`)
are tuned for dark themes. On white and azure they fail contrast or break outright. See the
Accessibility table and UV1–UV3. Fix direction (not current state): theme-scoped overrides on
`html.Theme--white .vitals, html.Theme--azure .vitals { … }` inside the token section, and
overlays bound to Unraid's `--background-color`.

## Typography

- Family: inherited from Unraid (`html { font-family: clear-sans, sans-serif }`). The plugin loads
  no font file and declares no text family. Monospace is the only family it sets: `.v-mono`
  (`ui-monospace, Menlo, Consolas, monospace`, `vitals.css:599`), the log drawer body
  (`ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`, `:276`), and `.v-storage-type-name`
  (`monospace`, `:128`). Three stacks for one role is a mismatch (UV17).
- Scale: one fluid `clamp()` curve, `--v-fs-xs … --v-fs-xl` (front matter). Base text on
  `.vitals` is `--v-fs-md`, line-height 1.5. Phones (<640px) pin base to 13px and h2 to 20px.
- Only 21 `font-size` declarations use the scale. 73 use px literals (13px×10, 11px×10, 11.5px×9,
  12.5px×8, 12px×7, 10px×7, 10.5px×5, 9.5px×4, …), plus a few `em` in inline styles (UV13).
  Because everything is px, Unraid's Display Settings font-size (`--custom-font-size` on `html`)
  does not scale Vitals.
- Weights in use: 400, 500, 600 (labels, buttons, pills), 650 (title, card value, chart stat),
  700 (panel h3, table th, big numbers), 800 (fan stall badge only).
- Numbers: always `font-variant-numeric: tabular-nums` for values, tables (`td.num`), timestamps.
- Section labels (`.v-panel h3`, `th`, `.v-card-label`): uppercase, letter-spacing .06–.07em,
  `--v-fs-xs`, reduced opacity.

## Layout

- Spacing: `--v-gap` 16px (12px on phones) is the only spacing token. It is used between grid
  cells, cards and stacked panels. Everything else is literal px on a 2/4/6/8/10/12/16 rhythm
  (gap 8px×18, 6px×12, 4px×6, 10px×6), plus 111 inline `style:` attributes in vitals.js
  (mostly `margin-top/-bottom/-left`).
- Grids: `.v-cards` `repeat(auto-fit, minmax(178px,1fr))`; `.v-cards-4` 2-up → 4-up at ≥800px →
  8-up at ≥1300px → 1-up at ≤380px. `.v-grid` `repeat(auto-fit, minmax(320px,1fr))` (280px on
  tablet, 1fr on phone); `.v-span2` spans 2 columns on desktop.
- Documented tiers (`vitals.css:696-768`): phone `<640` (`max-width: 639px`), tablet
  `640–1023`, desktop `≥1024`. Extra ad-hoc breakpoints exist: 380, 800, 860, 900, 1300 (UV18).
- Tables never widen the page. Below 1024px, `.v-scroll` scrolls sideways (`min-width` 520px,
  or 460px on phone). `.v-scroll` caps height at 340px (300px on phone); `.v-scroll-off` opts out.
- Tabs: wrap on desktop. Below 1024px they become one horizontally scrolling row with scroll-snap.
  On phones they go icon-only (`.v-tab span { display:none }`).
- `@media (hover: none)`: no hover lift, and a min-height of 36px on tabs, buttons and chips.
- RTL: **not supported. LTR is the stance.** All strings are hard-coded English (no `_()`
  translation). CSS uses physical properties (41 `left/right/margin-left/border-left/text-align`
  uses, plus 19 inline `margin-left`). Upstream webGui renders `<html <?= $display['rtl'] ?>…>`,
  so an RTL language pack could flip the host page around Vitals. Until the plugin is
  localised, keep Vitals LTR and do not add Arabic strings. New CSS should still prefer logical
  properties (`margin-inline-start`, `inset-inline-end`, `text-align: start`) so a future RTL
  pass is cheap.

## Elevation & Depth

Shadows in use (no tokens):

| Use | Value | Line |
|---|---|---|
| stat-card hover lift | `0 10px 24px rgba(0,0,0,.16)` + `translateY(-2px)` | css:455 |
| chart legend popover | `0 12px 28px rgba(0,0,0,.35)` | css:552 |
| toast | `0 8px 24px rgba(0,0,0,.35)` | css:797 |
| right drawer | `-18px 0 42px rgba(0,0,0,.5)` | css:268 |
| tab underline (not elevation) | `inset 0 -2px 0 var(--v-accent)` | css:98, 675 |
| stall/critical pulse ring | `0 0 0 5px rgba(239,68,68,…)` keyframes | css:389 |

Panels and cards rest flat (border only). Depth comes from hover and from overlays.

z-index layers (no tokens, UV15): chart threshold line 2, event marker 3, chart ⋮ button 4,
chart menu backdrop 9, chart menu 10, modal backdrop 200, toast stack 1000, drawer backdrop
9998, drawer 9999. Order to keep: chart overlays < chart menu < modal < toast < drawer.

## Shapes

| Radius | Where |
|---|---|
| `--v-radius` 12px (10px phone) | panels, stat cards, hero |
| `--v-radius-sm` 8px | buttons, inputs, selects, seg/icon bars, sensor/gauge tiles, journal items, images |
| 999px | pills, chips, tag badges, delta chips |
| 50% | dots, fan icon, card icon ring, radar dots |
| literals | 10px (logo, modal, toast, therm tile, card icon), 6px (core tile, browse row, chart menu row, hbar), 7px (chart stat), 9px (badge), 4px (warn badges), 3px/2px (bars, swatches) |

The literals duplicate the scale (UV14). New work uses `--v-radius`, `--v-radius-sm`, 999px or 50%.

## Components

All functions are in `js/vitals.js`. All styles are in `css/vitals.css` and scoped `.vitals .v-*`.

### Hero / header (`App()` :957, css `.v-hero` :57-98)
One card: title row (`.v-logo` heartbeat glyph + `h2` + `.v-sub` live line with pulsing `.v-dot`),
toolbar (time-range `.v-seg` 1h/6h/24h + `.v-iconbar` of `.v-ibtn` refresh / AI bell / logs), and
the tab strip as the card's bottom edge (active tab = `inset 0 -2px 0 var(--v-accent)`).
- Do: put new global actions into `.v-iconbar` as `.v-ibtn` with `title` **and** `aria-label`.
- Don't: add a second toolbar row or labelled buttons to the hero.

### Tabs (`TABS` :942, `.v-tab` :662-678)
`<button class="v-tab [on]">` with `fa` icon + `<span>` label. Focus: `outline 2px var(--v-accent)`,
offset -2px. Phone: icon-only. Mini tabs (`.v-tabs-mini`, :122) are `.v-btn.xs` buttons with `.active`.
- Do: keep the label in the DOM (it is hidden visually on phone). Add `aria-label`, `role="tab"`,
  and `aria-selected` when you touch this (UV10).
- Don't: create another tab style. Use `.v-tabs` for top-level tabs and `.v-tabs-mini` for in-panel tabs.

### Buttons (`.v-btn` :100-119, `.v-ibtn` :83-91, `.v-seg-btn` :218-224)
Variants: default, `.primary` (accent fill, white 600), `.danger` (red outline/text, 12% red hover
fill), `.xs` (3px 7px, 12px, used for row actions), `.xs.active`. States: hover = accent border +
`--v-card-hover`; `:focus-visible` = 2px accent outline, offset 1px; `:disabled` = opacity .55 +
`not-allowed`; busy = sibling `fa-spinner fa-spin` (`DockerActions` :798). Icon-only row actions
(Docker/VM start/stop/restart) carry `title` only. Destructive actions confirm with
`window.confirm` via `useAction`.
- Do: use `.v-btn.danger` for destructive actions. Do not use inline `style:'color:#c00'` (`vitals.js:2800`).
- Don't: add new `.on`/`.active` naming. Toggle state is `.on` for `.v-ibtn/.v-tab/.v-chip` and
  `.active` for `.v-seg-btn/.v-btn.xs` today, so match the component you extend.

### Inputs (`.v-input` :112-117, `.v-select` :100, `.v-field` :227)
Same surface as buttons. Width defaults to 90px (numeric). Use `width:auto` inside
`.v-kb-search`, `.v-field` or `textarea`. Focus = accent outline + accent border. `.v-price-input`
(:135) is a one-off duplicate of `.v-input` with an undefined `--v-bg2` background (UV4).
- Do: give every input a `<label for>` or `aria-label`. Only 4 `<label>` exist for 29 inputs/selects today.

### Panels (`Panel()` :398, `.v-panel` :421-439)
`<section class="v-panel [v-span2]"><h3>Title <span class="v-hint">…</span></h3>…`. Hover tints
the border toward the accent. Never `overflow:hidden`, because the chart popover must escape.
Stacked panels get `--v-gap` from the rhythm guard (:774-777).

### Stat cards / tiles (`StatCard()` :408, `.v-card` :446-507)
Icon badge (always present) optionally wrapped in a conic-gradient ring, then label (uppercase
10.5px), value (`card-value`, tabular, `.ok/.warn/.crit`), sub line, optional `.v-bar` or
sparkline + floating `.v-card-delta` (`.up` ok tint, `.down` red tint). Hover: lift 2px + shadow.
Reduced motion disables the lift. Phone: sparkline hidden, delta inline.
Sensor-type tiles are separate families: `.v-sensor` (hwmon, 3px status left border),
`.v-gauge-tile` (SVG arc `Gauge()` :334), `.v-therm` (heat tile), `.v-core` (per-core %),
`.v-fan` + `FanIcon()` :234. `StatTile()` :172 is dead code with no CSS (UV17).
- Do: use `StatCard` for KPIs and a status left border (`.v-temp-ok/-warn/-crit`) for sensors.
- Don't: add a sixth tile family.

### Tables (`Table()` :746, css :588-599)
Hairline rows, `th` uppercase `--v-fs-xs` at opacity .58, `tbody tr:hover` = 6% accent tint, and
`td.num`/`th.num` right-aligned tabular. Always wrap in `Table()` (`.v-scroll`) so small screens
pan sideways. Names `.v-name` 600. Code/paths `.v-mono`.

### Pills, badges, chips
- `Pill()` :404 / `.v-pill` :603: neutral, `.run` (ok), `.warn`, `.info` (accent), `.stop`
  (opacity), capability hues `.think` (`#2dd4bf`, raw), `.vision` (`--v-cpu`), `.scope` (warn).
  `align-self:flex-start` stops Unraid's flex `td` from stretching it.
- `.v-badge` :288: numeric count bubble (17px, radius 9px); `-critical/-alert/-error` red/white,
  `-warning` amber/#1a1a1a, `-info` #4f9cf9/white.
- `.v-chip` :176: filter chip; `.on` = accent fill; severity tints `.v-chip-sev-*` :180-187.
- `.v-tag-badge` :826: KB category tag, which also acts as a filter.
- `.warn-badge` :644 and `.v-warnbadge` :690 are two more "red label" variants (UV17).
- Do: counts → `.v-badge`, status → `Pill`, filters → `.v-chip` rendered as `<button>`.

### Modal (`ShareBrowser()` :2054, `.v-modal*` :165-173)
Fixed backdrop `rgba(0,0,0,.55)`, centred box `min(640px,92vw)`, max-height 78vh, header +
scroll body. Closes on backdrop click and the header ✕ button. No Escape, no focus trap, no
`role="dialog"` (UV8). Background `var(--v-panel, #1a1a1a)` with `--v-panel` undefined, so it is
always dark (UV1).

### Drawers (`LogDrawer()` :1119, `FindingsDrawer()` :1431, `.v-drawer*` :262-284)
Right side, `min(560px,92vw)`, slide-in `.18s ease-out`, backdrop click closes. Log body is
monospace 11.5px. The findings variant switches to normal type through `:has(.v-ai-item)`.
Same overlay gaps as the modal (UV1, UV8).

### Toasts (`ToastStack()` :2661, `.v-toast*` :792-807)
Bottom-right stack, z 1000, 3px left border by kind (`-success` ok, `-warn` bad, `-info` accent),
whole card clickable. The background is translucent `--v-card` (UV2), and the toast is not
announced: no `role="status"`/`aria-live` (UV10).

### Charts: uPlot (`Chart()` :451, css :522-582)
Props: `series[{name,color,points}]`, `height` (150–220, per call site), `area` (canvas
gradient `gradientFill` 0.38 → 0.02 alpha), `stack` (0.55 alpha), `y2Fmt`, `thresholds`
(dashed `.v-th-line`, `#f87171`), `events` (dashed `.v-ev-marker`, `#60a5fa`), `hideLegend`.
Cursor sync key `'vit'`. Every chart gets a `⋮` `.v-chart-menu-btn` (has `aria-label`) that
opens a stats popover (current/min/max/avg). Native `u-legend` is restyled as inline swatches
(:571-578). Empty state: `.v-empty` inside `.v-chart`. Other SVG charts: `Donut()` :299,
`Gauge()` :334, `HBars()` :352, `RadarChart()` :197, `Spark()` :274 (34px, no axes).
- Do: take series colours from `PAL[...]` (metric) or `pickColor(i)` (enumerations).
- Don't: pass hex strings at call sites (`'#f8717166'`, `'#666'`, `'#8b8f9a'`, UV6). Colour must
  never be the only signal, so pair it with a label or value in the legend or stats.

### Empty / loading / error
`.v-empty` (centred, opacity .6). Loading copy is `"Loading…"`, which ships in both `Vitals.page`
and `App()`. Collector errors go to `.v-sub` "collection error" with a `.crit` dot. Panel-level
empty copy explains who fills the data ("the background agents populate this as they run").
Inline warnings use `.v-warnnote`.

### Dashboard tile (`Vitals.Dashboard.page`)
5 `vt-cell` numbers + worst finding + link, refreshed every 60s, with a self-contained inline
`<style>` (:32-47). It uses its own literal palette (`#888`, `#666`, `#b8860b`, `#c0392b`), not
`--v-*` and not Unraid vars (UV7). It must stay self-contained (no vitals.css dependency). It
should take colour from Unraid's theme variables (`--text-color`, `--alt-text-color`) and from
local `--vt-*` custom properties declared once at the top of its `<style>`.

## Do's and Don'ts

- Do add every new colour, size, radius, z-index or duration as a `--v-*` property in the
  `.vitals { }` token block first, then use `var(--v-…)`.
- Do use the `color-mix(in srgb, <token> 15%/45%, transparent)` recipe for tinted status surfaces.
- Do keep text colour inherited. Never set `color:#fff`/`#000` on text that sits on the page
  background. Fixed text colours are only allowed on filled status surfaces (`on-accent`, `on-warn`).
- Do check every new colour on all four Unraid themes (black, white, azure, gray).
- Do render clickable things as `<button>` (or `<a href>`), never `div`/`span` with `onClick`.
- Don't write `var(--v-x, #hex)` fallbacks. The token is always defined on `.vitals`, and five
  fallbacks today disagree with their tokens (UV5).
- Don't reference undefined properties (`--v-fg`, `--v-bg`, `--v-bg2`, `--v-panel`, `--v-panel-bg`).
- Don't add inline `style:` for colour or spacing in vitals.js. Add a class.
- Don't add NEW `@media` breakpoints beyond the existing ones. Tiers are 639/640 and 1023/1024;
  the existing extras 380, 800, 860, 900 and 1300 (`vitals.css:759, 404, 130, 417, 407`) are UV18
  and get folded in over time.
- Don't edit `js/vendor/*` or `css/uPlot.min.css`.

## Motion

| Duration | Easing | Use |
|---|---|---|
| .12s | ease | table row hover, therm hover, tag badge |
| .14s | ease | default control transition (buttons, tabs, cards, seg) — 14 uses |
| .16s | ease | panel border |
| .18s | ease-out | drawer slide-in (`v-drawer-in`, 30px) |
| .22s | ease-out | toast in (`v-toast-in`, 8px) |
| .3s / .5s | ease | hbar / progress bar width |
| .6s | ease | ring conic background |
| 1.6–1.8s | ease-in-out, infinite | `v-fan-stall-pulse` (fan stall, voltage out of range, critical therm) |
| 2s | infinite | `v-pulse` live dot |
| RPM-derived | linear, infinite | `v-fanicon-spin` |

Reduced motion (`prefers-reduced-motion: reduce`) is honoured only for fan blades (:390) and the
card hover lift (:457). The pulses, drawer/toast entrance, therm lift and `fa-spin` keep
animating (UV16). Rule going forward: every new animation ships with its reduced-motion opt-out
in the same PR, and new transitions use .14s ease.

## Accessibility

Contrast, computed with WCAG 2.x relative luminance against each theme's `--background-color`
(translucent `--v-card` adds ≤4.5% grey and is ignored). AA = 4.5:1 for text, 3:1 for UI parts.

| Pair | black | white | azure | gray | Verdict |
|---|---|---|---|---|---|
| inherited body text | 15.32 | 15.32 | 4.25 | 3.29 | Unraid's own; azure/gray fail (host issue) |
| `--v-ok-fg` #4ade80 text | 9.84 | 1.56 | 1.42 | 9.84 | fails light themes |
| `--v-warn-fg` #fbbf24 text | 10.27 | 1.49 | 1.36 | 10.27 | fails light themes |
| `--v-bad-fg` #f87171 text | 6.20 | 2.47 | 2.26 | 6.20 | fails light themes |
| `--v-accent-fg` #818cf8 (active tab) | 5.75 | 2.66 | 2.43 | 5.75 | fails light themes |
| `--v-bad` #ef4444 (danger btn text) | 4.56 | 3.36 | 3.07 | 4.56 | fails light themes |
| `#f59e0b` raw warn text (sensors, therm) | 7.98 | 1.92 | 1.75 | 7.98 | fails light themes |
| `.muted` opacity .55 | 5.45 | 3.72 | 2.03 | 1.88 | fails white/azure/gray |
| `.v-foot` opacity .45 | 4.12 | 2.81 | 1.76 | 1.64 | fails all |
| `.v-sub` / `.v-tab` opacity .62 | 6.57 | 4.61 | 2.26 | 2.04 | fails azure/gray |
| `--v-border` vs bg (UI part) | 1.25 | 1.21 | 1.20 | 1.25 | below 3:1 for input borders |
| tile `#666` / `#888` labels | 2.99 / 4.84 | 5.13 / 3.17 | 4.69 / 2.89 | 2.99 / 4.84 | each fails on one side |

Filled surfaces (theme-independent):

| Pair | Ratio | Verdict |
|---|---|---|
| white on `--v-accent` #6366f1 (primary btn, active seg/chip/ibtn) | 4.47 | fails AA by 0.03 |
| white on `--v-bad` #ef4444 (critical badge) | 3.76 | fails (10.5px bold) |
| white on #4f9cf9 (info badge, medium chip on) | 2.82 | fails |
| white on `--v-bad-fg` #f87171 (critical chip on) | 2.77 | fails |
| #1a1a1a on #f59e0b (warning badge, high chip on) | 8.10 | pass |
| #1a1a1a on `--v-ok-fg` #4ade80 (low chip on) | 9.99 | pass |

Verified rendering (static harness of the real `vitals.css` on the four theme backgrounds,
headless Chromium, 2026-11-13): on white, `.v-modal` computes `rgb(26,26,26)` with inherited
`#1d1b1b` text, and the modal title and rows are invisible. `.v-toast` and `.v-chart-menu`
compute `rgba(127,127,127,0.043)`, so they are see-through over charts and page content.
This was not checked on a live Unraid server.

Focus: `:focus-visible` 2px `--v-accent` outline exists for `.v-btn`, `.v-select`, `.v-input`,
`.v-tab` only. `.v-ibtn`, `.v-seg-btn`, `.v-chip`, `.v-chart-menu-btn` and `.v-tag-badge` have none (UV9).

Keyboard and semantics today: 54 `<button>`s carry clicks, but 15 click handlers sit on
`div`/`span` (chips ×7, share-browser rows, toast, backdrops). No Escape handling anywhere, no
focus trap or return for the modal and drawers, 2 `role` attributes and 2 `aria-label`s in the
whole app, no `aria-hidden` on decorative `fa` icons, and no live region for toasts. Icon-only
controls rely on `title`, and phone tabs drop their visible label.

Targets: `.v-btn.xs` is about 20px tall (12px, line-height 1, 3px padding, 1px border) and `.v-chart-menu-btn` is 22×20 on pointer devices
(below 24×24). `(hover:none)` raises tabs, buttons and chips to 36px min-height and the chart
menu button to 30×30.

Rules: AA in all four themes for any new text; visible focus on every interactive element;
`aria-label` on icon-only buttons; dialogs get `role="dialog"`, `aria-modal`, Escape, focus
return; status is never colour-only (pair it with text: the badge count, pill label, value).

## HARD RULES

1. No hex/`rgb()`/`hsl()` literal and no `font-family` in `css/vitals.css` outside the
   `.vitals { }` token block (lines 9-48), or in `js/vitals.js` / `*.page`; vendor files are
   exempt. Enforced by `npm run lint:tokens` (`scripts/check-design-tokens.sh`, part of
   `npm run check`). It is a ratchet: today's 173 literals are tolerated (`MAX_HITS` baseline), no
   new ones are allowed, and the baseline is lowered as literals move to tokens.
2. Canvas/SVG colours in vitals.js come from `PAL` (refreshed by `resolvePalette()`) or
   `SERIES_COLORS`. No hex at call sites.
3. Every `var(--v-*)` used must be defined in the token block. No `var(--v-x, #hex)` fallbacks.
4. Overlays (modal, drawer, toast, popover) use an opaque surface token, never `--v-card`.
5. Every clickable element is a `<button>`/`<a href>` with a visible `:focus-visible` ring.
6. Icon-only buttons have `aria-label`. Dialogs/drawers have `role="dialog"` and `aria-modal`, and close on Escape.
7. New font sizes use `--v-fs-*`. New radii use `--v-radius`, `--v-radius-sm`, 999px or 50%.
8. Breakpoints: no NEW breakpoints beyond the existing ones: tiers 639/640 and 1023/1024, plus the
   existing 380, 800, 860, 900 and 1300 rules (UV18, to be folded in over time).
9. Every animation has a `prefers-reduced-motion: reduce` opt-out.
10. Check text contrast against black, white, azure and gray before merging.

## Known mismatches

Full list with file:line, counts, severity and fix scope: `docs/design-audit.md`. Each item is
a Plane ticket (project Unraid Plugins, label `design-system`):

| ID | Mismatch | Plane |
|---|---|---|
| UV1 | overlay surfaces dark-only | UNRAIDPLUG-207 |
| UV2 | translucent toast/popover | UNRAIDPLUG-201 |
| UV3 | status text fails on light themes | UNRAIDPLUG-202 |
| UV4 | undefined custom properties | UNRAIDPLUG-204 |
| UV5 | raw status hex + wrong fallbacks in CSS | UNRAIDPLUG-205 |
| UV6 | duplicate JS palette | UNRAIDPLUG-208 |
| UV7 | Dashboard tile palette | UNRAIDPLUG-211 |
| UV8 | keyboard/dialog semantics | UNRAIDPLUG-203 |
| UV9 | missing focus rings | UNRAIDPLUG-209 |
| UV10 | unlabeled controls | UNRAIDPLUG-210 |
| UV11 | opacity-muted contrast | UNRAIDPLUG-212 |
| UV12 | filled-surface contrast | UNRAIDPLUG-206 |
| UV13 | px type sizes | UNRAIDPLUG-213 |
| UV14 | radius literals | UNRAIDPLUG-214 |
| UV15 | z-index scale | UNRAIDPLUG-215 |
| UV16 | reduced motion | UNRAIDPLUG-216 |
| UV17 | duplicate components/dead code | UNRAIDPLUG-217 |
| UV18 | breakpoints | UNRAIDPLUG-218 |
| UV19 | inline styles | UNRAIDPLUG-219 |
| UV20 | header comment drift | UNRAIDPLUG-220 |
| UV21 | RTL hardening | UNRAIDPLUG-221 |

`npx -y @google/design.md lint DESIGN.md` (2026-11-13): **0 errors, 24 warnings, 1 info**.
- `contrast-ratio` ×5: `button-primary` and `chip-on` (white on `#6366f1`, 4.47:1) and
  `badge-critical` (white on `#ef4444`, 3.76:1) are real failures, tracked as UV12.
  `button-danger` (1.06:1) and `tab-active` (1.34:1) are linter artifacts: their background is the
  translucent `--v-card`, which the linter does not composite over a theme background. The real
  ratios are in the Accessibility table (UV3).
- `missing-primary` ×1: the code calls its brand colour `--v-accent`, and the token keeps the code
  name on purpose (agents must be able to grep it).
- `orphaned-tokens` ×18: the `unraid-*` host-theme references and metric colours are used in prose
  and charts, not by front-matter components. Allowed.
- Fluid sizes: `fontSize` holds the `clamp()` ceiling because the spec needs a plain dimension.
  The exact `clamp()` from code is in the YAML comment beside each token.
