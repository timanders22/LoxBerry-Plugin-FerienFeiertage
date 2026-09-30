#!/bin/bash
# Ferien und Feiertage - postupgrade (laeuft als Benutzer loxberry)
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Seit 1.2.16 spielt dieses Skript NICHTS mehr zurueck (I5): das tut
# postinstall.sh, solange die Marke aus preupgrade.sh liegt - postupgrade
# kommt zu spaet (Regeln/06). Liegt die Update-Sicherung hier noch, ist die
# Rueckgabe dort gescheitert, und postinstall.sh hat es gemeldet.
#
# BERICHTIGT IN 1.2.16 (I10): der Zweig "Sicherung am alten Ort ($ARGV1)"
# fuer Anlagen vor 1.0.2 ist entfallen. $1 ist eine zehnstellige
# Zufallskennung, kein Pfad; der Zweig konnte nie greifen.
ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
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

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

if [ -d "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" ]; then
    echo "<WARNING> Die Update-Sicherung liegt noch da - die Rueckgabe in postinstall.sh ist nicht vollstaendig gelungen: $BASE/data/plugins/$PFOLDER.upgrade_sicherung"
fi

# Zwischenspeicher verwerfen: nach einem Update koennen sich Felder im
# Zustand geaendert haben, und eine alte state.json wuerde bis zu einer
# Stunde weiterbenutzt. Der MQTT-Merker faellt mit - der naechste Lauf sendet
# den Vollsatz (Regeln/07).
rm -f "/tmp/$PFOLDER/state.json" "/tmp/$PFOLDER/mqtt_sig.txt" "/tmp/$PFOLDER/mqtt_beat" 2>/dev/null

# Altlast aus 1.1.0 und frueher: cron.php lag im HTML-Verzeichnis und war damit
# fuer jeden im Heimnetz per HTTP abrufbar - ein Aufruf stiess einen ganzen
# Durchlauf an (Abruf, MQTT, im Zweifel eine Ansage). Seit 1.1.1 liegt die Datei
# unter bin/ und wird nur noch vom Cron ueber die Kommandozeile aufgerufen.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi

echo "<OK> Update abgeschlossen."
exit 0
