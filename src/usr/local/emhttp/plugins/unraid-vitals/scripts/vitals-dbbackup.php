#!/usr/bin/php
<?php
/* unraid-vitals — scripts/vitals-dbbackup.php: daily consistent backup + retention. */
declare(strict_types=1);
require '/usr/local/emhttp/plugins/unraid-vitals/include/dbbackup.php';
$res = v_backup_run();
fwrite(STDOUT, 'vitals-dbbackup: ' . json_encode($res) . "\n");
exit($res['ok'] ?? false ? 0 : 1);