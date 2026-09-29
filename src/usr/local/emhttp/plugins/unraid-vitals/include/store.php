<?php
/**
 * unraid-vitals — history store
 *
 * Two tiers:
 *   1. RAM ring buffer  /var/tmp/unraid-vitals/history.json   (24h @ 1/min)
 *   2. Flash rollups    /boot/config/plugins/unraid-vitals/history/YYYY-MM.jsonl
 *      one aggregate line per hour — tiny, low flash wear, survives reboot.
 *
 * Ring points carry the full per-entity series (per interface, per container,
 * per disk) so the UI can chart any one of them without a second data source.
 *
 * The agents' SQLite DB (findings, knowledge base, research jobs) is separate
 * from both tiers: it lives in the Unraid appdata share — see v_db_path().
 */

require_once __DIR__ . '/collect.php';

if (!defined('VITALS_FLASH')) define('VITALS_FLASH', '/boot/config/plugins/unraid-vitals');
if (!defined('VITALS_RING_MAX')) define('VITALS_RING_MAX', 1440);   // 24h @ 1/min
if (!defined('VITALS_DOCKER_CFG')) define('VITALS_DOCKER_CFG', '/boot/config/docker.cfg');
if (!defined('VITALS_AI_RENOTIFY')) define('VITALS_AI_RENOTIFY', 86400);   // re-raise a standing AI finding once a day

/**
 * Directory holding the agents' SQLite DB. Persistent by default — the
 * knowledge base has to survive a reboot, so it cannot sit in the RAM-backed
 * state dir. Resolution (agent/lib/db.mjs mirrors this — keep the two in step):
 *   1. DATA_DIR in vitals.cfg, when set
 *   2. <Docker's appdata path>/unraid-vitals   (docker.cfg DOCKER_APP_CONFIG_PATH,
 *      /mnt/user/appdata when Docker has none configured)
 *
 * Returns '' while the parent directory is missing, i.e. the array is stopped.
 * Only our own leaf directory is ever created: creating /mnt/user/... on an
 * unmounted array would silently write into RAM.
 */
function v_data_dir(): string {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $dir = trim((string)($cfg['DATA_DIR'] ?? ''));
  if ($dir === '') {
    $docker = @parse_ini_file(VITALS_DOCKER_CFG) ?: [];
    $root = trim((string)($docker['DOCKER_APP_CONFIG_PATH'] ?? ''));
    if ($root === '') $root = '/mnt/user/appdata';
    $dir = rtrim($root, '/') . '/unraid-vitals';
  }
  $dir = rtrim($dir, '/');
  if ($dir === '' || !is_dir(dirname($dir))) return '';
  if (!is_dir($dir) && !@mkdir($dir, 0755)) return '';
  return $dir;
}

/** Full path of the agents' DB, or '' while v_data_dir() is unavailable. */
function v_db_path(): string {
  $dir = v_data_dir();
  if ($dir === '') return '';
  $db = $dir . '/vitals.db';
  // One-time move off RAM: versions before this kept the DB in the state dir.
  $legacy = v_state_dir() . '/vitals.db';
  if (!is_file($db) && is_file($legacy)) @copy($legacy, $db);
  return $db;
}

/** Threshold defaults, overridable from vitals.cfg. */
function v_thresholds(): array {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  return [
    'temp'      => (int)($cfg['ALERT_TEMP'] ?? 55),
    'load'      => (float)($cfg['ALERT_LOAD'] ?? 0),      // 0 = off
    'fill'      => (int)($cfg['ALERT_FILL'] ?? 90),
    'restarts'  => (int)($cfg['ALERT_RESTARTS'] ?? 3),
  ];
}

function v_latest_path(): string { return v_state_dir() . '/latest.json'; }
function v_ring_path():   string { return v_state_dir() . '/history.json'; }
function v_alert_path():  string { return v_state_dir() . '/alerts.json'; }

function v_read_json(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

function v_write_json(string $path, $data): bool {
  $tmp = $path . '.tmp';
  $ok  = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false;
  if ($ok) @rename($tmp, $path);
  return $ok;
}

function v_latest(): array {
  $l = v_read_json(v_latest_path());
  if (!$l) return [];
  $l['_age'] = time() - (int)($l['time'] ?? 0);
  return $l;
}

/**
 * The slim per-minute record used for charts.
 *
 * Scalar series first (cheap for the common charts), then the per-entity maps:
 *   net        { "<if>": [rx, tx] }             bytes/s
 *   containers { "<name>": [cpu, mem] }         percent, KiB
 *   smart      { "<dev>": [temp, realloc, pending] }
 *   gpu_hist   [util, mem_mib, temp, watts, fan]
 */
function v_point(array $snap): array {
  $temps = [];
  foreach ($snap['array']['data'] ?? [] as $d)   if ($d['temp'] !== null) $temps[] = $d['temp'];
  foreach ($snap['array']['parity'] ?? [] as $d) if ($d['temp'] !== null) $temps[] = $d['temp'];

  // Sensor (hwmon) series — separate from disk temps above: this is
  // CPU/motherboard/NVMe temps and fan RPMs, charted on the Hardware tab.
  $sTemps = []; $sFans = [];
  foreach ($snap['sensors']['temps'] ?? [] as $t) $sTemps[$t['id']] = $t['value'];
  foreach ($snap['sensors']['fans'] ?? [] as $f)  $sFans[$f['id']]  = $f['rpm'];

  $netRx = $netTx = 0.0;
  $net = [];
  foreach ($snap['net'] ?? [] as $if => $n) {
    $netRx += $n['rx_rate']; $netTx += $n['tx_rate'];
    $net[$if] = [round($n['rx_rate'], 0), round($n['tx_rate'], 0)];
  }

  $gpuUtil = null; $gpu = null;
  foreach (($snap['gpu'] ?? []) as $i => $g) {
    if (!is_array($g) || (isset($g['available']) && !$g['available'])) continue;
    if (($g['util'] ?? null) !== null) $gpuUtil = max($gpuUtil ?? 0, $g['util']);
    if ($gpu === null) {
      $gpu = [
        $g['util'], $g['mem_used'] === null ? null : round($g['mem_used'] / 1048576, 0),
        $g['temp'], $g['power'], $g['fan'],
      ];
    }
  }

  $containers = [];
  foreach ($snap['docker']['containers'] ?? [] as $c) {
    if ($c['cpu'] === null && $c['mem_pct'] === null) continue;
    $containers[$c['name']] = [$c['cpu'], $c['mem_bytes'] === null ? null : round($c['mem_bytes'] / 1024, 0)];
  }

  $smart = [];
  foreach ($snap['smart'] ?? [] as $dev => $s) {
    if ($s['temp'] === null && $s['reallocated'] === null && $s['pending'] === null) continue;
    $smart[$s['name']] = [$s['temp'], $s['reallocated'], $s['pending']];
  }

  return [
    't'        => (int)($snap['time'] ?? time()),
    'cpu'      => $snap['cpu']['total'] ?? null,
    'mem'      => $snap['mem']['pct'] ?? null,
    'load'     => $snap['load']['l1'] ?? null,
    'net_rx'   => round($netRx, 1),
    'net_tx'   => round($netTx, 1),
    'temp_max' => $temps ? max($temps) : null,
    'temp_avg' => $temps ? round(array_sum($temps) / count($temps), 1) : null,
    'gpu'      => $gpuUtil,
    'fs_used'  => $snap['array']['totals']['fs_used'] ?? null,
    'fill_max' => v_fill_max($snap),
    'var_log_pct' => $snap['fs_watch']['var_log']['used_pct'] ?? null,
    'tmp_pct'  => $snap['fs_watch']['tmp']['used_pct'] ?? null,
    'docker'   => $snap['docker']['running'] ?? null,
    'docker_img_pct' => $snap['docker_image']['used_pct'] ?? null,
    'net'      => $net,
    'ctr'      => $containers,
    'smart'    => $smart,
    'gpu_hist' => $gpu,
    'sensors_t' => $sTemps,
    'sensors_f' => $sFans,
  ];
}

function v_fill_max(array $snap): ?float {
  $max = null;
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    if (($d['fsSize'] ?? 0) > 0) $max = max($max ?? 0, $d['usedPct']);
  }
  return $max;
}

function v_ring_append(array $point): void {
  $ring = v_read_json(v_ring_path());
  $ring[] = $point;
  if (count($ring) > VITALS_RING_MAX) $ring = array_slice($ring, -VITALS_RING_MAX);
  v_write_json(v_ring_path(), $ring);
}

function v_ring(): array { return v_read_json(v_ring_path()); }

/**
 * Aggregate a set of numbers, ignoring nulls: avg (1dp), min, max, p95.
 * Returns all-null when nothing usable was passed (an empty hour, a metric
 * that never reported).
 */
function v_agg(array $vals): array {
  $vals = array_values(array_filter($vals, fn($v) => $v !== null));
  if (!$vals) return ['avg' => null, 'min' => null, 'max' => null, 'p95' => null];
  sort($vals);
  $n = count($vals);
  $p95idx = max(0, min($n - 1, (int)ceil(0.95 * $n) - 1));
  return [
    'avg' => round(array_sum($vals) / $n, 1),
    'min' => $vals[0],
    'max' => $vals[$n - 1],
    'p95' => $vals[$p95idx],
  ];
}

/**
 * Total bytes transferred over a set of ring points, not a sum of the
 * per-point rates (which double-counts — rates already integrate the gap
 * since the previous sample; summing them again multiplies by sample count).
 * Trapezoid over consecutive points' rates × the actual gap between them, so
 * an uneven collection interval (a missed minute, a paused collector) does
 * not skew the total. A single-point hour falls back to rate × 3600 — the
 * best available guess when there is nothing to integrate against.
 */
function v_hour_bytes(array $points, string $key): float {
  $n = count($points);
  if ($n === 0) return 0.0;
  if ($n === 1) return (float)($points[0][$key] ?? 0) * 3600;
  $total = 0.0;
  for ($i = 1; $i < $n; $i++) {
    $dt = max(0, min(3600, (int)$points[$i]['t'] - (int)$points[$i - 1]['t']));
    $avgRate = (((float)($points[$i][$key] ?? 0)) + ((float)($points[$i - 1][$key] ?? 0))) / 2;
    $total += $avgRate * $dt;
  }
  return $total;
}

/**
 * Append one hourly aggregate line to flash. Called by the collector right
 * after the new point lands in the ring, so it only actually writes when the
 * hour rolls over — flash sees ~24 writes/day.
 *
 * Plan 106 P13-02 — this used to store the single snapshot taken at the
 * moment the hour rolled over, not the hour that just closed: a quiet
 * `:00` hid a busy `:30`. Now it aggregates every ring point that falls in
 * the closed hour (avg/min/max/p95 for cpu/mem/load/temp/gpu, integrated
 * bytes for network) and labels the row with that closed hour, not the one
 * that just started.
 */
function v_rollup(array $ring, array $snap): void {
  $hour = (int)floor(((int)$snap['time']) / 3600);
  $markerFile = v_state_dir() . '/last_rollup_hour';
  $last = (int)@file_get_contents($markerFile);
  if ($last === $hour) return;

  $dir = VITALS_FLASH . '/history';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  if (!is_dir($dir)) return;

  $closedHour = $hour - 1;
  $points = array_values(array_filter(
    $ring,
    fn($p) => isset($p['t']) && (int)floor(((int)$p['t']) / 3600) === $closedHour,
  ));
  // Nothing landed in the just-closed hour (collector just started, or a gap) —
  // fall back to the current snapshot alone rather than write nothing.
  if (!$points) {
    $points = [v_point($snap)];
    $closedHour = $hour;
  }

  $cpu = v_agg(array_column($points, 'cpu'));
  $mem = v_agg(array_column($points, 'mem'));
  $load = v_agg(array_column($points, 'load'));
  $temp = v_agg(array_column($points, 'temp_max'));
  $gpu = v_agg(array_column($points, 'gpu'));
  $lastPoint = $points[count($points) - 1];

  $line = [
    'h'         => $closedHour,
    'n'         => count($points),
    'cpu_avg'   => $cpu['avg'], 'cpu_min' => $cpu['min'], 'cpu_max' => $cpu['max'], 'cpu_p95' => $cpu['p95'],
    'mem_avg'   => $mem['avg'], 'mem_min' => $mem['min'], 'mem_max' => $mem['max'], 'mem_p95' => $mem['p95'],
    'load_avg'  => $load['avg'], 'load_max' => $load['max'],
    'temp_avg'  => $temp['avg'], 'temp_max' => $temp['max'],
    'gpu_avg'   => $gpu['avg'], 'gpu_max' => $gpu['max'],
    'net_rx'    => round(v_hour_bytes($points, 'net_rx'), 0),
    'net_tx'    => round(v_hour_bytes($points, 'net_tx'), 0),
    'fs_used'   => $lastPoint['fs_used'] ?? ($snap['array']['totals']['fs_used'] ?? null),
    'fill_max'  => v_agg(array_column($points, 'fill_max'))['max'],
    'docker_img_pct' => $lastPoint['docker_img_pct'] ?? ($snap['docker_image']['used_pct'] ?? null),
    // SMART counters are monotonic — keep the hour's high-water mark so growth is
    // visible even when a single sample reads clean.
    'smart'     => $snap['smart'] ?? null,
  ];
  @file_put_contents($dir . '/' . date('Y-m', $closedHour * 3600) . '.jsonl',
                     json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  @file_put_contents($markerFile, (string)$hour);
}

/** Daily aggregates derived from the flash rollups (for longer-range charts). */
function v_daily(int $days = 90): array {
  $dir = VITALS_FLASH . '/history';
  if (!is_dir($dir)) return [];
  $buckets = [];
  foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
    foreach (explode("\n", (string)@file_get_contents($f)) as $line) {
      if ($line === '') continue;
      $r = json_decode($line, true);
      if (!is_array($r) || !isset($r['h'])) continue;
      $day = date('Y-m-d', $r['h'] * 3600);
      if (!isset($buckets[$day])) {
        $buckets[$day] = ['day' => $day, 'n' => 0, 'cpu' => 0, 'mem' => 0, 'cpu_max' => null,
                          'mem_max' => null, 'temp' => null, 'rx' => 0, 'tx' => 0, 'fill' => null,
                          'gpu' => null, 'smart' => [], 'single_sample_hours' => 0,
                          'docker_img_pct' => null, 'docker_img_pct_h' => -1];
      }
      $b = &$buckets[$day];
      $b['n']++;
      // Plan 106 P13-02 — rows written before the real-aggregate rollup had no
      // 'n' (hour-sample-count) field at all; flag them in the daily view so a
      // chart can distinguish "one snapshot stood in for the whole hour" from
      // a real hourly average, without discarding the old data.
      if (!array_key_exists('n', $r)) $b['single_sample_hours']++;
      if (($r['cpu_avg'] ?? null) !== null) $b['cpu'] += $r['cpu_avg'];
      if (($r['mem_avg'] ?? null) !== null) $b['mem'] += $r['mem_avg'];
      // 'cpu_max'/'mem_max' only exist on rows written by the real-aggregate
      // rollup; fall back to the hour's avg for legacy single-sample rows so
      // the daily max is never lower than the daily avg.
      $hourCpuMax = $r['cpu_max'] ?? $r['cpu_avg'] ?? null;
      $hourMemMax = $r['mem_max'] ?? $r['mem_avg'] ?? null;
      if ($hourCpuMax !== null) $b['cpu_max'] = max($b['cpu_max'] ?? 0, $hourCpuMax);
      if ($hourMemMax !== null) $b['mem_max'] = max($b['mem_max'] ?? 0, $hourMemMax);
      if (($r['temp_max'] ?? null) !== null) $b['temp'] = max($b['temp'] ?? 0, $r['temp_max']);
      if (($r['fill_max'] ?? null) !== null) $b['fill'] = max($b['fill'] ?? 0, $r['fill_max']);
      // Keep the latest hour's reading within the day (not max) — this is a
      // slow-moving gauge, not a spike metric, and growth-rate math wants the
      // end-of-day value, not the day's peak.
      if (($r['docker_img_pct'] ?? null) !== null && $r['h'] > $b['docker_img_pct_h']) {
        $b['docker_img_pct'] = $r['docker_img_pct'];
        $b['docker_img_pct_h'] = $r['h'];
      }
      // Legacy rows stored a single 'gpu' reading; current rows store 'gpu_max'.
      $hourGpuMax = $r['gpu_max'] ?? $r['gpu'] ?? null;
      if ($hourGpuMax !== null) $b['gpu'] = max($b['gpu'] ?? 0, $hourGpuMax);
      $b['rx'] += (float)($r['net_rx'] ?? 0);
      $b['tx'] += (float)($r['net_tx'] ?? 0);
      foreach ((array)($r['smart'] ?? []) as $name => $s) {
        if (!is_array($s)) continue;
        $cur = $b['smart'][$name] ?? ['temp' => null, 'reallocated' => null, 'pending' => null];
        if (($s['temp'] ?? null) !== null)       $cur['temp'] = max($cur['temp'] ?? 0, $s['temp']);
        if (($s['reallocated'] ?? null) !== null) $cur['reallocated'] = max($cur['reallocated'] ?? 0, $s['reallocated']);
        if (($s['pending'] ?? null) !== null)     $cur['pending'] = max($cur['pending'] ?? 0, $s['pending']);
        $b['smart'][$name] = $cur;
      }
      unset($b);
    }
  }
  ksort($buckets);
  $out = [];
  foreach (array_slice($buckets, -$days) as $b) {
    $out[] = [
      'day' => $b['day'], 'samples' => $b['n'],
      'cpu' => $b['n'] ? round($b['cpu'] / $b['n'], 1) : null,
      'cpu_max' => $b['cpu_max'],
      'mem' => $b['n'] ? round($b['mem'] / $b['n'], 1) : null,
      'mem_max' => $b['mem_max'],
      'temp_max' => $b['temp'], 'fill_max' => $b['fill'], 'gpu_max' => $b['gpu'],
      'net_rx' => round($b['rx'], 0), 'net_tx' => round($b['tx'], 0),
      'smart' => $b['smart'],
      'docker_img_pct' => $b['docker_img_pct'],
      // A day is only fully "single-sample" if every hour in it predates the
      // real-aggregate rollup — a mixed day (upgraded mid-day) is not flagged,
      // since most of its hours already carry a real average.
      'single_sample' => $b['n'] > 0 && $b['single_sample_hours'] === $b['n'],
    ];
  }
  return $out;
}

/* ------------------------------------------------------------------ alerts */

/**
 * Raise Unraid-native notifications on threshold breaches.
 *
 * Uses Unraid's own notify script so alerts land in the same place as every
 * other system warning (bell icon, notification settings, email/agent if the
 * user configured them). Each alert key is edge-triggered and suppressed for
 * an hour, so a disk sitting at 56 °C does not spam the notification centre.
 */
function v_check_alerts(array $snap): array {
  $th = v_thresholds();
  $state = v_read_json(v_alert_path());
  $now = time();
  $fired = [];

  $raise = function (string $key, string $subject, string $desc, string $importance) use (&$state, $now, &$fired) {
    $last = (int)($state[$key] ?? 0);
    if ($now - $last < 3600) return;               // suppress repeat for 1h
    $state[$key] = $now;
    $fired[] = ['key' => $key, 'subject' => $subject];
    $notify = '/usr/local/emhttp/webGui/scripts/notify';
    if (is_executable($notify)) {
      @shell_exec(sprintf(
        '%s -e %s -s %s -d %s -i %s -l %s 2>/dev/null',
        escapeshellarg($notify), escapeshellarg('unraid-vitals'),
        escapeshellarg($subject), escapeshellarg($desc),
        escapeshellarg($importance), escapeshellarg('/Vitals')
      ));
    }
  };

  // Disk temperature
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['parity'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    $key = 'temp_' . $d['name'];
    if ($d['temp'] !== null && $d['temp'] >= $th['temp']) {
      $raise($key, 'Vitals: ' . $d['name'] . ' at ' . $d['temp'] . '°C',
        'Disk ' . $d['name'] . ' (' . $d['device'] . ') is at ' . $d['temp'] . '°C, above the ' . $th['temp'] . '°C threshold.', 'warning');
      v_event_raise('temp', $d['name'], $key, 'warning',
        $d['name'] . ' at ' . $d['temp'] . '°C (threshold ' . $th['temp'] . '°C)',
        ['value' => $d['temp'], 'threshold' => $th['temp'], 'device' => $d['device']]);
    } else {
      v_event_resolve($key);
    }
  }

  // Array fill
  $fill = v_fill_max($snap);
  if ($fill !== null && $fill >= $th['fill']) {
    $raise('fill', 'Vitals: array ' . $fill . '% full',
      'The fullest data disk is ' . $fill . '% full, above the ' . $th['fill'] . '% threshold.', 'warning');
    v_event_raise('fill', 'array', 'fill', 'warning',
      'Array ' . $fill . '% full (threshold ' . $th['fill'] . '%)', ['value' => $fill, 'threshold' => $th['fill']]);
  } else {
    v_event_resolve('fill');
  }

  // Load average (opt-in: only when a limit is configured)
  if ($th['load'] > 0 && (float)($snap['load']['l1'] ?? 0) >= $th['load']) {
    $raise('load', 'Vitals: load ' . $snap['load']['l1'],
      '1-minute load average is ' . $snap['load']['l1'] . ', above the configured limit of ' . $th['load'] . '.', 'warning');
    v_event_raise('load', 'system', 'load', 'warning',
      'Load average ' . $snap['load']['l1'] . ' (limit ' . $th['load'] . ')',
      ['value' => $snap['load']['l1'], 'threshold' => $th['load']]);
  } else {
    v_event_resolve('load');
  }

  // SMART sector counters (P14-07): alert on GROWTH over the tracked
  // window, not on a standing lifetime value that never changes — a disk
  // with 8 reallocated sectors from years ago that hasn't grown in 30 days
  // is informational, not an hourly nag. growth_30d comes from
  // v_smart_tracked()'s day-bucketed history (collect.php); when there
  // isn't yet a second day of history (growth_30d.days === null), fall
  // back to reporting the standing value as 'info' only — first-run/fresh-
  // install behaviour, never louder than that until real growth is proven.
  foreach ($snap['smart'] ?? [] as $s) {
    $growth = $s['growth_30d'] ?? ['days' => null];
    foreach (['reallocated' => 'reallocated sectors', 'pending' => 'pending sectors'] as $k => $label) {
      $key = 'smart_' . $k . '_' . $s['name'];
      $value = $s[$k] ?? 0;
      $grew = $growth[$k] ?? null;
      if ($value > 0 && $grew !== null && $grew > 0) {
        $raise($key, 'Vitals: ' . $s['name'] . ' ' . $label . ' growing',
          'Disk ' . $s['name'] . ' reports ' . $value . ' ' . $label . ', up ' . $grew
          . ' in the last ' . $growth['days'] . ' days. Active growth on a sector counter is worth investigating now.', 'alert');
        v_event_raise('smart', $s['name'], $key, 'alert',
          $s['name'] . ' ' . $label . ' grew by ' . $grew . ' in ' . $growth['days'] . 'd',
          ['value' => $value, 'grew' => $grew, 'days' => $growth['days'], 'counter' => $k]);
      } elseif ($value > 0 && $grew === 0) {
        // Confirmed unchanged over the tracked window — exactly the
        // ticket's acceptance case: info, not an hourly alert.
        v_event_raise('smart', $s['name'], $key, 'info',
          $s['name'] . ' has ' . $value . ' ' . $label . ', unchanged in ' . $growth['days'] . 'd',
          ['value' => $value, 'grew' => 0, 'days' => $growth['days'], 'counter' => $k]);
        v_event_resolve($key);
      } elseif ($value > 0) {
        // No growth history yet (first day) — informational only, never
        // an hourly alert on a value we haven't watched long enough to
        // judge.
        v_event_raise('smart', $s['name'], $key, 'info',
          $s['name'] . ' has ' . $value . ' ' . $label . ' (watching for growth)',
          ['value' => $value, 'counter' => $k]);
        v_event_resolve($key);
      } else {
        v_event_resolve($key);
      }
    }
  }

  // hwmon sensor temps — only when the chip itself exposes a threshold
  // (temp_max/temp_crit). Using the vendor threshold rather than the global
  // ALERT_TEMP avoids false alarms on sensors that legitimately run hot.
  foreach ($snap['sensors']['temps'] ?? [] as $t) {
    if ($t['value'] === null) continue;
    foreach ([['crit', 'alert'], ['max', 'warning']] as [$k, $imp]) {
      $key = 'sensor_' . $k . '_' . $t['id'];
      if ($t[$k] !== null && $t[$k] > 0 && $t['value'] >= $t[$k]) {
        $breached = true;
        $raise($key,
          'Vitals: ' . $t['label'] . ' at ' . $t['value'] . '°C (' . $k . ')',
          'Sensor ' . $t['label'] . ' on ' . $t['chip'] . ' is at ' . $t['value'] .
          '°C, at or above the chip ' . $k . ' threshold of ' . $t[$k] . '°C.', $imp);
        v_event_raise('temp', $t['id'], $key, $imp,
          $t['label'] . ' at ' . $t['value'] . '°C (' . $k . ' ' . $t[$k] . '°C)',
          ['value' => $t['value'], 'threshold' => $t[$k], 'chip' => $t['chip']]);
      } else {
        v_event_resolve($key);
      }
    }
  }

  // Fan stalls — a fan that reported RPM before and now reads 0 usually means
  // a dead/failing fan or a severed cable, not a deliberate stop.
  foreach ($snap['sensors']['fans'] ?? [] as $f) {
    $key = 'fan_stall_' . $f['id'];
    if ($f['rpm'] > 0) { unset($state[$key]); v_event_resolve($key); continue; }
    if (empty($state['fan_seen_' . $f['id']])) continue;   // never spun: header may be unused
    $raise($key, 'Vitals: fan stalled — ' . $f['label'],
      'Fan ' . $f['label'] . ' on ' . $f['chip'] . ' reported ' . $f['rpm'] .
      ' RPM (was spinning earlier). Check the fan and its header/cable.', 'alert');
    v_event_raise('fan_stall', $f['id'], $key, 'alert',
      'Fan ' . $f['label'] . ' stalled (was spinning)', ['chip' => $f['chip']]);
  }
  foreach ($snap['sensors']['fans'] ?? [] as $f) {
    if ($f['rpm'] > 0) $state['fan_seen_' . $f['id']] = 1;
  }

  // Containers that were running and have stopped. A container that is simply
  // switched off in the UI is not a problem, so only containers we have seen
  // running in a previous sample are eligible — otherwise every disabled
  // container would alert forever.
  if ($th['restarts'] > 0) {
    $runningNow = [];
    foreach ($snap['docker']['containers'] ?? [] as $c) {
      $name = $c['name'];
      $key = 'ctr_' . $name;
      if (($c['state'] ?? '') === 'running') {
        $runningNow[$name] = true;
        unset($state[$key], $state['ctr_alert_' . $name]);
        v_event_resolve('ctr_alert_' . $name);
        continue;
      }
      // Only count a stop if this container was running in the last sample.
      if (empty($state['ctr_seen_' . $name])) continue;
      $state[$key] = (int)($state[$key] ?? 0) + 1;
      if ($state[$key] >= $th['restarts']) {
        $raise('ctr_alert_' . $name, 'Vitals: ' . $name . ' not running',
          'Container ' . $name . ' (' . $c['image'] . ') was running and has been down for ' . $state[$key] . ' consecutive samples.', 'alert');
        v_event_raise('container', $name, 'ctr_alert_' . $name, 'alert',
          $name . ' down for ' . $state[$key] . ' samples', ['image' => $c['image']]);
      }
    }
    // Remember what we saw, so the next sample can tell "stopped" from "never started".
    $seen = [];
    foreach ($snap['docker']['containers'] ?? [] as $c) if (($c['state'] ?? '') === 'running') $seen['ctr_seen_' . $c['name']] = 1;
    foreach ($state as $k => $v) if (strpos($k, 'ctr_seen_') === 0) unset($state[$k]);
    $state = array_merge($state, $seen);
  }

  v_write_json(v_alert_path(), $state);
  return $fired;
}

/**
 * Raise Unraid-native notifications for error/critical AI findings.
 *
 * Every agent run deletes and re-inserts its findings, so row ids change each
 * hour and the model rewords titles between runs — neither identifies a
 * finding. The key is what the finding is about (agent + subject + severity):
 * a standing problem notifies once, again after VITALS_AI_RENOTIFY if it is
 * still there, and immediately if its severity changes.
 */
function v_events_db(): ?SQLite3 {
  if (!class_exists('SQLite3')) return null;
  $dbFile = v_db_path();
  if ($dbFile === '') return null;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(2000);
    // Mirrors agent/lib/db.mjs's kb_events/kb_lessons/kb_solutions schema;
    // either side may create these first depending on which process starts
    // up first (the collector cron runs every minute, agents run hourly).
    $db->exec("CREATE TABLE IF NOT EXISTS kb_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kind TEXT NOT NULL, entity TEXT NOT NULL, alert_key TEXT,
      severity TEXT NOT NULL CHECK (severity IN ('info','warning','alert','critical')),
      status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','resolved','superseded')),
      summary TEXT NOT NULL, evidence TEXT, source TEXT, source_ref TEXT,
      started_at INTEGER NOT NULL, resolved_at INTEGER)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_status ON kb_events(status, started_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_key ON kb_events(alert_key)");
    $db->exec("CREATE TABLE IF NOT EXISTS kb_lessons (
      id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, entity TEXT NOT NULL,
      lesson TEXT NOT NULL, confidence REAL NOT NULL DEFAULT 0.5,
      times_seen INTEGER NOT NULL DEFAULT 1, first_seen_at INTEGER NOT NULL,
      last_seen_at INTEGER NOT NULL, merged_from TEXT)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lessons_kind_entity ON kb_lessons(kind, entity)");
    $db->exec("CREATE TABLE IF NOT EXISTS kb_solutions (
      id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, lesson_id INTEGER,
      detection TEXT, action_taken TEXT,
      outcome TEXT NOT NULL DEFAULT 'unknown' CHECK (outcome IN ('worked','did_not_work','unknown')),
      created_at INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_solutions_event ON kb_solutions(event_id)");
    return $db;
  } catch (Throwable $e) { return null; }
}

/** Open (or refresh) an event tied to an alert_key. Idempotent: a condition
 *  still breaching on every collector tick updates the same open row rather
 *  than spawning one event per minute. */
function v_event_raise(string $kind, string $entity, string $alertKey, string $severity, string $summary, array $evidence = []): void {
  $db = v_events_db();
  if (!$db) return;
  $now = time();
  $stmt = $db->prepare("SELECT id FROM kb_events WHERE alert_key = ? AND status = 'open' LIMIT 1");
  $stmt->bindValue(1, $alertKey, SQLITE3_TEXT);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if ($row) {
    $u = $db->prepare("UPDATE kb_events SET evidence = ?, summary = ? WHERE id = ?");
    $u->bindValue(1, json_encode($evidence), SQLITE3_TEXT);
    $u->bindValue(2, $summary, SQLITE3_TEXT);
    $u->bindValue(3, $row['id'], SQLITE3_INTEGER);
    $u->execute();
  } else {
    $i = $db->prepare("INSERT INTO kb_events (kind, entity, alert_key, severity, status, summary, evidence, source, started_at)
                        VALUES (?, ?, ?, ?, 'open', ?, ?, 'alert-engine', ?)");
    $i->bindValue(1, $kind, SQLITE3_TEXT);
    $i->bindValue(2, $entity, SQLITE3_TEXT);
    $i->bindValue(3, $alertKey, SQLITE3_TEXT);
    $i->bindValue(4, $severity, SQLITE3_TEXT);
    $i->bindValue(5, $summary, SQLITE3_TEXT);
    $i->bindValue(6, json_encode($evidence), SQLITE3_TEXT);
    $i->bindValue(7, $now, SQLITE3_INTEGER);
    $i->execute();
  }
  $db->close();
}

/** Close any open event for this alert_key — the metric normalized. */
function v_event_resolve(string $alertKey): void {
  $db = v_events_db();
  if (!$db) return;
  $stmt = $db->prepare("UPDATE kb_events SET status = 'resolved', resolved_at = ? WHERE alert_key = ? AND status = 'open'");
  $stmt->bindValue(1, time(), SQLITE3_INTEGER);
  $stmt->bindValue(2, $alertKey, SQLITE3_TEXT);
  $stmt->execute();
  $db->close();
}

function v_events_list(?string $status = null, int $limit = 100): array {
  $dbFile = v_db_path();
  if ($dbFile === '' || !is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $sql = "SELECT id, kind, entity, alert_key, severity, status, summary, source, started_at, resolved_at FROM kb_events";
  if ($status) $sql .= " WHERE status = '" . SQLite3::escapeString($status) . "'";
  $sql .= " ORDER BY started_at DESC LIMIT " . (int)$limit;
  $out = [];
  $res = $db->query($sql);
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Full event detail including evidence, plus any lesson/solution already
 *  distilled for it — for the Knowledge-tab event drill-down. */
function v_event_get(int $id): ?array {
  $db = v_events_db();  // ensures kb_lessons/kb_solutions exist even on first call
  if (!$db) return null;
  $stmt = $db->prepare("SELECT * FROM kb_events WHERE id = ?");
  $stmt->bindValue(1, $id, SQLITE3_INTEGER);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if (!$row) { $db->close(); return null; }
  $row['evidence'] = json_decode($row['evidence'] ?? '{}', true) ?: new stdClass();
  $sol = $db->prepare("SELECT * FROM kb_solutions WHERE event_id = ? ORDER BY id DESC LIMIT 1");
  $sol->bindValue(1, $id, SQLITE3_INTEGER);
  $solRow = $sol->execute()->fetchArray(SQLITE3_ASSOC);
  $row['solution'] = $solRow ?: null;
  if ($solRow && $solRow['lesson_id']) {
    $l = $db->prepare("SELECT * FROM kb_lessons WHERE id = ?");
    $l->bindValue(1, $solRow['lesson_id'], SQLITE3_INTEGER);
    $row['lesson'] = $l->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
  }
  $db->close();
  return $row;
}

function v_check_ai_findings(): void {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
  } catch (Throwable $e) { return; }

  $state = v_read_json(v_alert_path());
  $now = time();
  $notify = '/usr/local/emhttp/webGui/scripts/notify';
  $live = [];
  $res = $db->query("SELECT id, agent, severity, title, detail, subject FROM findings WHERE severity IN ('error','critical') ORDER BY id DESC LIMIT 50");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
    $key = 'ai_' . sha1($row['agent'] . '|' . strtolower(trim((string)($row['subject'] ?? ''))) . '|' . $row['severity']);
    if (isset($live[$key])) continue;
    $live[$key] = true;
    $last = (int)($state[$key] ?? 0);
    if ($now - $last < VITALS_AI_RENOTIFY) continue;
    $state[$key] = $now;
    if (is_executable($notify)) {
      @shell_exec(sprintf(
        '%s -e %s -s %s -d %s -i %s -l %s 2>/dev/null',
        escapeshellarg($notify), escapeshellarg('unraid-vitals AI · ' . ucfirst($row['agent'])),
        escapeshellarg($row['title']), escapeshellarg((string)($row['detail'] ?? '')),
        escapeshellarg($row['severity'] === 'critical' ? 'alert' : 'warning'),
        escapeshellarg('/Vitals')
      ));
    }
  }
  $db->close();

  // Keep the state file bounded: drop the old per-row-id keys, and any key
  // whose finding has been gone for a full window.
  foreach ($state as $k => $v) {
    if (strpos($k, 'ai_') !== 0) continue;
    if (strpos($k, 'ai_finding_') === 0 || (!isset($live[$k]) && $now - (int)$v >= VITALS_AI_RENOTIFY)) {
      unset($state[$k]);
    }
  }
  v_write_json(v_alert_path(), $state);
}

/** Bridge AI agent findings into the event store. Unlike metric alerts,
 *  findings have no clean "normalized" signal to auto-resolve on — they are
 *  the agent's point-in-time judgement — so they are created once (deduped
 *  by finding id via source_ref) and superseded when a fresh finding for the
 *  same agent+subject arrives with a low severity, meaning the agent no
 *  longer sees a problem there. */
function v_events_from_findings(): void {
  $dbFile = v_db_path();
  if ($dbFile === '' || !is_file($dbFile) || !class_exists('SQLite3')) return;
  try { $ro = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return; }
  $res = $ro->query("SELECT id, agent, severity, title, detail, recommendation, subject, created_at
                      FROM findings WHERE severity IN ('warning','error','critical') ORDER BY id DESC LIMIT 100");
  $rows = [];
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $row;
  $ro->close();
  if (!$rows) return;

  $db = v_events_db();
  if (!$db) return;
  foreach ($rows as $row) {
    $ref = (string)$row['id'];
    $exists = $db->querySingle("SELECT id FROM kb_events WHERE source = 'finding' AND source_ref = '" . SQLite3::escapeString($ref) . "'");
    if ($exists) continue;
    $sev = $row['severity'] === 'critical' ? 'critical' : ($row['severity'] === 'error' ? 'alert' : 'warning');
    $stmt = $db->prepare("INSERT INTO kb_events (kind, entity, severity, status, summary, evidence, source, source_ref, started_at)
                           VALUES ('finding', ?, ?, 'open', ?, ?, 'finding', ?, ?)");
    $stmt->bindValue(1, $row['agent'] . ':' . ($row['subject'] ?: 'general'), SQLITE3_TEXT);
    $stmt->bindValue(2, $sev, SQLITE3_TEXT);
    $stmt->bindValue(3, $row['title'], SQLITE3_TEXT);
    $stmt->bindValue(4, json_encode(['detail' => $row['detail'], 'recommendation' => $row['recommendation']]), SQLITE3_TEXT);
    $stmt->bindValue(5, $ref, SQLITE3_TEXT);
    $stmt->bindValue(6, (int)$row['created_at'], SQLITE3_INTEGER);
    $stmt->execute();
  }
  $db->close();
}

/** Read-only: latest findings from every agent, newest first. UI renders
 *  these as insight banners per tab; grouped by agent client-side. */
function v_ai_findings(int $limit = 200): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT id, agent, severity, title, detail, recommendation, subject, created_at
                      FROM findings ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Per-agent last-run status, so the UI can show "last checked 4m ago" /
 *  "agent X failed: <reason>" even when there are zero findings yet. */
function v_ai_runs(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT agent, MAX(started_at) AS started_at, status, error, finished_at
                      FROM runs GROUP BY agent ORDER BY agent");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Read-only: latest generated comment for a share, if any (written by
 *  agent/share-comment.mjs, triggered fire-and-forget from ajax.php). */
function v_share_comment(string $share): ?array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return null;
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return null; }
  $stmt = $db->prepare("SELECT comment, generated_at, status FROM share_comments WHERE share = ?");
  $stmt->bindValue(1, $share, SQLITE3_TEXT);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $db->close();
  return $row ?: null;
}

/* --------------------------------------------------------------------- KB */

function v_kb_search(string $query, int $limit = 20): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $words = array_filter(preg_split('/\s+/', preg_replace('/["*^]/', ' ', $query)));
  $safe = implode(' OR ', array_map(fn($w) => '"' . $w . '"', $words));
  if ($safe === '') { $db->close(); return []; }
  $out = [];
  try {
    $stmt = $db->prepare(
      "SELECT d.id, d.source, d.source_ref, d.topic, d.title, d.content, d.created_at, bm25(kb_fts) AS rank
       FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
       WHERE kb_fts MATCH :q ORDER BY rank LIMIT :lim");
    $stmt->bindValue(':q', $safe, SQLITE3_TEXT);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  } catch (Throwable $e) { /* malformed FTS query from odd input — return what we have */ }
  $db->close();
  return $out;
}

function v_kb_recent(int $limit = 50): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT id, source, source_ref, topic, title, content, created_at
                      FROM kb_documents ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

function v_kb_topics(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT topic, COUNT(*) AS n, MAX(created_at) AS last FROM kb_documents
                      WHERE topic IS NOT NULL GROUP BY topic ORDER BY last DESC");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/* --------------------------------------------------------------- research */

/** research_jobs is written from two sides: PHP creates the pending row,
 *  the detached node research.mjs process fills in the answer. Opened
 *  read-write only for the INSERT here; every other access is read-only. */
function v_research_create(string $prompt): ?int {
  $dbFile = v_db_path();
  if ($dbFile === '' || !class_exists('SQLite3')) return null;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->exec("CREATE TABLE IF NOT EXISTS research_jobs (
      id INTEGER PRIMARY KEY AUTOINCREMENT, prompt TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending', answer TEXT, sources TEXT, error TEXT,
      created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER)");
    $stmt = $db->prepare("INSERT INTO research_jobs (prompt, status, created_at) VALUES (:p, 'pending', :t)");
    $stmt->bindValue(':p', $prompt, SQLITE3_TEXT);
    $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
    $stmt->execute();
    $id = $db->lastInsertRowID();
    $db->close();
    return $id ?: null;
  } catch (Throwable $e) { return null; }
}

function v_research_get(int $id): ?array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return null;
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return null; }
  $stmt = $db->prepare("SELECT id, prompt, status, answer, sources, error, created_at, finished_at FROM research_jobs WHERE id = ?");
  $stmt->bindValue(1, $id, SQLITE3_INTEGER);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $db->close();
  if ($row && $row['sources']) $row['sources'] = json_decode($row['sources'], true);
  return $row ?: null;
}

function v_research_list(int $limit = 30): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT id, prompt, status, created_at, finished_at FROM research_jobs ORDER BY id DESC LIMIT " . (int)$limit);
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Read-only directory listing under /mnt/user/<share>[/<path>]. Every
 *  path segment is validated: the share must be a real, currently-known
 *  share (from v_shares()), and the resolved real path must stay inside
 *  that share's root — realpath() collapses any ../ before the check so
 *  this can't be tricked into escaping the share directory. */
function v_browse_share(string $share, string $rel): array {
  $shares = array_column(v_shares()['list'] ?? [], 'name');
  if ($share === '' || !in_array($share, $shares, true)) {
    return ['error' => 'unknown share', 'entries' => []];
  }
  $root = '/mnt/user/' . $share;
  $rootReal = realpath($root);
  if (!$rootReal) return ['error' => 'share path not found', 'entries' => []];

  $target = $rel === '' ? $rootReal : $rootReal . '/' . ltrim($rel, '/');
  $targetReal = realpath($target);
  if (!$targetReal || ($targetReal !== $rootReal && strpos($targetReal, $rootReal . '/') !== 0)) {
    return ['error' => 'invalid path', 'entries' => []];
  }
  if (!is_dir($targetReal)) return ['error' => 'not a directory', 'entries' => []];

  $entries = [];
  $items = @scandir($targetReal) ?: [];
  foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;
    $full = $targetReal . '/' . $name;
    $isDir = is_dir($full);
    $entries[] = [
      'name' => $name, 'dir' => $isDir,
      'size' => $isDir ? null : (@filesize($full) ?: 0),
      'mtime' => @filemtime($full) ?: 0,
    ];
    if (count($entries) >= 500) break; // sane cap for very large shares
  }
  usort($entries, function ($a, $b) {
    if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
  });
  $relOut = $targetReal === $rootReal ? '' : substr($targetReal, strlen($rootReal) + 1);
  return ['share' => $share, 'path' => $relOut, 'entries' => $entries];
}

/**
 * Run one collection cycle and persist everything.
 * Returns the snapshot.
 */
function v_tick(bool $full = true): array {
  $prev = v_read_json(v_state_dir() . '/prev.json');
  $elapsed = 60.0;
  if (isset($prev['time'])) $elapsed = max(1.0, (float)(time() - (int)$prev['time']));

  $snap = v_collect($prev ?: null, $elapsed);

  // keep raw counters for the next delta, but don't store them in the snapshot
  v_write_json(v_state_dir() . '/prev.json', ['time' => $snap['time'], '_raw' => $snap['_raw']]);
  $slim = $snap;
  unset($slim['_raw']);

  v_write_json(v_latest_path(), $slim);
  if ($full) {
    v_ring_append(v_point($slim));
    v_rollup(v_ring(), $slim);
    $slim['alerts'] = v_check_alerts($slim);
    v_check_ai_findings();
    v_events_from_findings();
  }
  return $slim;
}
