#!/bin/bash
# Ferien und Feiertage - postupgrade (laeuft als Benutzer loxberry)
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
SICHER="$BASE/data/plugins/$PFOLDER/upgrade_sicherung"

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

# Wer von 1.0.1 oder frueher kommt, hat seine Sicherung noch im
# Installationsverzeichnis liegen - das wird hier noch gelesen, damit genau
# dieses eine Update nichts verliert.
if [ ! -d "$SICHER" ] && [ -n "$ARGV1" ] && [ -f "$ARGV1/ferien.json" ]; then
    SICHER="$ARGV1"
    echo "<INFO> Sicherung am alten Ort gefunden ($ARGV1) - wird uebernommen."
fi

if [ -f "$SICHER/ferien.json" ]; then
    cp -p "$SICHER/ferien.json" "$BASE/config/plugins/$PFOLDER/ferien.json"
    echo "<OK> Konfiguration zurueckgestellt."
fi
[ -f "$SICHER/ferien.log" ] && cp -p "$SICHER/ferien.log" "$BASE/log/plugins/$PFOLDER/ferien.log"
[ -f "$SICHER/termine.json" ] && [ ! -f "$BASE/data/plugins/$PFOLDER/termine.json" ] \
    && cp -p "$SICHER/termine.json" "$BASE/data/plugins/$PFOLDER/termine.json"

# Rueckfallebene: die dauerhafte Sicherungskopie neben dem Konfigordner.
# Sie wird von der Oberflaeche bei jedem Speichern mitgeschrieben und ist
# damit die zweite Verteidigungslinie, falls oben nichts zu holen war.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/ferien.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
    fi
fi

# Zwischenspeicher verwerfen: nach einem Update koennen sich Felder im
# Zustand geaendert haben, und eine alte state.json wuerde bis zu einer
# Stunde weiterbenutzt.
rm -f /tmp/ferien/state.json /tmp/ferien/mqtt_sig.txt 2>/dev/null

rm -rf "$BASE/data/plugins/$PFOLDER/upgrade_sicherung" 2>/dev/null
echo "<OK> Update abgeschlossen."
exit 0
