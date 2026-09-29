<?php
/**
 * unraid-vitals — checks/docker_hygiene.php (P14-13)
 *
 * Reads $snap['docker_hygiene'] (collect.php's v_docker_hygiene()):
 * per-container restart count/exit code/memory limit/ports/mounts, plus
 * host-port conflicts between containers and the host's memory use.
 */

function v_check_docker_hygiene(array $snap): array {
  $out = [];
  $h = $snap['docker_hygiene'] ?? [];
  if (!$h) return $out;

  // ---- restart loops. A container the daemon keeps restarting is the
  // acceptance criterion; report its restart count AND last exit code, as
  // asked for.
  foreach ($h['containers'] ?? [] as $c) {
    $rc = $c['restart_count'] ?? 0;
    if ($rc >= 3) {
      $out[] = [
        'severity' => $rc >= 10 ? 'alert' : 'warning', 'subject' => 'docker_restart',
        'title' => $c['name'] . ': restarted ' . $rc . ' times (last exit code ' . ($c['exit_code'] ?? '?') . ')',
        'detail' => 'Container ' . $c['name'] . ' has been restarted ' . $rc . ' times'
          . ' (current state: ' . ($c['state'] ?? '?') . ', last exit code ' . ($c['exit_code'] ?? '?')
          . '). A rising restart count means the container is crash-looping: it starts, fails, and the'
          . ' restart policy brings it back. Check its logs for the actual failure.',
        'evidence' => [
          ['label' => 'container', 'value' => $c['name']],
          ['label' => 'restart_count', 'value' => $rc],
          ['label' => 'last_exit_code', 'value' => $c['exit_code'] ?? null],
          ['label' => 'state', 'value' => $c['state'] ?? null],
        ],
        'fix_id' => null,
      ];
    }
  }

  // ---- host port conflicts.
  foreach ($h['port_conflicts'] ?? [] as $pc) {
    $out[] = [
      'severity' => 'alert', 'subject' => 'docker_port_conflict',
      'title' => 'Host port ' . $pc['host_port'] . ' claimed by ' . count($pc['containers']) . ' containers',
      'detail' => 'Host port ' . $pc['host_port'] . ' is mapped by more than one container ('
        . implode(', ', $pc['containers']) . '). Only one can actually bind it -- the others will fail'
        . ' to start or silently lose the port, which often shows up much later as an unreachable service.',
      'evidence' => [
        ['label' => 'host_port', 'value' => $pc['host_port']],
        ['label' => 'containers', 'value' => implode(', ', $pc['containers'])],
      ],
      'fix_id' => null,
    ];
  }

  // ---- SQLite behind a /mnt/user mount. Only when BOTH hold: the
  // container has a /mnt/user path AND a SQLite file is actually visible
  // there (v_docker_hygiene() only populates sqlite_paths in that case).
  foreach ($h['containers'] ?? [] as $c) {
    if (empty($c['sqlite_paths'])) continue;
    $out[] = [
      'severity' => 'warning', 'subject' => 'docker_sqlite_user',
      'title' => $c['name'] . ': SQLite database behind a /mnt/user path',
      'detail' => 'Container ' . $c['name'] . ' has a SQLite database reachable through /mnt/user ('
        . implode(', ', $c['sqlite_paths']) . '). /mnt/user is a FUSE shim over the array, and SQLite'
        . ' relies on file locking that FUSE does not implement reliably -- this is a well-known cause'
        . ' of "database is locked" errors and corruption. Point the container at the pool/disk path'
        . ' directly (e.g. /mnt/cache/appdata/...) instead.',
      'evidence' => [
        ['label' => 'container', 'value' => $c['name']],
        ['label' => 'sqlite_paths', 'value' => implode(', ', $c['sqlite_paths'])],
      ],
      'fix_id' => null,
    ];
  }

  // ---- no memory limit while the host is under memory pressure. Only
  // flagged when pressure is real (>=85%); on a box with plenty of RAM an
  // unlimited container is a normal, deliberate choice and not noise worth
  // generating.
  $memPct = $h['mem_pct'] ?? null;
  if ($memPct !== null && $memPct >= 85) {
    foreach ($h['containers'] ?? [] as $c) {
      if (!empty($c['mem_limit_set'])) continue;
      if (($c['state'] ?? '') !== 'running') continue;
      $out[] = [
        'severity' => 'warning', 'subject' => 'docker_no_mem_limit',
        'title' => $c['name'] . ': running without a memory limit while host RAM is ' . $memPct . '% used',
        'detail' => 'Container ' . $c['name'] . ' is running with no memory limit while the host is at '
          . $memPct . '% memory use. An unbounded container is the usual cause of the kernel OOM killer'
          . ' taking out something unrelated. A limit would contain the damage.',
        'evidence' => [
          ['label' => 'container', 'value' => $c['name']],
          ['label' => 'host_mem_pct', 'value' => $memPct],
        ],
        'fix_id' => null,
      ];
    }
  }

  return $out;
}
