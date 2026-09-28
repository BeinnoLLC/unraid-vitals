/* unraid-vitals — front-end. Vanilla JS, no frameworks, no CDN. */
window.Vitals = (function () {
  'use strict';

  var cfg = { endpoint: '', range: 360, timer: null, snap: null, ring: [] };

  /* ------------------------------------------------------------- utilities */

  function el(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function bytes(n, p) {
    if (n == null || isNaN(n)) return '—';
    var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], i = 0;
    n = Number(n);
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return n.toFixed(p == null ? (i > 2 ? 2 : 0) : p) + ' ' + u[i];
  }

  function rate(n) { return n == null ? '—' : bytes(n, 1) + '/s'; }

  function pct(n) { return n == null ? '—' : Number(n).toFixed(1) + '%'; }

  function dur(sec) {
    if (sec == null) return '—';
    var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
    if (d) return d + 'd ' + h + 'h';
    if (h) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function timeAgo(ts) {
    if (!ts) return '';
    var s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    return Math.floor(s / 3600) + 'h ago';
  }

  /** green / amber / red by thresholds */
  function level(v, warn, crit) {
    if (v == null) return 'muted';
    if (v >= crit) return 'crit';
    if (v >= warn) return 'warn';
    return 'ok';
  }

  /* ------------------------------------------------------------ sparklines */

  /**
   * Render a small multi-series line chart into #id.
   * series: [{ name, color, points: [[t, value]], max, fmt }]
   * X is shared across series and taken from the first series' time span.
   */
  function chart(id, series, opts) {
    opts = opts || {};
    var host = el(id);
    if (!host) return;

    var all = [];
    series.forEach(function (s) { (s.points || []).forEach(function (p) { if (p[1] != null) all.push(p); }); });
    if (all.length < 2) {
      host.innerHTML = '<div class="v-empty">' + esc(opts.empty || 'Not enough samples yet — history builds up over the next few minutes.') + '</div>';
      return;
    }

    var W = 600, H = 132, padL = 4, padR = 4, padT = 10, padB = 16;
    var t0 = Math.min.apply(null, all.map(function (p) { return p[0]; }));
    var t1 = Math.max.apply(null, all.map(function (p) { return p[0]; }));
    if (t1 <= t0) t1 = t0 + 1;

    var maxV = opts.max;
    if (!maxV) {
      maxV = 0;
      all.forEach(function (p) { if (p[1] > maxV) maxV = p[1]; });
      maxV = maxV * 1.15;
    }
    if (!maxV || maxV <= 0) maxV = 1;

    var X = function (t) { return padL + (W - padL - padR) * ((t - t0) / (t1 - t0)); };
    var Y = function (v) { return H - padB - (H - padT - padB) * Math.max(0, Math.min(1, v / maxV)); };

    var p = [];
    p.push('<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img">');

    // horizontal grid + max label
    [0.25, 0.5, 0.75, 1].forEach(function (f) {
      var y = Y(maxV * f);
      p.push('<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y +
             '" stroke="currentColor" stroke-opacity=".1" stroke-dasharray="2 3"/>');
    });

    series.forEach(function (s) {
      var pts = (s.points || []).filter(function (q) { return q[1] != null; });
      if (pts.length < 2) return;
      var d = pts.map(function (q, i) { return (i ? 'L' : 'M') + X(q[0]).toFixed(1) + ' ' + Y(q[1]).toFixed(1); }).join(' ');
      // area fill
      p.push('<path d="' + d + ' L' + X(pts[pts.length - 1][0]).toFixed(1) + ' ' + (H - padB) +
             ' L' + X(pts[0][0]).toFixed(1) + ' ' + (H - padB) + ' Z" fill="' + s.color +
             '" fill-opacity=".13"/>');
      p.push('<path d="' + d + '" fill="none" stroke="' + s.color + '" stroke-width="1.8" ' +
             'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>');
    });

    // time labels
    var mid = t0 + (t1 - t0) / 2;
    var clock = function (t) {
      var d = new Date(t * 1000);
      return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    };
    p.push('<text x="' + padL + '" y="' + (H - 3) + '" font-size="10" fill="currentColor" fill-opacity=".45">' + clock(t0) + '</text>');
    p.push('<text x="' + (W / 2) + '" y="' + (H - 3) + '" font-size="10" fill="currentColor" fill-opacity=".45" text-anchor="middle">' + clock(mid) + '</text>');
    p.push('<text x="' + (W - padR) + '" y="' + (H - 3) + '" font-size="10" fill="currentColor" fill-opacity=".45" text-anchor="end">' + clock(t1) + '</text>');
    p.push('<text x="' + (W - padR) + '" y="' + (padT + 8) + '" font-size="10" fill="currentColor" fill-opacity=".5" text-anchor="end">' +
           esc(opts.maxLabel || (opts.max ? String(opts.max) : (maxV > 100 ? maxV.toFixed(0) : maxV.toFixed(1)))) + '</text>');
    p.push('</svg>');
    host.innerHTML = p.join('');
  }

  /* ----------------------------------------------------------------- cards */

  function card(c) {
    var cls = c.level ? ' ' + c.level : '';
    return '<div class="v-card">' +
      '<div class="v-card-label">' + esc(c.label) + '</div>' +
      '<div class="v-card-value' + cls + '">' + c.value +
        (c.unit ? ' <small>' + esc(c.unit) + '</small>' : '') + '</div>' +
      (c.sub ? '<div class="v-card-sub">' + c.sub + '</div>' : '') +
      (c.bar != null ? '<div class="v-bar"><i style="width:' + Math.max(0, Math.min(100, c.bar)) +
        '%;background:' + (c.color || 'var(--v-cpu)') + '"></i></div>' : '') +
      '</div>';
  }

  function renderCards(s) {
    var mem = s.mem || {}, load = s.load || {}, arr = s.array || {}, t = arr.totals || {};
    var temps = [];
    (arr.data || []).concat(arr.parity || []).forEach(function (d) { if (d.temp != null) temps.push(d.temp); });
    var tMax = temps.length ? Math.max.apply(null, temps) : null;

    var cpuPct = s.cpu ? s.cpu.total : null;
    var cards = [
      { label: 'CPU load', value: pct(cpuPct), level: level(cpuPct, 80, 95),
        sub: (load.cores || '?') + ' cores · load ' + (load.l1 != null ? load.l1.toFixed(2) : '—'),
        bar: cpuPct, color: 'var(--v-cpu)' },
      { label: 'Memory', value: pct(mem.pct), level: level(mem.pct, 80, 92),
        sub: bytes(mem.used) + ' of ' + bytes(mem.total), bar: mem.pct, color: 'var(--v-mem)' },
      { label: 'Array', value: String(arr.totals ? arr.totals.data_disks : 0) + '<small> data</small>',
        sub: (s.system && s.system.md_state ? s.system.md_state : '—') +
             ' · ' + (arr.totals ? arr.totals.parity_disks : 0) + ' parity' },
      { label: 'Storage used', value: pct(t.used_pct), level: level(t.used_pct, 85, 95),
        sub: bytes(t.fs_used) + ' of ' + bytes(t.fs_size), bar: t.used_pct, color: 'var(--v-mem)' },
      { label: 'Hottest disk', value: tMax == null ? '—' : tMax, unit: tMax == null ? '' : '°C',
        level: tMax == null ? 'muted' : level(tMax, 45, 55),
        sub: temps.length + ' disks reporting' },
      { label: 'Containers', value: (s.docker ? s.docker.running : 0) + '<small> / ' +
          (s.docker ? s.docker.count : 0) + '</small>',
        sub: s.docker && s.docker.stopped ? s.docker.stopped + ' stopped' : 'all running' },
      { label: 'Uptime', value: dur(s.system ? s.system.uptime : null),
        sub: s.system ? esc(s.system.version) : '' },
      { label: 'Shares', value: String(s.shares ? s.shares.total : 0),
        sub: s.shares ? s.shares.cache + ' cached · ' + s.shares.array + ' array' : '' }
    ];
    if (s.gpu && s.gpu.length) {
      var g = s.gpu[0];
      cards.splice(5, 0, { label: 'GPU', value: pct(g.util), level: level(g.util, 90, 98),
        sub: bytes(g.mem_used) + ' / ' + bytes(g.mem_total) + (g.temp != null ? ' · ' + g.temp + '°C' : ''),
        bar: g.util, color: 'var(--v-load)' });
    }
    el('v-cards').innerHTML = cards.map(card).join('');
  }

  /* --------------------------------------------------------------- panels */

  function renderArray(s) {
    var arr = s.array || {}, rows = [];
    var mk = function (d) {
      var used = d.usedPct;
      return '<tr>' +
        '<td class="v-name">' + esc(d.name) + '</td>' +
        '<td><span class="v-pill">' + esc(d.type || '—') + '</span></td>' +
        '<td class="v-mono">' + esc(d.device || '—') + '</td>' +
        '<td class="num">' + (d.temp == null ? '<span class="muted">—</span>'
            : '<span class="' + level(d.temp, 45, 55) + '">' + d.temp + '°C</span>') + '</td>' +
        '<td class="num">' + bytes(d.fsSize) + '</td>' +
        '<td class="num">' + (d.fsSize ? bytes(d.fsUsed) : '<span class="muted">—</span>') + '</td>' +
        '<td class="num" style="width:120px">' +
          (d.fsSize ? '<div class="v-bar" style="margin:0"><i style="width:' + used +
            '%;background:' + (used > 90 ? 'var(--v-temp)' : used > 75 ? 'var(--v-load)' : 'var(--v-rx)') + '"></i></div>' +
            '<span class="muted" style="font-size:11px">' + used + '%</span>' : '<span class="muted">—</span>') +
        '</td>' +
        '<td class="num ' + (d.numErrors > 0 ? 'crit' : 'muted') + '">' + d.numErrors + '</td>' +
        '</tr>';
    };
    var head = '<tr><th>Disk</th><th>Type</th><th>Device</th><th class="num">Temp</th>' +
               '<th class="num">Size</th><th class="num">Used</th><th class="num">Fill</th>' +
               '<th class="num">Errors</th></tr>';

    (arr.parity || []).forEach(function (d) { rows.push(mk(d)); });
    (arr.data || []).forEach(function (d) { rows.push(mk(d)); });
    (arr.cache || []).forEach(function (d) { rows.push(mk(d)); });

    var t = arr.totals || {};
    var foot = '<div class="v-card-sub" style="margin-top:8px">' +
      bytes(t.fs_free) + ' free of ' + bytes(t.fs_size) + ' · ' +
      (t.raw ? 'raw capacity ' + bytes(t.raw) : '') + '</div>';

    el('v-array').innerHTML = rows.length
      ? '<div class="v-scroll"><table>' + head + rows.join('') + '</table></div>' + foot
      : '<div class="v-empty muted">No array devices found.</div>';
  }

  function renderPools(s) {
    var arr = s.array || {};
    var pools = (arr.cache || []);
    if (!pools.length) { el('v-pools').innerHTML = '<div class="muted" style="font-size:13px">No pool devices.</div>'; return; }
    var html = '<div class="v-scroll"><table><tr><th>Pool device</th><th class="num">Size</th><th class="num">Used</th><th class="num">Temp</th></tr>';
    pools.forEach(function (d) {
      html += '<tr><td class="v-name">' + esc(d.name) + '</td>' +
        '<td class="num">' + bytes(d.fsSize) + '</td>' +
        '<td class="num">' + pct(d.usedPct) + '</td>' +
        '<td class="num">' + (d.temp == null ? '<span class="muted">—</span>' : d.temp + '°C') + '</td></tr>';
    });
    html += '</table></div>';
    html += '<div class="v-card-sub" style="margin-top:8px">' + (s.shares ? s.shares.cache : 0) +
            ' share(s) configured to use cache.</div>';
    el('v-pools').innerHTML = html;
  }

  function renderDocker(s) {
    var d = s.docker || {}, list = d.containers || [];
    el('v-docker-hint').textContent = d.running + ' running of ' + d.count;
    if (!list.length) { el('v-docker').innerHTML = '<div class="muted" style="font-size:13px">No containers.</div>'; return; }
    var html = '<div class="v-scroll"><table><tr><th>Container</th><th>State</th>' +
               '<th class="num">CPU</th><th class="num">Mem</th><th class="num">Mem %</th><th>Uptime</th></tr>';
    list.forEach(function (c) {
      var st = c.state === 'running' ? 'run' : 'stop';
      html += '<tr><td class="v-name">' + esc(c.name) + '</td>' +
        '<td><span class="v-pill ' + st + '">' + esc(c.state) + '</span></td>' +
        '<td class="num ' + level(c.cpu, 60, 90) + '">' + (c.cpu == null ? '—' : c.cpu.toFixed(1) + '%') + '</td>' +
        '<td class="num">' + esc(c.mem || '—') + '</td>' +
        '<td class="num">' + (c.mem_pct == null ? '—' : c.mem_pct.toFixed(1) + '%') + '</td>' +
        '<td class="muted">' + esc((c.status || '').replace(/^Up\s*/, '') || '—') + '</td></tr>';
    });
    el('v-docker').innerHTML = html + '</table></div>';
  }

  function renderProcs(s) {
    var list = s.top || [];
    if (!list.length) { el('v-procs').innerHTML = '<div class="muted" style="font-size:13px">—</div>'; return; }
    var html = '<table><tr><th>Process</th><th class="num">CPU</th><th class="num">Mem</th><th class="num">RSS</th></tr>';
    list.forEach(function (p) {
      html += '<tr><td class="v-name v-mono">' + esc(p.name) + '</td>' +
        '<td class="num">' + p.cpu.toFixed(1) + '%</td>' +
        '<td class="num">' + p.mem.toFixed(1) + '%</td>' +
        '<td class="num">' + bytes(p.rss) + '</td></tr>';
    });
    el('v-procs').innerHTML = html + '</table>';
  }

  function renderSmart(s) {
    var list = Object.keys(s.smart || {}).map(function (k) { return s.smart[k]; });
    if (!list.length) {
      el('v-smart').innerHTML = '<div class="muted" style="font-size:13px">No SMART data cached yet.</div>';
      return;
    }
    var html = '<div class="v-scroll"><table><tr><th>Disk</th><th>Health</th><th class="num">Temp</th>' +
               '<th class="num">Hours</th><th class="num">Realloc</th><th class="num">Pending</th>' +
               '<th class="num">CRC</th></tr>';
    list.forEach(function (r) {
      var bad = (r.reallocated || 0) + (r.pending || 0) + (r.uncorrectable || 0);
      var hcls = r.health === 'PASSED' ? 'ok' : (r.health ? 'crit' : 'muted');
      var htxt = (r.health || 'n/a').replace('PASSED', 'Passed').replace('FAILED!', 'FAILED');
      html += '<tr><td class="v-name">' + esc(r.name) + '</td>' +
        '<td><span class="' + hcls + '">' + esc(htxt) + '</span></td>' +
        '<td class="num ' + level(r.temp, 45, 55) + '">' + (r.temp == null ? '—' : r.temp + '°C') + '</td>' +
        '<td class="num">' + (r.hours == null ? '—' : r.hours.toLocaleString()) + '</td>' +
        '<td class="num ' + (r.reallocated ? 'crit' : 'muted') + '">' + (r.reallocated == null ? '—' : r.reallocated) + '</td>' +
        '<td class="num ' + (r.pending ? 'crit' : 'muted') + '">' + (r.pending == null ? '—' : r.pending) + '</td>' +
        '<td class="num ' + (r.crc ? 'warn' : 'muted') + '">' + (r.crc == null ? '—' : r.crc) + '</td></tr>';
    });
    el('v-smart').innerHTML = html + '</table></div>';
    if (list.some(function (r) { return (r.reallocated || 0) + (r.pending || 0) > 0; })) {
      el('v-smart').insertAdjacentHTML('beforeend',
        '<div class="crit" style="font-size:12px;margin-top:8px">Reallocated or pending sectors present — check disk health.</div>');
    }
  }

  function renderGpu(s) {
    var g = (s.gpu || [])[0];
    if (!g) { el('v-gpu-panel').hidden = true; return; }
    el('v-gpu-panel').hidden = false;
    var rows = [
      ['Model', esc(g.name || '—')],
      ['Utilisation', pct(g.util)],
      ['VRAM', bytes(g.mem_used) + ' / ' + bytes(g.mem_total)],
      ['Temperature', g.temp == null ? '—' : g.temp + '°C'],
      ['Power', g.power == null ? '—' : g.power.toFixed(1) + ' W' + (g.power_limit ? ' / ' + g.power_limit.toFixed(0) + ' W' : '')],
      ['Fan', g.fan == null ? '—' : g.fan + '%']
    ];
    el('v-gpu').innerHTML = '<table>' + rows.map(function (r) {
      return '<tr><td>' + r[0] + '</td><td class="num v-name">' + r[1] + '</td></tr>';
    }).join('') + '</table>';
    if (g.mem_total) {
      el('v-gpu').insertAdjacentHTML('beforeend', '<div class="v-bar"><i style="width:' +
        (100 * g.mem_used / g.mem_total).toFixed(1) + '%;background:var(--v-load)"></i></div>');
    }
  }

  function renderUps(s) {
    var u = s.ups || {};
    var keys = ['STATUS', 'LINEV', 'LOADPCT', 'BCHARGE', 'TIMELEFT', 'BATTV', 'MODEL'];
    var have = keys.filter(function (k) { return u[k] != null; });
    if (!have.length) { el('v-ups-panel').hidden = true; return; }
    el('v-ups-panel').hidden = false;
    var label = { STATUS: 'Status', LINEV: 'Line voltage', LOADPCT: 'Load', BCHARGE: 'Battery',
                  TIMELEFT: 'Runtime left', BATTV: 'Battery voltage', MODEL: 'Model' };
    el('v-ups').innerHTML = '<table>' + have.map(function (k) {
      return '<tr><td>' + label[k] + '</td><td class="num v-name">' + esc(u[k]) + '</td></tr>';
    }).join('') + '</table>';
  }

  function renderDaily(s) {
    fetch(cfg.endpoint + '?action=daily&days=30', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var d = j.daily || [];
        if (!d.length) {
          el('v-daily').innerHTML = '<div class="muted" style="font-size:13px">Daily rollups appear after the first full hour of collection.</div>';
          return;
        }
        var html = '<div class="v-scroll"><table><tr><th>Day</th><th class="num">CPU avg</th>' +
                   '<th class="num">Mem avg</th><th class="num">Peak temp</th></tr>';
        d.slice().reverse().forEach(function (r) {
          html += '<tr><td class="v-name">' + esc(r.day) + '</td>' +
            '<td class="num">' + (r.cpu == null ? '—' : r.cpu + '%') + '</td>' +
            '<td class="num">' + (r.mem == null ? '—' : r.mem + '%') + '</td>' +
            '<td class="num ' + level(r.temp_max, 50, 58) + '">' + (r.temp_max == null ? '—' : r.temp_max + '°C') + '</td></tr>';
        });
        el('v-daily').innerHTML = html + '</table></div>';
      })
      .catch(function () { el('v-daily').innerHTML = '<div class="muted">—</div>'; });
  }

  /* --------------------------------------------------------------- charts */

  function renderCharts(ring) {
    var n = cfg.range;
    var pts = ring.slice(-n);
    var pick = function (f) { return pts.map(function (p) { return [p.t, f(p)]; }); };

    chart('v-chart-resource', [
      { name: 'cpu', color: 'var(--v-cpu)', points: pick(function (p) { return p.cpu; }) },
      { name: 'mem', color: 'var(--v-mem)', points: pick(function (p) { return p.mem; }) },
      { name: 'load', color: 'var(--v-load)', points: pick(function (p) {
          return p.load == null ? null : Math.min(100, p.load * 100 / (cfg.snap && cfg.snap.load && cfg.snap.load.cores ? cfg.snap.load.cores : 1)); }) }
    ], { max: 100, maxLabel: '100%', empty: 'History builds up as the collector runs each minute.' });

    chart('v-chart-net', [
      { name: 'rx', color: 'var(--v-rx)', points: pick(function (p) { return p.net_rx; }) },
      { name: 'tx', color: 'var(--v-tx)', points: pick(function (p) { return p.net_tx; }) }
    ], { maxLabel: '' });

    chart('v-chart-temp', [
      { name: 'temp', color: 'var(--v-temp)', points: pick(function (p) { return p.temp_max; }) }
    ], {});

    el('v-chart-hint').textContent = pts.length + ' samples · ' + cfg.range + ' min window';
  }

  /* ----------------------------------------------------------------- load */

  function render(j) {
    var s = j.data || {};
    cfg.snap = s;
    cfg.ring = j.ring || [];
    el('v-server').innerHTML = esc((s.system && s.system.name) || 'server') +
      (s.system && s.system.cpu ? ' · ' + esc(s.system.cpu) : '');
    el('v-stamp').textContent = (s.time ? new Date(s.time * 1000).toLocaleTimeString() : '');

    renderCards(s);
    renderCharts(cfg.ring);
    renderArray(s);
    renderPools(s);
    renderDocker(s);
    renderProcs(s);
    renderSmart(s);
    renderGpu(s);
    renderUps(s);
    renderDaily(s);
  }

  function fetchData(force) {
    var url = cfg.endpoint + (force ? '?action=refresh' : '?action=data');
    return fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function (e) {
        var st = el('v-stamp');
        if (st) st.innerHTML = '<span class="crit">collection error</span>';
        console.error('[vitals]', e);
      });
  }

  function init(options) {
    cfg.endpoint = (options && options.endpoint) || 'ajax.php';
    var sel = el('v-range');
    if (sel) {
      sel.value = String(cfg.range);
      sel.addEventListener('change', function () {
        cfg.range = parseInt(sel.value, 10) || 360;
        renderCharts(cfg.ring);
      });
    }
    var btn = el('v-refresh');
    if (btn) btn.addEventListener('click', function () { fetchData(true); });

    fetchData(false);
    cfg.timer = setInterval(function () { fetchData(false); }, 60000);
  }

  return { init: init, refresh: fetchData };
})();
