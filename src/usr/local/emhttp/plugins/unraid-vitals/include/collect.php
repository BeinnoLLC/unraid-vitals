<?php

if (!defined('VITALS_PLUGDIR')) define('VITALS_PLUGDIR', '/usr/local/emhttp/plugins/unraid-vitals');
if (!defined('VITALS_STATE'))    define('VITALS_STATE',   '/var/tmp/unraid-vitals');

function v_state_dir(): string {
  if (!is_dir(VITALS_STATE)) @mkdir(VITALS_STATE, 0755, true);
  return VITALS_STATE;
}

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

const V_MODEL_BLURBS = [
  'llama3.1:latest' => 'Solid general-purpose fallback — fast, dependable tool use, nothing specialized.',
  'ministral-3:latest' => 'Small vision+tool model — good for quick multimodal checks without heavy VRAM.',
  'nomic-embed-text:latest' => 'Embedding-only model — cannot be used for diagnostics chat/reasoning.',
  'qwen2.5-coder:1.5b-base' => 'Tiny code-completion base model — fast but weak reasoning; not for diagnostics.',
  'qwen3:14b' => 'Strong all-rounder with thinking mode — good default for corroborated diagnostics.',
  'qwen3-coder:30b' => 'Large code-specialist MoE — best for reading/generating code, heavier to run.',
  'devstral-small-2:latest' => 'Vision+tool coding model tuned for agentic dev workflows.',
  'qwen3.8:latest' => 'Large model with thinking + vision — most capable, also the slowest per call.',
  'nemotron-3.5-lightning:latest' => 'NVIDIA MoE reasoning model — strong on structured/technical analysis.',
  'qwen2.5-coder:7b' => 'Mid-size code model — good balance of speed and code-aware reasoning.',
  'llama3-groq-tool-use:latest' => 'Tool-calling tuned Llama3 — reliable for structured function-call findings.',
  'qwen3:30b-a3b' => 'Large MoE with thinking mode — deep analysis at higher latency/VRAM cost.',
  'deepseek-r1:14b' => 'Dedicated reasoning ("thinking") model — best for multi-step root-cause analysis.',
  'qwen2.5-coder:14b' => 'Larger code-completion model — stronger than the 7b at the same coder family.',
  'gpt-oss:20b' => 'OpenAI open-weight model with thinking mode — broad general reasoning.',
];

function v_list_models(): array {
  $flashDir = defined('VITALS_FLASH') ? VITALS_FLASH : '/boot/config/plugins/unraid-vitals';
  $cfg = is_file($flashDir . '/vitals.cfg') ? (@parse_ini_file($flashDir . '/vitals.cfg') ?: []) : [];
  $primary = $cfg['LLM_STUDIO_PRIMARY'] ?? '';
  $primary = $primary !== '' ? $primary : 'https://llmstudio2.hazemhagrass.com';
  $backup  = $cfg['LLM_STUDIO_BACKUP']  ?? '';
  $backup  = $backup !== '' ? $backup : 'https://llmstudio1.hazemhagrass.com';
  $enabled = array_filter(array_map('trim', explode(',', (string)($cfg['VITALS_DIAG_MODELS'] ?? ''))));

  $fetchTags = function (string $base): array {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents(rtrim($base, '/') . '/api/tags', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    $names = [];
    foreach (($j['models'] ?? []) as $m) if (!empty($m['name'])) $names[$m['name']] = $m;
    return $names;
  };
  $onPrimary = $fetchTags($primary);
  $onBackup  = $primary === $backup ? $onPrimary : $fetchTags($backup);

  $all = array_keys($onPrimary + $onBackup);
  sort($all);
  $models = [];
  foreach ($all as $name) {
    $meta = $onPrimary[$name] ?? $onBackup[$name] ?? [];
    $det = $meta['details'] ?? [];
    $caps = $meta['capabilities'] ?? [];
    $models[] = [
      'name' => $name,
      'blurb' => V_MODEL_BLURBS[$name] ?? null,
      'params' => $det['parameter_size'] ?? null,
      'quant' => $det['quantization_level'] ?? null,
      'thinking' => in_array('thinking', $caps, true),
      'vision' => in_array('vision', $caps, true),
      'embedding_only' => in_array('embedding', $caps, true) && !in_array('completion', $caps, true),
      'on_primary' => isset($onPrimary[$name]),
      'on_backup' => isset($onBackup[$name]),
      
      
      
      
      'enabled' => $enabled ? in_array($name, $enabled, true) : null,
    ];
  }
  return [
    'models' => $models,
    'reachable_primary' => (bool)$onPrimary,
    'reachable_backup' => (bool)$onBackup,
    'configured_default' => empty($enabled),
  ];
}

function v_bytes(float $n, int $p = 1): string {
  $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
  return round($n, $p) . ' ' . $u[$i];
}

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

function v_cpu_stat(): array {
  $out = ['cores' => [], 'total' => ['total' => 0, 'idle' => 0]];
  $lines = @file('/proc/stat');
  if (!$lines) return $out;
  foreach ($lines as $line) {
    if (!preg_match('/^(cpu\d*)\s+(.*)$/', trim($line), $m)) continue;
    $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
    $idle  = ($v[3] ?? 0) + ($v[4] ?? 0);   
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
    
    
    
    
    'unclean_shutdown' => ($var['sbClean'] ?? 'yes') !== 'yes',
  ];
}

function v_syslog_signatures_def(): array {
  static $sigs = null;
  if ($sigs === null) {
    $path = __DIR__ . '/checks/signatures.json';
    $raw = @file_get_contents($path);
    $sigs = $raw ? (json_decode($raw, true) ?: []) : [];
  }
  return $sigs;
}

function v_syslog_scan(string $logPath = '/var/log/syslog'): array {
  $offsetPath = v_state_dir() . '/syslog_offset.json';
  $matchLogPath = v_state_dir() . '/syslog_matches.json';
  $state = v_read_json($offsetPath);
  if (!is_array($state)) $state = [];

  if (!is_readable($logPath)) return [];
  $inode = @fileinode($logPath) ?: 0;
  $size = @filesize($logPath) ?: 0;

  $offset = 0;
  if (($state['path'] ?? null) === $logPath && ($state['inode'] ?? null) === $inode && ($state['offset'] ?? 0) <= $size) {
    $offset = (int)$state['offset'];
  }
  
  
  
  

  $fh = @fopen($logPath, 'r');
  if (!$fh) return [];
  if ($offset > 0) fseek($fh, $offset);
  $newContent = stream_get_contents($fh);
  $newOffset = ftell($fh);
  fclose($fh);

  v_write_json($offsetPath, ['path' => $logPath, 'inode' => $inode, 'offset' => $newOffset]);

  if ($newContent === false || $newContent === '') return [];

  $sigs = v_syslog_signatures_def();
  $findings = [];
  $lines = explode("\n", $newContent);
  $matchedKeysThisRun = [];

  foreach ($lines as $line) {
    if (trim($line) === '') continue;
    foreach ($sigs as $key => $sig) {
      if (empty($sig['pattern'])) continue;
      if (!@preg_match($sig['pattern'], $line, $m)) continue;
      $matchedKeysThisRun[$key] = true;
      $title = $sig['title'] ?? $key;
      $detail = $sig['detail'] ?? '';
      if (!empty($m[1])) { $title = str_replace('{1}', $m[1], $title); $detail = str_replace('{1}', $m[1], $detail); }
      $findings[] = [
        'signature' => $key, 'severity' => $sig['severity'] ?? 'warning',
        'title' => $title, 'detail' => $detail, 'line' => trim($line), 'time' => time(),
      ];
    }
  }

  
  
  
  
  $findings = array_values(array_filter($findings, function ($f) use ($sigs, $matchedKeysThisRun) {
    $suppress = $sigs[$f['signature']]['suppress_if_matched'] ?? [];
    foreach ($suppress as $other) if (!empty($matchedKeysThisRun[$other])) return false;
    return true;
  }));

  if ($findings) {
    $recent = v_read_json($matchLogPath);
    if (!is_array($recent)) $recent = [];
    $recent = array_merge($recent, $findings);
    if (count($recent) > 200) $recent = array_slice($recent, -200);
    v_write_json($matchLogPath, $recent);
  }

  return $findings;
}

function v_pool_health(): array {
  $out = ['btrfs' => [], 'zfs' => []];
  $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];

  
  
  
  
  $btrfsSeen = [];
  foreach ($mounts as $line) {
    $f = preg_split('/\s+/', $line);
    if (count($f) < 3 || $f[2] !== 'btrfs') continue;
    $mountPoint = stripcslashes($f[1]);
    $dev = trim(shell_exec('findmnt -no SOURCE ' . escapeshellarg($mountPoint) . ' 2>/dev/null') ?? '');
    if ($dev === '') continue;
    
    
    
    $devKey = preg_replace('/\[.*\]$/', '', $dev);
    if (isset($btrfsSeen[$devKey])) continue;
    $btrfsSeen[$devKey] = true;

    $entry = ['mount' => $mountPoint, 'device' => $dev, 'devices' => [], 'scrub_status' => null, 'scrub_date' => null];

    $statsRaw = @shell_exec('btrfs device stats ' . escapeshellarg($mountPoint) . ' 2>/dev/null') ?: '';
    
    $perDevice = [];
    if (preg_match_all('/^\[([^\]]+)\]\.(\w+)\s+(\d+)/m', $statsRaw, $ms, PREG_SET_ORDER)) {
      foreach ($ms as $m) { $perDevice[$m[1]][$m[2]] = (int)$m[3]; }
    }
    foreach ($perDevice as $devPath => $stats) {
      $entry['devices'][] = ['device' => $devPath, 'stats' => $stats, 'total_errors' => array_sum($stats)];
    }

    $scrubRaw = @shell_exec('btrfs scrub status ' . escapeshellarg($mountPoint) . ' 2>/dev/null') ?: '';
    if (stripos($scrubRaw, 'no stats available') !== false) {
      $entry['scrub_status'] = 'never_run';
    } elseif (preg_match('/Scrub (started|resumed).*(?:\n.*)*?status:\s*(\w+)/i', $scrubRaw, $m)) {
      $entry['scrub_status'] = strtolower($m[2]);
    } elseif (stripos($scrubRaw, 'finished') !== false || stripos($scrubRaw, 'no errors') !== false) {
      $entry['scrub_status'] = 'finished';
    }
    
    
    
    

    $out['btrfs'][] = $entry;
  }

  
  
  if (@shell_exec('command -v zpool 2>/dev/null')) {
    $poolsRaw = trim(@shell_exec('zpool list -H -o name 2>/dev/null') ?? '');
    $pools = $poolsRaw !== '' ? explode("\n", $poolsRaw) : [];
    $arc = v_zfs_arc_stats();
    foreach ($pools as $pool) {
      $pool = trim($pool);
      if ($pool === '') continue;
      $statusRaw = @shell_exec('zpool status ' . escapeshellarg($pool) . ' 2>/dev/null') ?: '';
      $state = null;
      if (preg_match('/state:\s*(\w+)/i', $statusRaw, $m)) $state = strtoupper($m[1]);
      $scrubDate = null; $scrubErrors = null;
      if (preg_match('/scan:\s*(?:scrub repaired \S+ in \S+ with (\d+) errors on (.+)|.*)/i', $statusRaw, $m)) {
        if (isset($m[1])) { $scrubErrors = (int)$m[1]; $scrubDate = trim($m[2] ?? ''); }
      }
      
      
      $devErrors = [];
      if (preg_match_all('/^\s+(\S+)\s+(?:ONLINE|DEGRADED|FAULTED|OFFLINE|UNAVAIL|REMOVED)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $statusRaw, $dms, PREG_SET_ORDER)) {
        foreach ($dms as $dm) {
          $read = (int)$dm[2]; $write = (int)$dm[3]; $cksum = (int)$dm[4];
          if ($dm[1] === $pool) continue; 
          if ($read + $write + $cksum > 0) $devErrors[] = ['device' => $dm[1], 'read' => $read, 'write' => $write, 'cksum' => $cksum];
        }
      }
      $out['zfs'][] = [
        'pool' => $pool, 'state' => $state, 'scrub_date' => $scrubDate ?: null,
        'scrub_errors' => $scrubErrors, 'device_errors' => $devErrors, 'arc' => $arc,
      ];
    }
  }

  return $out;
}

function v_zfs_arc_stats(): ?array {
  $raw = @file_get_contents('/proc/spl/kstat/zfs/arcstats');
  if ($raw === false) return null;
  $vals = [];
  foreach (['size', 'c', 'c_max'] as $key) {
    if (preg_match('/^' . $key . '\s+\d+\s+(\d+)/m', $raw, $m)) $vals[$key] = (int)$m[1];
  }
  if (!isset($vals['size'])) return null;
  $memTotal = 0;
  $memRaw = @file_get_contents('/proc/meminfo') ?: '';
  if (preg_match('/MemTotal:\s*(\d+)\s*kB/i', $memRaw, $m)) $memTotal = (int)$m[1] * 1024;
  return [
    'size_bytes' => $vals['size'], 'target_bytes' => $vals['c'] ?? null, 'max_bytes' => $vals['c_max'] ?? null,
    'pct_of_ram' => $memTotal > 0 ? round(100 * $vals['size'] / $memTotal, 1) : null,
  ];
}

function v_diskstats_for(string $device): ?array {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    $raw = @file_get_contents('/proc/diskstats') ?: '';
    foreach (explode("\n", $raw) as $line) {
      $f = preg_split('/\s+/', trim($line));
      if (count($f) < 14) continue;
      
      
      $cache[$f[2]] = ['reads' => (int)$f[5], 'writes' => (int)$f[9],
                        'reads_ops' => (int)$f[3], 'writes_ops' => (int)$f[7]];
    }
  }
  return $cache[$device] ?? null;
}

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

function v_spin_analysis(): array {
  $path = v_state_dir() . '/spin_history.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) return [];
  $out = [];
  foreach ($hist as $name => $series) {
    if (count($series) < 2) continue;
    $spanMin = ($series[count($series) - 1]['t'] - $series[0]['t']) / 60;
    $upSamples = array_filter($series, fn($s) => ($s['spundown'] ?? 0) === 0);
    $upMinutes = count($upSamples); 
    
    
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
      'continuously_up' => $upMinutes >= count($series) && $spanMin >= 1439, 
      
      
      
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
    
    
    
    if (!empty($e['device'])) {
      $ds = v_diskstats_for($e['device']);
      if ($ds !== null) {
        $e['io_reads_sectors'] = $ds['reads']; $e['io_writes_sectors'] = $ds['writes'];
        $e['io_reads_ops'] = $ds['reads_ops']; $e['io_writes_ops'] = $ds['writes_ops'];
      }
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
  $idmap = [];   
  foreach ($disks as $d) {
    if (!is_array($d) || empty($d['device'])) continue;
    $devmap[$d['device']] = $d['name'] ?? $d['device'];
    if (!empty($d['name']) && !empty($d['id'])) $idmap[$d['name']] = $d['id'];
  }
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
      
      
      
      'model' => null, 'start_stop_count' => null,
      
      'nvme_pct_used' => null, 'nvme_spare_pct' => null, 'nvme_spare_threshold' => null,
      'nvme_media_errors' => null, 'nvme_critical_warning' => null,
      
      
      
      'ssd_wear_pct' => null,
    ];
    if (preg_match('/^Device Model:\\s+(.+)$/mi', $raw, $m))                     $r['model'] = trim($m[1]);
    elseif (preg_match('/^Model Number:\\s+(.+)$/mi', $raw, $m))                 $r['model'] = trim($m[1]);   
    if ($r['model'] === null && isset($idmap[$dev])) {
      
      
      
      
      $id = $idmap[$dev];
      $r['model'] = str_replace('_', ' ', preg_replace('/_[A-Za-z0-9]+$/', '', $id)) ?: $id;
    }
    if (preg_match('/^\\s*4\\s+Start_Stop_Count\\s+(.*)$/mi', $raw, $m))         $r['start_stop_count'] = v_ata_raw($m[1]);
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
    
    
    
    if (preg_match('/^\s*177\s+Wear_Leveling_Count\s+0x[0-9a-f]+\s+(\d+)/mi', $raw, $m))      $r['ssd_wear_pct'] = 100 - (int)$m[1];
    elseif (preg_match('/^\s*233\s+Media_Wearout_Indicator\s+0x[0-9a-f]+\s+(\d+)/mi', $raw, $m)) $r['ssd_wear_pct'] = 100 - (int)$m[1];
    
    
    if (preg_match('/Percentage Used:\s+(\d+)%/i', $raw, $m))            $r['nvme_pct_used'] = (int)$m[1];
    if (preg_match('/Available Spare:\s+(\d+)%/i', $raw, $m))            $r['nvme_spare_pct'] = (int)$m[1];
    if (preg_match('/Available Spare Threshold:\s+(\d+)%/i', $raw, $m))  $r['nvme_spare_threshold'] = (int)$m[1];
    if (preg_match('/Media and Data Integrity Errors:\s+([\d,]+)/i', $raw, $m)) $r['nvme_media_errors'] = (int)str_replace(',', '', $m[1]);
    if (preg_match('/Critical Warning:\s+0x([0-9a-f]+)/i', $raw, $m))    $r['nvme_critical_warning'] = hexdec($m[1]);
    $out[$dev] = $r;
  }
  
  
  
  
  
  foreach ($out as $dev => $r) {
    if (isset($devmap[$dev]) && $devmap[$dev] !== $dev && isset($out[$devmap[$dev]])) unset($out[$dev]);
  }
  ksort($out);
  return $out;
}

function v_smart_tracked(): array {
  $current = v_smart();
  $path = v_state_dir() . '/smart_history.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) $hist = [];
  $today = date('Y-m-d');

  foreach ($current as $dev => $r) {
    $series = $hist[$dev] ?? [];
    
    
    
    if (!isset($series[$today])) {
      $series[$today] = [
        'reallocated' => $r['reallocated'], 'pending' => $r['pending'],
        'crc' => $r['crc'], 'uncorrectable' => $r['uncorrectable'],
      ];
      
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

function v_vm_storage(): array {
  $out = ['vdisks' => [], 'total_virtual' => 0, 'total_actual' => 0,
          'pools' => [], 'libvirt_img' => null, 'overcommit' => null];
  if (!is_executable('/usr/bin/qemu-img')) return $out;

  
  
  
  
  
  
  
  
  
  
  
  $files = [];
  $seen = [];
  $confDir = '/etc/libvirt/qemu';
  foreach (glob($confDir . '/*.xml') ?: [] as $xml) {
    $doc = @file_get_contents($xml);
    if ($doc === false) continue;
    if (preg_match_all('/<source\s+file=(["\'])(.*?)\1/', $doc, $ms)) {
      foreach ($ms[2] as $src) {
        if (!is_file($src)) continue;
        $real = realpath($src) ?: $src;
        $k = @fileinode($real) . ':' . (@stat($real)['dev'] ?? '');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $files[$real] = true;
      }
    }
  }
  if (!$files) {
    foreach (glob('/mnt/*/domains/*/*') ?: [] as $f) {
      if (!preg_match('/\.(img|qcow2|raw)$/i', $f) || !is_file($f)) continue;
      $real = realpath($f) ?: $f;
      $st = @stat($real);
      $k = ($st['ino'] ?? '') . ':' . ($st['dev'] ?? '');
      if (isset($seen[$k])) continue;
      $seen[$k] = true;
      $files[$real] = true;
    }
  }

  foreach (array_keys($files) as $f) {
    $info = v_run('qemu-img info --output=json ' . escapeshellarg($f), 10);
    $j = $info !== '' ? json_decode($info, true) : null;
    $vs = $j['virtual-size'] ?? null;
    $as = $j['actual-size'] ?? null;
    if ($vs === null) { $vs = @filesize($f) ?: null; }
    if ($as === null) { $as = @filesize($f) ?: null; }
    
    
    
    $pool = v_pool_for_path($f);
    $out['vdisks'][] = [
      'file' => $f, 'vm' => basename(dirname($f)), 'format' => $j['format'] ?? null,
      'virtual_size' => $vs, 'actual_size' => $as, 'pool' => $pool,
    ];
    if ($vs !== null) $out['total_virtual'] += $vs;
    if ($as !== null) $out['total_actual'] += $as;
  }

  
  foreach (array_unique(array_filter(array_column($out['vdisks'], 'pool'))) as $pool) {
    $total = @disk_total_space($pool);
    $free  = @disk_free_space($pool);
    $out['pools'][$pool] = $total !== false && $free !== false
      ? ['total' => (float)$total, 'free' => (float)$free]
      : null;
  }

  
  
  
  
  
  foreach ($out['pools'] as $pool => $p) {
    if ($p === null) continue;
    $virtual = 0;
    foreach ($out['vdisks'] as $d) if ($d['pool'] === $pool && $d['virtual_size'] !== null) $virtual += $d['virtual_size'];
    if ($virtual > $p['total']) {
      $out['overcommit'][$pool] = ['virtual' => $virtual, 'pool_total' => $p['total'], 'shortfall' => $virtual - $p['total']];
    }
  }

  
  $libvirt = v_run('grep -h libvirt /boot/config/plugins/dynamix/*.cfg 2>/dev/null', 5);
  $libvirtPath = null;
  if ($libvirt !== '' && preg_match('/libvirt[^\n=]*=\s*([^\n]+)/i', $libvirt, $m)) {
    $cand = trim($m[1], " \"'");
    if (is_file($cand)) $libvirtPath = $cand;
  }
  if ($libvirtPath === null) {
    foreach (glob('/mnt/*/system/libvirt.img') ?: [] as $c) { $libvirtPath = $c; break; }
  }
  if ($libvirtPath !== null && is_file($libvirtPath)) {
    $lsize = @filesize($libvirtPath);
    
    
    
    $total = @disk_total_space('/etc/libvirt');
    $free  = @disk_free_space('/etc/libvirt');
    $out['libvirt_img'] = [
      'file' => $libvirtPath, 'image_size' => $lsize !== false ? $lsize : null,
      'mount_total' => $total !== false ? (float)$total : null,
      'mount_free'  => $free !== false ? (float)$free : null,
      'used_pct'    => ($total !== false && $free !== false && $total > 0)
        ? round(100 * ($total - $free) / $total, 1) : null,
    ];
  }

  return $out;
}

function v_pool_for_path(string $path): ?string {
  static $mounts = null;
  if ($mounts === null) {
    $mounts = [];
    foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
      $f = preg_split('/\s+/', $line);
      if (count($f) >= 3 && str_starts_with($f[1], '/mnt/') && $f[2] !== 'shfs') {
        $mounts[] = stripcslashes($f[1]);
      }
    }
  }

  if (str_starts_with($path, '/mnt/user/') || str_starts_with($path, '/mnt/user0/')) {
    $rel = substr($path, strpos($path, '/', strlen('/mnt/user')) ?: strlen('/mnt/user'));
    $size = @filesize($path);
    foreach ($mounts as $m) {
      $cand = rtrim($m, '/') . '/' . ltrim($rel, '/');
      if (is_file($cand) && @filesize($cand) === $size) return $m;
    }
  }

  $best = null;
  foreach ($mounts as $m) {
    $prefix = rtrim($m, '/') . '/';
    if (str_starts_with($path, $prefix) && ($best === null || strlen($m) > strlen($best))) $best = $m;
  }
  return $best;
}

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

function v_cpu_freq(): array {
  $cores = [];
  foreach (glob('/sys/devices/system/cpu/cpu[0-9]*/cpufreq/scaling_cur_freq') ?: [] as $f) {
    if (!preg_match('#/cpu(\d+)/cpufreq/#', $f, $m)) continue;
    $khz = trim((string)@file_get_contents($f));
    if ($khz === '' || !is_numeric($khz)) continue;
    $dir = dirname($f);
    $minKhz = is_file("$dir/scaling_min_freq") ? (float)file_get_contents("$dir/scaling_min_freq") : null;
    $maxKhz = is_file("$dir/scaling_max_freq") ? (float)file_get_contents("$dir/scaling_max_freq") : null;
    $cores[(int)$m[1]] = [
      'mhz' => round((float)$khz / 1000, 0),
      'min_mhz' => $minKhz !== null ? round($minKhz / 1000, 0) : null,
      'max_mhz' => $maxKhz !== null ? round($maxKhz / 1000, 0) : null,
    ];
  }
  if (!$cores) return ['available' => false, 'cores' => []];
  $mhzVals = array_column($cores, 'mhz');
  return [
    'available' => true, 'cores' => $cores,
    'avg_mhz' => round(array_sum($mhzVals) / count($mhzVals), 0),
    'min_mhz' => min($mhzVals), 'max_mhz' => max($mhzVals),
  ];
}

function v_sensors(): array {
  $base = '/sys/class/hwmon';
  $temps = []; $fans = []; $pwms = []; $volts = [];
  if (!is_dir($base)) return ['temps' => $temps, 'fans' => $fans, 'pwms' => $pwms, 'volts' => $volts];

  foreach (glob($base . '/hwmon*') ?: [] as $dir) {
    $chip = trim((string)@file_get_contents($dir . '/name')) ?: basename($dir);

    foreach (glob($dir . '/temp*_input') ?: [] as $f) {
      if (!preg_match('#/temp(\d+)_input$#', $f, $m)) continue;
      $n = $m[1];
      $raw = trim((string)@file_get_contents($f));
      if ($raw === '' || !is_numeric($raw)) continue;
      $val = (float)$raw / 1000;
      
      
      
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

    
    
    
    
    
    
    
    foreach (glob($dir . '/in*_input') ?: [] as $f) {
      if (!preg_match('#/in(\d+)_input$#', $f, $m)) continue;
      $n = $m[1];
      $raw = trim((string)@file_get_contents($f));
      if ($raw === '' || !is_numeric($raw)) continue;
      $val = round((float)$raw / 1000, 3); 
      $label = trim((string)@file_get_contents("$dir/in{$n}_label")) ?: "$chip in$n";
      $min = is_file("$dir/in{$n}_min") ? round((float)file_get_contents("$dir/in{$n}_min") / 1000, 3) : null;
      $max = is_file("$dir/in{$n}_max") ? round((float)file_get_contents("$dir/in{$n}_max") / 1000, 3) : null;
      $volts[] = [
        'id' => "$chip/in$n", 'chip' => $chip, 'label' => $label,
        'value' => $val, 'min' => $min, 'max' => $max,
      ];
    }
  }

  usort($temps, fn($a, $b) => $b['value'] <=> $a['value']);
  usort($fans, fn($a, $b) => $b['rpm'] <=> $a['rpm']);
  return ['temps' => $temps, 'fans' => $fans, 'pwms' => $pwms, 'volts' => $volts];
}

function v_log_sources(): array {
  return [
    'syslog' => ['label' => 'System (syslog)', 'file' => '/var/log/syslog'],
    
    
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
    
    if ($raw === '' && isset($sources[$source . '2'])) {
      $raw = v_run($sources[$source . '2']['cmd'] . ' 2>/dev/null | tail -n ' . (int)$lines, 8);
    }
  }

  $entries = [];
  foreach (explode("\n", $raw) as $line) {
    if ($line === '') continue;
    
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

function v_docker_hygiene(): array {
  $names = v_run("docker ps -a --format '{{.Names}}'", 8);
  if ($names === '') return ['containers' => [], 'port_conflicts' => [], 'mem_pct' => null];

  
  
  $fmt = '{{.Name}}|{{.RestartCount}}|{{.State.ExitCode}}|{{.State.Status}}|{{.HostConfig.Memory}}'
       . '|{{range $p, $c := .NetworkSettings.Ports}}{{if $c}}{{$p}}={{(index $c 0).HostPort}};{{end}}{{end}}'
       . '|{{range .Mounts}}{{.Source}}:{{.Destination}};{{end}}';
  $raw = v_run("docker inspect --format '" . $fmt . "' " . implode(' ', array_map('escapeshellarg', explode("\n", trim($names)))), 25);

  $out = ['containers' => [], 'port_conflicts' => [], 'mem_pct' => null];
  $portMap = [];

  foreach (explode("\n", $raw) as $line) {
    if (trim($line) === '') continue;
    $parts = explode('|', $line, 7);
    if (count($parts) < 7) continue;
    [$name, $restarts, $exit, $state, $memLimit, $ports, $mounts] = $parts;
    $name = ltrim(trim($name), '/');

    $portList = [];
    foreach (array_filter(explode(';', $ports)) as $p) {
      [$cport, $hport] = array_pad(explode('=', $p, 2), 2, null);
      if ($hport !== null && $hport !== '') {
        $portList[] = ['container' => $cport, 'host' => $hport];
        $portMap[$hport][] = $name;
      }
    }

    $mountList = [];
    $hasUserPath = false;
    foreach (array_filter(explode(';', $mounts)) as $m) {
      $pos = strrpos($m, ':');
      if ($pos === false) continue;
      $src = substr($m, 0, $pos);
      $dst = substr($m, $pos + 1);
      $mountList[] = ['source' => $src, 'destination' => $dst];
      if (str_starts_with($src, '/mnt/user')) $hasUserPath = true;
    }

    $out['containers'][$name] = [
      'name' => $name, 'restart_count' => (int)$restarts, 'exit_code' => (int)$exit,
      'state' => trim($state), 'mem_limit_bytes' => (int)$memLimit,
      'mem_limit_set' => (int)$memLimit > 0, 'ports' => $portList, 'mounts' => $mountList,
      'has_mnt_user_path' => $hasUserPath,
      
      
      'sqlite_paths' => [],
    ];
  }

  
  
  
  
  foreach ($out['containers'] as $name => &$c) {
    if (!$c['has_mnt_user_path']) continue;
    foreach ($c['mounts'] as $m) {
      if (!str_starts_with($m['source'], '/mnt/user') || !is_dir($m['source'])) continue;
      foreach (glob(rtrim($m['source'], '/') . '/*') ?: [] as $f) {
        if (preg_match('/\.(db|sqlite|sqlite3)(-wal|-shm)?$/i', basename($f))) {
          $c['sqlite_paths'][] = $f;
        }
      }
    }
  }
  unset($c);

  
  foreach ($portMap as $hport => $owners) {
    if (count($owners) > 1) {
      $out['port_conflicts'][] = ['host_port' => $hport, 'containers' => array_values(array_unique($owners))];
    }
  }

  
  $mem = v_mem();
  $out['mem_pct'] = $mem['pct'] ?? null;

  return $out;
}

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

function v_docker_layers(): array {
  $raw = v_run("docker ps -a -s --format '{{.Names}}\\t{{.Size}}'", 30);
  $out = [];
  if ($raw === '') return $out;
  foreach (explode("\n", $raw) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 2) continue;
    
    
    
    if (!preg_match('/^([\d.]+\s*[KMGT]?i?B)/', $c[1], $m)) continue;
    $out[$c[0]] = v_parse_size($m[1]);
  }
  return $out;
}

function v_docker_layers_cached(): array {
  $path = v_state_dir() . '/docker_layers.json';
  $cache = v_read_json($path);
  if (($cache['ts'] ?? 0) > time() - 3600) return $cache;
  $layers = v_docker_layers();
  $cache = ['ts' => time(), 'layers' => $layers];
  v_write_json($path, $cache);
  return $cache;
}

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

function v_gpu(): array {
  $smi = null;
  foreach (['/usr/bin/nvidia-smi', '/usr/local/bin/nvidia-smi'] as $p) {
    if (is_executable($p)) { $smi = $p; break; }
  }
  if ($smi === null) return [];
  $q = 'utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,power.limit,name,fan.speed';
  $raw = v_run(escapeshellarg($smi) . " --query-gpu={$q} --format=csv,noheader,nounits", 10);
  
  
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

function v_parity_history(int $limit = 20): array {
  $path = '/boot/config/parity-checks.log';
  if (!is_file($path)) return [];
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $out = [];
  foreach ($lines as $line) {
    $c = explode('|', $line);
    if (count($c) < 4) continue;
    
    
    
    
    $dt = DateTime::createFromFormat('Y M j H:i:s', preg_replace('/\s+/', ' ', trim($c[0])));
    $ts = $dt !== false ? $dt->getTimestamp() : false;
    $elapsed = (int)$c[1];
    
    
    $speedMbps = null;
    if (preg_match('/([\d.]+)\s*(KB|MB|GB)\/s/i', $c[2] ?? '', $m)) {
      $mult = ['KB' => 1 / 1024, 'MB' => 1, 'GB' => 1024][strtoupper($m[2])];
      $speedMbps = round((float)$m[1] * $mult, 1);
    }
    $status = isset($c[3]) && is_numeric($c[3]) ? (int)$c[3] : null;
    $errors = isset($c[4]) && is_numeric($c[4]) ? (int)$c[4] : null;
    
    
    
    
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
  
  
  return array_slice($out, -$limit);
}

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

function v_array_disk_mounts(): array {
  $out = [];
  foreach (glob('/mnt/disk*') ?: [] as $p) {
    $name = basename($p);
    if (preg_match('/^disk\d+$/', $name) && is_dir($p)) $out[] = $name;
  }
  sort($out, SORT_NATURAL);
  return $out;
}

function v_share_placement(): array {
  $shares = v_ini('/var/local/emhttp/shares.ini');
  $arrayDisks = v_array_disk_mounts();
  $out = [];
  foreach ($shares as $name => $s) {
    if (!is_array($s)) continue;
    $pool = $s['useCache'] ?? 'no';
    $stray = [];
    if ($pool === 'only') {
      
      foreach ($arrayDisks as $disk) {
        $p = '/mnt/' . $disk . '/' . $name;
        if (is_dir($p)) $stray[] = ['disk' => $disk, 'path' => $p, 'expected' => 'cache'];
      }
    } elseif ($pool === 'no') {
      
      $p = '/mnt/cache/' . $name;
      if (is_dir($p)) $stray[] = ['disk' => 'cache', 'path' => $p, 'expected' => 'array'];
    }
    if ($stray) $out[$name] = ['pool' => $pool, 'stray' => $stray];
  }
  return $out;
}

function v_share_placement_cached(): array {
  $path = v_state_dir() . '/share_placement.json';
  $cache = v_read_json($path);
  if (($cache['ts'] ?? 0) > time() - 3600) return $cache['conflicts'] ?? [];
  $conflicts = v_share_placement();
  v_write_json($path, ['ts' => time(), 'conflicts' => $conflicts]);
  return $conflicts;
}

function v_rapl_energy_uj(): ?array {
  $zones = @glob('/sys/class/powercap/intel-rapl:[0-9]*') ?: [];
  $total = 0.0; $max = 0.0; $found = false;
  foreach ($zones as $z) {
    
    
    if (!preg_match('#/intel-rapl:\d+$#', $z)) continue;
    $e = @file_get_contents($z . '/energy_uj');
    if ($e === false) continue;
    $total += (float)trim($e);
    $max += (float)(@file_get_contents($z . '/max_energy_range_uj') ?: '0');
    $found = true;
  }
  return $found ? ['energy_uj' => $total, 'max_uj' => $max] : null;
}

function v_power(?array $prevRapl, float $elapsed, array $gpu, array $ups): array {
  $cpuWatts = null;
  $rapl = v_rapl_energy_uj();
  if ($rapl && $prevRapl && $elapsed > 0) {
    $delta = $rapl['energy_uj'] - $prevRapl['energy_uj'];
    
    
    
    
    if ($delta < 0 && $rapl['max_uj'] > 0) $delta += $rapl['max_uj'];
    if ($delta >= 0) $cpuWatts = round(($delta / 1e6) / $elapsed, 1);
  }

  $gpuWatts = null;
  foreach ($gpu as $g) {
    if (($g['power'] ?? null) !== null) $gpuWatts = ($gpuWatts ?? 0) + $g['power'];
  }

  $upsWatts = null; $upsPct = null;
  if (isset($ups['NOMPOWER']) && isset($ups['LOADPCT'])) {
    $nom = (float)preg_replace('/[^0-9.]/', '', $ups['NOMPOWER']);
    $pct = (float)preg_replace('/[^0-9.]/', '', $ups['LOADPCT']);
    if ($nom > 0) { $upsWatts = round($nom * $pct / 100, 1); $upsPct = $pct; }
  }

  $parts = [];
  $total = 0.0;
  if ($cpuWatts !== null) { $parts[] = 'cpu'; $total += $cpuWatts; }
  if ($gpuWatts !== null) { $parts[] = 'gpu'; $total += $gpuWatts; }
  
  
  
  if ($upsWatts !== null) { $parts = ['ups']; $total = $upsWatts; }

  return [
    'cpu_watts' => $cpuWatts,
    'gpu_watts' => $gpuWatts,
    'ups_watts' => $upsWatts,
    'ups_load_pct' => $upsPct,
    'total_watts' => $parts ? round($total, 1) : null,
    'total_includes' => $parts,
    'rapl_available' => $rapl !== null,
    '_raw_rapl' => $rapl,
  ];
}

function v_net_read(): array {
  $out = [];
  foreach (@file('/proc/net/dev') ?: [] as $l) {
    if (!preg_match('/^\s*([^:]+):\s*(.*)$/', $l, $m)) continue;
    $if = trim($m[1]);
    if ($if === 'lo') continue;
    $v = preg_split('/\s+/', trim($m[2]));
    
    
    $out[$if] = [
      'rx' => (int)$v[0], 'tx' => (int)$v[8],
      'rx_errs' => (int)($v[2] ?? 0), 'rx_drop' => (int)($v[3] ?? 0),
      'tx_errs' => (int)($v[10] ?? 0), 'tx_drop' => (int)($v[11] ?? 0), 'tx_colls' => (int)($v[13] ?? 0),
    ];
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
                 'rx_total' => $n['rx'], 'tx_total' => $n['tx'],
                 'rx_errs_delta' => max(0, ($n['rx_errs'] ?? 0) - ($prev[$if]['rx_errs'] ?? 0)),
                 'rx_drop_delta' => max(0, ($n['rx_drop'] ?? 0) - ($prev[$if]['rx_drop'] ?? 0)),
                 'tx_errs_delta' => max(0, ($n['tx_errs'] ?? 0) - ($prev[$if]['tx_errs'] ?? 0)),
                 'tx_drop_delta' => max(0, ($n['tx_drop'] ?? 0) - ($prev[$if]['tx_drop'] ?? 0)),
                 'tx_colls_delta' => max(0, ($n['tx_colls'] ?? 0) - ($prev[$if]['tx_colls'] ?? 0))];
  }
  return $out;
}

function v_disk_io_delta(array $now, array $prev, float $seconds): array {
  if ($seconds <= 0) $seconds = 1;
  $out = [];
  foreach ($now as $name => $n) {
    if (!isset($prev[$name])) continue;
    $readSectors = max(0, $n['reads_sectors'] - $prev[$name]['reads_sectors']);
    $writeSectors = max(0, $n['writes_sectors'] - $prev[$name]['writes_sectors']);
    $readOps = max(0, $n['reads_ops'] - $prev[$name]['reads_ops']);
    $writeOps = max(0, $n['writes_ops'] - $prev[$name]['writes_ops']);
    $out[$name] = [
      'read_bps' => round($readSectors * 512 / $seconds, 0),
      'write_bps' => round($writeSectors * 512 / $seconds, 0),
      'read_iops' => round($readOps / $seconds, 1),
      'write_iops' => round($writeOps / $seconds, 1),
    ];
  }
  return $out;
}

function v_net_link_info(): array {
  $out = [];
  $ifaces = @glob('/sys/class/net/*') ?: [];
  $bestPath = v_state_dir() . '/net_best_speed.json';
  $best = v_read_json($bestPath);
  if (!is_array($best)) $best = [];

  foreach ($ifaces as $path) {
    $if = basename($path);
    if ($if === 'lo') continue;
    $speedRaw = @file_get_contents($path . '/speed');
    $speed = $speedRaw !== false ? (int)trim($speedRaw) : -1;
    $duplex = trim(@file_get_contents($path . '/duplex') ?: '');
    $mtu = (int)trim(@file_get_contents($path . '/mtu') ?: '0');
    $carrier = trim(@file_get_contents($path . '/carrier') ?: '');
    $operstate = trim(@file_get_contents($path . '/operstate') ?: '');

    if ($speed > 0) {
      if (!isset($best[$if]) || $speed > $best[$if]) $best[$if] = $speed;
    }

    $slaves = [];
    if (is_readable($path . '/bonding/slaves')) {
      $slaves = preg_split('/\s+/', trim(@file_get_contents($path . '/bonding/slaves') ?: ''), -1, PREG_SPLIT_NO_EMPTY);
    } elseif (is_dir($path . '/brif')) {
      $slaves = array_map('basename', array_filter(@glob($path . '/brif/*') ?: [], 'is_dir'));
    }

    $out[$if] = [
      'speed_mbps' => $speed > 0 ? $speed : null, 'duplex' => $duplex ?: null,
      'mtu' => $mtu ?: null, 'carrier' => $carrier === '1', 'operstate' => $operstate ?: null,
      'best_speed_seen' => $best[$if] ?? null, 'members' => $slaves,
    ];
  }

  v_write_json($bestPath, $best);
  return $out;
}

function v_flash_health(): array {
  $out = [
    'mount' => '/boot', 'total' => null, 'free' => null, 'used_pct' => null,
    'read_only' => null, 'mount_opts' => null,
    'last_backup' => null, 'backup_age_days' => null,
    'our_writes_today' => null, 'our_writes_per_day' => null,
  ];

  
  
  foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $f = preg_split('/\s+/', $line);
    if (count($f) < 4 || $f[1] !== '/boot') continue;
    $opts = $f[3];
    $out['mount_opts'] = $opts;
    $out['read_only'] = in_array('ro', explode(',', $opts), true);
    break;
  }
  $total = @disk_total_space('/boot');
  $free  = @disk_free_space('/boot');
  if ($total !== false && $free !== false && $total > 0) {
    $out['total'] = (float)$total;
    $out['free']  = (float)$free;
    $out['used_pct'] = round(100 * ($total - $free) / $total, 1);
  }

  
  $keepalive = '/boot/config/plugins/dynamix.my.servers/fb_keepalive';
  $stamp = @file_get_contents($keepalive);
  $ts = $stamp !== false ? strtotime(trim($stamp)) : false;
  if ($ts === false) {
    $mt = @filemtime($keepalive);
    $ts = $mt !== false ? $mt : false;
  }
  if ($ts !== false && $ts > 0) {
    $out['last_backup'] = $ts;
    $out['backup_age_days'] = round((time() - $ts) / 86400, 1);
  }

  
  $w = v_flash_writes_series();
  $out['our_writes_today'] = $w['today'];
  $out['our_writes_per_day'] = $w['per_day'];

  return $out;
}

function v_flash_writes_track(): void {
  $path = v_state_dir() . '/flash_writes.json';
  $hist = v_read_json($path);
  if (!is_array($hist)) $hist = [];
  $day = date('Y-m-d');
  $hist[$day] = ($hist[$day] ?? 0) + 1;
  if (count($hist) > 30) {
    ksort($hist);
    $hist = array_slice($hist, -30, null, true);
  }
  v_write_json($path, $hist);
}

function v_flash_writes_series(): array {
  $hist = v_read_json(v_state_dir() . '/flash_writes.json');
  if (!is_array($hist)) return ['today' => 0, 'per_day' => null];
  $today = $hist[date('Y-m-d')] ?? 0;
  $vals = array_values(array_filter($hist, 'is_numeric'));
  return ['today' => $today, 'per_day' => $vals ? round(array_sum($vals) / count($vals), 1) : null];
}

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

function v_mount_fill(string $path): array {
  $total = @disk_total_space($path);
  $free  = @disk_free_space($path);
  if ($total === false || $free === false || $total <= 0) {
    return ['path' => $path, 'total' => null, 'free' => null, 'used_pct' => null, 'largest' => []];
  }
  $used = $total - $free;
  $pct = round(($used / $total) * 100, 1);
  
  
  
  $largest = $pct >= 70 ? v_largest_files($path, 5) : [];
  return ['path' => $path, 'total' => (int)$total, 'free' => (int)$free, 'used_pct' => $pct, 'largest' => $largest];
}

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

function v_fs_watch(): array {
  return [
    'rootfs'   => v_mount_fill('/'),
    'var_log'  => v_mount_fill('/var/log'),
    'tmp'      => v_mount_fill('/tmp'),
    'run'      => v_mount_fill('/run'),
  ];
}

function v_collect(?array $prev = null, float $elapsed = 60.0): array {
  $cpuNow = v_cpu_stat();
  $netNow = v_net_read();
  $gpuNow = v_gpu();
  $upsNow = v_ups();
  $snap = [
    'time'    => time(),
    'system'  => v_system(),
    'cpu'     => ['cores' => [], 'total' => null],
    'cpu_topology' => v_cpu_topology(),
    'cpu_freq' => v_cpu_freq(),
    'sensors' => v_sensors(),
    'mem'     => v_mem(),
    'load'    => v_load(),
    'array'   => v_array_disks(),
    'spin_analysis' => [],
    'parity_history' => v_parity_history(20),
    'rootfs'  => v_rootfs(),
    'fs_watch' => v_fs_watch(),
    'syslog_matches' => v_syslog_scan(),
    'smart'   => v_smart_tracked(),
    'vms'     => v_vms(),
    'vm_storage' => v_vm_storage(),
    'docker'  => v_docker(),
    'docker_image' => v_docker_image(),
    'docker_hygiene' => v_docker_hygiene(),
    'docker_layers' => v_docker_layers_cached(),
    'docker_logs' => v_docker_logs_cached(),
    'gpu'     => $gpuNow,
    'ups'     => $upsNow,
    'power'   => v_power($prev['_raw']['rapl'] ?? null, $elapsed, $gpuNow, $upsNow),
    'shares'  => v_shares(),
    'pool_health' => v_pool_health(),
    'share_placement' => v_share_placement_cached(),
    'net'     => [],
    'net_link' => v_net_link_info(),
    'flash'   => v_flash_health(),
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

  
  
  
  $diskIoNow = [];
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['parity'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    if (isset($d['io_reads_sectors'])) {
      $diskIoNow[$d['name']] = [
        'reads_sectors' => $d['io_reads_sectors'], 'writes_sectors' => $d['io_writes_sectors'],
        'reads_ops' => $d['io_reads_ops'] ?? 0, 'writes_ops' => $d['io_writes_ops'] ?? 0,
      ];
    }
  }
  $diskIoRate = ($prev && !empty($prev['_raw']['disk_io']))
    ? v_disk_io_delta($diskIoNow, $prev['_raw']['disk_io'], $elapsed) : [];
  foreach (['data', 'parity', 'cache'] as $grp) {
    foreach ($snap['array'][$grp] ?? [] as $i => $d) {
      if (isset($diskIoRate[$d['name']])) $snap['array'][$grp][$i]['io'] = $diskIoRate[$d['name']];
    }
  }

  $snap['_raw'] = ['cpu' => $cpuNow, 'net' => $netNow, 'rapl' => $snap['power']['_raw_rapl'] ?? null, 'disk_io' => $diskIoNow];
  unset($snap['power']['_raw_rapl']);

  
  
  
  $allDisks = array_merge($snap['array']['data'] ?? [], $snap['array']['parity'] ?? [], $snap['array']['cache'] ?? []);
  v_spin_track($allDisks);
  $snap['spin_analysis'] = v_spin_analysis();

  return $snap;
}
