#!/bin/bash
# unraid-vitals — post-install. Runs on the server at install/update time.
set -u

PLUGIN=unraid-vitals
PLUGDIR=/usr/local/emhttp/plugins/$PLUGIN
STATE=/var/tmp/$PLUGIN
FLASH=/boot/config/plugins/$PLUGIN

mkdir -p "$STATE" "$FLASH/history"
chmod 755 "$STATE" 2>/dev/null

# --- configuration defaults (never clobber an existing value, but DO backfill
#     any key introduced by a later version so upgrades don't silently miss it) ---
if [ ! -f "$FLASH/vitals.cfg" ]; then
  touch "$FLASH/vitals.cfg"
fi
while IFS='=' read -r key val; do
  [ -z "$key" ] && continue
  grep -q "^${key}=" "$FLASH/vitals.cfg" || echo "${key}=${val}" >> "$FLASH/vitals.cfg"
done <<'DEFAULTS'
INTERVAL="1"
KEEP_DAYS="90"
SET_STARTPAGE="no"
ALERT_TEMP="55"
ALERT_FILL="90"
ALERT_LOAD="0"
ALERT_RESTARTS="3"
DATA_DIR=""
LLM_STUDIO_PRIMARY=""
LLM_STUDIO_BACKUP=""
DEFAULTS

# --- collector cron ----------------------------------------------------------
# Unraid's /etc/cron.d/... is restored from flash each boot, but we also keep a
# copy on flash so a fresh boot reinstates it without a reinstall.
CRON=/etc/cron.d/$PLUGIN
mkdir -p /etc/cron.d
INTERVAL=$(grep -oP '^INTERVAL="?\K[0-9]+' "$FLASH/vitals.cfg" 2>/dev/null || echo 1)
[ -z "$INTERVAL" ] && INTERVAL=1

cat > "$CRON" <<CRONEOF
# unraid-vitals collector — installed by $PLUGIN.plg
# Unraid's cron.d format has NO user field (unlike Debian's) — check
# /etc/cron.d/root: five time fields, then the command directly.
* * * * * /usr/bin/php $PLUGDIR/scripts/vitals-collect.php --quiet >> $STATE/collector.log 2>&1
CRONEOF

# honour a non-default interval by editing the minute field
if [ "$INTERVAL" != "1" ]; then
  sed -i "s|^\* \* \* \* \*|*/$INTERVAL * * * *|" "$CRON"
fi
chmod 644 "$CRON"
cp -f "$CRON" "$FLASH/collector.cron" 2>/dev/null || true

# --- checks engine cron -------------------------------------------------------
# Every 5 minutes, independent of the collector's own cadence: most checks
# (disk fill, temperature trends) don't need per-minute resolution, and
# running the checks engine less often keeps its DB writes off the collector's
# critical path.
CHECKS_CRON=/etc/cron.d/${PLUGIN}-checks
cat > "$CHECKS_CRON" <<CHECKSCRONEOF
# unraid-vitals checks engine — installed by $PLUGIN.plg
*/5 * * * * /usr/bin/php $PLUGDIR/scripts/vitals-checks.php --quiet >> $STATE/checks.log 2>&1
CHECKSCRONEOF
chmod 644 "$CHECKS_CRON"
cp -f "$CHECKS_CRON" "$FLASH/checks.cron" 2>/dev/null || true

# --- storage analyzer cron (P15-05) --------------------------------------------
# Nightly at 03:10 (after most other maintenance windows), low I/O priority
# (nice/ionice inside the script itself) — a full `du` pass across every
# share is far too heavy to run more than once a day.
STORAGE_CRON=/etc/cron.d/${PLUGIN}-storage
cat > "$STORAGE_CRON" <<STORAGECRONEOF
# unraid-vitals storage analyzer — installed by $PLUGIN.plg
10 3 * * * /usr/bin/php $PLUGDIR/scripts/vitals-storage-scan.php --quiet >> $STATE/storage-scan.log 2>&1
STORAGECRONEOF
chmod 644 "$STORAGE_CRON"
cp -f "$STORAGE_CRON" "$FLASH/storage.cron" 2>/dev/null || true

# --- duplicate file finder cron (P15-06) ---------------------------------------
# Weekly, not nightly — hashing every file over 1 MiB across every share is
# considerably heavier than the storage analyzer's single `du` pass, so this
# runs once a week (Sunday 04:00) rather than competing with the nightly
# storage scan for the same low-I/O-priority window.
DUP_CRON=/etc/cron.d/${PLUGIN}-dupscan
cat > "$DUP_CRON" <<DUPCRONEOF
# unraid-vitals duplicate file finder — installed by $PLUGIN.plg
0 4 * * 0 /usr/bin/php $PLUGDIR/scripts/vitals-dup-scan.php --quiet >> $STATE/dup-scan.log 2>&1
DUPCRONEOF
chmod 644 "$DUP_CRON"
cp -f "$DUP_CRON" "$FLASH/dupscan.cron" 2>/dev/null || true

# --- weekly health report cron (P15-10) ----------------------------------------
# Monday 08:00 -- after both the nightly storage scan and the weekly dup
# scan's own slot, so the report reflects the freshest data from both.
WEEKLY_CRON=/etc/cron.d/${PLUGIN}-weekly
cat > "$WEEKLY_CRON" <<WEEKLYCRONEOF
# unraid-vitals weekly health report — installed by $PLUGIN.plg
0 8 * * 1 /usr/bin/php $PLUGDIR/scripts/vitals-weekly-report.php --quiet >> $STATE/weekly-report.log 2>&1
WEEKLYCRONEOF
chmod 644 "$WEEKLY_CRON"
cp -f "$WEEKLY_CRON" "$FLASH/weekly.cron" 2>/dev/null || true

# --- flash retention script --------------------------------------------------
cat > "$FLASH/prune.php" <<'PRUNE'
<?php
// Delete rollup files older than KEEP_DAYS. Called once a day.
$cfg = @parse_ini_file('/boot/config/plugins/unraid-vitals/vitals.cfg') ?: [];
$keep = (int)($cfg['KEEP_DAYS'] ?? 90);
$dir  = '/boot/config/plugins/unraid-vitals/history';
if ($keep < 1 || !is_dir($dir)) exit(0);
$cutoff = strtotime('-' . $keep . ' days');
foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
  if (preg_match('/(\d{4})-(\d{2})\.jsonl$/', $f, $m)) {
    // keep a month file if any day in it is still inside the window
    $monthEnd = strtotime($m[1] . '-' . $m[2] . '-01 +1 month');
    if ($monthEnd < $cutoff) @unlink($f);
  }
}
PRUNE

# daily prune cron
cat > /etc/cron.d/${PLUGIN}-prune <<PRUNECRON
# unraid-vitals retention
17 4 * * * /usr/bin/php $FLASH/prune.php >/dev/null 2>&1
PRUNECRON
cp -f /etc/cron.d/${PLUGIN}-prune "$FLASH/prune.cron" 2>/dev/null || true
chmod 644 /etc/cron.d/${PLUGIN}-prune

# --- background AI agents cron ------------------------------------------------
# Runs the SmythOS/Ollama analysis suite hourly. flock guards against overlap:
# a single run can legitimately take several minutes on CPU-only inference
# hardware (see agent/lib/smythos-client.mjs), so a slow hour must not stack
# a second run on top of it.
#
# LLM_STUDIO_PRIMARY/LLM_STUDIO_BACKUP in vitals.cfg (empty by default —
# smythos-client.mjs's own hardcoded remote defaults apply until set) let a
# user point the agents at their own Ollama-compatible endpoint instead of
# the developer's remote one, per ca_profile.xml's disclosure of what "runs
# by default" actually means.
NODE_BIN="$(command -v node 2>/dev/null || true)"
if [ -n "$NODE_BIN" ] && [ -d "$PLUGDIR/agent/node_modules" ]; then
  LLM_PRIMARY_CFG=$(grep -oP '^LLM_STUDIO_PRIMARY="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "")
  LLM_BACKUP_CFG=$(grep -oP '^LLM_STUDIO_BACKUP="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "")
  # Deep-scan tunables (P24: configurable scan interval, default 6h/360m —
  # analyze.mjs's own isDueForRun() gate enforces this using the last
  # completed run time, independent of the hourly cron cadence below, so
  # changing these in Settings takes effect on the very next hourly tick
  # with no cron file rewrite needed for the interval itself).
  DIAG_INTERVAL_CFG=$(grep -oP '^VITALS_DIAG_INTERVAL_MINUTES="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "360")
  DIAG_WINDOW_CFG=$(grep -oP '^VITALS_DIAG_WINDOW_HOURS="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "6")
  DIAG_MODELS_CFG=$(grep -oP '^VITALS_DIAG_MODELS="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "")
  UPDATE_INTERVAL_CFG=$(grep -oP '^VITALS_UPDATE_INTERVAL_MINUTES="?\K[^"]*' "$FLASH/vitals.cfg" 2>/dev/null || echo "360")
  cat > /etc/cron.d/${PLUGIN}-agents <<AGENTCRON
# unraid-vitals background AI agents — installed by $PLUGIN.plg
7 * * * * LLM_STUDIO_PRIMARY="$LLM_PRIMARY_CFG" LLM_STUDIO_BACKUP="$LLM_BACKUP_CFG" VITALS_DIAG_INTERVAL_MINUTES="$DIAG_INTERVAL_CFG" VITALS_DIAG_WINDOW_HOURS="$DIAG_WINDOW_CFG" VITALS_DIAG_MODELS="$DIAG_MODELS_CFG" VITALS_UPDATE_INTERVAL_MINUTES="$UPDATE_INTERVAL_CFG" /usr/bin/flock -n $STATE/agents.lock $NODE_BIN $PLUGDIR/agent/analyze.mjs >> $STATE/agents.log 2>&1
AGENTCRON
  chmod 644 /etc/cron.d/${PLUGIN}-agents
  cp -f /etc/cron.d/${PLUGIN}-agents "$FLASH/agents.cron" 2>/dev/null || true

  # --- VM event listener cron --------------------------------------------------
  # Diffs libvirt VM state every 2 minutes and records a kb_event only on an
  # actual transition (see agent/vmwatch.mjs) — no LLM call, cheap enough to
  # run far more often than the LLM-driven agents so state changes are
  # caught close to when they happen, which matters for "what happened to
  # VM X" questions to actually have timestamps worth answering with.
  cat > /etc/cron.d/${PLUGIN}-vmwatch <<VMWATCHCRON
# unraid-vitals VM event listener — installed by $PLUGIN.plg
*/2 * * * * /usr/bin/flock -n $STATE/vmwatch.lock $NODE_BIN $PLUGDIR/agent/vmwatch.mjs >> $STATE/vmwatch.log 2>&1
VMWATCHCRON
  chmod 644 /etc/cron.d/${PLUGIN}-vmwatch
  cp -f /etc/cron.d/${PLUGIN}-vmwatch "$FLASH/vmwatch.cron" 2>/dev/null || true

  # --- study-mode ticker cron -------------------------------------------------
  # Advances every standing "study the system for N hours" job: takes a
  # sample if a job is due (per its own tick_minutes), and synthesizes a
  # final report for any job whose window has closed. Runs every 5 min so a
  # tick_minutes=5 study is actually honored; each individual job's own
  # dueStudyJobs() check is what keeps this from over-sampling.
  cat > /etc/cron.d/${PLUGIN}-study <<STUDYCRON
# unraid-vitals study-mode ticker — installed by $PLUGIN.plg
*/5 * * * * LLM_STUDIO_PRIMARY="$LLM_PRIMARY_CFG" LLM_STUDIO_BACKUP="$LLM_BACKUP_CFG" /usr/bin/flock -n $STATE/study.lock $NODE_BIN $PLUGDIR/agent/study.mjs >> $STATE/study.log 2>&1
STUDYCRON
  chmod 644 /etc/cron.d/${PLUGIN}-study
  cp -f /etc/cron.d/${PLUGIN}-study "$FLASH/study.cron" 2>/dev/null || true
else
  rm -f /etc/cron.d/${PLUGIN}-agents "$FLASH/agents.cron" /etc/cron.d/${PLUGIN}-study "$FLASH/study.cron" \
        /etc/cron.d/${PLUGIN}-vmwatch "$FLASH/vmwatch.cron" 2>/dev/null || true
  echo "unraid-vitals: node/agent deps not found — background AI agents disabled (run 'cd $PLUGDIR/agent && npm install' to enable)"
fi

# --- apply start-page preference --------------------------------------------
# Two-way: "yes" points START_PAGE at Vitals; "no" only reverts it if WE set it,
# never touching a start page the user chose themselves.
DYN=/boot/config/plugins/dynamix/dynamix.cfg
if [ -f "$DYN" ]; then
  if grep -q '^SET_STARTPAGE="yes"' "$FLASH/vitals.cfg" 2>/dev/null; then
    if ! grep -q '^START_PAGE=' "$DYN"; then
      sed -i '/^\[display\]/a START_PAGE="Vitals"' "$DYN"
    else
      sed -i 's/^START_PAGE=.*/START_PAGE="Vitals"/' "$DYN"
    fi
  elif grep -q '^START_PAGE="Vitals"' "$DYN"; then
    sed -i 's/^START_PAGE=.*/START_PAGE="Main"/' "$DYN"
  fi
fi

# --- first sample so the UI is never empty ----------------------------------
/usr/bin/php "$PLUGDIR/scripts/vitals-collect.php" --quiet >/dev/null 2>&1 || true

# Settings-page re-apply: config written by update.htm, cron + start page now
# match it; no banner needed.
if [ "${1:-}" = "--reapply" ]; then
  echo "unraid-vitals: settings applied (interval ${INTERVAL}m)"
  exit 0
fi

echo ""
echo "-------------------------------------------------------------"
echo " $PLUGIN installed"
echo " Dashboard page : /Vitals (Settings is a tab on this page)"
echo " Dashboard tile : add it from the dashboard's tile picker"
echo " Collector      : every $INTERVAL minute(s), log in $STATE"
echo "-------------------------------------------------------------"
echo ""
