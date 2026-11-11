#!/usr/bin/php
<?php

declare(strict_types=1);
require '/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php';
require '/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$envVars = [];
foreach (['NODE_BIN', 'LLM_PRIMARY_CFG', 'LLM_BACKUP_CFG', 'DIAG_INTERVAL_CFG',
          'DIAG_WINDOW_CFG', 'DIAG_MODELS_CFG', 'UPDATE_INTERVAL_CFG'] as $k) {
  if (getenv($k) !== false) $envVars[$k] = (string)getenv($k);
}
$res = v_sched_apply('/var/tmp/unraid-vitals', '/boot/config/plugins/unraid-vitals', $envVars);
fwrite(STDOUT, 'cron-apply: applied=' . $res['applied'] . ' removed=' . $res['removed'] . ' skipped=' . $res['skipped'] . "\n");
exit(0);