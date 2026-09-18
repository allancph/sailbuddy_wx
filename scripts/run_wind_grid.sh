#!/usr/bin/env bash
# Sailbuddy wind-grid pipeline: fetch Open-Meteo u/v grid, sync to CT146.
set -uo pipefail

TOOLBOX=/opt/cmems-toolbox
PY="$(command -v python3 || echo /usr/bin/python3)"
OUT="$TOOLBOX/windgrid"
LOG="$TOOLBOX/cmems.log"
CT_IP=192.168.0.240
CT_PRIV=/home/devsail/sailbuddy9/private/windgrid

echo "=== $(date -Is) windgrid fetch start ===" >> "$LOG"
"$PY" "$TOOLBOX/wind_grid_fetch.py" >> "$LOG" 2>&1
rc=$?
echo "windgrid fetch exit=$rc" >> "$LOG"

if [ "$rc" -eq 0 ]; then
    ssh -o StrictHostKeyChecking=no -q "root@$CT_IP" mkdir -p "$CT_PRIV"
    scp -o StrictHostKeyChecking=no -q "$OUT/wind_grid.json" "root@$CT_IP:$CT_PRIV/"
    scp_rc=$?
    ssh -o StrictHostKeyChecking=no -q "root@$CT_IP" \
        "chown devsail:www-data '$CT_PRIV' '$CT_PRIV/wind_grid.json'" 2>/dev/null
    echo "windgrid sync exit=$scp_rc" >> "$LOG"
else
    echo "windgrid sync SKIPPED (fetch failed)" >> "$LOG"
fi
echo "=== $(date -Is) done ===" >> "$LOG"