<?php
/**
 * unraid-vitals — metric collection
 *
 * Reads Unraid's own state files (/var/local/emhttp/*.ini), /proc, the Docker
 * API, nvidia-smi and cached SMART output. No external dependencies, no
 * InfluxDB/Grafana/Telegraf required.
 */

if (!defined('VITALS_PLUGDIR')) define('VITALS_PLUGDIR', '/usr/local/emhttp/plugins/unraid-vitals');
if (!defined('VITALS_STATE'))    define('VITALS_STATE',   '/var/tmp/unraid-vitals');

/* ------------------------------------------------------------------ helpers */

function v_state_dir(): string {
  if (!is_dir(VITALS_STATE)) @mkdir(VITALS_STATE, 0755, true);
  return VITALS_STATE;
}

/** parse_ini_file with quote-stripped keys; Unraid writes ["disk1"] sections. */
function v_ini(string $file, bool $sections = true): array {
  if (!is_file($file)) return [];
  $raw = @parse_ini_file($file, $sections, INI_SCANNER_RAW);
  if (!is_array($raw)) return [];
  $out = [];
  foreach ($raw as $k => $v) {
    $k = trim((string)$k, '"');
    if (is_array($v)) {
      $sub = [];
      foreach ($v as $sk => $sv) $sub[trim((string)$sk, '"')] = $sv;
      $out[$k] = $sub;
    } else {
      $out[$k] = $v;
    }
  }
  return $out;
}

function v_run(string $cmd, int $timeout = 8): string {
  $out = @shell_exec('timeout ' . $timeout . ' ' . $cmd . ' 2>/dev/null');
  return is_string($out) ? trim($out) : '';
}

function v_bytes(float $n, int $p = 1): string {
  $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
  return round($n, $p) . ' ' . $u[$i];
}

/** "512.3MiB" / "1.5GiB" → bytes. Used for docker stats memory figures. */
function v_parse_size(?string $s): ?int {
  if ($s === null) return null;
  $s = trim($s);
  if (!preg_match('/^([0-9.]+)\s*([KMGTPE]?i?B)?$/i', $s, $m)) return null;
  $n = (float)$m[1];
  $unit = strtoupper(rtrim($m[2] ?? 'B', 'B'));
  $mult = ['K' => 1024, 'KI' => 1024, 'M' => 1048576, 'MI' => 1048576,
           'G' => 1073741824, 'GI' => 1073741824, 'T' => 1099511627776, 'TI' => 1099511627776][$unit] ?? 1;
  return (int)round($n * $mult);
}

/* ---------------------------------------------------------------------- CPU */

function v_cpu_stat(): array {
  $out = ['cores' => [], 'total' => ['total' => 0, 'idle' => 0]];
  $lines = @file('/proc/stat');
  if (!$lines) return $out;
  foreach ($lines as $line) {
    if (!preg_match('/^(cpu\d*)\s+(.*)$/', trim($line), $m)) continue;
    $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
    $idle  = ($v[3] ?? 0) + ($v[4] ?? 0);   // idle + iowait
    $total = array_sum($v);
    $out['cores'][$m[1]] = ['total' => $total, 'idle' => $idle];
  }
  if (isset($out['cores']['cpu'])) $out['total'] = $out['cores']['cpu'];
  return $out;
}

function v_cpu_pct(array $now, array $prev): array {
  $calc = function (array $a, array $b): ?float {
    $dt = $a['total'] - $b['total'];
    if ($dt <= 0) return null;
    $di = $a['idle'] - $b['idle'];
    return round(max(0, min(100, 100 * ($dt - $di) / $dt)), 1);
  };
  $total = $calc($now['total'], $prev['total']);
  $cores = [];
  foreach ($now['cores'] as $name => $c) {
    if ($name === 'cpu' || !isset($prev['cores'][$name])) continue;
    $cores[$name] = $calc($c, $prev['cores'][$name]);
  }
  return ['total' => $total, 'cores' => $cores];
}

function v_mem(): array {
  $m = @file('/proc/meminfo');
  $g = [];
  if ($m) foreach ($m as $l) {
    if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) $g[$x[1]] = (int)$x[2] * 1024;
  }
  $total = $g['MemTotal'] ?? 0;
  $avail = $g['MemAvailable'] ?? ($g['MemFree'] ?? 0);
  $used  = max(0, $total - $avail);
  $swapT = $g['SwapTotal'] ?? 0;
  $swapF = $g['SwapFree'] ?? 0;
  return [
    'total' => $total, 'used' => $used, 'free' => $g['MemFree'] ?? 0,
    'available' => $avail, 'buffers' => $g['Buffers'] ?? 0, 'cached' => $g['Cached'] ?? 0,
    'pct' => $total ? round(100 * $used / $total, 1) : 0,
    'swap_total' => $swapT, 'swap_used' => max(0, $swapT - $swapF),
    'swap_pct' => $swapT ? round(100 * ($swapT - $swapF) / $swapT, 1) : 0,
  ];
}

function v_load(): array {
  $l = @file_get_contents('/proc/loadavg') ?: '';
  $p = preg_split('/\s+/', trim($l));
  $cores = (int)v_run('nproc') ?: 1;
  return [
    'l1' => (float)($p[0] ?? 0), 'l5' => (float)($p[1] ?? 0), 'l15' => (float)($p[2] ?? 0),
    'cores' => $cores,
    'pct' => $cores ? round(100 * (float)($p[0] ?? 0) / $cores, 1) : 0,
  ];
}

function v_uptime(): int {
  $s = @file_get_contents('/proc/uptime') ?: '0';
  return (int)(float)explode(' ', trim($s))[0];
}

/* ------------------------------------------------------------------- system */

function v_system(): array {
  $var = v_ini('/var/local/emhttp/var.ini');
  $ver = v_ini('/etc/unraid-version');
  $cpu = '';
  foreach (@file('/proc/cpuinfo') ?: [] as $l) {
    if (stripos($l, 'model name') === 0) { $cpu = trim(substr($l, strpos($l, ':') + 1)); break; }
  }
  return [
    'name'    => $var['NAME'] ?? 'tower',
    'comment' => $var['COMMENT'] ?? '',
    'version' => $ver['version'] ?? ($var['version'] ?? '?'),
    'kernel'  => trim(@file_get_contents('/proc/sys/kernel/osrelease') ?: ''),
    'cpu'     => $cpu,
    'uptime'  => v_uptime(),
    'md_state'=> $var['mdState'] ?? '',
    'hostname'=> gethostname() ?: '',
    // sbClean="no" means the array was NOT unmounted cleanly (crash, power
    // loss, forced shutdown) — the standard Unraid webGUI shows an "Unclean
    // shutdown detected" banner in this state; expose it directly since
    // ArrayOperation.page's logic is buried behind an authenticated page.
    'unclean_shutdown' => ($var['sbClean'] ?? 'yes') !== 'yes',
  ];
}

/* -------------------------------------------------------------------- disks */

/** Reads/writes (in sectors, 512 bytes each) for one device from /proc/diskstats. */
function v_diskstats_for(string $device): ?array {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    $raw = @file_get_contents('/proc/diskstats') ?: '';
    foreach (explode("\n", $raw) as $line) {
      $f = preg_split('/\s+/', trim($line));
      if (count($f) < 14) continue;
      // major minor name reads-completed reads-merged sectors-read ms-reading
      // writes-completed writes-merged sectors-written ms-writing ...
      $cache[$f[2]] = ['reads' => (int)$f[5], 'writes' => (int)$f[9]];
    }
  }
  return $cache[$device] ?? null;
}

/**
 * Spin state / I/O history tracker (P14-08). One minute-resolution sample
 * per disk, rotated to the last 24 hours (1440 samples) -- enough to
 * answer "has this disk been spun up continuously for 24h" and, from the
 * diskstats deltas between samples, "what interval kept waking it up".
 *
 * Sampled every collection run (per-minute cron) -- this is cheap (just
 * appending to a small JSON array), unlike the docker/share checks that
 * need hourly caching for expensive shell-outs.
 */
function v_spin_track(array $disks): void {
  $path = v_state_dir() . '/spin_history.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) $hist = [];
  $now = time();
  foreach ($disks as $d) {
    if (empty($d['name'])) continue;
    $series = $hist[$d['name']] ?? [];
    $series[] = [
      't' => $now, 'spundown' => $d['spundown'] ? 1 : 0,
      'r' => $d['io_reads_sectors'] ?? null, 'w' => $d['io_writes_sectors'] ?? null,
    ];
    if (count($series) > 1440) $series = array_slice($series, -1440);
    $hist[$d['name']] = $series;
  }
  v_write_json($path, $hist);
}

/**
 * Per-disk spin-down analysis over the tracked window (up to 24h):
 * spun-up minutes, whether it's been continuously spun up for 24h, and
 * the I/O activity intervals observed while spun up (for the "what kept
 * it awake" finding).
 */
function v_spin_analysis(): array {
  $path = v_state_dir() . '/spin_history.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) return [];
  $out = [];
  foreach ($hist as $name => $series) {
    if (count($series) < 2) continue;
    $spanMin = ($series[count($series) - 1]['t'] - $series[0]['t']) / 60;
    $upSamples = array_filter($series, fn($s) => ($s['spundown'] ?? 0) === 0);
    $upMinutes = count($upSamples); // ~1 sample/min
    // Gaps between consecutive read/write activity while spun up, in
    // minutes -- this is "the interval that kept it awake".
    $activityGaps = [];
    $lastActiveT = null;
    $prevR = null; $prevW = null;
    foreach ($series as $s) {
      if (($s['spundown'] ?? 0) === 1) { $prevR = null; $prevW = null; continue; }
      $active = false;
      if ($prevR !== null && $s['r'] !== null && $s['r'] > $prevR) $active = true;
      if ($prevW !== null && $s['w'] !== null && $s['w'] > $prevW) $active = true;
      $prevR = $s['r']; $prevW = $s['w'];
      if ($active) {
        if ($lastActiveT !== null) $activityGaps[] = round(($s['t'] - $lastActiveT) / 60, 1);
        $lastActiveT = $s['t'];
      }
    }
    $out[$name] = [
      'span_minutes' => round($spanMin, 1),
      'spun_up_minutes' => $upMinutes,
      'continuously_up' => $upMinutes >= count($series) && $spanMin >= 1439, // full window, no spin-down seen
      // Median gap is more representative than mean when a scan tool wakes
      // the disk at a very regular interval but the occasional user access
      // adds noise -- sorting is cheap at this size (<=1440 entries).
      'median_activity_gap_min' => v_median($activityGaps),
      'activity_count' => count($activityGaps) + ($lastActiveT !== null ? 1 : 0),
    ];
  }
  return $out;
}

if (!function_exists('v_median')) {
  function v_median(array $vals): ?float {
    if (!$vals) return null;
    sort($vals);
    $n = count($vals);
    $mid = intdiv($n, 2);
    return $n % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
  }
}

function v_array_disks(): array {
  $disks = v_ini('/var/local/emhttp/disks.ini');
  $out = ['parity' => [], 'data' => [], 'cache' => [], 'totals' => []];
  $sizeT = $fsT = $fsU = 0;
  foreach ($disks as $d) {
    if (!is_array($d) || empty($d['name'])) continue;
    $e = [
      'name'   => $d['name'],
      'device' => $d['device'] ?? '',
      'id'     => $d['id'] ?? '',
      'type'   => $d['type'] ?? '',
      'status' => $d['status'] ?? '',
      'temp'   => isset($d['temp']) && $d['temp'] !== '*' ? (int)$d['temp'] : null,
      'size'   => (int)($d['size'] ?? 0) * 1024,
      'fsSize' => (int)($d['fsSize'] ?? 0) * 1024,
      'fsUsed' => (int)($d['fsUsed'] ?? 0) * 1024,
      'fsFree' => (int)($d['fsFree'] ?? 0) * 1024,
      'rotational' => ($d['rotational'] ?? '0') === '1',
      'numReads'  => (int)($d['numReads'] ?? 0),
      'numWrites' => (int)($d['numWrites'] ?? 0),
      'numErrors' => (int)($d['numErrors'] ?? 0),
      'spundown'  => ($d['spundown'] ?? '0') === '1',
      'color'  => $d['color'] ?? '',
    ];
    $e['usedPct'] = $e['fsSize'] > 0 ? round(100 * $e['fsUsed'] / $e['fsSize'], 1) : 0;
    // /proc/diskstats sector counters (P14-08) -- reads[2] and
    // writes[6] fields (0-indexed within the space-separated stat line),
    // used to detect what's keeping a disk from spinning down.
    if (!empty($e['device'])) {
      $ds = v_diskstats_for($e['device']);
      if ($ds !== null) { $e['io_reads_sectors'] = $ds['reads']; $e['io_writes_sectors'] = $ds['writes']; }
    }
    if ($e['type'] === 'Parity') {
      $out['parity'][] = $e;
    } elseif (stripos($e['type'], 'Cache') !== false || stripos($e['name'], 'cache') === 0) {
      $out['cache'][] = $e;
      $sizeT += $e['fsSize']; $fsU += $e['fsUsed']; $fsT += $e['fsSize'];
    } else {
      $out['data'][] = $e;
      $sizeT += $e['size'];
      $fsT += $e['fsSize']; $fsU += $e['fsUsed'];
    }
  }
  $out['totals'] = [
    'raw'       => $sizeT,
    'fs_size'   => $fsT,
    'fs_used'   => $fsU,
    'fs_free'   => max(0, $fsT - $fsU),
    'used_pct'  => $fsT ? round(100 * $fsU / $fsT, 1) : 0,
    'data_disks'=> count($out['data']),
    'parity_disks' => count($out['parity']),
    'cache_disks'  => count($out['cache']),
  ];
  return $out;
}

/* SMART. Unraid caches smartctl output in /var/local/emhttp/smart/<dev>. */
/**
 * Extract the RAW_VALUE from a `smartctl -A` attribute line.
 *
 * The columns are: ID NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE
 * VALUE/WORST are the *normalized* numbers (200, 100 ...) — the failure counters
 * live in the trailing RAW_VALUE column, which may itself contain spaces
 * ("33 (Min/Max 20/45)"). Passing only the text after the attribute name lets
 * this skip the seven fixed columns and read the raw value, so a healthy disk
 * with VALUE=200/RAW=0 is never reported as having 200 pending sectors.
 */
function v_ata_raw(string $rest): ?int {
  $parts = preg_split('/\s+/', trim($rest));
  if (!is_array($parts) || count($parts) < 8) return null;
  $rawval = implode(' ', array_slice($parts, 7));
  return preg_match('/(\d+)/', $rawval, $m) ? (int)$m[1] : null;
}

function v_smart(): array {
  $dir = '/var/local/emhttp/smart';
  $disks = v_ini('/var/local/emhttp/disks.ini');
  $devmap = [];
  foreach ($disks as $d) if (is_array($d) && !empty($d['device'])) $devmap[$d['device']] = $d['name'] ?? $d['device'];
  $out = [];
  foreach (glob($dir . '/*') ?: [] as $f) {
    $dev = basename($f);
    if ($dev === 'cache' || !is_file($f)) continue;
    $raw = @file_get_contents($f);
    if (!$raw) continue;
    $r = [
      'dev' => $dev, 'name' => $devmap[$dev] ?? $dev,
      'health' => null, 'temp' => null, 'hours' => null,
      'reallocated' => null, 'pending' => null, 'uncorrectable' => null, 'crc' => null,
      'spin_retry' => null,
      // NVMe-specific — null on ATA/SATA disks (the fields don't exist there).
      'nvme_pct_used' => null, 'nvme_spare_pct' => null, 'nvme_spare_threshold' => null,
      'nvme_media_errors' => null, 'nvme_critical_warning' => null,
      // SSD wear (SATA SSDs use different vendor attribute IDs for this —
      // 177/233 are the two most common; null when neither is present,
      // e.g. on a spinning disk or a vendor using a different ID).
      'ssd_wear_pct' => null,
    ];
    if (preg_match('/SMART overall-health self-assessment test result:\s*(\S+)/i', $raw, $m)) {
      $r['health'] = strtoupper(trim($m[1], '.'));
    }
    if (preg_match('/^\s*194\s+Temperature_Celsius\s+(.*)$/mi', $raw, $m))       $r['temp'] = v_ata_raw($m[1]);
    elseif (preg_match('/Temperature:\s+(\d+)\s+Celsius/i', $raw, $m))            $r['temp'] = (int)$m[1];
    if (preg_match('/^\s*9\s+Power_On_Hours\s+(.*)$/mi', $raw, $m))               $r['hours'] = v_ata_raw($m[1]);
    elseif (preg_match('/Power On Hours:\s+([\d,]+)/i', $raw, $m))                $r['hours'] = (int)str_replace(',', '', $m[1]);
    if (preg_match('/^\s*5\s+Reallocated_Sector_Ct\s+(.*)$/mi', $raw, $m))        $r['reallocated'] = v_ata_raw($m[1]);
    if (preg_match('/^\s*197\s+Current_Pending_Sector\s+(.*)$/mi', $raw, $m))     $r['pending'] = v_ata_raw($m[1]);
    if (preg_match('/^\s*198\s+Offline_Uncorrectable\s+(.*)$/mi', $raw, $m))      $r['uncorrectable'] = v_ata_raw($m[1]);
    if (preg_match('/^\s*199\s+UDMA_CRC_Error_Count\s+(.*)$/mi', $raw, $m))       $r['crc'] = v_ata_raw($m[1]);
    if (preg_match('/^\s*10\s+Spin_Retry_Count\s+(.*)$/mi', $raw, $m))            $r['spin_retry'] = v_ata_raw($m[1]);
    // SSD wear: attribute 177 (Wear_Leveling_Count) or 233 (Media_Wearout_Indicator)
    // both encode remaining life as the "current" (normalized) column, not
    // the raw column — 100 = fresh, decreasing toward 0.
    if (preg_match('/^\s*177\s+Wear_Leveling_Count\s+0x[0-9a-f]+\s+(\d+)/mi', $raw, $m))      $r['ssd_wear_pct'] = 100 - (int)$m[1];
    elseif (preg_match('/^\s*233\s+Media_Wearout_Indicator\s+0x[0-9a-f]+\s+(\d+)/mi', $raw, $m)) $r['ssd_wear_pct'] = 100 - (int)$m[1];
    // NVMe fields — smartctl's NVMe log format is fixed-width text, not the
    // ATA attribute-table format above.
    if (preg_match('/Percentage Used:\s+(\d+)%/i', $raw, $m))            $r['nvme_pct_used'] = (int)$m[1];
    if (preg_match('/Available Spare:\s+(\d+)%/i', $raw, $m))            $r['nvme_spare_pct'] = (int)$m[1];
    if (preg_match('/Available Spare Threshold:\s+(\d+)%/i', $raw, $m))  $r['nvme_spare_threshold'] = (int)$m[1];
    if (preg_match('/Media and Data Integrity Errors:\s+([\d,]+)/i', $raw, $m)) $r['nvme_media_errors'] = (int)str_replace(',', '', $m[1]);
    if (preg_match('/Critical Warning:\s+0x([0-9a-f]+)/i', $raw, $m))    $r['nvme_critical_warning'] = hexdec($m[1]);
    $out[$dev] = $r;
  }
  ksort($out);
  return $out;
}

/**
 * v_smart() plus per-disk day-over-day snapshots of the sector/CRC counters
 * (state-dir JSON, one entry per disk, rotated to the last 30 days),
 * used to compute genuine growth instead of a standing lifetime value —
 * the whole point of P14-07 ("a disk with 8 reallocated sectors that has
 * not changed in 30 days produces an info finding, not an hourly alert").
 *
 * Sampled at most once per real calendar day (not per-minute) — the
 * counters themselves only change occasionally, and a day-bucketed history
 * is exactly what "how much did this grow in N days" needs.
 */
function v_smart_tracked(): array {
  $current = v_smart();
  $path = v_state_dir() . '/smart_history.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) $hist = [];
  $today = date('Y-m-d');

  foreach ($current as $dev => $r) {
    $series = $hist[$dev] ?? [];
    // Already sampled today — don't overwrite today's bucket, but still
    // attach the growth computed from history so every collection run
    // (not just the first one of the day) reports it.
    if (!isset($series[$today])) {
      $series[$today] = [
        'reallocated' => $r['reallocated'], 'pending' => $r['pending'],
        'crc' => $r['crc'], 'uncorrectable' => $r['uncorrectable'],
      ];
      // Keep the last 30 calendar days only.
      if (count($series) > 30) {
        uksort($series, 'strcmp');
        $series = array_slice($series, -30, null, true);
      }
      $hist[$dev] = $series;
    }
    $current[$dev]['growth_30d'] = v_smart_growth($series, $today);
  }

  v_write_json($path, $hist);
  return $current;
}

/**
 * Growth of each tracked counter from the OLDEST bucket present (up to 30
 * days back) to today's bucket. Null when there's only one day of history
 * yet (nothing to compare against) — that's what lets a long-standing
 * value report as unchanged rather than looking like infinite growth.
 */
function v_smart_growth(array $series, string $today): array {
  $dates = array_keys($series);
  sort($dates, SORT_STRING);
  $oldestDate = $dates[0] ?? null;
  $out = ['days' => null, 'reallocated' => null, 'pending' => null, 'crc' => null, 'uncorrectable' => null];
  if ($oldestDate === null || $oldestDate === $today || !isset($series[$today])) return $out;
  $oldest = $series[$oldestDate];
  $latest = $series[$today];
  $out['days'] = (int)((strtotime($today) - strtotime($oldestDate)) / 86400);
  foreach (['reallocated', 'pending', 'crc', 'uncorrectable'] as $k) {
    if (($oldest[$k] ?? null) !== null && ($latest[$k] ?? null) !== null) {
      $out[$k] = max(0, $latest[$k] - $oldest[$k]);
    }
  }
  return $out;
}

/* ----------------------------------------------------------------------- VMs */

function v_vms(): array {
  if (!is_executable('/usr/bin/virsh')) return ['available' => false, 'list' => []];
  $raw = v_run("virsh list --all --name", 8);
  $names = array_values(array_filter(array_map('trim', explode("\n", $raw))));
  $list = [];
  foreach ($names as $name) {
    $info = v_run("virsh dominfo " . escapeshellarg($name), 5);
    $row = ['name' => $name, 'state' => 'unknown', 'cpus' => null, 'mem_kib' => null,
            'autostart' => false, 'pinning' => null];
    foreach (explode("\n", $info) as $line) {
      if (!str_contains($line, ':')) continue;
      [$k, $v] = array_map('trim', explode(':', $line, 2));
      if ($k === 'State') $row['state'] = $v;
      elseif ($k === 'CPU(s)') $row['cpus'] = (int)$v;
      elseif ($k === 'Used memory') $row['mem_kib'] = (int)$v;
      elseif ($k === 'Autostart') $row['autostart'] = str_starts_with($v, 'enable');
    }
    // vcpupin only means something while the VM is actually running; a
    // stopped VM's affinity call is either empty or misleading.
    if ($row['state'] === 'running') {
      $pin = v_run('virsh vcpupin ' . escapeshellarg($name), 5);
      $rows = [];
      foreach (explode("\n", $pin) as $line) {
        if (preg_match('/^\s*(\d+)\s+(\S.*)$/', $line, $m)) $rows[] = ['vcpu' => (int)$m[1], 'affinity' => trim($m[2])];
      }
      if ($rows) $row['pinning'] = $rows;
    }
    $list[] = $row;
  }
  usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
  $running = count(array_filter($list, fn($r) => $r['state'] === 'running'));
  return ['available' => true, 'count' => count($list), 'running' => $running,
          'stopped' => count($list) - $running, 'list' => $list];
}

/** Physical CPU topology (sockets/cores/threads) — static hardware layout,
 *  paired with v_cpu_pct()'s live per-core load so the System tab can show
 *  which physical cores are hot, and cross-reference against VM pinning. */
function v_cpu_topology(): array {
  $out = v_run('lscpu -p=CPU,CORE,SOCKET 2>/dev/null', 5);
  $cpus = [];
  foreach (explode("\n", $out) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode(',', $line);
    if (count($p) < 3) continue;
    $cpus[] = ['cpu' => (int)$p[0], 'core' => (int)$p[1], 'socket' => (int)$p[2]];
  }
  $sockets = count(array_unique(array_column($cpus, 'socket')));
  $cores = count(array_unique(array_map(fn($c) => $c['socket'] . ':' . $c['core'], $cpus)));
  return ['threads' => count($cpus), 'cores' => $cores, 'sockets' => $sockets, 'map' => $cpus];
}

/* ------------------------------------------------------------------ sensors */

/**
 * Generic hwmon reader — temps, fans, and PWM duty for every chip the kernel
 * exposes (CPU, motherboard super-I/O, NVMe composite sensors, etc.).
 * Deliberately reads /sys/class/hwmon directly instead of shelling out to
 * `sensors` (lm-sensors) so this works on any Unraid box, sensors package
 * installed or not — this plugin is generic, not tuned to one server.
 *
 * Per hwmon convention: raw temp values are millidegrees C (divide by 1000);
 * pwm* is 0-255 (rescaled to a 0-100% duty); fan*_input is already RPM.
 * _max/_min/_crit files, where the driver exposes them, become chip-reported
 * thresholds so the UI can show "this vendor considers X the ceiling" instead
 * of only the user's own configured alert level.
 */
function v_sensors(): array {
  $base = '/sys/class/hwmon';
  $temps = []; $fans = []; $pwms = [];
  if (!is_dir($base)) return ['temps' => $temps, 'fans' => $fans, 'pwms' => $pwms];

  foreach (glob($base . '/hwmon*') ?: [] as $dir) {
    $chip = trim((string)@file_get_contents($dir . '/name')) ?: basename($dir);

    foreach (glob($dir . '/temp*_input') ?: [] as $f) {
      if (!preg_match('#/temp(\d+)_input$#', $f, $m)) continue;
      $n = $m[1];
      $raw = trim((string)@file_get_contents($f));
      if ($raw === '' || !is_numeric($raw)) continue;
      $val = (float)$raw / 1000;
      // Unrealistic readings: 127/-3C = classic disconnected-sensor sentinels,
      // anything outside -20..120C is not a real temperature. Threshold files
      // can hold driver garbage too (seen: 65261.85) — same sanity bound.
      if ($val < -20 || $val > 120) continue;
      $label = trim((string)@file_get_contents("$dir/temp{$n}_label")) ?: "temp$n";
      $max  = is_file("$dir/temp{$n}_max")  ? (float)file_get_contents("$dir/temp{$n}_max") / 1000 : null;
      $crit = is_file("$dir/temp{$n}_crit") ? (float)file_get_contents("$dir/temp{$n}_crit") / 1000 : null;
      if ($max !== null && ($max < -20 || $max > 120))  $max = null;
      if ($crit !== null && ($crit < -20 || $crit > 120)) $crit = null;
      $temps[] = [
        'id' => "$chip/temp$n", 'chip' => $chip, 'label' => $label,
        'value' => round($val, 1),
        'max' => $max, 'crit' => $crit,
      ];
    }

    foreach (glob($dir . '/fan*_input') ?: [] as $f) {
      if (!preg_match('#/fan(\d+)_input$#', $f, $m)) continue;
      $n = $m[1];
      $raw = trim((string)@file_get_contents($f));
      if ($raw === '' || !is_numeric($raw)) continue;
      $label = trim((string)@file_get_contents("$dir/fan{$n}_label")) ?: "$chip fan$n";
      $min = is_file("$dir/fan{$n}_min") ? (int)file_get_contents("$dir/fan{$n}_min") : null;
      $fans[] = [
        'id' => "$chip/fan$n", 'chip' => $chip, 'label' => $label,
        'rpm' => (int)$raw, 'min' => $min,
        // Stalled = reads 0. Whether that is meaningful (was spinning before)
        // is decided by the alert layer, which has history; the UI renders
        // '0 RPM' plainly without crying wolf on unused headers.
        'stalled' => (int)$raw === 0,
      ];
    }

    foreach (glob($dir . '/pwm[0-9]*') ?: [] as $f) {
      if (!preg_match('#/pwm(\d+)$#', $f, $m)) continue;
      $n = $m[1];
      $raw = trim((string)@file_get_contents($f));
      if ($raw === '' || !is_numeric($raw)) continue;
      $enable = is_file("$dir/pwm{$n}_enable") ? (int)file_get_contents("$dir/pwm{$n}_enable") : null;
      $pwms[] = [
        'id' => "$chip/pwm$n", 'chip' => $chip,
        'duty_pct' => round(((float)$raw / 255) * 100, 0),
        'mode' => $enable === 0 ? 'manual/full' : ($enable === 1 ? 'manual' : ($enable === 2 ? 'auto' : null)),
      ];
    }
  }

  usort($temps, fn($a, $b) => $b['value'] <=> $a['value']);
  usort($fans, fn($a, $b) => $b['rpm'] <=> $a['rpm']);
  return ['temps' => $temps, 'fans' => $fans, 'pwms' => $pwms];
}

/* --------------------------------------------------------------------- logs */

/**
 * Curated system-log reader for the UI's critical-logs drawer.
 *
 * Only whitelisted sources are readable — the source parameter is a key into
 * this list, never a file path from the client, so there is no traversal or
 * arbitrary-read surface. Returns the newest lines last, with a best-effort
 * syslog timestamp preserved per line when present.
 */
function v_log_sources(): array {
  return [
    'syslog' => ['label' => 'System (syslog)', 'file' => '/var/log/syslog'],
    // NB: no shell grouping here — v_run() prefixes `timeout N`, which cannot
    // wrap a ( subshell ). The iso→legacy fallback is handled in v_logs().
    'dmesg'  => ['label' => 'Kernel (dmesg)',  'cmd'  => 'dmesg --time-format iso 2>/dev/null'],
    'dmesg2' => ['label' => 'Kernel (dmesg)',  'cmd'  => 'dmesg 2>/dev/null'],
    'docker' => ['label' => 'Docker daemon',   'file' => '/var/log/docker.log'],
  ];
}

function v_logs(string $source, int $lines = 200): array {
  $sources = v_log_sources();
  $lines = max(10, min(1000, $lines));
  if (!isset($sources[$source])) return ['source' => $source, 'entries' => [], 'error' => 'unknown source'];

  $raw = '';
  if (isset($sources[$source]['file'])) {
    $f = $sources[$source]['file'];
    $raw = is_file($f) ? (string)shell_exec('tail -n ' . (int)$lines . ' ' . escapeshellarg($f) . ' 2>/dev/null') : '';
  } else {
    $raw = v_run($sources[$source]['cmd'] . ' 2>/dev/null | tail -n ' . (int)$lines, 8);
    // dmesg --time-format iso unsupported on old kernels → empty output, retry legacy.
    if ($raw === '' && isset($sources[$source . '2'])) {
      $raw = v_run($sources[$source . '2']['cmd'] . ' 2>/dev/null | tail -n ' . (int)$lines, 8);
    }
  }

  $entries = [];
  foreach (explode("\n", $raw) as $line) {
    if ($line === '') continue;
    // syslog style: "Sep 29 04:54:22 host proc[pid]: msg" — keep ts+rest.
    $ts = null;
    if (preg_match('/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/', $line, $m)) {
      $ts = $m[1]; $line = $m[2];
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\s+(.*)$/', $line, $m)) {
      $ts = $m[1]; $line = $m[2];
    }
    $entries[] = ['ts' => $ts, 'line' => $line];
  }
  return ['source' => $source, 'entries' => $entries];
}

/* -------------------------------------------------------------------- docker */

function v_docker(): array {
  $ps = v_run("docker ps -a --format '{{.Names}}\\t{{.State}}\\t{{.Status}}\\t{{.Image}}'", 10);
  $rows = [];
  if ($ps !== '') {
    foreach (explode("\n", $ps) as $line) {
      $c = explode("\t", $line);
      if (count($c) < 4) continue;
      $rows[$c[0]] = ['name' => $c[0], 'state' => $c[1], 'status' => $c[2], 'image' => $c[3],
                      'cpu' => null, 'mem' => null, 'mem_pct' => null];
    }
  }
  $stats = v_run("docker stats --no-stream --format '{{.Name}}\\t{{.CPUPerc}}\\t{{.MemUsage}}\\t{{.MemPerc}}'", 25);
  if ($stats !== '') {
    foreach (explode("\n", $stats) as $line) {
      $c = explode("\t", $line);
      if (count($c) < 4 || !isset($rows[$c[0]])) continue;
      $rows[$c[0]]['cpu'] = (float)rtrim($c[1], '%');
      $rows[$c[0]]['mem'] = $c[2];
      $rows[$c[0]]['mem_bytes'] = v_parse_size(strtok($c[2], '/'));
      $rows[$c[0]]['mem_pct'] = (float)rtrim($c[3], '%');
    }
  }
  $running = 0;
  foreach ($rows as $r) if ($r['state'] === 'running') $running++;
  usort($rows, fn($a, $b) => ($b['cpu'] ?? -1) <=> ($a['cpu'] ?? -1));
  return ['count' => count($rows), 'running' => $running,
          'stopped' => count($rows) - $running, 'containers' => array_values($rows)];
}

/**
 * docker.img usage — "Docker image is full" is one of the most common Unraid
 * problems, usually caused by a container writing data inside the image
 * instead of a mapped path. `df` on Docker's own root dir gives an accurate
 * total/used/free regardless of storage driver (btrfs loop image, overlay2
 * directory, etc.) without parsing `docker info`'s driver-specific output.
 */
function v_docker_root(): string {
  $root = v_run('docker info --format \'{{.DockerRootDir}}\'', 8);
  return $root !== '' ? $root : '/var/lib/docker';
}

function v_docker_image(): array {
  $root = v_docker_root();
  if (!is_dir($root)) return [];
  $out = v_run('df -B1 --output=size,used,avail ' . escapeshellarg($root) . ' | tail -1', 8);
  $c = preg_split('/\s+/', trim($out));
  if (count($c) < 3 || !is_numeric($c[0])) return [];
  $total = (float)$c[0]; $used = (float)$c[1]; $free = (float)$c[2];
  return [
    'root' => $root, 'total' => $total, 'used' => $used, 'free' => $free,
    'used_pct' => $total > 0 ? round($used / $total * 100, 1) : null,
  ];
}

/**
 * Per-container writable layer size via `docker ps -s` — deliberately NOT
 * called every minute: -s makes the daemon walk every container's diff
 * layer on disk, which is slow (seconds per container on a busy host).
 * Callers should sample this at most hourly (see v_docker_layers_cached()).
 */
function v_docker_layers(): array {
  $raw = v_run("docker ps -a -s --format '{{.Names}}\\t{{.Size}}'", 30);
  $out = [];
  if ($raw === '') return $out;
  foreach (explode("\n", $raw) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 2) continue;
    // "{{.Size}}" looks like "1.23MB (virtual 512MB)" or "0B (virtual 128MB)"
    // — the first number is the container's own writable layer, not counting
    // the shared read-only image layers below it.
    if (!preg_match('/^([\d.]+\s*[KMGT]?i?B)/', $c[1], $m)) continue;
    $out[$c[0]] = v_parse_size($m[1]);
  }
  return $out;
}

/**
 * v_docker_layers() sampled at most once an hour, cached in the state dir.
 * Returns the cached sample even if it is stale — a check can compare its
 * own timestamp against the cache's 'ts' to decide whether to trust it.
 */
function v_docker_layers_cached(): array {
  $path = v_state_dir() . '/docker_layers.json';
  $cache = v_read_json($path);
  if (($cache['ts'] ?? 0) > time() - 3600) return $cache;
  $layers = v_docker_layers();
  $cache = ['ts' => time(), 'layers' => $layers];
  v_write_json($path, $cache);
  return $cache;
}

/**
 * Size of each container's JSON log file (/var/lib/docker/containers/<id>/
 * <id>-json.log — the log driver Unraid uses by default). Sampled at most
 * hourly like v_docker_layers(): `docker inspect` per container adds up on
 * a host with many containers, and log growth is not a per-minute concern.
 */
function v_docker_logs(): array {
  $ps = v_run("docker ps -a --format '{{.Names}}\\t{{.ID}}'", 10);
  $out = [];
  if ($ps === '') return $out;
  foreach (explode("\n", $ps) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 2 || $c[1] === '') continue;
    $name = $c[0]; $id = $c[1];
    $logPath = v_run('docker inspect --format \'{{.LogPath}}\' ' . escapeshellarg($id), 8);
    if ($logPath === '' || !is_file($logPath)) continue;
    $size = @filesize($logPath);
    if ($size === false) continue;
    $out[$name] = ['path' => $logPath, 'bytes' => $size];
  }
  return $out;
}

/**
 * v_docker_logs() sampled at most once an hour, cached in the state dir,
 * keeping the PREVIOUS sample's bytes+ts too so a check can compute a
 * growth rate without needing the flash rollup history (log files don't
 * flow through the ring/rollup — only their check-time size matters).
 */
function v_docker_logs_cached(): array {
  $path = v_state_dir() . '/docker_logs.json';
  $cache = v_read_json($path);
  if (($cache['ts'] ?? 0) > time() - 3600) return $cache;
  $logs = v_docker_logs();
  $cache = [
    'ts' => time(), 'logs' => $logs,
    'prev_ts' => $cache['ts'] ?? null, 'prev_logs' => $cache['logs'] ?? [],
  ];
  v_write_json($path, $cache);
  return $cache;
}

/* ----------------------------------------------------------------------- GPU */

function v_gpu(): array {
  $smi = null;
  foreach (['/usr/bin/nvidia-smi', '/usr/local/bin/nvidia-smi'] as $p) {
    if (is_executable($p)) { $smi = $p; break; }
  }
  if ($smi === null) return [];
  $q = 'utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,power.limit,name,fan.speed';
  $raw = v_run(escapeshellarg($smi) . " --query-gpu={$q} --format=csv,noheader,nounits", 10);
  // nvidia-smi present but the driver is not loaded (typical when the GPU is
  // passed through to a VM). Report it explicitly rather than as "no GPU".
  if ($raw === '' || stripos($raw, 'failed') !== false || stripos($raw, "couldn't communicate") !== false) {
    return ['available' => false, 'reason' => 'driver-not-loaded', 'smi' => $smi];
  }
  $out = [];
  foreach (explode("\n", $raw) as $line) {
    $c = array_map('trim', explode(',', $line));
    if (count($c) < 8) continue;
    $num = fn($s) => is_numeric($s) ? (float)$s : null;
    $out[] = [
      'util' => $num($c[0]), 'mem_used' => $num($c[1]) === null ? null : $num($c[1]) * 1048576,
      'mem_total' => $num($c[2]) === null ? null : $num($c[2]) * 1048576,
      'temp' => $num($c[3]), 'power' => $num($c[4]), 'power_limit' => $num($c[5]),
      'name' => $c[6], 'fan' => $num($c[7]),
    ];
  }
  if (!$out) return ['available' => false, 'reason' => 'no-devices', 'smi' => $smi];
  return $out;
}

/* ----------------------------------------------------------------------- UPS */

function v_ups(): array {
  if (!is_executable('/usr/sbin/apcaccess') && !is_executable('/usr/bin/apcaccess')) return [];
  $raw = v_run('apcaccess', 6);
  if ($raw === '') return [];
  $out = [];
  foreach (explode("\n", $raw) as $line) {
    if (!str_contains($line, ':')) continue;
    [$k, $v] = explode(':', $line, 2);
    $out[trim($k)] = trim($v);
  }
  return $out;
}

/* ---------------------------------------------------------------- parity */

/**
 * /boot/config/parity-checks.log — Unraid's own history of every parity
 * check, sync, rebuild and disk clear. Pipe-separated; the field count per
 * line varies by Unraid version and whether the parity.check.tuning plugin
 * is installed (5/6/7/8/9 fields seen in the wild), so this only reads the
 * common prefix every format shares: date, elapsed seconds, speed, status,
 * errors — everything after that (device list, sync size, etc.) is ignored.
 *
 * status: 0 = clean finish, -4 = cancelled/incomplete, otherwise an error
 * count on some Unraid versions. errors: the corrected-sector count.
 */
function v_parity_history(int $limit = 20): array {
  $path = '/boot/config/parity-checks.log';
  if (!is_file($path)) return [];
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $out = [];
  foreach ($lines as $line) {
    $c = explode('|', $line);
    if (count($c) < 4) continue;
    // strtotime() cannot parse Unraid's "YYYY Mon DD HH:MM:SS" field order
    // (tested directly: returns false) — DateTime::createFromFormat can.
    // The day-of-month is space-padded to 2 chars ("May  1" for the 1st),
    // so collapse repeated spaces before parsing.
    $dt = DateTime::createFromFormat('Y M j H:i:s', preg_replace('/\s+/', ' ', trim($c[0])));
    $ts = $dt !== false ? $dt->getTimestamp() : false;
    $elapsed = (int)$c[1];
    // Speed is "94.6 MB/s" or "0" (when the run was too short/cancelled to
    // have a meaningful average) or "2.7 GB/s" — normalise to MB/s.
    $speedMbps = null;
    if (preg_match('/([\d.]+)\s*(KB|MB|GB)\/s/i', $c[2] ?? '', $m)) {
      $mult = ['KB' => 1 / 1024, 'MB' => 1, 'GB' => 1024][strtoupper($m[2])];
      $speedMbps = round((float)$m[1] * $mult, 1);
    }
    $status = isset($c[3]) && is_numeric($c[3]) ? (int)$c[3] : null;
    $errors = isset($c[4]) && is_numeric($c[4]) ? (int)$c[4] : null;
    // Field 9 (0-indexed) is the human-readable action description on the
    // newer plugin format ("Manual Correcting Parity-Check"); fall back to
    // field 5 (the raw "check P Q" / "recon D5" / "clear" token) when the
    // longer format isn't present.
    $type = trim($c[9] ?? ($c[5] ?? ''));
    $out[] = [
      'date' => $ts !== false ? $ts : null,
      'elapsed_sec' => $elapsed,
      'speed_mbps' => $speedMbps,
      'status' => $status,
      'errors' => $errors,
      'cancelled' => $status === -4,
      'clean' => $status === 0 && ($errors === null || $errors === 0),
      'type' => $type,
    ];
  }
  // Log is chronological (oldest first) — take the most recent $limit,
  // newest last (so a chart/table can read it in display order directly).
  return array_slice($out, -$limit);
}

/* ------------------------------------------------------------------- shares */

function v_shares(): array {
  $shares = v_ini('/var/local/emhttp/shares.ini');
  $out = ['total' => 0, 'cache' => 0, 'array' => 0, 'list' => []];
  foreach ($shares as $name => $s) {
    if (!is_array($s)) continue;
    $pool = $s['useCache'] ?? 'no';
    $out['total']++;
    if (in_array($pool, ['only', 'prefer', 'yes'], true)) $out['cache']++; else $out['array']++;
    if (count($out['list']) < 60) {
      $out['list'][] = [
        'name' => $name,
        'comment' => $s['comment'] ?? '',
        'pool' => $pool,
        'free' => (int)($s['free'] ?? 0) * 1024,
        'size' => (int)($s['size'] ?? 0) * 1024,
      ];
    }
  }
  return $out;
}

/** Array disk mount names currently present (disk1, disk2, ... — not always contiguous). */
function v_array_disk_mounts(): array {
  $out = [];
  foreach (glob('/mnt/disk*') ?: [] as $p) {
    $name = basename($p);
    if (preg_match('/^disk\d+$/', $name) && is_dir($p)) $out[] = $name;
  }
  sort($out, SORT_NATURAL);
  return $out;
}

/**
 * Share placement conflicts (P14-05): a pool-only share ("useCache=only")
 * that also has a top-level folder sitting on an array disk (or vice versa
 * for an array-only share with a folder left on cache) means files got
 * written to the wrong place — classic causes are a share's cache setting
 * changed after files already existed, or the mover never ran. This is the
 * kind of split that keeps array disks spinning for an "SSD-only" share
 * like appdata, and makes Docker slow.
 *
 * Deliberately shallow: only checks for the share's own top-level folder's
 * *existence* on the wrong pool/disk (is_dir), not a full recursive walk —
 * a full walk of every share on every disk would be one of the slowest
 * things this plugin could do. "Files on disks the include/exclude rules
 * out" and the case-mismatch check are left for a follow-up if this
 * shallow version proves too coarse in practice.
 */
function v_share_placement(): array {
  $shares = v_ini('/var/local/emhttp/shares.ini');
  $arrayDisks = v_array_disk_mounts();
  $out = [];
  foreach ($shares as $name => $s) {
    if (!is_array($s)) continue;
    $pool = $s['useCache'] ?? 'no';
    $stray = [];
    if ($pool === 'only') {
      // Cache-only share — flag any array disk that also has this top-level folder.
      foreach ($arrayDisks as $disk) {
        $p = '/mnt/' . $disk . '/' . $name;
        if (is_dir($p)) $stray[] = ['disk' => $disk, 'path' => $p, 'expected' => 'cache'];
      }
    } elseif ($pool === 'no') {
      // Array-only share — flag if it also exists on cache.
      $p = '/mnt/cache/' . $name;
      if (is_dir($p)) $stray[] = ['disk' => 'cache', 'path' => $p, 'expected' => 'array'];
    }
    if ($stray) $out[$name] = ['pool' => $pool, 'stray' => $stray];
  }
  return $out;
}

/** v_share_placement() sampled at most once an hour, cached in the state dir. */
function v_share_placement_cached(): array {
  $path = v_state_dir() . '/share_placement.json';
  $cache = v_read_json($path);
  if (($cache['ts'] ?? 0) > time() - 3600) return $cache['conflicts'] ?? [];
  $conflicts = v_share_placement();
  v_write_json($path, ['ts' => time(), 'conflicts' => $conflicts]);
  return $conflicts;
}

/* ------------------------------------------------------------------ network */

function v_net_read(): array {
  $out = [];
  foreach (@file('/proc/net/dev') ?: [] as $l) {
    if (!preg_match('/^\s*([^:]+):\s*(.*)$/', $l, $m)) continue;
    $if = trim($m[1]);
    if ($if === 'lo') continue;
    $v = preg_split('/\s+/', trim($m[2]));
    $out[$if] = ['rx' => (int)$v[0], 'tx' => (int)$v[8]];
  }
  return $out;
}

function v_net_delta(array $now, array $prev, float $seconds): array {
  if ($seconds <= 0) $seconds = 1;
  $out = [];
  foreach ($now as $if => $n) {
    if (!isset($prev[$if])) continue;
    $rx = max(0, $n['rx'] - $prev[$if]['rx']);
    $tx = max(0, $n['tx'] - $prev[$if]['tx']);
    $out[$if] = ['rx_rate' => $rx / $seconds, 'tx_rate' => $tx / $seconds,
                 'rx_total' => $n['rx'], 'tx_total' => $n['tx']];
  }
  return $out;
}

/* ---------------------------------------------------------------- processes */

function v_top_procs(int $n = 8): array {
  $raw = v_run("ps -eo pcpu,pmem,rss,comm --sort=-pcpu --no-headers", 8);
  $out = [];
  foreach (explode("\n", $raw) as $line) {
    $c = preg_split('/\s+/', trim($line));
    if (count($c) < 4) continue;
    $out[] = ['cpu' => (float)$c[0], 'mem' => (float)$c[1],
              'rss' => (int)$c[2] * 1024, 'name' => implode(' ', array_slice($c, 3))];
    if (count($out) >= $n) break;
  }
  return $out;
}

/* ------------------------------------------------------------------ assemble */

/**
 * Root filesystem usage. Unraid boots into RAM (tmpfs) — `/` filling up is a
 * different, more urgent failure than an array disk filling up (it can wedge
 * docker, syslog, and the webGUI itself), so it gets its own snapshot field
 * rather than being folded into 'array'.
 */
function v_rootfs(): array {
  $total = @disk_total_space('/');
  $free  = @disk_free_space('/');
  if ($total === false || $free === false || $total <= 0) {
    return ['total' => null, 'free' => null, 'used_pct' => null];
  }
  $used = $total - $free;
  return [
    'total' => (int)$total,
    'free' => (int)$free,
    'used_pct' => round(($used / $total) * 100, 1),
  ];
}

/**
 * Disk usage of one mount point. Unraid runs / (and /var/log, /tmp, /run —
 * all tmpfs/overlay mounted from RAM) so this is cheap and safe to call
 * every minute; it is NOT walked for the array/cache disks (see
 * v_array_disks() for those).
 */
function v_mount_fill(string $path): array {
  $total = @disk_total_space($path);
  $free  = @disk_free_space($path);
  if ($total === false || $free === false || $total <= 0) {
    return ['path' => $path, 'total' => null, 'free' => null, 'used_pct' => null, 'largest' => []];
  }
  $used = $total - $free;
  $pct = round(($used / $total) * 100, 1);
  // Only walk for the largest files once usage looks like a real problem —
  // no point spending a `find` every minute on a healthy filesystem, and
  // this keeps the collector's per-minute cost flat on a quiet system.
  $largest = $pct >= 70 ? v_largest_files($path, 5) : [];
  return ['path' => $path, 'total' => (int)$total, 'free' => (int)$free, 'used_pct' => $pct, 'largest' => $largest];
}

/**
 * Top N largest files under $dir, same filesystem only (-xdev — stops the
 * walk at a mount boundary, e.g. /var/log won't wander into a bind-mounted
 * docker log dir sitting under it).
 */
function v_largest_files(string $dir, int $n = 5): array {
  $raw = v_run('find ' . escapeshellarg($dir) . " -xdev -type f -printf '%s\\t%p\\n' 2>/dev/null | sort -rn | head -n " . (int)$n, 10);
  $out = [];
  if ($raw === '') return $out;
  foreach (explode("\n", $raw) as $line) {
    $c = explode("\t", $line, 2);
    if (count($c) < 2 || !is_numeric($c[0])) continue;
    $out[] = ['path' => $c[1], 'bytes' => (int)$c[0]];
  }
  return $out;
}

/** rootfs + /var/log + /tmp + /run, for the P14-03 fill check. */
function v_fs_watch(): array {
  return [
    'rootfs'   => v_mount_fill('/'),
    'var_log'  => v_mount_fill('/var/log'),
    'tmp'      => v_mount_fill('/tmp'),
    'run'      => v_mount_fill('/run'),
  ];
}

/**
 * Collect a full snapshot. $prev is the previous snapshot (for rate deltas)
 * and $elapsed the seconds since it was taken.
 */
function v_collect(?array $prev = null, float $elapsed = 60.0): array {
  $cpuNow = v_cpu_stat();
  $netNow = v_net_read();
  $snap = [
    'time'    => time(),
    'system'  => v_system(),
    'cpu'     => ['cores' => [], 'total' => null],
    'cpu_topology' => v_cpu_topology(),
    'sensors' => v_sensors(),
    'mem'     => v_mem(),
    'load'    => v_load(),
    'array'   => v_array_disks(),
    'spin_analysis' => [],
    'parity_history' => v_parity_history(20),
    'rootfs'  => v_rootfs(),
    'fs_watch' => v_fs_watch(),
    'smart'   => v_smart_tracked(),
    'vms'     => v_vms(),
    'docker'  => v_docker(),
    'docker_image' => v_docker_image(),
    'docker_layers' => v_docker_layers_cached(),
    'docker_logs' => v_docker_logs_cached(),
    'gpu'     => v_gpu(),
    'ups'     => v_ups(),
    'shares'  => v_shares(),
    'share_placement' => v_share_placement_cached(),
    'net'     => [],
    'top'     => v_top_procs(8),
  ];

  if ($prev && !empty($prev['_raw']['cpu'])) {
    $p = v_cpu_pct($cpuNow, $prev['_raw']['cpu']);
    $snap['cpu'] = $p;
  }
  if ($prev && !empty($prev['_raw']['net'])) {
    $snap['net'] = v_net_delta($netNow, $prev['_raw']['net'], $elapsed);
    if (!$snap['net']) {
      foreach ($netNow as $if => $n) {
        $snap['net'][$if] = ['rx_rate' => 0, 'tx_rate' => 0, 'rx_total' => $n['rx'], 'tx_total' => $n['tx']];
      }
    }
  }
  foreach ($netNow as $if => $n) {
    if (!isset($snap['net'][$if])) {
      $snap['net'][$if] = ['rx_rate' => 0, 'tx_rate' => 0, 'rx_total' => $n['rx'], 'tx_total' => $n['tx']];
    }
  }

  $snap['_raw'] = ['cpu' => $cpuNow, 'net' => $netNow];

  // Spin-down tracking (P14-08): record this sample, then compute analysis
  // from the accumulated history -- must happen after v_array_disks() has
  // already populated $snap['array'] with spundown + diskstats.
  $allDisks = array_merge($snap['array']['data'] ?? [], $snap['array']['parity'] ?? [], $snap['array']['cache'] ?? []);
  v_spin_track($allDisks);
  $snap['spin_analysis'] = v_spin_analysis();

  return $snap;
}
