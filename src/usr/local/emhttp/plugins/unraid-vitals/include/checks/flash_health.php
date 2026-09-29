<?php
/**
 * unraid-vitals — checks/flash_health.php (P14-12)
 *
 * Reads $snap['flash'] (collect.php's v_flash_health()): /boot free space,
 * read-only remount state, last flash-backup age, and this plugin's own
 * flash-write rate.
 */

function v_check_flash_health(array $snap): array {
  $out = [];
  $f = $snap['flash'] ?? [];
  if (!$f) return $out;

  // ---- read-only remount. This is the acceptance criterion and deserves
  // to be the loudest thing here: a read-only /boot means Unraid cannot
  // persist any config change, and if it stays that way the next reboot
  // comes up with stale/broken state.
  if (($f['read_only'] ?? null) === true) {
    $out[] = [
      'severity' => 'critical', 'subject' => 'flash_ro',
      'title' => 'Flash drive (/boot) is mounted read-only',
      'detail' => '/boot is currently mounted read-only (mount options: ' . ($f['mount_opts'] ?? '?')
        . '). Nothing written to the Unraid config can persist while this is the case -- the array'
        . ' config, docker templates, and share settings all live on this drive. This is usually the'
        . ' kernel remounting the flash read-only after a write error, which often means the USB stick'
        . ' itself is starting to fail. Back up the flash contents and replace it if it recurs.',
      'evidence' => [
        ['label' => 'mount', 'value' => $f['mount'] ?? '/boot'],
        ['label' => 'mount_opts', 'value' => $f['mount_opts'] ?? null],
      ],
      'fix_id' => null,
    ];
  }

  // ---- free space. Unraid's flash drive is small (a few GB) and holds
  // the whole config tree plus plugin/docker-template history; a full /boot
  // breaks every config write the same way a read-only remount does.
  $pct = $f['used_pct'] ?? null;
  if ($pct !== null && $pct >= 85) {
    $out[] = [
      'severity' => $pct >= 95 ? 'critical' : 'alert', 'subject' => 'flash_space',
      'title' => 'Flash drive (/boot) is ' . $pct . '% full',
      'detail' => '/boot is ' . $pct . '% full (' . v_bytes((float)($f['free'] ?? 0))
        . ' free of ' . v_bytes((float)($f['total'] ?? 0)) . '). A full flash drive cannot write'
        . ' config changes and can break the next boot. Old diagnostics bundles under /boot/logs and'
        . ' stale plugin archives in /boot/config/plugins are the usual culprits.',
      'evidence' => [
        ['label' => 'used_pct', 'value' => $pct],
        ['label' => 'free_bytes', 'value' => $f['free'] ?? null],
        ['label' => 'total_bytes', 'value' => $f['total'] ?? null],
      ],
      'fix_id' => null,
    ];
  }

  // ---- flash backup age. Not actionable as a hard failure, but a backup
  // older than a month is the thing people regret after the USB stick dies.
  $age = $f['backup_age_days'] ?? null;
  if ($age !== null && $age > 30) {
    $out[] = [
      'severity' => 'warning', 'subject' => 'flash_backup',
      'title' => 'Flash backup is ' . round($age) . ' days old',
      'detail' => 'The flash backup (Unraid Connect keepalive) is ' . round($age) . ' days old. If the'
        . ' USB stick fails without a recent backup, the whole array configuration is lost and has to'
        . ' be rebuilt by hand.',
      'evidence' => [
        ['label' => 'backup_age_days', 'value' => $age],
        ['label' => 'last_backup_ts', 'value' => $f['last_backup'] ?? null],
      ],
      'fix_id' => null,
    ];
  }
  // No backup signal at all (file absent) -> say so rather than silently pass.
  if (($f['last_backup'] ?? null) === null && ($f['backup_age_days'] ?? null) === null) {
    $out[] = [
      'severity' => 'warning', 'subject' => 'flash_backup',
      'title' => 'No flash backup detected',
      'detail' => 'No flash-backup keepalive file was found, so the age of the last flash backup could'
        . ' not be determined. If Unraid Connect flash backup is not enabled, the array config exists'
        . ' only on the USB stick itself.',
      'evidence' => [['label' => 'mount', 'value' => $f['mount'] ?? '/boot']],
      'fix_id' => null,
    ];
  }

  return $out;
}
