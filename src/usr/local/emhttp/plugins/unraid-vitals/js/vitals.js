/*! unraid-vitals app — Preact + htm + uPlot, all vendored (no CDN). */
(function () {
'use strict';

var mountEl = document.getElementById('vitals-root');
function bail(msg) { if (mountEl) mountEl.textContent = 'unraid-vitals: ' + msg; }
if (typeof preact === 'undefined') return bail('preact failed to load.');
if (typeof uPlot === 'undefined') return bail('uPlot failed to load.');
if (typeof preactHooks === 'undefined') return bail('preact hooks failed to load.');

/* preact.h IS createElement: h(type, props, ...children) — exactly the call
   shape used throughout. (htm.bind() would return a tagged-template function
   that only works as h`<div/>`, so it is deliberately not used.) */
var h = preact.h;
var render = preact.render;
var useState = preactHooks.useState;
var useEffect = preactHooks.useEffect;
var useRef = preactHooks.useRef;
var ENDPOINT = (mountEl && mountEl.getAttribute('data-endpoint')) ||
               '/plugins/unraid-vitals/include/ajax.php';
var KB_ASSET_BASE = ENDPOINT + '?action=kb_asset&f=';

/* --------------------------------------------------------------- helpers */

function bytes(n, p) {
  if (n == null || n === '' || isNaN(n)) return '—';
  var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], i = 0;
  n = Number(n);
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i ? n.toFixed(p === undefined ? 1 : p) : String(Math.round(n))) + ' ' + u[i];
}
function pctStr(n, d) { return n == null ? '—' : Number(n).toFixed(d === undefined ? 1 : d) + '%'; }
/* Whole-number tick increments for counters (sectors, restarts, containers). */
var INT_INCRS = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000, 100000];
/* Binary tick increments for byte-valued y axes: 1,2,4,8,16,… × 1 B/KiB/MiB/GiB. */
var BYTE_INCRS = (function () {
  var out = [];
  for (var e = 0; e <= 4; e++) [1, 2, 4, 8, 16, 32, 64, 128, 256, 512].forEach(function (m) { out.push(m * Math.pow(1024, e)); });
  return out;
})();
function dur(s) {
  if (s == null) return '—';
  s = Math.floor(s);
  var d = Math.floor(s / 86400), hh = Math.floor((s % 86400) / 3600), mm = Math.floor((s % 3600) / 60);
  return (d ? d + 'd ' : '') + (d || hh ? hh + 'h ' : '') + mm + 'm';
}
function lvl(v, w, c) { return v == null ? '' : (v >= c ? 'crit' : v >= w ? 'warn' : 'ok'); }
function ts(t) {
  var d = new Date(t * 1000);
  return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
}
/** Seconds between a unix timestamp and now, for "sampled Ns ago". */
function age(t) { return t ? Math.max(0, Math.round(Date.now() / 1000 - t)) : 0; }
/** Human "sampled Ns/Nm/Nh ago" instead of a raw (and eventually huge) second count. */
function ageLabel(t) {
  var s = age(t);
  if (s < 90) return s + 's ago';
  if (s < 5400) return Math.round(s / 60) + 'm ago';
  return Math.round(s / 3600) + 'h ago';
}

/* Minimal markdown -> preact vnodes for AI-written KB reports (headings,
   bold/italic, unordered/ordered lists, paragraphs). Deliberately not a
   full CommonMark implementation — the model is instructed to use only
   this subset (see agent/study.mjs / research.mjs prompts). No raw HTML
   is ever interpreted; text runs go through h()'s normal text-child
   escaping, so this is safe against anything the model emits. */
function mdInline(text, key) {
  var parts = [], rest = text, re = /\*\*(.+?)\*\*|\*(.+?)\*|`(.+?)`/;
  var i = 0;
  while (rest.length) {
    var m = re.exec(rest);
    if (!m) { parts.push(rest); break; }
    if (m.index > 0) parts.push(rest.slice(0, m.index));
    if (m[1] !== undefined) parts.push(h('b', { key: key + '-' + (i++) }, m[1]));
    else if (m[2] !== undefined) parts.push(h('i', { key: key + '-' + (i++) }, m[2]));
    else parts.push(h('code', { key: key + '-' + (i++) }, m[3]));
    rest = rest.slice(m.index + m[0].length);
  }
  return parts;
}
function renderMd(md) {
  if (!md) return null;
  var lines = String(md).replace(/\r\n/g, '\n').split('\n');
  var out = [], list = null, para = null, key = 0;
  function flushPara() { if (para) { out.push(h('p', { key: key++ }, mdInline(para.join(' '), 'p' + key))); para = null; } }
  function flushList() { if (list) { out.push(h(list.tag, { key: key++ }, list.items)); list = null; } }
  lines.forEach(function (raw) {
    var line = raw.trim();
    var mh = /^(#{1,4})\s+(.*)$/.exec(line);
    var mul = /^[-*]\s+(.*)$/.exec(line);
    var mol = /^\d+\.\s+(.*)$/.exec(line);
    if (line === '') { flushPara(); flushList(); return; }
    if (mh) {
      flushPara(); flushList();
      var tag = 'h' + Math.min(6, mh[1].length + 2); // markdown ## -> h4, keeps report headings visually subordinate to the KB card title
      out.push(h(tag, { key: key++ }, mdInline(mh[2], 'h' + key)));
      return;
    }
    if (mul || mol) {
      flushPara();
      var tag2 = mul ? 'ul' : 'ol';
      if (!list || list.tag !== tag2) { flushList(); list = { tag: tag2, items: [] }; }
      list.items.push(h('li', { key: 'li' + list.items.length }, mdInline((mul || mol)[1], 'li' + key + '-' + list.items.length)));
      return;
    }
    flushList();
    if (!para) para = [];
    para.push(line);
  });
  flushPara(); flushList();
  return out;
}

/* uPlot needs concrete colours; the vars live on .vitals, not :root. */
var PAL = {
  'v-cpu': '#4f9cf9', 'v-mem': '#b07cf9', 'v-load': '#f9a94f', 'v-rx': '#35c48a',
  'v-tx': '#f97066', 'v-temp': '#f97066', 'v-gpu': '#22d3ee', 'v-accent': '#818cf8',
  'v-ok-fg': '#4ade80', 'v-warn-fg': '#fbbf24', 'v-bad-fg': '#f87171'
};
function resolvePalette() {
  var host = document.querySelector('.vitals') || document.documentElement;
  var cs = getComputedStyle(host);
  Object.keys(PAL).forEach(function (k) {
    var v = cs.getPropertyValue('--' + k).trim();
    if (v) PAL[k] = v;
  });
}
var SERIES_COLORS = ['#4f9cf9', '#b07cf9', '#35c48a', '#f97066', '#22d3ee',
                     '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#60a5fa',
                     '#fb7185', '#facc15', '#4ade80', '#38bdf8', '#e879f9',
                     '#c4b5fd', '#fdba74', '#67e8f9', '#86efac', '#fca5a5'];
function pickColor(i) { return SERIES_COLORS[i % SERIES_COLORS.length]; }

/** '#rrggbb' -> 'rgba(r,g,b,a)' for uPlot area fills (needs concrete colours). */
function withAlpha(hex, a) {
  if (hex && hex.slice(0, 1) === '#' && (hex.length === 7 || hex.length === 4)) {
    var r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
  }
  return hex;
}

/** Canvas linear gradient (opaque near the line, fading to transparent at
 *  the baseline) instead of a flat alpha fill — matches the "glow" area
 *  chart look from the reference dashboards instead of the flat single-
 *  alpha wash uPlot's fill option gives you by default. Returns a function
 *  because uPlot's series.fill wants a fill-resolver, not a static value:
 *  it needs the canvas 2D context to build the CanvasGradient object, and
 *  that context isn't available until uPlot itself calls this at draw time.
 */
function gradientFill(hex, topAlpha) {
  return function (u, seriesIdx) {
    var ctx = u.ctx;
    var top = 0, height = (u.bbox && u.bbox.height) || u.height || 150;
    var g = ctx.createLinearGradient(0, top, 0, top + height);
    // A caller passing an undefined/invalid color used to throw deep inside
    // uPlot's draw loop (CanvasGradient.addColorStop rejects a non-color
    // string) and silently break EVERY chart on the page, not just the one
    // with the bad series — fall back to a neutral gray instead of
    // propagating the bad value into the canvas API.
    var safeHex = (hex && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) ? hex : '#8b8f9a';
    g.addColorStop(0, withAlpha(safeHex, topAlpha == null ? 0.38 : topAlpha));
    g.addColorStop(1, withAlpha(safeHex, 0.02));
    return g;
  };
}

/* Grafana-style stat tile: value on a tinted background, tone-driven colour.
   P: {label, value, sub, tone: ok|info|warn|crit|muted, spark: [[t,v]...]} */
var TONE_COLOR = { ok: '#4ade80', info: '#4f9cf9', warn: '#fbbf24', crit: '#f87171', muted: '#9ca3af' };
function StatTile(P) {
  var tone = TONE_COLOR[P.tone || 'muted'];
  return h('div', { class: 'v-stat-tile', style: '--tile:' + tone },
    h('div', { class: 'v-stat-tile-label' }, P.label),
    h('div', { class: 'v-stat-tile-value' }, P.value),
    P.sub ? h('div', { class: 'v-stat-tile-sub' }, P.sub) : null,
    P.spark ? h(Spark, { points: P.spark, color: tone }) : null);
}

/* hwmon sensors come with opaque labels ("Sensor 1", "temp3") when the
 * chip has no name for the input — prefix the chip family so two "Sensor 1"
 * tiles from different chips are distinguishable at a glance. */
function sensorLabel(t) {
  var lbl = t.label || t.id || '?';
  if (/^sensor \d+$/i.test(lbl) || /^temp\d+$/.test(lbl)) {
    var chip = String(t.chip || '').split(/[-_\s]/)[0];
    return (chip ? chip + ' ' : '') + lbl.replace(/^sensor /i, '#').replace(/^temp/, 'temp ');
  }
  return lbl;
}

/* SVG radar/spider chart. axes: [{label, value(0..100), color?}]. Value is
 * a normalized 0-100 "how close to the concerning threshold" score — the
 * polygon touching the rim means that dimension is at its limit. Grid rings
 * at 25/50/75/100. */
function RadarChart(P) {
  var axes = P.axes || [];
  var n = Math.max(3, axes.length);
  var size = P.size || 220, cx = size / 2, cy = size / 2, R = size / 2 - 34;
  var pt = function (i, frac) {
    var ang = (Math.PI * 2 * i) / n - Math.PI / 2;
    return [cx + Math.cos(ang) * R * frac, cy + Math.sin(ang) * R * frac];
  };
  var ring = function (frac) {
    var s = [];
    for (var i = 0; i < n; i++) { var p = pt(i, frac); s.push(p[0].toFixed(1) + ',' + p[1].toFixed(1)); }
    return s.join(' ');
  };
  var poly = axes.map(function (a, i) { var p = pt(i, Math.max(0.04, Math.min(1, a.value / 100))); return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
  return h('svg', { viewBox: '0 0 ' + size + ' ' + size, class: 'v-radar', width: size, height: size },
    [0.25, 0.5, 0.75, 1].map(function (f, i) {
      return h('polygon', { key: 'r' + i, points: ring(f), class: 'v-radar-ring' + (i === 3 ? ' rim' : '') });
    }),
    axes.map(function (a, i) {
      var p = pt(i, 1);
      return h('line', { key: 's' + i, x1: cx, y1: cy, x2: p[0], y2: p[1], class: 'v-radar-spoke' });
    }),
    h('polygon', { points: poly, class: 'v-radar-poly' }),
    axes.map(function (a, i) {
      var p = pt(i, 1.22);
      var anchor = p[0] > cx + 4 ? 'start' : p[0] < cx - 4 ? 'end' : 'middle';
      return h('text', { key: 'l' + i, x: p[0], y: p[1], class: 'v-radar-label', 'text-anchor': anchor,
        'dominant-baseline': 'middle' }, a.label);
    }));
}

/* Animated PWM fan icon: CSS-spun blade whose rotation duration scales with
   RPM (faster fan = faster spin, capped so it stays readable rather than a
   blur), plus a duty-% progress ring around it. Pure CSS animation (no JS
   rAF loop) so many fan tiles on screen cost nothing. `stalled` freezes the
   blade and swaps to a warning tone — a fan that should be spinning but
   reads 0 RPM is a real fault, not "off". */
function FanIcon(P) {
  var rpm = P.rpm || 0;
  var duty = P.duty; // 0-100 or null
  var stalled = P.stalled;
  // 0 RPM intentionally idle (duty 0, e.g. a case fan header with a 0%
  // curve floor) vs "spinning but slow" both just don't animate.
  var spinning = rpm > 0 && !stalled;
  // RPM -> spin period: linear-ish mapping clamped to [0.35s, 3s] so a
  // 200 RPM case fan and a 3000 RPM CPU fan both read as "spinning" at a
  // glance without the fast one turning into a strobe.
  // Quantized to 0.25s steps: RPM jitters a few percent between polls and
  // a changed animation-duration RESTARTS the CSS animation — without
  // quantization the blades visibly snap every refresh. A 0.25s step only
  // changes the duration when the speed genuinely shifted.
  var period = spinning ? Math.round(Math.max(0.35, Math.min(3, 1400 / rpm)) * 4) / 4 : 0;
  var pct = duty != null ? Math.max(0, Math.min(100, duty)) : (rpm > 0 ? null : 0);
  var ringDeg = pct != null ? Math.round(pct * 3.6) : null;
  var tone = stalled ? '#f87171' : (spinning ? '#4f9cf9' : '#9ca3af');
  return h('div', { class: 'v-fanicon' + (stalled ? ' stalled' : '') },
    h('div', {
      class: 'v-fanicon-ring',
      style: ringDeg != null
        ? '--ring:' + tone + ';background:conic-gradient(' + tone + ' ' + ringDeg + 'deg, var(--v-border) 0deg)'
        : '--ring:' + tone + ';background:var(--v-border)'
    },
      h('div', { class: 'v-fanicon-hub' },
        h('svg', {
          viewBox: '0 0 24 24', class: 'v-fanicon-blades',
          style: spinning ? 'animation-duration:' + period + 's' : 'animation-play-state:paused',
        },
          h('g', { fill: tone },
            h('path', { d: 'M12 12c0-3.5 1.2-6.5 3.2-8C17 2.7 18.8 3.6 19 5.5c.3 2.6-1.8 5-4.6 6.2-.6.3-1.3.4-2.4.3z' }),
            h('path', { d: 'M12 12c3.5 0 6.5 1.2 8 3.2 1.3 1.8.4 3.6-1.5 3.8-2.6.3-5-1.8-6.2-4.6-.3-.6-.4-1.3-.3-2.4z' }),
            h('path', { d: 'M12 12c0 3.5-1.2 6.5-3.2 8C7 21.3 5.2 20.4 5 18.5c-.3-2.6 1.8-5 4.6-6.2.6-.3 1.3-.4 2.4-.3z' }),
            h('path', { d: 'M12 12c-3.5 0-6.5-1.2-8-3.2C2.7 7 3.6 5.2 5.5 5c2.6-.3 5 1.8 6.2 4.6.3.6.4 1.3.3 2.4z' }),
            h('circle', { cx: 12, cy: 12, r: 2.4 }))))),
    stalled ? h('div', { class: 'v-fanicon-badge' }, '!') : null);
}

/* Tiny sparkline (no axes, no legend) for use inside cards/tiles. */
function Spark(P) {
  var ref = useRef(null);
  var plot = useRef(null);
  useEffect(function () {
    if (!ref.current) return;
    var pts = (P.points || []).filter(function (q) { return q[1] != null; });
    if (pts.length < 2) { ref.current.innerHTML = ''; return; }
    var opts = {
      width: ref.current.clientWidth || 120, height: P.height || 34,
      cursor: { show: false }, legend: { show: false },
      scales: { x: { time: false } },
      axes: [{}, {}],
      series: [{}, { stroke: P.color || PAL['v-accent'], width: 1.4, fill: withAlpha(P.color || PAL['v-accent'], .18), spanGaps: true }],
      padding: [2, 2, 2, 2],
      ms: 1,
    };
    opts.axes.forEach(function (a) { a.show = false; });
    if (plot.current) plot.current.destroy();
    plot.current = new uPlot(opts, [pts.map(function (q) { return q[0]; }), pts.map(function (q) { return q[1]; })], ref.current);
  });
  return h('div', { class: 'v-spark', ref: ref });
}

/* SVG donut with centre label + side legend. P: {segments:[{label,value,color}],
   center:{big,small}, size} */
function Donut(P) {
  var segs = (P.segments || []).filter(function (s) { return s.value > 0; });
  var total = segs.reduce(function (s, x) { return s + x.value; }, 0);
  var size = P.size || 132, th = P.thickness || 16, r = (size - th) / 2, c = 2 * Math.PI * r;
  var off = 0;
  return h('div', { class: 'v-donut-wrap' },
    h('div', { class: 'v-donut', style: 'width:' + size + 'px;height:' + size + 'px' },
      h('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size },
        h('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: 'rgba(127,127,127,.15)', 'stroke-width': th }),
        segs.map(function (s, i) {
          var frac = total ? s.value / total : 0;
          var el = h('circle', { key: i, cx: size / 2, cy: size / 2, r: r, fill: 'none',
            stroke: s.color, 'stroke-width': th,
            'stroke-dasharray': (frac * c - 2) + ' ' + (c - frac * c + 2),
            'stroke-dashoffset': -off * c,
            transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' });
          off += frac;
          return el;
        })),
      h('div', { class: 'v-donut-center' },
        h('div', { class: 'v-donut-big' }, (P.center || {}).big || ''),
        h('div', { class: 'v-donut-small' }, (P.center || {}).small || ''))),
    h('div', { class: 'v-donut-legend' },
      segs.map(function (s, i) {
        return h('div', { key: i, class: 'v-donut-key' },
          h('i', { style: 'background:' + s.color }), s.label,
          h('b', null, ' ' + s.value));
      })));
}

/* Single-value arc gauge — ring showing pct progress toward a threshold,
   with a bold center readout. Matches the "% ring with big number" gauge
   style from the reference dashboards, driven by real headroom-to-threshold
   data instead of a decorative percentage. P: {pct(0-100), color, big, small,
   size, thickness}. */
function Gauge(P) {
  var size = P.size || 96, th = P.thickness || 9, r = (size - th) / 2, c = 2 * Math.PI * r;
  var pct = Math.max(0, Math.min(100, P.pct == null ? 0 : P.pct));
  var dash = (pct / 100) * c;
  return h('div', { class: 'v-gauge', style: 'width:' + size + 'px;height:' + size + 'px' },
    h('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size },
      h('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: 'rgba(127,127,127,.15)', 'stroke-width': th }),
      h('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: P.color || PAL['v-accent'],
        'stroke-width': th, 'stroke-linecap': 'round',
        'stroke-dasharray': dash + ' ' + (c - dash),
        transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')',
        style: 'transition:stroke-dasharray .4s ease' })),
    h('div', { class: 'v-gauge-center' },
      h('div', { class: 'v-gauge-big' }, P.big || ''),
      P.small ? h('div', { class: 'v-gauge-small' }, P.small) : null));
}

/* Ranked horizontal bars. P: {rows:[{label,value(string),pct(0-100),color}], max} */
function HBars(P) {
  var rows = P.rows || [];
  if (!rows.length) return h('div', { class: 'v-empty' }, P.empty || 'No data yet.');
  return h('div', { class: 'v-hbars' },
    rows.map(function (r, i) {
      var pct = r.pct == null ? 0 : Math.max(0, Math.min(100, r.pct));
      return h('div', { key: i, class: 'v-hbar-row' },
        h('div', { class: 'v-hbar-label', title: r.label }, r.label),
        h('div', { class: 'v-hbar-track' },
          h('i', { style: 'width:' + pct + '%;background:' + (r.color || pickColor(i)) })),
        h('div', { class: 'v-hbar-val' }, r.value));
    }));
}

/* OctoPrint-style inline stats for a chart: per series — now · avg · min–max.
   P: {series:[{name,color,points}]} */
function ChartStats(P) {
  var rows = (P.series || []).map(function (s) {
    var vs = (s.points || []).map(function (q) { return q[1]; }).filter(function (v) { return v != null; });
    if (!vs.length) return null;
    var sum = vs.reduce(function (a, b) { return a + b; }, 0);
    return {
      name: s.name, color: s.color, now: vs[vs.length - 1],
      avg: sum / vs.length, min: Math.min.apply(null, vs), max: Math.max.apply(null, vs),
      fmt: s.fmt || function (v) { return Math.round(v * 10) / 10; },
    };
  }).filter(Boolean);
  if (!rows.length) return null;
  // Each series is its own bordered chip instead of run-on inline text —
  // with 5-6+ high-cardinality sensors the old plain-text "· avg · min–max"
  // strings had no visual boundary between series and read as one solid
  // unreadable line (user: "legends are broken for charts"). Current value
  // is the prominent bit; avg/range drops to a smaller secondary line.
  return h('div', { class: 'v-chart-stats' },
    rows.map(function (r, i) {
      return h('span', { key: i, class: 'v-chart-stat' },
        h('i', { style: 'background:' + r.color }),
        h('span', { class: 'v-chart-stat-body' },
          h('span', { class: 'v-chart-stat-name' }, r.name),
          h('span', { class: 'v-chart-stat-now' }, r.fmt(r.now)),
          h('span', { class: 'v-chart-stat-range' }, 'avg ' + r.fmt(r.avg) + ' · ' + r.fmt(r.min) + '–' + r.fmt(r.max))));
    }));
}

/* ------------------------------------------------------------ primitives */

function Panel(P) {
  return h('section', { class: 'v-panel' + (P.span2 ? ' v-span2' : '') },
    h('h3', null, P.title, P.hint ? h('span', { class: 'v-hint' }, P.hint) : null),
    P.children);
}

function Pill(P) {
  return h('span', { class: 'v-pill ' + (P.kind || ''), title: P.title }, P.children);
}

function StatCard(P) {
  // Icon badge is ALWAYS shown now (user: "we should have an icon for
  // CPU, instead of the circle, same for memory and so on for every
  // single card — be smart and consistent"). Cards that used to swap the
  // icon out entirely for a plain donut gauge (CPU/Memory/Storage) now
  // wrap the SAME icon badge in a thin conic-gradient progress ring
  // instead, so every card reads as "icon + label + number" at a glance
  // and the percentage is an accent around it, not a replacement.
  return h('div', { class: 'v-card' },
    h('div', { class: 'v-card-icon-wrap' },
      P.ring != null ? h('div', {
        class: 'v-card-icon-ring',
        style: '--ring:' + P.color + ';background:conic-gradient(' + P.color + ' ' +
          Math.round(Math.max(0, Math.min(100, P.ring)) * 3.6) + 'deg, var(--v-border) 0deg)',
      }) : null,
      h('div', { class: 'v-card-icon', style: 'background:color-mix(in srgb,' + P.color + ' 16%,transparent);color:' + P.color },
        h('i', { class: 'fa ' + (P.icon || 'fa-circle') }))),
    h('div', { class: 'v-card-body' },
      h('div', { class: 'v-card-label' }, P.label),
      h('div', { class: 'v-card-value ' + (P.level || '') },
        P.value, P.unit ? h('small', null, ' ' + P.unit) : null),
      P.sub ? h('div', { class: 'v-card-sub' }, P.sub) : null,
      P.ring == null && P.bar != null ? h('div', { class: 'v-bar' },
        h('i', { style: 'width:' + Math.max(0, Math.min(100, P.bar)) + '%;background:' + P.color })) : null,
      P.spark ? h('div', { class: 'v-card-spark-row' },
        h(Spark, { points: P.spark, color: P.sparkColor || P.color }),
        // Reference-style floating delta pill ("+32 New") anchored beside
        // the sparkline rather than buried in the .v-card-sub text line —
        // the one glanceable signal on the card besides the number itself.
        P.delta ? h('span', { class: 'v-card-delta ' + (P.deltaKind || '') }, P.delta) : null) : null));
}

/* uPlot wrapper. P: {series:[{name,color,points,fill(axis2,stack)}], max, floor,
   height, yFmt, thresholds:[{v,color,label}], area, stack, y2Fmt, empty}

   Every chart — single series or many — gets a "⋮" menu button (top-right)
   that toggles a stats popover: swatch + name + current/min/max/avg per
   series. uPlot's own hover legend only shows on multi-series charts and
   only while the cursor is over the plot; single-series charts (CPU %,
   Memory %) had no legend at all (user: "I can't see any legend for
   CPU & memory ... I should see 3 dots at the top ... open legend").
   The popover works identically regardless of series count, so it's the
   one legend affordance the user can rely on everywhere. */
function Chart(P) {
  var ref = useRef(null);
  var plot = useRef(null);
  var rafRef = useRef(null);
  var prevYsRef = useRef(null);   // last rendered y arrays, source for the next morph
  var redrawRef = useRef(function () {});
  var seriesRef = useRef(null); seriesRef.current = P.series || [];
  var menuState = useState(false); var menuOpen = menuState[0], setMenuOpen = menuState[1];
  var series = P.series || [];
  var n = series.reduce(function (m, s) { return Math.max(m, (s.points || []).length); }, 0);
  var minV = P.floor || 0;

  // Structural signature: only things that require destroying and rebuilding
  // the uPlot instance (series identity/count/axis, chart chrome). Anything
  // NOT in here (the actual y-values) is handled by the data effect below
  // via setData, which is what lets a new sample animate in smoothly
  // instead of every tick doing a full destroy+rebuild (user: "I should
  // see charts moving with time with animations based on adding new data
  // points" — a rebuild every render made that impossible).
  var structSig = JSON.stringify({
    empty: n < 2,
    h: P.height, area: !!P.area, stack: !!P.stack, hideLegend: !!P.hideLegend, integer: !!P.integer,
    y2: !!P.y2Fmt, y2max: P.y2Max, y2floor: P.y2Floor,
    names: series.map(function (s) { return s.name + '|' + s.color + '|' + (s.axis || 1); }),
    thrN: (P.thresholds || []).length,
    evN: (P.events || []).length,
  });

  useEffect(function () {
    if (!ref.current) return;
    if (n < 2) {
      if (plot.current) { plot.current.destroy(); plot.current = null; }
      ref.current.innerHTML = '<div class="v-empty">' +
        (P.empty || 'Not enough samples yet — history builds up each minute.') + '</div>';
      return;
    }

    var fmt1 = function (s, v) {
      if (s.fmt) return s.fmt(v);
      if (v == null) return '';
      return Math.round(v * 10) / 10 + (s.unit || '');
    };
    // In stacked mode the legend shows each band's own value, not the
    // cumulative. Reads from seriesRef so it always reflects the CURRENT
    // points even though this closure was captured at structural-build
    // time, not on the latest data tick.
    var legendVal = function (u, si) {
      var s = seriesRef.current[si];
      var raw = s && s.points[u.cursor.idx];
      return raw && raw[1] != null ? fmt1(s, raw[1]) : '';
    };

    var opts = {
      width: ref.current.clientWidth || 600,
      height: P.height || 150,
      legend: { show: P.hideLegend ? false : series.length > 1, live: false,
        markers: { width: 0, fill: function (u, si) { return u.series[si].stroke(u, si); } } },
      cursor: { sync: { key: 'vit' } },
      scales: {
        x: { time: false },
        y: { range: [minV, minV + 1] }, // real range applied by the data effect right after
        y2: P.y2Fmt ? { range: [P.y2Floor != null ? P.y2Floor : 0, P.y2Max != null ? P.y2Max : 100] } : undefined,
      },
      axes: [
        { stroke: '#8889', grid: { stroke: '#8882', width: 1 }, ticks: { show: false },
          space: 70, size: 26,
          values: function (u, sp) { return sp.map(ts); } },
        { stroke: '#8889', grid: { stroke: '#8882', width: 1 }, ticks: { show: false }, size: 46,
          incrs: P.yFmt === bytes ? BYTE_INCRS : (P.integer ? INT_INCRS : undefined),
          values: function (u, sp) {
            // Never print the same label twice down an axis (a 0..1 range
            // with 5 splits rounded to integers reads "1 1 1 0 0").
            var seen = {};
            return sp.map(function (v) {
              var s = P.yFmt ? P.yFmt(v) : v;
              if (seen[s]) return '';
              seen[s] = true; return s;
            });
          } },
      ],
      series: [{}].concat(series.map(function (s, i) {
        var o = {
          label: s.name, stroke: s.color, width: P.stack ? 1 : 1.7,
          spanGaps: true, points: { show: false },
          value: function (u, v) { return legendVal(u, i + 1); },
        };
        if (P.area || s.fill || P.stack) {
          o.fill = P.stack ? withAlpha(s.color, 0.55) : gradientFill(s.color, 0.38);
        }
        if (s.axis === 2) { o.scale = 'y2'; o.stroke = s.color; }
        return o;
      })),
      padding: [8, 10, 0, P.y2Fmt ? 46 : 0],
    };
    if (P.y2Fmt) {
      opts.axes.push({ stroke: '#8886', grid: { show: false }, ticks: { show: false }, side: 1, size: 44,
        scale: 'y2', values: function (u, sp) { return sp.map(function (v) { return P.y2Fmt(v); }); } });
      opts.padding[1] = 52;
    }

    // Seed with whatever data is available right now — the data effect
    // (below, runs immediately after this commit) will call setData again
    // on the very next tick, but uPlot needs a valid initial dataset of the
    // right shape to construct at all.
    var xs0 = series[0].points.map(function (q) { return q[0]; });
    var ys0 = series.map(function (s) { return s.points.map(function (q) { return q[1]; }); });
    if (P.stack) {
      for (var si0 = 1; si0 < ys0.length; si0++) {
        ys0[si0] = ys0[si0].map(function (v, i) {
          var prev = ys0[si0 - 1][i];
          return v == null ? prev : (prev == null ? v : v + prev);
        });
      }
    }

    plot.current = new uPlot(opts, [xs0].concat(ys0), ref.current);
    prevYsRef.current = null; // fresh instance — first data-effect tick paints instantly, no morph

    var ro = new ResizeObserver(function () { redrawRef.current(); });
    ro.observe(ref.current);
    var onR = function () {
      if (plot.current && ref.current) {
        plot.current.setSize({ width: ref.current.clientWidth, height: opts.height });
        redrawRef.current();
      }
    };
    window.addEventListener('resize', onR);
    return function () {
      window.removeEventListener('resize', onR);
      ro.disconnect();
      if (plot.current) { plot.current.destroy(); plot.current = null; }
    };
  }, [structSig]);

  // Data effect: runs on every render (series point arrays are new objects
  // each poll tick). Pushes new values into the EXISTING uPlot instance via
  // setData rather than rebuilding it — destroying/recreating uPlot every
  // ~10s poll is what made charts "jump cut" instead of visibly moving.
  // When the data shape matches the previous tick (same series count, same
  // window length) the new values are eased in over a few animation
  // frames; a shape change (window grew/shrank) just snaps in immediately
  // since there's no meaningful point-to-point correspondence to morph.
  useEffect(function () {
    if (!plot.current || n < 2) return;
    var xs = series[0].points.map(function (q) { return q[0]; });
    var ys = series.map(function (s) { return s.points.map(function (q) { return q[1]; }); });
    if (P.stack) {
      for (var si = 1; si < ys.length; si++) {
        ys[si] = ys[si].map(function (v, i) {
          var prev = ys[si - 1][i];
          return v == null ? prev : (prev == null ? v : v + prev);
        });
      }
    }

    var maxV = P.max;
    if (!maxV) {
      maxV = 0;
      ys.forEach(function (a) { a.forEach(function (v) { if (v != null && v > maxV) maxV = v; }); });
      maxV = maxV * 1.15;
    }
    if (!maxV || maxV <= minV) maxV = minV + (P.integer ? 4 : 1);
    var thresholds = P.thresholds || [];
    thresholds.forEach(function (t) {
      if (t.v != null && t.v > maxV) maxV = t.v * 1.06;
    });

    var overlayLayer = function () {
      return ref.current ? ref.current.querySelector('.u-over') : null;
    };
    var drawThresholds = function () {
      var over = overlayLayer();
      if (!over || !plot.current) return;
      over.querySelectorAll('.v-th-line').forEach(function (el) { el.remove(); });
      thresholds.forEach(function (t) {
        if (t.v == null || t.v < minV || t.v > maxV) return;
        var y = plot.current.valToPos(t.v, 'y');
        if (y == null || !isFinite(y)) return;
        var el = document.createElement('div');
        el.className = 'v-th-line';
        el.style.top = y + 'px';
        el.style.borderColor = t.color || '#f87171';
        if (t.label) el.setAttribute('data-label', t.label);
        over.appendChild(el);
      });
    };

    var events = P.events || [];
    var evColor = { critical: '#f87171', alert: '#f87171', warning: '#fbbf24', info: '#60a5fa' };
    var drawEvents = function () {
      var over = overlayLayer();
      if (!over || !plot.current) return;
      over.querySelectorAll('.v-ev-marker').forEach(function (el) { el.remove(); });
      var u = plot.current;
      events.forEach(function (e) {
        if (e.t < xs[0] || e.t > xs[xs.length - 1]) return;
        var x = u.valToPos(e.t, 'x');
        if (x == null || x < 0 || x > u.bbox.width / devicePixelRatio) return;
        var el = document.createElement('div');
        el.className = 'v-ev-marker';
        el.style.left = x + 'px';
        var color = evColor[e.severity] || evColor.info;
        el.style.borderColor = color;
        el.style.color = color;
        el.title = e.label;
        var dot = document.createElement('div');
        dot.className = 'v-ev-dot';
        el.appendChild(dot);
        if (P.onEventClick) el.addEventListener('click', function () { P.onEventClick(e); });
        over.appendChild(el);
      });
    };
    redrawRef.current = function () { drawThresholds(); drawEvents(); };

    if (rafRef.current) { cancelAnimationFrame(rafRef.current); rafRef.current = null; }
    var fromYs = prevYsRef.current;
    var canAnimate = fromYs && fromYs.length === ys.length &&
      fromYs[0] && ys[0] && fromYs[0].length === ys[0].length;
    if (canAnimate) {
      var dur = 260, start = null;
      var frame = function (ts) {
        if (start == null) start = ts;
        var t = Math.min(1, (ts - start) / dur);
        var eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
        var mixed = ys.map(function (arr, si2) {
          return arr.map(function (v, i) {
            var f = fromYs[si2][i];
            if (v == null || f == null) return v;
            return f + (v - f) * eased;
          });
        });
        if (plot.current) plot.current.setData([xs].concat(mixed), false);
        if (t < 1) { rafRef.current = requestAnimationFrame(frame); }
        else {
          rafRef.current = null;
          if (plot.current) plot.current.setData([xs].concat(ys), false);
          redrawRef.current();
        }
      };
      rafRef.current = requestAnimationFrame(frame);
    } else {
      plot.current.setData([xs].concat(ys), false);
    }
    plot.current.setScale('y', { min: minV, max: maxV });
    redrawRef.current();
    prevYsRef.current = ys;
  });

  // Per-series current/min/max/avg for the legend popover — computed from
  // the same points the chart renders, so the popover numbers always match
  // what's on screen even mid-zoom/resize.
  var seriesStats = series.map(function (s) {
    var vals = (s.points || []).map(function (q) { return q[1]; }).filter(function (v) { return v != null; });
    var fmt = function (v) {
      var r = Math.round(v * 10) / 10;
      return s.fmt ? s.fmt(r) : (P.yFmt ? P.yFmt(r) : (r + (s.unit || '')));
    };
    if (!vals.length) return { name: s.name, color: s.color, current: '—', min: '—', max: '—', avg: '—' };
    return {
      name: s.name, color: s.color,
      current: fmt(vals[vals.length - 1]),
      min: fmt(Math.min.apply(null, vals)),
      max: fmt(Math.max.apply(null, vals)),
      avg: fmt(vals.reduce(function (a, b) { return a + b; }, 0) / vals.length),
    };
  });

  return h('div', { class: 'v-chart-wrap' },
    series.length ? h('button', {
      class: 'v-chart-menu-btn', title: 'Legend & stats', 'aria-label': 'Legend & stats',
      onClick: function (e) { e.stopPropagation(); setMenuOpen(!menuOpen); },
    }, '⋮') : null,
    menuOpen ? h('div', { class: 'v-chart-menu-backdrop', onClick: function () { setMenuOpen(false); } }) : null,
    menuOpen ? h('div', { class: 'v-chart-menu' },
      seriesStats.length ? seriesStats.map(function (s, i) {
        return h('div', { key: i, class: 'v-chart-menu-row' },
          h('i', { class: 'v-chart-menu-swatch', style: 'background:' + s.color }),
          h('span', { class: 'v-chart-menu-name' }, s.name),
          h('span', { class: 'v-chart-menu-vals' },
            h('b', null, s.current), ' · min ' + s.min + ' · max ' + s.max + ' · avg ' + s.avg));
      }) : h('div', { class: 'v-chart-menu-row muted' }, 'No series yet.')
    ) : null,
    h('div', { class: 'v-chart', ref: ref }));
}

function topKeys(pts, field, idx, n) {
  var last = pts[pts.length - 1];
  if (!last || !last[field]) return [];
  var m = last[field];
  return Object.keys(m).filter(function (k) { return m[k] && m[k][idx] != null; })
    .sort(function (a, b) { return (m[b][idx] || 0) - (m[a][idx] || 0); }).slice(0, n);
}

/* --------------------------------------------------------------- tables */

function Table(P) { return h('div', { class: P.noScroll ? 'v-scroll v-scroll-off' : 'v-scroll' }, h('table', null, P.children)); }

function ContainerTable(P) {
  var list = ((P.d.docker || {}).containers || []).slice();
  if (P.compact) list = list.slice(0, 9);
  if (!list.length) return h('div', { class: 'v-empty' }, 'No containers reported.');
  return h(Table, { noScroll: !P.compact },
    h('tr', null, h('th', null, 'Container'), h('th', null, 'State'), h('th', null, 'Image'),
      h('th', { class: 'num' }, 'CPU'), h('th', { class: 'num' }, 'Memory'),
      h('th', { class: 'num' }, 'Mem %'), h('th', null, 'Uptime'),
      P.compact ? null : h('th', null, 'Actions')),
    list.map(function (c) {
      var run = c.state === 'running';
      return h('tr', { key: c.name },
        h('td', { class: 'v-name' }, c.name),
        h('td', null, h(Pill, { kind: run ? 'run' : 'stop' }, run ? 'running' : c.state)),
        h('td', { class: 'muted' }, c.image),
        h('td', { class: 'num ' + lvl(c.cpu, 150, 300) }, c.cpu == null ? '—' : c.cpu.toFixed(1) + '%'),
        h('td', { class: 'num' }, c.mem || bytes(c.mem_bytes)),
        h('td', { class: 'num' }, c.mem_pct == null ? '—' : c.mem_pct.toFixed(2) + '%'),
        h('td', { class: 'muted' }, (c.status || '').replace(/^Up\s*/, '') || '—'),
        P.compact ? null : h('td', null, h(DockerActions, { name: c.name, running: run })));
    }));
}

/* --------------------------------------------------------------- actions */

/** Fire a control action (docker/VM) with a confirm step for anything that
 *  interrupts a running service, CSRF token attached, and a brief inline
 *  status while it runs. `onDone` lets the caller trigger a data refresh. */
function useAction() {
  var s = useState({}); var busy = s[0], setBusy = s[1];
  var run = function (op, target, opts) {
    opts = opts || {};
    if (opts.confirm && !window.confirm(opts.confirm)) return;
    setBusy(function (b) { var n = merge(b, {}); n[target] = op; return n; });
    var body = new URLSearchParams({ op: op, target: target, csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT.replace('/ajax.php', '/actions.php'), { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setBusy(function (b) { var n = merge(b, {}); delete n[target]; return n; });
        if (!j || !j.ok) window.alert('Action failed: ' + ((j && j.error) || 'unknown error'));
        if (opts.onDone) opts.onDone(j);
      })
      .catch(function (e) {
        setBusy(function (b) { var n = merge(b, {}); delete n[target]; return n; });
        window.alert('Action failed: ' + e.message);
      });
  };
  return [busy, run];
}

function DockerActions(P) {
  var s = useAction(); var busy = s[0], run = s[1];
  var isBusy = !!busy[P.name];
  return h('div', { class: 'v-row-actions' },
    P.running
      ? [
          h('button', { key: 'restart', class: 'v-btn xs', disabled: isBusy, title: 'Restart',
            onClick: function () { run('docker_restart', P.name, { confirm: 'Restart ' + P.name + '?' }); } },
            h('i', { class: 'fa fa-refresh' })),
          h('button', { key: 'stop', class: 'v-btn xs danger', disabled: isBusy, title: 'Stop',
            onClick: function () { run('docker_stop', P.name, { confirm: 'Stop ' + P.name + '?' }); } },
            h('i', { class: 'fa fa-stop' }))
        ]
      : h('button', { key: 'start', class: 'v-btn xs primary', disabled: isBusy, title: 'Start',
          onClick: function () { run('docker_start', P.name, {}); } },
          h('i', { class: 'fa fa-play' })),
    isBusy ? h('i', { class: 'fa fa-spinner fa-spin', style: 'margin-left:6px' }) : null);
}

function VmActions(P) {
  var s = useAction(); var busy = s[0], run = s[1];
  var isBusy = !!busy[P.name];
  var running = P.state === 'running';
  return h('div', { class: 'v-row-actions' },
    running
      ? [
          h('button', { key: 'restart', class: 'v-btn xs', disabled: isBusy, title: 'Reboot (graceful)',
            onClick: function () { run('vm_restart', P.name, { confirm: 'Reboot VM ' + P.name + '?' }); } },
            h('i', { class: 'fa fa-refresh' })),
          h('button', { key: 'stop', class: 'v-btn xs', disabled: isBusy, title: 'Shutdown (graceful)',
            onClick: function () { run('vm_stop', P.name, { confirm: 'Shut down VM ' + P.name + ' gracefully?' }); } },
            h('i', { class: 'fa fa-power-off' })),
          h('button', { key: 'force', class: 'v-btn xs danger', disabled: isBusy, title: 'Force off',
            onClick: function () { run('vm_force_stop', P.name, { confirm: 'Force off VM ' + P.name + '? Unsaved data will be lost, same as pulling the power.' }); } },
            h('i', { class: 'fa fa-bolt' }))
        ]
      : h('button', { key: 'start', class: 'v-btn xs primary', disabled: isBusy, title: 'Start',
          onClick: function () { run('vm_start', P.name, {}); } },
          h('i', { class: 'fa fa-play' })),
    isBusy ? h('i', { class: 'fa fa-spinner fa-spin', style: 'margin-left:6px' }) : null);
}

function VmTable(P) {
  var vms = P.vms || {};
  if (!vms.available) return h('div', { class: 'v-empty' },
    h('i', { class: 'fa fa-info-circle' }), 'virsh not available on this system.');
  var list = vms.list || [];
  if (!list.length) return h('div', { class: 'v-empty' }, 'No virtual machines defined.');
  return h(Table, null,
    h('tr', null, h('th', null, 'VM'), h('th', null, 'State'), h('th', { class: 'num' }, 'vCPUs'),
      h('th', { class: 'num' }, 'Memory'), h('th', null, 'Autostart'), h('th', null, 'Actions')),
    list.map(function (v) {
      var run = v.state === 'running';
      return h('tr', { key: v.name },
        h('td', { class: 'v-name' }, v.name),
        h('td', null, h(Pill, { kind: run ? 'run' : 'stop' }, v.state)),
        h('td', { class: 'num' }, v.cpus == null ? '—' : String(v.cpus)),
        h('td', { class: 'num' }, v.mem_kib ? bytes(v.mem_kib * 1024) : '—'),
        h('td', { class: 'muted' }, v.autostart ? 'yes' : 'no'),
        h('td', null, h(VmActions, { name: v.name, state: v.state })));
    }));
}

function TopTable(P) {
  var list = P.d.top || [];
  if (!list.length) return h('div', { class: 'v-empty' }, 'No process data.');
  return h(Table, null,
    h('tr', null, h('th', null, 'Process'), h('th', { class: 'num' }, 'CPU'),
      h('th', { class: 'num' }, 'Mem %'), h('th', { class: 'num' }, 'RSS')),
    list.map(function (p, i) {
      return h('tr', { key: i },
        h('td', { class: 'v-name v-mono' }, p.name),
        h('td', { class: 'num ' + lvl(p.cpu, 50, 100) }, p.cpu.toFixed(1) + '%'),
        h('td', { class: 'num' }, p.mem.toFixed(1) + '%'),
        h('td', { class: 'num muted' }, bytes(p.rss)));
    }));
}

function SmartTable(P) {
  var keys = Object.keys(P.d.smart || {});
  if (!keys.length) return h('div', { class: 'v-empty' }, 'No SMART data cached yet.');
  var rows = keys.map(function (k) { return P.d.smart[k]; });
  rows.sort(function (a, b) { return (b.temp || 0) - (a.temp || 0); });
  var growing = function (r) {
    var g = r.growth_30d || {};
    return (g.reallocated || 0) > 0 || (g.pending || 0) > 0 || (g.crc || 0) > 0;
  };
  var flagged = rows.some(growing);
  var wearOf = function (r) { return r.nvme_pct_used != null ? r.nvme_pct_used : r.ssd_wear_pct; };
  return h('div', null,
    h(Table, null,
      h('tr', null, h('th', null, 'Disk'), h('th', null, 'Health'), h('th', { class: 'num' }, 'Temp'),
        h('th', { class: 'num' }, 'Power-on h'), h('th', { class: 'num' }, 'Realloc'),
        h('th', { class: 'num' }, 'Pending'), h('th', { class: 'num' }, 'Uncorr'),
        h('th', { class: 'num' }, 'CRC'), h('th', { class: 'num' }, 'Growth (30d)'),
        h('th', { class: 'num' }, 'Wear')),
      rows.map(function (r) {
        var hcls = r.health === 'PASSED' ? 'ok' : (r.health ? 'crit' : 'muted');
        var g = r.growth_30d || {};
        var isGrowing = growing(r);
        var growthLabel = g.days == null ? 'watching' : (isGrowing ? ('+' + [g.reallocated, g.pending, g.crc].filter(function (v) { return v; }).reduce(function (a, b) { return a + b; }, 0) + ' in ' + g.days + 'd') : 'stable');
        var wear = wearOf(r);
        return h('tr', { key: r.dev || r.name },
          h('td', { class: 'v-name' }, r.name),
          h('td', null, h('span', { class: hcls }, r.health || 'n/a')),
          h('td', { class: 'num ' + lvl(r.temp, 45, 55) }, r.temp == null ? '—' : r.temp + '°'),
          h('td', { class: 'num muted' }, r.hours == null ? '—' : r.hours.toLocaleString()),
          h('td', { class: 'num ' + (r.reallocated ? (isGrowing ? 'crit' : 'warn') : 'muted') }, r.reallocated == null ? '—' : String(r.reallocated)),
          h('td', { class: 'num ' + (r.pending ? (isGrowing ? 'crit' : 'warn') : 'muted') }, r.pending == null ? '—' : String(r.pending)),
          h('td', { class: 'num ' + (r.uncorrectable ? 'crit' : 'muted') }, r.uncorrectable == null ? '—' : String(r.uncorrectable)),
          h('td', { class: 'num ' + (r.crc ? (isGrowing ? 'crit' : 'warn') : 'muted') }, r.crc == null ? '—' : String(r.crc)),
          h('td', { class: 'num ' + (isGrowing ? 'crit' : 'muted') }, growthLabel),
          h('td', { class: 'num ' + lvl(wear, 80, 90) }, wear == null ? '—' : wear + '%'));
      })),
    flagged ? h('div', { class: 'v-warnnote' },
      h('i', { class: 'fa fa-exclamation-triangle' }),
      ' Reallocated, pending, or CRC counters actively growing — check disk/cabling.') : null);
}

function DiskTable(P) {
  var a = P.d.array || {};
  var disks = (a.parity || []).concat(a.data || [], a.cache || []);
  if (!disks.length) return h('div', { class: 'v-empty' }, 'No array devices found.');
  return h(Table, null,
    h('tr', null, h('th', null, 'Disk'), h('th', null, 'Type'), h('th', null, 'Device'),
      h('th', { class: 'num' }, 'Temp'), h('th', { class: 'num' }, 'Size'),
      h('th', { class: 'num' }, 'Used'), h('th', { class: 'num' }, 'Fill'),
      h('th', { class: 'num' }, 'Errors'), h('th', null, 'State')),
    disks.map(function (x) {
      return h('tr', { key: x.name },
        h('td', { class: 'v-name' }, x.name),
        h('td', null, x.type),
        h('td', { class: 'muted v-mono' }, x.device || '—'),
        h('td', { class: 'num ' + lvl(x.temp, 45, 55) }, x.temp == null ? '—' : x.temp + '°'),
        h('td', { class: 'num' }, bytes(x.size)),
        h('td', { class: 'num' }, x.fsUsed ? bytes(x.fsUsed) : '—'),
        h('td', { class: 'num ' + lvl(x.usedPct, 85, 95) }, x.usedPct ? x.usedPct.toFixed(1) + '%' : '—'),
        h('td', { class: 'num ' + (x.numErrors ? 'crit' : 'muted') }, String(x.numErrors || 0)),
        h('td', null, h(Pill, { kind: x.spundown ? 'stop' : 'run' }, x.spundown ? 'spun down' : 'active')));
    }));
}

/* ----------------------------------------------------------------- tabs */

var TABS = [
  { id: 'dash',   label: 'Dashboard',     icon: 'fa-tachometer' },
  { id: 'array',  label: 'Array & Disks', icon: 'fa-hdd-o' },
  { id: 'docker', label: 'Docker',        icon: 'fa-cubes' },
  { id: 'net',    label: 'Network',       icon: 'fa-exchange' },
  { id: 'sys',    label: 'System',        icon: 'fa-microchip' },
  { id: 'shares', label: 'Shares',        icon: 'fa-folder-open-o' },
  { id: 'hw',     label: 'Hardware',      icon: 'fa-tv' },
  { id: 'power',  label: 'Power',         icon: 'fa-bolt' },
  { id: 'kb',     label: 'Knowledge',     icon: 'fa-book' },
  { id: 'research', label: 'Research',   icon: 'fa-flask' },
  { id: 'cleanup', label: 'Cleanup',     icon: 'fa-trash-o' },
  { id: 'settings', label: 'Settings',    icon: 'fa-cog' }
];

function App() {
  var s1 = useState(null), payload = s1[0], setPayload = s1[1];
  var s2 = useState(360), range = s2[0], setRange = s2[1];
  var s3 = useState('dash'), tab = s3[0], setTab = s3[1];
  var s4 = useState('loading'), status = s4[0], setStatus = s4[1];
  var s5 = useState(null), daily = s5[0], setDaily = s5[1];
  var s6 = useState([]), findings = s6[0], setFindings = s6[1];
  var s7 = useState(false), drawerOpen = s7[0], setDrawerOpen = s7[1];
  var s8 = useState(false), findingsOpen = s8[0], setFindingsOpen = s8[1];
  var s9 = useState([]), chartEvents = s9[0], setChartEvents = s9[1];

  var load = function (force) {
    setStatus(function (s) { return s === 'loading' ? s : 'busy'; });
    fetch(ENDPOINT + '?action=' + (force ? 'refresh' : 'data'), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setPayload(j); setStatus('ok'); } })
      .catch(function () { setStatus('error'); });
  };

  var loadFindings = function () {
    fetch(ENDPOINT + '?action=findings', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setFindings(j.findings || []); })
      .catch(function () {});
  };

  useEffect(function () {
    resolvePalette();
    load(false);
    loadFindings();
    var iv2 = setInterval(loadFindings, 60000);
    return function () { clearInterval(iv2); };
  }, []);

  var d = payload && payload.data;

  // Dashboard poll cadence (default 10s) is user-configurable in Settings
  // and comes back on every payload as data.ui_refresh_seconds — re-arm the
  // timer whenever it changes so a saved setting takes effect on the next
  // tick without requiring a page reload.
  var refreshSeconds = (d && d.ui_refresh_seconds) || 10;
  // P18-09: refresh pauses while the tab is hidden (Page Visibility API) —
  // no wasted polling of a dashboard nobody is looking at; resumes (and
  // immediately re-syncs) on return.
  useEffect(function () {
    var hidden = false;
    var onVis = function () {
      var nowHidden = document.visibilityState === 'hidden';
      if (nowHidden === hidden) return;
      hidden = nowHidden;
      if (!hidden) load(false);
    };
    document.addEventListener('visibilitychange', onVis);
    return function () { document.removeEventListener('visibilitychange', onVis); };
  }, []);
  useEffect(function () {
    var iv = setInterval(function () {
      if (document.visibilityState === 'hidden') return; // paused
      load(false);
    }, refreshSeconds * 1000);
    return function () { clearInterval(iv); };
  }, [refreshSeconds]);

  useEffect(function () {
    fetch(ENDPOINT + '?action=daily&days=30', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { setDaily((j && j.daily) || []); })
      .catch(function () { setDaily([]); });
  }, []);

  // P15-04: event markers, refreshed on the same cadence as findings —
  // events are sparse and slow-changing, no need for the 10s poll cadence.
  useEffect(function () {
    var loadEvents = function () {
      fetch(ENDPOINT + '?action=chart_events&hours=24', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) setChartEvents(j.events || []); })
        .catch(function () {});
    };
    loadEvents();
    var iv3 = setInterval(loadEvents, 60000);
    return function () { clearInterval(iv3); };
  }, []);

  if (d && d.csrf_token) window.__V_CSRF__ = d.csrf_token;
  var ring = (payload && payload.ring) || [];
  // P18-07: the range selector promises HOURS — count points by their real
  // timestamps, never by "1 sample = 1 minute" (a 5-min INTERVAL made '1h'
  // silently show 5 hours). Timespan from the newest sample backwards.
  var newestT = ring.length ? ring[ring.length - 1].t : 0;
  var rangeT = newestT - range * 60;             // range is in minutes
  var pts = ring.filter(function (p) { return p.t >= rangeT; });
  if (!pts.length && ring.length) pts = [ring[ring.length - 1]]; // sparse ring: keep the last sample
  var props = { d: d, pts: pts, range: range, daily: daily, findings: findings, chartEvents: chartEvents };

  return h('div', null,
    h('div', { class: 'v-hero' },
      h('div', { class: 'v-hero-row' },
        h('div', { class: 'v-title' },
          h('i', { class: 'fa fa-heartbeat v-logo' }),
          h('div', null,
            h('h2', null, 'Vitals'),
            h('span', { class: 'v-sub' },
              h('span', { class: 'v-dot' + (status === 'error' || (d && age(d.time) > 180) ? ' crit' : '') }),
              status === 'error' ? 'collection error'
                : !d ? 'loading…'
                : [d.system.name, d.system.version].filter(Boolean).join(' · ') + ' · sampled ' + ageLabel(d.time)))),
        h('div', { class: 'v-toolbar' },
          h('div', { class: 'v-seg', role: 'group', 'aria-label': 'Time range' },
            [[60, '1h'], [360, '6h'], [1440, '24h']].map(function (o) {
              return h('button', { key: o[0], class: 'v-seg-btn' + (range === o[0] ? ' active' : ''),
                onClick: function () { setRange(o[0]); } }, o[1]);
            })),
          h('div', { class: 'v-iconbar' },
            h('button', { class: 'v-ibtn', title: 'Refresh now', onClick: function () { load(true); } },
              h('i', { class: 'fa fa-refresh' })),
            h(AiBell, { findings: findings, open: findingsOpen, onClick: function () { setFindingsOpen(!findingsOpen); } }),
            h('button', { class: 'v-ibtn' + (drawerOpen ? ' on' : ''), title: 'Critical system logs',
              onClick: function () { setDrawerOpen(!drawerOpen); } },
              h('i', { class: 'fa fa-file-text-o' }))))),

      h('div', { class: 'v-tabs' },
        TABS.map(function (t) {
          return h('button', { key: t.id, class: 'v-tab' + (tab === t.id ? ' on' : ''),
            onClick: function () { setTab(t.id); } },
            h('i', { class: 'fa ' + t.icon }), h('span', null, t.label));
        }))),

    h('div', { class: 'v-body' },
      !d ? h('div', { class: 'v-panel' }, h('div', { class: 'v-empty' }, 'Loading…'))
        : tab === 'dash'   ? h(DashTab,   props)
        : tab === 'array'  ? h(ArrayTab,  props)
        : tab === 'docker' ? h(DockerTab, props)
        : tab === 'net'    ? h(NetTab,    props)
        : tab === 'sys'    ? h(SysTab,    props)
        : tab === 'shares' ? h(SharesTab, props)
        : tab === 'hw'     ? h(HwTab,     props)
        : tab === 'power'  ? h(PowerTab,  props)
        : tab === 'kb'     ? h(KbTab,     props)
        : tab === 'research' ? h(ResearchTab, {})
        : tab === 'cleanup' ? h(CleanupTab, {})
        : tab === 'settings' ? h(SettingsTab, {}) : null),

    h('div', { class: 'v-foot' },
      'unraid-vitals · samples retained on flash'),
    h(LogDrawer, { open: drawerOpen, onClose: function () { setDrawerOpen(false); } }),
    h(FindingsDrawer, { findings: findings, open: findingsOpen, onClose: function () { setFindingsOpen(false); } }));
}

/* ------------------------------------------------------------ log drawer */

var LOG_LEVEL_RE = /\b(panic|emerg|critical|crit|alert|error|err|fail(?:ed|ure)?|fatal|denied|refused|timeout(?:d| out)?|offline|corrupt|unrecoverable|segfault|oom|out of memory|i\/o error|reset|unhealthy|exited|killed|abort(?:ed)?)\b/i;

function LogDrawer(P) {
  var s1 = useState('syslog'); var src = s1[0], setSrc = s1[1];
  var s2 = useState(null); var data = s2[0], setData = s2[1];
  var s3 = useState(true); var criticalOnly = s3[0], setCriticalOnly = s3[1];
  var s4 = useState(''); var q = s4[0], setQ = s4[1];
  var s5 = useState(true); var autoScroll = s5[0], setAutoScroll = s5[1];
  var bodyRef = useRef(null);

  var loadLogs = function (source) {
    fetch(ENDPOINT + '?action=logs&source=' + encodeURIComponent(source) + '&lines=500', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setData(j); })
      .catch(function () { setData({ entries: [], error: 'fetch failed' }); });
  };
  useEffect(function () { loadLogs(src); }, [src]);
  useEffect(function () {
    if (!P.open) return;
    var iv = setInterval(function () { loadLogs(src); }, 20000);
    return function () { clearInterval(iv); };
  }, [P.open, src]);

  var entries = (data && data.entries) || [];
  var filtered = entries.filter(function (e) {
    if (criticalOnly && !LOG_LEVEL_RE.test(e.line)) return false;
    if (q && e.line.toLowerCase().indexOf(q.toLowerCase()) === -1) return false;
    return true;
  });

  useEffect(function () {
    if (autoScroll && bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [filtered.length, autoScroll]);

  var sources = (data && data.sources) || [{ id: 'syslog', label: 'System (syslog)' }, { id: 'dmesg', label: 'Kernel (dmesg)' }, { id: 'docker', label: 'Docker daemon' }];
  var nCrit = entries.filter(function (e) { return LOG_LEVEL_RE.test(e.line); }).length;

  return P.open ? h('div', null,
    h('div', { class: 'v-drawer-backdrop', onClick: P.onClose }),
    h('div', { class: 'v-drawer' },
      h('div', { class: 'v-drawer-head' },
        h('i', { class: 'fa fa-file-text-o' }),
        h('span', { class: 'v-drawer-title' }, 'System logs'),
        h('span', { class: 'v-ai-agent' + (nCrit ? ' warn' : '') }, nCrit + ' critical'),
        h('button', { class: 'v-btn xs', style: 'margin-left:auto', onClick: P.onClose }, '✕')),
      h('div', { class: 'v-drawer-tools' },
        h('select', { class: 'v-select', value: src, onChange: function (e) { setSrc(e.target.value); setData(null); } },
          sources.map(function (s) { return h('option', { key: s.id, value: s.id }, s.label); })),
        h('input', { class: 'v-input v-drawer-q', placeholder: 'filter…', value: q,
          onInput: function (e) { setQ(e.target.value); } }),
        h('button', { class: 'v-chip' + (criticalOnly ? ' on' : ''), style: 'border-radius:6px',
          onClick: function () { setCriticalOnly(!criticalOnly); } }, 'critical only'),
        h('button', { class: 'v-chip' + (autoScroll ? ' on' : ''), style: 'border-radius:6px',
          onClick: function () { setAutoScroll(!autoScroll); } }, 'follow tail')),
      h('div', { class: 'v-drawer-body', ref: bodyRef },
        !data ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-spinner fa-spin' }), ' Loading log…')
        : data.error ? h('div', { class: 'v-empty' }, data.error)
        : !filtered.length ? h('div', { class: 'v-empty' }, criticalOnly || q ? 'No matching lines in this view.' : 'Log is empty.')
        : filtered.map(function (e, i) {
            var crit = LOG_LEVEL_RE.test(e.line);
            return h('div', { key: i, class: 'v-log-line' + (crit ? ' crit' : '') },
              e.ts ? h('span', { class: 'v-log-ts' }, e.ts) : null,
              h('span', { class: 'v-log-msg' }, e.line));
          })),
      h('div', { class: 'v-drawer-foot' },
        filtered.length + ' of ' + entries.length + ' lines · newest at the bottom · refreshes every 20 s'))) : null;
}

/* ------------------------------------------------------------ dashboard */

function DashTab(P) {
  var d = P.d, pts = P.pts, a = d.array || {}, t = a.totals || {}, load = d.load || {};
  var temps = [];
  (a.data || []).concat(a.parity || [], a.cache || []).forEach(function (x) {
    if (x.temp != null) temps.push(x.temp);
  });
  var tMax = temps.length ? Math.max.apply(null, temps) : null;
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var cores = load.cores || 1;

  // P15-03: anomaly detection against an hour-of-week baseline — a
  // dashboard-level signal (cross-metric), not tied to any one tab.
  var anState = useState(null); var anomaly = anState[0], setAnomaly = anState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=anomaly', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setAnomaly(j); })
      .catch(function () { setAnomaly({ status: 'error', findings: [] }); });
  }, []);

  var cpuSeries = [
    { name: 'CPU %', color: PAL['v-cpu'], points: pick(function (p) { return p.cpu; }) },
    { name: 'Memory %', color: PAL['v-mem'], points: pick(function (p) { return p.mem; }) },
    { name: 'Load (norm)', color: PAL['v-load'],
      points: pick(function (p) { return p.load == null ? null : Math.min(100, p.load * 100 / cores); }) }
  ];

  var ifTop = topKeys(pts, 'net', 0, 2);
  var netSeries = ifTop.length
    ? ifTop.reduce(function (acc, ifn, i) {
        var c = pickColor(i);
        acc.push({ name: ifn + ' down', color: c,
          points: pick(function (p) { return p.net && p.net[ifn] ? p.net[ifn][0] : null; }) });
        acc.push({ name: ifn + ' up', color: c,
          points: pick(function (p) { return p.net && p.net[ifn] ? p.net[ifn][1] : null; }) });
        return acc;
      }, [])
    : [{ name: 'RX', color: PAL['v-rx'], points: pick(function (p) { return p.net_rx; }) },
       { name: 'TX', color: PAL['v-tx'], points: pick(function (p) { return p.net_tx; }) }];

  var ctrTop = topKeys(pts, 'ctr', 0, 6);
  var ctrSeries = ctrTop.map(function (name, i) {
    return { name: name, color: pickColor(i),
      points: pick(function (p) { return p.ctr && p.ctr[name] ? p.ctr[name][0] : null; }) };
  });

  // Grafana-style sparklines under the headline cards.
  var sparkOf = function (f, color) {
    var pts2 = pts.map(function (p) { return [p.t, f(p)]; });
    return { spark: pts2, sparkColor: color };
  };
  // Reference-card delta pill: first-vs-last value across the visible
  // window, signed and rounded to whole points so it reads like "+32 New"
  // rather than a noisy decimal. null when there's not enough history yet
  // (never fabricate a 0 change from a single sample).
  var deltaOf = function (f, unit) {
    var vals = pts.map(f).filter(function (v) { return v != null; });
    if (vals.length < 2) return { delta: null };
    var d0 = vals[vals.length - 1] - vals[0];
    var r = Math.round(d0 * 10) / 10;
    return { delta: (r > 0 ? '+' : '') + r + (unit || ''), deltaKind: r > 0 ? 'up' : r < 0 ? 'down' : '' };
  };
  var cards = [
    { label: 'CPU', icon: 'fa-microchip', color: PAL['v-cpu'], value: pctStr(d.cpu && d.cpu.total),
      level: lvl(d.cpu && d.cpu.total, 80, 95),
      sub: (load.cores || '?') + ' threads · load ' +
           (load.l1 != null ? load.l1.toFixed(2) : '—'),
      ring: d.cpu && d.cpu.total },
    merge(merge({ label: 'Memory', icon: 'fa-server', color: PAL['v-mem'], value: pctStr(d.mem && d.mem.pct),
      level: lvl(d.mem && d.mem.pct, 80, 92),
      sub: bytes(d.mem && d.mem.used) + ' of ' + bytes(d.mem && d.mem.total), ring: d.mem && d.mem.pct },
      sparkOf(function (p) { return p.mem; }, PAL['v-mem'])),
      deltaOf(function (p) { return p.mem; }, 'pt')),
    { label: 'Array', icon: 'fa-hdd-o', color: PAL['v-accent'], value: String(t.data_disks || 0), unit: 'data',
      sub: d.system.md_state + ' · ' + (t.parity_disks || 0) + ' parity · ' + (t.cache_disks || 0) + ' pool' },
    { label: 'Storage', icon: 'fa-database', color: PAL['v-mem'], value: pctStr(t.used_pct),
      level: lvl(t.used_pct, 85, 95), sub: bytes(t.fs_free) + ' free of ' + bytes(t.fs_size), ring: t.used_pct },
    merge(merge({ label: 'Hottest disk', icon: 'fa-thermometer-half', color: PAL['v-temp'],
      value: tMax == null ? '—' : String(tMax), unit: tMax == null ? '' : '°C',
      level: tMax == null ? '' : lvl(tMax, 45, 55), sub: temps.length + ' disks reporting' },
      sparkOf(function (p) { return p.temp_max; }, PAL['v-temp'])),
      deltaOf(function (p) { return p.temp_max; }, '°')),
    { label: 'Containers', icon: 'fa-cubes', color: PAL['v-ok-fg'],
      value: String((d.docker || {}).running || 0), unit: '/ ' + ((d.docker || {}).count || 0),
      sub: ((d.docker || {}).stopped || 0) + ' stopped' },
    { label: 'Uptime', icon: 'fa-clock-o', color: PAL['v-accent'], value: dur(d.system.uptime),
      sub: 'kernel ' + d.system.kernel },
    { label: 'Shares', icon: 'fa-folder-open-o', color: PAL['v-accent'],
      value: String((d.shares || {}).total || 0),
      sub: ((d.shares || {}).cache || 0) + ' cached · ' + ((d.shares || {}).array || 0) + ' array' }
  ];

  // Donut: containers by state (reference: "tasks by status" chart).
  var dkr = d.docker || {};
  var donut = {
    segments: [
      { label: 'Running', value: dkr.running || 0, color: PAL['v-ok-fg'] },
      { label: 'Stopped', value: dkr.stopped || 0, color: PAL['v-warn-fg'] },
    ],
    center: { big: String(dkr.count || 0), small: 'containers' },
    size: 120,
  };

  // P17-01: one-click full health check — runs every check now, shows a score
  // + severity-sorted findings + diff vs the previous run.
  var hcState = useState(null); var hcReport = hcState[0], setHcReport = hcState[1];
  var hcRunState = useState('idle'); var hcRunning = hcRunState[0], setHcRunning = hcRunState[1];
  var runHealth = function () {
    setHcRunning('busy');
    fetch(ENDPOINT + '?action=health_check', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { setHcRunning('idle'); if (j && j.ok) setHcReport(j); })
      .catch(function () { setHcRunning('idle'); });
  };

  return h('div', null,
    h('div', { class: 'v-cards v-cards-4' }, cards.map(function (c, i) {
      return h(StatCard, merge(c, { key: i }));
    })),
    h(Panel, {
      title: 'Health check',
      span2: true,
      hint: hcReport ? ('score ' + hcReport.score + '/100' +
        (hcReport.diff ? (' — ' + (hcReport.diff.new || []).length + ' new, ' + (hcReport.diff.resolved || []).length + ' resolved vs previous run') : '')) : 'runs every check in the engine now'
    },
      h('div', null,
        h('button', { class: 'v-btn', onClick: runHealth, disabled: hcRunning === 'busy' },
          hcRunning === 'busy' ? 'Running…' : 'Run health check now'),
        hcReport ? h('div', { style: 'margin-top:10px' },
          hcReport.diff && hcReport.diff.new && hcReport.diff.new.length
            ? h('div', { class: 'v-empty' }, 'New since last run: ' + hcReport.diff.new.length) : null,
          (hcReport.findings || []).map(function (f, i) {
            return h('div', { key: i, style: 'margin-bottom:6px' },
              h('span', { class: 'v-badge ' + (f.severity === 'critical' || f.severity === 'alert' ? 'v-badge-error' : f.severity === 'warning' ? 'v-badge-warning' : '') },
                f.severity), ' ',
              h('span', null, f.title), ' ',
              h('button', { class: 'v-btn xs', onClick: function () {
                  fetch(ENDPOINT + '?action=playbook&check=' + encodeURIComponent(f.check_id), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                      if (!(j && j.ok)) return;
                      // inline playbook: replace the button with a pre block
                      setHcReport(function (rep) {
                        var copy = rep.findings.slice();
                        copy[i] = merge(copy[i], { playbook: j.title + '\n\n' + j.body });
                        return merge(rep, { findings: copy });
                      });
                    });
                } }, 'Fix guide'),
            f.playbook ? h('pre', { class: 'v-pre', style: 'white-space:pre-wrap;max-height:300px;overflow:auto;background:transparent' }, f.playbook) : null);
          }),
          (hcReport.findings || []).length === 0 ? h('div', { class: 'v-empty' }, 'No findings — clean run.') : null)
          : null)),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'CPU & Memory', span2: true, hint: pts.length + ' samples' },
        h(Chart, { series: cpuSeries, max: 100, height: 165, area: true, events: P.chartEvents,
          onEventClick: function (e) { window.alert(new Date(e.t * 1000).toLocaleString() + '\n' + e.label); },
          yFmt: function (v) { return v + '%'; } })),
      h(Panel, { title: 'Network flow', hint: 'stacked RX + TX' },
        h(Chart, { series: netSeries, height: 165, stack: true, area: true, yFmt: bytes })),
      h(Panel, { title: 'Disk temperature', hint: 'alert at 55°C' },
        h(Chart, { series: [{ name: 'Hottest °C', color: PAL['v-temp'],
            points: pick(function (p) { return p.temp_max; }) }],
          floor: tMax == null ? 0 : Math.max(0, Math.floor(tMax - 10)), height: 165,
          thresholds: [{ v: 55, color: '#f87171', label: 'alert 55°' }],
          events: P.chartEvents,
          onEventClick: function (e) { window.alert(new Date(e.t * 1000).toLocaleString() + '\n' + e.label); },
          yFmt: function (v) { return v + '°'; } }))),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Container CPU', hint: ctrTop.length ? 'top ' + ctrTop.length : '' },
        h(Chart, { series: ctrSeries, yFmt: function (v) { return v + '%'; }, area: true,
          empty: 'Container series appear once docker stats are sampled.' })),
      h(Panel, { title: 'Containers by state' },
        h(Donut, donut)),
      h(Panel, { title: 'Pools', hint: ((a.cache || []).length || 0) + ' devices' },
        h('table', null,
          h('tr', null, h('th', null, 'Pool'), h('th', { class: 'num' }, 'Size'),
            h('th', { class: 'num' }, 'Used'), h('th', { class: 'num' }, 'Temp')),
          (a.cache || []).map(function (x) {
            return h('tr', { key: x.name },
              h('td', { class: 'v-name' }, x.name),
              h('td', { class: 'num' }, bytes(x.size, 0)),
              h('td', { class: 'num ' + lvl(x.usedPct, 85, 95) }, x.usedPct ? x.usedPct.toFixed(0) + '%' : '—'),
              h('td', { class: 'num ' + lvl(x.temp, 45, 55) }, x.temp == null ? '—' : x.temp + '°'));
          })))),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Docker containers', span2: true,
        hint: ((d.docker || {}).running || 0) + ' of ' + ((d.docker || {}).count || 0) + ' running' },
        h(ContainerTable, { d: d, compact: true })),
      h(Panel, { title: 'Top processes', hint: 'by CPU' }, h(TopTable, { d: d }))),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Anomaly detection', span2: true,
        hint: anomaly && anomaly.status === 'ok' ? anomaly.have_hours + 'h of history' : '' },
        !anomaly ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-spinner fa-spin' }), ' Building baseline…')
        : anomaly.status === 'need_more_data'
          ? h('div', { class: 'v-empty' }, 'Needs at least 2 weeks of history to build a per-hour baseline — '
              + anomaly.have_hours + ' of ' + anomaly.need_hours + ' hours collected so far.')
        : !anomaly.findings.length
          ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-check-circle' }), ' Everything is within its usual range for this time of week.')
        : anomaly.findings.map(function (f, i) {
            return h('div', { key: i, class: 'v-ai-item' },
              h('i', { class: 'fa fa-exclamation-triangle' }),
              h('div', { class: 'v-ai-body' },
                h('div', { class: 'v-ai-title' }, f.metric.toUpperCase() + ' is well outside its usual range for this time') ,
                h('div', { class: 'v-ai-detail' }, 'Reading ' + f.value + ' for 3+ hours, vs a usual '
                  + f.baseline_median + ' at this hour of the week (z-score ' + f.z_score + ').')));
          }))));
}

function merge(a, b) {
  var o = {}, k;
  for (k in a) if (Object.prototype.hasOwnProperty.call(a, k)) o[k] = a[k];
  for (k in b) if (Object.prototype.hasOwnProperty.call(b, k)) o[k] = b[k];
  return o;
}

var SEV_ORDER = { critical: 0, error: 1, warning: 2, info: 3, ok: 4 };
var SEV_ICON = { critical: 'fa-bolt', error: 'fa-times-circle', warning: 'fa-exclamation-triangle',
  info: 'fa-info-circle', ok: 'fa-check-circle' };

/** Renders the background AI agents' findings as a compact list of
 *  banners, worst severity first. `agents` optionally filters to one
 *  domain (e.g. only 'thermal' findings on the Hardware tab); omitted on
 *  the dashboard to show everything. Nothing rendered when there's
 *  nothing to report yet — agents haven't run, or genuinely all-clear. */
function AiBell(P) {
  var all = P.findings || [];
  var interesting = all.filter(function (f) { return f.severity !== 'ok'; });
  var worst = interesting.reduce(function (w, f) {
    return (SEV_ORDER[f.severity] ?? 5) < (SEV_ORDER[w] ?? 5) ? f.severity : w;
  }, 'info');
  return h('button', { class: 'v-ibtn v-bell' + (P.open ? ' on' : '') + (interesting.length ? ' has-badge' : ''),
      title: 'AI health findings' + (interesting.length ? ' (' + interesting.length + ')' : ''), onClick: P.onClick },
    h('i', { class: 'fa fa-magic' }),
    interesting.length ? h('span', { class: 'v-badge v-badge-' + worst }, interesting.length) : null);
}

/** Right-side drawer: every AI finding across every agent, newest/worst
 *  first — same drawer pattern as the log viewer instead of a full-width
 *  block sitting on top of every tab. */
function FindingsDrawer(P) {
  var all = P.findings || [];
  var s1 = useState('all'); var agentFilter = s1[0], setAgentFilter = s1[1];
  var agents = Array.from(new Set(all.map(function (f) { return f.agent; }).filter(Boolean))).sort();
  var interesting = all.filter(function (f) { return f.severity !== 'ok'; });
  var pool = interesting.length ? interesting : all;
  var shown = pool.filter(function (f) { return agentFilter === 'all' || f.agent === agentFilter; })
    .slice().sort(function (a, b) { return (SEV_ORDER[a.severity] ?? 5) - (SEV_ORDER[b.severity] ?? 5); });

  return P.open ? h('div', null,
    h('div', { class: 'v-drawer-backdrop', onClick: P.onClose }),
    h('div', { class: 'v-drawer' },
      h('div', { class: 'v-drawer-head' },
        h('i', { class: 'fa fa-magic' }),
        h('span', { class: 'v-drawer-title' }, 'AI health findings'),
        h('span', { class: 'v-ai-agent' + (interesting.length ? ' warn' : '') }, interesting.length + ' active'),
        h('button', { class: 'v-btn xs', style: 'margin-left:auto', onClick: P.onClose }, '✕')),
      h('div', { class: 'v-drawer-tools' },
        h('select', { class: 'v-select', value: agentFilter, onChange: function (e) { setAgentFilter(e.target.value); } },
          h('option', { value: 'all' }, 'All agents'),
          agents.map(function (a) { return h('option', { key: a, value: a }, a); })),
        h('span', { class: 'muted', style: 'align-self:center;font-size:11.5px' }, 'updated hourly by local agents')),
      h('div', { class: 'v-drawer-body' },
        !shown.length ? h('div', { class: 'v-empty' }, 'No findings yet — the agents run hourly.')
        : shown.map(function (f) {
            return h('div', { key: f.id, class: 'v-ai-item v-ai-' + f.severity },
              h('i', { class: 'fa ' + (SEV_ICON[f.severity] || 'fa-info-circle') }),
              h('div', { class: 'v-ai-body' },
                h('div', { class: 'v-ai-title' }, f.title, f.agent ? h('span', { class: 'v-ai-agent' }, f.agent) : null),
                f.detail ? h('div', { class: 'v-ai-detail' }, f.detail) : null,
                f.recommendation ? h('div', { class: 'v-ai-rec' }, h('i', { class: 'fa fa-lightbulb-o' }), ' ', f.recommendation) : null));
          })),
      h('div', { class: 'v-drawer-foot' },
        shown.length + ' of ' + all.length + ' total findings'))) : null;
}

/* ---------------------------------------------------------------- array */

function ArrayTab(P) {
  var d = P.d, pts = P.pts, a = d.array || {}, t = a.totals || {};
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var smTop = topKeys(pts, 'smart', 0, 8);

  // P15-09: disk fleet risk report — model/age/start-stop/growth, ranked.
  var dfState = useState(null); var fleet = dfState[0], setFleet = dfState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=disk_fleet', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setFleet(j.disks || []); })
      .catch(function () {});
  }, []);

  // P15-01: "days until full" forecast, fetched separately since it needs
  // 30 days of flash-persisted history, not just the in-memory ring.
  var fcState = useState(null); var forecast = fcState[0], setForecast = fcState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=capacity_forecast', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setForecast(j.forecast || []); })
      .catch(function () { setForecast([]); });
  }, []);

  var smSeries = smTop.map(function (name, i) {
    return { name: name, color: pickColor(i),
      points: pick(function (p) { return p.smart && p.smart[name] ? p.smart[name][0] : null; }) };
  });
  var disks = (a.parity || []).concat(a.data || [], a.cache || []);
  var used = disks.filter(function (x) { return x.fsSize > 0; });
  var avg = used.length
    ? used.reduce(function (s, x) { return s + (x.usedPct || 0); }, 0) / used.length : null;

  // Ranked temperature hot-list (reference: total-tasks-by-assignee bars).
  // Shows BOTH current and the 24h max (ticket #31) — current alone hides a
  // drive that spiked earlier in the window and has since cooled back down,
  // which is exactly the kind of warming trend you want to catch before it
  // crosses the alert threshold again.
  var hotRows = smTop.map(function (name) {
    var cur = null, max24 = null;
    for (var i = pts.length - 1; i >= 0; i--) {
      var s = pts[i].smart && pts[i].smart[name];
      if (!s || s[0] == null) continue;
      if (cur == null) cur = s[0];
      max24 = max24 == null ? s[0] : Math.max(max24, s[0]);
    }
    return { label: name, value: cur == null ? '—' : cur + '°' + (max24 != null && max24 !== cur ? ' (24h max ' + max24 + '°)' : ''),
      pct: cur == null ? 0 : Math.min(100, (cur / 60) * 100),
      color: cur == null ? '#666' : cur >= 55 ? PAL['v-bad-fg'] : cur >= 45 ? PAL['v-warn-fg'] : PAL['v-ok-fg'] };
  }).sort(function (a, b) { return (b.pct || 0) - (a.pct || 0); });

  // Per-disk throughput (P15-02): read+write bytes/sec combined into one
  // series per disk so a parity check (sustained reads across every array
  // disk at once) is visually obvious as several lines rising together.
  var ioTop = topKeys(pts, 'disk_io', 0, 8);
  var ioReadSeries = ioTop.map(function (name, i) {
    return { name: name + ' read', color: pickColor(i),
      points: pick(function (p) { return p.disk_io && p.disk_io[name] ? p.disk_io[name][0] : null; }) };
  });
  var ioWriteSeries = ioTop.map(function (name, i) {
    return { name: name + ' write', color: pickColor(i + ioTop.length),
      points: pick(function (p) { return p.disk_io && p.disk_io[name] ? p.disk_io[name][1] : null; }) };
  });
  var hasIo = ioTop.length > 0;

  // SMART sector growth: reallocated (index 1) AND pending (index 2) for
  // disks with non-zero counters (ticket #31 asks for both — a disk can
  // accumulate pending sectors well before any get reallocated).
  var smReallocNames = topKeys(pts, 'smart', 1, 6);
  var smPendingNames = topKeys(pts, 'smart', 2, 6);
  var hasGrowth = smReallocNames.length > 0 || smPendingNames.length > 0;
  var growthSeries = smReallocNames.map(function (name, i) {
    return { name: name + ' realloc', color: pickColor(i + 3),
      points: pick(function (p) { return p.smart && p.smart[name] ? p.smart[name][1] : null; }) };
  }).concat(smPendingNames.map(function (name, i) {
    return { name: name + ' pending', color: pickColor(i + 3 + smReallocNames.length),
      points: pick(function (p) { return p.smart && p.smart[name] ? p.smart[name][2] : null; }) };
  }));

  return h('div', null,
    h('div', { class: 'v-cards' },
      [
        { label: 'Array state', icon: 'fa-hdd-o', color: PAL['v-accent'], value: d.system.md_state,
          sub: t.data_disks + ' data · ' + t.parity_disks + ' parity' },
        { label: 'Raw capacity', icon: 'fa-database', color: PAL['v-mem'], value: bytes(t.raw, 1),
          sub: t.fs_size ? bytes(t.fs_size, 1) + ' usable' : '' },
        { label: 'Used', icon: 'fa-pie-chart', color: PAL['v-mem'], value: pctStr(t.used_pct),
          bar: t.used_pct, level: lvl(t.used_pct, 85, 95), sub: bytes(t.fs_free, 1) + ' free' },
        { label: 'Mean fill', icon: 'fa-bar-chart', color: PAL['v-load'], value: pctStr(avg),
          level: lvl(avg, 85, 95), sub: used.length + ' filesystems' },
        { label: 'Pool devices', icon: 'fa-server', color: PAL['v-accent'], value: String(t.cache_disks || 0),
          sub: (a.cache || []).filter(function (x) { return x.spundown; }).length + ' spun down' }
      ].map(function (c, i) { return h(StatCard, merge(c, { key: i })); })),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Per-disk temperature', span2: true, hint: smTop.length ? 'hottest ' + smTop.length + ', alert at 55°' : '' },
        smSeries.length ? h('div', null,
          h(Chart, { series: smSeries, height: 190, area: true, hideLegend: true,
            thresholds: [{ v: 55, color: '#f8717166', label: '55° alert' }],
            yFmt: function (v) { return v + '°'; },
            empty: 'Per-disk temps appear once SMART data is cached.' }),
          h(ChartStats, { series: smSeries.map(function (s) {
            return merge(s, { fmt: function (v) { return (Math.round(v * 10) / 10) + '°'; } });
          }) })) : h('div', { class: 'v-empty' }, 'Per-disk temps appear once SMART data is cached.')),
      h(Panel, { title: 'Hottest disks', hint: 'current, vs 60° scale' },
        h(HBars, { rows: hotRows, empty: 'Waiting for SMART samples.' })),
      h(Panel, { title: 'Capacity forecast', hint: forecast && forecast.length ? '30-day linear fit' : '', span2: true },
        forecast == null
          ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-spinner fa-spin' }), ' Fitting growth trend…')
        : !forecast.length
          ? h('div', { class: 'v-empty' }, 'No target is on track to fill within a year, or there is not yet 5+ days of history to fit a trend against.')
        : h(Table, null,
            h('tr', null, h('th', null, 'Target'), h('th', null, 'Growth'), h('th', null, 'Free now'),
              h('th', null, 'Full in'), h('th', null, 'Fit')),
            forecast.map(function (f, i) {
              return h('tr', { key: i },
                h('td', { class: 'v-name' }, f.name),
                h('td', null, '+' + f.gb_per_day + ' GB/day'),
                h('td', { class: 'muted' }, f.free_now_gb + ' / ' + f.total_gb + ' GB'),
                h('td', null, h(Pill, { kind: f.within_14d ? (f.days_left <= 3 ? 'stop' : 'warn') : 'info' },
                  'about ' + f.days_left + 'd')),
                h('td', { class: 'muted' }, Math.round(f.r2 * 100) + '% fit'));
            }))),
      h(Panel, { title: 'SMART sector counters', hint: hasGrowth ? 'lifetime totals — growth is the signal' : '' },
        hasGrowth ? h(Chart, { series: growthSeries, height: 170, integer: true,
          yFmt: function (v) { return String(Math.round(v)); },
          empty: 'No disks with non-zero reallocated/pending counters.' })
        : h('div', { class: 'v-empty' }, 'No disks with non-zero reallocated/pending counters — healthy.')),
      h(Panel, { title: 'Per-disk I/O', span2: true, hint: hasIo ? 'read (solid) vs write (dashed), bytes/s' : '' },
        hasIo ? h('div', null,
          h(Chart, { series: ioReadSeries.concat(ioWriteSeries), height: 190,
            yFmt: function (v) { return v >= 1048576 ? (Math.round(v / 1048576 * 10) / 10) + ' MB/s' : Math.round(v / 1024) + ' KB/s'; },
            empty: 'No disk I/O activity yet.' }),
          h(ChartStats, { series: ioTop.map(function (name, i) {
            var r = pick(function (p) { return p.disk_io && p.disk_io[name] ? p.disk_io[name][0] : null; });
            var w = pick(function (p) { return p.disk_io && p.disk_io[name] ? p.disk_io[name][1] : null; });
            return { name: name, color: pickColor(i),
              points: r.map(function (p, j) { return [p[0], (p[1] || 0) + ((w[j] || [0, 0])[1] || 0)]; }),
              fmt: function (v) { return v >= 1048576 ? (Math.round(v / 1048576 * 10) / 10) + ' MB/s' : Math.round(v / 1024) + ' KB/s'; } };
          }) }))
        : h('div', { class: 'v-empty' }, 'No disk I/O activity yet — a parity check or heavy transfer will show up here as sustained reads across every array disk.'))),
    h(Panel, { title: 'Disks', span2: true, hint: disks.length + ' devices' }, h(DiskTable, { d: d })),
    h(Panel, { title: 'SMART detail', span2: true, hint: Object.keys(d.smart || {}).length + ' disks' },
      h(SmartTable, { d: d })),
    h(Panel, { title: 'Disk fleet risk report', span2: true, hint: fleet ? fleet.length + ' disks ranked' : '' },
      !fleet ? h('div', { class: 'v-empty' }, 'Loading…')
      : !fleet.length ? h('div', { class: 'v-empty' }, 'No SMART-capable disks found.')
      : h(Table, null,
          h('tr', null, h('th', null, 'Disk'), h('th', null, 'Model'), h('th', { class: 'num' }, 'Age'),
            h('th', { class: 'num' }, 'Start/stop'), h('th', { class: 'num' }, 'Temp'),
            h('th', { class: 'num' }, 'Risk'), h('th', null, 'Reasons')),
          fleet.map(function (dk) {
            var ageYrs = dk.hours != null ? (dk.hours / 8760).toFixed(1) + 'y' : '—';
            var riskCls = dk.risk_score >= 500 ? 'v-badge-error' : dk.risk_score > 0 ? 'v-badge-warning' : '';
            return h('tr', { key: dk.dev },
              h('td', { class: 'v-name' }, dk.name),
              h('td', { class: 'muted' }, dk.model || '—'),
              h('td', { class: 'num' }, ageYrs),
              h('td', { class: 'num' }, dk.start_stop_count != null ? String(dk.start_stop_count) : '—'),
              h('td', { class: 'num' }, dk.temp != null ? dk.temp + '°' : '—'),
              h('td', { class: 'num' }, h('span', { class: 'v-badge ' + riskCls }, String(dk.risk_score))),
              h('td', { class: 'muted' }, dk.risk_reasons.length ? dk.risk_reasons.join('; ') : 'nothing notable'));
          }))),
    h(Panel, { title: 'Daily rollups', span2: true,
      hint: (P.daily || []).length ? 'last ' + P.daily.length + ' days' : 'building' },
      h(DailyTable, { daily: P.daily })),
    d.system.unclean_shutdown ? h(Panel, { title: 'Unclean shutdown detected', span2: true },
      h('div', { class: 'v-empty' },
        'The array was not unmounted cleanly last time it stopped. Unraid runs a parity check on the'
        + ' next array start to verify consistency — treat parity as unverified until one has completed.')) : null,
    h(Panel, { title: 'Parity check history', span2: true,
      hint: (d.parity_history || []).length ? 'last ' + d.parity_history.length + ' checks' : '' },
      h(ParityTable, { history: d.parity_history })));
}

function DailyTable(P) {
  var list = (P.daily || []).slice().reverse();
  if (!list.length) {
    return h('div', { class: 'v-empty' }, 'Daily rollups appear after the first full hour of collection.');
  }
  return h(Table, null,
    h('tr', null, h('th', null, 'Day'), h('th', { class: 'num' }, 'CPU avg'),
      h('th', { class: 'num' }, 'Mem avg'), h('th', { class: 'num' }, 'Peak temp'),
      h('th', { class: 'num' }, 'Net RX/s'), h('th', { class: 'num' }, 'Net TX/s'),
      h('th', { class: 'num' }, 'Peak GPU'), h('th', { class: 'num' }, 'Fullest')),
    list.map(function (r) {
      var warns = Object.keys(r.smart || {}).filter(function (k) {
        var s = r.smart[k] || {};
        return (s.reallocated || 0) > 0 || (s.pending || 0) > 0;
      });
      return h('tr', { key: r.day },
        h('td', { class: 'v-name' }, r.day,
          warns.map(function (w) { return h('span', { key: w, class: 'v-warnbadge' }, w); })),
        h('td', { class: 'num' }, r.cpu == null ? '—' : r.cpu + '%'),
        h('td', { class: 'num' }, r.mem == null ? '—' : r.mem + '%'),
        h('td', { class: 'num ' + lvl(r.temp_max, 50, 58) }, r.temp_max == null ? '—' : r.temp_max + '°'),
        h('td', { class: 'num' }, r.net_rx ? bytes(r.net_rx) + '/s' : '—'),
        h('td', { class: 'num' }, r.net_tx ? bytes(r.net_tx) + '/s' : '—'),
        h('td', { class: 'num' }, r.gpu_max == null ? '—' : r.gpu_max + '%'),
        h('td', { class: 'num ' + lvl(r.fill_max, 85, 95) }, r.fill_max == null ? '—' : r.fill_max + '%'));
    }));
}

function ParityTable(P) {
  var hist = (P.history || []).slice().reverse();
  if (!hist.length) {
    return h('div', { class: 'v-empty' }, 'Parity check history appears after Unraid records its first check.');
  }
  return h(Table, null,
    h('tr', null, h('th', null, 'Date'), h('th', null, 'Type'), h('th', { class: 'num' }, 'Duration'),
      h('th', { class: 'num' }, 'Speed'), h('th', { class: 'num' }, 'Errors'), h('th', null, 'Result')),
    hist.map(function (r, i) {
      var hrs = r.elapsed_sec ? (r.elapsed_sec / 3600).toFixed(1) + 'h' : '—';
      var result = r.cancelled ? { label: 'Cancelled', cls: 'v-badge-warning' }
        : (r.errors || 0) > 0 ? { label: r.errors + ' error(s)', cls: 'v-badge-error' }
        : { label: 'Clean', cls: '' };
      return h('tr', { key: i },
        h('td', { class: 'v-name' }, r.date ? new Date(r.date * 1000).toISOString().slice(0, 10) : '—'),
        h('td', null, r.type || '—'),
        h('td', { class: 'num' }, hrs),
        h('td', { class: 'num' }, r.speed_mbps == null ? '—' : r.speed_mbps + ' MB/s'),
        h('td', { class: 'num' }, r.errors == null ? '—' : String(r.errors)),
        h('td', null, h('span', { class: 'v-badge ' + result.cls }, result.label)));
    }));
}

/* --------------------------------------------------------------- docker */

function DockerTab(P) {
  var d = P.d, pts = P.pts;
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var dk = d.docker || {};
  var ctr = dk.containers || [];
  var ctrTop = topKeys(pts, 'ctr', 0, 10);
  var mk = function (idx) {
    return ctrTop.map(function (name, i) {
      return { name: name, color: pickColor(i),
        points: pick(function (p) { return p.ctr && p.ctr[name] ? p.ctr[name][idx] : null; }) };
    });
  };
  var byCpu = ctr.slice().sort(function (a, b) { return (b.cpu || 0) - (a.cpu || 0); });
  var byMem = ctr.slice().sort(function (a, b) { return (b.mem_bytes || 0) - (a.mem_bytes || 0); });

  // P15-07: weekly per-container resource report.
  var wr = useState(null); var weekly = wr[0], setWeekly = wr[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=container_weekly', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setWeekly(j); })
      .catch(function () {});
  }, []);

  return h('div', null,
    h('div', { class: 'v-cards' },
      [
        { label: 'Defined', icon: 'fa-cubes', color: PAL['v-accent'], value: String(dk.count || 0),
          sub: 'containers' },
        { label: 'Running', icon: 'fa-play-circle', color: PAL['v-ok-fg'], value: String(dk.running || 0),
          bar: dk.count ? 100 * dk.running / dk.count : 0, sub: dk.stopped + ' stopped' },
        { label: 'Stopped', icon: 'fa-stop-circle', color: PAL['v-warn-fg'], value: String(dk.stopped || 0),
          sub: 'down or crashed' },
        { label: 'Top CPU', icon: 'fa-fire', color: PAL['v-load'],
          value: byCpu.length ? byCpu[0].name : '—',
          sub: byCpu.length ? (byCpu[0].cpu || 0).toFixed(1) + '% now' : '' },
        { label: 'Top memory', icon: 'fa-server', color: PAL['v-mem'],
          value: byMem.length ? byMem[0].name : '—',
          sub: byMem.length ? bytes(byMem[0].mem_bytes) : '' }
      ].map(function (c, i) { return h(StatCard, merge(c, { key: i })); })),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Container CPU %', span2: true, hint: ctrTop.length ? 'top ' + ctrTop.length : '' },
        h(Chart, { series: mk(0), height: 175, yFmt: function (v) { return v + '%'; },
          empty: 'Container series appear once docker stats are sampled.' })),
      h(Panel, { title: 'Container memory', span2: true, hint: 'top ' + ctrTop.length },
        h(Chart, { series: mk(1), height: 175,
          yFmt: function (v) { return v >= 1048576 ? (v / 1048576).toFixed(1) + 'Gi' : Math.round(v / 1024) + 'Mi'; } }))),
    h(Panel, { title: 'All containers', span2: true, hint: ctr.length + ' defined' },
      h(ContainerTable, { d: d })),
    h(Panel, { title: 'Weekly resource report', span2: true,
      hint: weekly && weekly.this_week_hours ? weekly.this_week_hours + 'h this week' : '' },
      !weekly ? h('div', { class: 'v-empty' }, 'Loading…')
      : !weekly.containers.length ? h('div', { class: 'v-empty' }, 'Not enough hourly history yet — check back after a day or two of uptime.')
      : h('div', null,
          !weekly.have_last_week ? h('div', { class: 'v-empty', style: 'margin-bottom:10px' },
            'Less than a week of history — week-over-week change will appear once a full prior week exists.') : null,
          h(Table, null,
            h('tr', null, h('th', null, 'Container'), h('th', { class: 'num' }, 'Avg CPU'),
              h('th', { class: 'num' }, 'Peak CPU'), h('th', { class: 'num' }, 'Avg mem'),
              h('th', { class: 'num' }, 'Peak mem'), h('th', { class: 'num' }, 'Restarts'),
              h('th', { class: 'num' }, 'vs last week')),
            weekly.containers.map(function (c) {
              var tw = c.this_week || {};
              var chg = c.cpu_change_pct;
              return h('tr', { key: c.name },
                h('td', { class: 'v-name' }, c.name),
                h('td', { class: 'num' }, tw.cpu_avg != null ? tw.cpu_avg.toFixed(1) + '%' : '—'),
                h('td', { class: 'num' }, tw.cpu_peak != null ? tw.cpu_peak.toFixed(1) + '%' : '—'),
                h('td', { class: 'num' }, tw.mem_avg_kb != null ? bytes(tw.mem_avg_kb * 1024) : '—'),
                h('td', { class: 'num' }, tw.mem_peak_kb != null ? bytes(tw.mem_peak_kb * 1024) : '—'),
                h('td', { class: 'num' }, tw.restarts != null ? String(tw.restarts) : '—'),
                h('td', { class: 'num' }, chg == null ? '—'
                  : h('span', { class: chg > 10 ? 'v-txt-warn' : chg < -10 ? 'v-txt-ok' : 'muted' },
                      (chg > 0 ? '+' : '') + chg.toFixed(0) + '%')));
            })))));
}

/* -------------------------------------------------------------- network */

function NetTab(P) {
  var d = P.d, pts = P.pts;
  var net = d.net || {};
  var ifs = Object.keys(net);
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var ifTop = topKeys(pts, 'net', 0, 8).concat(topKeys(pts, 'net', 1, 8))
    .filter(function (v, i, arr) { return arr.indexOf(v) === i; }).slice(0, 8);
  var series = ifTop.reduce(function (acc, ifn, i) {
    var c = pickColor(i);
    acc.push({ name: ifn + ' down', color: c,
      points: pick(function (p) { return p.net && p.net[ifn] ? p.net[ifn][0] : null; }) });
    acc.push({ name: ifn + ' up', color: c,
      points: pick(function (p) { return p.net && p.net[ifn] ? p.net[ifn][1] : null; }) });
    return acc;
  }, []);
  var sorted = ifs.slice().sort(function (a, b) {
    return ((net[b].rx_rate || 0) + (net[b].tx_rate || 0)) - ((net[a].rx_rate || 0) + (net[a].tx_rate || 0));
  });
  var active = sorted.filter(function (k) { return net[k].rx_total || net[k].tx_total; });
  var sum = function (k, f) { return active.reduce(function (s, x) { return s + (net[x][f] || 0); }, 0); };

  return h('div', null,
    h('div', { class: 'v-cards' },
      [
        { label: 'Interfaces', icon: 'fa-exchange', color: PAL['v-accent'], value: String(ifs.length),
          sub: active.length + ' carrying traffic' },
        { label: 'Total RX', icon: 'fa-download', color: PAL['v-rx'], value: bytes(sum(0, 'rx_rate')) + '/s',
          sub: bytes(sum(0, 'rx_total')) + ' since boot' },
        { label: 'Total TX', icon: 'fa-upload', color: PAL['v-tx'], value: bytes(sum(0, 'tx_rate')) + '/s',
          sub: bytes(sum(0, 'tx_total')) + ' since boot' },
        { label: 'Busiest', icon: 'fa-bolt', color: PAL['v-load'], value: sorted[0] || '—',
          sub: sorted[0] ? bytes((net[sorted[0]].rx_rate || 0) + (net[sorted[0]].tx_rate || 0)) + '/s' : '' }
      ].map(function (c, i) { return h(StatCard, merge(c, { key: i })); })),
    h(Panel, { title: 'Throughput per interface', span2: true, hint: ifTop.length ? 'top ' + ifTop.length : '' },
      h(Chart, { series: series, height: 200, yFmt: bytes, empty: 'No per-interface series yet.' })),
    h(Panel, { title: 'All interfaces', span2: true, hint: ifs.length + ' total' },
      h(Table, null,
        h('tr', null, h('th', null, 'Interface'), h('th', { class: 'num' }, 'RX rate'),
          h('th', { class: 'num' }, 'TX rate'), h('th', { class: 'num' }, 'RX total'),
          h('th', { class: 'num' }, 'TX total')),
        sorted.map(function (k) {
          var n = net[k];
          return h('tr', { key: k },
            h('td', { class: 'v-name v-mono' }, k),
            h('td', { class: 'num ' + (n.rx_rate > 1e6 ? 'warn' : '') }, n.rx_rate ? bytes(n.rx_rate) + '/s' : '—'),
            h('td', { class: 'num ' + (n.tx_rate > 1e6 ? 'warn' : '') }, n.tx_rate ? bytes(n.tx_rate) + '/s' : '—'),
            h('td', { class: 'num muted' }, n.rx_total ? bytes(n.rx_total) : '—'),
            h('td', { class: 'num muted' }, n.tx_total ? bytes(n.tx_total) : '—'));
        }))));
}

/* --------------------------------------------------------------- system */

function SysTab(P) {
  var d = P.d, pts = P.pts;
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var mem = d.mem || {}, load = d.load || {}, sys = d.system || {};
  var sCpu = [{ name: 'CPU %', color: PAL['v-cpu'], points: pick(function (p) { return p.cpu; }) }];
  var sMem = [{ name: 'Mem %', color: PAL['v-mem'], points: pick(function (p) { return p.mem; }) }];
  // P17-03: diagnostics export — Unraid bundle + anonymized forum summary.
  var diagState = useState(null); var diag = diagState[0], setDiag = diagState[1];
  var dgState = useState('idle'); var diagBusy = dgState[0], setDiagBusy = dgState[1];
  var loadDiag = function () {
    fetch(ENDPOINT + '?action=diag_summary', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setDiag(j.summary); });
  };
  useEffect(loadDiag, []);
  var trigBundle = function () {
    setDiagBusy('busy');
    fetch(ENDPOINT + '?action=diag_bundle', { method: 'POST', body: new URLSearchParams({}) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setDiagBusy('idle');
        if (j && j.ok) window.alert('Diagnostics bundle created: ' + j.bundle + '\n(attach this file to your support post)');
        else window.alert((j && j.error) || 'bundle failed');
      })
      .catch(function () { setDiagBusy('idle'); });
  };

  return h('div', null,
    h('div', { class: 'v-cards' },
      [
        { label: 'CPU', icon: 'fa-microchip', color: PAL['v-cpu'], value: pctStr(d.cpu && d.cpu.total),
          sub: sys.cpu, bar: d.cpu && d.cpu.total },
        { label: 'Load 1m', icon: 'fa-tachometer', color: PAL['v-load'],
          value: load.l1 == null ? '—' : load.l1.toFixed(2),
          sub: load.l5 != null ? load.l5.toFixed(2) + ' · ' + load.l15.toFixed(2) + ' (5m · 15m)' : '' },
        { label: 'Memory', icon: 'fa-server', color: PAL['v-mem'], value: pctStr(mem.pct), bar: mem.pct,
          sub: bytes(mem.used) + ' · ' + bytes(mem.available) + ' avail' },
        { label: 'Cached', icon: 'fa-files-o', color: PAL['v-accent'],
          value: bytes((mem.buffers || 0) + (mem.cached || 0)), sub: 'kernel reclaimable' },
        { label: 'Swap', icon: 'fa-exchange', color: mem.swap_used ? PAL['v-warn-fg'] : PAL['v-ok-fg'],
          value: mem.swap_total ? pctStr(mem.swap_pct) : 'none',
          sub: mem.swap_total ? bytes(mem.swap_used) + ' of ' + bytes(mem.swap_total) : 'no swap configured' },
        { label: 'Uptime', icon: 'fa-clock-o', color: PAL['v-accent'], value: dur(sys.uptime),
          sub: sys.hostname || '' }
      ].map(function (c, i) { return h(StatCard, merge(c, { key: i })); })),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'CPU %' },
        h(Chart, { series: sCpu, max: 100, height: 170, yFmt: function (v) { return v + '%'; } })),
      h(Panel, { title: 'Memory %' },
        h(Chart, { series: sMem, max: 100, height: 170, yFmt: function (v) { return v + '%'; } }))),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Host', span2: true },
        h('table', null, [
          ['Hostname', sys.hostname], ['Unraid', sys.version], ['Kernel', sys.kernel],
          ['CPU', sys.cpu], ['Threads', load.cores], ['Description', sys.comment],
          ['MD state', sys.md_state], ['Uptime', dur(sys.uptime)]
        ].map(function (r, i) {
          return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]),
            h('td', { class: 'num v-name' }, r[1] == null ? '—' : String(r[1])));
        }))),
      h(Panel, { title: 'Top processes', hint: 'by CPU' }, h(TopTable, { d: d }))),
    h(Panel, { title: 'CPU cores', span2: true,
      hint: (d.cpu_topology || {}).sockets ? d.cpu_topology.sockets + ' socket · ' + d.cpu_topology.cores + ' cores · ' + d.cpu_topology.threads + ' threads' : '' },
      h(CoreGrid, { d: d })),
    h(Panel, { title: 'Diagnostics export', span2: true,
      hint: 'Diagnostics bundle for support threads + an anonymized summary safe to paste in a forum post' },
      h('div', null,
        h('button', { class: 'v-btn', onClick: trigBundle, disabled: diagBusy === 'busy' },
          diagBusy === 'busy' ? 'Collecting…' : 'Collect Unraid diagnostics (.zip)'),
        h('div', { class: 'muted', style: 'margin-top:10px;margin-bottom:4px' },
          'Anonymized summary (shares, host, IPs, serials masked) — paste this in a forum post:'),
        h('pre', { class: 'v-pre', style: 'white-space:pre-wrap;max-height:280px;overflow:auto;background:transparent' },
          diag || 'Loading…'))));
}

/** Per-thread load grid cross-referenced with live VM vcpupin so a hot core
 *  can be traced back to the VM it's pinned to at a glance. */
function CoreGrid(P) {
  var d = P.d, cores = (d.cpu && d.cpu.cores) || {};
  var keys = Object.keys(cores).filter(function (k) { return /^cpu\d+$/.test(k); })
    .sort(function (a, b) { return Number(a.slice(3)) - Number(b.slice(3)); });
  if (!keys.length) return h('div', { class: 'v-empty' }, 'No per-core data yet.');
  var pinMap = {};
  ((d.vms || {}).list || []).forEach(function (v) {
    (v.pinning || []).forEach(function (p) {
      String(p.affinity).split(',').forEach(function (part) {
        part = part.trim();
        var range = part.match(/^(\d+)-(\d+)$/);
        if (range) { for (var i = Number(range[1]); i <= Number(range[2]); i++) pinMap[i] = v.name; }
        else if (/^\d+$/.test(part)) pinMap[Number(part)] = v.name;
      });
    });
  });
  return h('div', { class: 'v-core-grid' },
    keys.map(function (k) {
      var n = Number(k.slice(3));
      var pct = cores[k];
      var pin = pinMap[n];
      return h('div', { key: k, class: 'v-core ' + lvl(pct, 70, 90), title: pin ? 'pinned: ' + pin : 'unpinned' },
        h('div', { class: 'v-core-n' }, 'CPU' + n),
        h('div', { class: 'v-core-pct' }, pct == null ? '—' : Math.round(pct) + '%'),
        pin ? h('div', { class: 'v-core-pin' }, pin) : null);
    }));
}

/* --------------------------------------------------------------- shares */

function SharesTab(P) {
  var d = P.d, sh = d.shares || {}, list = sh.list || [];
  var byPool = function (v) { return list.filter(function (s) { return s.pool === v; }).length; };
  var bs = useState(null); var browseShare = bs[0], setBrowseShare = bs[1];

  // P15-05: storage analyzer — cached results only, no on-request scanning.
  var saState = useState(null); var storageData = saState[0], setStorageData = saState[1];
  var selState = useState(null); var selectedShare = selState[0], setSelectedShare = selState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=storage_analyzer', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setStorageData(j); if (j.shares && j.shares.length) setSelectedShare(j.shares[0].share); } })
      .catch(function () {});
  }, []);
  var saShares = (storageData && storageData.shares) || [];
  var current = saShares.filter(function (s) { return s.share === selectedShare; })[0] || saShares[0] || null;

  // P15-06: duplicate file finder — report-only, deletion is out of scope
  // for this ticket (goes through the cleanup framework in P16-01).
  var dupState = useState(null); var dupData = dupState[0], setDupData = dupState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=dup_report', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setDupData(j); })
      .catch(function () {});
  }, []);

  return h('div', null,
    h('div', { class: 'v-cards' },
      [
        { label: 'Shares', icon: 'fa-folder-open-o', color: PAL['v-accent'], value: String(sh.total || 0),
          sub: (sh.cache || 0) + ' using the pool' },
        { label: 'Pool + array', icon: 'fa-server', color: PAL['v-ok-fg'], value: String(byPool('yes')),
          sub: 'cache then array' },
        { label: 'Pool only', icon: 'fa-exclamation-triangle', color: PAL['v-warn-fg'], value: String(byPool('only')),
          sub: 'no array copy' },
        { label: 'Array only', icon: 'fa-hdd-o', color: PAL['v-accent'], value: String(byPool('no')),
          sub: 'skips the pool' }
      ].map(function (c, i) { return h(StatCard, merge(c, { key: i })); })),
    h(Panel, { title: 'All shares', span2: true, hint: list.length + ' configured' },
      list.length ? h(Table, null,
        h('tr', null, h('th', null, 'Share'), h('th', null, 'Comment'), h('th', null, 'Storage'),
          h('th', { class: 'num' }, 'Free'), h('th', null, '')),
        list.map(function (s) {
          return h('tr', { key: s.name },
            h('td', { class: 'v-name' }, s.name),
            h('td', null, h(ShareCommentCell, { share: s.name, existing: s.comment })),
            h('td', null, h(Pill, { kind: s.pool === 'only' ? 'warn' : s.pool === 'yes' ? 'run' : 'stop' },
              s.pool === 'yes' ? 'pool + array' : s.pool === 'only' ? 'pool only' : 'array only')),
            h('td', { class: 'num' }, s.free ? bytes(s.free, 1) : '—'),
            h('td', null, h('button', { class: 'v-btn xs', onClick: function () { setBrowseShare(s.name); } },
              h('i', { class: 'fa fa-folder-open' }), ' Browse')));
        }))
        : h('div', { class: 'v-empty' }, 'No shares configured.')),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Storage analyzer', span2: true,
        hint: storageData && storageData.scanned_at ? 'last scan ' + ageLabel(storageData.scanned_at) : '' },
        !storageData ? h('div', { class: 'v-empty' }, 'Loading…')
        : !saShares.length ? h('div', { class: 'v-empty' },
            'No scan results yet — the nightly storage-analyzer scan (03:10) hasn\'t run, or every share\'s disks were spun down at scan time. Run scripts/vitals-storage-scan.php --force to seed data now.')
        : h('div', null,
            h('div', { class: 'v-tabs-mini' }, saShares.map(function (s) {
              return h('button', { key: s.share, class: 'v-btn xs' + (s.share === selectedShare ? ' active' : ''),
                onClick: function () { setSelectedShare(s.share); } }, s.share + ' · ' + bytes(s.total_bytes));
            })),
            current ? h('div', { class: 'v-storage-detail' },
              h('div', { class: 'v-storage-col' },
                h('h4', null, 'Top folders in ' + current.share),
                current.top_folders.length ? h(Table, { noScroll: true },
                  h('tr', null, h('th', null, 'Folder'), h('th', { class: 'num' }, 'Size')),
                  current.top_folders.slice(0, 20).map(function (f, i) {
                    var pct = current.total_bytes ? (100 * f.bytes / current.total_bytes) : 0;
                    return h('tr', { key: i },
                      h('td', { class: 'v-name' }, f.name),
                      h('td', { class: 'num' }, bytes(f.bytes) + ' (' + pct.toFixed(0) + '%)'));
                  })) : h('div', { class: 'v-empty' }, 'No subfolder breakdown.'),
                current.stale_count ? h('div', { class: 'v-storage-stale' },
                  h('i', { class: 'fa fa-clock-o' }), ' ' + bytes(current.stale_bytes) + ' across '
                    + current.stale_count + ' file(s) not accessed in over a year (sampled)') : null),
              h('div', { class: 'v-storage-col' },
                h('h4', null, '30-day growth'),
                current.growth.length >= 2
                  ? h(Chart, { series: [{ name: current.share, color: PAL['v-accent'],
                        points: current.growth }], height: 190, yFmt: bytes })
                  : h('div', { class: 'v-empty' }, 'Needs 2+ nightly scans to chart growth — currently '
                      + current.growth.length + '.'),
                Object.keys(current.file_types).length ? h('div', { class: 'v-storage-types' },
                  h('h4', null, 'By file type (sampled)'),
                  Object.keys(current.file_types).slice(0, 8).map(function (ext) {
                    var b = current.file_types[ext];
                    var pct = current.total_bytes ? Math.min(100, 100 * b / current.total_bytes) : 0;
                    return h('div', { key: ext, class: 'v-storage-type-row' },
                      h('span', { class: 'v-storage-type-name' }, '.' + ext),
                      h('div', { class: 'v-bar' }, h('i', { style: 'width:' + pct + '%;background:' + PAL['v-accent'] })),
                      h('span', { class: 'v-storage-type-size' }, bytes(b)));
                  })) : null)) : null))),
    h(Panel, { title: 'Duplicate files', span2: true,
      hint: dupData && dupData.scanned_at ? bytes(dupData.total_wasted) + ' wasted · last scan ' + ageLabel(dupData.scanned_at) : '' },
      !dupData ? h('div', { class: 'v-empty' }, 'Loading…')
      : !dupData.groups.length ? h('div', { class: 'v-empty' },
          dupData.scanned_at ? 'No duplicate files found in the last scan.'
            : 'No scan results yet — the weekly duplicate scan (Sunday 04:00) hasn\'t run. Run scripts/vitals-dup-scan.php --force to seed data now.')
      : h(Table, null,
          h('tr', null, h('th', null, 'Size'), h('th', { class: 'num' }, 'Copies'),
            h('th', { class: 'num' }, 'Wasted'), h('th', null, 'Paths')),
          dupData.groups.slice(0, 40).map(function (g, i) {
            return h('tr', { key: i },
              h('td', null, bytes(g.size)),
              h('td', { class: 'num' }, String(g.file_count)),
              h('td', { class: 'num' }, bytes(g.wasted_bytes)),
              h('td', { class: 'muted v-dup-paths' }, g.files.join('  ·  ')));
          }))),
    browseShare ? h(ShareBrowser, { share: browseShare, onClose: function () { setBrowseShare(null); } }) : null);
}

/** Read-only /mnt/user/<share> file browser, driven by ?action=browse.
 *  Client keeps its own path stack so "up a level" doesn't need a server
 *  round trip for the parent path — v_browse_share() re-validates on
 *  every request regardless (never trust client-held state for security). */
function ShareBrowser(P) {
  var s1 = useState(''); var path = s1[0], setPath = s1[1];
  var s2 = useState(null); var data = s2[0], setData = s2[1];
  var s3 = useState(false); var loading = s3[0], setLoading = s3[1];

  var go = function (p) {
    setLoading(true);
    fetch(ENDPOINT + '?action=browse&share=' + encodeURIComponent(P.share) + '&path=' + encodeURIComponent(p))
      .then(function (r) { return r.json(); })
      .then(function (j) { setData(j); setPath(p); setLoading(false); })
      .catch(function () { setLoading(false); });
  };

  useEffect(function () { go(''); }, [P.share]);

  var parts = path ? path.split('/') : [];
  return h('div', { class: 'v-modal-backdrop', onClick: P.onClose },
    h('div', { class: 'v-modal', onClick: function (e) { e.stopPropagation(); } },
      h('div', { class: 'v-modal-head' },
        h('div', null, h('i', { class: 'fa fa-folder-open' }), ' ', P.share,
          parts.length ? h('span', { class: 'muted' }, ' / ' + parts.join(' / ')) : null),
        h('button', { class: 'v-btn xs', onClick: P.onClose }, h('i', { class: 'fa fa-times' }))),
      h('div', { class: 'v-modal-body' },
        path ? h('div', { class: 'v-browse-row muted', onClick: function () {
                  var up = parts.slice(0, -1).join('/'); go(up);
                } },
                h('i', { class: 'fa fa-level-up' }), ' ..') : null,
        loading ? h('div', { class: 'v-empty' }, 'Loading…')
        : data && data.error ? h('div', { class: 'v-empty' }, data.error)
        : (data && data.entries || []).length === 0 ? h('div', { class: 'v-empty' }, 'Empty directory.')
        : (data.entries || []).map(function (e) {
            return h('div', { key: e.name, class: 'v-browse-row',
              onClick: e.dir ? function () { go((path ? path + '/' : '') + e.name); } : null },
              h('i', { class: 'fa ' + (e.dir ? 'fa-folder' : 'fa-file-o') }),
              h('span', { class: 'v-browse-name' }, e.name),
              e.dir ? null : h('span', { class: 'v-browse-meta' }, bytes(e.size)),
              h('span', { class: 'v-browse-meta' }, ts(e.mtime)));
          }))));
}

/** Shows the REAL Unraid share comment (from shares.ini, the same field
 *  ShareEdit.page writes) with an AI Generate/Regenerate button beside
 *  it. "Generate" kicks off agent/share-comment.mjs fire-and-forget on
 *  the PHP side, polls ?action=share_comment for the result, then
 *  immediately POSTs it to ?action=apply_share_comment — which pushes it
 *  through Unraid's own /update.htm path — so the AI's output becomes the
 *  actual share comment instead of living in a separate plugin-only
 *  column (user: "when we have AI comment here, it should just update
 *  comment field instead"). LLM latency on this hardware is real (tens of
 *  seconds to a couple minutes) — the poll interval is intentionally slow
 *  (6s) so it doesn't hammer the endpoint. On a failed generation (status
 *  'error') the button becomes "Retry" so a transient LLM/agent failure
 *  isn't a dead end (user: "we should be able somehow to retry study if
 *  it has error" — same failure-recovery pattern applied here too). */
function ShareCommentCell(P) {
  var s = useState(null); var result = s[0], setResult = s[1];
  var b = useState(false); var busy = b[0], setBusy = b[1];
  var a = useState(false); var applying = a[0], setApplying = a[1];
  var timerRef = useRef(null);

  var apply = function (comment) {
    setApplying(true);
    var body = new URLSearchParams({ share: P.share, comment: comment, csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT + '?action=apply_share_comment', { method: 'POST', body: body })
      .then(function () { setApplying(false); })
      .catch(function () { setApplying(false); });
  };

  var poll = function () {
    fetch(ENDPOINT + '?action=share_comment&share=' + encodeURIComponent(P.share))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok && j.comment) {
          setResult(j.comment); setBusy(false);
          if (j.comment.status === 'done' && j.comment.comment) apply(j.comment.comment);
        }
        else if (busy) { timerRef.current = setTimeout(poll, 6000); }
      })
      .catch(function () { if (busy) timerRef.current = setTimeout(poll, 6000); });
  };

  useEffect(function () {
    poll();
    return function () { if (timerRef.current) clearTimeout(timerRef.current); };
  }, [P.share]);

  var generate = function () {
    setBusy(true);
    var body = new URLSearchParams({ share: P.share, csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT + '?action=gen_share_comment', { method: 'POST', body: body })
      .then(function () { timerRef.current = setTimeout(poll, 6000); })
      .catch(function () { setBusy(false); });
  };

  var failed = result && result.status === 'error';
  var label = busy ? 'Generating…' : applying ? 'Applying…' : failed ? 'Retry' : (P.existing ? 'Regenerate' : 'Generate');
  var icon = busy || applying ? 'fa-spinner fa-spin' : failed ? 'fa-refresh' : 'fa-magic';

  return h('div', { class: 'v-share-comment' },
    P.existing ? h('div', { class: 'muted' }, P.existing) : h('div', { class: 'muted' }, '—'),
    failed ? h('div', { class: 'v-share-comment-err' }, 'AI generation failed.') : null,
    h('button', { class: 'v-btn xs' + (P.existing ? '' : ' primary'), style: 'margin-top:4px',
      disabled: busy || applying, onClick: generate },
      h('i', { class: 'fa ' + icon }), ' ' + label));
}

/* ------------------------------------------------------------------ power */

/**
 * Whole-machine power draw. total_watts only ever sums components the
 * backend actually detected real sensors for (v_power() in collect.php) —
 * on this box that's CPU package via Intel-style RAPL... except this box
 * is a Ryzen 9 5950X, which has NO RAPL/amd_energy sysfs power number
 * without a kernel module the box doesn't load, so total_includes may be
 * empty and cpu_watts/total_watts null. That's shown honestly as "not
 * available on this hardware", never a fabricated estimate.
 */
function PowerTab(P) {
  var d = P.d, pts = P.pts;
  var pw = d.power || {};
  var have = (pw.total_includes || []).length > 0;
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var haveHistory = pts.some(function (p) { return p.watts_total != null; });

  // P15-08: real daily kWh + cost from the hourly rollup, not a
  // steady-state guess off the current instantaneous draw.
  var erState = useState(null); var energy = erState[0], setEnergy = erState[1];
  var priceState = useState(''); var priceInput = priceState[0], setPriceInput = priceState[1];
  var savedState = useState(false); var justSaved = savedState[0], setJustSaved = savedState[1];
  useEffect(function () {
    fetch(ENDPOINT + '?action=energy_report', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setEnergy(j); if (j.price_per_kwh != null) setPriceInput(String(j.price_per_kwh)); } })
      .catch(function () {});
  }, []);
  var savePrice = function () {
    var v = parseFloat(priceInput);
    if (!(v > 0)) return;
    var body = new URLSearchParams({ PRICE_PER_KWH: String(v), csrf_token: (window.__V_CSRF__ || '') });
    fetch(ENDPOINT + '?action=save_settings', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function () { setJustSaved(true); setTimeout(function () { setJustSaved(false); }, 2000);
        fetch(ENDPOINT + '?action=energy_report', { cache: 'no-store' }).then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.ok) setEnergy(j); }); })
      .catch(function () {});
  };

  var componentCards = [];
  if (pw.cpu_watts != null) componentCards.push({ label: 'CPU package', icon: 'fa-microchip',
    color: PAL['v-cpu'], value: pw.cpu_watts.toFixed(1), unit: 'W',
    sub: 'from CPU energy-counter sensor (RAPL)' });
  if (pw.gpu_watts != null) componentCards.push({ label: 'GPU', icon: 'fa-tv',
    color: PAL['v-gpu'], value: pw.gpu_watts.toFixed(1), unit: 'W', sub: 'nvidia-smi power.draw' });
  if (pw.ups_watts != null) componentCards.push({ label: 'UPS load', icon: 'fa-plug',
    color: PAL['v-accent'], value: pw.ups_watts.toFixed(1), unit: 'W',
    sub: (pw.ups_load_pct != null ? pw.ups_load_pct + '% of rated capacity' : '') });

  return h('div', null,
    h('div', { class: 'v-cards v-cards-4' },
      h(StatCard, { label: 'Total draw', icon: 'fa-bolt',
        color: have ? PAL['v-warn-fg'] : '#8b8f9a',
        value: have ? pw.total_watts.toFixed(1) : '—', unit: have ? 'W' : '',
        sub: have
          ? ('includes: ' + pw.total_includes.join(' + '))
          : 'No power sensor detected on this hardware' }),
      componentCards.map(function (c, i) { return h(StatCard, merge(c, { key: i })); }),
      // Pad the row out to 4 cells with an explanatory card when fewer than
      // 3 components were detected, so the grid doesn't look broken/empty.
      componentCards.length === 0 ? h('div', { class: 'v-card', style: 'opacity:.6' },
        h('div', { class: 'v-card-label' }, 'Why so few sensors?'),
        h('div', { class: 'v-card-sub', style: 'margin-top:6px;line-height:1.5' },
          'Whole-system wattage needs a real sensor per component — this app never ' +
          'guesses. Intel CPUs expose it via RAPL; AMD Ryzen normally does not unless ' +
          'the amd_energy kernel module is loaded. A discrete GPU needs nvidia-smi ' +
          '(and its driver actually loaded, not passed through to a VM). A UPS needs ' +
          'apcupsd/apcaccess configured and running.')) : null),

    h('div', { class: 'v-grid' },
      h(Panel, { title: 'Power draw over time', span2: true,
        hint: have ? 'total + per-component, last 24 h' : 'no data — no power sensor detected' },
        !haveHistory ? h('div', { class: 'v-empty' }, 'No power history recorded yet — check back after a few collector ticks.')
        : h(Chart, {
            series: [
              pw.cpu_watts != null || pts.some(function (p) { return p.watts_cpu != null; }) ?
                { name: 'CPU', color: PAL['v-cpu'], points: pick(function (p) { return p.watts_cpu; }) } : null,
              pts.some(function (p) { return p.watts_gpu != null; }) ?
                { name: 'GPU', color: PAL['v-gpu'], points: pick(function (p) { return p.watts_gpu; }) } : null,
              pts.some(function (p) { return p.watts_ups != null; }) ?
                { name: 'UPS load', color: PAL['v-accent'], points: pick(function (p) { return p.watts_ups; }) } : null,
            ].filter(Boolean),
            height: 220, area: true, yFmt: function (v) { return v + 'W'; }
          })),
      have ? h(Panel, { title: 'Energy use & cost', hint: energy && energy.current_watts_avg_24h != null ? energy.current_watts_avg_24h + 'W avg, last 24h' : '' },
        h('div', null,
          h('div', { class: 'v-price-row' },
            h('label', { class: 'muted' }, 'Price per kWh: $'),
            h('input', { type: 'number', step: '0.01', min: '0', value: priceInput, class: 'v-price-input',
              onInput: function (e) { setPriceInput(e.target.value); } }),
            h('button', { class: 'v-btn xs', onClick: savePrice }, justSaved ? 'Saved ✓' : 'Save')),
          !energy || !energy.days.length ? h('div', { class: 'v-empty', style: 'margin-top:10px' },
            'No hourly history with power data yet — check back after a few hours of uptime.')
          : h('div', null,
              h(Chart, { series: [{ name: 'kWh/day', color: PAL['v-warn-fg'],
                points: energy.days.map(function (d) { return [Date.parse(d.day + 'T00:00:00Z') / 1000, d.kwh]; }) }],
                height: 150, yFmt: function (v) { return v.toFixed(1) + ' kWh'; } }),
              h(Table, { noScroll: true },
                h('tr', null, h('th', null, 'Day'), h('th', { class: 'num' }, 'kWh'),
                  h('th', { class: 'num' }, 'Cost'), h('th', null, 'Source')),
                energy.days.slice(-10).reverse().map(function (dd) {
                  return h('tr', { key: dd.day },
                    h('td', null, dd.day + (dd.estimated ? ' (partial day)' : '')),
                    h('td', { class: 'num' }, dd.kwh.toFixed(2)),
                    h('td', { class: 'num' }, dd.cost != null ? '$' + dd.cost.toFixed(2) : '—'),
                    h('td', { class: 'muted' }, (dd.includes || []).join('+') || '—'));
                })))))
        : null));
}

function HwTab(P) {
  var d = P.d, pts = P.pts;
  var gpu = d.gpu || {};
  var upsRaw = d.ups;
  var ups = (upsRaw && !Array.isArray(upsRaw)) ? upsRaw : {};
  var upsKeys = [['Status', 'STATUS'], ['Line voltage', 'LINEV'], ['Load', 'LOADPCT'],
                 ['Battery charge', 'BCHARGE'], ['Runtime left', 'TIMELEFT'],
                 ['Battery voltage', 'BATTV'], ['Model', 'MODEL']];
  var haveUps = upsKeys.filter(function (r) { return ups[r[1]] != null; });
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var gpuHist = pts.some(function (p) { return p.gpu_hist && p.gpu_hist[0] != null; });
  var gl = (!Array.isArray(gpu) && gpu.available !== false && gpu.util != null) ? gpu
         : (Array.isArray(gpu) && gpu.length ? gpu[0] : null);

  /* hwmon sensors: current values + ring time-series per sensor id. */
  var sensors = d.sensors || { temps: [], fans: [], pwms: [], volts: [] };
  var tempSeries = pts.some(function (p) { return p.sensors_t && Object.keys(p.sensors_t).length; });
  var fanSeries = pts.some(function (p) { return p.sensors_f && Object.keys(p.sensors_f).length; });
  var voltSeries = pts.some(function (p) { return p.sensors_v && Object.keys(p.sensors_v).length; });
  var cpuFreq = d.cpu_freq || { available: false };
  var cpuFreqSeries = pts.some(function (p) { return p.cpu_mhz_avg != null; });
  var sensorMeta = {};   // id -> {label, chip, max, crit}
  (sensors.temps || []).forEach(function (t) { sensorMeta[t.id] = t; });
  var topTempIds = (sensors.temps || []).slice(0, 4).map(function (t) { return t.id; });
  var topFanIds = (sensors.fans || []).slice(0, 4).map(function (f) { return f.id; });
  var PAL_T = ['v-temp', 'v-cpu', 'v-rx', 'v-gpu'];
  var lvl = function (t) {
    if (t.crit != null && t.value >= t.crit) return 'crit';
    if (t.max != null && t.value >= t.max) return 'warn';
    if (t.value >= 75) return 'crit';
    if (t.value >= 60) return 'warn';
    return 'ok';
  };
  // Distance to threshold for the proximity readout (reference: "9% remaining").
  var headroom = function (t) {
    var ref = t.crit != null ? t.crit : (t.max != null ? t.max : null);
    if (ref == null) return null;
    return Math.round((ref - t.value) * 10) / 10;
  };
  var tempSeriesFor = function (id) {
    return pts.map(function (p) { return [p.t, p.sensors_t ? p.sensors_t[id] : null]; });
  };
  var fanSeriesFor = function (id) {
    return pts.map(function (p) { return [p.t, p.sensors_f ? p.sensors_f[id] : null]; });
  };
  var voltSeriesFor = function (id) {
    return pts.map(function (p) { return [p.t, p.sensors_v ? p.sensors_v[id] : null]; });
  };
  var cpuFreqSeriesFor = function () {
    return pts.map(function (p) { return [p.t, p.cpu_mhz_avg]; });
  };
  // OctoPrint-style per-series stats under a chart.
  var hwSeriesStats = function (ids, meta, sfn) {
    return h(ChartStats, { series: ids.map(function (id, i) {
      var m = meta[id] || {};
      return { name: m.label || id.split('/').pop(), color: PAL[PAL_T[i % 4]],
        points: sfn(id), fmt: function (v) { return (Math.round(v * 10) / 10) + '°'; } };
    }) });
  };

  // Thermal map: every temperature the box exposes — chip sensors AND disk
  // temps — as one heat-colored tile field. Color scale: green <45, amber
  // 45-60, orange 60-75, red >=75 (or within 90% of the chip threshold).
  var arr = d.array || {};
  var diskTemps = [];
  (arr.data || []).concat(arr.parity || [], arr.cache || []).forEach(function (x) {
    if (x && x.name && x.temp != null) diskTemps.push({ id: 'disk:' + x.name, label: x.name, value: x.temp, max: null, crit: null });
  });
  var heat = function (v, t) {
    var ref = t && (t.crit != null ? t.crit : (t.max != null ? t.max : null));
    if (ref != null && v >= ref * 0.92) return '#ef4444';
    if (v >= 75) return '#ef4444';
    if (v >= 60) return '#f59e0b';
    if (v >= 45) return '#eab308';
    return '#34d399';
  };
  var ThermalMap = function () {
    var all = (sensors.temps || []).concat(diskTemps);
    if (!all.length) return h('div', { class: 'v-empty' }, 'No temperature sensors found.');
    return h('div', { class: 'v-therm-grid' },
      all.map(function (t) {
        var lv = t.crit != null && t.value >= t.crit ? 'crit' : (t.value >= 75 ? 'crit' : (t.value >= 60 ? 'warn' : 'ok'));
        return h('div', { key: t.id, class: 'v-therm v-therm-' + lv, title: sensorLabel(t) + ': ' + t.value + '°C' },
          h('span', { class: 'v-therm-val' }, Math.round(t.value) + '°'),
          h('span', { class: 'v-therm-label' }, sensorLabel(t)));
      }));
  };

  // Radar: normalize the six dimensions that define "system health" to a
  // 0-100 proximity-to-limit score (rim = at the limit).
  var memPct = d.mem && d.mem.pct != null ? d.mem.pct : null;
  var hottestTemp = 0;
  (sensors.temps || []).concat(diskTemps).forEach(function (t) { if (t.value > hottestTemp) hottestTemp = t.value; });
  var load = d.load || {};
  var load1 = load['1min'] != null ? load['1min'] : (Array.isArray(load) ? load[0] : (load.one != null ? load.one : null));
  var cores = (d.cpu && d.cpu.cores && d.cpu.cores.count) || 8;
  var cpuPct = d.cpu && d.cpu.total != null ? d.cpu.total : 0;
  // Network: peak interface throughput this window vs 1 Gbps reference —
  // it is the honest "how busy is the wire" proxy available in the slim points.
  var netPeak = 0;
  (pts.slice(-12)).forEach(function (p) {
    var n = p.net || {};
    Object.keys(n).forEach(function (k) { var tot = (n[k][0] || 0) + (n[k][1] || 0); if (tot > netPeak) netPeak = tot; });
  });
  var netPct = Math.min(100, (netPeak / (125 * 1024 * 1024)) * 100);
  var radarAxes = [
    { label: 'CPU', value: Math.min(100, cpuPct) },
    { label: 'Memory', value: Math.min(100, memPct || 0) },
    { label: 'Temp', value: Math.min(100, (hottestTemp / 90) * 100) },
    { label: 'Load', value: Math.min(100, load1 != null ? (load1 / cores) * 100 : 0) },
    { label: 'Network', value: netPct },
  ];
  var busyDisks = diskTemps.length;
  var dockerRunning = d.docker && d.docker.running != null ? d.docker.running : null;

  return h('div', null,
    h(Panel, { title: 'Thermal map', span2: true,
      hint: (sensors.temps || []).length + ' chip sensors + ' + busyDisks + ' disks — color is the band, number is °C' },
      h(ThermalMap)),
    h(Panel, { title: 'System health radar', span2: true,
      hint: 'distance to limit per dimension — rim = at the limit' },
      h('div', { class: 'v-radar-wrap' },
        h(RadarChart, { axes: radarAxes, size: 240 }),
        h('div', { class: 'v-radar-legend' },
          radarAxes.map(function (a, i) {
            return h('div', { key: i, class: 'v-radar-row' },
              h('span', { class: 'v-radar-dot', style: 'background:' + (a.value >= 85 ? '#ef4444' : a.value >= 60 ? '#f59e0b' : '#34d399') }),
              h('span', { class: 'v-radar-name' }, a.label),
              h('span', { class: 'v-radar-val' }, Math.round(a.value) + '%'));
          })),
        h('div', { class: 'v-radar-note muted' },
          dockerRunning != null ? dockerRunning + ' containers running' : ''))),
    h(Panel, { title: 'Temperatures', span2: true,
      hint: (sensors.temps || []).length + ' sensors — headroom is distance to the chip threshold' },
      !(sensors.temps || []).length ? h('div', { class: 'v-empty' }, 'No hwmon temperature sensors found.')
      : h('div', { class: 'v-gauge-grid' },
          sensors.temps.map(function (t) {
            var hr = headroom(t);
            var ref = t.crit != null ? t.crit : (t.max != null ? t.max : null);
            // Gauge fill = how far this reading has closed the gap toward its
            // chip threshold (0% = cold, 100% = at/above crit or max). With
            // no chip-reported threshold, fall back to the same 60/75 bands
            // lvl() uses so the ring still means something.
            var pct = ref != null ? (t.value / ref) * 100 : (t.value / 90) * 100;
            var level = lvl(t);
            var color = level === 'crit' ? '#f87171' : level === 'warn' ? '#f59e0b' : '#34d399';
            return h('div', { key: t.id, class: 'v-gauge-tile v-temp-' + level, title:
              (t.crit != null ? 'chip crit: ' + t.crit + '°C' : '') +
              (t.max != null ? (t.crit != null ? ' · ' : '') + 'chip max: ' + t.max + '°C' : '') },
              h(Gauge, { pct: pct, color: color, size: 92, thickness: 8,
                big: t.value != null ? t.value.toFixed(1) + '°' : '—',
                small: hr != null ? hr + '° left' : null }),
              h('div', { class: 'v-sensor-label', title: t.chip }, t.label));
          }))),
    tempSeries ? h(Panel, { title: 'Temperature history', span2: true, hint: 'hottest 4 sensors, last 24 h' },
      h(Chart, {
        series: topTempIds.map(function (id, i) {
          return { name: (sensorMeta[id] || {}).label || id, color: PAL[PAL_T[i % 4]],
            points: tempSeriesFor(id) };
        }),
        max: Math.max(90, Math.ceil(Math.max.apply(null, [60].concat(
          topTempIds.map(function (id) {
            var m = sensorMeta[id];
            return m && m.max ? m.max : 0;
          })))) ), height: 190, area: true, hideLegend: true,
        thresholds: topTempIds.map(function (id) {
          var m = sensorMeta[id];
          return m && m.max ? { v: m.max, color: '#f8717166', label: m.label + ' max' } : null;
        }).filter(Boolean),
        yFmt: function (v) { return v + '°C'; }
      }),
      hwSeriesStats(topTempIds, sensorMeta, tempSeriesFor)) : null,
    h(Panel, { title: 'Thermal vs load', span2: true,
      hint: 'CPU package temp (left) over total CPU load (right) — throttling shows as temp up while load drops' },
      tempSeries ? h(Chart, {
        series: [
          { name: 'CPU temp °C', color: PAL['v-temp'], points: tempSeriesFor(topTempIds[0] || '') },
          { name: 'CPU load %', color: PAL['v-cpu'], points: pick(function (p) { return p.cpu; }), axis: 2 },
        ],
        max: 100, y2Max: 100, height: 170, area: true, y2Fmt: function (v) { return v + '%'; },
        yFmt: function (v) { return v + '°'; }
      }) : h('div', { class: 'v-empty' }, 'Sensor history still building.')),
    h(Panel, { title: 'Fans', span2: true,
      hint: (sensors.fans || []).length + ' fans · PWM duty from the controller' },
      !(sensors.fans || []).length ? h('div', { class: 'v-empty' }, 'No hwmon fan sensors found (server fans on a controller this kernel does not expose, or none present).')
      : h('div', { class: 'v-fan-grid' },
          sensors.fans.map(function (f) {
            // Match the fan's own PWM channel by number (fan3 -> pwm3) —
            // most Super-I/O chips number fan/pwm channels in step. Falling
            // back to "first PWM on the chip" (the old behavior) showed the
            // SAME duty % on every fan on a multi-channel chip.
            var fanNum = (f.id.match(/\/fan(\d+)$/) || [])[1];
            var pwmSame = fanNum ? (sensors.pwms || []).find(function (p) {
              return p.id === f.chip + '/pwm' + fanNum;
            }) : null;
            var pwmAny = (sensors.pwms || []).filter(function (p) { return p.chip === f.chip; });
            var pwm = pwmSame || (pwmAny.length === 1 ? pwmAny[0] : null);
            var duty = pwm ? pwm.duty_pct : null;
            var mode = pwm ? pwm.mode : null;
            // Stalled = reads 0 now AND spun at some point in the visible
            // history ring. An always-0 fan is an unused/unwired header
            // (common — motherboards expose far more fan channels than
            // most cases have headers for), not a fault; PWM duty alone is
            // NOT a safe signal here since manual/full-speed mode drives
            // every channel on the chip regardless of whether anything is
            // plugged into it (seen live: 3 of 7 nct6797 channels sit at 0
            // RPM with 86% duty simply because no fan is wired there).
            var hist = fanSeriesFor(f.id);
            var everSpun = hist.some(function (p) { return p[1] > 0; });
            var stalled = f.rpm === 0 && everSpun;
            return h('div', { key: f.id, class: 'v-sensor v-fan' + (stalled ? ' stall' : (f.rpm > 0 ? '' : ' idle')) },
              h(FanIcon, { rpm: f.rpm, duty: duty, stalled: stalled }),
              h('div', { class: 'v-fan-text' },
                h('div', { class: 'v-sensor-val' }, f.rpm > 0 ? f.rpm : '0'),
                h('div', { class: 'v-sensor-label' }, 'RPM · ' + f.label),
                h('div', { class: 'v-sensor-th' },
                  stalled ? h('b', { class: 'crit' }, 'stalled — check cable')
                    : (duty != null ? duty + '% duty' : '') + (mode ? (duty != null ? ' · ' : '') + mode : '') || '\u00A0')));
          })),
      fanSeries ? h('div', null,
        h(Chart, {
          series: topFanIds.map(function (id, i) {
            return { name: id.split('/').pop(), color: PAL[PAL_T[i % 4]], points: fanSeriesFor(id) };
          }),
          height: 150, area: true, yFmt: function (v) { return Math.round(v) + ' RPM'; }
        }),
        h(ChartStats, { series: topFanIds.map(function (id, i) {
          return { name: id.split('/').pop(), color: PAL[PAL_T[i % 4]], points: fanSeriesFor(id),
            fmt: function (v) { return Math.round(v) + ''; } };
        }) })) : null),
    h(Panel, { title: 'Voltage rails', span2: true,
      hint: (sensors.volts || []).length + ' rails — generic in<N> naming: most Super-I/O chips report no per-rail label' },
      !(sensors.volts || []).length ? h('div', { class: 'v-empty' }, 'No hwmon voltage sensors found.')
      : h('div', null,
          h('div', { class: 'v-gauge-grid' },
            sensors.volts.slice(0, 8).map(function (v) {
              // Voltage rails don't have a "hotter is worse" direction like
              // temps — flag only when the chip's own min/max window (if it
              // reports one) is breached; otherwise just display the value.
              var outOfRange = (v.min != null && v.min > 0 && v.value < v.min) ||
                                (v.max != null && v.max > 0 && v.value > v.max);
              return h('div', { key: v.id, class: 'v-sensor' + (outOfRange ? ' v-volt-oor' : '') },
                h('i', { class: 'fa fa-bolt v-sensor-icon' + (outOfRange ? ' crit' : '') }),
                h('div', { class: 'v-sensor-val' }, v.value.toFixed(2) + ' V'),
                h('div', { class: 'v-sensor-label' }, v.label),
                h('div', { class: 'v-sensor-th' },
                  outOfRange ? h('b', { class: 'crit' }, 'out of chip range')
                    : (v.min || v.max) ? ('range ' + (v.min != null ? v.min.toFixed(2) : '?') + '–' +
                        (v.max != null ? v.max.toFixed(2) : '?') + ' V') : '\u00A0'));
            })),
          voltSeries ? h(Chart, {
            series: (sensors.volts || []).slice(0, 4).map(function (v, i) {
              return { name: v.label, color: PAL[PAL_T[i % 4]], points: voltSeriesFor(v.id) };
            }),
            height: 150, area: true, hideLegend: true, yFmt: function (v) { return v.toFixed(2) + 'V'; }
          }) : null)),
    cpuFreq.available ? h(Panel, { title: 'CPU frequency', span2: true,
      hint: cpuFreq.cores ? Object.keys(cpuFreq.cores).length + ' threads — throttling shows as freq pinned near min while load stays high' : '' },
      h('div', null,
        h('table', null, [
          ['Average', cpuFreq.avg_mhz + ' MHz'],
          ['Lowest thread', cpuFreq.min_mhz + ' MHz'],
          ['Highest thread', cpuFreq.max_mhz + ' MHz'],
        ].map(function (r, i) {
          return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]),
            h('td', { class: 'num v-name' }, String(r[1])));
        })),
        cpuFreqSeries ? h(Chart, {
          series: [{ name: 'Avg CPU freq (MHz)', color: PAL['v-cpu'], points: cpuFreqSeriesFor() }],
          height: 150, area: true, hideLegend: true, yFmt: function (v) { return Math.round(v) + ' MHz'; }
        }) : null)) : null,
    h(Panel, { title: 'Virtual machines', span2: true,
      hint: d.vms && d.vms.available ? d.vms.running + ' running / ' + d.vms.count + ' total' : '' },
      h(VmTable, { vms: d.vms })),
    h(Panel, { title: 'GPU', span2: true },
      gpu.available === false
        ? h('div', { class: 'v-empty' },
            h('i', { class: 'fa fa-info-circle' }),
            gpu.reason === 'driver-not-loaded'
              ? 'nvidia-smi is installed but the host driver is not loaded — the GPU is most likely passed through to a VM.'
              : 'GPU not available' + (gpu.reason ? ' (' + gpu.reason + ')' : '') + '.',
            gpu.smi ? h('div', { class: 'v-mono muted' }, gpu.smi) : null)
        : gl ? h('div', null,
            h('table', null, [
              ['Model', gl.name], ['Utilisation', gl.util == null ? null : gl.util + '%'],
              ['VRAM', bytes(gl.mem_used) + ' / ' + bytes(gl.mem_total)],
              ['Temperature', gl.temp == null ? null : gl.temp + '°C'],
              ['Power', gl.power == null ? null : gl.power.toFixed(1) + ' W'],
              ['Fan', gl.fan == null ? null : gl.fan + '%']
            ].filter(function (r) { return r[1] != null; }).map(function (r, i) {
              return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]),
                h('td', { class: 'num v-name' }, String(r[1])));
            })),
            gpuHist ? h(Chart, { series: [
              { name: 'Util %', color: PAL['v-gpu'],
                points: pick(function (p) { return p.gpu_hist ? p.gpu_hist[0] : null; }) },
              { name: 'Temp °C', color: PAL['v-temp'],
                points: pick(function (p) { return p.gpu_hist ? p.gpu_hist[2] : null; }) }
            ], max: 100, height: 160, yFmt: String }) : null)
          : h('div', { class: 'v-empty' }, 'No GPU reported.')),
    h(Panel, { title: 'UPS', span2: true },
      haveUps.length
        ? h('table', null, haveUps.map(function (r, i) {
            return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]),
              h('td', { class: 'num v-name' }, String(ups[r[1]])));
          }))
        : h('div', { class: 'v-empty' },
            h('i', { class: 'fa fa-plug' }),
            'No UPS configured, or apcupsd / NUT is not reporting.')),
    h(Panel, { title: 'SMART detail', span2: true,
      hint: Object.keys(d.smart || {}).length + ' disks' },
      h(SmartTable, { d: d })),
    h(SmartSelfTestPanel, {}));
}

/* P17-04: per-disk SMART self-tests — start short/long, progress + history. */
function SmartSelfTestPanel() {
  var st = useState(null); var data = st[0], setData = st[1];
  var bs = useState('idle'); var busy = bs[0], setBusy = bs[1];
  var msgS = useState(null); var msg = msgS[0], setMsg = msgS[1];
  var load = function () {
    fetch(ENDPOINT + '?action=smart_tests', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setData(j.disks); });
  };
  useEffect(load, []);
  // poll while anything is running so progress moves
  useEffect(function () {
    if (!data) return;
    var any = Object.keys(data).some(function (k) { return data[k].running; });
    if (!any) return;
    var t = setInterval(load, 15000);
    return function () { clearInterval(t); };
  }, [data]);

  var start = function (disk, type) {
    setBusy(disk);
    fetch(ENDPOINT + '?action=smart_test_start', { method: 'POST', body: new URLSearchParams({ disk: disk, type: type }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setBusy(null);
        setMsg(j && j.ok ? ('Started ' + j.type + ' test on ' + j.device + ' — progress in this panel updates every 15 s.')
                          : ((j && j.error) || 'start failed'));
        load();
      }).catch(function () { setBusy(null); setMsg('start failed'); });
  };

  return h(Panel, { title: 'SMART self-tests', span2: true,
    hint: 'Short ≈ 2 min/disk, Long ≈ hours. Never starts during a parity check.' },
    msg ? h('div', { class: 'v-empty' }, msg) : null,
    !data ? h('div', { class: 'v-empty' }, 'Loading disks…')
      : h(Table, null,
        h('tr', null, h('th', null, 'Disk'), h('th', null, 'State'), h('th', null, 'Last result'), h('th', null, '')),
        Object.keys(data).sort().map(function (name) {
          var dk = data[name];
          var running = dk.running ? h('b', null, 'testing…' + (dk.progress_pct != null ? ' ' + dk.progress_pct + '%' : '')) : h('span', { class: 'muted' }, 'idle');
          var last = (dk.history || [])[0];
          return h('tr', { key: name },
            h('td', { class: 'v-name' }, name + ' (' + dk.device + ')'),
            h('td', null, running),
            h('td', null, last ? (last.type + ' — ' + last.result) : h('span', { class: 'muted' }, 'no tests yet')),
            h('td', null,
              h('button', { class: 'v-btn xs', onClick: function () { start(name, 'short'); }, disabled: busy === name || dk.running }, 'Short'),
              ' ',
              h('button', { class: 'v-btn xs', onClick: function () { start(name, 'long'); }, disabled: busy === name || dk.running }, 'Long')));
        })));
}

/* ------------------------------------------------------------------- kb */

/* Global toast notifications. ToastBus.push({kind, icon, title, body, ttl,
 * onClick}) renders a stack of cards bottom-right; toasts vanish after ttl
 * (default 6s) unless hovered. Used by the ask-once research flow ("ready
 * soon" / "research ready — click to view") and any future async events. */
var ToastBus = (function () {
  var items = [];
  var listeners = [];
  function push(t) {
    var item = Object.assign({ kind: 'info', ttl: 6000, at: Date.now() }, t);
    items = items.concat([item]);
    emit();
    if (item.ttl > 0) setTimeout(function () { dismiss(item); }, item.ttl);
  }
  function dismiss(item) { items = items.filter(function (x) { return x !== item; }); emit(); }
  function emit() { listeners.forEach(function (l) { l(items.slice()); }); }
  function subscribe(l) { listeners.push(l); return function () { listeners = listeners.filter(function (x) { return x !== l; }); }; }
  return { push: push, dismiss: dismiss, subscribe: subscribe };
})();

function ToastStack() {
  var s = useState([]); var list = s[0], setList = s[1];
  useEffect(function () { return ToastBus.subscribe(setList); }, []);
  if (!list.length) return null;
  return h('div', { class: 'v-toasts' },
    list.map(function (t, i) {
      return h('div', { key: i, class: 'v-toast v-toast-' + t.kind,
                        onClick: function () { ToastBus.dismiss(t); if (t.onClick) t.onClick(); } },
        h('i', { class: 'fa ' + (t.icon || 'fa-info-circle') }),
        h('div', { class: 'v-toast-body' },
          h('div', { class: 'v-toast-title' }, t.title || ''),
          t.body ? h('div', { class: 'v-toast-msg' }, t.body) : null));
    }));
}

// 'auto:disk:disk3' → 'disk agent (disk3)'; 'auto:unraid-release:7.3.2' → 'Unraid release watcher (7.3.2)'
function autoOriginLabel(origin) {
  var p = String(origin || '').split(':');
  var who = p[1] === 'unraid-release' ? 'Unraid release watcher' : (p[1] || 'system') + ' agent';
  return who + (p[2] ? ' (' + p.slice(2).join(':') + ')' : '');
}

var SEV_KB_ICON = { finding: 'fa-heartbeat', research: 'fa-flask', manual: 'fa-pencil' };
// Importance badge shown on every KB entry so the admin can tell "read
// now" from "read later" at a glance (user: "give a score low, medium,
// high, critical badge to every topic ... things that admin should read
// or read later"). Order matters for the filter chip row (most urgent
// first) and doubles as the badge tone.
var KB_SEVERITIES = ['critical', 'high', 'medium', 'low'];
var KB_SEV_LABEL = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' };
var KB_SEV_PILL = { critical: 'stop', high: 'warn', medium: 'info', low: 'run' };

function KbTab() {
  var s1 = useState(''); var q = s1[0], setQ = s1[1];
  var s2 = useState([]); var results = s2[0], setResults = s2[1];
  var s3 = useState([]); var topics = s3[0], setTopics = s3[1];
  var s4 = useState(false); var searching = s4[0], setSearching = s4[1];
  var s5 = useState(null); var topicFilter = s5[0], setTopicFilter = s5[1];
  var s6 = useState(null); var sevFilter = s6[0], setSevFilter = s6[1]; // null = all
  var s7 = useState({}); var sevCounts = s7[0], setSevCounts = s7[1];
  var s8 = useState(null); var tagFilter = s8[0], setTagFilter = s8[1]; // null = all
  var s9 = useState({}); var tagCounts = s9[0], setTagCounts = s9[1];

  var loadRecent = function (sev, tag) {
    var qs = sev ? '&severity=' + encodeURIComponent(sev) : '';
    if (tag) qs += '&tag=' + encodeURIComponent(tag);
    fetch(ENDPOINT + '?action=kb_recent' + qs, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setResults(j.docs || []); setTopics(j.topics || []); setSevCounts(j.severity_counts || {}); setTagCounts(j.tag_counts || {}); } });
  };
  useEffect(function () { loadRecent(sevFilter, tagFilter); }, [sevFilter, tagFilter]);

  var search = function (e) {
    if (e) e.preventDefault();
    if (!q.trim()) { loadRecent(sevFilter, tagFilter); return; }
    setSearching(true);
    var qs = sevFilter ? '&severity=' + encodeURIComponent(sevFilter) : '';
    if (tagFilter) qs += '&tag=' + encodeURIComponent(tagFilter);
    fetch(ENDPOINT + '?action=kb_search&q=' + encodeURIComponent(q) + qs, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setResults(j.results || []); setTopics(j.topics || []); setSevCounts(j.severity_counts || {}); setTagCounts(j.tag_counts || {}); } setSearching(false); })
      .catch(function () { setSearching(false); });
  };
  useEffect(function () { if (q.trim()) search(null); }, [sevFilter, tagFilter]);

  var shown = topicFilter ? results.filter(function (r) { return r.topic === topicFilter; }) : results;
  var totalCount = Object.keys(sevCounts).reduce(function (a, k) { return a + sevCounts[k]; }, 0);

  return h('div', null,
    h(Panel, { title: 'Search the knowledge base', span2: true,
      hint: 'built from everything the background AI agents have learned' },
      h('form', { class: 'v-kb-search', onSubmit: search },
        h('input', { class: 'v-input', style: 'flex:1', placeholder: 'e.g. cache pool temperature, disk errors, network drops…',
          value: q, onInput: function (e) { setQ(e.target.value); } }),
        h('button', { class: 'v-btn primary', type: 'submit', disabled: searching },
          h('i', { class: 'fa fa-search' }), ' Search')),
      // Severity filter row — the strong filter the user asked for: each
      // chip shows its own count so "3 critical" is visible before you
      // even click it, and multiple filter axes (severity + topic) combine
      // (AND), not replace each other.
      h('div', { class: 'v-kb-topics', style: 'margin-top:8px' },
        h('span', { class: 'v-chip' + (sevFilter === null ? ' on' : ''), onClick: function () { setSevFilter(null); } },
          'all (' + totalCount + ')'),
        KB_SEVERITIES.map(function (sv) {
          return sevCounts[sv] ? h('span', {
            key: sv, class: 'v-chip v-chip-sev-' + sv + (sevFilter === sv ? ' on' : ''),
            onClick: function () { setSevFilter(sv); },
          }, KB_SEV_LABEL[sv] + ' (' + sevCounts[sv] + ')') : null;
        })),
      // Category row — the agent-assigned tags, most-used first. Third
      // AND-axis: severity + tag + topic all combine.
      Object.keys(tagCounts).length ? h('div', { class: 'v-kb-topics' },
        h('span', { class: 'v-chip' + (tagFilter === null ? ' on' : ''), onClick: function () { setTagFilter(null); } }, 'all categories'),
        Object.keys(tagCounts).map(function (t) {
          return h('span', { key: t, class: 'v-chip v-chip-tag' + (tagFilter === t ? ' on' : ''),
            onClick: function () { setTagFilter(tagFilter === t ? null : t); } },
            t + ' (' + tagCounts[t] + ')');
        })) : null,
      topics.length ? h('div', { class: 'v-kb-topics' },
        h('span', { class: 'v-chip' + (topicFilter === null ? ' on' : ''), onClick: function () { setTopicFilter(null); } }, 'all topics'),
        topics.map(function (t) {
          return h('span', { key: t.topic, class: 'v-chip' + (topicFilter === t.topic ? ' on' : ''),
            onClick: function () { setTopicFilter(t.topic); } }, t.topic + ' (' + t.n + ')');
        })) : null),
    h(Panel, { title: q ? 'Results' : 'Recent knowledge', span2: true, hint: shown.length + ' documents' },
      !shown.length ? h('div', { class: 'v-empty' }, 'Nothing here yet — the background agents populate this as they run.')
      : shown.map(function (doc) {
          var sev = doc.severity || 'medium';
          return h('div', { key: doc.id, class: 'v-kb-doc' },
            h('div', { class: 'v-kb-doc-head' },
              h('i', { class: 'fa ' + (SEV_KB_ICON[doc.source] || 'fa-file-text-o') }),
              h('span', { class: 'v-kb-doc-title' }, doc.title),
              h(Pill, { kind: KB_SEV_PILL[sev] || 'info' }, KB_SEV_LABEL[sev] || sev),
              doc.kind && doc.kind !== 'note' ? h(Pill, { kind: doc.kind === 'study' ? 'info' : 'run' }, doc.kind) : null,
              (doc.tags || []).map(function (t) {
                return h('span', { key: t, class: 'v-tag-badge' + (tagFilter === t ? ' on' : ''),
                  title: 'Filter by category: ' + t,
                  onClick: function () { setTagFilter(tagFilter === t ? null : t); } }, t);
              }),
              doc.topic ? h('span', { class: 'v-ai-agent' }, doc.topic) : null,
              h('span', { class: 'muted', style: 'margin-left:auto' }, ts(doc.created_at))),
            doc.summary ? h('div', { class: 'v-kb-doc-summary' }, doc.summary) : null,
            doc.images && doc.images.length ? h('div', { class: 'v-kb-doc-images' },
              doc.images.map(function (im, i) {
                return h('figure', { key: i, class: 'v-kb-doc-fig' },
                  h('img', { src: KB_ASSET_BASE + im.path, alt: im.caption || doc.title, loading: 'lazy' }),
                  im.caption ? h('figcaption', null, im.caption) : null);
              })) : null,
            h('div', { class: 'v-kb-doc-body' + (doc.kind === 'report' || doc.kind === 'study' ? ' v-kb-doc-rich' : '') },
              renderMd(doc.content)));
        })));
}

/* --------------------------------------------------------------- research */

function ResearchTab() {
  var s1 = useState(''); var prompt = s1[0], setPrompt = s1[1];
  var s2 = useState([]); var jobs = s2[0], setJobs = s2[1];
  var s3 = useState(null); var activeJob = s3[0], setActiveJob = s3[1];
  var s4 = useState(false); var submitting = s4[0], setSubmitting = s4[1];
  // Ask-once: no mode/hours/tick controls. The server plans the job from
  // the prompt itself; this side shows the plan live while typing and
  // raises a toast (with an ETA estimate) on submit and on completion.
  var s5 = useState(null); var plan = s5[0], setPlan = s5[1];
  var prevStatuses = useRef({});

  var loadJobs = function () {
    fetch(ENDPOINT + '?action=research_list', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!(j && j.ok)) return;
        var jobs = j.jobs || [];
        setJobs(jobs);
        // Completion watcher: any job that leaves pending/running/studying
        // while the page is open raises a notification. Clicking it opens
        // the job — the report is delivered to the user, not silently
        // parked in a table.
        jobs.forEach(function (x) {
          var prev = prevStatuses.current[x.id];
          if (prev && prev !== x.status && (x.status === 'done' || x.status === 'error')) {
            ToastBus.push({
              kind: x.status === 'done' ? 'success' : 'warn',
              icon: x.status === 'done' ? 'fa-check-circle' : 'fa-exclamation-triangle',
              title: x.status === 'done' ? 'Research ready' : 'Research failed',
              body: (x.prompt || '').slice(0, 90) + ((x.prompt || '').length > 90 ? '…' : ''),
              ttl: 10000,
              onClick: function () { openJob(x.id); },
            });
          }
          prevStatuses.current[x.id] = x.status;
        });
      });
  };
  useEffect(function () { loadJobs(); var iv = setInterval(loadJobs, 10000); return function () { clearInterval(iv); }; }, []);

  // Live plan preview while typing (debounced, one cheap JSON endpoint).
  useEffect(function () {
    if (!prompt.trim()) { setPlan(null); return; }
    var t = setTimeout(function () {
      fetch(ENDPOINT + '?action=research_plan&prompt=' + encodeURIComponent(prompt), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) setPlan(j.plan); })
        .catch(function () {});
    }, 350);
    return function () { clearTimeout(t); };
  }, [prompt]);

  var openJob = function (id) {
    fetch(ENDPOINT + '?action=research_status&id=' + id, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setActiveJob(j.job); });
  };

  var retrying = useState(false); var retryBusy = retrying[0], setRetryBusy = retrying[1];
  var retryJob = function (id) {
    setRetryBusy(true);
    var body = new URLSearchParams({ id: String(id), csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT + '?action=research_retry', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { setRetryBusy(false); if (j && j.ok) { loadJobs(); openJob(id); } })
      .catch(function () { setRetryBusy(false); });
  };

  // A studying job's own status doesn't change tick-to-tick, so a fixed
  // 10s poll (same interval as loadJobs) is enough to show new
  // observations as they land without a dedicated fast-poll path.
  useEffect(function () {
    if (!activeJob || activeJob.status !== 'studying') return;
    var iv = setInterval(function () { openJob(activeJob.id); }, 10000);
    return function () { clearInterval(iv); };
  }, [activeJob && activeJob.id, activeJob && activeJob.status]);

  var submit = function (e) {
    e.preventDefault();
    if (!prompt.trim()) return;
    setSubmitting(true);
    // Free text only — the planner decides how the job runs.
    var body = new URLSearchParams({ prompt: prompt, csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT + '?action=research_ask', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setSubmitting(false);
        if (j && j.ok) {
          setPrompt(''); setPlan(null);
          ToastBus.push({ kind: 'info', icon: 'fa-hourglass-start', ttl: 8000,
            title: j.mode === 'study' ? 'Study started' : 'Research started',
            body: (j.eta_label || '') + " — we'll notify you here when it's ready." });
          loadJobs(); openJob(j.job_id);
        } else ToastBus.push({ kind: 'warn', icon: 'fa-exclamation-triangle', title: 'Could not start research',
                               body: (j && j.error) || 'unknown error' });
      })
      .catch(function () { setSubmitting(false); });
  };

  var now = Math.floor(Date.now() / 1000);
  var studyPct = activeJob && activeJob.mode === 'study' && activeJob.study_until
    ? Math.max(0, Math.min(100, 100 * (1 - (activeJob.study_until - now) / Math.max(1, activeJob.study_until - activeJob.created_at))))
    : null;
  // Percent-complete for ANY studying job in the table below, not just the
  // one currently open — the user wants a progress indicator on research/
  // study jobs generally, not only after clicking into one.
  var jobPct = function (j) {
    if (j.mode !== 'study' || j.status !== 'studying' || !j.study_until) return null;
    return Math.max(0, Math.min(100, 100 * (1 - (j.study_until - now) / Math.max(1, j.study_until - j.created_at))));
  };

  return h('div', null,
    h(Panel, { title: 'Ask once — research anything', span2: true,
      hint: 'free text. The system detects whether this is a one-shot question or a study, and for how long.' },
      h('form', { class: 'v-kb-search', onSubmit: submit, style: 'flex-direction:column;align-items:stretch;gap:8px' },
        h('textarea', { class: 'v-input', rows: 3,
          placeholder: 'e.g. "I need to monitor CPU spikes", "Why has the cache pool been running hot this week?", "Study the system for the next 12 hours"…',
          value: prompt, onInput: function (e) { setPrompt(e.target.value); } }),
        plan ? h('div', { class: 'v-plan-hint' + (plan.study ? ' study' : '') },
          h('i', { class: 'fa ' + (plan.study ? 'fa-binoculars' : 'fa-flask') }),
          h('span', null, plan.eta_label),
          plan.study ? h('span', { class: 'v-plan-meta' }, 'checks every ' + plan.tick + ' min') : null) : null,
        h('button', { class: 'v-btn primary', type: 'submit', disabled: submitting || !prompt.trim(), style: 'align-self:flex-start' },
          h('i', { class: 'fa ' + (plan && plan.study ? 'fa-binoculars' : 'fa-flask') }),
          submitting ? ' Submitting…' : ' Research'))),
    activeJob ? h(Panel, { title: activeJob.mode === 'study' ? 'Study' : 'Result', span2: true,
        hint: activeJob.status + (activeJob.mode === 'study' && activeJob.status === 'studying'
          ? ' — ' + dur(activeJob.study_until - now) + ' remaining' : '') },
      activeJob.status === 'pending' || activeJob.status === 'running'
        ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-spinner fa-spin' }),
            ' Researching' + (activeJob.started_at ? ' — ' + dur(now - activeJob.started_at) + ' elapsed' : '') +
            '. This can take a few minutes, feel free to leave this tab.')
        : activeJob.status === 'studying'
        ? h('div', null,
            h('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:12px' },
              h('div', { class: 'v-bar', style: 'flex:1;margin:0' },
                h('i', { style: 'width:' + studyPct.toFixed(0) + '%;background:var(--v-accent)' })),
              h('span', { class: 'v-name', style: 'font-variant-numeric:tabular-nums;flex:none' }, studyPct.toFixed(0) + '%')),
            h('div', { class: 'v-empty', style: 'padding:8px 0' },
              h('i', { class: 'fa fa-binoculars' }),
              ' Studying — checking every ' + (activeJob.tick_minutes || 15) + ' min. ' +
              (activeJob.observations ? activeJob.observations.length : 0) + ' observation(s) so far.'),
            activeJob.observations && activeJob.observations.length
              ? h('div', { class: 'v-scroll', style: 'max-height:280px' },
                  h('ul', { class: 'v-journal' },
                    activeJob.observations.slice().reverse().map(function (o, i) {
                      return h('li', { key: i }, h('span', { class: 'muted' }, ts(o.at)), ' ', o.note);
                    })))
              : null)
        : activeJob.status === 'error'
        ? h('div', { class: 'v-empty' },
            h('div', null, 'Failed: ', activeJob.error),
            h('button', { class: 'v-btn xs', style: 'margin-top:8px', disabled: retryBusy,
                onClick: function () { retryJob(activeJob.id); } },
              h('i', { class: 'fa ' + (retryBusy ? 'fa-spinner fa-spin' : 'fa-refresh') }), retryBusy ? ' Retrying…' : ' Retry'))
        : h('div', { class: 'v-kb-doc-body v-kb-doc-rich' }, renderMd(activeJob.answer))) : null,
    h(Panel, { title: 'Past questions', span2: true, hint: jobs.length + ' total' },
      !jobs.length ? h('div', { class: 'v-empty' }, 'No research jobs yet.')
      : h(Table, null,
          h('tr', null, h('th', null, 'Question'), h('th', null, 'Mode'), h('th', null, 'Status'),
            h('th', null, 'Progress'), h('th', null, 'Asked'), h('th', null, '')),
          jobs.map(function (j) {
            var pct = jobPct(j);
            return h('tr', { key: j.id },
              h('td', { class: 'v-name' },
                j.origin && j.origin.indexOf('auto:') === 0 ? h(Pill, { kind: 'warn', title: 'Opened automatically by the ' + autoOriginLabel(j.origin) }, h('i', { class: 'fa fa-bolt' }), ' auto') : null,
                j.origin && j.origin.indexOf('auto:') === 0 ? ' ' : null,
                j.prompt.length > 80 ? j.prompt.slice(0, 80) + '…' : j.prompt),
              h('td', null, j.mode === 'study' ? h(Pill, { kind: 'info' }, j.tick_minutes ? 'study/' + j.tick_minutes + 'm' : 'study') : '—'),
              h('td', null, h(Pill, { kind: j.status === 'done' ? 'run' : j.status === 'error' ? 'stop' : 'warn' }, j.status)),
              h('td', null, pct != null
                ? h('div', { style: 'display:flex;align-items:center;gap:6px;min-width:90px' },
                    h('div', { class: 'v-bar', style: 'flex:1;margin:0' },
                      h('i', { style: 'width:' + pct.toFixed(0) + '%;background:var(--v-accent)' })),
                    h('span', { class: 'muted', style: 'font-variant-numeric:tabular-nums;font-size:11px' }, pct.toFixed(0) + '%'))
                : (j.status === 'pending' || j.status === 'running')
                  ? h('i', { class: 'fa fa-spinner fa-spin muted' }) : h('span', { class: 'muted' }, '—')),
              h('td', { class: 'muted' }, ts(j.created_at)),
              h('td', null,
                h('button', { class: 'v-btn xs', onClick: function () { openJob(j.id); } }, 'View'),
                j.status === 'error' ? h('button', {
                  class: 'v-btn xs', style: 'margin-left:6px', disabled: retryBusy,
                  onClick: function () { retryJob(j.id); },
                }, h('i', { class: 'fa ' + (retryBusy ? 'fa-spinner fa-spin' : 'fa-refresh') }), ' Retry') : null));
          }))));
}

/* ------------------------------------------------------------- settings */

var CLEANUP_KINDS = [
  { kind: 'logs',                      label: 'Plugin logs (var/tmp)',  docker: false, confirm2: false, hint: 'Rotated/truncated plugin-owned logs' },
  { kind: 'tmp',                       label: 'Plugin tmp files',       docker: false, confirm2: false, hint: 'Files the plugin left under /tmp' },
  { kind: 'orphan_appdata',            label: 'Orphaned appdata folders', docker: false, confirm2: false, hint: 'No container maps them — moved to a dated held folder, delete later if truly unwanted' },
  { kind: 'container_logs',            label: 'Oversized container logs', docker: false, confirm2: false, hint: 'Truncates the JSON log; lasting fix = docker log-size limit setting' },
  { kind: 'recycle',                   label: 'Recycle bins',           docker: false, confirm2: false, hint: 'Per-share .recycle sizes; empty a bin or entries older than N days' },
  { kind: 'junk',                      label: 'Junk files',             docker: false, confirm2: false, hint: '.DS_Store/Thumbs.db/._*/@eaDir/empty dirs — opted-in shares only (JUNK_SHARES in Settings)' },
  { kind: 'docker_dangling_images',    label: 'Dangling images',        docker: true,  confirm2: false, hint: '<none> image layers from rebuilds' },
  { kind: 'docker_unused_images',      label: 'Unused images (>24h)',   docker: true,  confirm2: false, hint: 'Images no container has used in 24h' },
  { kind: 'docker_stopped_containers', label: 'Stopped containers',     docker: true,  confirm2: false, hint: 'Containers in exited/created state' },
  { kind: 'docker_build_cache',        label: 'Build cache',            docker: true,  confirm2: false, hint: 'docker builder cache' },
  { kind: 'docker_unused_volumes',     label: 'Unused volumes',         docker: true,  confirm2: true,  hint: 'Volumes nothing references — data loss risk, double confirm' }
];

function CleanupTab() {
  var s1 = useState(null), audit = s1[0], setAudit = s1[1];
  var s2 = useState(null), preview = s2[0], setPreview = s2[1];   // {kind, data}
  var s3 = useState('idle'), applyState = s3[0], setApplyState = s3[1];
  var s4 = useState(null), applyResult = s4[0], setApplyResult = s4[1];
  var s5 = useState(false), confirm2 = s5[0], setConfirm2 = s5[1];
  var s6 = useState(null), mover = s6[0], setMover = s6[1];
  var s7 = useState('idle'), moverState = s7[0], setMoverState = s7[1];
  var s8 = useState(false), moveParityConfirm = s8[0], setMoveParityConfirm = s8[1];
  var s9 = useState(null), timeline = s9[0], setTimeline = s9[1];

  var reloadTimeline = function () {
    fetch(ENDPOINT + '?action=timeline', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setTimeline(j); });
  };
  useEffect(reloadTimeline, []);

  var reloadMover = function () {
    fetch(ENDPOINT + '?action=mover_status', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setMover(j); });
  };
  useEffect(reloadMover, []);

  var moverStart = function () {
    setMoverState('starting');
    var body = new URLSearchParams({ confirm_parity: moveParityConfirm ? 'yes' : '' });
    fetch(ENDPOINT + '?action=mover_start', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setMoverState(j && j.ok ? 'started' : (j && j.confirm_required ? 'confirm' : 'error'));
        if (j && j.ok) { reloadMover(); setTimeout(reloadMover, 4000); }
        if (j && !j.ok) setMover(j); // surface error or confirm_required flag
      })
      .catch(function () { setMoverState('error'); });
  };
  var moverStop = function () {
    setMoverState('stopping');
    fetch(ENDPOINT + '?action=mover_stop', { method: 'POST', body: new URLSearchParams({}) })
      .then(function (r) { return r.json(); })
      .then(function () { setMoverState('idle'); reloadMover(); })
      .catch(function () { setMoverState('error'); });
  };

  var reloadAudit = function () {
    fetch(ENDPOINT + '?action=cleanup_audit', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setAudit(j.audit); });
  };
  useEffect(reloadAudit, []);

  var doPreview = function (k) {
    return function () {
      setPreview(null); setApplyResult(null); setConfirm2(false);
      setApplyState('previewing');
      fetch(ENDPOINT + '?action=cleanup_preview&kind=' + encodeURIComponent(k.kind), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          setApplyState('idle');
          if (j && j.ok) setPreview({ kind: k, data: j });
          else setPreview({ kind: k, error: (j && j.error) || 'preview failed' });
        })
        .catch(function () { setPreview({ kind: k, error: 'preview failed' }); setApplyState('idle'); });
    };
  };

  var doApply = function () {
    if (!preview || !preview.data) return;
    setApplyState('applying');
    var body = new URLSearchParams({ preview_id: preview.data.preview_id, confirm2: confirm2 ? 'yes' : '' });
    fetch(ENDPOINT + '?action=cleanup_apply', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setApplyState('idle');
        setApplyResult(j);
        if (j && j.ok) { setPreview(null); reloadAudit(); }
      })
      .catch(function () { setApplyState('idle'); setApplyResult({ ok: false, error: 'apply failed' }); });
  };

  var fmt = function (n) { return (n == null ? '—' : bytes(n, 1)); };

  return h('div', null,
    h(Panel, { title: 'Mover', hint: 'Starts /usr/local/sbin/mover; refuses during a parity check unless confirmed' },
      !mover ? h('div', { class: 'v-empty' }, 'Loading…')
        : h('div', null,
            h('div', { style: 'margin-bottom:8px' },
              h('span', { class: mover.running ? 'v-badge ok' : 'v-badge' },
                mover.running ? ('running (pid ' + mover.pid + ')' + (mover.parity_busy ? ' — parity busy' : '')) : 'not running'),
              mover.parity_busy && !mover.running ? h('span', { class: 'v-badge warn', style: 'margin-left:8px' }, 'parity check/sync active') : null),
            h('button', { class: 'v-btn', onClick: moverStart, disabled: moverState === 'starting' || mover.running },
              mover.running ? 'Mover already running' : 'Run mover now'),
            ' ',
            h('button', { class: 'v-btn xs', onClick: moverStop, disabled: moverState === 'stopping' || !mover.running },
              'Stop'),
            mover.confirm_required && moverState === 'confirm'
              ? h('label', { style: 'display:block;margin-top:8px' },
                  h('input', { type: 'checkbox', checked: moveParityConfirm,
                    onChange: function (e) { setMoveParityConfirm(e.target.checked); } }),
                  ' Run anyway during the parity ' + '(rebuild/sync slows both down)')
              : null,
            mover.log && mover.log.length
              ? h('pre', { class: 'v-pre', style: 'max-height:180px;overflow:auto;margin-top:8px' },
                  mover.log.slice(-12).join('\n'))
              : null)),
    h(Panel, { title: 'Cleanup', hint: 'Preview first — apply only runs exactly what the preview listed' },
      h('table', null,
        h('tr', null, h('th', null, 'Kind'), h('th', null, 'What it does'), h('th', null, '')),
        CLEANUP_KINDS.map(function (k) {
          return h('tr', { key: k.kind },
            h('td', { class: 'v-name' }, k.label + (k.confirm2 ? ' ⚠' : '')),
            h('td', { class: 'muted' }, k.hint),
            h('td', null, h('button', {
              class: 'v-btn xs', onClick: doPreview(k),
              disabled: applyState !== 'idle' && applyState !== 'error'
            }, 'Preview')));
        }))),
    preview && preview.error ? h(Panel, { title: 'Preview failed' },
      h('div', { class: 'v-empty' }, preview.error)) : null,
    preview && preview.data ? h(Panel, {
      title: 'Preview: ' + preview.kind.label,
      hint: preview.kind.confirm2 ? 'Type-on confirmation required below' : null
    },
      h('div', null,
        h('div', { class: 'muted', style: 'margin-bottom:6px' },
          (preview.data.items || []).length + ' item(s), reclaiming ' + fmt(preview.data.total_bytes) +
          ' — expires ' + ts(preview.data.expires_at)),
        h('div', { style: 'max-height:260px;overflow:auto' },
          h('table', null,
            (preview.data.items || []).slice(0, 200).map(function (it, i) {
              return h('tr', { key: i },
                h('td', { class: 'v-name', style: 'word-break:break-all' }, it.path || it.ref),
                h('td', { class: 'num muted' }, it.bytes == null ? '' : fmt(it.bytes)));
            }))),
        preview.kind.confirm2
          ? h('label', { style: 'display:block;margin-top:8px' },
              h('input', { type: 'checkbox', checked: confirm2,
                onChange: function (e) { setConfirm2(e.target.checked); } }),
              ' I understand this deletes unused docker volumes (data loss risk)')
          : null,
        h('div', { style: 'margin-top:10px' },
          h('button', { class: 'v-btn', onClick: doApply,
            disabled: applyState !== 'idle' || (preview.kind.confirm2 && !confirm2) },
            'Apply — delete ' + (preview.data.items || []).length + ' item(s)'),
          ' ',
          h('button', { class: 'v-btn xs', onClick: function () { setPreview(null); } }, 'Cancel'))))
      : null,
    applyResult ? h(Panel, { title: applyResult.ok ? 'Applied' : 'Rejected' },
      h('div', null,
        applyResult.ok
          ? h('div', null, 'Removed ' + applyResult.removed + ' of ' + applyResult.of +
              ' — reclaimed ' + fmt(applyResult.bytes_reclaimed))
          : h('div', { class: 'v-empty' }, applyResult.error +
            (applyResult.detail ? '' : '')),
        applyResult.results && applyResult.results.length
          ? h('table', null, applyResult.results.slice(0, 50).map(function (r, i) {
              return h('tr', { key: i },
                h('td', { class: 'v-name', style: 'word-break:break-all' }, r.path || r.ref),
                h('td', { class: 'muted' }, r.removed === false ? (r.error || 'failed') : (r.out ? 'ok' : 'removed')));
            }))
          : null))
      : null,
    h(Panel, { title: 'What changed? (14-day timeline)', hint: 'Diffs of the daily identity snapshot: containers/images, plugins, Unraid version, share settings, disk assignments' },
      timeline && timeline.ok && timeline.days && timeline.days.length
        ? h('div', null,
            timeline.days.map(function (dayRow, di) {
              var ch = dayRow.changes || {};
              var rows = [];
              ['unraid', 'images', 'containers', 'plugins', 'shares', 'disks'].forEach(function (k) {
                (ch[k] || []).forEach(function (c, i) {
                  rows.push(h('tr', { key: di + '-' + k + '-' + i },
                    h('td', { class: 'muted' }, dayRow.day),
                    h('td', { class: 'v-name' },
                      ((k === 'unraid') ? 'Unraid OS' : (k === 'images' ? (c.name || '?') : (c.name || '?')))),
                    h('td', null,
                      k === 'images'
                        ? ((c.kind === 'updated' ? 'image: ' + (c.old || '∅') + ' → ' + (c.new || '∅') : c.kind))
                        : k === 'unraid'
                          ? ((c.old || '?') + ' → ' + (c.new || '?'))
                          : ((c.kind || '?') + (c.kind === 'changed' ? ((typeof c.old === 'object' ? JSON.stringify(c.old) : (c.old || '')) + ' → ' + (typeof c.new === 'object' ? JSON.stringify(c.new) : (c.new || ''))) : '')))));
                });
              });
              return rows.length ? rows : h('tr', { key: di }, h('td', { class: 'muted' }, dayRow.day), h('td', { colspan: 2, class: 'muted' }, 'no changes'));
            }).flat(),
            h('div', { class: 'v-empty', style: 'margin-top:6px' }, 'Acceptance-worthy row: updating a container image shows old tag → new tag.'))
        : h('div', { class: 'v-empty' }, 'Timeline builds from daily snapshots — check again after the next day rolls over.')),
    h(Panel, { title: 'Audit log', hint: 'Every apply/reject is recorded — who, when, what, bytes' },
      !audit ? h('div', { class: 'v-empty' }, 'Loading…')
        : audit.length === 0 ? h('div', { class: 'v-empty' }, 'No cleanup actions yet.')
        : h('table', null,
            h('tr', null, h('th', null, 'When'), h('th', null, 'Who'), h('th', null, 'Kind'),
              h('th', null, 'Items'), h('th', null, 'Bytes'), h('th', null, 'Result')),
            audit.map(function (r, i) {
              return h('tr', { key: i },
                h('td', { class: 'muted' }, ts(r.at)),
                h('td', null, r.user || '—'),
                h('td', { class: 'v-name' }, r.kind),
                h('td', { class: 'num' }, r.items),
                h('td', { class: 'num' }, r.bytes ? bytes(r.bytes, 1) : ''),
                h('td', null, h('span', { class: r.result === 'applied' ? 'v-badge ok' : 'v-badge warn' }, r.result)));
            }))));
}

/* P18-02: schedules panel — one row per job (enabled switch, frequency,
 * last run + duration + exit, Run-now (CSRF, lock-aware), last log lines). */
function SchedulesPanel() {
  var st = useState(null); var jobs = st[0], setJobs = st[1];
  var s2 = useState(null); var sys = s2[0], setSys = s2[1];   // quiet/busy flags
  var s3 = useState(null); var openJob = s3[0], setOpenJob = s3[1];
  var s4 = useState('idle'); var actState = s4[0], setActState = s4[1];
  var s5 = useState(''); var msg = s5[0], setMsg = s5[1];

  var load = function () {
    fetch(ENDPOINT + '?action=job_statuses', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setJobs(j.jobs); setSys({ quiet: j.quiet, busy: j.busy }); } });
  };
  useEffect(load, []);

  var toggleJob = function (id, enabled) {
    setActState('saving');
    fetch(ENDPOINT + '?action=save_settings', { method: 'POST',
      body: new URLSearchParams(Object.fromEntries([['csrf_token', ''],
        ['SCHED_' + id.toUpperCase() + '_ENABLED', enabled ? '1' : '0']])) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setActState('idle');
        setMsg(j && (j.ok || j.saved) ? ('Job ' + id + ' ' + (enabled ? 'enabled' : 'disabled') + ' — cron updated.') : 'Save failed');
        load();
      }).catch(function () { setActState('idle'); setMsg('Save failed'); });
  };
  var runNow = function (id, force) {
    setActState('running');
    fetch(ENDPOINT + '?action=job_run', { method: 'POST',
      body: new URLSearchParams({ job: id, force: force ? 'yes' : '' }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setActState('idle');
        setMsg((j && j.ok) ? ('Started ' + id + ' — duration/exit appear when it finishes.')
                           : ((j && j.error) || 'run refused'));
        load();
      }).catch(function () { setActState('idle'); setMsg('run failed'); });
  };

  return h(Panel, {
    title: 'Scheduled jobs',
    span2: true,
    hint: (sys && sys.quiet ? 'quiet hours active · ' : '') + (sys && sys.busy ? 'parity/mover busy — heavy jobs skip' : 'all clear')
  },
    msg ? h('div', { class: 'v-empty' }, msg) : null,
    !jobs ? h('div', { class: 'v-empty' }, 'Loading jobs…')
      : h(Table, null,
        h('tr', null, h('th', null, 'Job'), h('th', null, 'Schedule'), h('th', null, 'Last run'), h('th', null, '')),
        Object.keys(jobs).sort().map(function (id) {
          var j = jobs[id];
          var last = j.last_start ? (ts(j.last_start) + (j.last_duration_s != null ? ' (' + dur(j.last_duration_s) + ')' : '')
            + (j.last_exit != null ? ' — exit ' + j.last_exit : (j.running ? ' — running' : ' — exit ?')) + (j.manual ? ' (manual)' : ''))
            : h('span', { class: 'muted' }, j.running ? 'running…' : 'never');
          return h('tr', { key: id },
            h('td', { class: 'v-name' }, j.label || id,
              j.heavy ? h('span', { class: 'v-badge warn', style: 'margin-left:6px' }, 'heavy') : null,
              j.running ? h('span', { class: 'v-badge ok', style: 'margin-left:6px' }, 'running') : null),
            h('td', { class: 'num' }, h('code', null, j.sched || '—'),
              !j.enabled ? h('span', { class: 'v-badge', style: 'margin-left:6px' }, 'disabled') : null),
            h('td', null, last),
            h('td', null,
              h('button', { class: 'v-btn xs', onClick: function () { setOpenJob(openJob === id ? null : id); } }, ''),
              ' ',
              h('button', { class: 'v-btn xs', onClick: function () { runNow(id, false); }, disabled: actState !== 'idle' || j.running }, 'Run now')));
        })),
    openJob && jobs[openJob]
      ? h('div', { style: 'margin-top:8px' },
        h('div', { class: 'muted' }, 'Last log lines of ' + openJob + ':'),
        h('pre', { class: 'v-pre', style: 'white-space:pre-wrap;max-height:220px;overflow:auto;background:transparent' },
          ((jobs[openJob].lines || []).join('\n')) || '(no output yet)'))
      : null);
}

/* P19: backups — list/run/restore/download/bundle, destination pool warning. */
function BackupsPanel() {
  var st = useState(null); var data = st[0], setData = st[1];
  var s2 = useState('idle'); var state = s2[0], setState = s2[1];
  var s3 = useState(''); var msg = s3[0], setMsg = s3[1];
  var s4 = useState(null); var confirmRestore = s4[0], setConfirmRestore = s4[1];
  var load = function () {
    fetch(ENDPOINT + '?action=backups', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setData(j); });
  };
  useEffect(load, []);
  var runNow = function () {
    setState('backing');
    fetch(ENDPOINT + '?action=backup_run', { method: 'POST', body: new URLSearchParams({}) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setState('idle');
        setMsg(j && j.ok ? ('Backup created: ' + j.file + ' (' + bytes(j.bytes, 1) + ') — kept ' + j.kept_daily + ' daily + ' + j.kept_weekly + ' weekly.')
                         : ((j && j.error) || 'backup failed'));
        load();
      }).catch(function () { setState('idle'); setMsg('backup failed'); });
  };
  var restore = function (f) {
    setState('restoring');
    fetch(ENDPOINT + '?action=backup_restore', { method: 'POST', body: new URLSearchParams({ file: f }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setState('idle'); setConfirmRestore(null);
        setMsg(j && j.ok ? ('Restored ' + j.restored + ' — pre-restore safety copy: ' + j.pre_restore + '.') : ((j && j.error) || 'restore failed'));
        load();
      }).catch(function () { setState('idle'); setMsg('restore failed'); });
  };
  var bundle = function () {
    setState('bundling');
    fetch(ENDPOINT + '?action=backup_bundle', { method: 'POST', body: new URLSearchParams({}) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setState('idle');
        setMsg(j && j.ok ? ('State bundle ready: ' + j.bundle + ' (' + bytes(j.bytes, 1) + ') — contains DB + rollups + config.') : ((j && j.error) || 'bundle failed'));
      }).catch(function () { setState('idle'); });
  };

  return h(Panel, {
    title: 'Backups & restore', span2: true,
    hint: data && data.same_pool ? '⚠ backup destination is on the SAME pool as the DB — set BACKUP_DIR to another pool/array for real safety' : 'destination: ' + ((data && data.dest) || '…')
  },
    msg ? h('div', { class: 'v-empty' }, msg) : null,
    data && data.integrity && data.integrity !== 'ok'
      ? h('div', { class: 'v-empty', style: 'margin-bottom:6px' },
        h('b', { class: 'crit' }, 'Live DB integrity: '), data.integrity, ' — restore advised; existing backups are NOT pruned while this shows.')
      : null,
    data && data.schema && data.schema.migrated
      ? h('div', { class: 'v-empty', style: 'margin-bottom:6px' }, 'Schema upgraded to v' + data.schema.version + ' — pre-upgrade backup ' + data.schema.pre_upgrade + ' kept.')
      : null,
    h('div', { style: 'margin-bottom:10px' },
      h('button', { class: 'v-btn', onClick: runNow, disabled: state !== 'idle' }, state === 'backing' ? 'Backing up…' : 'Back up now'),
      ' ',
      h('button', { class: 'v-btn xs', onClick: bundle, disabled: state !== 'idle' }, state === 'bundling' ? 'Bundling…' : 'Full state bundle (.zip)')),
    !data ? h('div', { class: 'v-empty' }, 'Loading backups…')
      : !(data.backups || []).length ? h('div', { class: 'v-empty' }, 'No backups yet — the daily 03:00 job creates the first one.')
      : h(Table, null,
        h('tr', null, h('th', null, 'Backup'), h('th', null, 'When'), h('th', { class: 'num' }, 'Size'), h('th', null, 'Integrity'), h('th', null, '')),
        data.backups.map(function (b, i) {
          return h('tr', { key: i },
            h('td', { class: 'v-name' }, b.file, b.tag !== 'auto' ? h('span', { class: 'v-badge', style: 'margin-left:6px' }, b.tag) : null),
            h('td', { class: 'muted' }, ts(b.at)),
            h('td', { class: 'num' }, bytes(b.bytes, 1)),
            h('td', null, b.integrity || h('span', { class: 'muted' }, '—')),
            h('td', null,
              h('a', { class: 'v-btn xs', href: ENDPOINT + '?action=backup_download&file=' + encodeURIComponent(b.file) }, 'Download'),
              ' ',
              h('button', { class: 'v-btn xs', onClick: function () { confirmRestore === b.file ? restore(b.file) : setConfirmRestore(b.file); } },
                confirmRestore === b.file ? 'Confirm restore' : 'Restore')));
        })),
    confirmRestore ? h('div', { class: 'v-empty' }, 'Restoring replaces the live DB (a pre-restore backup is taken automatically). Click Confirm restore again.') : null);
}

function SettingsTab() {
  var s1 = useState(null), cfg = s1[0], setCfg = s1[1];
  var s2 = useState(null), meta = s2[0], setMeta = s2[1];
  var s3 = useState('idle'), saveState = s3[0], setSaveState = s3[1];
  var s4 = useState(null), modelInfo = s4[0], setModelInfo = s4[1];

  var reload = function () {
    fetch(ENDPOINT + '?action=settings', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        setCfg({
          INTERVAL: j.cfg.INTERVAL || '1', KEEP_DAYS: j.cfg.KEEP_DAYS || '90',
          SET_STARTPAGE: j.cfg.SET_STARTPAGE || 'no',
          ALERT_TEMP: j.cfg.ALERT_TEMP || '55', ALERT_FILL: j.cfg.ALERT_FILL || '90',
          ALERT_LOAD: j.cfg.ALERT_LOAD || '0', ALERT_RESTARTS: j.cfg.ALERT_RESTARTS || '3',
          LLM_STUDIO_PRIMARY: j.cfg.LLM_STUDIO_PRIMARY || '', LLM_STUDIO_BACKUP: j.cfg.LLM_STUDIO_BACKUP || '',
          UI_REFRESH_SECONDS: j.cfg.UI_REFRESH_SECONDS || '10',
          VITALS_DIAG_INTERVAL_MINUTES: j.cfg.VITALS_DIAG_INTERVAL_MINUTES || '360',
          VITALS_DIAG_WINDOW_HOURS: j.cfg.VITALS_DIAG_WINDOW_HOURS || '6',
          VITALS_DIAG_MODELS: j.cfg.VITALS_DIAG_MODELS || '',
          VITALS_UPDATE_INTERVAL_MINUTES: j.cfg.VITALS_UPDATE_INTERVAL_MINUTES || '360'
        });
        setMeta(j);
      });
  };
  useEffect(reload, []);
  useEffect(function () {
    fetch(ENDPOINT + '?action=list_models', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setModelInfo(j); })
      .catch(function () {});
  }, []);

  if (!cfg) return h('div', { class: 'v-panel' }, h('div', { class: 'v-empty' }, 'Loading settings…'));

  var set = function (k) { return function (e) {
    var v = e.target.value;
    setCfg(function (c) { var n = merge(c, {}); n[k] = v; return n; });
  }; };

  // Which models are "on": explicit VITALS_DIAG_MODELS list if the user has
  // ever saved one, otherwise the server's own default set (modelInfo tells
  // us which — see configured_default in v_list_models()) so first-load
  // checkboxes reflect the real default instead of showing everything
  // unchecked.
  var selectedModels = (cfg.VITALS_DIAG_MODELS || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  var usingDefaults = selectedModels.length === 0;
  var isChecked = function (m) {
    if (!usingDefaults) return selectedModels.indexOf(m.name) !== -1;
    return m.enabled === true || m.enabled === null; // null = "server has no override yet", treat as its own default
  };
  var toggleModel = function (name) {
    return function (e) {
      var checked = e.target.checked;
      // First toggle while still "using defaults" seeds the explicit list
      // from whatever was effectively on, then flips just this one — so
      // unchecking a single default model doesn't silently turn ALL of
      // them off.
      var base = usingDefaults
        ? (modelInfo ? modelInfo.models.filter(isChecked).map(function (m) { return m.name; }) : [])
        : selectedModels.slice();
      var next = checked ? base.concat([name]).filter(function (v, i, a) { return a.indexOf(v) === i; })
                          : base.filter(function (n) { return n !== name; });
      setCfg(function (c) { return merge(c, { VITALS_DIAG_MODELS: next.join(',') }); });
    };
  };

  var save = function () {
    setSaveState('saving');
    var body = new URLSearchParams(merge(cfg, { csrf_token: meta.csrf_token }));
    fetch(ENDPOINT + '?action=save_settings', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { setSaveState(j && j.ok ? 'saved' : 'error'); if (j && j.ok) reload();
        setTimeout(function () { setSaveState('idle'); }, 2500); })
      .catch(function () { setSaveState('error'); });
  };

  return h('div', null,
    h(SchedulesPanel, {}),
    h(BackupsPanel, {}),
    h(Panel, { title: 'Collector status' },
      h('table', null, [
        ['Last sample', meta.last_run ? ts(meta.last_run) : '—'],
        ['Ring buffer', meta.ring_samples + ' samples'],
        ['Flash rollups', meta.flash_files + ' file(s), ' + bytes(meta.flash_bytes, 1)],
        ['Knowledge base', !meta.db_path ? 'unavailable — array stopped?'
          : meta.db_path + (meta.db_bytes == null ? ' (not created yet)' : ', ' + bytes(meta.db_bytes, 1))]
      ].map(function (r, i) {
        return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]), h('td', { class: 'num v-name' }, r[1]));
      }))),
    h(Panel, { title: 'Export data', hint: 'CSV opens directly in a spreadsheet' },
      h('div', { class: 'v-export-row' },
        h('div', null,
          h('div', { class: 'muted', style: 'margin-bottom:4px' }, 'Ring buffer (' + (meta.ring_samples || 0) + ' recent samples)'),
          h('a', { class: 'v-btn xs', href: ENDPOINT + '?action=export&what=ring&format=csv' }, 'Download CSV'),
          ' ',
          h('a', { class: 'v-btn xs', href: ENDPOINT + '?action=export&what=ring&format=json' }, 'Download JSON')),
        h('div', { style: 'margin-top:12px' },
          h('div', { class: 'muted', style: 'margin-bottom:4px' }, 'Hourly rollups (' + (meta.flash_files || 0) + ' month file(s))'),
          h('a', { class: 'v-btn xs', href: ENDPOINT + '?action=export&what=rollups&format=csv' }, 'Download CSV'),
          ' ',
          h('a', { class: 'v-btn xs', href: ENDPOINT + '?action=export&what=rollups&format=json' }, 'Download JSON')))),
    h(Panel, { title: 'Preferences' },
      h('table', null,
        h('tr', null, h('td', { class: 'muted' }, 'Sample interval'),
          h('td', null, h('select', { class: 'v-select', value: cfg.INTERVAL, onChange: set('INTERVAL') },
            [1, 2, 5, 10].map(function (i) { return h('option', { key: i, value: i }, i + ' minute' + (i > 1 ? 's' : '')); })))),
        h('tr', null, h('td', { class: 'muted' }, 'Keep daily rollups'),
          h('td', null, h('select', { class: 'v-select', value: cfg.KEEP_DAYS, onChange: set('KEEP_DAYS') },
            [30, 90, 180, 365].map(function (d) { return h('option', { key: d, value: d }, d + ' days'); })))),
        h('tr', null, h('td', { class: 'muted' }, 'Start page'),
          h('td', null, h('select', { class: 'v-select', value: cfg.SET_STARTPAGE, onChange: set('SET_STARTPAGE') },
            h('option', { value: 'no' }, 'Stock Unraid dashboard'),
            h('option', { value: 'yes' }, 'Vitals')))),
        h('tr', null, h('td', { class: 'muted' }, 'Disk temp alert'),
          h('td', null, h('input', { class: 'v-input', type: 'number', min: 30, max: 80,
            value: cfg.ALERT_TEMP, onInput: set('ALERT_TEMP') }), ' °C')),
        h('tr', null, h('td', { class: 'muted' }, 'Array fill alert'),
          h('td', null, h('input', { class: 'v-input', type: 'number', min: 50, max: 100,
            value: cfg.ALERT_FILL, onInput: set('ALERT_FILL') }), ' %')),
        h('tr', null, h('td', { class: 'muted' }, 'Load-average alert'),
          h('td', null, h('input', { class: 'v-input', type: 'number', min: 0, max: 256, step: 0.5,
            value: cfg.ALERT_LOAD, onInput: set('ALERT_LOAD') }), ' (0 = off)')),
        h('tr', null, h('td', { class: 'muted' }, 'Container-down samples'),
          h('td', null, h('input', { class: 'v-input', type: 'number', min: 0, max: 60,
            value: cfg.ALERT_RESTARTS, onInput: set('ALERT_RESTARTS') }), ' (0 = off)')),
        h('tr', null, h('td', { class: 'muted' }, 'Dashboard refresh rate'),
          h('td', null, h('select', { class: 'v-select', value: cfg.UI_REFRESH_SECONDS,
            onChange: set('UI_REFRESH_SECONDS') },
            [
              [5, 'Every 5 seconds'], [10, 'Every 10 seconds (default)'], [15, 'Every 15 seconds'],
              [30, 'Every 30 seconds'], [60, 'Every minute']
            ].map(function (o) { return h('option', { key: o[0], value: o[0] }, o[1]); }))))),
      h('div', { class: 'muted', style: 'margin-top:16px;margin-bottom:6px;font-weight:600' }, 'AI agent endpoint'),
      h('div', { class: 'muted', style: 'margin-bottom:8px;font-size:0.9em' },
        'Empty = the built-in remote default (your metrics and logs leave your network to reach it). ' +
        'Set your own Ollama-compatible endpoint here to keep everything local.'),
      h('table', null,
        h('tr', null, h('td', { class: 'muted' }, 'Primary endpoint'),
          h('td', null, h('input', { class: 'v-input', type: 'text', style: 'width:280px',
            placeholder: 'http://127.0.0.1:11434 (default: remote)',
            value: cfg.LLM_STUDIO_PRIMARY, onInput: set('LLM_STUDIO_PRIMARY') }))),
        h('tr', null, h('td', { class: 'muted' }, 'Backup endpoint'),
          h('td', null, h('input', { class: 'v-input', type: 'text', style: 'width:280px',
            placeholder: '(default: remote)',
            value: cfg.LLM_STUDIO_BACKUP, onInput: set('LLM_STUDIO_BACKUP') })))),
      h('div', { style: 'margin-top:12px;display:flex;gap:8px;align-items:center' },
        h('button', { class: 'v-btn primary', onClick: save, disabled: saveState === 'saving' },
          h('i', { class: 'fa fa-save' }), ' ' + (saveState === 'saving' ? 'Saving…' : 'Save')),
        saveState === 'saved' ? h('span', { class: 'ok' }, h('i', { class: 'fa fa-check' }), ' Saved & reapplied') : null,
        saveState === 'error' ? h('span', { class: 'crit' }, 'Save failed') : null)),
    h(Panel, { title: 'Deep scan (diagnostics + update checks)' },
      h('div', { class: 'muted', style: 'margin-bottom:8px;font-size:0.9em' },
        'The diagnostics agent looks back across a rolling window and correlates across ' +
        'domains (thermal/disk/network/containers) rather than judging the latest sample alone. ' +
        'Runs on its own interval, independent of the hourly per-domain agents.'),
      h('table', null,
        h('tr', null, h('td', { class: 'muted' }, 'Scan interval'),
          h('td', null, h('select', { class: 'v-select', value: cfg.VITALS_DIAG_INTERVAL_MINUTES,
            onChange: set('VITALS_DIAG_INTERVAL_MINUTES') },
            [
              [60, 'Every hour'], [180, 'Every 3 hours'], [360, 'Every 6 hours (default)'],
              [720, 'Every 12 hours'], [1440, 'Once a day']
            ].map(function (o) { return h('option', { key: o[0], value: o[0] }, o[1]); })))),
        h('tr', null, h('td', { class: 'muted' }, 'Look-back window'),
          h('td', null, h('input', { class: 'v-input', type: 'number', min: 1, max: 72,
            value: cfg.VITALS_DIAG_WINDOW_HOURS, onInput: set('VITALS_DIAG_WINDOW_HOURS') }), ' hours')),
        h('tr', null, h('td', { class: 'muted', style: 'vertical-align:top;padding-top:6px' }, 'Models (multi-model corroboration)'),
          h('td', null,
            !modelInfo ? h('div', { class: 'muted' }, 'Loading model list from your LLM studios…')
            : !modelInfo.models.length ? h('div', { class: 'muted' },
                'Could not reach either LLM studio to list models (', 'primary: ',
                modelInfo.reachable_primary ? 'ok' : 'unreachable', ', backup: ',
                modelInfo.reachable_backup ? 'ok' : 'unreachable', ').')
            : h('div', null,
                h('div', { class: 'muted', style: 'font-size:0.85em;margin-bottom:6px' },
                  'Checkboxes replace typing model names by hand — a typo there used to silently drop a ' +
                  'model from corroboration with no error. Models only on one studio still work (the agent ' +
                  'fails over automatically) but are marked below.'),
                h('div', { style: 'display:flex;flex-direction:column;gap:4px;max-height:260px;overflow:auto' },
                  modelInfo.models.map(function (m) {
                    var onBoth = m.on_primary && m.on_backup;
                    return h('label', {
                      key: m.name,
                      style: 'display:flex;gap:8px;align-items:flex-start;padding:4px 6px;border-radius:4px;' +
                        (m.embedding_only ? 'opacity:.5' : '')
                    },
                      h('input', {
                        type: 'checkbox', style: 'margin-top:3px',
                        checked: !m.embedding_only && isChecked(m),
                        disabled: m.embedding_only,
                        onChange: toggleModel(m.name)
                      }),
                      h('div', { style: 'min-width:0' },
                        h('div', null,
                          h('b', { class: 'v-name' }, m.name),
                          m.params ? h('span', { class: 'muted', style: 'font-size:0.85em' }, ' · ' + m.params) : null,
                          m.thinking ? h('span', { class: 'v-pill think', style: 'margin-left:6px' }, 'thinking') : null,
                          m.vision ? h('span', { class: 'v-pill vision', style: 'margin-left:4px' }, 'vision') : null,
                          !onBoth ? h('span', { class: 'v-pill scope', style: 'margin-left:4px' },
                            m.on_primary ? 'primary only' : 'backup only') : null),
                        h('div', { class: 'muted', style: 'font-size:0.85em' },
                          m.embedding_only ? 'Embedding-only — cannot be used for diagnostics.' : (m.blurb || 'No description available.'))));
                  }))))),
        h('tr', null, h('td', { class: 'muted' }, 'Container-update check interval'),
          h('td', null, h('select', { class: 'v-select', value: cfg.VITALS_UPDATE_INTERVAL_MINUTES,
            onChange: set('VITALS_UPDATE_INTERVAL_MINUTES') },
            [
              [60, 'Every hour'], [360, 'Every 6 hours (default)'], [720, 'Every 12 hours'],
              [1440, 'Once a day'], [10080, 'Once a week'], [43200, 'Once a month']
            ].map(function (o) { return h('option', { key: o[0], value: o[0] }, o[1]); }))))),
      h('div', { style: 'margin-top:12px;display:flex;gap:8px;align-items:center' },
        h('button', { class: 'v-btn primary', onClick: save, disabled: saveState === 'saving' },
          h('i', { class: 'fa fa-save' }), ' ' + (saveState === 'saving' ? 'Saving…' : 'Save')))),
    h(Panel, { title: 'Collector log', span2: true },
      h('pre', { class: 'v-mono v-scroll', style: 'max-height:220px;margin:0;white-space:pre-wrap' },
        meta.log_tail || '(no output yet)')));
}

/* ---------------------------------------------------------------- mount */

if (mountEl) render(h('div', null, h(App), h(ToastStack)), mountEl);
})();
