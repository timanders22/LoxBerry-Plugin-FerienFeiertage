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

if [ -f "$BASE/config/plugins/$PFOLDER/ferien.json" ]; then
    cp -p "$BASE/config/plugins/$PFOLDER/ferien.json" "$SICHER/ferien.json" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
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
