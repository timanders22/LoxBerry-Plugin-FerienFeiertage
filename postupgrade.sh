#!/bin/bash
# Ferien und Feiertage - postupgrade (laeuft als Benutzer loxberry)
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

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
    # Erfolg nur melden, wenn er eingetreten ist: bis 1.2.13 stand hier
    # "<OK>" auch nach "cp: ... File too large" (gemessen 18.09.2026,
    # Pruefung-FerienFeiertage-1.2.14, Fall K13).
    if cp -p "$SICHER/ferien.json" "$BASE/config/plugins/$PFOLDER/ferien.json"; then
        echo "<OK> Konfiguration zurueckgestellt."
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT zurueckstellen: $SICHER/ferien.json"
    fi
fi
[ -f "$SICHER/ferien.log" ] && cp -p "$SICHER/ferien.log" "$BASE/log/plugins/$PFOLDER/ferien.log"
[ -f "$SICHER/termine.json" ] && [ ! -f "$BASE/data/plugins/$PFOLDER/termine.json" ] \
    && cp -p "$SICHER/termine.json" "$BASE/data/plugins/$PFOLDER/termine.json"

# Rueckfallebene: die dauerhafte Sicherungskopie neben dem Konfigordner.
# Sie wird von der Oberflaeche bei jedem Speichern mitgeschrieben und ist
# damit die zweite Verteidigungslinie, falls oben nichts zu holen war.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/ferien.json"
# Traegt die Datei das Aktionstoken? Dieselbe Frage wie fer_config_hat_inhalt()
# in ferien_lib.php; gleichlautend in preupgrade.sh, postinstall.sh und
# postupgrade.sh. Entschieden wird nach INHALT, nicht nach Form oder Groesse:
# eine abgeschnittene ferien.json ist weder leer noch "{}".
# Rueckgabe: 0 ja, 1 nein, 2 nicht pruefbar (kein php im Pfad).
fer_traegt_token() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "" ? 0 : 1);' -- "$1" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}
# Fehlt, leer oder "{}" - dort geht beim Ueberschreiben nichts verloren.
fer_form_leer() {
    [ ! -s "$1" ] || [ "$(tr -d ' \t\r\n' < "$1" 2>/dev/null)" = "{}" ]
}

# Geheilt wird, wenn die Konfiguration kein Aktionstoken traegt und die
# Sicherungskopie eines. Bis 1.2.13 lautete die Frage "fehlt, leer oder {}":
# eine ABGESCHNITTENE ferien.json bestand sie, und Aktionstoken und
# Bundesland blieben verloren (gemessen 18.09.2026 in WSL,
# Bestand-2026-09-18/klasse-C Fall 7; Pruefung-FerienFeiertage-1.2.14 K1-K3).
# Der verdraengte Stand bleibt als ferien.json.kaputt (0600) liegen, wie in
# fer_selbstheilung(). Eine Sicherungskopie ohne Aktionstoken heilt nichts.
# Ohne php ist der Inhalt nicht pruefbar; dann wird nur geheilt, wo nichts
# verloren gehen kann (K8/K9).
if [ -f "$BK" ]; then
    fer_traegt_token "$CF"; CF_RC=$?
    fer_traegt_token "$BK"; BK_RC=$?
    if [ "$CF_RC" = 0 ]; then
        :
    elif [ "$CF_RC" = 1 ] && [ "$BK_RC" = 0 ]; then
        if ! fer_form_leer "$CF"; then
            if cp -p "$CF" "$CF.kaputt" 2>/dev/null; then
                chmod 0600 "$CF.kaputt" 2>/dev/null
                echo "<WARNING> ferien.json trug kein Aktionstoken; der bisherige Inhalt liegt unter $CF.kaputt"
            fi
        fi
        if cp -p "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        else
            echo "<WARNING> Die Sicherungskopie liess sich nicht zurueckspielen: $BK"
        fi
    elif [ "$BK_RC" = 2 ] && fer_form_leer "$CF"; then
        if cp -p "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Inhalt der Konfiguration nicht pruefbar (kein php) - sie bleibt unveraendert."
    else
        echo "<WARNING> Die Sicherungskopie traegt kein Aktionstoken - nicht zurueckgespielt: $BK"
    fi
fi

# Zwischenspeicher verwerfen: nach einem Update koennen sich Felder im
# Zustand geaendert haben, und eine alte state.json wuerde bis zu einer
# Stunde weiterbenutzt.
rm -f /tmp/ferien/state.json /tmp/ferien/mqtt_sig.txt 2>/dev/null

# Die Update-Sicherung erst wegraeumen, wenn ihr Inhalt angekommen ist. Bis
# 1.2.13 fiel sie ohne Bedingung - auch wenn das Zurueckstellen oben
# gescheitert war (volle Karte, nachgestellt mit ulimit -f 0): dann gab es
# weder Konfiguration noch Sicherung (gemessen 18.09.2026,
# Pruefung-FerienFeiertage-1.2.14, Fall K13). Liegen bleibt sie nur, wenn sie
# das Aktionstoken traegt und die Konfiguration nicht.
SI_CF="$BASE/data/plugins/$PFOLDER.upgrade_sicherung/ferien.json"
fer_traegt_token "$SI_CF"; SI_RC=$?
if [ ! -f "$SI_CF" ] || cmp -s "$SI_CF" "$CF" || fer_traegt_token "$CF" || [ "$SI_RC" = 1 ]; then
    rm -rf "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" 2>/dev/null
else
    echo "<WARNING> Die Konfiguration ist nicht angekommen - die Update-Sicherung bleibt"
    echo "<WARNING> liegen: $BASE/data/plugins/$PFOLDER.upgrade_sicherung"
    echo "<WARNING> Bis dahin arbeitet das Plugin mit den Vorgaben. Bitte Plugin-Oberflaeche oeffnen und Bundesland waehlen."
fi

# Altlast aus 1.1.0 und frueher: cron.php lag im HTML-Verzeichnis und war damit
# fuer jeden im Heimnetz per HTTP abrufbar - ein Aufruf stiess einen ganzen
# Durchlauf an (Abruf, MQTT, im Zweifel eine Ansage). Seit 1.1.1 liegt die Datei
# unter bin/ und wird nur noch vom Cron ueber die Kommandozeile aufgerufen.
#
# Diese Zeile steht hier, weil sie nichts kostet und der Zweck des Umzugs sonst
# davon abhinge, dass das Update das alte HTML-Verzeichnis restlos ersetzt.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi

echo "<OK> Update abgeschlossen."
exit 0
