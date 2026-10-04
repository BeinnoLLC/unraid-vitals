<?php
/* unraid-vitals check: system_maintenance — OS/plugin updates, clock sync, TRIM.
 * Acceptance (P14-15): a box with NTP disabled raises the finding; SSDs without
 * a TRIM schedule raise one; a stale update-check timestamp raises one.
 *
 * All signals are read from the box itself, no network calls:
 *  - updates:  /var/local/emhttp/var.ini reg*Check timestamps + update-check
 *              service files; Unraid updates its own "last checked" markers.
 *  - clock:    /boot/config/network.cfg USE_NTP + rc.ntpd running state.
 *  - TRIM:     /etc/cron.d/* + crontab -l for a fstrim/ssd_trim line, only when
 *              non-rotational disks exist (lsblk ROTA=0, excluding loop/zram).
 */

declare(strict_types=1);

/**
 * @param array $snap full snapshot (unused keys tolerated)
 * @return array[] list of findings (may be empty)
 */
function v_check_system_maintenance(array $snap): array {
  $findings = [];

  // --- updates -----------------------------------------------------------------
  // regCheck / regFlashCheck in var.ini: unix ts of last update check. A very
  // stale value (or disabled check) means the user runs blind on updates.
  $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
  $now = time();
  foreach (['regCheck' => 'system update check', 'regFlashCheck' => 'flash drive update check'] as $key => $label) {
    $ts = isset($var[$key]) ? (int)$var[$key] : 0;
    if ($ts === 0) {
      $findings[] = [
        'check_id' => 'system_maintenance',
        'severity' => 'info',
        'subject'  => 'updates',
        'title'    => ucfirst($label) . ' has never run or is disabled',
        'detail'   => "No timestamp for {$label} ({$key} unset). Enable periodic update checks or review updates manually.",
        'fix_id'   => 'updates_enable_check',
        'evidence' => ['key' => $key, 'value' => null],
      ];
    } else {
      $ageDays = ($now - $ts) / 86400;
      if ($ageDays > 60) {
        $findings[] = [
          'check_id' => 'system_maintenance',
          'severity' => 'warning',
          'subject'  => 'updates',
          'title'    => ucfirst($label) . ' last ran ' . (int)$ageDays . ' days ago',
          'detail'   => "Update checks are stale (>60 days). Unraid may have security fixes you have not seen.",
          'fix_id'   => 'updates_enable_check',
          'evidence' => ['key' => $key, 'last_checked' => $ts, 'age_days' => round($ageDays, 1)],
        ];
      }
    }
  }

  // --- clock sync ---------------------------------------------------------------
  // USE_NTP lives in ident.cfg on Unraid (network.cfg carries the bond/bridging
  // settings), so read both: ident.cfg wins, network.cfg kept for older setups.
  // Paths come from constants (overridable in tests) with real defaults.
  $useNtp = false;
  $identPath = defined('VITALS_IDENT_CFG') ? VITALS_IDENT_CFG : '/boot/config/ident.cfg';
  $netPath = defined('VITALS_NET_CFG') ? VITALS_NET_CFG : '/boot/config/network.cfg';
  $cfg = null;
  foreach ([$identPath, $netPath] as $cfgFile) {
    $cfg = @parse_ini_file($cfgFile) ?: [];
    if (array_key_exists('USE_NTP', $cfg)) {
      $useNtp = strtolower((string)$cfg['USE_NTP']) === 'yes';
      break;
    }
  }
  if (!$useNtp) {
    $findings[] = [
      'check_id' => 'system_maintenance',
      'severity' => 'warning',
      'subject'  => 'clock',
      'title'    => 'NTP time sync is disabled',
      'detail'   => 'USE_NTP is off. Logs, certificates and parity checks all rely on a correct clock — enable NTP under Settings → Date and Time.',
      'fix_id'   => 'clock_enable_ntp',
      'evidence' => ['USE_NTP' => isset($cfg['USE_NTP']) ? $cfg['USE_NTP'] : null],
    ];
  } else {
    $ntpdRunning = false;
    $lines = @file('/proc/uptime') ? @explode("\n", (string)@shell_exec('ps -o comm= -C ntpd 2>/dev/null')) : [];
    // ps may be unavailable; fall back to /proc scan.
    if (is_array($lines) && array_filter($lines)) {
      $ntpdRunning = true;
    } else {
      foreach (glob('/proc/[0-9]*/comm') ?: [] as $commFile) {
        if (@file_get_contents($commFile) === 'ntpd' . "\n") { $ntpdRunning = true; break; }
      }
    }
    if (!$ntpdRunning) {
      $findings[] = [
        'check_id' => 'system_maintenance',
        'severity' => 'warning',
        'subject'  => 'clock',
        'title'    => 'NTP is configured but ntpd is not running',
        'detail'   => 'USE_NTP=yes in network.cfg but no ntpd process. The clock will drift and timestamps in logs/findings become unreliable.',
        'fix_id'   => 'clock_enable_ntp',
        'evidence' => ['USE_NTP' => 'yes', 'ntpd' => 'not found'],
      ];
    }
  }

  // --- TRIM schedule for SSDs ----------------------------------------------------
  $ssds = [];
  $out = @shell_exec('timeout 10 lsblk -d -n -o NAME,ROTA,TYPE 2>/dev/null');
  foreach ($out !== null ? explode("\n", trim($out)) : [] as $line) {
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) < 3) continue;
    [$name, $rota, $type] = [$parts[0], $parts[1], $parts[2]];
    if ($rota === '0' && in_array($type, ['disk', 'mmc'], true)) {
      $ssds[] = $name;
    }
  }
  if ($ssds) {
    $cronText = '';
    foreach (glob('/etc/cron.d/*') ?: [] as $f) {
      $cronText .= (string)@file_get_contents($f);
    }
    $cronText .= (string)@shell_exec('timeout 5 crontab -l 2>/dev/null');
    $hasTrim = (bool)preg_match('/(fstrim|ssd_trim)/i', $cronText);
    if (!$hasTrim) {
      $findings[] = [
        'check_id' => 'system_maintenance',
        'severity' => 'warning',
        'subject'  => 'ssd_trim',
        'title'    => 'SSDs present but no TRIM schedule',
        'detail'   => 'Non-rotational disks found (' . implode(', ', array_slice($ssds, 0, 5)) .
                      ') and no fstrim/ssd_trim cron entry. Wear and write amplification increase without periodic TRIM.',
        'fix_id'   => 'ssd_trim_schedule',
        'evidence' => ['ssds' => $ssds, 'trim_found' => false],
      ];
    }
  }

  return $findings;
}