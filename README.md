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
- **`FREITAGE`** — wie viele freie Tage am Stück ab heute anstehen. Der Wert,
  der ein Wochenende (2) von den Sommerferien (45) unterscheidet, und damit der
  einzige, an dem sich eine Heizungsabsenkung sinnvoll festmachen lässt
- **Brückentage** werden erkannt, wahlweise klassisch (Werktag unmittelbar
  zwischen Feiertag und Wochenende) oder **erweitert** — dann auch die Werktage
  zwischen Weihnachten und Neujahr, die die klassische Regel geschlossen
  übersieht. Inklusive Jahresübersicht im Januar und `BRUECKEIN` für Loxone
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
- **Übergänge**: `FERIENENDE` (heute ist der letzte Ferientag) und
  `MERSTERSCHULTAG` (morgen geht die Schule wieder los) — die beiden Momente, an
  denen der Wecker wieder scharf gestellt wird. `URLAUBHEIM` hebt die
  Urlaubsabsenkung schon vor dem letzten Tag auf, damit das Haus warm ist
- **Zwei Regionen**: ein zweites Bundesland nur für die Schulferien, für einen
  Haushalt mit Kindern an Schulen in verschiedenen Ländern. Dazu die
  **Schulart** dort, wo die Datenquelle sie trennt (in Deutschland nur
  Mecklenburg-Vorpommern)
- **Termine aus einem Kalender**: ICS-Abonnement von Google, Nextcloud oder
  iCloud statt der sechs handgepflegten Zeilen. Übernommen werden
  Ganztagstermine; Termine mit Uhrzeit und Wiederholungen bleiben außen vor
- **Vorabend-Ansage** (TTS) „Morgen ist schulfrei" und Push-Auslöser für Loxone
- **MQTT** über das LoxBerry MQTT Gateway, **JSON** für Drittsoftware. Beide
  Wege tragen dieselben Werte, weil sie aus einer einzigen Feldliste entstehen
- **Selbstprüfung** im Reiter Test: beantwortet ohne Loxone, ob die Einrichtung
  trägt — ruft dazu den eigenen Endpunkt wirklich auf und prüft nach, dass in
  der Ausgabezeile kein Feld ein anderes verdeckt
- Daten für 18 Monate im Voraus, lokal gespeichert (läuft auch ohne Internet
  weiter), automatisches Nachladen vor dem Auslaufen — kein Jahreswechsel-Problem
- Reiter: Einstellungen, MQTT, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste), Brückentage, Kommende Ferien, Kommende Feiertage,
  Test, Logdateien

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/ferien/ferien.php` | Loxone-Zeile `FERIEN;OK=..;SCHULTAG=..;MSCHULTAG=..;BRUECKE=..;FERIENIN=..;URLAUB=..;URLAUBENDE=..;…` |
| `/plugins/ferien/ferien.php?debug=1` | Ferien-, Feiertags- und Brückentagsliste im Klartext |
| `/plugins/ferien/ferien.php?refresh=1` | Daten sofort neu abrufen |
| `/plugins/ferien/ferien.php?json=1` | kompletter Zustand als JSON |
| `/plugins/ferien/ferien.php?say=1&token=…` | Test-Ansage **(Token nötig)** |
| `/plugins/ferien/ferien.php?ptest=1&token=…` | Test-Pushnachricht auslösen **(Token nötig)** |
| `/plugins/ferien/ferien.php?selftest=1&token=…` | nur prüfen, ob das Token stimmt — löst nichts aus |

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal (`config/plugins/ferien/ferien.json`). Externe Verbindungen gibt es
ausschließlich zur öffentlichen OpenHolidays-API (ohne Kennung).

## Lizenz

MIT — siehe [LICENSE](LICENSE).

## Änderungen

### 1.2.0

**Der MQTT-Weg deckte den HTTP-Weg doch nicht ab.** Die Fassung 1.1.7 hat vier
fehlende Melde-Merker nachgetragen und in diese Datei geschrieben, damit
liefere MQTT jetzt alles. Nachgemessen stimmte das nicht: beide Wege führten
27 Werte, aber nicht dieselben. Über MQTT fehlten `WOCHENENDE`, `MBRUECKE`,
`FERIENDAUER` und `URLAUBDAUER`; dafür trug MQTT vier Namenstexte, die es über
HTTP nicht gibt. Dass beide Seiten auf 27 kamen, war Zufall — und genau
deshalb ist es niemandem aufgefallen. **Beide Wege entstehen jetzt aus einer
einzigen Feldliste**, ebenso die Importdatei für Loxone Config und die
Themen-Tabelle im Reiter MQTT. Zwei Listen können auseinanderlaufen, eine
nicht; der Reiter Test misst die Deckung zusätzlich nach.

**Die englische Oberfläche nannte MQTT-Themen, die es nicht gibt.** Die
englische Sprachdatei übersetzte die Themennamen (`/no_school`, `/schoolday`,
`/public_holiday`), veröffentlicht wurden aber immer die deutschen. Acht der
neun genannten Themen führten ins Leere. Die Themenliste kommt jetzt aus dem
Code, übersetzt werden nur die Spaltenüberschriften.

**Der Reiter MQTT nennt endlich das einzutragende Abo** und die vollständige
Themen-Tabelle — bisher fehlte beides, und ein fehlender Abo-Eintrag ist die
häufigste Fehlerursache überhaupt.

**`URLAUBIN` ist −1, die Importdatei ließ nur 0 zu.** Ohne eingetragenen
Urlaub liefert das Plugin `URLAUBIN=-1`, und das ist der Normalzustand jeder
Anlage. In der erzeugten Importdatei stand trotzdem `MinVal="0"`. Alle sieben
Zählfelder, die −1 liefern können, tragen jetzt `MinVal="-1"`.

**Die Meldezeit wurde zurechtgebogen statt abgewiesen.** `99:99` wurde
angenommen und lief hinterher auf 23:59, `25:00` auf 23:00, `7:5` wortlos
zurück auf 19:00 — dreimal sah der Anwender nach dem Speichern eine andere
Zeit als die eingegebene. Fehlerhafte Eingaben werden jetzt gemeldet, und der
bisherige Wert bleibt stehen. Dasselbe gilt für unvollständige eigene Termine:
sie verschwanden bisher lautlos und schoben die nachfolgenden Zeilen hoch.

**Die Oberfläche startete ohne `LBHOMEDIR` gar nicht.** `index.php` rief eine
Funktion auf, die zweihundert Zeilen später und bedingt definiert war; PHP
zieht solche Definitionen nicht nach vorn. Betroffen war genau der Fall, den
der Kommentar darüber abdecken wollte.

**Neu — aus vorhandenen Daten, ohne zusätzlichen Abruf:**

- `FREITAGE` und `MFREITAGE`: freie Tage am Stück. Gemessen über 500 Tage gibt
  es 44 Blöcke mit zwei Tagen, aber neun mit neun Tagen und mehr — ein Merker
  „heute ist frei" kann das nicht ausdrücken, und an dieser Zahl hängt jede
  sinnvolle Heizungsabsenkung.
- `FERIENENDE`, `MERSTERSCHULTAG`: die beiden Übergänge. Für Urlaub gab es das
  seit 1.1.0, für Ferien nichts Vergleichbares.
- `URLAUBHEIM`: Vorwärmen vor der Rückkehr, einstellbar in Tagen. `URLAUBENDE`
  springt erst am letzten Tag um — ein Haus, das dann zu heizen beginnt, ist
  bei der Ankunft kalt.
- `BRUECKEIN`, `FERIENNAECHSTEIN`, `FEIERTAG2IN`, `WOCHENTAG`, `MWOCHENTAG`,
  `HALBTAG`, `MHALBTAG`. Von der Brückentagsliste ging bisher **nichts** an
  Loxone; `FERIENNAECHSTEIN` beantwortet „wann beginnen die nächsten Ferien",
  während gerade welche laufen und `FERIENIN` deshalb auf 0 steht.

**Erweiterte Brückentag-Erkennung, ab Werk aus.** Die klassische Regel findet
über 900 Tage genau drei Brückentage und übersieht dabei den 28.–31.12.2026
und den 27.–30.12.2027 geschlossen — also die Tage, für die man Urlaub nimmt.
Der erweiterte Modus nimmt Werktage zwischen zwei freien Blöcken dazu. Er
bleibt ab Werk aus, weil `BRUECKE` im Miniserver üblicherweise an einem
Schwellwertschalter hängt und sich sonst auf jeder bestehenden Anlage die Zahl
der Impulse änderte. Die Voreinstellung für die Lücke ist vier Werktage: mit
drei fällt die Zeit zwischen den Jahren heraus, mit fünf wird jede gewöhnliche
Woche zur Brücke (gemessen: 254 statt 32 Tage im Jahr).

**Zweite Region und Schulart.** Ein zweites Bundesland nur für die
Schulferien, mit eigener Feldgruppe (`FERIEN2`, `SCHULTAG2`, `MSCHULTAG2`, …).
Gesetzliche Feiertage werden bewusst nur einmal geholt — sie hängen am
Wohnort, nicht an der Schule. Dazu die Schulart, wo die Datenquelle sie führt.

**Termine aus einem Kalender (ICS).** Statt sechs handgepflegter Zeilen ein
Abonnement. Übernommen werden Ganztagstermine; Termine mit Uhrzeit und
Wiederholungen bleiben außen vor, weil das Plugin in ganzen Tagen rechnet.
`DTEND` ist bei Ganztagsterminen exklusiv — wer das übersieht, verlängert
jeden Urlaub um einen Tag.

**Die Datenquelle liefert mehr, als das Plugin genommen hat.** Ein Eintrag
führt neben Datum und Namen auch `type` (Public, Bank, Optional, School,
EndOfLessons), `temporalScope` (ganzer oder halber Tag) und einen Kommentar,
in dem bei Halbtagen die Uhrzeit steht. Das alles wurde bisher verworfen. Für
Deutschland und Österreich ist es folgenlos — dort ist alles `Public` und
ganztägig; in Luxemburg ist Karfreitag ein Bank Holiday und damit kein
allgemein freier Tag. Die Aussiebung ist eine Einstellung und ab Werk aus; die
Oberfläche sagt vorher, wie viele Einträge der eigenen Region sie beträfe.

**Selbstprüfung im Reiter Test.** Bis 1.1.7 bestand der Reiter aus drei
Knopfreihen. Jetzt beantwortet er ohne Loxone, ob die Daten reichen, ob der
eigene Endpunkt antwortet und ein falsches Token abweist, ob das Gateway
eingerichtet ist, ob die Importdatei wohlgeformt ist und ob in der
Ausgabezeile ein Feld ein anderes verdeckt. Ein Hinweis zählt dabei
ausdrücklich **nicht** als bestanden.

**Sonstiges:** Der Knopf „Neues Token" war als einziger ein grauer
Browserknopf ohne Farbklasse. Der Hinweis „Bibliothek nicht geladen" war
unerreichbar, weil die Seite vorher an derselben fehlenden Bibliothek starb.
Eigene Termine wurden nach dem Zusammenführen nicht neu sortiert, weshalb ein
früher liegender eigener Termin bei `FERIENIN` übergangen wurde. Die Sprache
der Feiertagsnamen war fest auf Deutsch verdrahtet und ließ sich nicht
einstellen. `socket_create()` wird vor dem Aufruf auf Vorhandensein geprüft —
ein `@` fängt keinen „undefined function".

### 1.1.7

**Der MQTT-Weg liefert jetzt alles, was der HTTP-Weg liefert.** Bisher
veröffentlichte das Plugin über MQTT 23 Werte, über HTTP aber 27: es fehlten
die vier Melde-Merker `ann` (Meldefenster), `audio` und `push` (Freigaben aus
der Konfiguration) sowie `ptest` (Test-Push). Wer auf MQTT umstellte, verlor
damit genau die Werte, mit denen sich Ansage und Pushnachricht im Miniserver
steuern und **prüfen** lassen — der Test-Push löste über MQTT schlicht nicht
mehr aus.

Drei Änderungen, damit das wirklich wirkt:

- Die vier Merker kommen aus **einer** Funktion (`fer_meldeflags()`), die
  beide Wege benutzen. HTTP und MQTT können nicht mehr auseinanderlaufen.
- Sie stehen jetzt auch in der **Signatur** des Cron-Laufs. Ohne das wären
  sie zwar in der Nachricht gewesen, die Nachricht aber nicht verschickt
  worden: `ann` und `ptest` ändern sich allein durch Zeitablauf, nicht durch
  einen Zustandswechsel — ein gesetzter `ptest` wäre bis zum halbstündlichen
  Lebenszeichen liegengeblieben, sein Fenster ist aber nur fünf Minuten breit.
- `?ptest=1` veröffentlicht **sofort**, statt bis zu eine Minute auf den
  nächsten Cron-Lauf zu warten. Ein Test, der erst eine Minute später wirkt,
  sieht aus wie ein Test, der nicht wirkt.

**Aktionstoken für die beiden auslösenden Aufrufe.** `?say=1` (das Haus
spricht) und `?ptest=1` (Pushnachricht aufs Telefon) lagen bisher offen im
Heimnetz — jedes Gerät konnte sie auslösen. Sie verlangen jetzt ein Token aus
dem Reiter *Einbindung in Loxone*; ohne passendes Token antworten sie mit
HTTP 403. Die abfragenden Aufrufe bleiben offen, sie ändern nichts.

Dazu neu: **`?selftest=1&token=…`** beantwortet die Tokenfrage, ohne etwas
auszulösen — Hausstandard für alle Aktionsendpunkte. Das Token wird beim
ersten Aufruf der Oberfläche erzeugt und überlebt jedes Speichern; ein Knopf
erzeugt auf Wunsch ein neues.

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

