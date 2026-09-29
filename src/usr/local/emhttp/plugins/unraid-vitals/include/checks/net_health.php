<?php
/**
 * unraid-vitals — checks/net_health.php (P14-11)
 *
 * Reads $snap['net'] (per-interface error/drop/collision deltas from
 * v_net_delta()) and $snap['net_link'] (per-interface speed/duplex/mtu
 * from v_net_link_info()).
 */

function v_check_net_health(array $snap): array {
  $out = [];
  $net = $snap['net'] ?? [];
  $link = $snap['net_link'] ?? [];

  // ---- errors/drops/collisions, as deltas since the last collection run.
  foreach ($net as $if => $n) {
    $errs = ($n['rx_errs_delta'] ?? 0) + ($n['tx_errs_delta'] ?? 0);
    $drops = ($n['rx_drop_delta'] ?? 0) + ($n['tx_drop_delta'] ?? 0);
    $colls = $n['tx_colls_delta'] ?? 0;
    if ($errs > 0) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'net_errors',
        'title' => $if . ': ' . $errs . ' network error(s)',
        'detail' => 'Interface ' . $if . ' reported ' . $errs . ' rx/tx error(s) since the last check.'
          . ' Usually a cabling, NIC driver, or switch port problem.',
        'evidence' => [['label' => 'interface', 'value' => $if], ['label' => 'errors', 'value' => $errs]],
        'fix_id' => null,
      ];
    }
    if ($drops > 0) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'net_drops',
        'title' => $if . ': ' . $drops . ' dropped packet(s)',
        'detail' => 'Interface ' . $if . ' dropped ' . $drops . ' packet(s) since the last check'
          . ' -- often a sign of buffer exhaustion or a receiver that cannot keep up.',
        'evidence' => [['label' => 'interface', 'value' => $if], ['label' => 'drops', 'value' => $drops]],
        'fix_id' => null,
      ];
    }
    if ($colls > 0) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'net_collisions',
        'title' => $if . ': ' . $colls . ' transmit collision(s)',
        'detail' => 'Interface ' . $if . ' reported ' . $colls . ' transmit collision(s). Collisions'
          . ' are essentially unheard of on switched full-duplex Ethernet -- this usually means a'
          . ' duplex mismatch or a genuine half-duplex/hub segment.',
        'evidence' => [['label' => 'interface', 'value' => $if], ['label' => 'collisions', 'value' => $colls]],
        'fix_id' => null,
      ];
    }
  }

  // ---- negotiated speed regression: a port that has previously linked at
  // a higher speed than it currently reports (see v_net_link_info()'s
  // best_speed_seen tracking -- this correctly handles genuinely
  // 100Mb-only ports, which never have a higher speed on record).
  foreach ($link as $if => $l) {
    if (($l['carrier'] ?? false) !== true) continue; // no cable/no link -- nothing to compare
    $speed = $l['speed_mbps'] ?? null;
    $best = $l['best_speed_seen'] ?? null;
    if ($speed !== null && $best !== null && $speed < $best) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'net_speed',
        'title' => $if . ' linked at ' . $speed . ' Mb, previously seen at ' . $best . ' Mb',
        'detail' => 'Interface ' . $if . ' is currently negotiated at ' . $speed . ' Mbps, but has'
          . ' previously linked at ' . $best . ' Mbps. A cable, switch port, or NIC problem can force'
          . ' a lower negotiated speed even when the hardware is capable of more.',
        'evidence' => [
          ['label' => 'interface', 'value' => $if], ['label' => 'current_speed_mbps', 'value' => $speed],
          ['label' => 'best_speed_seen_mbps', 'value' => $best],
        ],
        'fix_id' => null,
      ];
    }

    // ---- MTU mismatch inside a bond or bridge.
    foreach ($l['members'] ?? [] as $member) {
      if (!isset($link[$member])) continue;
      if (($link[$member]['mtu'] ?? null) !== null && $l['mtu'] !== null && $link[$member]['mtu'] !== $l['mtu']) {
        $out[] = [
          'severity' => 'warning', 'subject' => 'net_mtu',
          'title' => 'MTU mismatch: ' . $member . ' (' . $link[$member]['mtu'] . ') inside ' . $if . ' (' . $l['mtu'] . ')',
          'detail' => 'Member interface ' . $member . ' has MTU ' . $link[$member]['mtu'] . ', but its'
            . ' bond/bridge ' . $if . ' has MTU ' . $l['mtu'] . '. A mismatched MTU inside a bond or'
            . ' bridge can cause silent packet fragmentation or drops for larger frames.',
          'evidence' => [
            ['label' => 'parent', 'value' => $if], ['label' => 'parent_mtu', 'value' => $l['mtu']],
            ['label' => 'member', 'value' => $member], ['label' => 'member_mtu', 'value' => $link[$member]['mtu']],
          ],
          'fix_id' => null,
        ];
      }
    }
  }

  return $out;
}
