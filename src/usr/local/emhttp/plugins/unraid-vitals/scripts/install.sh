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
* * * * * root /usr/bin/php $PLUGDIR/scripts/vitals-collect.php --quiet >> $STATE/collector.log 2>&1
CRONEOF

# honour a non-default interval by editing the minute field
if [ "$INTERVAL" != "1" ]; then
  sed -i "s|^\* \* \* \* \*|*/$INTERVAL * * * *|" "$CRON"
fi
chmod 644 "$CRON"
cp -f "$CRON" "$FLASH/collector.cron" 2>/dev/null || true

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
17 4 * * * root /usr/bin/php $FLASH/prune.php >/dev/null 2>&1
PRUNECRON
cp -f /etc/cron.d/${PLUGIN}-prune "$FLASH/prune.cron" 2>/dev/null || true
chmod 644 /etc/cron.d/${PLUGIN}-prune

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
echo " Dashboard page : /Vitals"
echo " Dashboard tile : add it from the dashboard's tile picker"
echo " Settings       : /Settings/VitalsSettings"
echo " Collector      : every $INTERVAL minute(s), log in $STATE"
echo "-------------------------------------------------------------"
echo ""
