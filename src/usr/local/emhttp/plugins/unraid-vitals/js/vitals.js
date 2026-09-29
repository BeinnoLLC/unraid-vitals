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

/* --------------------------------------------------------------- helpers */

function bytes(n, p) {
  if (n == null || n === '' || isNaN(n)) return '—';
  var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], i = 0;
  n = Number(n);
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i ? n.toFixed(p === undefined ? 1 : p) : String(Math.round(n))) + ' ' + u[i];
}
function pctStr(n, d) { return n == null ? '—' : Number(n).toFixed(d === undefined ? 1 : d) + '%'; }
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
  return h('div', { class: 'v-chart-stats' },
    rows.map(function (r, i) {
      return h('span', { key: i, class: 'v-chart-stat' },
        h('i', { style: 'background:' + r.color }),
        h('b', null, r.name),
        h('span', { class: 'num' }, ' ' + r.fmt(r.now)),
        h('span', { class: 'muted' },
          ' · avg ' + r.fmt(r.avg) + ' · ' + r.fmt(r.min) + '–' + r.fmt(r.max)));
    }));
}

/* ------------------------------------------------------------ primitives */

function Panel(P) {
  return h('section', { class: 'v-panel' + (P.span2 ? ' v-span2' : '') },
    h('h3', null, P.title, P.hint ? h('span', { class: 'v-hint' }, P.hint) : null),
    P.children);
}

function Pill(P) {
  return h('span', { class: 'v-pill ' + (P.kind || '') }, P.children);
}

function StatCard(P) {
  return h('div', { class: 'v-card' },
    P.icon ? h('div', { class: 'v-card-icon',
      style: 'background:color-mix(in srgb,' + P.color + ' 16%,transparent);color:' + P.color },
      h('i', { class: 'fa ' + P.icon })) : null,
    h('div', { class: 'v-card-label' }, P.label),
    h('div', { class: 'v-card-value ' + (P.level || '') },
      P.value, P.unit ? h('small', null, ' ' + P.unit) : null),
    P.sub ? h('div', { class: 'v-card-sub' }, P.sub) : null,
    P.bar != null ? h('div', { class: 'v-bar' },
      h('i', { style: 'width:' + Math.max(0, Math.min(100, P.bar)) + '%;background:' + P.color })) : null,
    P.spark ? h(Spark, { points: P.spark, color: P.sparkColor || P.color }) : null);
}

/* uPlot wrapper. P: {series:[{name,color,points,fill(axis2,stack)}], max, floor,
   height, yFmt, thresholds:[{v,color,label}], area, stack, y2Fmt, empty} */
function Chart(P) {
  var ref = useRef(null);
  var plot = useRef(null);
  var series = P.series || [];
  var n = series.reduce(function (m, s) { return Math.max(m, (s.points || []).length); }, 0);
  var minV = P.floor || 0, maxV = P.max;

  useEffect(function () {
    if (!ref.current) return;
    if (n < 2) {
      if (plot.current) { plot.current.destroy(); plot.current = null; }
      ref.current.innerHTML = '<div class="v-empty">' +
        (P.empty || 'Not enough samples yet — history builds up each minute.') + '</div>';
      return;
    }
    var xs = series[0].points.map(function (q) { return q[0]; });
    var ys = series.map(function (s) { return s.points.map(function (q) { return q[1]; }); });

    // Stacked mode: cumulative ys so uPlot's area fills draw a stacked band
    // per series (values themselves stay raw for the legend readout via $F).
    if (P.stack) {
      for (var si = 1; si < ys.length; si++) {
        ys[si] = ys[si].map(function (v, i) {
          var prev = ys[si - 1][i];
          return v == null ? prev : (prev == null ? v : v + prev);
        });
      }
    }

    if (!maxV) {
      maxV = 0;
      ys.forEach(function (a) { a.forEach(function (v) { if (v != null && v > maxV) maxV = v; }); });
      maxV = maxV * 1.15;
    }
    if (!maxV || maxV <= minV) maxV = minV + 1;
    // Make room for threshold lines above the data ceiling.
    (P.thresholds || []).forEach(function (t) {
      if (t.v != null && t.v > maxV) maxV = t.v * 1.06;
    });

    var thresholds = P.thresholds || [];

    var fmt1 = function (s, v) {
      if (s.fmt) return s.fmt(v);
      if (v == null) return '';
      return Math.round(v * 10) / 10 + (s.unit || '');
    };
    // In stacked mode the legend shows each band's own value, not the cumulative.
    var legendVal = function (u, si) {
      var raw = series[si] && series[si].points[u.cursor.idx];
      return raw && raw[1] != null ? fmt1(series[si], raw[1]) : '';
    };

    var opts = {
      width: ref.current.clientWidth || 600,
      height: P.height || 150,
      legend: { show: series.length > 1, live: false, markers: { width: 1.5 } },
      cursor: { sync: { key: 'vit' } },
      scales: {
        x: { time: false },
        y: { range: [minV, maxV] },
        y2: P.y2Fmt ? { range: [P.y2Floor != null ? P.y2Floor : 0, P.y2Max != null ? P.y2Max : 100] } : undefined,
      },
      axes: [
        { stroke: '#8889', grid: { stroke: '#8882', width: 1 }, ticks: { show: false },
          values: function (u, sp) { return sp.map(ts); } },
        { stroke: '#8889', grid: { stroke: '#8882', width: 1 }, ticks: { show: false }, size: 46,
          values: function (u, sp) { return sp.map(function (v) { return P.yFmt ? P.yFmt(v) : v; }); } },
      ],
      series: [{}].concat(series.map(function (s, i) {
        var o = {
          label: s.name, stroke: s.color, width: P.stack ? 1 : 1.7,
          spanGaps: true, points: { show: false },
          value: function (u, v) { return legendVal(u, i + 1); },
        };
        if (P.area || s.fill || P.stack) o.fill = withAlpha(s.color, P.stack ? 0.55 : 0.22);
        if (s.axis === 2) { o.scale = 'y2'; o.stroke = s.color; }
        return o;
      })),
      padding: [8, 10, 0, P.y2Fmt ? 46 : 0],
    };
    // Secondary axis.
    if (P.y2Fmt) {
      opts.axes.push({ stroke: '#8886', grid: { show: false }, ticks: { show: false }, side: 1, size: 44,
        scale: 'y2', values: function (u, sp) { return sp.map(function (v) { return P.y2Fmt(v); }); } });
      opts.padding[1] = 52;
    }
    // Threshold dashes are drawn as post-render DOM overlays instead of uPlot
    // series: keeps the data arrays pure and the legend free of phantom rows.
    var drawThresholds = function () {
      var host = ref.current;
      if (!host) return;
      host.querySelectorAll('.v-th-line').forEach(function (el) { el.remove(); });
      thresholds.forEach(function (t) {
        if (t.v == null || t.v < minV || t.v > maxV) return;
        var y = maxV - t.v, span = maxV - minV;
        if (span <= 0) return;
        var el = document.createElement('div');
        el.className = 'v-th-line';
        el.style.bottom = (8 + (y / span) * (opts.height - 30)) + 'px';
        el.style.borderColor = t.color || '#f87171';
        if (t.label) el.setAttribute('data-label', t.label);
        host.appendChild(el);
      });
    };

    if (plot.current) plot.current.destroy();
    plot.current = new uPlot(opts, [xs].concat(ys), ref.current);
    if (thresholds.length) {
      drawThresholds();
      var ro = new ResizeObserver(drawThresholds);
      ro.observe(ref.current);
      var _oldDestroy = plot.current.destroy.bind(plot.current);
      plot.current.destroy = function () { ro.disconnect(); _oldDestroy(); };
    }

    var onR = function () {
      if (plot.current && ref.current) {
        plot.current.setSize({ width: ref.current.clientWidth, height: opts.height });
      }
    };
    window.addEventListener('resize', onR);
    return function () {
      window.removeEventListener('resize', onR);
      if (plot.current) { plot.current.destroy(); plot.current = null; }
    };
  });

  return h('div', { class: 'v-chart', ref: ref });
}

function topKeys(pts, field, idx, n) {
  var last = pts[pts.length - 1];
  if (!last || !last[field]) return [];
  var m = last[field];
  return Object.keys(m).filter(function (k) { return m[k] && m[k][idx] != null; })
    .sort(function (a, b) { return (m[b][idx] || 0) - (m[a][idx] || 0); }).slice(0, n);
}

/* --------------------------------------------------------------- tables */

function Table(P) { return h('div', { class: 'v-scroll' }, h('table', null, P.children)); }

function ContainerTable(P) {
  var list = ((P.d.docker || {}).containers || []).slice();
  if (P.compact) list = list.slice(0, 9);
  if (!list.length) return h('div', { class: 'v-empty' }, 'No containers reported.');
  return h(Table, null,
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
  var flagged = rows.some(function (r) { return (r.reallocated || 0) + (r.pending || 0) > 0; });
  return h('div', null,
    h(Table, null,
      h('tr', null, h('th', null, 'Disk'), h('th', null, 'Health'), h('th', { class: 'num' }, 'Temp'),
        h('th', { class: 'num' }, 'Power-on h'), h('th', { class: 'num' }, 'Realloc'),
        h('th', { class: 'num' }, 'Pending'), h('th', { class: 'num' }, 'Uncorr'),
        h('th', { class: 'num' }, 'CRC')),
      rows.map(function (r) {
        var hcls = r.health === 'PASSED' ? 'ok' : (r.health ? 'crit' : 'muted');
        return h('tr', { key: r.dev || r.name },
          h('td', { class: 'v-name' }, r.name),
          h('td', null, h('span', { class: hcls }, r.health || 'n/a')),
          h('td', { class: 'num ' + lvl(r.temp, 45, 55) }, r.temp == null ? '—' : r.temp + '°'),
          h('td', { class: 'num muted' }, r.hours == null ? '—' : r.hours.toLocaleString()),
          h('td', { class: 'num ' + (r.reallocated ? 'crit' : 'muted') }, r.reallocated == null ? '—' : String(r.reallocated)),
          h('td', { class: 'num ' + (r.pending ? 'crit' : 'muted') }, r.pending == null ? '—' : String(r.pending)),
          h('td', { class: 'num ' + (r.uncorrectable ? 'crit' : 'muted') }, r.uncorrectable == null ? '—' : String(r.uncorrectable)),
          h('td', { class: 'num ' + (r.crc ? 'warn' : 'muted') }, r.crc == null ? '—' : String(r.crc)));
      })),
    flagged ? h('div', { class: 'v-warnnote' },
      h('i', { class: 'fa fa-exclamation-triangle' }),
      ' Reallocated or pending sectors present — check disk health.') : null);
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
  { id: 'kb',     label: 'Knowledge',     icon: 'fa-book' },
  { id: 'research', label: 'Research',   icon: 'fa-flask' },
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
    var iv = setInterval(function () { load(false); }, 15000);
    var iv2 = setInterval(loadFindings, 60000);
    return function () { clearInterval(iv); clearInterval(iv2); };
  }, []);

  useEffect(function () {
    fetch(ENDPOINT + '?action=daily&days=30', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { setDaily((j && j.daily) || []); })
      .catch(function () { setDaily([]); });
  }, []);

  var d = payload && payload.data;
  if (d && d.csrf_token) window.__V_CSRF__ = d.csrf_token;
  var ring = (payload && payload.ring) || [];
  var pts = ring.slice(-range);
  var props = { d: d, pts: pts, range: range, daily: daily, findings: findings };

  return h('div', null,
    h('div', { class: 'v-head' },
      h('div', { class: 'v-title' },
        h('h2', null, h('i', { class: 'fa fa-heartbeat' }), ' Vitals'),
        d ? h('span', { class: 'v-sub' },
              [d.system.name, d.system.version, d.system.cpu].filter(Boolean).join(' · ')) : null),
      h('div', { class: 'v-actions' },
        h('span', { class: 'v-stamp' },
          h('span', { class: 'v-dot' + (status === 'error' || (d && d._age > 180) ? ' crit' : '') }),
          status === 'error' ? 'collection error'
            : !d ? 'loading…'
            : 'sample ' + ts(d.time) + ' · ' + d._age + 's ago'),
        h('select', { class: 'v-select', value: range,
          onChange: function (e) { setRange(+e.target.value); } },
          h('option', { value: 60 }, 'Last hour'),
          h('option', { value: 360 }, 'Last 6 hours'),
          h('option', { value: 1440 }, 'Last 24 hours')),
        h('button', { class: 'v-btn', onClick: function () { load(true); } },
          h('i', { class: 'fa fa-refresh' }), ' Refresh'),
        h('button', { class: 'v-btn' + (drawerOpen ? ' primary' : ''), title: 'Critical system logs',
          onClick: function () { setDrawerOpen(!drawerOpen); } },
          h('i', { class: 'fa fa-file-text-o' }), ' Logs'))),

    h('div', { class: 'v-tabs' },
      TABS.map(function (t) {
        return h('button', { key: t.id, class: 'v-tab' + (tab === t.id ? ' on' : ''),
          onClick: function () { setTab(t.id); } },
          h('i', { class: 'fa ' + t.icon }), h('span', null, t.label));
      })),

    h('div', { class: 'v-body' },
      !d ? h('div', { class: 'v-panel' }, h('div', { class: 'v-empty' }, 'Loading…'))
        : tab === 'dash'   ? h(DashTab,   props)
        : tab === 'array'  ? h(ArrayTab,  props)
        : tab === 'docker' ? h(DockerTab, props)
        : tab === 'net'    ? h(NetTab,    props)
        : tab === 'sys'    ? h(SysTab,    props)
        : tab === 'shares' ? h(SharesTab, props)
        : tab === 'hw'     ? h(HwTab,     props)
        : tab === 'kb'     ? h(KbTab,     props)
        : tab === 'research' ? h(ResearchTab, {})
        : tab === 'settings' ? h(SettingsTab, {}) : null),

    h('div', { class: 'v-foot' },
      'unraid-vitals · samples retained on flash'),
    h(LogDrawer, { open: drawerOpen, onClose: function () { setDrawerOpen(false); } }));
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
  var cards = [
    { label: 'CPU', icon: 'fa-microchip', color: PAL['v-cpu'], value: pctStr(d.cpu && d.cpu.total),
      level: lvl(d.cpu && d.cpu.total, 80, 95),
      sub: (load.cores || '?') + ' threads · load ' +
           (load.l1 != null ? load.l1.toFixed(2) : '—'),
      bar: d.cpu && d.cpu.total },
    merge({ label: 'Memory', icon: 'fa-server', color: PAL['v-mem'], value: pctStr(d.mem && d.mem.pct),
      level: lvl(d.mem && d.mem.pct, 80, 92),
      sub: bytes(d.mem && d.mem.used) + ' of ' + bytes(d.mem && d.mem.total), bar: d.mem && d.mem.pct },
      sparkOf(function (p) { return p.mem; }, PAL['v-mem'])),
    { label: 'Array', icon: 'fa-hdd-o', color: PAL['v-accent'], value: String(t.data_disks || 0), unit: 'data',
      sub: d.system.md_state + ' · ' + (t.parity_disks || 0) + ' parity · ' + (t.cache_disks || 0) + ' pool' },
    { label: 'Storage', icon: 'fa-database', color: PAL['v-mem'], value: pctStr(t.used_pct),
      level: lvl(t.used_pct, 85, 95), sub: bytes(t.fs_free) + ' free of ' + bytes(t.fs_size), bar: t.used_pct },
    merge({ label: 'Hottest disk', icon: 'fa-thermometer-half', color: PAL['v-temp'],
      value: tMax == null ? '—' : String(tMax), unit: tMax == null ? '' : '°C',
      level: tMax == null ? '' : lvl(tMax, 45, 55), sub: temps.length + ' disks reporting' },
      sparkOf(function (p) { return p.temp_max; }, PAL['v-temp'])),
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

  return h('div', null,
    h(AiFindingsPanel, { findings: P.findings }),
    h('div', { class: 'v-cards v-cards-4' }, cards.map(function (c, i) {
      return h(StatCard, merge(c, { key: i }));
    })),
    h('div', { class: 'v-grid' },
      h(Panel, { title: 'CPU & Memory', span2: true, hint: pts.length + ' samples' },
        h(Chart, { series: cpuSeries, max: 100, height: 165, area: true,
          yFmt: function (v) { return v + '%'; } })),
      h(Panel, { title: 'Network flow', hint: 'stacked RX + TX' },
        h(Chart, { series: netSeries, height: 165, stack: true, area: true, yFmt: bytes })),
      h(Panel, { title: 'Disk temperature', hint: 'alert at 55°C' },
        h(Chart, { series: [{ name: 'Hottest °C', color: PAL['v-temp'],
            points: pick(function (p) { return p.temp_max; }) }],
          floor: tMax == null ? 0 : Math.max(0, Math.floor(tMax - 10)), height: 165,
          thresholds: [{ v: 55, color: '#f87171', label: 'alert 55°' }],
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
      h(Panel, { title: 'Top processes', hint: 'by CPU' }, h(TopTable, { d: d }))));
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
function AiFindingsPanel(P) {
  var all = P.findings || [];
  var list = P.agents ? all.filter(function (f) { return P.agents.indexOf(f.agent) !== -1; }) : all;
  if (!list.length) return null;
  var interesting = list.filter(function (f) { return f.severity !== 'ok'; });
  var shown = (interesting.length ? interesting : list.slice(0, 1)).slice()
    .sort(function (a, b) { return (SEV_ORDER[a.severity] ?? 5) - (SEV_ORDER[b.severity] ?? 5); })
    .slice(0, 6);
  return h('div', { class: 'v-ai-panel' },
    h('div', { class: 'v-ai-head' }, h('i', { class: 'fa fa-magic' }), ' AI health findings',
      h('span', { class: 'muted' }, ' · updated hourly by local agents')),
    shown.map(function (f) {
      return h('div', { key: f.id, class: 'v-ai-item v-ai-' + f.severity },
        h('i', { class: 'fa ' + (SEV_ICON[f.severity] || 'fa-info-circle') }),
        h('div', { class: 'v-ai-body' },
          h('div', { class: 'v-ai-title' }, f.title, f.agent ? h('span', { class: 'v-ai-agent' }, f.agent) : null),
          f.detail ? h('div', { class: 'v-ai-detail' }, f.detail) : null,
          f.recommendation ? h('div', { class: 'v-ai-rec' }, h('i', { class: 'fa fa-lightbulb-o' }), ' ', f.recommendation) : null));
    }));
}

/* ---------------------------------------------------------------- array */

function ArrayTab(P) {
  var d = P.d, pts = P.pts, a = d.array || {}, t = a.totals || {};
  var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };
  var smTop = topKeys(pts, 'smart', 0, 8);
  var smSeries = smTop.map(function (name, i) {
    return { name: name, color: pickColor(i),
      points: pick(function (p) { return p.smart && p.smart[name] ? p.smart[name][0] : null; }) };
  });
  var disks = (a.parity || []).concat(a.data || [], a.cache || []);
  var used = disks.filter(function (x) { return x.fsSize > 0; });
  var avg = used.length
    ? used.reduce(function (s, x) { return s + (x.usedPct || 0); }, 0) / used.length : null;

  // Ranked temperature hot-list (reference: total-tasks-by-assignee bars).
  var hotRows = smTop.map(function (name) {
    var cur = null;
    for (var i = pts.length - 1; i >= 0; i--) {
      var s = pts[i].smart && pts[i].smart[name];
      if (s && s[0] != null) { cur = s[0]; break; }
    }
    return { label: name, value: cur == null ? '—' : cur + '°',
      pct: cur == null ? 0 : Math.min(100, (cur / 60) * 100),
      color: cur == null ? '#666' : cur >= 55 ? PAL['v-bad-fg'] : cur >= 45 ? PAL['v-warn-fg'] : PAL['v-ok-fg'] };
  });

  // SMART sector growth: reallocated/pending for disks with non-zero counters.
  var smNames = topKeys(pts, 'smart', 1, 6);
  var hasGrowth = smNames.length > 0;
  var growthSeries = smNames.map(function (name, i) {
    return { name: name + ' realloc', color: pickColor(i + 3),
      points: pick(function (p) { return p.smart && p.smart[name] ? p.smart[name][1] : null; }) };
  });

  return h('div', null,
    h(AiFindingsPanel, { findings: P.findings, agents: ['disks', 'pools'] }),
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
          h(Chart, { series: smSeries, height: 190, area: true,
            thresholds: [{ v: 55, color: '#f8717166', label: '55° alert' }],
            yFmt: function (v) { return v + '°'; },
            empty: 'Per-disk temps appear once SMART data is cached.' }),
          h(ChartStats, { series: smSeries.map(function (s) {
            return merge(s, { fmt: function (v) { return (Math.round(v * 10) / 10) + '°'; } });
          }) })) : h('div', { class: 'v-empty' }, 'Per-disk temps appear once SMART data is cached.')),
      h(Panel, { title: 'Hottest disks', hint: 'current, vs 60° scale' },
        h(HBars, { rows: hotRows, empty: 'Waiting for SMART samples.' })),
      h(Panel, { title: 'SMART sector counters', hint: hasGrowth ? 'lifetime totals — growth is the signal' : '' },
        hasGrowth ? h(Chart, { series: growthSeries, height: 170,
          yFmt: function (v) { return String(Math.round(v)); },
          empty: 'No disks with non-zero reallocated/pending counters.' })
        : h('div', { class: 'v-empty' }, 'No disks with non-zero reallocated/pending counters — healthy.'))),
    h(Panel, { title: 'Disks', span2: true, hint: disks.length + ' devices' }, h(DiskTable, { d: d })),
    h(Panel, { title: 'SMART detail', span2: true, hint: Object.keys(d.smart || {}).length + ' disks' },
      h(SmartTable, { d: d })),
    h(Panel, { title: 'Daily rollups', span2: true,
      hint: (P.daily || []).length ? 'last ' + P.daily.length + ' days' : 'building' },
      h(DailyTable, { daily: P.daily })));
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
      h(ContainerTable, { d: d })));
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
    h(AiFindingsPanel, { findings: P.findings, agents: ['network'] }),
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
      h(CoreGrid, { d: d })));
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
          h('th', { class: 'num' }, 'Free'), h('th', null, 'AI comment'), h('th', null, '')),
        list.map(function (s) {
          return h('tr', { key: s.name },
            h('td', { class: 'v-name' }, s.name),
            h('td', { class: 'muted' }, s.comment || '—'),
            h('td', null, h(Pill, { kind: s.pool === 'only' ? 'warn' : s.pool === 'yes' ? 'run' : 'stop' },
              s.pool === 'yes' ? 'pool + array' : s.pool === 'only' ? 'pool only' : 'array only')),
            h('td', { class: 'num' }, s.free ? bytes(s.free, 1) : '—'),
            h('td', null, h(ShareCommentCell, { share: s.name })),
            h('td', null, h('button', { class: 'v-btn xs', onClick: function () { setBrowseShare(s.name); } },
              h('i', { class: 'fa fa-folder-open' }), ' Browse')));
        }))
        : h('div', { class: 'v-empty' }, 'No shares configured.')),
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

/** "Generate" kicks off agent/share-comment.mjs fire-and-forget on the PHP
 *  side, then polls ?action=share_comment for the result. LLM latency on
 *  this hardware is real (tens of seconds to a couple minutes) — the poll
 *  interval is intentionally slow (6s) so it doesn't hammer the endpoint. */
function ShareCommentCell(P) {
  var s = useState(null); var result = s[0], setResult = s[1];
  var b = useState(false); var busy = b[0], setBusy = b[1];
  var timerRef = useRef(null);

  var poll = function () {
    fetch(ENDPOINT + '?action=share_comment&share=' + encodeURIComponent(P.share))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok && j.comment) { setResult(j.comment); setBusy(false); }
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

  if (result && result.comment) {
    return h('div', { class: 'v-share-comment' },
      h('div', { class: 'muted' }, result.comment),
      h('button', { class: 'v-btn xs', style: 'margin-top:4px', disabled: busy, onClick: generate },
        h('i', { class: 'fa fa-refresh' }), ' Regenerate'));
  }
  return h('button', { class: 'v-btn xs primary', disabled: busy, onClick: generate },
    busy ? [h('i', { key: 's', class: 'fa fa-spinner fa-spin' }), ' Generating…']
         : [h('i', { key: 'm', class: 'fa fa-magic' }), ' Generate']);
}

/* ------------------------------------------------------------- hardware */

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
  var sensors = d.sensors || { temps: [], fans: [], pwms: [] };
  var tempSeries = pts.some(function (p) { return p.sensors_t && Object.keys(p.sensors_t).length; });
  var fanSeries = pts.some(function (p) { return p.sensors_f && Object.keys(p.sensors_f).length; });
  var sensorMeta = {};   // id -> {label, chip, max, crit}
  (sensors.temps || []).forEach(function (t) { sensorMeta[t.id] = t; });
  var topTempIds = (sensors.temps || []).slice(0, 4).map(function (t) { return t.id; });
  var topFanIds = (sensors.fans || []).slice(0, 4).map(function (f) { return f.id; });
  var PAL_T = ['v-temp', 'v-cpu', 'v-net', 'v-gpu'];
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
  // OctoPrint-style per-series stats under a chart.
  var hwSeriesStats = function (ids, meta, sfn) {
    return h(ChartStats, { series: ids.map(function (id, i) {
      var m = meta[id] || {};
      return { name: m.label || id.split('/').pop(), color: PAL[PAL_T[i % 4]],
        points: sfn(id), fmt: function (v) { return (Math.round(v * 10) / 10) + '°'; } };
    }) });
  };

  return h('div', null,
    h(AiFindingsPanel, { findings: P.findings, agents: ['thermal', 'general'] }),
    h(Panel, { title: 'Temperatures', span2: true,
      hint: (sensors.temps || []).length + ' sensors — headroom is distance to the chip threshold' },
      !(sensors.temps || []).length ? h('div', { class: 'v-empty' }, 'No hwmon temperature sensors found.')
      : h('div', { class: 'v-sensor-grid' },
          sensors.temps.map(function (t) {
            var hr = headroom(t);
            return h('div', { key: t.id, class: 'v-sensor v-temp-' + lvl(t), title:
              (t.crit != null ? 'chip crit: ' + t.crit + '°C' : '') +
              (t.max != null ? (t.crit != null ? ' · ' : '') + 'chip max: ' + t.max + '°C' : '') },
              h('div', { class: 'v-sensor-val' }, t.value != null ? t.value.toFixed(1) + '°' : '—'),
              h('div', { class: 'v-sensor-label', title: t.chip }, t.label),
              hr != null
                ? h('div', { class: 'v-sensor-th' }, h('b', { class: hr <= 5 ? 'crit' : hr <= 12 ? 'warn' : '' }, hr + '° headroom'),
                    (t.crit != null ? ' · crit ' + t.crit + '°' : (t.max != null ? ' · max ' + t.max + '°' : '')))
                : h('div', { class: 'v-sensor-th' }, '\u00A0'));
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
          })))) ), height: 190, area: true,
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
            var pwm = (sensors.pwms || []).filter(function (p) { return p.chip === f.chip; });
            var duty = pwm.length ? pwm[0].duty_pct : null;
            var mode = pwm.length ? pwm[0].mode : null;
            return h('div', { key: f.id, class: 'v-sensor v-fan' + (f.rpm > 0 ? '' : ' idle') },
              h('div', { class: 'v-sensor-val' }, f.rpm > 0 ? f.rpm : '0'),
              h('div', { class: 'v-sensor-label' }, 'RPM · ' + f.label),
              h('div', { class: 'v-sensor-th' },
                (duty != null ? duty + '% duty' : '') + (mode ? (duty != null ? ' · ' : '') + mode : '') || '\u00A0'),
              f.rpm > 0 ? h('div', { class: 'v-fan-bar' },
                h('i', { style: 'width:' + Math.min(100, Math.round(f.rpm / 40)) + '%' })) : null);
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
      h(SmartTable, { d: d })));
}

/* ------------------------------------------------------------------- kb */

var SEV_KB_ICON = { finding: 'fa-heartbeat', research: 'fa-flask', manual: 'fa-pencil' };

function KbTab() {
  var s1 = useState(''); var q = s1[0], setQ = s1[1];
  var s2 = useState([]); var results = s2[0], setResults = s2[1];
  var s3 = useState([]); var topics = s3[0], setTopics = s3[1];
  var s4 = useState(false); var searching = s4[0], setSearching = s4[1];
  var s5 = useState(null); var topicFilter = s5[0], setTopicFilter = s5[1];

  var loadRecent = function () {
    fetch(ENDPOINT + '?action=kb_recent', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setResults(j.docs || []); setTopics(j.topics || []); } });
  };
  useEffect(function () { loadRecent(); }, []);

  var search = function (e) {
    e.preventDefault();
    if (!q.trim()) { loadRecent(); return; }
    setSearching(true);
    fetch(ENDPOINT + '?action=kb_search&q=' + encodeURIComponent(q), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { setResults(j.results || []); setTopics(j.topics || []); } setSearching(false); })
      .catch(function () { setSearching(false); });
  };

  var shown = topicFilter ? results.filter(function (r) { return r.topic === topicFilter; }) : results;

  return h('div', null,
    h(Panel, { title: 'Search the knowledge base', span2: true,
      hint: 'built from everything the background AI agents have learned' },
      h('form', { class: 'v-kb-search', onSubmit: search },
        h('input', { class: 'v-input', style: 'flex:1', placeholder: 'e.g. cache pool temperature, disk errors, network drops…',
          value: q, onInput: function (e) { setQ(e.target.value); } }),
        h('button', { class: 'v-btn primary', type: 'submit', disabled: searching },
          h('i', { class: 'fa fa-search' }), ' Search')),
      topics.length ? h('div', { class: 'v-kb-topics' },
        h('span', { class: 'v-chip' + (topicFilter === null ? ' on' : ''), onClick: function () { setTopicFilter(null); } }, 'all'),
        topics.map(function (t) {
          return h('span', { key: t.topic, class: 'v-chip' + (topicFilter === t.topic ? ' on' : ''),
            onClick: function () { setTopicFilter(t.topic); } }, t.topic + ' (' + t.n + ')');
        })) : null),
    h(Panel, { title: q ? 'Results' : 'Recent knowledge', span2: true, hint: shown.length + ' documents' },
      !shown.length ? h('div', { class: 'v-empty' }, 'Nothing here yet — the background agents populate this as they run.')
      : shown.map(function (doc) {
          return h('div', { key: doc.id, class: 'v-kb-doc' },
            h('div', { class: 'v-kb-doc-head' },
              h('i', { class: 'fa ' + (SEV_KB_ICON[doc.source] || 'fa-file-text-o') }),
              h('span', { class: 'v-kb-doc-title' }, doc.title),
              doc.topic ? h('span', { class: 'v-ai-agent' }, doc.topic) : null,
              h('span', { class: 'muted', style: 'margin-left:auto' }, ts(doc.created_at))),
            h('div', { class: 'v-kb-doc-body' }, doc.content));
        })));
}

/* --------------------------------------------------------------- research */

function ResearchTab() {
  var s1 = useState(''); var prompt = s1[0], setPrompt = s1[1];
  var s2 = useState([]); var jobs = s2[0], setJobs = s2[1];
  var s3 = useState(null); var activeJob = s3[0], setActiveJob = s3[1];
  var s4 = useState(false); var submitting = s4[0], setSubmitting = s4[1];

  var loadJobs = function () {
    fetch(ENDPOINT + '?action=research_list', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setJobs(j.jobs || []); });
  };
  useEffect(function () { loadJobs(); var iv = setInterval(loadJobs, 10000); return function () { clearInterval(iv); }; }, []);

  var openJob = function (id) {
    fetch(ENDPOINT + '?action=research_status&id=' + id, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) setActiveJob(j.job); });
  };

  var submit = function (e) {
    e.preventDefault();
    if (!prompt.trim()) return;
    setSubmitting(true);
    var body = new URLSearchParams({ prompt: prompt, csrf_token: window.__V_CSRF__ || '' });
    fetch(ENDPOINT + '?action=research_ask', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        setSubmitting(false);
        if (j && j.ok) { setPrompt(''); loadJobs(); openJob(j.job_id); }
        else window.alert('Failed: ' + ((j && j.error) || 'unknown error'));
      })
      .catch(function () { setSubmitting(false); });
  };

  return h('div', null,
    h(Panel, { title: 'Ask a question', span2: true,
      hint: 'runs in the background using local models — may take a few minutes on this hardware' },
      h('form', { class: 'v-kb-search', onSubmit: submit, style: 'flex-direction:column;align-items:stretch;gap:8px' },
        h('textarea', { class: 'v-input', rows: 3, placeholder: 'e.g. Why has the cache pool been running hot this week? What should I check first?',
          value: prompt, onInput: function (e) { setPrompt(e.target.value); } }),
        h('button', { class: 'v-btn primary', type: 'submit', disabled: submitting, style: 'align-self:flex-start' },
          h('i', { class: 'fa fa-flask' }), submitting ? ' Submitting…' : ' Research in background'))),
    activeJob ? h(Panel, { title: 'Result', span2: true, hint: activeJob.status },
      activeJob.status === 'pending' || activeJob.status === 'running'
        ? h('div', { class: 'v-empty' }, h('i', { class: 'fa fa-spinner fa-spin' }), ' Researching — this can take a few minutes, feel free to leave this tab.')
        : activeJob.status === 'error'
        ? h('div', { class: 'v-empty' }, 'Failed: ', activeJob.error)
        : h('div', { class: 'v-kb-doc-body' }, activeJob.answer)) : null,
    h(Panel, { title: 'Past questions', span2: true, hint: jobs.length + ' total' },
      !jobs.length ? h('div', { class: 'v-empty' }, 'No research jobs yet.')
      : h(Table, null,
          h('tr', null, h('th', null, 'Question'), h('th', null, 'Status'), h('th', null, 'Asked'), h('th', null, '')),
          jobs.map(function (j) {
            return h('tr', { key: j.id },
              h('td', { class: 'v-name' }, j.prompt.length > 80 ? j.prompt.slice(0, 80) + '…' : j.prompt),
              h('td', null, h(Pill, { kind: j.status === 'done' ? 'run' : j.status === 'error' ? 'stop' : 'warn' }, j.status)),
              h('td', { class: 'muted' }, ts(j.created_at)),
              h('td', null, h('button', { class: 'v-btn xs', onClick: function () { openJob(j.id); } }, 'View')));
          }))));
}

/* ------------------------------------------------------------- settings */

function SettingsTab() {
  var s1 = useState(null), cfg = s1[0], setCfg = s1[1];
  var s2 = useState(null), meta = s2[0], setMeta = s2[1];
  var s3 = useState('idle'), saveState = s3[0], setSaveState = s3[1];

  var reload = function () {
    fetch(ENDPOINT + '?action=settings', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        setCfg({
          INTERVAL: j.cfg.INTERVAL || '1', KEEP_DAYS: j.cfg.KEEP_DAYS || '90',
          SET_STARTPAGE: j.cfg.SET_STARTPAGE || 'no',
          ALERT_TEMP: j.cfg.ALERT_TEMP || '55', ALERT_FILL: j.cfg.ALERT_FILL || '90',
          ALERT_LOAD: j.cfg.ALERT_LOAD || '0', ALERT_RESTARTS: j.cfg.ALERT_RESTARTS || '3'
        });
        setMeta(j);
      });
  };
  useEffect(reload, []);

  if (!cfg) return h('div', { class: 'v-panel' }, h('div', { class: 'v-empty' }, 'Loading settings…'));

  var set = function (k) { return function (e) {
    var v = e.target.value;
    setCfg(function (c) { var n = merge(c, {}); n[k] = v; return n; });
  }; };

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
    h(Panel, { title: 'Collector status' },
      h('table', null, [
        ['Last sample', meta.last_run ? ts(meta.last_run) : '—'],
        ['Ring buffer', meta.ring_samples + ' samples'],
        ['Flash rollups', meta.flash_files + ' file(s), ' + bytes(meta.flash_bytes, 1)]
      ].map(function (r, i) {
        return h('tr', { key: i }, h('td', { class: 'muted' }, r[0]), h('td', { class: 'num v-name' }, r[1]));
      }))),
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
            value: cfg.ALERT_RESTARTS, onInput: set('ALERT_RESTARTS') }), ' (0 = off)'))),
      h('div', { style: 'margin-top:12px;display:flex;gap:8px;align-items:center' },
        h('button', { class: 'v-btn primary', onClick: save, disabled: saveState === 'saving' },
          h('i', { class: 'fa fa-save' }), ' ' + (saveState === 'saving' ? 'Saving…' : 'Save')),
        saveState === 'saved' ? h('span', { class: 'ok' }, h('i', { class: 'fa fa-check' }), ' Saved & reapplied') : null,
        saveState === 'error' ? h('span', { class: 'crit' }, 'Save failed') : null)),
    h(Panel, { title: 'Collector log', span2: true },
      h('pre', { class: 'v-mono v-scroll', style: 'max-height:220px;margin:0;white-space:pre-wrap' },
        meta.log_tail || '(no output yet)')));
}

/* ---------------------------------------------------------------- mount */

if (mountEl) render(h(App), mountEl);
})();
