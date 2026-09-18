#!/bin/bash
# Ferien und Feiertage - preupgrade (laeuft als Benutzer loxberry)
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-ferien}"; BASE="${ARGV5:-$LBHOMEDIR}"

# Die Sicherung liegt BEWUSST NICHT im Installationsverzeichnis ($ARGV1).
#
# $ARGV1 ist der Ordner, in den LoxBerry das neue Archiv entpackt. Zwei
# Gruende sprechen dagegen, dort eigene Dateien abzulegen:
#
#   1. Er liegt unter /tmp, und /tmp ist auf dem LoxBerry eine Ramdisk.
#      Zwischen preupgrade und postupgrade liegt eine Paketinstallation.
#      Braucht die einen Neustart oder bricht das Update in der Mitte ab,
#      ist die Ramdisk leer - und mit ihr die einzige Kopie der
#      Konfiguration.
#   2. Er gehoert dem Installationsvorgang. Was dort liegt, wird entpackt,
#      ueberschrieben und am Ende geloescht; dass die eigenen Dateien
#      dazwischen unangetastet bleiben, ist nirgends zugesichert.
#
# Deshalb: data/plugins/<ordner>.upgrade_sicherung. Das liegt auf der Karte
# und uebersteht auch einen Neustart mittendrin.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

mkdir -p "$SICHER" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

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

# Zwei Regeln fuer die Sicherung, beide gemessen am 18.09.2026 in WSL
# (Pruefung-FerienFeiertage-1.2.14, messe_haken.sh):
#  1. Ein Stand OHNE Aktionstoken verdraengt keine Sicherung MIT (Fall K11:
#     eine liegengebliebene Sicherung aus einem abgebrochenen Lauf wurde von
#     einem "{}" ueberschrieben).
#  2. Die neue Sicherung entsteht daneben und kommt erst nach dem Vergleich
#     per Umbenennen an ihren Platz. Ein cp direkt auf die Sicherung kappt sie
#     zuerst; scheiterte das Schreiben (volle Karte, nachgestellt mit
#     ulimit -f 0), blieb sie mit 0 Byte zurueck (Fall K12).
CF="$BASE/config/plugins/$PFOLDER/ferien.json"
if [ -f "$CF" ]; then
    fer_traegt_token "$CF"; CF_RC=$?
    fer_traegt_token "$SICHER/ferien.json"; SI_RC=$?
    if [ "$CF_RC" = 1 ] && [ "$SI_RC" = 0 ]; then
        echo "<WARNING> ferien.json traegt kein Aktionstoken, die vorhandene Update-Sicherung"
        echo "<WARNING> schon - sie bleibt unveraendert: $SICHER/ferien.json"
    elif cp -p "$CF" "$SICHER/ferien.json.neu" 2>/dev/null \
         && cmp -s "$CF" "$SICHER/ferien.json.neu" \
         && mv -f "$SICHER/ferien.json.neu" "$SICHER/ferien.json" 2>/dev/null; then
        echo "<OK> Konfiguration gesichert."
    else
        rm -f "$SICHER/ferien.json.neu" 2>/dev/null
        echo "<WARNING> Die Konfiguration liess sich NICHT sichern (Platz? Rechte?): $SICHER"
        if [ -f "$SICHER/ferien.json" ]; then
            echo "<WARNING> Die vorhandene Sicherung bleibt unveraendert."
        fi
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi
if [ -f "$BASE/log/plugins/$PFOLDER/ferien.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/ferien.log" "$SICHER/ferien.log" 2>/dev/null
fi
# Die abgerufenen Ferien- und Feiertagsdaten mitnehmen. Sie liegen ohnehin
# unter data/ und werden vom Update nicht angefasst - aber wenn schon eine
# Sicherung, dann eine vollstaendige.
if [ -f "$BASE/data/plugins/$PFOLDER/termine.json" ]; then
    cp -p "$BASE/data/plugins/$PFOLDER/termine.json" "$SICHER/termine.json" 2>/dev/null
fi
exit 0
