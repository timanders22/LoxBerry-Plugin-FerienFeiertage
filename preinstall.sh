#!/bin/bash
# Ferien und Feiertage - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 1.2.16 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22 (audi_bau/preinstall.sh). Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Rueckgabe macht
# postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Bis 1.2.15 spielte sie eine
# liegengebliebene Zweitschrift ungefragt zurueck - Aktionstoken, Region,
# Kalenderadresse und TTS-Ziel der frueheren Installation; die Bibliothek
# heilte sogar schon VOR postinstall.sh daraus, sobald ein Takt lief
# (Installer-Pruefer F2a/F2b). Und eine liegengebliebene Update-Sicherung
# aus einem abgebrochenen Update spielte beim naechsten Update fremde Termine
# ein (F2c). Jetzt gehen beide nach <name>.alt, gemeldet mit genau einer
# <WARNING>. Die Selbstheilung der Bibliothek liest .alt nie; die
# Deinstallation raeumt es ab. Dazu faellt der Zwischenstand einer frueheren
# Installation aus /tmp/ferien weg - sonst lieferte der Endpunkt bis zu einer
# Stunde lang deren Ferien und Urlaub (F9c).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ferien}"
BASE="${ARGV5:-$LBHOMEDIR}"
umask 077
# Wurzelsuche wie in den uebrigen Hakenskripten (fail-closed): ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - dieses Skript tut nichts."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - dieses Skript tut nichts."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung.neu"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BASE/config/plugins/$PFOLDER.backup.json.alt" ] && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json.alt" 2>/dev/null
for T in state.json mqtt_sig.txt mqtt_beat refresh_letzt; do
    rm -f "/tmp/$PFOLDER/$T" 2>/dev/null
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Termine einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
