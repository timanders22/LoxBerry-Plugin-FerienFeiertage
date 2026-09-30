#!/bin/bash
# Ferien und Feiertage - preupgrade (laeuft als Benutzer loxberry)
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# BERICHTIGT IN 1.2.16 (I10): hier stand, $ARGV1 sei der Ordner, in den
# LoxBerry das neue Archiv entpackt. $1 ist aber eine zehnstellige
# Zufallskennung, kein Pfad (Regeln/06, "$1 an die Upgrade-Skripte ist kein
# Pfad"). Die Sicherung liegt deshalb NEBEN dem Datenordner, nicht darin:
# der Installer ruft &purge_installation auch im Upgrade-Zweig
# (plugininstall.pl :874/:886) und loescht config/plugins/<x>/,
# data/plugins/<x>/, bin/, templates/ und beide webfrontend-Ordner. Der
# Punkt im Namen ist der ganze Unterschied: "rm -rf .../<x>/" trifft den
# Nachbarn "<x>.upgrade_sicherung" nicht.
ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"
# I7 (1.2.16): alles, was diese Skripte anlegen, ist nur fuer loxberry lesbar.
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

# ---------------------------------------------------------------------------
# ZUERST die Marke "Aktualisierung laeuft" (I1, Entscheidung 1) - vor jeder
# Sicherung. Nur mit ihr spielt postinstall.sh zurueck; preinstall.sh legt
# ohne sie liegengebliebene Bestaende beiseite. Kein Altersvergleich: eine
# vergessene Marke gilt (Entscheidung 8, Frage 17). postinstall.sh raeumt sie
# ueber einen trap ab.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if ! : > "$MARKE" 2>/dev/null; then
    echo "<WARNING> Die Marke fuer das Update liess sich nicht anlegen: $MARKE - postinstall.sh wird nichts zurueckspielen."
fi

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
NEU="$SICHER.neu"

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

# I2 (1.2.16): die Sicherung wird JEDES MAL neu gebaut - erst als .neu, dann
# umbenannt. Bis 1.2.15 kamen neue Dateien in einen vorhandenen Ordner, und
# eine termine.json aus einem frueheren, abgebrochenen Vorgang blieb darin
# liegen und wurde beim naechsten Update eingespielt (gemessen: Ferien einer
# fremden Region, Installer-Pruefer F2c). Entscheidung 1: bei einem Upgrade
# wird nie ein Bestand aus einem frueheren Vorgang eingespielt.
rm -rf "${NEU:?}" 2>/dev/null
if ! mkdir -p "$NEU" 2>/dev/null; then
    echo "<WARNING> Die Update-Sicherung liess sich nicht anlegen (Platz? Rechte?): $NEU"
    exit 0
fi
chmod 0700 "$NEU" 2>/dev/null

CF="$BASE/config/plugins/$PFOLDER/ferien.json"
if [ -f "$CF" ]; then
    if cp -p "$CF" "$NEU/ferien.json" 2>/dev/null && cmp -s "$CF" "$NEU/ferien.json"; then
        chmod 0600 "$NEU/ferien.json" 2>/dev/null
        fer_traegt_token "$CF"
        case $? in
            0) echo "<OK> Konfiguration gesichert." ;;
            1) echo "<WARNING> Die Konfiguration traegt kein Aktionstoken (unlesbar oder abgeschnitten): $CF"
               echo "<WARNING> Gesichert ist sie trotzdem; postinstall.sh holt das Token aus der Zweitschrift, wenn es eine gibt." ;;
            *) echo "<OK> Konfiguration gesichert (Inhalt ohne php nicht pruefbar)." ;;
        esac
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT sichern (Platz? Rechte?): $CF"
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi

# Termine und Kalender-Zwischenspeicher. BERICHTIGT IN 1.2.16 (I10): hier
# stand, die Termine "werden vom Update nicht angefasst". Der Installer
# raeumt data/plugins/<ordner>/ beim Update ab (Installer-Pruefer F6: nach
# purge fehlte termine.json). I4: kalender.json geht mit - sonst fielen
# URLAUB und URLAUBHEIM nach jedem Update auf 0, bis der Kalender wieder
# antwortet.
for F in termine.json kalender.json; do
    Q="$BASE/data/plugins/$PFOLDER/$F"
    [ -f "$Q" ] || continue
    if cp -p "$Q" "$NEU/$F" 2>/dev/null && cmp -s "$Q" "$NEU/$F"; then
        chmod 0600 "$NEU/$F" 2>/dev/null
    else
        rm -f "$NEU/$F" 2>/dev/null
        echo "<WARNING> $F liess sich NICHT sichern: $Q"
    fi
done
# I3 (1.2.16): ferien.log wird NICHT gesichert. log/plugins/<ordner> raeumt der
# Installer beim Update gar nicht ab (nur bei "all", plugininstall.pl :1643);
# das Zurueckkopieren ueberschrieb die Zeilen aus der Luecke, darunter die
# Meldung, dass aus der Zweitschrift geheilt wurde (Regeln/06).

# Die neue Sicherung an ihren Platz - die alte faellt dabei.
rm -rf "${SICHER:?}" 2>/dev/null
if ! mv "$NEU" "$SICHER" 2>/dev/null; then
    echo "<WARNING> Die Update-Sicherung liess sich nicht an ihren Platz bringen: $SICHER"
fi
exit 0
