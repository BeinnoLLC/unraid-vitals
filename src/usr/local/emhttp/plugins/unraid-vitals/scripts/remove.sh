#!/bin/bash
# unraid-vitals — remove. Cleans up everything the plugin created, EXCEPT the
# history rollups on flash and the knowledge-base DB in appdata (deliberately
# kept so a reinstall keeps your data).
set -u

PLUGIN=unraid-vitals
STATE=/var/tmp/$PLUGIN
FLASH=/boot/config/plugins/$PLUGIN

rm -f /etc/cron.d/$PLUGIN /etc/cron.d/${PLUGIN}-prune /etc/cron.d/${PLUGIN}-agents /etc/cron.d/${PLUGIN}-checks /etc/cron.d/${PLUGIN}-study /etc/cron.d/${PLUGIN}-vmwatch
rm -rf /usr/local/emhttp/plugins/$PLUGIN
rm -rf "$STATE"
rm -f "$FLASH/prune.php" "$FLASH/collector.cron" "$FLASH/prune.cron" "$FLASH/agents.cron" "$FLASH/checks.cron" "$FLASH/study.cron" "$FLASH/vmwatch.cron"

# If Vitals was the start page, fall back to Unraid's Main.
if [ -f /boot/config/plugins/dynamix/dynamix.cfg ] && grep -q '^START_PAGE="Vitals"' /boot/config/plugins/dynamix/dynamix.cfg; then
  sed -i 's/^START_PAGE=.*/START_PAGE="Main"/' /boot/config/plugins/dynamix/dynamix.cfg
fi

echo ""
echo "-------------------------------------------------------------"
echo " $PLUGIN removed."
echo " Your history is still on flash at $FLASH/history"
echo " and the knowledge base in your appdata share (unraid-vitals/vitals.db)."
echo " Remove those manually to delete collected data."
echo "-------------------------------------------------------------"
echo ""
