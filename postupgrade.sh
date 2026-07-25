#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/ferien.json" ] && cp -p "$ARGV1/ferien.json" "$BASE/config/plugins/$PFOLDER/ferien.json"
[ -f "$ARGV1/ferien.log" ] && cp -p "$ARGV1/ferien.log" "$BASE/log/plugins/$PFOLDER/ferien.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"; CF="$BASE/config/plugins/$PFOLDER/ferien.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; fi
fi
exit 0
