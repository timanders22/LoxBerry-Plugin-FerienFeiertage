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

# ---------- Gruppe 19 (1.2.28): endpunkt_ein=1 fuer bestehende Anlagen (Entscheidung 2, Entwurf 3.2, E-E16) ----------
# Bis 1.2.27 antwortete ferien.php ohne Schalter. Eine bestehende Anlage behaelt den HTTP-Weg: in ferien.json und in der
# Zweitschrift wird endpunkt_ein=1 nachgetragen, wenn die Datei das Aktionstoken traegt (fer_traegt_token(), wie in
# preupgrade.sh) und den Schluessel noch nicht kennt. Ueber eine Nebendatei (.neu, umask 077) mit Gegenprobe (gleich
# bis auf den Schluessel), dann mv, 0600. Scheitert es, gilt die Lese-Regel in fer_config() (Datei mit Inhalt ohne
# Schluessel -> 1) - kein Unterschied. Ein zweiter Lauf aendert nichts; der postinstall-Rohling "{}" bleibt, wie er ist.
# Hier und nicht in postinstall.sh: postinstall spielt die Konfiguration erst zurueck, postupgrade kommt danach.
fer_traegt_token() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "" ? 0 : 1);' -- "$1" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}
fer_endpunkt_nachtragen() {
    [ -f "$1" ] || return 0
    fer_traegt_token "$1" || return 0
    php -r 'umask(077); $f = $argv[1]; $roh = (string) @file_get_contents($f); $d = json_decode($roh, true);
        if (!is_array($d) || array_key_exists("endpunkt_ein", $d)) { exit(3); }
        $d["endpunkt_ein"] = 1;
        $js = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($js === false) { exit(1); }
        $neu = $f . ".neu";
        if (@file_put_contents($neu, $js) !== strlen($js)) { @unlink($neu); exit(1); }
        $g = json_decode((string) @file_get_contents($neu), true);
        if (!is_array($g) || !isset($g["endpunkt_ein"]) || $g["endpunkt_ein"] !== 1) { @unlink($neu); exit(1); }
        unset($g["endpunkt_ein"]);
        if ($g !== json_decode($roh, true)) { @unlink($neu); exit(1); }
        if (!@rename($neu, $f)) { @unlink($neu); exit(1); }
        exit(0);' -- "$1" 2>/dev/null
    fer_e=$?
    if [ "$fer_e" = 0 ]; then
        chmod 600 "$1" 2>/dev/null
        echo "<INFO> Konfiguration ergaenzt ($(basename "$1")): endpunkt_ein=1 (bestehende Anlage - ferien.php bleibt erreichbar; abschalten im Reiter Einbindung)."
    elif [ "$fer_e" != 3 ]; then
        echo "<WARNING> endpunkt_ein liess sich in $(basename "$1") nicht nachtragen - es gilt die Lese-Regel (ferien.php bleibt erreichbar)."
    fi
    return 0
}
fer_endpunkt_nachtragen "$BASE/config/plugins/$PFOLDER/ferien.json"
fer_endpunkt_nachtragen "$BASE/config/plugins/$PFOLDER.backup.json"

if [ -d "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" ]; then
    echo "<WARNING> Die Update-Sicherung liegt noch da - die Rueckgabe in postinstall.sh ist nicht vollstaendig gelungen: $BASE/data/plugins/$PFOLDER.upgrade_sicherung"
fi

# Zwischenspeicher verwerfen: nach einem Update koennen sich Felder im
# Zustand geaendert haben, und eine alte state.json wuerde bis zu einer
# Stunde weiterbenutzt. Der MQTT-Merker faellt mit - der naechste Lauf sendet
# den Vollsatz (Regeln/07). Seit 1.2.28 (Gruppe 19, V1) ebenso der Merker der Haus-Themen haus/kalender: der naechste
# Lauf schickt dann alle zwoelf retained Themen (Vollversand).
rm -f "/tmp/$PFOLDER/state.json" "/tmp/$PFOLDER/mqtt_sig.txt" "/tmp/$PFOLDER/mqtt_beat" \
      "/tmp/$PFOLDER/haus_letzte.json" "/tmp/$PFOLDER/haus_sitzung.json" 2>/dev/null

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
