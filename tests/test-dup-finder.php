<?php
/**
 * unraid-vitals — standalone test for the duplicate-file finder logic
 * (P15-06). Exercises the same size -> head/tail hash -> full hash
 * pipeline as scripts/vitals-dup-scan.php, against real temp files, then
 * verifies the acceptance criterion: two copies of the same large file on
 * different "disks" are reported as ONE group.
 *
 * This does not shell out to the real script (which walks /mnt/user and
 * needs actual array disks) -- it re-implements the same three-stage
 * algorithm inline against a controlled temp-file layout, which is the
 * part with real logic worth testing. The script itself was exercised
 * live against Selene's real shares in this session.
 */

$tmp = sys_get_temp_dir() . '/vitals-dup-test-' . getmypid();
@mkdir($tmp . '/disk1/Movies', 0755, true);
@mkdir($tmp . '/disk2/Movies', 0755, true);
@mkdir($tmp . '/disk1/Unique', 0755, true);

function cleanup(string $dir): void {
  foreach (glob($dir . '/*') ?: [] as $f) is_dir($f) ? cleanup($f) : @unlink($f);
  @rmdir($dir);
}

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

// A ~1 MiB "1 GB file" stand-in (identical content) on two different
// disks -- big enough to exercise the head+tail-then-full-hash pipeline
// meaningfully without actually writing 2 GB of test data.
$payload = str_repeat('A', 200000) . str_repeat('B', 200000) . random_bytes(100000) . str_repeat('C', 200000);
file_put_contents($tmp . '/disk1/Movies/movie.mkv', $payload);
file_put_contents($tmp . '/disk2/Movies/movie.mkv', $payload);   // identical copy, different disk
file_put_contents($tmp . '/disk1/Unique/other.mkv', $payload . 'X');   // same size after padding? no -- deliberately different size
file_put_contents($tmp . '/disk1/Unique/similar-head.mkv', substr($payload, 0, 200000) . random_bytes(300000) . str_repeat('C', 200000));  // same size, same head, different middle -- must NOT be grouped with the real dupe

// Inline re-implementation of the scan's core algorithm (size -> head/tail hash -> full hash).
function scanDupes(array $files): array {
  $bySize = [];
  foreach ($files as $f) $bySize[filesize($f)][] = $f;
  $candidates = array_filter($bySize, fn($p) => count($p) >= 2);

  $headTailHash = function (string $path): ?string {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $head = fread($fh, 65536);
    $size = fstat($fh)['size'] ?? 0;
    if ($size > 131072) fseek($fh, -65536, SEEK_END);
    $tail = $size > 65536 ? fread($fh, 65536) : '';
    fclose($fh);
    return md5($head . $tail);
  };

  $groups = [];
  foreach ($candidates as $size => $paths) {
    $byHt = [];
    foreach ($paths as $p) { $ht = $headTailHash($p); if ($ht !== null) $byHt[$ht][] = $p; }
    foreach ($byHt as $sameHt) {
      if (count($sameHt) < 2) continue;
      $byFull = [];
      foreach ($sameHt as $p) { $full = hash_file('md5', $p); $byFull[$full][] = $p; }
      foreach ($byFull as $full => $same) {
        if (count($same) < 2) continue;
        $groups[] = ['size' => $size, 'files' => $same];
      }
    }
  }
  return $groups;
}

$allFiles = [
  $tmp . '/disk1/Movies/movie.mkv', $tmp . '/disk2/Movies/movie.mkv',
  $tmp . '/disk1/Unique/other.mkv', $tmp . '/disk1/Unique/similar-head.mkv',
];
// Note: other.mkv is 1 byte larger -- won't be a size candidate at all.
// similar-head.mkv is the SAME size as movie.mkv (deliberately) and
// shares the same head 64 KiB, so it survives the head/tail stage but
// must be filtered out by the full-hash stage.
$sameSizeAsMovie = strlen(file_get_contents($tmp . '/disk1/Unique/similar-head.mkv')) === strlen($payload);
check($sameSizeAsMovie, 'similar-head.mkv is deliberately the same size as the real duplicate (tests full-hash filtering)');

$groups = scanDupes($allFiles);

check(count($groups) === 1, 'exactly one duplicate group found, got ' . count($groups));
if (count($groups) === 1) {
  $g = $groups[0];
  check(count($g['files']) === 2, 'the group has exactly 2 files (the real duplicate pair), got ' . count($g['files']));
  check(in_array($tmp . '/disk1/Movies/movie.mkv', $g['files'], true) && in_array($tmp . '/disk2/Movies/movie.mkv', $g['files'], true),
    'the group contains both disk1 and disk2 copies — two copies on different disks reported as ONE group (acceptance criterion)');
  check(!in_array($tmp . '/disk1/Unique/similar-head.mkv', $g['files'], true),
    'the same-size, same-head-but-different-content file is correctly excluded by the full-hash stage');
}

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
