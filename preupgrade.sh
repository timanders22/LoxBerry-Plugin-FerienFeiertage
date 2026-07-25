#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/ferien.json" "$ARGV1/ferien.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/ferien.log" "$ARGV1/ferien.log" 2>/dev/null
exit 0
