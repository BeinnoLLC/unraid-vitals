<?php

require_once __DIR__ . '/collect.php';

if (!defined('VITALS_FLASH')) define('VITALS_FLASH', '/boot/config/plugins/unraid-vitals');
if (!defined('VITALS_RING_MAX')) define('VITALS_RING_MAX', 1440);        
if (!defined('VITALS_RING_MINUTES')) define('VITALS_RING_MINUTES', 1440); 
 
 
 
if (!defined('VITALS_DOCKER_CFG')) define('VITALS_DOCKER_CFG', '/boot/config/docker.cfg');
if (!defined('VITALS_AI_RENOTIFY')) define('VITALS_AI_RENOTIFY', 86400);   

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

function v_db_path(): string {
  $dir = v_data_dir();
  if ($dir === '') return '';
  $db = $dir . '/vitals.db';
  
  $legacy = v_state_dir() . '/vitals.db';
  if (!is_file($db) && is_file($legacy)) @copy($legacy, $db);
  return $db;
}

function v_thresholds(): array {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  return [
    'temp'      => (int)($cfg['ALERT_TEMP'] ?? 55),
    'load'      => (float)($cfg['ALERT_LOAD'] ?? 0),      
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

function v_point(array $snap): array {
  if (isset($snap['system']['uptime'])) v_track_reboots((int)$snap['system']['uptime']);

  $temps = [];
  foreach ($snap['array']['data'] ?? [] as $d)   if ($d['temp'] !== null) $temps[] = $d['temp'];
  foreach ($snap['array']['parity'] ?? [] as $d) if ($d['temp'] !== null) $temps[] = $d['temp'];

  
  
  
  
  
  $fsBytes = [];
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    if (($d['fsSize'] ?? 0) > 0 && !empty($d['name'])) $fsBytes[$d['name']] = (int)$d['fsUsed'];
  }
  $dockerImgUsed = $snap['docker_image']['used'] ?? null;

  
  
  $sTemps = []; $sFans = []; $sVolts = [];
  foreach ($snap['sensors']['temps'] ?? [] as $t) $sTemps[$t['id']] = $t['value'];
  foreach ($snap['sensors']['fans'] ?? [] as $f)  $sFans[$f['id']]  = $f['rpm'];
  foreach ($snap['sensors']['volts'] ?? [] as $v) $sVolts[$v['id']] = $v['value'];

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
    $containers[$c['name']] = [$c['cpu'], $c['mem_bytes'] === null ? null : round($c['mem_bytes'] / 1024, 0), $c['restart_count'] ?? 0];
  }

  $smart = [];
  foreach ($snap['smart'] ?? [] as $dev => $s) {
    if ($s['temp'] === null && $s['reallocated'] === null && $s['pending'] === null) continue;
    $smart[$s['name']] = [$s['temp'], $s['reallocated'], $s['pending']];
  }

  
  
  
  
  $diskIo = [];
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['parity'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    if (!empty($d['io'])) {
      $diskIo[$d['name']] = [$d['io']['read_bps'], $d['io']['write_bps'], $d['io']['read_iops'], $d['io']['write_iops']];
    }
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
    'sensors_v' => $sVolts,
    'cpu_mhz_avg' => $snap['cpu_freq']['avg_mhz'] ?? null,
    'watts_cpu' => $snap['power']['cpu_watts'] ?? null,
    'watts_gpu' => $snap['power']['gpu_watts'] ?? null,
    'watts_ups' => $snap['power']['ups_watts'] ?? null,
    'watts_total' => $snap['power']['total_watts'] ?? null,
    'fs_bytes' => $fsBytes,
    'docker_img_used' => $dockerImgUsed,
    'disk_io' => $diskIo,
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

function v_ctr_hourly_agg(array $points): array {
  $byName = [];
  foreach ($points as $p) {
    foreach (($p['ctr'] ?? []) as $name => $c) {
      $byName[$name]['cpu'][] = $c[0];
      $byName[$name]['mem'][] = $c[1];
      $byName[$name]['restart'] = $c[2] ?? 0;   
    }
  }
  $out = [];
  foreach ($byName as $name => $d) {
    $cpuAgg = v_agg($d['cpu']);
    $memAgg = v_agg($d['mem']);
    $out[$name] = [$cpuAgg['avg'], $cpuAgg['max'], $memAgg['avg'], $memAgg['max'], $d['restart']];
  }
  return $out;
}

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
    
    
    'fs_bytes'  => $lastPoint['fs_bytes'] ?? [],
    'docker_img_used' => $lastPoint['docker_img_used'] ?? ($snap['docker_image']['used'] ?? null),
    
    
    'smart'     => $snap['smart'] ?? null,
    
    
    'watts_avg' => v_agg(array_column($points, 'watts_total'))['avg'],
    'watts_max' => v_agg(array_column($points, 'watts_total'))['max'],
    'watts_includes' => $snap['power']['total_includes'] ?? [],
    
    
    
    
    'ctr'       => v_ctr_hourly_agg($points),
  ];
  @file_put_contents($dir . '/' . date('Y-m', $closedHour * 3600) . '.jsonl',
                     json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  @file_put_contents($markerFile, (string)$hour);
  
  
  v_flash_writes_track();
  v_flash_writes_track();
}

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
                          'mem_max' => null, 'temp' => null, 'temp_avg_sum' => 0, 'temp_avg_n' => 0,
                          'rx' => 0, 'tx' => 0, 'fill' => null,
                          'gpu' => null, 'smart' => [], 'single_sample_hours' => 0,
                          'docker_img_pct' => null, 'docker_img_pct_h' => -1,
                          'fs_bytes' => [], 'fs_bytes_h' => -1,
                          'docker_img_used' => null, 'docker_img_used_h' => -1];
      }
      $b = &$buckets[$day];
      $b['n']++;
      
      
      
      
      if (!array_key_exists('n', $r)) $b['single_sample_hours']++;
      if (($r['cpu_avg'] ?? null) !== null) $b['cpu'] += $r['cpu_avg'];
      if (($r['mem_avg'] ?? null) !== null) $b['mem'] += $r['mem_avg'];
      
      
      
      $hourCpuMax = $r['cpu_max'] ?? $r['cpu_avg'] ?? null;
      $hourMemMax = $r['mem_max'] ?? $r['mem_avg'] ?? null;
      if ($hourCpuMax !== null) $b['cpu_max'] = max($b['cpu_max'] ?? 0, $hourCpuMax);
      if ($hourMemMax !== null) $b['mem_max'] = max($b['mem_max'] ?? 0, $hourMemMax);
      if (($r['temp_max'] ?? null) !== null) $b['temp'] = max($b['temp'] ?? 0, $r['temp_max']);
      if (($r['temp_avg'] ?? null) !== null) { $b['temp_avg_sum'] += $r['temp_avg']; $b['temp_avg_n']++; }
      if (($r['fill_max'] ?? null) !== null) $b['fill'] = max($b['fill'] ?? 0, $r['fill_max']);
      
      
      
      if (($r['docker_img_pct'] ?? null) !== null && $r['h'] > $b['docker_img_pct_h']) {
        $b['docker_img_pct'] = $r['docker_img_pct'];
        $b['docker_img_pct_h'] = $r['h'];
      }
      
      
      
      if (($r['fs_bytes'] ?? null) && $r['h'] > $b['fs_bytes_h']) {
        $b['fs_bytes'] = $r['fs_bytes'];
        $b['fs_bytes_h'] = $r['h'];
      }
      if (($r['docker_img_used'] ?? null) !== null && $r['h'] > $b['docker_img_used_h']) {
        $b['docker_img_used'] = $r['docker_img_used'];
        $b['docker_img_used_h'] = $r['h'];
      }
      
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
      'temp_avg' => $b['temp_avg_n'] ? round($b['temp_avg_sum'] / $b['temp_avg_n'], 1) : null,
      'net_rx' => round($b['rx'], 0), 'net_tx' => round($b['tx'], 0),
      'smart' => $b['smart'],
      'docker_img_pct' => $b['docker_img_pct'],
      'fs_bytes' => $b['fs_bytes'],
      'docker_img_used' => $b['docker_img_used'],
      
      
      
      'single_sample' => $b['n'] > 0 && $b['single_sample_hours'] === $b['n'],
    ];
  }
  return $out;
}

function v_hourly_rows(): array {
  $dir = VITALS_FLASH . '/history';
  if (!is_dir($dir)) return [];
  $rows = [];
  foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
    foreach (explode("\n", (string)@file_get_contents($f)) as $line) {
      if ($line === '') continue;
      $r = json_decode($line, true);
      if (is_array($r) && isset($r['h'])) $rows[] = $r;
    }
  }
  usort($rows, fn($a, $b) => $a['h'] <=> $b['h']);
  return $rows;
}

function v_anomaly_check(array $metrics = ['cpu' => 'cpu_avg', 'mem' => 'mem_avg', 'temp' => 'temp_max'], int $recentHours = 6): array {
  $rows = v_hourly_rows();
  if (count($rows) < 336) {
    return ['status' => 'need_more_data', 'have_hours' => count($rows), 'need_hours' => 336, 'findings' => []];
  }

  $recent = array_slice($rows, -$recentHours);
  $baselineRows = array_slice($rows, 0, -$recentHours);

  
  $buckets = [];
  foreach ($baselineRows as $r) {
    $howBucket = ((int)gmdate('N', $r['h'] * 3600) - 1) * 24 + (int)gmdate('G', $r['h'] * 3600);
    foreach ($metrics as $key => $field) {
      if (($r[$field] ?? null) !== null) $buckets[$howBucket][$key][] = (float)$r[$field];
    }
  }

  $baseline = function (int $howBucket, string $key) use ($buckets) {
    $vals = $buckets[$howBucket][$key] ?? [];
    if (count($vals) < 3) return null;   
    sort($vals);
    $n = count($vals);
    $median = $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
    $devs = array_map(fn($v) => abs($v - $median), $vals);
    sort($devs);
    $mad = $n % 2 ? $devs[intdiv($n, 2)] : ($devs[$n / 2 - 1] + $devs[$n / 2]) / 2;
    return ['median' => $median, 'mad' => max($mad, 0.5)];   
  };

  
  
  $flags = [];
  foreach ($recent as $r) {
    $howBucket = ((int)gmdate('N', $r['h'] * 3600) - 1) * 24 + (int)gmdate('G', $r['h'] * 3600);
    foreach ($metrics as $key => $field) {
      $val = $r[$field] ?? null;
      if ($val === null) continue;
      $b = $baseline($howBucket, $key);
      if ($b === null) continue;
      $z = abs($val - $b['median']) / ($b['mad'] * 1.4826);   
      $flags[$key][] = $z >= 3.5 ? ['h' => $r['h'], 'val' => $val, 'median' => $b['median'], 'z' => round($z, 1)] : null;
    }
  }

  $findings = [];
  foreach ($flags as $key => $seq) {
    
    
    for ($i = 0; $i + 2 < count($seq); $i++) {
      if ($seq[$i] && $seq[$i + 1] && $seq[$i + 2]) {
        $findings[] = [
          'metric' => $key, 'hours' => [$seq[$i]['h'], $seq[$i + 1]['h'], $seq[$i + 2]['h']],
          'value' => $seq[$i + 2]['val'], 'baseline_median' => $seq[$i + 2]['median'], 'z_score' => $seq[$i + 2]['z'],
        ];
        break;   
      }
    }
  }

  return ['status' => 'ok', 'have_hours' => count($rows), 'findings' => $findings];
}

function v_capacity_forecast(array $daily, array $totals): array {
  $series = [];
  foreach ($daily as $d) {
    foreach (($d['fs_bytes'] ?? []) as $name => $used) {
      if ($used !== null) $series[$name][] = [$d['day'], $used];
    }
    if (($d['docker_img_used'] ?? null) !== null) $series['docker.img'][] = [$d['day'], $d['docker_img_used']];
  }

  $out = [];
  foreach ($series as $name => $pts) {
    if (count($pts) < 5) continue;   
    $t = $totals[$name] ?? null;
    if (!$t || ($t[0] ?? 0) <= 0) continue;
    list($totalBytes, $freeBytesNow) = $t;

    $n = count($pts);
    $x0 = strtotime($pts[0][0] . ' 00:00:00');
    $xs = array_map(fn($p) => (strtotime($p[0] . ' 00:00:00') - $x0) / 86400.0, $pts);   
    $ys = array_map(fn($p) => $p[1], $pts);
    $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
    $sxx = 0.0; $sxy = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
      $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my;
      $sxx += $dx * $dx; $sxy += $dx * $dy; $syy += $dy * $dy;
    }
    if ($sxx <= 0) continue;
    $slope = $sxy / $sxx;                       
    $r2 = ($syy > 0) ? ($sxy * $sxy) / ($sxx * $syy) : 0.0;

    if ($slope <= 0 || $r2 < 0.5) continue;      

    $gbPerDay = round($slope / 1073741824, 2);
    $daysLeft = $freeBytesNow / $slope;
    if ($daysLeft > 3650) continue;              

    $out[] = [
      'name' => $name,
      'gb_per_day' => $gbPerDay,
      'days_left' => round($daysLeft, 1),
      'r2' => round($r2, 2),
      'free_now_gb' => round($freeBytesNow / 1073741824, 1),
      'total_gb' => round($totalBytes / 1073741824, 1),
      'within_14d' => $daysLeft <= 14,
    ];
  }
  usort($out, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
  return $out;
}

function v_check_alerts(array $snap): array {
  $th = v_thresholds();
  $state = v_read_json(v_alert_path());
  $now = time();
  $fired = [];

  $raise = function (string $key, string $subject, string $desc, string $importance) use (&$state, $now, &$fired) {
    $last = (int)($state[$key] ?? 0);
    if ($now - $last < 3600) return;               
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

  
  $fill = v_fill_max($snap);
  if ($fill !== null && $fill >= $th['fill']) {
    $raise('fill', 'Vitals: array ' . $fill . '% full',
      'The fullest data disk is ' . $fill . '% full, above the ' . $th['fill'] . '% threshold.', 'warning');
    v_event_raise('fill', 'array', 'fill', 'warning',
      'Array ' . $fill . '% full (threshold ' . $th['fill'] . '%)', ['value' => $fill, 'threshold' => $th['fill']]);
  } else {
    v_event_resolve('fill');
  }

  
  if ($th['load'] > 0 && (float)($snap['load']['l1'] ?? 0) >= $th['load']) {
    $raise('load', 'Vitals: load ' . $snap['load']['l1'],
      '1-minute load average is ' . $snap['load']['l1'] . ', above the configured limit of ' . $th['load'] . '.', 'warning');
    v_event_raise('load', 'system', 'load', 'warning',
      'Load average ' . $snap['load']['l1'] . ' (limit ' . $th['load'] . ')',
      ['value' => $snap['load']['l1'], 'threshold' => $th['load']]);
  } else {
    v_event_resolve('load');
  }

  
  
  
  
  
  
  
  
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
        
        
        v_event_raise('smart', $s['name'], $key, 'info',
          $s['name'] . ' has ' . $value . ' ' . $label . ', unchanged in ' . $growth['days'] . 'd',
          ['value' => $value, 'grew' => 0, 'days' => $growth['days'], 'counter' => $k]);
        v_event_resolve($key);
      } elseif ($value > 0) {
        
        
        
        v_event_raise('smart', $s['name'], $key, 'info',
          $s['name'] . ' has ' . $value . ' ' . $label . ' (watching for growth)',
          ['value' => $value, 'counter' => $k]);
        v_event_resolve($key);
      } else {
        v_event_resolve($key);
      }
    }
  }

  
  
  
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

  
  
  foreach ($snap['sensors']['fans'] ?? [] as $f) {
    $key = 'fan_stall_' . $f['id'];
    if ($f['rpm'] > 0) { unset($state[$key]); v_event_resolve($key); continue; }
    if (empty($state['fan_seen_' . $f['id']])) continue;   
    $raise($key, 'Vitals: fan stalled — ' . $f['label'],
      'Fan ' . $f['label'] . ' on ' . $f['chip'] . ' reported ' . $f['rpm'] .
      ' RPM (was spinning earlier). Check the fan and its header/cable.', 'alert');
    v_event_raise('fan_stall', $f['id'], $key, 'alert',
      'Fan ' . $f['label'] . ' stalled (was spinning)', ['chip' => $f['chip']]);
  }
  foreach ($snap['sensors']['fans'] ?? [] as $f) {
    if ($f['rpm'] > 0) $state['fan_seen_' . $f['id']] = 1;
  }

  
  
  
  
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
      
      if (empty($state['ctr_seen_' . $name])) continue;
      $state[$key] = (int)($state[$key] ?? 0) + 1;
      if ($state[$key] >= $th['restarts']) {
        $raise('ctr_alert_' . $name, 'Vitals: ' . $name . ' not running',
          'Container ' . $name . ' (' . $c['image'] . ') was running and has been down for ' . $state[$key] . ' consecutive samples.', 'alert');
        v_event_raise('container', $name, 'ctr_alert_' . $name, 'alert',
          $name . ' down for ' . $state[$key] . ' samples', ['image' => $c['image']]);
      }
    }
    
    $seen = [];
    foreach ($snap['docker']['containers'] ?? [] as $c) if (($c['state'] ?? '') === 'running') $seen['ctr_seen_' . $c['name']] = 1;
    foreach ($state as $k => $v) if (strpos($k, 'ctr_seen_') === 0) unset($state[$k]);
    $state = array_merge($state, $seen);
  }

  v_write_json(v_alert_path(), $state);
  return $fired;
}

function v_events_db(): ?SQLite3 {
  if (!class_exists('SQLite3')) return null;
  $dbFile = v_db_path();
  if ($dbFile === '') return null;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(2000);
    
    
    
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

function v_event_resolve(string $alertKey): void {
  $db = v_events_db();
  if (!$db) return;
  $stmt = $db->prepare("UPDATE kb_events SET status = 'resolved', resolved_at = ? WHERE alert_key = ? AND status = 'open'");
  $stmt->bindValue(1, time(), SQLITE3_INTEGER);
  $stmt->bindValue(2, $alertKey, SQLITE3_TEXT);
  $stmt->execute();
  $db->close();
}

function v_track_reboots(int $uptimeSec): void {
  $stateFile = v_state_dir() . '/last_uptime.json';
  $now = time();
  $bootedAt = $now - $uptimeSec;
  $prev = v_read_json($stateFile);
  $prevUptime = $prev['uptime'] ?? null;
  $prevBootedAt = $prev['booted_at'] ?? null;
  
  
  if ($prevUptime !== null && $uptimeSec < $prevUptime - 5 && $prevBootedAt !== null) {
    $logFile = VITALS_FLASH . '/reboots.jsonl';
    @file_put_contents($logFile, json_encode(['t' => $bootedAt]) . "\n", FILE_APPEND | LOCK_EX);
  }
  v_write_json($stateFile, ['uptime' => $uptimeSec, 'booted_at' => $bootedAt]);
}

function v_reboot_log(int $limit = 50): array {
  $f = VITALS_FLASH . '/reboots.jsonl';
  if (!is_file($f)) return [];
  $out = [];
  foreach (explode("\n", (string)@file_get_contents($f)) as $line) {
    if ($line === '') continue;
    $r = json_decode($line, true);
    if (is_array($r) && isset($r['t'])) $out[] = $r['t'];
  }
  return array_slice($out, -$limit);
}

function v_chart_events(int $sinceHours = 24): array {
  $since = time() - $sinceHours * 3600;
  $out = [];

  foreach (v_events_list(null, 200) as $e) {
    if ((int)$e['started_at'] < $since) continue;
    $out[] = ['t' => (int)$e['started_at'], 'kind' => 'event:' . $e['kind'],
      'label' => $e['summary'], 'severity' => $e['severity']];
  }

  foreach (v_parity_history(30) as $p) {
    if ($p['date'] === null || $p['date'] < $since) continue;
    $out[] = ['t' => $p['date'], 'kind' => 'parity',
      'label' => ($p['type'] ?: 'Parity check') . ($p['clean'] ? ' (clean)' : ($p['cancelled'] ? ' (cancelled)' : ' (' . $p['errors'] . ' errors)')),
      'severity' => $p['clean'] ? 'info' : 'warning'];
  }

  foreach (v_reboot_log(20) as $t) {
    if ($t < $since) continue;
    $out[] = ['t' => $t, 'kind' => 'reboot', 'label' => 'System reboot', 'severity' => 'info'];
  }

  
  
  
  $syslog = @file_get_contents('/var/log/syslog');
  if ($syslog !== false) {
    $year = (int)date('Y');
    if (preg_match_all('/^(\w+\s+\d+\s+\d+:\d+:\d+)\s+\S+\s+move:\s*mover:\s*finished/mi', $syslog, $m)) {
      foreach ($m[1] as $ts) {
        $dt = DateTime::createFromFormat('M j H:i:s Y', preg_replace('/\s+/', ' ', trim($ts)) . ' ' . $year);
        $t = $dt !== false ? $dt->getTimestamp() : false;
        if ($t !== false && $t >= $since) $out[] = ['t' => $t, 'kind' => 'mover', 'label' => 'Mover run finished', 'severity' => 'info'];
      }
    }
  }

  usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
  return $out;
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

function v_event_get(int $id): ?array {
  $db = v_events_db();  
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

function v_storage_analyzer(): array {
  $dbFile = v_db_path();
  if ($dbFile === '' || !is_file($dbFile) || !class_exists('SQLite3')) return ['shares' => [], 'scanned_at' => null];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return ['shares' => [], 'scanned_at' => null]; }
  $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='storage_scans'");
  if (!$exists) { $db->close(); return ['shares' => [], 'scanned_at' => null]; }

  $since = time() - 31 * 86400;
  $rows = [];
  $res = $db->query("SELECT share, scanned_at, total_bytes, top_folders, file_types, stale_bytes, stale_count
                       FROM storage_scans WHERE scanned_at >= " . (int)$since . " ORDER BY share, scanned_at ASC");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $row;
  $db->close();

  $byShare = [];
  foreach ($rows as $r) $byShare[$r['share']][] = $r;

  $out = [];
  $latestScan = null;
  foreach ($byShare as $share => $scans) {
    $last = end($scans);
    if ($latestScan === null || $last['scanned_at'] > $latestScan) $latestScan = $last['scanned_at'];
    $out[] = [
      'share' => $share,
      'scanned_at' => (int)$last['scanned_at'],
      'total_bytes' => (int)$last['total_bytes'],
      'top_folders' => json_decode($last['top_folders'] ?? '[]', true) ?: [],
      'file_types' => json_decode($last['file_types'] ?? '{}', true) ?: [],
      'stale_bytes' => (int)$last['stale_bytes'],
      'stale_count' => (int)$last['stale_count'],
      'growth' => array_map(fn($s) => [(int)$s['scanned_at'], (int)$s['total_bytes']], $scans),
    ];
  }
  usort($out, fn($a, $b) => $b['total_bytes'] <=> $a['total_bytes']);
  return ['shares' => $out, 'scanned_at' => $latestScan];
}

function v_container_weekly_report(): array {
  $rows = v_hourly_rows();
  $now = time();
  $thisWeekStart = $now - 7 * 86400;
  $lastWeekStart = $now - 14 * 86400;

  $agg = function (array $rows) {
    $byName = [];
    foreach ($rows as $r) {
      foreach (($r['ctr'] ?? []) as $name => $c) {
        
        $byName[$name]['cpu'][] = $c[0];
        $byName[$name]['cpu_peak'][] = $c[1];
        $byName[$name]['mem'][] = $c[2];
        $byName[$name]['mem_peak'][] = $c[3];
        $byName[$name]['restart_first'] = $byName[$name]['restart_first'] ?? $c[4];
        $byName[$name]['restart_last'] = $c[4];
      }
    }
    $out = [];
    foreach ($byName as $name => $d) {
      $cpuVals = array_values(array_filter($d['cpu'], fn($v) => $v !== null));
      $memVals = array_values(array_filter($d['mem'], fn($v) => $v !== null));
      $cpuPeakVals = array_values(array_filter($d['cpu_peak'], fn($v) => $v !== null));
      $memPeakVals = array_values(array_filter($d['mem_peak'], fn($v) => $v !== null));
      $out[$name] = [
        'cpu_avg' => $cpuVals ? round(array_sum($cpuVals) / count($cpuVals), 1) : null,
        'cpu_peak' => $cpuPeakVals ? max($cpuPeakVals) : null,
        'mem_avg_kb' => $memVals ? round(array_sum($memVals) / count($memVals), 0) : null,
        'mem_peak_kb' => $memPeakVals ? max($memPeakVals) : null,
        
        
        
        'restarts' => max(0, ($d['restart_last'] ?? 0) - ($d['restart_first'] ?? 0)),
      ];
    }
    return $out;
  };

  $thisWeekRows = array_filter($rows, fn($r) => $r['h'] * 3600 >= $thisWeekStart);
  $lastWeekRows = array_filter($rows, fn($r) => $r['h'] * 3600 >= $lastWeekStart && $r['h'] * 3600 < $thisWeekStart);

  $thisWeek = $agg($thisWeekRows);
  $lastWeek = $agg($lastWeekRows);
  $haveLastWeek = count($lastWeekRows) > 0;

  $names = array_unique(array_merge(array_keys($thisWeek), array_keys($lastWeek)));
  $out = [];
  foreach ($names as $name) {
    $tw = $thisWeek[$name] ?? null;
    $lw = $lastWeek[$name] ?? null;
    $pctChange = function ($a, $b) {
      if ($a === null || $b === null || $b == 0) return null;
      return round(100 * ($a - $b) / $b, 1);
    };
    $out[] = [
      'name' => $name,
      'this_week' => $tw,
      'last_week' => $lw,
      'cpu_change_pct' => $tw && $lw ? $pctChange($tw['cpu_avg'], $lw['cpu_avg']) : null,
      'mem_change_pct' => $tw && $lw ? $pctChange($tw['mem_avg_kb'], $lw['mem_avg_kb']) : null,
    ];
  }
  usort($out, fn($a, $b) => ($b['this_week']['cpu_avg'] ?? -1) <=> ($a['this_week']['cpu_avg'] ?? -1));

  return ['containers' => $out, 'have_last_week' => $haveLastWeek,
    'this_week_hours' => count($thisWeekRows), 'last_week_hours' => count($lastWeekRows)];
}

function v_export_ring_rows(): array {
  $ring = v_ring();
  $rows = [];
  foreach ($ring as $p) {
    $rows[] = [
      'time' => $p['t'] ?? null,
      'time_iso' => isset($p['t']) ? gmdate('c', $p['t']) : null,
      'cpu_pct' => $p['cpu'] ?? null,
      'mem_pct' => $p['mem'] ?? null,
      'load' => $p['load'] ?? null,
      'temp_max_c' => $p['temp_max'] ?? null,
      'gpu_pct' => $p['gpu'] ?? null,
      'fs_used_bytes' => $p['fs_used'] ?? null,
      'fill_max_pct' => $p['fill_max'] ?? null,
      'var_log_pct' => $p['var_log_pct'] ?? null,
      'tmp_pct' => $p['tmp_pct'] ?? null,
      'docker_running' => $p['docker'] ?? null,
      'docker_img_pct' => $p['docker_img_pct'] ?? null,
      'watts_total' => $p['watts_total'] ?? null,
      'watts_cpu' => $p['watts_cpu'] ?? null,
      'watts_gpu' => $p['watts_gpu'] ?? null,
      'watts_ups' => $p['watts_ups'] ?? null,
      'cpu_mhz_avg' => $p['cpu_mhz_avg'] ?? null,
    ];
  }
  return $rows;
}

function v_export_rollup_rows(): array {
  $rows = [];
  foreach (v_hourly_rows() as $r) {
    $rows[] = [
      'hour' => $r['h'] ?? null,
      'hour_iso' => isset($r['h']) ? gmdate('c', $r['h'] * 3600) : null,
      'samples' => $r['n'] ?? null,
      'cpu_avg' => $r['cpu_avg'] ?? null, 'cpu_min' => $r['cpu_min'] ?? null,
      'cpu_max' => $r['cpu_max'] ?? null, 'cpu_p95' => $r['cpu_p95'] ?? null,
      'mem_avg' => $r['mem_avg'] ?? null, 'mem_min' => $r['mem_min'] ?? null,
      'mem_max' => $r['mem_max'] ?? null, 'mem_p95' => $r['mem_p95'] ?? null,
      'load_avg' => $r['load_avg'] ?? null, 'load_max' => $r['load_max'] ?? null,
      'temp_avg' => $r['temp_avg'] ?? null, 'temp_max' => $r['temp_max'] ?? null,
      'gpu_avg' => $r['gpu_avg'] ?? null, 'gpu_max' => $r['gpu_max'] ?? null,
      'net_rx_bytes' => $r['net_rx'] ?? null, 'net_tx_bytes' => $r['net_tx'] ?? null,
      'fs_used_bytes' => $r['fs_used'] ?? null, 'fill_max_pct' => $r['fill_max'] ?? null,
      'docker_img_pct' => $r['docker_img_pct'] ?? null,
      'watts_avg' => $r['watts_avg'] ?? null, 'watts_max' => $r['watts_max'] ?? null,
    ];
  }
  return $rows;
}

function v_rows_to_csv(array $rows): string {
  if (!$rows) return '';
  $fh = fopen('php://temp', 'r+');
  fputcsv($fh, array_keys($rows[0]));
  foreach ($rows as $r) fputcsv($fh, array_map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $r));
  rewind($fh);
  $out = stream_get_contents($fh);
  fclose($fh);
  return $out;
}

function v_weekly_health_summary(): array {
  $rows = v_hourly_rows();
  $now = time();
  $thisWeekStart = $now - 7 * 86400;
  $lastWeekStart = $now - 14 * 86400;
  $thisWeek = array_values(array_filter($rows, fn($r) => $r['h'] * 3600 >= $thisWeekStart));
  $lastWeek = array_values(array_filter($rows, fn($r) => $r['h'] * 3600 >= $lastWeekStart && $r['h'] * 3600 < $thisWeekStart));

  $avgOf = function (array $rows, string $key) {
    $vals = array_values(array_filter(array_column($rows, $key), fn($v) => $v !== null));
    return $vals ? round(array_sum($vals) / count($vals), 1) : null;
  };

  $cpuThis = $avgOf($thisWeek, 'cpu_avg'); $cpuLast = $avgOf($lastWeek, 'cpu_avg');
  $memThis = $avgOf($thisWeek, 'mem_avg'); $memLast = $avgOf($lastWeek, 'mem_avg');
  $tempThis = $avgOf($thisWeek, 'temp_avg'); $tempLast = $avgOf($lastWeek, 'temp_avg');

  
  $findings = [];
  $db = v_events_db();
  if ($db) {
    $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='check_results'");
    if ($exists) {
      $res = $db->query("SELECT check_id, severity, subject, title FROM check_results
                           WHERE severity IN ('error','critical','alert') ORDER BY
                           CASE severity WHEN 'critical' THEN 0 WHEN 'alert' THEN 1 ELSE 2 END LIMIT 10");
      while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $findings[] = $row;
    }
    $db->close();
  }

  
  $daily = v_daily(30);
  $snap = v_latest() ?: [];
  $totals = [];
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['cache'] ?? []) as $dk) {
    if (($dk['fsSize'] ?? 0) > 0 && !empty($dk['name'])) $totals[$dk['name']] = [$dk['fsSize'], $dk['fsFree']];
  }
  $forecast = v_capacity_forecast($daily, $totals);
  $forecastSoon = array_values(array_filter($forecast, fn($f) => ($f['days_left'] ?? 999) < 30));

  
  $fleetTop = [];
  try { $fleet = v_disk_fleet_report(); $fleetTop = array_slice(array_filter($fleet['disks'], fn($d) => $d['risk_score'] > 0), 0, 5); } catch (Throwable $e) {}
  $ctrRestarts = 0;
  try { $wk = v_container_weekly_report(); foreach ($wk['containers'] as $c) $ctrRestarts += $c['this_week']['restarts'] ?? 0; } catch (Throwable $e) {}

  return [
    'generated_at' => $now,
    'week_start' => $thisWeekStart,
    'cpu_avg' => $cpuThis, 'cpu_avg_last_week' => $cpuLast,
    'mem_avg' => $memThis, 'mem_avg_last_week' => $memLast,
    'temp_avg' => $tempThis, 'temp_avg_last_week' => $tempLast,
    'worst_findings' => $findings,
    'capacity_forecast_soon' => $forecastSoon,
    'disk_fleet_top_risk' => array_values($fleetTop),
    'container_restarts_this_week' => $ctrRestarts,
  ];
}

function v_send_weekly_health_report(): void {
  $s = v_weekly_health_summary();
  $lines = [];
  $lines[] = 'CPU avg ' . ($s['cpu_avg'] ?? '—') . '%' . ($s['cpu_avg_last_week'] !== null ? ' (was ' . $s['cpu_avg_last_week'] . '%)' : '');
  $lines[] = 'Mem avg ' . ($s['mem_avg'] ?? '—') . '%' . ($s['mem_avg_last_week'] !== null ? ' (was ' . $s['mem_avg_last_week'] . '%)' : '');
  $lines[] = 'Temp avg ' . ($s['temp_avg'] ?? '—') . '°C' . ($s['temp_avg_last_week'] !== null ? ' (was ' . $s['temp_avg_last_week'] . '°C)' : '');
  if ($s['worst_findings']) {
    $lines[] = count($s['worst_findings']) . ' open finding(s), worst: ' . $s['worst_findings'][0]['title'];
  } else {
    $lines[] = 'No open error/critical findings.';
  }
  if ($s['capacity_forecast_soon']) {
    $f = $s['capacity_forecast_soon'][0];
    $lines[] = $f['name'] . ' projected full in ' . $f['days_left'] . ' day(s).';
  }
  if ($s['disk_fleet_top_risk']) {
    $d = $s['disk_fleet_top_risk'][0];
    $lines[] = 'Top disk risk: ' . $d['name'] . ' (score ' . $d['risk_score'] . ') — ' . implode('; ', $d['risk_reasons']);
  }
  if ($s['container_restarts_this_week'] > 0) {
    $lines[] = $s['container_restarts_this_week'] . ' container restart(s) this week.';
  }
  $desc = implode("\n", $lines);

  $notify = '/usr/local/emhttp/webGui/scripts/notify';
  if (is_executable($notify)) {
    $importance = ($s['worst_findings'] || $s['capacity_forecast_soon']) ? 'warning' : 'normal';
    @shell_exec(sprintf(
      '%s -e %s -s %s -d %s -i %s -l %s 2>/dev/null',
      escapeshellarg($notify), escapeshellarg('unraid-vitals weekly report'),
      escapeshellarg('Weekly health summary'), escapeshellarg($desc),
      escapeshellarg($importance), escapeshellarg('/Vitals')
    ));
  }
}

function v_disk_fleet_report(): array {
  $current = v_smart();
  $rows = v_hourly_rows();
  $since = time() - 30 * 86400;
  $rows = array_values(array_filter($rows, fn($r) => $r['h'] * 3600 >= $since));

  
  
  
  $tempHistByDev = [];
  foreach ($rows as $r) {
    foreach (($r['smart'] ?? []) as $dev => $s) {
      if (isset($s['temp']) && $s['temp'] !== null) $tempHistByDev[$dev][] = [$r['h'] * 3600, $s['temp']];
    }
  }

  $out = [];
  foreach ($current as $dev => $d) {
    $reasons = [];
    $score = 0;

    $growth = $d['growth_30d'] ?? [];
    $pending = $d['pending'] ?? 0;
    $reallocated = $d['reallocated'] ?? 0;
    $uncorrectable = $d['uncorrectable'] ?? 0;
    $crc = $d['crc'] ?? 0;

    if ($pending !== null && $pending > 0) {
      $score += 100 * $pending;
      $reasons[] = "$pending pending sector(s)";
    }
    if (($growth['pending'] ?? null) !== null && $growth['pending'] > 0) {
      $score += 500;
      $reasons[] = "pending sectors growing (+{$growth['pending']} in {$growth['days']}d)";
    }
    if ($reallocated !== null && $reallocated > 0) {
      $score += 20 * $reallocated;
      $reasons[] = "$reallocated reallocated sector(s)";
    }
    if (($growth['reallocated'] ?? null) !== null && $growth['reallocated'] > 0) {
      $score += 100;
      $reasons[] = "reallocated sectors growing (+{$growth['reallocated']} in {$growth['days']}d)";
    }
    if ($uncorrectable !== null && $uncorrectable > 0) {
      $score += 10 * $uncorrectable;
      $reasons[] = "$uncorrectable uncorrectable error(s)";
    }
    if ($crc !== null && $crc > 0) {
      $score += 10 * $crc;
      $reasons[] = "$crc CRC error(s) (often cabling)";
    }
    if (($d['health'] ?? null) !== null && $d['health'] !== 'PASSED' && $d['health'] !== '') {
      $score += 1000;
      $reasons[] = 'SMART overall health: ' . $d['health'];
    }
    if (($d['temp'] ?? null) !== null && $d['temp'] > 50) {
      $over = $d['temp'] - 50;
      $score += 5 * $over;
      $reasons[] = "running hot ({$d['temp']}\xC2\xB0C)";
    }

    $out[] = [
      'dev' => $dev,
      'name' => $d['name'] ?? $dev,
      'model' => $d['model'] ?? null,
      'hours' => $d['hours'] ?? null,
      'start_stop_count' => $d['start_stop_count'] ?? null,
      'temp' => $d['temp'] ?? null,
      'temp_history' => $tempHistByDev[$dev] ?? [],
      'reallocated' => $reallocated, 'pending' => $pending,
      'uncorrectable' => $uncorrectable, 'crc' => $crc,
      'growth_30d' => $growth,
      'health' => $d['health'] ?? null,
      'risk_score' => $score,
      'risk_reasons' => $reasons,
    ];
  }
  usort($out, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
  return ['disks' => $out];
}

function v_energy_report(): array {
  $rows = v_hourly_rows();
  $since = time() - 30 * 86400;
  $rows = array_values(array_filter($rows, fn($r) => $r['h'] * 3600 >= $since && isset($r['watts_avg']) && $r['watts_avg'] !== null));

  $byDay = [];
  foreach ($rows as $r) {
    $day = gmdate('Y-m-d', $r['h'] * 3600);
    $byDay[$day]['wh'] = ($byDay[$day]['wh'] ?? 0) + $r['watts_avg'];   
    $byDay[$day]['hours'] = ($byDay[$day]['hours'] ?? 0) + 1;
    $byDay[$day]['includes'] = $r['watts_includes'] ?? [];
  }
  ksort($byDay);

  $cfg = defined('V_CFG_FILE') && is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
  $pricePerKwh = isset($cfg['PRICE_PER_KWH']) && $cfg['PRICE_PER_KWH'] !== '' ? (float)$cfg['PRICE_PER_KWH'] : null;

  $days = [];
  foreach ($byDay as $day => $d) {
    $kwh = round($d['wh'] / 1000, 3);
    $days[] = [
      'day' => $day,
      'kwh' => $kwh,
      'hours_sampled' => $d['hours'],
      
      
      
      'estimated' => $d['hours'] < 20,
      'kwh_extrapolated' => $d['hours'] > 0 ? round($kwh * 24 / $d['hours'], 3) : $kwh,
      'cost' => $pricePerKwh !== null ? round($kwh * $pricePerKwh, 2) : null,
      'includes' => $d['includes'],
    ];
  }

  $last24 = array_slice($rows, -24);
  $current = null;
  if ($last24) {
    $recentAvg = array_sum(array_column($last24, 'watts_avg')) / count($last24);
    $current = round($recentAvg, 1);
  }

  return [
    'days' => $days,
    'price_per_kwh' => $pricePerKwh,
    'current_watts_avg_24h' => $current,
    'source' => $rows ? (end($rows)['watts_includes'] ?? []) : [],
  ];
}

function v_dup_report(int $limit = 100): array {
  $dbFile = v_db_path();
  if ($dbFile === '' || !is_file($dbFile) || !class_exists('SQLite3')) return ['groups' => [], 'scanned_at' => null, 'total_wasted' => 0];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return ['groups' => [], 'scanned_at' => null, 'total_wasted' => 0]; }
  $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='dup_groups'");
  if (!$exists) { $db->close(); return ['groups' => [], 'scanned_at' => null, 'total_wasted' => 0]; }

  $groups = [];
  $res = $db->query("SELECT id, size, file_count, wasted_bytes, scanned_at FROM dup_groups ORDER BY wasted_bytes DESC LIMIT " . (int)$limit);
  $latest = null; $totalWasted = 0;
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
    if ($latest === null || $row['scanned_at'] > $latest) $latest = $row['scanned_at'];
    $totalWasted += (int)$row['wasted_bytes'];
    $files = [];
    $fstmt = $db->prepare("SELECT path FROM dup_files WHERE group_id = ?");
    $fstmt->bindValue(1, $row['id'], SQLITE3_INTEGER);
    $fres = $fstmt->execute();
    while ($fres && ($frow = $fres->fetchArray(SQLITE3_ASSOC))) $files[] = $frow['path'];
    $groups[] = ['size' => (int)$row['size'], 'file_count' => (int)$row['file_count'],
      'wasted_bytes' => (int)$row['wasted_bytes'], 'files' => $files];
  }
  $db->close();
  return ['groups' => $groups, 'scanned_at' => $latest, 'total_wasted' => $totalWasted];
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

  
  
  foreach ($state as $k => $v) {
    if (strpos($k, 'ai_') !== 0) continue;
    if (strpos($k, 'ai_finding_') === 0 || (!isset($live[$k]) && $now - (int)$v >= VITALS_AI_RENOTIFY)) {
      unset($state[$k]);
    }
  }
  v_write_json(v_alert_path(), $state);
}

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

function v_ai_runs(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  
  
  $cols = ['agent', 'MAX(started_at) AS started_at', 'status', 'error', 'finished_at'];
  $have = [];
  $pragma = $db->query('PRAGMA table_info(runs)');
  while (($c = $pragma->fetchArray(SQLITE3_ASSOC))) $have[] = $c['name'];
  foreach (['prompt_tokens', 'output_tokens', 'model_ms', 'endpoint', 'retries', 'findings_kept', 'findings_dropped'] as $c) {
    if (in_array($c, $have, true)) $cols[] = $c;
  }
  $res = $db->query("SELECT " . implode(', ', $cols) . "
                      FROM runs GROUP BY agent ORDER BY agent");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

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

function v_apply_share_comment(string $share, string $comment): bool {
  if (!preg_match('/^[\w.\- ]{1,60}$/', $share)) return false;
  $cfgFile = '/boot/config/shares/' . $share . '.cfg';
  if (!is_file($cfgFile)) return false;

  
  
  $raw = @parse_ini_file($cfgFile) ?: [];
  if (!$raw) return false;
  $raw['shareComment'] = (string)$comment;
  $text = '';
  foreach ($raw as $k => $v) $text .= $k . '="' . str_replace('"', '', (string)$v) . "\"\n";

  $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
  $csrf = (string)($var['csrf_token'] ?? '');

  $fields = ['csrf_token' => $csrf, '#file' => $cfgFile, '#arg' => 'edit'];
  foreach ($raw as $k => $v) $fields[$k] = (string)$v;

  $cookieHeader = '';
  foreach ($_COOKIE as $k => $v) $cookieHeader .= $k . '=' . urlencode($v) . '; ';

  $ch = curl_init('http://127.0.0.1/update.php');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_HTTPHEADER => ['Cookie: ' . rtrim($cookieHeader, '; ')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
  ]);
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 300 || $resp === false) {
    syslog(LOG_WARNING, "unraid-vitals: apply share comment to $share rejected by webGui (HTTP $code) — a signed-in admin session is required");
    return false;
  }

  
  $now = @parse_ini_file($cfgFile)['shareComment'] ?? null;
  if ($now !== (string)$comment) {
    syslog(LOG_WARNING, "unraid-vitals: share comment on $share did not land (cfg still shows " . var_export($now, true) . ')');
    return false;
  }
  
  
  @shell_exec('timeout 5 /usr/local/emhttp/plugins/dynamix/scripts/share_size ' . escapeshellarg($share) . ' >/dev/null 2>&1 &');
  return true;
}

function v_kb_search(string $query, int $limit = 20, string $severity = '', string $tag = ''): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $words = array_filter(preg_split('/\s+/', preg_replace('/["*^]/', ' ', $query)));
  $safe = implode(' OR ', array_map(fn($w) => '"' . $w . '"', $words));
  if ($safe === '') { $db->close(); return []; }
  $out = [];
  try {
    $sevClause = $severity !== '' ? 'AND d.severity = :sev' : '';
    
    
    $tagClause = ($tag !== '' && v_kb_has_tags($db)) ? "AND (',' || d.tags || ',') LIKE :taglike" : '';
    $stmt = $db->prepare(
      "SELECT d.id, d.source, d.source_ref, d.topic, d.title, d.content, d.kind, d.summary, d.images, d.severity, d.tags, d.created_at, bm25(kb_fts) AS rank
       FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
       WHERE kb_fts MATCH :q $sevClause $tagClause ORDER BY rank LIMIT :lim");
    $stmt->bindValue(':q', $safe, SQLITE3_TEXT);
    if ($severity !== '') $stmt->bindValue(':sev', $severity, SQLITE3_TEXT);
    if ($tag !== '' && v_kb_has_tags($db)) $stmt->bindValue(':taglike', '%,' . $tag . ',%', SQLITE3_TEXT);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = v_kb_row_decode($row);
  } catch (Throwable $e) {  }
  $db->close();
  return $out;
}

function v_kb_has_tags(SQLite3 $db): bool {
  static $memo = null;
  if ($memo !== null) return $memo;
  $memo = false;
  $res = $db->query("PRAGMA table_info(kb_documents)");
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) if ($r['name'] === 'tags') { $memo = true; break; }
  return $memo;
}

function v_kb_tags_of(array $row): array {
  return !empty($row['tags']) ? explode(',', $row['tags']) : [];
}

function v_research_has_origin(SQLite3 $db): bool {
  static $memo = null;
  if ($memo !== null) return $memo;
  $memo = false;
  $res = $db->query("PRAGMA table_info(research_jobs)");
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) if ($r['name'] === 'origin') { $memo = true; break; }
  return $memo;
}

function v_kb_row_decode(array $row): array {
  $row['images'] = $row['images'] ? (json_decode($row['images'], true) ?: []) : [];
  $row['tags'] = v_kb_tags_of($row);
  return $row;
}

function v_kb_recent(int $limit = 50, string $severity = '', string $tag = ''): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $tagsSel = v_kb_has_tags($db) ? 'tags' : "'' AS tags";
  $tagClause = ($tag !== '' && v_kb_has_tags($db)) ? "AND (',' || tags || ',') LIKE :taglike" : '';
  $sevAndTag = "severity = :sev $tagClause";
  if ($severity !== '' || $tag !== '') {
    if ($severity !== '') {
      $stmt = $db->prepare("SELECT id, source, source_ref, topic, title, content, kind, summary, images, severity, $tagsSel, created_at
                             FROM kb_documents WHERE $sevAndTag ORDER BY created_at DESC, id DESC LIMIT :lim");
      $stmt->bindValue(':sev', $severity, SQLITE3_TEXT);
    } else {
      $stmt = $db->prepare("SELECT id, source, source_ref, topic, title, content, kind, summary, images, severity, $tagsSel, created_at
                             FROM kb_documents WHERE 1=1 $tagClause ORDER BY created_at DESC, id DESC LIMIT :lim");
    }
    if ($tag !== '' && v_kb_has_tags($db)) $stmt->bindValue(':taglike', '%,' . $tag . ',%', SQLITE3_TEXT);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
  } else {
    $res = $db->query("SELECT id, source, source_ref, topic, title, content, kind, summary, images, severity, $tagsSel, created_at
                        FROM kb_documents ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
  }
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = v_kb_row_decode($row);
  $db->close();
  return $out;
}

function v_kb_get(int $id): ?array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return null;
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return null; }
  $tagsSel = v_kb_has_tags($db) ? 'tags' : "'' AS tags";
  $stmt = $db->prepare("SELECT id, source, source_ref, topic, title, content, kind, summary, images, severity, $tagsSel, created_at
                         FROM kb_documents WHERE id = ?");
  $stmt->bindValue(1, $id, SQLITE3_INTEGER);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $db->close();
  return $row ? v_kb_row_decode($row) : null;
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

function v_kb_tag_counts(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  if (!v_kb_has_tags($db)) { $db->close(); return []; }
  $out = [];
  $res = $db->query("SELECT tags FROM kb_documents WHERE tags != ''");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
    foreach (explode(',', $row['tags']) as $t) if ($t !== '') $out[$t] = ($out[$t] ?? 0) + 1;
  }
  $db->close();
  arsort($out);
  return $out;
}

function v_kb_severity_counts(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $res = $db->query("SELECT severity, COUNT(*) AS n FROM kb_documents GROUP BY severity");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[$row['severity']] = (int)$row['n'];
  $db->close();
  return $out;
}

function v_research_create(string $prompt, string $mode = 'once', int $durationMinutes = 0, int $tickMinutes = 15): ?int {
  $dbFile = v_db_path();
  if ($dbFile === '' || !class_exists('SQLite3')) return null;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(2000);
    $db->exec("CREATE TABLE IF NOT EXISTS research_jobs (
      id INTEGER PRIMARY KEY AUTOINCREMENT, prompt TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending', answer TEXT, sources TEXT, error TEXT,
      created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER,
      mode TEXT NOT NULL DEFAULT 'once', study_until INTEGER, tick_minutes INTEGER,
      last_tick_at INTEGER, observations TEXT)");
    $now = time();
    if ($mode === 'study') {
      $until = $now + max(5, $durationMinutes) * 60;
      $stmt = $db->prepare("INSERT INTO research_jobs (prompt, status, mode, study_until, tick_minutes, observations, created_at)
        VALUES (:p, 'studying', 'study', :u, :tk, '[]', :t)");
      $stmt->bindValue(':u', $until, SQLITE3_INTEGER);
      $stmt->bindValue(':tk', max(1, $tickMinutes), SQLITE3_INTEGER);
    } else {
      $stmt = $db->prepare("INSERT INTO research_jobs (prompt, status, mode, created_at) VALUES (:p, 'pending', 'once', :t)");
    }
    $stmt->bindValue(':p', $prompt, SQLITE3_TEXT);
    $stmt->bindValue(':t', $now, SQLITE3_INTEGER);
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
  $originCol = v_research_has_origin($db) ? 'origin, context,' : "'manual' AS origin, NULL AS context,";
  $stmt = $db->prepare("SELECT id, prompt, status, answer, sources, error, mode, study_until, tick_minutes,
                                last_tick_at, observations, $originCol created_at, finished_at
                         FROM research_jobs WHERE id = ?");
  $stmt->bindValue(1, $id, SQLITE3_INTEGER);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $db->close();
  if ($row && $row['sources']) $row['sources'] = json_decode($row['sources'], true);
  if ($row && $row['observations']) $row['observations'] = json_decode($row['observations'], true);
  return $row ?: null;
}

function v_research_retry(int $id): bool {
  $dbFile = v_db_path();
  if ($dbFile === '' || !class_exists('SQLite3')) return false;
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(2000);
    $stmt = $db->prepare("SELECT mode, study_until, tick_minutes FROM research_jobs WHERE id = ? AND status = 'error'");
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) { $db->close(); return false; }
    
    
    
    
    $now = time();
    if ($row['mode'] === 'study' && (int)$row['study_until'] <= $now) {
      $extendStmt = $db->prepare("UPDATE research_jobs SET status='pending', error=NULL, answer=NULL,
                                   study_until = :until, last_tick_at = NULL WHERE id = :id");
      $tick = max(5, (int)$row['tick_minutes'] ?: 15);
      $extendStmt->bindValue(':until', $now + 12 * 60 * 60, SQLITE3_INTEGER);
      $extendStmt->bindValue(':id', $id, SQLITE3_INTEGER);
      $extendStmt->execute();
    } else {
      $stmt2 = $db->prepare("UPDATE research_jobs SET status='pending', error=NULL, answer=NULL WHERE id = ?");
      $stmt2->bindValue(1, $id, SQLITE3_INTEGER);
      $stmt2->execute();
    }
    $db->close();
    return true;
  } catch (Throwable $e) { return false; }
}

function v_research_list(int $limit = 30): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $out = [];
  $originCol = v_research_has_origin($db) ? 'origin,' : "'manual' AS origin,";
  $res = $db->query("SELECT id, prompt, status, mode, study_until, tick_minutes, last_tick_at, $originCol created_at, finished_at
                      FROM research_jobs ORDER BY id DESC LIMIT " . (int)$limit);
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

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
    if (count($entries) >= 500) break; 
  }
  usort($entries, function ($a, $b) {
    if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
  });
  $relOut = $targetReal === $rootReal ? '' : substr($targetReal, strlen($rootReal) + 1);
  return ['share' => $share, 'path' => $relOut, 'entries' => $entries];
}

function v_tick(bool $full = true): array {
  $prev = v_read_json(v_state_dir() . '/prev.json');
  $elapsed = 60.0;
  if (isset($prev['time'])) $elapsed = max(1.0, (float)(time() - (int)$prev['time']));

  $snap = v_collect($prev ?: null, $elapsed);

  
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
  
  
  
  $slim['thresholds'] = v_thresholds();
  return $slim;
}
