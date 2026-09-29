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
 */

require_once __DIR__ . '/collect.php';

if (!defined('VITALS_FLASH')) define('VITALS_FLASH', '/boot/config/plugins/unraid-vitals');
if (!defined('VITALS_RING_MAX')) define('VITALS_RING_MAX', 1440);   // 24h @ 1/min

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
    'docker'   => $snap['docker']['running'] ?? null,
    'net'      => $net,
    'ctr'      => $containers,
    'smart'    => $smart,
    'gpu_hist' => $gpu,
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
 * Append one hourly aggregate line to flash. Called by the collector; it only
 * actually writes when the hour rolls over, so flash sees ~24 writes/day.
 */
function v_rollup(array $snap): void {
  $hour = (int)floor(((int)$snap['time']) / 3600);
  $markerFile = v_state_dir() . '/last_rollup_hour';
  $last = (int)@file_get_contents($markerFile);
  if ($last === $hour) return;

  $dir = VITALS_FLASH . '/history';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  if (!is_dir($dir)) return;

  $p = v_point($snap);
  $line = [
    'h'        => $hour,
    'cpu_avg'  => $snap['cpu']['total'] ?? null,
    'mem_avg'  => $snap['mem']['pct'] ?? null,
    'temp_max' => $p['temp_max'],
    'net_rx'   => $p['net_rx'],
    'net_tx'   => $p['net_tx'],
    'gpu'      => $p['gpu'],
    'fs_used'  => $snap['array']['totals']['fs_used'] ?? null,
    'fill_max' => $p['fill_max'],
    // SMART counters are monotonic — keep the day's high-water mark so growth is
    // visible even when a single sample reads clean.
    'smart'    => $snap['smart'] ?? null,
  ];
  @file_put_contents($dir . '/' . date('Y-m', $hour * 3600) . '.jsonl',
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
        $buckets[$day] = ['day' => $day, 'n' => 0, 'cpu' => 0, 'mem' => 0, 'temp' => null,
                          'rx' => 0, 'tx' => 0, 'fill' => null, 'gpu' => null, 'smart' => []];
      }
      $b = &$buckets[$day];
      $b['n']++;
      if (($r['cpu_avg'] ?? null) !== null) $b['cpu'] += $r['cpu_avg'];
      if (($r['mem_avg'] ?? null) !== null) $b['mem'] += $r['mem_avg'];
      if (($r['temp_max'] ?? null) !== null) $b['temp'] = max($b['temp'] ?? 0, $r['temp_max']);
      if (($r['fill_max'] ?? null) !== null) $b['fill'] = max($b['fill'] ?? 0, $r['fill_max']);
      if (($r['gpu'] ?? null) !== null) $b['gpu'] = max($b['gpu'] ?? 0, $r['gpu']);
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
      'mem' => $b['n'] ? round($b['mem'] / $b['n'], 1) : null,
      'temp_max' => $b['temp'], 'fill_max' => $b['fill'], 'gpu_max' => $b['gpu'],
      'net_rx' => round($b['rx'], 0), 'net_tx' => round($b['tx'], 0),
      'smart' => $b['smart'],
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
    if ($d['temp'] !== null && $d['temp'] >= $th['temp']) {
      $raise('temp_' . $d['name'], 'Vitals: ' . $d['name'] . ' at ' . $d['temp'] . '°C',
        'Disk ' . $d['name'] . ' (' . $d['device'] . ') is at ' . $d['temp'] . '°C, above the ' . $th['temp'] . '°C threshold.', 'warning');
    }
  }

  // Array fill
  $fill = v_fill_max($snap);
  if ($fill !== null && $fill >= $th['fill']) {
    $raise('fill', 'Vitals: array ' . $fill . '% full',
      'The fullest data disk is ' . $fill . '% full, above the ' . $th['fill'] . '% threshold.', 'warning');
  }

  // Load average (opt-in: only when a limit is configured)
  if ($th['load'] > 0 && (float)($snap['load']['l1'] ?? 0) >= $th['load']) {
    $raise('load', 'Vitals: load ' . $snap['load']['l1'],
      '1-minute load average is ' . $snap['load']['l1'] . ', above the configured limit of ' . $th['load'] . '.', 'warning');
  }

  // SMART counters — any non-zero reallocated/pending is worth knowing about.
  foreach ($snap['smart'] ?? [] as $s) {
    foreach (['reallocated' => 'reallocated sectors', 'pending' => 'pending sectors'] as $k => $label) {
      if (($s[$k] ?? 0) > 0) {
        $raise('smart_' . $k . '_' . $s['name'], 'Vitals: ' . $s['name'] . ' has ' . $label,
          'Disk ' . $s['name'] . ' reports ' . $s[$k] . ' ' . $label . '. Total is a lifetime counter; watch for growth.', 'alert');
      }
    }
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
        continue;
      }
      // Only count a stop if this container was running in the last sample.
      if (empty($state['ctr_seen_' . $name])) continue;
      $state[$key] = (int)($state[$key] ?? 0) + 1;
      if ($state[$key] >= $th['restarts']) {
        $raise('ctr_alert_' . $name, 'Vitals: ' . $name . ' not running',
          'Container ' . $name . ' (' . $c['image'] . ') was running and has been down for ' . $state[$key] . ' consecutive samples.', 'alert');
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

function v_check_ai_findings(): void {
  $dbFile = v_state_dir() . '/vitals.db';
  if (!is_file($dbFile) || !class_exists('SQLite3')) return;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
  } catch (Throwable $e) { return; }

  $state = v_read_json(v_alert_path());
  $now = time();
  $notify = '/usr/local/emhttp/webGui/scripts/notify';
  $res = $db->query("SELECT id, agent, severity, title, detail, subject FROM findings WHERE severity IN ('error','critical') ORDER BY id DESC LIMIT 50");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
    $key = 'ai_finding_' . $row['id'];
    $last = (int)($state[$key] ?? 0);
    if ($now - $last < 3600) continue; // 1h suppression, same as the metric alerts
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
  v_write_json(v_alert_path(), $state);
}

/** Read-only: latest findings from every agent, newest first. UI renders
 *  these as insight banners per tab; grouped by agent client-side. */
function v_ai_findings(int $limit = 200): array {
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $words = array_filter(preg_split('/\s+/', preg_replace('/["*^]/', ' ', $query)));
  $safe = implode(' OR ', array_map(fn($w) => '"' . $w . '"', $words));
  if ($safe === '') { $db->close(); return []; }
  $out = [];
  try {
    $stmt = $db->prepare(
      "SELECT kb_documents.id, source, source_ref, topic, title, content, created_at, bm25(kb_fts) AS rank
       FROM kb_fts JOIN kb_documents ON kb_documents.id = kb_fts.rowid
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
  if (!class_exists('SQLite3')) return null;
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  $dbFile = v_state_dir() . '/vitals.db';
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
  if (!$targetReal || strpos($targetReal, $rootReal) !== 0) {
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
    v_rollup($slim);
    $slim['alerts'] = v_check_alerts($slim);
    v_check_ai_findings();
  }
  return $slim;
}
