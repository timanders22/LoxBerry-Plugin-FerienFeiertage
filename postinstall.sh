#!/bin/bash
# Ferien und Feiertage - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Seit 1.2.16 das Rueckgabefenster eines Updates (I1, I4, I5; Regeln/06:
# "postinstall ist das Rueckgabefenster, postupgrade kommt zu spaet"). Bis
# 1.2.15 holte erst postupgrade.sh die Termine zurueck - und nur, wenn noch
# keine termine.json dalag: ein Takt in der Luecke, der mit den Vorgaben
# (DE-BY) abgerufen hatte, verdraengte den richtigen Bestand fuer bis zu
# sieben Tage (Installer-Pruefer F6d).
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

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
# Zurueckgespielt wird NUR bei einer Aktualisierung - und die erkennt dieses
# Skript allein an der Marke aus preupgrade.sh (kein Altersvergleich).
# Festgehalten wird es hier, bevor der trap die Marke entfernt.
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

PCFG="$BASE/config/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
mkdir -p "$PCFG" "$PDATA" 2>/dev/null
CF="$PCFG/ferien.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
# I7: mit umask 077 entsteht die Datei mit 600 (bis 1.2.15: 644).
[ -f "$CF" ] || printf '{}\n' > "$CF"

# Traegt die Datei das Aktionstoken? Dieselbe Frage wie fer_config_hat_inhalt()
# in ferien_lib.php. Entschieden wird nach INHALT, nicht nach Form oder Groesse:
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
# Gehoert die Termindatei zur Region der Konfiguration? (I2) Dieselbe Frage
# wie fer_region_passt() in ferien_lib.php, mit denselben Vorgaben (DE,
# DE-BY, keine Schulart). Rueckgabe: 0 ja, 1 nein, 2 nicht pruefbar.
fer_region_passt() {
    command -v php >/dev/null 2>&1 || return 2
    php -r '$t = json_decode((string) @file_get_contents($argv[1]), true);
        $c = json_decode((string) @file_get_contents($argv[2]), true);
        if (!is_array($t) || !is_array($c)) { exit(1); }
        $n = function ($w, $m) { return preg_replace($m, "", strtoupper(is_string($w) ? $w : "")); };
        $land = $n(isset($c["country"]) ? $c["country"] : "DE", "/[^A-Z]/");
        if ($land === "") { $land = "DE"; }
        $sub = $n(isset($c["subdivision"]) ? $c["subdivision"] : "DE-BY", "/[^A-Z0-9-]/");
        $gr = $n(isset($c["group"]) ? $c["group"] : "", "/[^A-Z0-9-]/");
        exit((isset($t["land"], $t["sub"]) && $t["land"] === $land && $t["sub"] === $sub
              && (isset($t["gruppe"]) ? (string) $t["gruppe"] : "") === $gr) ? 0 : 1);' -- "$1" "$2" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}
# Eine Datei ueber eine Nebendatei an ihren Platz bringen (0600, vollstaendig).
fer_hinlegen() { # quelle ziel
    cp "$1" "$2.neu.$$" 2>/dev/null && chmod 600 "$2.neu.$$" 2>/dev/null \
        && cmp -s "$1" "$2.neu.$$" && mv -f "$2.neu.$$" "$2" 2>/dev/null && return 0
    rm -f "$2.neu.$$" 2>/dev/null
    return 1
}

# --- 1. Konfiguration aus der Update-Sicherung (nur bei Marke) ----------------
if [ "$UPGRADE" = 1 ] && [ -f "$SICHER/ferien.json" ]; then
    if fer_hinlegen "$SICHER/ferien.json" "$CF"; then
        echo "<OK> Konfiguration aus der Update-Sicherung zurueckgestellt."
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT zurueckstellen: $SICHER/ferien.json"
    fi
fi

# --- 2. Heilung aus der Zweitschrift -------------------------------------------
# Geheilt wird, wenn die Konfiguration kein Aktionstoken traegt und die
# Sicherungskopie eines. Bei einer Neuinstallation liegt keine Zweitschrift
# mehr da - preinstall.sh hat sie nach .alt gelegt (I1). Der verdraengte
# Stand bleibt als ferien.json.kaputt (0600) liegen, wie in
# fer_selbstheilung(). Ohne php ist der Inhalt nicht pruefbar; dann wird nur
# geheilt, wo nichts verloren gehen kann.
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
        if fer_hinlegen "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        else
            echo "<WARNING> Die Sicherungskopie liess sich nicht zurueckspielen: $BK"
        fi
    elif [ "$BK_RC" = 2 ] && fer_form_leer "$CF"; then
        if fer_hinlegen "$BK" "$CF"; then
            echo "<OK> Konfiguration aus der Sicherungskopie wiederhergestellt."
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Inhalt der Konfiguration nicht pruefbar (kein php) - sie bleibt unveraendert."
    else
        echo "<WARNING> Die Sicherungskopie traegt kein Aktionstoken - nicht zurueckgespielt: $BK"
    fi
fi
# I7: Konfiguration und Zweitschrift tragen das Aktionstoken - 600.
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

# --- 3. Termine und Kalender (nur bei Marke) -------------------------------------
# I5: die gesicherte termine.json ERSETZT einen Stand, den ein Lauf in der
# Luecke geholt hat - aber nur, wenn sie zur Region der Konfiguration passt
# (I2). I4: kalender.json ebenso; ob sie zur eingestellten Adresse gehoert,
# prueft die Bibliothek beim Lesen.
if [ "$UPGRADE" = 1 ] && [ -d "$SICHER" ]; then
    if [ -f "$SICHER/termine.json" ]; then
        fer_region_passt "$SICHER/termine.json" "$CF"; RP=$?
        if [ "$RP" = 0 ] || { [ "$RP" = 2 ] && [ ! -f "$PDATA/termine.json" ]; }; then
            if fer_hinlegen "$SICHER/termine.json" "$PDATA/termine.json"; then
                echo "<OK> Ferien- und Feiertagsdaten zurueckgestellt."
            else
                echo "<WARNING> Die Ferien- und Feiertagsdaten liessen sich NICHT zurueckstellen - der naechste Lauf holt sie neu."
            fi
        elif [ "$RP" = 1 ]; then
            echo "<WARNING> Die gesicherten Ferien- und Feiertagsdaten gehoeren zu einer anderen Region als die Konfiguration - nicht zurueckgestellt; der naechste Lauf holt die richtigen."
        fi
    fi
    if [ -f "$SICHER/kalender.json" ]; then
        fer_hinlegen "$SICHER/kalender.json" "$PDATA/kalender.json" \
            || echo "<WARNING> Der Kalender-Zwischenspeicher liess sich NICHT zurueckstellen."
    fi
    # Nach dem Update: kein alter Zwischenstand, und der naechste Lauf sendet
    # MQTT vollstaendig (Regeln/07).
    rm -f "/tmp/$PFOLDER/state.json" "/tmp/$PFOLDER/mqtt_sig.txt" "/tmp/$PFOLDER/mqtt_beat" 2>/dev/null
    # Die Update-Sicherung erst wegraeumen, wenn ihr Inhalt angekommen ist.
    # Bis 1.2.13 fiel sie ohne Bedingung (Pruefung-FerienFeiertage-1.2.14,
    # Fall K13); liegen bleibt sie nur, wenn sie das Token traegt und die
    # Konfiguration nicht.
    fer_traegt_token "$SICHER/ferien.json"; SI_RC=$?
    if [ ! -f "$SICHER/ferien.json" ] || cmp -s "$SICHER/ferien.json" "$CF" || fer_traegt_token "$CF" || [ "$SI_RC" = 1 ]; then
        rm -rf "${SICHER:?}" 2>/dev/null
    else
        echo "<WARNING> Die Konfiguration ist nicht angekommen - die Update-Sicherung bleibt liegen: $SICHER"
    fi
fi

# --- 4. Ergebnis -----------------------------------------------------------------
# I10/I11 (1.2.16): bis 1.2.15 stand hier bei einer Neuinstallation "holt
# postupgrade.sh gleich aus der Update-Sicherung zurueck" - postupgrade laeuft
# bei einer Neuinstallation nie. Und eine unlesbare Konfiguration nach einem
# Update ergab nur <OK>-Zeilen.
if fer_traegt_token "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
elif [ "$UPGRADE" = 1 ]; then
    echo "<WARNING> Die Konfiguration traegt nach dem Update KEIN Aktionstoken (unlesbar, abgeschnitten oder ohne Sicherung): $CF"
    echo "<WARNING> Beim ersten Oeffnen der Oberflaeche entsteht ein neues Token - die Adressen in Loxone muessen dann angepasst werden. Bitte Plugin-Oberflaeche oeffnen und Bundesland pruefen."
else
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und Bundesland waehlen."
fi
exit 0
