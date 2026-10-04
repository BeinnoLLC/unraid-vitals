<?php
/* unraid-vitals — include/diagnostics.php (P17-03 / #86)
 *
 * Diagnostics bundle for support threads:
 *  1. Trigger Unraid's diagnostics (emhttp's diagnostics script) — the plugin
 *     runs the bundle maker and hands the .zip back as a download.
 *  2. A Vitals summary: current findings, last 24 h of key metrics, recent
 *     signature matches.
 *  3. An anonymized text version for forums: share names, hostnames, IPs,
 *     disk serials masked.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/checks.php';

/** Anonymizer: masks hostnames, IPs, serials, share names, pool names. */
function v_diag_anonymize(string $text): string {
  $host = gethostname() ?: 'server';
  // hostname (case-insensitive, word-ish boundary)
  $text = str_ireplace($host, '[SERVER]', $text);
  // IPv4 (keep class/loopback shape recognizable but mask octets 2-4)
  $text = preg_replace_callback(
    '/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/',
    function ($m) {
      if ($m[1] === '127' && $m[2] === '0') return $m[0]; // loopback stays
      return $m[1] . '.x.x.x';
    }, $text);
  // IPv6 (mask everything but prefix)
  $text = preg_replace('/\b([0-9a-f]{1,4}(:[0-9a-f]{0,4}){2})[0-9a-f:]*/i', '$1:…', $text);
  // disk serials — only in real serial contexts, never bare CAPS words
  // (the first version turned 'STARTED' into [SERIAL] — caught by the leak test)
  $text = preg_replace('/\b((W[CD]|S[NXY])[A-Z0-9_-]{5,14})\b/', '[SERIAL]', $text);
  $text = preg_replace('/(Serial Number:|S\/N:)\s*[A-Za-z0-9_-]+/', '$1 [SERIAL]', $text);
  $text = preg_replace('/\b(serial|wwn)([=:])\s*[A-Za-z0-9_-]+/i', '$1$2 [SERIAL]', $text);
  // share names — array-side mounts and path tails too ('/mnt/cache/Backups'
  // with a trailing '.' was a live leak), then a word-boundary pass for any
  // remaining mention (covers 'appdata.live' folder names).
  foreach ((@parse_ini_file('/var/local/emhttp/shares.ini', true) ?: []) as $name => $s) {
    if (!is_string($name) || strlen($name) < 4 || !is_array($s)) continue; // ≥4 chars: no 'system'/'tmp' collisions with English words
    foreach (['/mnt/user/' . $name, '/mnt/user0/' . $name, '/mnt/cache/' . $name, '/mnt/' . $name . '/', '/mnt/disk' . $name] as $mount) {
      $text = str_ireplace($mount, str_replace($name, '[SHARE]', $mount), $text);
    }
    $text = str_ireplace('share ' . $name, 'share [SHARE]', $text);
    $text = str_ireplace('"' . $name . '"', '"[SHARE]"', $text);
    // word-boundary catch-all for tail-period and folder-name forms
    $text = preg_replace('/\b(' . preg_quote($name, '/') . ')\b(?!\.db)/i', '[SHARE]', $text);
  }
  // user names on the box
  $text = preg_replace('/\b(root|nginx|nobody|www-data)\b/', '$1', $text); // system users stay
  return $text;
}

/** Vitals summary (anonymized text form) for pasting into a forum post. */
function v_diag_forum_summary(bool $anonymize = true): string {
  $snap = v_collect();
  $findings = v_checks_run($snap);
  $lines = [];
  $lines[] = '--- unraid-vitals summary (v' . (is_file('/usr/local/emhttp/plugins/unraid-vitals/VERSION') ? trim((string)file_get_contents('/usr/local/emhttp/plugins/unraid-vitals/VERSION')) : '?') . ') — host [SERVER] ---';
  $lines[] = 'Unraid: ' . ($snap['system']['version'] ?? '?') . ' | Kernel: ' . ($snap['system']['kernel'] ?? '?');
  $lines[] = 'CPU: ' . ($snap['system']['cpu'] ?? '?') . ' | ' . ($snap['load']['cores'] ?? '?') . ' cores';
  $lines[] = 'Memory: ' . ($snap['mem']['pct'] ?? '?') . '% used | Array state: ' . ($snap['system']['md_state'] ?? '?') . ' | uptime: ' . round(($snap['system']['uptime'] ?? 0) / 86400, 1) . ' days';
  // last 24h key metrics from the rollup
  $rollup = [];
  $histFile = '/boot/config/plugins/unraid-vitals/history/' . gmdate('Y-m') . '.jsonl';
  if (is_file($histFile)) {
    $rows = @file($histFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $win = array_slice($rows, -24);
    foreach ($win as $r) {
      $j = json_decode($r, true);
      if (!$j) continue;
      $rollup[] = ['cpu_avg' => $j['cpu_avg'] ?? null, 'mem_avg' => $j['mem_avg'] ?? null,
                   'temp_max' => $j['temp_max'] ?? null, 'load_max' => $j['load_max'] ?? null];
    }
  }
  if ($rollup) {
    $memVals = array_filter(array_column($rollup, 'mem_avg'));
    $lines[] = 'Last 24 h (hourly rollups): avg CPU ' . round(array_sum(array_filter(array_column($rollup, 'cpu_avg'))) / max(1, count(array_filter($rollup, function ($r) { return ($r['cpu_avg'] ?? null) !== null; }))), 1)
      . '% | avg Mem ' . round(array_sum($memVals) / max(1, count($memVals)), 1)
      . '% | peak temp ' . (max(array_filter(array_column($rollup, 'temp_max'))) ?: '—');
  }
  foreach ($findings as $f) {
    $lines[] = '[' . strtoupper($f['severity'] ?? 'info') . '] ' . ($f['title'] ?? '?')
      . (isset($f['detail']) ? ' — ' . substr($f['detail'], 0, 160) : '');
  }
  $text = implode("\n", $lines);
  return $anonymize ? v_diag_anonymize($text) : $text;
}

/** Trigger Unraid's own diagnostics bundle; return the zip path (or null). */
function v_diag_unraid_bundle(): ?string {
  // Unraid 6.9+: /usr/local/emhttp/plugins/dynamix/scripts/diagnostics
  // (or /usr/local/sbin/diagnostics wrapper) writes .zip to /boot/logs/.
  $bin = is_executable('/usr/local/emhttp/plugins/dynamix/scripts/diagnostics')
    ? '/usr/local/emhttp/plugins/dynamix/scripts/diagnostics'
    : (is_executable('/usr/local/sbin/diagnostics') ? '/usr/local/sbin/diagnostics' : null);
  if (!$bin) return null;
  @shell_exec('timeout 300 ' . $bin . ' > /dev/null 2>&1');
  $zips = array_merge(glob('/boot/logs/*diagnostics*.zip') ?: [], glob('/boot/logs/diagnostics-*.zip') ?: []);
  usort($zips, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
  $zip = $zips[0] ?? null;
  return $zip && @file_exists($zip) ? $zip : null;
}