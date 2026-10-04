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

# --- SRE vault bootstrap (P13-01) -------------------------------------------
# @smythos/sre demands $HOME/.smyth/vault.json before any agent entrypoint
# will run. On Unraid /root is tmpfs: wiped each boot. Empty-provider vault —
# the agents never read credentials from it (they talk to a locally configured
# Ollama-compatible endpoint), so nothing secret is stored.
VAULT="$HOME/.smyth/vault.json"
if [ ! -f "$VAULT" ]; then
  mkdir -p "$(dirname "$VAULT")"
  printf '{"default":{"echo":"","openai":"","anthropic":"","googleai":"","groq":"","togetherai":"","xai":""}}\n' > "$VAULT"
  chmod 600 "$VAULT"
  echo "unraid-vitals: created SRE vault stub at $VAULT (agents never store secrets in it)"
fi

# --- agent deps bootstrap (P13-01) -------------------------------------------
# The txz ships agent/ source without node_modules (386 MB does not belong in
# a plugin payload). npm ci runs once here so cron jobs pointing at agent
# entrypoints don't die with MODULE_NOT_FOUND. NODE_BIN feeds the registry.
NODE_BIN="$(command -v node 2>/dev/null || true)"
if [ -n "$NODE_BIN" ] && command -v npm >/dev/null 2>&1 && [ ! -d "$PLUGDIR/agent/node_modules" ] && [ -f "$PLUGDIR/agent/package.json" ]; then
  echo "unraid-vitals: installing agent deps (one-time, may take a minute)…"
  (cd "$PLUGDIR/agent" && npm ci --no-audit --no-fund --loglevel=error) \
    && echo "unraid-vitals: agent deps installed" \
    || echo "unraid-vitals: npm ci failed — background AI agents disabled until manually run"
fi

# flash retention script (KEEP_DAYS prune) — payload file, not a cron job;
# its cron file comes from the registry below.
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

# --- scheduled jobs (P18-01: single registry) ---------------------------------
# Every /etc/cron.d/unraid-vitals-* file is written from the schedule registry
# (include/schedule_registry.php) via scripts/vitals-cron-apply.php. User
# overrides live in vitals.cfg as SCHED_<JOB> (5-field cron) and
# SCHED_<JOB>_ENABLED=0; registry apply also removes files for jobs that no
# longer exist (glob cleanup). Adding a job = one registry entry + reapply.
mkdir -p /etc/cron.d
cfgval() { grep -oP "^$1=\"?\\K[^\"]*" "$FLASH/vitals.cfg" 2>/dev/null || true; }
LLM_PRIMARY_CFG="$(cfgval LLM_STUDIO_PRIMARY)"
LLM_BACKUP_CFG="$(cfgval LLM_STUDIO_BACKUP)"
DIAG_INTERVAL_CFG="$(cfgval VITALS_DIAG_INTERVAL_MINUTES)"
[ -z "$DIAG_INTERVAL_CFG" ] && DIAG_INTERVAL_CFG=360
DIAG_WINDOW_CFG="$(cfgval VITALS_DIAG_WINDOW_HOURS)"
[ -z "$DIAG_WINDOW_CFG" ] && DIAG_WINDOW_CFG=6
DIAG_MODELS_CFG="$(cfgval VITALS_DIAG_MODELS)"
UPDATE_INTERVAL_CFG="$(cfgval VITALS_UPDATE_INTERVAL_MINUTES)"
[ -z "$UPDATE_INTERVAL_CFG" ] && UPDATE_INTERVAL_CFG=360
INTERVAL="$(cfgval INTERVAL)"
[ -z "$INTERVAL" ] && INTERVAL=1
export LLM_PRIMARY_CFG LLM_BACKUP_CFG DIAG_INTERVAL_CFG DIAG_WINDOW_CFG DIAG_MODELS_CFG UPDATE_INTERVAL_CFG NODE_BIN
/usr/bin/php "$PLUGDIR/scripts/vitals-cron-apply.php" || echo "unraid-vitals: cron apply failed"

# --- dashboard tile ----------------------------------------------------------
# The compact dashboard tile (Menu="Dashboard") ships again as of 2026.10.04 —
# user decision #44: keep the top-level navbar tab AND restore the tile. The
# payload overwrites any legacy copy on upgrade; no deletion needed here.

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
echo " Navbar tab     : /Vitals (top-level tab, Settings is a tab on this page)"
echo " Collector      : every $INTERVAL minute(s), log in $STATE"
echo "-------------------------------------------------------------"
echo ""
