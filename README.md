# LoxBerry-Plugin: Ferien und Feiertage

Liefert **Schulferien und gesetzliche Feiertage** an Loxone — automatisch, ohne
jährliches Nachpflegen von Terminlisten. Damit lassen sich Wecker, Morgen-Briefing,
Rollladen- und Heizzeiten an freie Tage anpassen.

Datenquelle: **openholidaysapi.org** (amtliche Daten, kostenlos, kein Konto).
Unterstützt Deutschland, Österreich, Schweiz und weitere Länder mit ihren
Bundesländern/Kantonen.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Neu in 1.2.29

Pflegefassung der Nachtwelle (29.10.2026) mit der Welle Mehrfach-Ziele, ohne Änderung an Ferien, Feiertagen und
MQTT-Werten:

* **Befehlsbrücke 1.0.2** (gemeinsame Stammfassung): Die Vorlage der MQTT-Befehle (`VQ_ferien_mqtt.xml`, Reiter
  Einbindung in Loxone) kommt jetzt mit Windows-Zeilenenden (CRLF) wie die Ausfuhren der Loxone Config. Titel, Adresse
  und Befehle sind unverändert, ein Neuimport ist nicht nötig.
* **Sprachausgabe 1.2.1** (gemeinsames Modul): Sichtbar neu sind der Hinweis unter dem Feld „Sprache“, die Bezeichnung
  „Amazon Echo (Alexa-NG)“ in der Auswahl und die Tabelle der weiteren Ausgabeziele (nächster Punkt). Abfahrts-Assistent
  1.6.34, Abfuhrkalender 1.4.27 und Ferien und Feiertage 1.2.29 laden einander in denselben Prozess (Ferien-Bibliothek)
  und gehen deshalb zusammen auf 1.2.1 – bitte alle drei einspielen.
* **Mehrere Lautsprecher gleichzeitig (Sprachmodul 1.2.1):** Unter der Ausgabeart (jetzt „Ziel 1“) nimmt die Tabelle
  *Weitere Ausgabeziele (gleichzeitig)* bis zu fünf weitere Ziele auf – etwa Google-Lautsprecher (Chromecast 4 Lox NG)
  in einem Raum und Amazon Echo (Alexa-NG) in einem anderen neben dem Loxone Music Server. Die Ansage geht an alle
  eingeschalteten Ziele zugleich (eine Ansage dauert so lange wie das langsamste Ziel, höchstens 10 s, mit Sonos 30 s);
  scheitert eines, sprechen die anderen. Protokoll und Reiter Test nennen jedes Ziel, im Reiter Test gibt es je Ziel
  eine Testansage. Die bestehende Einstellung ist unverändert Ziel 1; ohne weitere Ziele verhält sich alles wie in
  1.2.28. Antwort an Loxone, MQTT-Themen und Brückenbefehl `ansage` bleiben, wie sie sind.
* **Vor einem Zurückgehen** auf 1.2.28 oder älter die weiteren Ziele entfernen: ältere Fassungen kennen die Liste nicht
  und fielen beim Laden auf die Werkseinstellung der Sprachausgabe zurück.
* **Selbstprüfung „MQTT-Gateway V2: Datenpunkte angehakt?“** (Reiter Test): Läuft das Gateway in Fassung 2, sieht das
  Plugin in `config/system/subscriptions.json` nach, ob alle Datenpunkte der Hakenliste (Reiter Einbindung in Loxone,
  Schritt 2, auch die Texte der Musterseiten) angehakt sind und `ok` „Noncached“ trägt; was fehlt, steht mit Namen da. Unter Fassung 1 trifft die
  Zeile nicht zu.

## Neu in 1.2.28

Gruppe 19 (Prüfbericht, Entwurf und Entscheidungen vom 25.10.2026):

* **Neu: Kalendertage an andere Plugins** (Reiter MQTT, ab Werk aus): `haus/kalender/heute|morgen/feiertag|ruhetag|frei|urlaub`,
  `haus/kalender/ferien_in`, `…/ferien_rest`, `…/feiertag_in` und `haus/kalender/datum` (retained) sowie
  `haus/kalender/zaehler` je Minute – nur Zahlen, nie Namen, über eine kurze Verbindung direkt zum Broker. Beim
  Ausschalten und bei der Deinstallation räumt das Plugin die Themen mit Rücklesen ab. Erster Abnehmer später:
  Sprachsteuerung („Ist morgen schulfrei?“).
* **HTTP-Weg mit Haken:** `ferien.php` antwortet nur noch mit „HTTP-Weg für Loxone annehmen“ (Reiter Einbindung). Nach
  dem Update ist der Haken gesetzt – bestehende Anlagen merken nichts; eine Neuinstallation bekommt 403. Abfahrts-Assistent
  und Abfuhrkalender lesen dann die Bibliothek. Zeile, JSON und Debug zeigt der Reiter Test hinter der Anmeldung;
  Test-Push und Neu abrufen sind dort Knöpfe ohne Token.
* **Kalender-Abo:** abgesagte Termine und Erinnerungen zählen nicht mehr, Termine mit `VALUE=DATE-TIME` sind kein
  Ganztag, `DURATION` statt `DTEND` wird verstanden, `DTSTART` mit genau 8 Ziffern gilt als Ganztag. Neu (ab Werk aus):
  jährliche Ganztagstermine nur mit Filterwort – so wird kein Geburtstag zum Urlaub.
* **MQTT:** `ok` kommt jede Minute mit dem Lebenszeichen (im Gateway V2 „Noncached“, Spalte in der Hakenliste), ferne
  Änderungen (neuer Urlaub, verschobener Feiertag) gehen sofort hinaus statt mit dem 30-Minuten-Takt, 5 ms Pause im
  Vollsatz; die PHP-Erweiterung sockets wird nicht mehr gebraucht.
* **Sprachausgabe 1.1.4:** Sonos4Lox und SonoX als Ausgabearten (SonoX schickt Ansagetexte an Google); Befehlsbrücke 1.0.1.
* **Sicherung:** eine Sicherung aus 1.2.26/1.2.27 lässt sich wieder zurückspielen (fehlende neue Einstellungen behalten
  ihren Wert); beim Zurückspielen schaltet sich die Befehlsbrücke sofort.
* Kleinere Korrekturen: Update bricht ab, wenn die Update-Marke nicht angelegt werden kann; Minutenlauf auch bei
  schreibgeschütztem Protokollordner; strengere Wurzel- und Pfadermittlung (Zweitinstallation, Archiv); Vorlagenadresse
  einer Zweitinstallation; „Jetzt abrufen“ orange; Hilfelink auf die eigene Wiki-Seite.

## Neu in 1.2.27

Umstellung auf MQTT (Gruppe 1): Werte **und** Befehle zwischen Plugin und Loxone gehen über das MQTT-Gateway des
LoxBerry – Gateway V1 und V2. Der HTTP-Weg (Zeile, Vorlage, Aktionen mit Token) bleibt für bestehende Anlagen.

* **Musterseiten auf MQTT:** Die drei Seiten des
  [LoxBerry-Plugins Musterprojekts](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt) tragen jetzt die
  Namen des MQTT-Gateways (`ferien_ok`, `ferien_status_zaehler` …) statt `FERIEN_…`. Wer sie nach 1.2.26 gebaut hat
  und dabei bleiben will, muss nichts tun (HTTP-Weg unverändert); wer umsteigt, legt die Eingänge nach der
  Namenstabelle an (oder kopiert die Seiten) und verbindet die Bausteine neu. Drei neue Bilder.
* **Neu: Namenstabelle** (Reiter „Einbindung in Loxone“, Schritt 3): alle 54 Namen, die das MQTT-Gateway vergibt
  (48 Zahlen, 6 Texte), mit Kopierknopf, Bedeutung und Bereich. Je Zahl ein „Virtueller Eingang“, je Text ein
  „Virtueller Texteingang“, Name genau so, „Als Digitaleingang verwenden“ nicht aktivieren; der LoxBerry-Benutzer am
  Miniserver braucht Rechte darauf. Eine Importvorlage für die MQTT-Werte gibt es bewusst nicht (LoxBerry-Anleitung:
  einfacher Virtueller Eingang). Wer die Seiten aus dem Musterprojekt kopiert, bekommt die Eingänge mit. Die
  HTTP-Vorlage bleibt unverändert.
* **Neu: Befehle über MQTT** (Befehlsbrücke, gemeinsames Modul): `ferien/befehl/ansage` und
  `ferien/befehl/testpush` (Wert 1) – dieselbe Verarbeitung wie `?say=1` und `?ptest=1`, Antwort auf
  `…/quittung`. Haken „Befehle über MQTT annehmen“ im Reiter MQTT, **ab Werk aus**; Vorlage „Vorlage
  MQTT-Befehle erzeugen“ (eigener Ausgang „Ferien und Feiertage MQTT-Befehle“). Im Musterprojekt stehen die
  Befehle am gemeinsamen Ausgang „LoxBerry MQTT-Gateway“; der Behälter „Ferien und Feiertage Aktionen“ entfällt dort.
* **Neu: Lebenszeichen `status/zaehler`** über MQTT (derselbe Laufzähler wie `ZAEHLER` der Loxone-Zeile, jede
  Minute, nie zurückbehalten); daran hängt die Ausfallerkennung der Musterseite.
* **Verhalten geändert (nur MQTT):** Ohne Daten der Quelle gehen nur noch `ok = 0` und das Lebenszeichen hinaus –
  keine geratenen Werte mehr (bis 1.2.26: `schultag` aus dem Wochentag, `feiertag`/`ferien` = 0). Gleich wie der
  HTTP-Weg mit 503.
* **Verhalten geändert (nur neue Installationen):** „Zustand per MQTT veröffentlichen“ ist ab Werk an. Bestehende
  Konfigurationen bleiben, wie sie sind.
* **Abo für Gateway V1:** Das Plugin bringt `config/plugins/ferien/mqtt_subscriptions.cfg` mit `ferien/#` mit und
  führt sie beim Speichern und im Minutenlauf auf ein anderes Präfix nach. Unter Gateway V2 nennt der Reiter die
  51 Datenpunkte zum Anhaken (43 davon auf den Musterseiten verdrahtet).
* Reiter „Einbindung in Loxone“ neu geordnet: MQTT-Weg Schritt 1–8, darunter „Bisherige Anlagen (HTTP)“. Reiter
  Test: Zeilen „Passt die Namenstabelle zu den gesendeten Themen?“ und „Befehlsbrücke“. Die Gateway-Fassung wird
  jetzt über dieselbe Wurzel gelesen wie die Autostart-Prüfung.
* Deinstallation: hält die Befehlsbrücke an und entfernt ihre Zugangsdaten; ein Lauf für ein fremdes Plugin, dessen
  Name mit „fer“ beginnt, tut nichts mehr.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.2.26

Einbindung in Loxone in Vollausstattung: drei Seiten aus dem gemeinsamen Musterprojekt mit drei Bildern und
Schritt-für-Schritt-Anleitung, ein Lebenszeichen und eine fertige Vorlage für Ansage und Test-Push.

* **Reiter „Einbindung in Loxone“, Schritt 4:** Statt der bisherigen Liste mit 80 Zeilen stehen dort die drei
  Seiten des [LoxBerry-Plugins Musterprojekts](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt),
  je mit Kasten „So übernehmen Sie diese Seite“, Tabelle, Hinweisen und Bild: „Ferien und Feiertage“ (36 Zeilen:
  läuft das Plugin – mit dem Lebenszeichen an einer Analogwertvalidierung –, heute, morgen und die vorhandenen
  Betriebsmodi „Feiertag“ und „Freier Tag/Schulferien“, nur mit gültigen Daten), „Ferien und Feiertage Meldungen“
  (33: Vorabend-Push, Erinnerung am Abend vor dem ersten Schultag, Test-Push, Ansage, Countdown, Namen über MQTT)
  und „Ferien und Feiertage Haus“ (35: Wecker, Rollladen am Morgen, Urlaubsmodus mit den Betriebsmodi „Urlaub“
  und „Anwesenheitssimulation“, Heizung absenken, zweite Region).
* **Die alte Liste setzte zwölf Schwellwertschalter an 0/1-Felder** – genau die Bauart, die am 07.09.2026 im
  Haus eine Push-Meldung bei jeder Abholung (alle 5 Minuten) erzeugte. Die neuen Seiten hängen jede Meldung an
  den digitalen Ausgang des Befehls; der einzige Schwellwertschalter sitzt an der Zahl `FREITAGE`, mit 600 s
  Einschaltverzögerung dahinter. Wer die alte Liste gebaut hat, nimmt die Seiten neu.
* **Neu: Lebenszeichen `ZAEHLER`** hinten in der Loxone-Zeile (0 bis 999, +1 je Minutenlauf, -1 = noch kein
  Lauf; wie beim Abfuhrkalender). Alle anderen Werte sind Tageswerte – steht der LoxBerry, behält Loxone sie,
  und nur der stehende Zähler verrät es. Kein bestehender Suchtext ändert sich; über MQTT gibt es den Wert nicht
  (dort ist `status/ts` das Lebenszeichen).
* **Verhalten geändert: `ANN` beachtet die Melde-Häkchen.** Bis 1.2.25 ging das Meldefenster an Loxone auch
  dann auf, wenn „Melden, wenn morgen schulfrei ist“ bzw. „Melden am Vorabend des Ferienbeginns“ abgewählt
  war; nur die eigene Ansage hielt sich daran. Jetzt bleibt `ANN` dort 0 – in der Zeile, über MQTT und in
  `?json=1`. Der Brückentag bleibt ein eigener Anlass.
* **Neu: Ausgangsvorlage** „Ausgangsvorlage erzeugen (Ansage und Test-Push)“ – `VQ_ferien_aktionen.xml`,
  Behälter „Ferien und Feiertage Aktionen“ mit „Ansage jetzt sprechen“ (`?say=1`) und „Test-Push auslösen“
  (`?ptest=1`), Aktionstoken eingetragen (nach „Neues Token erzeugen“ neu herunterladen).
* **Vorlage der virtuellen Eingänge:** Kommentare und Hinweistexte aus der Sprachdatei (deutsch mit Umlauten,
  englisch übersetzt; Titel und Suchtexte bleiben), Einheit `<v.0>` statt `<v.1>` (zeigt „12 Tage“ statt
  „12.0 Tage“), dazu `FERIEN_ZAEHLER` – 48 Befehle. Wer die alte Vorlage eingelesen hat, muss nichts tun; für
  das Lebenszeichen den einen Befehl `FERIEN_ZAEHLER` (Suchtext `\i;ZAEHLER=\i\v`) von Hand anlegen.
* Schritt 3 nennt als Kacheln die Status der drei Seiten; die Praxis-Erfahrungen zur Benachrichtigung nennen den
  Impuls-Befund. Reiter MQTT und Selbstprüfung zählen `ZAEHLER` nicht als fehlendes Thema.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.2.25

Gemeinsame Sprachausgabe 1.1.2.

* **Sprachausgabe 1.1.2:** gemeinsames Modul und Abschnitt [ANSAGE] mit 157 Sätzen. Die Auswahl der
  Ausgabeart nennt den Loxone Music Server jetzt „(ab Werk)“ und „aus“ schlicht „aus“ – das Modul
  weiß, dass hier ab Werk der Music Server eingestellt ist. Der eigene Hinweis unter der Auswahl
  (Haken „Audioausgabe aktiv“) bleibt. Dazu aus dem Modul: eigene Sätze zu einem unbekannten
  Eintrag im Block der Sprachausgabe, Zeichenzahl bei kaputtem UTF-8 in Zeichen, die Meldung
  „Port abgewiesen“ nennt das Feld nicht mehr doppelt.
* Abfuhrkalender und Abfahrts-Assistent binden die Bibliothek dieses Plugins ein und teilen sich
  damit das Modul; alle drei kommen mit 1.1.2.
* Baustein-Liste unverändert – frei formuliert sind nur noch die Stellen, an denen Sie eigene
  Schalter, Zeitimpulse oder vorhandene Bausteine anschließen.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.2.24

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Statusübersicht über den Reitern:** Dienst (ohne – der Minuten-Cron holt die Daten), Region, ob die
  Daten der Quelle heute und morgen abdecken, und der letzte gelungene Abruf – aus dem, was die Seite schon
  liest, ohne eigene Abfrage. Der Tageskasten darüber (heute, morgen, nächste Ferien) bleibt, wie er ist.
* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 1.2.23

Sprachausgabe in Hausform über die gemeinsame Sprachausgabe 1.1.1 (Entscheidung 40, Stufe 2).
Gemessen unter PHP 7.4 und 8.5 gegen Attrappen (Music Server, Alexa-NG, Chromecast); nicht am Gerät, nicht an
einem echten Lautsprecher.

* **Einstellungen, Abschnitt „Sprachausgabe“:** das Formular der gemeinsamen Sprachausgabe (wie Intercom und
  Raumklima). Neu in der Auswahl ist „aus“. Ab Werk bleibt es beim Loxone Music Server ohne Adresse; gespeicherte
  Einstellungen bleiben, wie sie sind.
* Adresse des Music Servers und Adressvorlage müssen im Heimnetz liegen. Eine andere Adresse wird beim Speichern
  und beim Zurückspielen abgewiesen und vor jedem Senden erneut geprüft (sie trüge den Ansagetext hinaus).
* Strenger als bisher: Lautstärke für Alexa-NG und Google 1 bis 100 (0 war stumm und galt trotzdem als
  gesendet), Zonen nur Zahlen, je wahlweise mit `~Lautstärke` 1 bis 100. Ein gespeicherter Wert, der das
  verletzt, wird beim nächsten Speichern beanstandet (dann wird nichts gespeichert); „Einstellungen sichern“
  nennt ihn vorher.
* **Testansage als Knopf im Reiter Test** (ein fester Satz mit den gespeicherten Einstellungen, für jede
  Ausgabeart; Neuladen spricht nicht erneut). Der bisherige Verweis auf `?say=1` mit dem Token entfällt dort;
  `?say=1` selbst bleibt für Loxone, mit unveränderter Antwort.
* Reiter Test: eine Zeile „Ist die Sprachausgabe eingerichtet?“ für alle Ausgabearten, mit der letzten Ansage.
  Alexa-NG und Chromecast werden mit `selftest=1` gefragt, dort wird nichts gesprochen; die zusätzliche Abfrage
  der Amazon-Anmeldung bei Alexa-NG entfällt.
* Protokoll: eine Zeile je Ansage, „Ansage: art=… stand=… zeichen=… http=…“ – nie Text oder Token.
* Einbindung in Loxone: Die Spalte „Eingänge verbinden mit“ der Baustein-Liste steht jetzt in der Form, aus der
  das Leitungswerkzeug die Leitungen ableitet („Ausgang von MSCHULTAG (#2)“, beim Status „V1 = …“).

**In Loxone:** nichts zu tun.

## Neu in 1.2.22

Gemeinsame Sprachausgabe auf 1.0.2 (Entscheidung 40).
Gemessen mit der Ferien-Gegenprobe unter PHP 7.4 und 8.5 (alle Punkte vorher = nachher); nicht am Gerät.

* Die gemeinsame Datei `sprachausgabe.php` ist auf den Stand der übrigen Plugins gehoben (1.0.2). Ansagen, Einstellungen, Antworten und Protokoll bleiben unverändert.

**In Loxone:** nichts zu tun.

## Neu in 1.2.21

Gemeinsame Sprachausgabe, Ansagetext nicht mehr im Protokoll (Entscheidung 40, Stufe 1).
Gemessen mit dem Ferien-Prüfstand gegen Attrappen und den echten Endpunkt von Chromecast 4 Lox NG 1.3.18,
PHP 7.4 und 8.5; nicht am Gerät, nicht an einem echten Lautsprecher.

* **Der Ansagetext steht nicht mehr im Protokoll und nicht mehr in der Antwort von `?say=1`**, sondern nur seine Länge:
  Protokoll „Ansage gesendet (… Text 77 Zeichen) -> OK“, Antwort `SAY;OK=1;TEXTLAENGE=77` (mit Alexa-NG im Fehlerfall
  weiter `CODE=…;GRUND=…`). Ausnahme: Ausgabeart Original-Audioserver – dort ist der Text die Antwort für Loxone Config
  und steht weiter als `TEXT=…` da.
* Die Sprachausgabe läuft jetzt über die gemeinsame Sprachausgabe der Plugins dieses Hauses (Datei
  `sprachausgabe.php`, dieselbe in allen Plugins mit Ansage). Einstellungen, Felder, Testansage, Sicherungen und
  Verhalten bleiben gleich.
* Der Webport für Alexa-NG und Chromecast 4 Lox NG wird jetzt auch unter `WEBSERVER` in `general.json` gefunden.
* Der Abruf beim Music Server und bei einer eigenen Adressvorlage folgt keiner Weiterleitung mehr und gilt nur bei
  HTTP 2xx als gesendet.

**In Loxone:** Wer aus der Antwort von `?say=1` den Text `TEXT=` gelesen hat, liest jetzt `TEXTLAENGE=` (außer beim Original-Audioserver).

## Neu in 1.2.20

Baustein-Liste zum Nachbauen (Nachzug B: X-8, Hausregel A4).
Gemessen mit der gerenderten Oberfläche unter PHP 7.4 und 8.5 gegen die mitgelieferten Vorlagen; nicht am Gerät.

* **Baustein-Liste zum Nachbauen:** Schritt 4 im Reiter „Einbindung in Loxone“ ist jetzt EINE nummerierte Liste
  (# | Baustein | Name | Parameter | Eingänge verbinden mit) statt fünf Teiltabellen ohne Nummern. Sie nennt die
  Importvorlage „Ferien und Feiertage“ mit Adresse und allen 47 Befehlen (Befehlserkennung, Min/Max, Einheit) und danach
  die Bausteine der fünf Anwendungen: Wecker/Briefing nur an Schultagen, Vorabend-Meldung, Ferien-Countdown und
  Brückentage, Urlaubsmodus, die Werte ab 1.2.0. Die Abschnitte 4a bis 4e stehen unter der Tabelle mit ihren
  Zeilennummern.
* Zusammengefasste Zeilen sind getrennt („NICHT + UND“ jetzt zwei Bausteine); jedes UND/ODER hat höchstens zwei
  Eingänge mit je einer Quelle; jeder Benachrichtigungs-Baustein hat genau eine Quelle (der Brückentag-Hinweis bekommt
  einen eigenen, optionalen). Die Raumregelung im Urlaub hängt am UND „Absenken im Urlaub“, damit das Vorwärmen am
  letzten Urlaubstag greift.
* Titel, Adresse und Befehle der Liste kommen aus denselben Funktionen wie die Importdatei; die Importdatei selbst ist
  unverändert.
* **In Loxone:** nichts zu tun. Wer die Logik nachbauen will, arbeitet die Liste von oben nach unten ab.

## Neu in 1.2.19

Ansage-3: Ausgabe über Google-Lautsprecher. Gemessen an Attrappen unter PHP 7.4 und 8.5 sowie gegen den echten Sprech-Endpunkt von Chromecast 4 Lox NG 1.3.15 (Dienst-Attrappe); die Ausgabe über Alexa NG und Music Server misst vorher = nachher gleich. Nicht am Gerät, nicht an echten Lautsprechern.

* **Neue Ausgabeart „Google-Lautsprecher (Chromecast 4 Lox NG)“** für die Vorabend-Ansage, ab Werk
  nicht gewählt. Chromecast-, Nest- und Google-Home-Lautsprecher sprechen über das Plugin
  [Chromecast 4 Lox NG](https://github.com/timanders22/LoxBerry-Plugin-Chromecast4lox) **ab Fassung 1.3.15**.
* Einrichtung dort: Reiter Einstellungen, Abschnitt „Sprachausgabe für andere Plugins“, Haken setzen und
  ein Sprechtoken festlegen. Hier: Lautsprecher (leer = Standardgerät des Chromecast-Plugins), Lautstärke
  (leer = dessen Ansagelautstärke) und dieses Sprechtoken.
* Eigenes Sprechtoken, getrennt vom Alexa-NG-Token, wie ein Kennwort behandelt: nie angezeigt, nie in Adresse
  oder Protokoll, nicht in „Einstellungen sichern“. Leer lassen behält es, ein Haken löscht es. Eine Sicherung
  mit Token wird beim Zurückspielen abgewiesen.
* Bei dieser Ausgabeart steht auch der Ansagetext weder im Protokoll noch in der Antwort von `?say=1`, nur
  seine Länge (`SAY;OK=…;CODE=…;GRUND=…;TEXTLAENGE=…`).
* „Gesendet“ heißt: der Dienst des Chromecast-Plugins hat die Ansage bei den verbundenen Lautsprechern
  eingereiht. Fällt der Endpunkt aus (Token falsch, Sprachausgabe dort aus, Dienst läuft nicht, Lautsprecher
  nicht verbunden, unbekannter Name, keine Antwort in 10 s), entfällt die Ansage, ohne Wechsel auf einen
  anderen Lautsprecher. Protokoll, Reiter Test (eigene Zeile) und `?say=1` nennen HTTP-Code und Grund;
  „404 ohne GRUND“ heißt: Chromecast 4 Lox NG fehlt oder ist älter als 1.3.15.
* Neuer Knopf „Testansage über Google-Lautsprecher“ im Reiter Test; die Antwortzeile steht danach oben,
  Neuladen der Seite löst nichts erneut aus.
* Ungültige Eingaben (Token zu kurz, Lautstärke außerhalb 0–100, Name mit Steuerzeichen oder über 200
  Zeichen, Ausgabeart ohne Token) werden beanstandet; es wird dann nichts gespeichert.
* Hinweis: Eine mit dieser Fassung erstellte Sicherung lässt sich in 1.2.18 und älter nicht zurückspielen
  (unbekannte Felder `tts.google_geraet`, `tts.google_laut`).

## Neu in 1.2.18

Ansage-2 aus der Verbesserungsliste (`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`).
Gemessen an einer Attrappe unter PHP 7.4 und 8.5; nicht am Gerät.

* **Neue Ausgabeart „Alexa-NG“** für Ansagen über Amazon-Echo-Geräte, ab Werk
  nicht gewählt. Voraussetzung ist das Plugin
  [Alexa-NG](https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG). Eingetragen
  werden Gerät (leer = Standardgerät), Lautstärke (leer = bleibt) und das
  Sprechtoken.
* Das Sprechtoken wird wie ein Kennwort behandelt: nie angezeigt, nie in Adresse
  oder Protokoll, nicht in „Einstellungen sichern“. Leer lassen behält es, ein
  Haken löscht es. Eine Sicherung mit Token wird beim Zurückspielen abgewiesen.
* Ausfälle nennen Protokoll, Reiter Test (eigene Zeile „Ansage über Alexa-NG“) und
  `?say=1` mit HTTP-Code und Grund.
* Ungültige Eingaben (Token zu kurz, Lautstärke außerhalb 0–100, Alexa-NG ohne
  Token) werden beanstandet; es wird dann nichts gespeichert. Beanstandete Felder
  tragen `aria-invalid`.
* Hinweis: Eine mit dieser Fassung erstellte Sicherung lässt sich in 1.2.17 und
  älter nicht zurückspielen (unbekannte Felder).

## Neu in 1.2.17

Verbesserungen aus dem Durchgang vom 30.09.2026 (Verbesserungsliste
`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`). Gemessen an einer
Quellen-Attrappe und mit den echten Lesestellen von AWM und Abfahrts-Assistent
unter PHP 7.4, 8.3 und 8.5; nicht am Gerät. Die Antworten an alle Leser sind
unverändert.

* **Reiter Test „Wer liest dieses Plugin?“:** Leser mit Weg, letztem Abruf,
  Ergebnis und Zahl der Aufrufe, nie ein Token; nach einem Neustart leer. Ein
  freiwilliger Zusatz `&quelle=name` an lesenden Aufrufen benennt den Leser.
* **Knopf „Probe gegen die Quelle (DE-BY)“:** gekürzte Rohantwort, Wochentag
  jedes Ferienendes, Gebietscodes; ändert keine Termine, höchstens einmal je Minute.
* **Bei einer Beanstandung wird nichts gespeichert** (bis 1.2.16 wurde der Rest
  gespeichert, beim MQTT-Formular sogar „MQTT an“); die eingetippten Werte stehen
  wieder im Formular, beanstandete Felder sind markiert.
* „Einstellungen sichern“ warnt gelb, wenn sich die Sicherung so nicht
  zurückspielen ließe (`_warnung`, nur Namen). Eine solche Sicherung lehnen
  1.2.16 und ältere Fassungen ab.

## Neu in 1.2.16

Durchgang vom 30.09.2026 mit vier Prüfern (Code, Oberfläche, Installer, MQTT).
Gemessen an Attrappen für Quelle und Kalender unter PHP 7.4, 8.3 und 8.5; nicht
am Gerät. Befunde mit Datei:Zeile:
`Pruefung-Durchgang-2026-09-29/FerienFeiertage_BEFUNDE_UND_VERBESSERUNGEN.md`.

**Falsche Ferien**

* **Scheiterte nur der Abruf der Schulferien** (die Feiertage kamen), wurde die
  Termindatei ohne Ferien geschrieben und als Erfolg verbucht: mitten in den
  Herbstferien `FERIEN=0`, `SCHULTAG=1` — bis zu 7 Tage lang, weil die Datei
  als frisch galt. Jetzt behält jeder gescheiterte Teil seinen gespeicherten
  Stand, und ein neuer Versuch folgt nach 1 h, 6 h, dann 24 h.
* **Ein gescheiterter Abruf galt als frisch** („vor 0 Tagen“). Jetzt zählt nur
  ein gelungener Abruf; das neue Feld `ALTER_TAGE` sagt, wie alt er ist.
* **Andere Plugins fragen mit `20261003` statt `2026-10-03`:** AWM-Abfuhr bekam
  dadurch keinen Feiertag und an jedem Tag des Jahres 2026 „Ferien“. Das
  Ferien-Plugin nimmt jetzt beide Schreibweisen an.

**`OK` und der Endpunkt**

* `OK=1` gibt es nur mit Daten der Quelle für die eingestellte Region, die
  heute und morgen abdecken. Ist der letzte gelungene Abruf älter als 21 Tage,
  kommt zusätzlich `WARN=1`.
* Ohne Daten antwortet der Endpunkt mit **503** (auch `?json=1`) statt mit
  `SCHULTAG=1`.
* Der Endpunkt fragt weder Quelle noch Kalender mehr: hing die Quelle, dauerte
  eine Antwort bis zu 40 s, ein hängendes Kalender-Abo ließ die Oberfläche
  46 s warten. Beides fragt jetzt nur der Minutentakt (Kalender mit 5 s
  Zeitgrenze).
* `?refresh=1` braucht das Token und wirkt höchstens einmal je 5 Minuten.

**MQTT**

* Nach dem Einschalten von MQTT oder einem Präfixwechsel gehen sofort alle
  Werte hinaus (bisher bis zu 30 min nichts).
* Neu: `datum` (JJJJMMTT) und `status/ts` bei jedem Lauf, dazu `alter_tage` und
  `warnung` — alle flüchtig, wie alle Themen dieses Plugins (es sind Tageswerte).

**Sicherung und Oberfläche**

* Eine Sicherung mit dem Token als Liste öffnete den Endpunkt für
  `token=Array`; ein Land als Liste legte die Oberfläche unter PHP 8 lahm. Jetzt
  wird jeder Wert geprüft wie beim Speichern.
* Ein leeres Token in einer Sicherung heißt „keins gesichert“: das geltende
  bleibt, die Seite sagt es.
* Nach jedem Absenden leitet die Seite um; F5 würfelt kein neues Token mehr.
* Eingaben werden abgewiesen und benannt statt still zurechtgebogen.
* Gleichzeitiges Speichern und Heilen verlor bis zu jeden fünften
  Speichervorgang; jetzt wird über eine Nebendatei geschrieben.

**Installation**

* Eine Neuinstallation spielt keine Einstellungen einer früheren Installation
  mehr ein (neu: `preinstall.sh`, Reste nach `.alt`).
* Der Kalender-Zwischenspeicher übersteht ein Update; die Termine einer
  anderen Region werden nie zurückgeholt.
* Die Konfiguration hat die Rechte 0600; Fehler des Minutentakts stehen in
  `cron.err`.

## Neu in 1.2.15

**Nach einem Update fordert die Installation nicht mehr dazu auf, das Bundesland zu wählen.**
Bis 1.2.14 endete `postinstall.sh` immer mit diesem Rat, auch wenn die Einstellungen gerade
übernommen worden waren. Jetzt steht dort „Einstellungen übernommen", sobald die Konfiguration
das Aktionstoken trägt (dieselbe Prüfung wie für die Sicherungskopie); die Anleitung erscheint
nur noch bei der Erstinstallation oder als Warnung, wenn das Zurückstellen der Update-Sicherung
gescheitert ist. In WSL nachgestellt (`Pruefung-FerienFeiertage-1.2.15/`), nicht am Gerät.

## Neu in 1.2.14

- **Die Hakenskripte entscheiden nach dem Inhalt, nicht nach der Größe.**
  `postinstall.sh` und `postupgrade.sh` fragten bis 1.2.13, ob `ferien.json`
  fehlt, leer ist oder `{}` enthält. Eine **abgeschnittene** Datei ist nichts
  davon; sie wurde nicht aus der Sicherungskopie geheilt, und Aktionstoken und
  Bundesland blieben verloren. Jetzt heißt es wie in der Oberfläche: trägt die
  Datei das Aktionstoken? Der verdrängte Stand bleibt als `ferien.json.kaputt`
  (0600) liegen, und eine Sicherungskopie ohne Aktionstoken heilt nichts.
- **Die Update-Sicherung wird nicht mehr gekappt.** `preupgrade.sh` legt die
  neue Sicherung daneben an und benennt sie erst nach dem Vergleich um; ein
  Stand ohne Aktionstoken ersetzt keine Sicherung mit. `postupgrade.sh` räumt
  die Sicherung erst weg, wenn ihr Inhalt angekommen ist — bisher fiel sie auch
  dann, wenn das Zurückstellen an einer vollen Karte gescheitert war.
- **Die Zweitschrift wird ebenso unteilbar nachgezogen** (Nebendatei, dann
  Umbenennen) und bekommt die Rechte der Konfiguration.
- **„Einstellungen zurückspielen" zieht die Zweitschrift mit.** Bis 1.2.13
  blieb sie auf dem alten Stand, und die nächste Selbstheilung machte das
  Zurückspielen still rückgängig — samt altem Aktionstoken.
- **Die Prüfung „Startet das MQTT-Gateway automatisch mit?" liest die
  LoxBerry-Wurzel**, statt ohne `LBHOMEDIR` auf einen festen Pfad zu raten.

Gemessen am 18.09.2026 in WSL/Ubuntu (bash, PHP 8.3.6) und unter Windows-PHP
7.4.33 und 8.4.24, jeweils vorher rot und nachher grün, mit Kontrollfällen.
Nicht am Gerät gemessen.

## Neu in 1.2.13

- **Die Selbstheilung entscheidet nach dem Inhalt der Konfiguration, nicht nach
  ihrer Form.** Bis 1.2.12 wurde nur gefragt: fehlt `ferien.json`, ist sie leer
  oder enthält sie `{}`? Eine **abgeschnittene** Datei — etwa nach einem
  Stromausfall oder auf einem vollen Dateisystem — ist keines davon. Sie ging
  an der Prüfung vorbei, `json_decode` gab stumm nichts zurück, das Plugin hielt
  das Aktionstoken für nicht gesetzt, würfelte ein **neues** und kopierte es
  über die Sicherungskopie. Damit war das alte Token endgültig fort, und jede
  Adresse im Miniserver (`?say=`, `?ptest=`, `?selftest=`) beantwortete jeden
  Aufruf mit HTTP 403 — ohne dass irgendwo etwas davon stand.
  Gemessen am 18.09.2026 in WSL/Ubuntu (PHP 8.3.6) und unter Windows-PHP
  7.4.33 und 8.4.24, mit Kontrollfall: dieselbe Messung ist an der heilen und
  an der leeren Datei grün.
- **„Inhalt" heißt: lesbares JSON-Objekt *und* vorhandenes Aktionstoken.**
  Wiederhergestellt wird nur aus einer Sicherungskopie, die selbst Inhalt
  trägt; ein Stand ohne Token ersetzt keinen anderen, in keiner Richtung.
- **Die Entscheidung steht an genau einer Stelle** (`fer_selbstheilung()` in
  `ferien_lib.php`). Bis 1.2.12 stand sie zweimal da — wortgleich in
  `ferien_lib.php` und in `webfrontend/htmlauth/index.php`. Zwei Abschriften
  laufen auseinander.
- **Der verdrängte Stand geht nicht verloren:** er bleibt als
  `ferien.json.kaputt` neben der Konfiguration liegen, mit den Rechten 0600 —
  es können Zugangsdaten darin stehen. Eine Datei, die nur `{}` enthielt, wird
  nicht aufgehoben; dort ging nichts verloren.
- **Die Sicherungskopie wird nie mit einem Stand ohne Aktionstoken
  überschrieben.** Alle vier Stellen, die sie nachziehen, gehen jetzt durch
  dieselbe Wache; weist sie ab, steht der Grund im Protokoll und die Kopie
  bleibt, wie sie war. Gemessen: bis 1.2.12 schrieb ein Klick auf *Speichern*
  bei unlesbarer Konfiguration ein leeres Token in die Sicherungskopie.
- **Ein neues Aktionstoken entsteht nur bei einer echten Erstinstallation.**
  Liegt eine Sicherungskopie mit Token daneben, wird keines gewürfelt: das
  wäre nicht der Anfang, sondern der endgültige Verlust des alten. Die
  Oberfläche sagt statt dessen, was los ist, und nennt den Reiter
  *Logdateien*. Sonst ist an dieser Fassung nichts geändert.

## Neu in 1.2.12

- **Tabellen mit Eingabefeldern rollen seitlich, statt abgeschnitten zu werden.**
  Die Oberfläche von LoxBerry schneidet breite Inhalte ab, ohne dass die Seite
  seitlich rollt; an BatterieBMS waren so zwei Einstellungen je Speicher nicht
  erreichbar. Diese Tabellen stehen jetzt im Rollbehälter der Hausform.

## Neu in 1.2.11

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.2.10 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

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
- **Vorabend-Ansage** (TTS) „Morgen ist schulfrei" und Push-Auslöser für Loxone –
  beide nur für die Anlässe, die unter Benachrichtigungen angehakt sind (das
  Meldefenster `ANN` seit 1.2.26 ebenso)
- **Ansage über Alexa-NG** (Ausgabeart „Alexa-NG“, ab Werk nicht gewählt):
  Echo-Geräte sprechen die Vorabend-Ansage über das Plugin
  [LoxBerry-Plugin-Alexa-NG](https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG).
  Eingetragen werden Gerät (leer = Standardgerät von Alexa-NG), Lautstärke
  (leer = bleibt) und das Sprechtoken aus Alexa-NG. Die Ansage geht per POST
  an `http://127.0.0.1:<Webport>/plugins/alexang/index.php`. Fällt Alexa-NG
  aus (Token falsch, nicht bei Amazon angemeldet, keine Antwort in 10 s),
  entfällt die Ansage; Protokoll, Reiter Test und `?say=1` nennen HTTP-Code
  und `GRUND`
- **Ansage über Google-Lautsprecher** (Ausgabeart „Google-Lautsprecher
  (Chromecast 4 Lox NG)“, ab Werk nicht gewählt): Chromecast-, Nest- und
  Google-Home-Lautsprecher sprechen die Vorabend-Ansage über das Plugin
  [Chromecast 4 Lox NG](https://github.com/timanders22/LoxBerry-Plugin-Chromecast4lox)
  ab Fassung 1.3.15. Dort im Reiter Einstellungen, Abschnitt „Sprachausgabe
  für andere Plugins“, den Haken setzen und ein Sprechtoken festlegen. Hier
  werden Lautsprecher (leer = Standardgerät des Chromecast-Plugins),
  Lautstärke (leer = dessen Ansagelautstärke) und dieses eigene Sprechtoken
  eingetragen. Die Ansage geht per POST an
  `http://127.0.0.1:<Webport>/plugins/chromecast-4lox-ng/index.php`; „gesendet“
  heißt: dort bei den verbundenen Lautsprechern eingereiht. Fällt der Endpunkt
  aus, entfällt die Ansage, ohne Wechsel auf einen anderen Lautsprecher;
  Protokoll, Reiter Test, der Knopf „Testansage über Google-Lautsprecher“ und
  `?say=1` nennen HTTP-Code und `GRUND` (404 ohne GRUND: Chromecast 4 Lox NG
  fehlt oder ist zu alt)
- **MQTT** über das LoxBerry MQTT Gateway (seit 1.2.27 der Hauptweg, Vorlage für
  Loxone Config, Befehle aus Loxone über die Befehlsbrücke), **JSON** für
  Drittsoftware. HTTP und MQTT tragen dieselben Werte, weil sie aus einer
  einzigen Feldliste entstehen
- **Lebenszeichen `ZAEHLER`** in der Loxone-Zeile bzw. `status/zaehler` über
  MQTT: steigt mit jedem Minutenlauf; steht er, steht der LoxBerry oder sein
  Minutenlauf
- **Selbstprüfung** im Reiter Test: beantwortet ohne Loxone, ob die Einrichtung
  trägt — ruft dazu den eigenen Endpunkt wirklich auf und prüft nach, dass in
  der Ausgabezeile kein Feld ein anderes verdeckt
- Daten für 18 Monate im Voraus, lokal gespeichert (läuft auch ohne Internet
  weiter), automatisches Nachladen vor dem Auslaufen — kein Jahreswechsel-Problem
- Reiter: Einstellungen, MQTT, Einbindung in Loxone (Schritt für Schritt mit den
  drei Seiten des
  [LoxBerry-Plugins Musterprojekts](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt)
  samt Bildern, Vorlagen für die Eingänge und für Ansage und Test-Push),
  Brückentage, Kommende Ferien, Kommende Feiertage, Test, Logdateien

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/ferien/ferien.php` | Loxone-Zeile `FERIEN;OK=..;SCHULTAG=..;MSCHULTAG=..;BRUECKE=..;FERIENIN=..;URLAUB=..;URLAUBENDE=..;…` |
| `/plugins/ferien/ferien.php?debug=1` | Ferien-, Feiertags- und Brückentagsliste im Klartext |
| `/plugins/ferien/ferien.php?refresh=1&token=…` | Daten sofort neu abrufen **(Token nötig, höchstens einmal je 5 min)** |
| `/plugins/ferien/ferien.php?json=1` | kompletter Zustand als JSON |
| `/plugins/ferien/ferien.php?say=1&token=…` | Test-Ansage **(Token nötig)**; Antwort `SAY;OK=…;TEXTLAENGE=…` (die Länge, nicht der Text), mit Alexa-NG im Fehlerfall zusätzlich `CODE=…;GRUND=…`, mit Google-Lautsprecher immer `CODE=…;GRUND=…`; nur beim Original-Audioserver `SAY;OK=0;TEXT=…` |
| `/plugins/ferien/ferien.php?ptest=1&token=…` | Test-Pushnachricht auslösen **(Token nötig)** |
| `/plugins/ferien/ferien.php?selftest=1&token=…` | nur prüfen, ob das Token stimmt — löst nichts aus |

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Alle Einstellungen
liegen lokal (`config/plugins/ferien/ferien.json`, Rechte 0600). Externe
Verbindungen gibt es zur öffentlichen OpenHolidays-API (ohne Kennung) und —
**nur wenn eingetragen** — zur Adresse des eigenen Kalender-Abonnements
(ICS). Diese Adresse ist bei Google oder iCloud oft ein geheimer Link; sie
steht in der Konfiguration und in der Sicherungsdatei und ist wie ein Passwort
zu behandeln.

Das **Sprechtoken für Alexa-NG** (nur mit dieser Ausgabeart) wird wie ein
Kennwort behandelt: Es geht nur im Körper einer POST-Anfrage an Alexa-NG auf
demselben LoxBerry (127.0.0.1), nie in einer Adresse. Es wird nie angezeigt
(das Feld bleibt leer, es steht nur „gespeichert (n Zeichen)“), nie
protokolliert und steht nicht in der Sicherungsdatei. Leer lassen behält es,
ein Haken löscht es. Beim Zurückspielen einer Sicherung bleibt das
gespeicherte; eine Datei mit Sprechtoken wird abgewiesen.

Dasselbe gilt für das **Sprechtoken für Chromecast 4 Lox NG** (nur mit der
Ausgabeart Google-Lautsprecher). Es ist ein eigenes, nicht das von Alexa-NG,
und geht nur im Körper einer POST-Anfrage an Chromecast 4 Lox NG auf demselben
LoxBerry (127.0.0.1). Mit dieser Ausgabeart steht auch der Ansagetext weder
im Protokoll noch in der Antwort von `?say=1`, nur seine Länge.

## Fassung 1.2.9 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/ferien_lib.php:266`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).

## Änderungen

Die Freigabenotiz zu jeder Fassung steht bei den Releases:
<https://github.com/timanders22/LoxBerry-Plugin-FerienFeiertage/releases>

Was hier noch steht, beschreibt einzelne Fassungen ausführlicher, als es auf
die Release-Seite passt. Maßgeblich ist die Release-Seite.

### 1.2.2

**Aufräumen in den Sprachdateien — keine Änderung am Verhalten.** Je
Sprache sind 66 Schlüssel entfernt worden, die kein Code mehr liest.
Sie sind Rückstände aus 1.2.0: seit die Tabelle der Befehlserkennungen im
Reiter *Einbindung in Loxone* und die Themenliste im Reiter *MQTT* aus
`fer_felder()` erzeugt werden, stand ihr Inhalt nur noch in den
ini-Dateien herum.

Warum das nicht bloß Kosmetik ist: 27 dieser Schlüssel führten die
**alte** Schreibweise der Suchtexte (`\i<NAME>=\i\v`, ohne Semikolon) —
also genau das Gegenteil dessen, was 1.2.1 eingeführt hat. Weitere neun
trugen die MQTT-Themennamen, und die englische Datei nannte dort
`/no_school`, `/schoolday`, `/public_holiday`, während das Plugin seit
jeher `/schulfrei`, `/schultag`, `/feiertag` veröffentlicht. Genau diese
neun waren der Befund, den 1.2.0 behoben hat; die Zeilen selbst sind erst
jetzt verschwunden.

Eine Sprachdatei, die etwas anderes behauptet als der Code tut, ist keine
Kleinigkeit — sie ist die Stelle, an der jemand nachschlägt.

Vor dem Entfernen gemessen: die Erkennung ist in beide Richtungen geeicht,
es gibt keine dynamisch zusammengesetzten Schlüssel (442 von 450
`fer_t()`-Aufrufen sind wörtliche Literale, die übrigen acht sind die
Funktion selbst und Kommentare), und beide Sprachdateien tragen hinterher
dieselben 382 Schlüssel. Ungenutzt ist jetzt keiner mehr.

### 1.2.1

**Der Suchtext der Befehlserkennungen trägt jetzt das Trennzeichen der
Antwortzeile.** Bis 1.2.0 lautete er `\i<NAME>=\i\v` ohne das Semikolon
davor. Loxone nimmt die **erste** Fundstelle, und `SCHULTAG=` steckt auch in
`MSCHULTAG=`. Gutgegangen ist das nur, weil jedes Heute-Feld in der
Ausgabezeile vor seinem M-Gegenstück steht — eine Wette auf die Sortierung,
die beim nächsten neuen Feld verloren gehen kann. Mit dem Semikolon ist die
Frage strukturell erledigt: in der Antwortzeile steht vor jedem Feldnamen
eines, auch vor dem ersten.

Der Anlass ist kein eigener Vorfall, sondern ein fremder: am 20.08.2026 las
in drei anderen Plugins ein virtueller Eingang für den Kilometerstand die
Inspektionsvorgabe — 15.000 statt 48.210, weil sein Suchtext ohne
Trennzeichen zuerst auf das längere Feld traf. Beide Zahlen sahen aus wie
ein Kilometerstand, und gemeldet hat sich nichts.

**In diesem Plugin war zum Zeitpunkt der Umstellung kein Feld falsch
getroffen** — die Prüfung im Reiter Test war und ist grün. Die Änderung
beseitigt die Abhängigkeit von der Reihenfolge, nicht einen wirkenden
Fehler.

**Wer bereits importiert hat, muss nichts tun.** Die bestehenden virtuellen
Eingänge arbeiten unverändert weiter. Der neue Suchtext steckt in der
Importdatei und in der Tabelle im Reiter *Einbindung in Loxone*; er wirkt
sich erst aus, wenn jemand neu importiert oder eine Befehlserkennung von
Hand nachträgt.

Beides — Importdatei und Tabelle — holt den Text jetzt aus **einer**
Funktion. In den drei fremden Linien war seinerzeit die Vorlage berichtigt
worden und die Oberfläche nicht; vier Abschriften einer Regel sind vier
Gelegenheiten, sie an einer Stelle zu vergessen.

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

