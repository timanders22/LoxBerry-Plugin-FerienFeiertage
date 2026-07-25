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
