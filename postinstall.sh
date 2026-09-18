#!/bin/bash
# Ferien und Feiertage - postinstall
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-ferien}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/ferien.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/ferien.json"
fi
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
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Bundesland waehlen."
exit 0
