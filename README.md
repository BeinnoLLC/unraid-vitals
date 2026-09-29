# unraid-vitals

A generic, AI-powered Unraid intelligence plugin. Deep analytics, live charts,
real history, hwmon sensor monitoring, background AI agents, and a growing
knowledge base that learns from what happens on your server — all collected
and computed **locally**. No InfluxDB, no Grafana, no cloud telemetry, no
external API keys required.

![unraid-vitals icon](assets/icon.png)

## What you get

**10 tabs**, one nav entry (`Dashboard → Vitals`):

| Tab | What's there |
|---|---|
| **Dashboard** | Grafana-style stat tiles with sparklines (CPU/memory/hottest-disk), CPU+memory area chart, stacked network flow, disk-temperature chart with alert threshold, container-CPU chart, containers-by-state donut, pool summary |
| **Array & Disks** | Per-disk temperature history with threshold lines + min/avg/max stats, ranked hottest-disks bar chart, SMART sector-growth chart (reallocated/pending), full disk table, SMART detail table, 30-day daily rollups |
| **Docker** | Per-container CPU/memory table with sortable columns, start/stop/restart controls (CSRF-protected) |
| **Network** | Per-interface throughput history, totals |
| **System** | CPU core topology grid with live per-core load, VM table with pinning and controls |
| **Shares** | Share browser, AI-generated share descriptions |
| **Hardware** | Every hwmon sensor on the box: temperature tiles with °-headroom-to-threshold, fan tiles with RPM + PWM duty, temperature/fan history charts with chip-reported alert lines, thermal-vs-load dual-axis overlay chart |
| **Knowledge** | Full-text search (FTS5) over everything the AI agents and background research have learned |
| **Research** | Ask a free-form question; a local LLM answers grounded in your live metrics + the knowledge base, run fully in the background (no HTTP timeout) |
| **Settings** | Sample interval, retention, alert thresholds, start-page switch |

Plus, in the header (not cluttering the tabs):
- **Findings** button — a badge shows the count of active AI findings; opens a
  right-side drawer listing every finding, filterable by agent
- **Logs** button — opens a right-side drawer over syslog/dmesg/docker logs,
  with a critical-only filter and follow-tail auto-scroll

## AI agents & the knowledge base

A background agent suite (Node.js + [SmythOS SDK](https://smythos.com), calling
a **local** Ollama model — no cloud LLM, no API key) runs hourly per domain
(disks, pools, network, thermal, general) and writes findings to SQLite. These
surface as the Findings drawer and feed three things:

- **Events** (`kb_events`) — every alert-engine breach (disk temp, array fill,
  load, SMART counters, sensor thresholds, fan stalls, container stops) AND
  every AI finding becomes a typed, timestamped event with frozen evidence
  (the metric series + threshold at the time). Events are idempotent per
  condition — a temp staying high for 40 minutes is one event, not 40 — and
  auto-resolve when the metric normalizes.
- **Lessons** (`kb_lessons`) — durable takeaways distilled from resolved
  events (e.g. *"RAM spikes on this box correlate with the Arr stack scanning
  shares after cache moves"*). Recurring events of the same kind+entity merge
  into one lesson instead of duplicating, with confidence growing each time
  it recurs.
- **Solutions** (`kb_solutions`) — how a specific event was detected and
  fixed, linked back to its event and lesson.

This is deliberately **event ≠ knowledge**: an event is a fact about what
happened; a lesson is what you learned from it. See
[docs/phases](docs/phases/) for the full roadmap (event lifecycle → learning
pipeline → hybrid retrieval + reranker → KB curation UI).

Retrieval today is FTS5 keyword search over `kb_documents` (findings +
research answers), synthesized by the local LLM — a deliberate no-vector-store
scope (no GPU, no external embedding API). Hybrid BM25+embeddings retrieval
with an LLM reranker is on the roadmap.

## Sensors & alerting

`include/collect.php` reads `/sys/class/hwmon/*` **directly** — no dependency
on the `sensors` CLI or lm-sensors package, so it works on any Unraid box.
Readings are sanity-filtered (−20..120 °C bounds reject disconnected-sensor
sentinels like 127 °C). Alerts use the **chip-reported** max/crit thresholds
per sensor rather than one global temperature setting, so legitimately
hot-running sensors don't false-alarm. Fan-stall detection only fires for
fans previously seen spinning (unused headers stay silent).

## Everything is local

Collected every minute by a PHP collector reading Unraid's own state files
(`/var/local/emhttp/*`, `/proc`, the Docker API, hwmon sysfs, SMART cache).
History lives in a 24-hour RAM ring buffer plus hourly rollups on flash.
The AI agents' knowledge base (`vitals.db`, SQLite) persists in your appdata
share (survives reboots) — never in RAM, never off-box.

The stock Unraid dashboard is never modified: Vitals adds its own page, an
optional dashboard tile, and an optional start-page switch.

## Requirements

- Unraid 6.12+ (developed and tested on 7.x)
- No external services, no cloud accounts, no API keys
- AI agents need Node.js on the box (or a reachable local Ollama endpoint —
  configurable in Settings) — the core dashboard works without either; agents
  simply disable themselves with a clear hint if Node isn't available

## Install

**Plugins → Install Plugin** (in the Unraid web UI), paste:

```
https://raw.githubusercontent.com/BeinnoLLC/unraid-vitals/main/plugins/unraid-vitals.plg
```

Unraid downloads the payload, verifies its MD5, runs the post-install (cron
entries + first sample) and the Vitals pages appear immediately — no reboot
needed.

## Uninstall

Plugins → unraid-vitals → Remove. Cron entries and the RAM buffer are cleaned
up. Your flash-side history rollups AND the knowledge-base database in your
appdata share (`appdata/unraid-vitals/vitals.db`) are deliberately **kept** —
delete them manually if you want a clean slate.

## How it works

```
┌────────────┐  every minute   ┌───────────────┐  1/min  ┌──────────────────┐
│ cron       │ ───────────────▶│ collect.php   │────────▶│ RAM ring buffer  │
│ (unraid)   │                 │ + hwmon read  │         │ 24h @ 1 sample/m │
└────────────┘                 │ + alert engine│         └────────┬─────────┘
                                └───────┬───────┘                  │ hourly
                                        │ events                   ▼
                                        ▼                 ┌──────────────────┐
                                ┌───────────────┐         │ flash rollups    │
                                │ kb_events      │         │ (24 writes/day)  │
                                │ (SQLite,       │         └──────────────────┘
                                │  appdata)      │
                                └───────▲────────┘
                                        │ hourly, per domain
┌────────────┐   hourly cron  ┌────────┴────────┐   fire-and-forget  ┌───────────────┐
│ cron       │───────────────▶│ agent/analyze.mjs│                   │ research.mjs  │
│ (agents)   │  (flock-guarded)│ (SmythOS+Ollama)│                   │ (on-demand,   │
└────────────┘                 └────────┬────────┘                   │  FTS5+LLM)    │
                                         │ findings → kb_documents    └───────────────┘
                                         ▼
                                ┌──────────────────┐        ┌────────────────────────┐
                                │ vitals.db (SQLite)│◀──────▶│ ajax.php ◀── vitals.js │
                                │ findings/events/   │        │ (polls while open)    │
                                │ lessons/solutions/ │        └────────────────────────┘
                                │ kb_documents (FTS5)│
                                └────────────────────┘
```

| Path | Role |
|---|---|
| `Vitals.page` | Main dashboard page (Unraid `Dashboard` menu); Settings is a tab on this page, not a separate `.page` |
| `Vitals.Dashboard.page` | Compact tile for the stock dashboard |
| `js/vitals.js` | The entire UI — Preact + uPlot, vendored (no CDN) |
| `include/collect.php` | One sample of every metric source, incl. hwmon sensors |
| `include/store.php` | Ring buffer, flash rollups, alert engine, event store, KB reads |
| `include/ajax.php` | Polling + action endpoints for the UI (15+ actions) |
| `include/actions.php` | Docker/VM control endpoint (CSRF-protected) |
| `agent/analyze.mjs` | Hourly per-domain AI agent runner (SmythOS + local Ollama) |
| `agent/research.mjs` | On-demand background research job (FTS5 retrieval + LLM synthesis) |
| `agent/lib/db.mjs` | SQLite schema + helpers: findings, events, lessons, solutions, KB |
| `scripts/install.sh` / `remove.sh` | Cron + config setup / teardown |

### CSRF note

Unraid's own `webGui/include/local_prepend.php` validates every POST's CSRF
token **and unsets it** before plugin code runs. State-changing endpoints in
this plugin therefore treat an absent token as "Unraid already validated it,"
not as an attack — the same trust boundary as Unraid's own dynamix pages. A
token still present (CLI calls, tests) is still `hash_equals`-checked.

## Building from source

```
./build/build.sh 2026.09.28      # date-versioned, e.g. YYYY.MM.DD
```

Produces `dist/unraid-vitals.plg` (the install manifest) and
`dist/unraid-vitals-<version>-x86_64-1.txz` (the payload, attached to a GitHub
release). The `.plg` in `plugins/` is what users install; it points at
`releases/latest/download/`, so every release must ship the payload under the
exact versioned name.

## Roadmap

Tracked as GitHub issues under the [BeinnoLLC/unraid-vitals](https://github.com/BeinnoLLC/unraid-vitals)
project board, one milestone per phase. Highlights:

- **Responsive/mobile layout** — full phone/tablet support across all tabs,
  tables, charts and drawers
- **Event store & incident lifecycle** — done; see `kb_events` above
- **Learning pipeline** — resolved events auto-distill into lessons +
  solution writeups, merged on recurrence, surfaced at alert time
- **Hybrid retrieval + reranker** — local embeddings, BM25+cosine fusion,
  LLM reranking, eval harness
- **KB curation UI** — Events/Lessons/Solutions workspace with feedback and
  export

## License

[MIT](LICENSE) — © 2026 BeinnoLLC
