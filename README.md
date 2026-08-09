# LoxBerry-Plugin: Ferien und Feiertage

Liefert **Schulferien und gesetzliche Feiertage** an Loxone — automatisch, ohne
jährliches Nachpflegen von Terminlisten. Damit lassen sich Wecker, Morgen-Briefing,
Rollladen- und Heizzeiten an freie Tage anpassen.

Datenquelle: **openholidaysapi.org** (amtliche Daten, kostenlos, kein Konto).
Unterstützt Deutschland, Österreich, Schweiz und weitere Länder mit ihren
Bundesländern/Kantonen.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Funktionen

- **Heute und morgen** getrennt ausgewertet: Ferien, Feiertag, Wochenende,
  schulfrei, Schultag
- **`MSCHULTAG`** — „morgen ist Schultag": der Schlüsselwert für Wecker- und
  Abendlogik, ohne den man Ferien immer zu spät bemerkt
- **Brückentage** werden erkannt (Werktag zwischen Feiertag und Wochenende) —
  inklusive Jahresübersicht im Januar, gut für die Urlaubsplanung
- **Countdown**: Tage bis zu den nächsten Ferien, verbleibende Ferientage,
  Tage bis zum nächsten Feiertag, Name des laufenden Feiertags/der Ferien
- **Arbeitsort/Gemeinde** für die drei Feiertage, die nicht im ganzen Bundesland
  gelten: Friedensfest (nur Stadt Augsburg), Mariä Himmelfahrt (in Bayern nur in
  den ~1.700 überwiegend katholischen Gemeinden) und Fronleichnam (in Sachsen und
  Thüringen nur in bestimmten katholischen Gemeinden). Die Datenquelle kennt davon
  nur Augsburg — die beiden anderen Fälle rechnet das Plugin selbst (Fronleichnam
  über das Osterdatum + 60 Tage)
- **Eigene Termine** (bis zu 6): Betriebsferien, Urlaub, schulfreie Tage —
  wahlweise „wie Ferien", „wie Feiertag" oder **„Urlaub (abwesend)"**
- **Urlaubsmodus**: Termine der Art „Urlaub (abwesend)" liefern zusätzlich
  `URLAUB`, `MURLAUB`, `URLAUBIN`, `URLAUBREST`, `URLAUBDAUER` und `URLAUBENDE`.
  Damit fährt die Haussteuerung automatisch in den Urlaubsmodus
  (Anwesenheitssimulation, Temperaturabsenkung, Steckdosen aus) und hebt ihn am
  letzten Urlaubstag rechtzeitig wieder auf, damit das Haus zur Rückkehr warm ist
- **Vorabend-Ansage** (TTS) „Morgen ist schulfrei" und Push-Auslöser für Loxone
- **MQTT** über das LoxBerry MQTT Gateway, **JSON** für Drittsoftware
- Daten für 18 Monate im Voraus, lokal gespeichert (läuft auch ohne Internet
  weiter), automatisches Nachladen vor dem Auslaufen — kein Jahreswechsel-Problem
- Reiter: Einstellungen, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste), Brückentage, Kommende Ferien, Kommende Feiertage,
  Test, Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/ferien/ferien.php` | Loxone-Zeile `FERIEN;OK=..;SCHULTAG=..;MSCHULTAG=..;BRUECKE=..;FERIENIN=..;URLAUB=..;URLAUBENDE=..;…` |
| `/plugins/ferien/ferien.php?debug=1` | Ferien-, Feiertags- und Brückentagsliste im Klartext |
| `/plugins/ferien/ferien.php?refresh=1` | Daten sofort neu abrufen |
| `/plugins/ferien/ferien.php?json=1` | kompletter Zustand als JSON |
| `/plugins/ferien/ferien.php?say=1` | Test-Ansage |
| `/plugins/ferien/ferien.php?ptest=1` | Test-Pushnachricht auslösen |

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal (`config/plugins/ferien/ferien.json`). Externe Verbindungen gibt es
ausschließlich zur öffentlichen OpenHolidays-API (ohne Kennung).

## Lizenz

MIT — siehe [LICENSE](LICENSE).

## Änderungen

### 1.1.1

- **`cron.php` liegt jetzt unter `bin/` statt unter `webfrontend/html/`.**
  Aufgerufen wird die Datei ausschließlich vom Minutencron, und zwar über die
  PHP-Kommandozeile — nie über HTTP. Im HTML-Verzeichnis war sie zusätzlich für
  jeden im Heimnetz abrufbar, und ein Aufruf stößt einen vollständigen
  Durchlauf an: Abruf bei openholidaysapi.org, MQTT-Meldung, im Zweifel eine
  Ansage über den Audioserver. Ein Weg, den von außen niemand braucht, sollte
  von außen auch nicht erreichbar sein.

  `cron/cron.01min` ruft die Datei jetzt über `REPLACELBPBINDIR` auf.
  `ferien_lib.php` bleibt im HTML-Verzeichnis, weil dort auch `ferien.php`
  liegt — der Endpunkt für den Miniserver; `cron.php` findet die Bibliothek
  über dieselbe Marke, mit einem Rückfall für den Lauf aus dem ausgepackten
  Archiv. Bleibt beides erfolglos, bricht das Skript mit einer Meldung ab,
  statt still nichts zu tun.

  `postupgrade.sh` entfernt eine aus 1.1.0 stehengebliebene `cron.php` aus dem
  HTML-Verzeichnis — sonst hinge der Zweck des Umzugs davon ab, dass das Update
  das alte Verzeichnis restlos ersetzt.

- **An den Loxone-Adressen ändert sich nichts.** `ferien.php` bleibt, wo es
  ist, mit denselben Parametern.

### 1.1.0

- **Verpasste Ansagen.** Die Vorabend-Ansage prüfte auf die Minute genau
  (`date('H:i') === '19:00'`). Der Minutencron trifft die Minute nicht
  zuverlässig — ist der LoxBerry beschäftigt, läuft das Skript statt um
  19:00:05 erst um 19:01:02, und die Ansage des Tages fiel ersatzlos aus.
  Verglichen wird jetzt über Zeitstempel mit einer Stunde Nachlauf. Gemessen
  an einem Lauf mit 62 s Verzug: vorher keine Ansage, nachher eine. Nach
  einem Ausfall von mehr als einer Stunde bleibt es bewusst beim Ausfall —
  ein LoxBerry, der um drei Uhr nachts hochfährt, soll nicht mehr verkünden,
  dass morgen schulfrei ist.
- **Bundesland wechseln half nicht.** Beim Speichern wurde `state.json`
  geleert und `/tmp/ferien/termine.json` — nur liegt die Termindatei gar
  nicht dort, sondern unter `data/plugins/`. Wer von Bayern auf Berlin
  umstellte und speicherte, bekam bis zu sieben Tage lang weiter die
  bayerischen Ferien. Jetzt werden die Pfade über die Funktionen geholt, und
  bei einer geänderten Region wird sofort neu abgerufen.
- **Die englische Oberfläche war kaputt.** Im Statuskasten standen
  `$fe_st[fer_t('TEXT.HEUTE_2')]` und `$fe_st['heute'][fer_t('TEXT.SCHULFREI')]` —
  Array-Schlüssel, die durch die Sprachdatei liefen. Auf Deutsch ging das
  gut, weil der Schlüssel zufällig „heute" ergibt; auf Englisch ergibt er
  „today", der Zugriff läuft ins Leere und der ganze Kasten blieb leer.
- **Ohne JavaScript blieb die Seite leer.** Die Reiter waren `<div>`-Elemente,
  und `sm-active` setzte ausschließlich das JavaScript. Jetzt sind es echte
  Verweise, und der Server entscheidet, welcher Bereich sichtbar ist.
- **Ein Häkchen ohne Wirkung.** „Melden am Vorabend des Ferienbeginns" wurde
  nirgends abgefragt; entschieden hat allein „Melden, wenn morgen schulfrei
  ist". Jetzt genügt am Ferienbeginn eines von beiden — bewusst ODER, damit
  niemand eine Ansage verliert, die er heute bekommt.
- **Sperre für den Cron.** Hängt openholidaysapi.org, kann ein Durchlauf
  länger als eine Minute dauern (zweimal 20 s Zeitgrenze, dazu die Ansage).
  Die Läufe stapelten sich und schrieben alle in dieselben Dateien.
- **Atomares Schreiben.** `json_encode` liefert bei ungültigem UTF-8 `false`,
  und `file_put_contents($p, false)` schreibt 0 Bytes und meldet Erfolg.
  Außerdem konnte Loxone `state.json` halb geschrieben erwischen, während
  der Cron sie ersetzte. Beides ist abgestellt: erst Zwischendatei, dann
  `rename()`.
- **Sicherung beim Update** liegt nicht mehr im Installationsverzeichnis
  unter `/tmp` (auf dem LoxBerry eine Ramdisk), sondern unter
  `data/plugins/`. Ein Neustart zwischen den beiden Update-Schritten hätte
  sonst die Konfiguration mitgenommen.
- **MQTT.** Thema und Nutzlast werden vor dem Senden gesäubert;
  Feiertagsnamen kommen aus einer fremden Schnittstelle. Fehlgeschlagene
  Sendungen zählen nicht mehr als Erfolg. Zusätzlich `is_array()` vor dem
  verschachtelten Zugriff auf `general.json`.
- **Hausstandard.** Reiter als echte Verweise mit serverseitigem
  `sm-active`, kein roter Knopf mehr, Legende auch im Reiter Protokoll,
  `prerelease.cfg` ergänzt, 22 Sprachwerte, die ein `>` aus dem HTML
  verschluckt hatten, wieder geradegezogen, und die restlichen fest
  eingetragenen deutschen Texte in beide Sprachdateien überführt
  (363 Schlüssel je Datei).

