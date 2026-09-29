<?php
/**
 * unraid-vitals — checks/vm_storage.php (P14-14)
 *
 * Reads $snap['vm_storage'] (collect.php's v_vm_storage()).
 */

function v_check_vm_storage(array $snap): array {
  $out = [];
  $s = $snap['vm_storage'] ?? [];
  if (!$s) return $out;

  // ---- per-vdisk: allocated (virtual) vs actually used on disk. A large
  // gap is not itself a fault -- thin/sparse images are a legitimate,
  // useful thing -- so this only reports when the gap is big enough to be
  // worth knowing about, and always states both numbers.
  foreach ($s['vdisks'] ?? [] as $d) {
    $vs = $d['virtual_size'] ?? null;
    $as = $d['actual_size'] ?? null;
    if ($vs === null || $as === null || $vs <= 0) continue;
    $slack = $vs - $as;
    if ($slack > 0 && $slack / $vs >= 0.25) {
      $out[] = [
        'severity' => 'info', 'subject' => 'vm_vdisk_slack',
        'title' => $d['vm'] . ' vdisk: ' . v_bytes((float)$as) . ' on disk of ' . v_bytes((float)$vs) . ' allocated',
        'detail' => 'VM ' . $d['vm'] . '\'s vdisk (' . $d['file'] . ', ' . ($d['format'] ?? '?') . ' format)'
          . ' is allocated ' . v_bytes((float)$vs) . ' but only ' . v_bytes((float)$as) . ' is actually used'
          . ' on ' . ($d['pool'] ?? 'its pool') . ' -- ' . v_bytes((float)$slack) . ' of slack. This is'
          . ' informational: it is how thin provisioning is supposed to work. It becomes a problem only'
          . ' if the guest fills the disk while the pool has no room for the growth.',
        'evidence' => [
          ['label' => 'vm', 'value' => $d['vm']], ['label' => 'file', 'value' => $d['file']],
          ['label' => 'virtual_size', 'value' => $vs], ['label' => 'actual_size', 'value' => $as],
        ],
        'fix_id' => null,
      ];
    }
  }

  // ---- overcommit: the acceptance criterion. Total VIRTUAL size of the
  // vdisks on a pool exceeding that pool's total capacity, reported with
  // the shortfall.
  foreach ($s['overcommit'] ?? [] as $pool => $oc) {
    $out[] = [
      'severity' => 'alert', 'subject' => 'vm_overcommit',
      'title' => 'VM storage overcommitted on ' . $pool . ' by ' . v_bytes((float)($oc['shortfall'] ?? 0)),
      'detail' => 'The vdisks on ' . $pool . ' are allocated ' . v_bytes((float)($oc['virtual'] ?? 0))
        . ' in total, but the pool is only ' . v_bytes((float)($oc['pool_total'] ?? 0)) . ' -- a shortfall'
        . ' of ' . v_bytes((float)($oc['shortfall'] ?? 0)) . '. Every guest believes it can write its'
        . ' full allocation, so if those disks actually fill up the pool runs out of space and the VMs'
        . ' fail. Either thin-provision, move a vdisk to another pool, or grow this one.',
      'evidence' => [
        ['label' => 'pool', 'value' => $pool],
        ['label' => 'virtual_total', 'value' => $oc['virtual'] ?? null],
        ['label' => 'pool_total', 'value' => $oc['pool_total'] ?? null],
        ['label' => 'shortfall_bytes', 'value' => $oc['shortfall'] ?? null],
      ],
      'fix_id' => null,
    ];
  }

  // ---- libvirt.img usage: the VM config image. If it fills, libvirt can
  // no longer save VM definitions.
  $li = $s['libvirt_img'] ?? null;
  if ($li !== null && ($li['used_pct'] ?? null) !== null && $li['used_pct'] >= 80) {
    $out[] = [
      'severity' => $li['used_pct'] >= 95 ? 'alert' : 'warning', 'subject' => 'vm_libvirt_img',
      'title' => 'libvirt.img is ' . $li['used_pct'] . '% used',
      'detail' => 'The libvirt config image (' . $li['file'] . ') is ' . $li['used_pct'] . '% used'
        . ' (' . v_bytes((float)($li['mount_free'] ?? 0)) . ' free of ' . v_bytes((float)($li['mount_total'] ?? 0))
        . '). If it fills, libvirt cannot save VM definitions and changes to VMs start failing.',
      'evidence' => [
        ['label' => 'file', 'value' => $li['file']],
        ['label' => 'used_pct', 'value' => $li['used_pct']],
        ['label' => 'image_size', 'value' => $li['image_size'] ?? null],
      ],
      'fix_id' => null,
    ];
  }

  return $out;
}
