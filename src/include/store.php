<?php
/**
 * unraid-vitals — history store
 *
 * Two tiers:
 *   1. RAM ring buffer  /var/tmp/unraid-vitals/history.json   (24h @ 1/min)
 *   2. Flash rollups    /boot/config/plugins/unraid-vitals/history/YYYY-MM.jsonl
 *      one aggregate line per hour — tiny, low flash wear, survives reboot.
 */

require_once __DIR__ . '/collect.php';

if (!defined('VITALS_FLASH')) define('VITALS_FLASH', '/boot/config/plugins/unraid-vitals');

const VITALS_RING_MAX = 1440;   // 24 hours at one sample per minute

function v_latest_path(): string { return v_state_dir() . '/latest.json'; }
function v_ring_path():   string { return v_state_dir() . '/history.json'; }

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

/** The slim record kept in the ring buffer / used for charts. */
function v_point(array $snap): array {
  $temps = [];
  foreach ($snap['array']['data'] ?? [] as $d)   if ($d['temp'] !== null) $temps[] = $d['temp'];
  foreach ($snap['array']['parity'] ?? [] as $d) if ($d['temp'] !== null) $temps[] = $d['temp'];
  $netRx = $netTx = 0.0;
  foreach ($snap['net'] ?? [] as $n) { $netRx += $n['rx_rate']; $netTx += $n['tx_rate']; }
  $gpuUtil = null;
  foreach ($snap['gpu'] ?? [] as $g) if ($g['util'] !== null) $gpuUtil = max($gpuUtil ?? 0, $g['util']);
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
    'docker'   => $snap['docker']['running'] ?? null,
  ];
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

  $file = $dir . '/' . date('Y-m') . '.jsonl';
  $line = [
    'h'        => $hour,
    'cpu_avg'  => $snap['cpu']['total'] ?? null,
    'mem_avg'  => $snap['mem']['pct'] ?? null,
    'temp_max' => v_point($snap)['temp_max'],
    'net_rx'   => v_point($snap)['net_rx'],
    'net_tx'   => v_point($snap)['net_tx'],
    'gpu'      => v_point($snap)['gpu'],
    'fs_used'  => $snap['array']['totals']['fs_used'] ?? null,
  ];
  @file_put_contents($file, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  @file_put_contents($markerFile, (string)$hour);
}

/** Daily aggregates derived from the flash rollups (for longer-range charts). */
function v_daily(int $days = 30): array {
  $dir = VITALS_FLASH . '/history';
  if (!is_dir($dir)) return [];
  $buckets = [];
  foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
    foreach (explode("\n", (string)@file_get_contents($f)) as $line) {
      if ($line === '') continue;
      $r = json_decode($line, true);
      if (!is_array($r) || !isset($r['h'])) continue;
      $day = date('Y-m-d', $r['h'] * 3600);
      $cpu = $r['cpu_avg'] ?? null; $mem = $r['mem_avg'] ?? null;
      $tp  = $r['temp_max'] ?? null; $rx = $r['net_rx'] ?? 0; $tx = $r['net_tx'] ?? 0;
      if (!isset($buckets[$day])) {
        $buckets[$day] = ['day' => $day, 'n' => 0, 'cpu' => 0, 'mem' => 0, 'temp' => null, 'rx' => 0, 'tx' => 0];
      }
      $b = &$buckets[$day];
      $b['n']++;
      if ($cpu !== null) $b['cpu'] += $cpu;
      if ($mem !== null) $b['mem'] += $mem;
      if ($tp  !== null) $b['temp'] = max($b['temp'] ?? 0, $tp);
      $b['rx'] += $rx; $b['tx'] += $tx;
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
      'temp_max' => $b['temp'], 'net_rx' => round($b['rx'], 0), 'net_tx' => round($b['tx'], 0),
    ];
  }
  return $out;
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
  }
  return $slim;
}
