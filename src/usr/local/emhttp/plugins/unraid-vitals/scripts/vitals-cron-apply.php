#!/usr/bin/php
<?php
/* unraid-vitals — scripts/vitals-cron-apply.php (P18-01 helper)
 * Applies the schedule registry to /etc/cron.d (install.sh --reapply calls
 * this; settings UI can call the same ajax action later).
 */
declare(strict_types=1);
require '/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php';
require '/usr/local/emhttp/plugins/unraid-vitals/include/store.php';
// install.sh exports NODE_BIN + LLM_*/VITALS_*_CFG before calling us.
$envVars = [];
foreach (['NODE_BIN', 'LLM_PRIMARY_CFG', 'LLM_BACKUP_CFG', 'DIAG_INTERVAL_CFG',
          'DIAG_WINDOW_CFG', 'DIAG_MODELS_CFG', 'UPDATE_INTERVAL_CFG'] as $k) {
  if (getenv($k) !== false) $envVars[$k] = (string)getenv($k);
}
$res = v_sched_apply('/var/tmp/unraid-vitals', '/boot/config/plugins/unraid-vitals', $envVars);
fwrite(STDOUT, 'cron-apply: applied=' . $res['applied'] . ' removed=' . $res['removed'] . ' skipped=' . $res['skipped'] . "\n");
exit(0);