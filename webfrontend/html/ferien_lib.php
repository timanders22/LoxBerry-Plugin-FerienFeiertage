<?php
/**
 * Ferien und Feiertage - gemeinsame Bibliothek
 *
 * Holt Schulferien und gesetzliche Feiertage von der offenen OpenHolidays-API
 * (openholidaysapi.org, amtliche Daten, kein Konto noetig) und liefert an Loxone:
 *   - heute/morgen: Ferien, Feiertag, schulfrei, Schultag, Brueckentag
 *   - Tage bis zu den naechsten Ferien, Restdauer laufender Ferien
 *   - Namen des Feiertags bzw. der Ferien
 *   - JSON, MQTT und optionale Ansage/Push am Vorabend
 *
 * Zusaetzlich koennen eigene Termine gepflegt werden (Betriebsferien, Urlaub,
 * schulfreie Tage), die wie Ferien behandelt werden.
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der lokalen Konfiguration.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Fassung 1.2.1.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Zeitzone (C16, Durchgang 30.09.2026).
 *
 * Bis 1.2.15 stand hier auf oberster Ebene date_default_timezone_set(
 * 'Europe/Berlin'). Die Bibliothek wird aber auch von fremden Prozessen
 * eingebunden (Abfahrts-Assistent, AWM-Abfuhr); das Einbinden stellte deren
 * Zeitzone um, gemessen aus einem UTC-Prozess heraus. Jetzt gilt:
 *   - Die eigenen Einstiege (ferien.php, bin/cron.php, htmlauth/index.php)
 *     stellen die Zeitzone ihres EIGENEN Prozesses selbst ein.
 *   - Die drei Funktionen, die andere Plugins aufrufen - fer_data(),
 *     fer_day() und fer_state() -, rechnen in Europe/Berlin und stellen die
 *     Zone des Aufrufers danach zurueck.
 */
function fer_zone_an()
{
    $alt = date_default_timezone_get();
    if ($alt !== 'Europe/Berlin') {
        date_default_timezone_set('Europe/Berlin');
    }
    return $alt;
}

function fer_zone_aus($alt)
{
    if (is_string($alt) && $alt !== '' && $alt !== date_default_timezone_get()) {
        date_default_timezone_set($alt);
    }
}


/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php,
 * Nr. 36 b). Seit 1.2.23 Stufe 2: Sprechen, Formular, Wertpruefung, Zeile im Reiter
 * Test und Testansage kommen aus dem Modul (Fassung 1.1.1). Liegt neben dieser Datei.
 * Laedt ein anderes Plugin diese Bibliothek (Abfahrts-Assistent, AWM-Abfuhr) und hat
 * es schon eine eigene Abschrift geladen, gilt jene; die Datei schuetzt sich selbst
 * gegen doppeltes Laden. Was dort laeuft (fer_data(), fer_day(), fer_state() und
 * fer_config()), ruft deshalb nur Modulfunktionen, die es schon in 1.0.2 gab. */
require_once __DIR__ . '/sprachausgabe.php';

/** Die Ausgabearten, die dieses Plugin anbietet: wie bis 1.2.22, dazu "aus" und seit 1.2.28 Sonos4Lox und SonoX
 *  (Pflegewelle 22, Modul 1.1.4; ungemessen, kein Sonos im Haus - das Modul prueft Installation und Zone). Keine
 *  Fassungspruefung noetig: in fremden Prozessen ruft diese Bibliothek das Modul nur zum Vervollstaendigen. */
function fer_ansage_modi() {
    return array('aus', 'musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox', 'sonos4lox', 'sonox');
}

/**
 * Kontext fuer die gemeinsame Sprachausgabe: Webport, Kennung dieses Plugins, Ordner
 * der letzten Ansage (Zwischenordner, wie bis 1.2.22 alexa_letzte.json) und die Texte
 * ([ANSAGE] der Sprachdateien). Ab Werk ist der Music Server ohne Adresse eingestellt:
 * 'werk' => 'musicserver' (Modul 1.1.2) - die Auswahl nennt "aus" schlicht und den
 * Music Server "(ab Werk)". Den Hinweis unter der Auswahl behaelt die Linie
 * (TTS.ART_HINWEIS, mit dem Haken "Audioausgabe aktiv"); die Umlenkung O_AUS ist seit
 * 1.2.25 gestrichen (X-10).
 */
function fer_ansage_k() {
    /* Gruppe 19 (1.2.28, FE4 "nur sslport"): der HTTPS-Port aus general.json fuer die Umleitung auf https (ohne Wurzel
     * ist 'general' leer und ansage_sslport('') = 0 - wie bis 1.2.27). */
    return array('port' => fer_webport(), 'sslport' => ansage_sslport(fer_paths()['general']),
                 'kopf' => array('User-Agent: LoxBerry Ferien-Plugin'),
                 'ordner' => fer_tmpdir(),
                 't' => function ($s) { return fer_t($s); },
                 'werk' => 'musicserver',
                 'schluessel' => array('ART_HINWEIS' => 'TTS.ART_HINWEIS'));
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND data/plugins enthaelt UND config/system/general.json
 * traegt (Regeln/06; Gruppe 19, 1.2.28, FE2 - bis 1.2.27 genuegten
 * config/plugins und webfrontend, und ein fremder Baum mit diesen beiden
 * Ordnern galt als LoxBerry). Das trifft die uebliche Installation genauso
 * wie eine an einem anderen Ort; liegt das Plugin als entpacktes Archiv da,
 * findet es nichts und gibt einen Leerstring zurueck (fer_paths() nimmt
 * dann die Ersatzpfade unter <temp>/ferien-archiv).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function fer_paths() {
    /* Gruppe 19 (1.2.28; FE2, Entwurf 3.5 c, E-E15; Bauart abfahrt_lbhome()/abfahrt_paths() des Abfahrts-Assistenten):
     *  - Wurzel = LBHOMEDIR (mit config/plugins und data/plugins), sonst lb_wurzel_ermitteln() (streng, mit general.json);
     *  - Archivschutz: Pfade der Anlage nur, wenn diese Datei wirklich unter <wurzel>/webfrontend/html/plugins/<ordner>/
     *    liegt (installiert) ODER LBHOMEDIR und ein gueltiges LBPPLUGINDIR ausdruecklich gesetzt sind (Pruefstaende,
     *    Freigabetor: beide Variablen noetig). Sonst Ersatzpfade unter <temp>/ferien-archiv - bis 1.2.27 zeigte der
     *    Archivfall auf config/ im Archiv selbst und auf <temp>/ferien (unter Linux der Zwischenordner der Anlage);
     *  - ABWEICHUNG zu Abfahrt (begruendet): installiert gilt der eigene Ordner, NICHT LBPPLUGINDIR. Diese Bibliothek
     *    laeuft auch in fremden Prozessen (Abfahrts-Assistent und AWM-Abfuhr setzen LBPPLUGINDIR nur um das Einbinden
     *    herum auf 'ferien'); stuende dort einmal der fremde Ordner, zeigte fer_paths() auf dessen Konfiguration;
     *  - 'tmp' = /tmp/<ordner> (eine Zweitinstallation ferien_01 bekommt /tmp/ferien_01 - wie die Hakenskripte),
     *    'plugin' = Ordnername, 'general' = <wurzel>/config/system/general.json bzw. ''.
     * Ergebnis je Prozess gemerkt, Schluessel = LBHOMEDIR und LBPPLUGINDIR (Abfahrt schaltet die Umgebung um). Am Geraet
     * aendert sich kein Pfad (Ordner ferien, /tmp/ferien). */
    static $merk = array();
    $env_home = (string) getenv('LBHOMEDIR');
    $env_ordner = (string) getenv('LBPPLUGINDIR');
    $schluessel = $env_home . "\n" . $env_ordner;
    if (isset($merk[$schluessel])) { return $merk[$schluessel]; }
    $nie = array('', '.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend');
    $env_home = rtrim($env_home, '/');
    $home_ok = ($env_home !== '' && is_dir($env_home . '/config/plugins') && is_dir($env_home . '/data/plugins'));
    $wurzel = $home_ok ? $env_home : lb_wurzel_ermitteln();
    $self = basename(__DIR__);
    $ausdruecklich = basename(rtrim($env_ordner, '/'));
    $ordner = '';
    if ($wurzel !== '') {
        $inst = @realpath($wurzel . '/webfrontend/html/plugins/' . $self);
        $hier = @realpath(__DIR__);
        if (!in_array($self, $nie, true) && $inst !== false && $hier !== false && $inst === $hier) {
            $ordner = $self;
        } elseif ($home_ok && !in_array($ausdruecklich, $nie, true)
                  && preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $ausdruecklich)) {
            $ordner = $ausdruecklich;
        }
    }
    if ($ordner !== '') {
        $t = preg_replace('/[^A-Za-z0-9_\-]/', '', $ordner);
        return $merk[$schluessel] = array(
            'config' => $wurzel . '/config/plugins/' . $ordner . '/ferien.json',
            'backup' => $wurzel . '/config/plugins/' . $ordner . '.backup.json',
            'log' => $wurzel . '/log/plugins/' . $ordner . '/ferien.log',
            'datadir' => $wurzel . '/data/plugins/' . $ordner,
            'tmp' => '/tmp/' . ($t !== '' ? $t : 'ferien'),
            'lbhome' => $wurzel,
            'plugin' => $ordner,
            'general' => $wurzel . '/config/system/general.json',
        );
    }
    $a = sys_get_temp_dir() . '/ferien-archiv';
    return $merk[$schluessel] = array(
        'config' => $a . '/config/ferien.json',
        'backup' => $a . '/config/ferien.backup.json',
        'log' => $a . '/log/ferien.log',
        'datadir' => $a . '/data',
        'tmp' => $a . '/tmp',
        'lbhome' => '',
        'plugin' => in_array($self, $nie, true) ? 'ferien' : $self,
        'general' => '',
    );
}

function fer_vorgaben()
{
    /* Herausgezogen aus fer_config(): die Vorgaben stehen weiterhin an
     * EINER Stelle, jetzt aber an einer abrufbaren. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return array(
    'country' => 'DE',           // DE, AT, CH, ...
    'subdivision' => 'DE-BY',    // Bundesland/Kanton
    'lang' => 'DE',
    'school' => 1,               // Schulferien auswerten
    'public' => 1,               // gesetzliche Feiertage auswerten
    'locality' => '',            // Arbeitsort/Gemeinde fuer oertliche Sonderfaelle:
                                 // '' = alle anderen Gemeinden
                                 // 'DE-BY-AU' = Stadt Augsburg (mit Friedensfest)
                                 // 'BY-EV' = bayerische Gemeinde ohne Mariae Himmelfahrt
                                 // 'SN-KATH'/'TH-KATH' = kath. Gemeinden mit Fronleichnam
    'local_holidays' => 0,       // alle oertlichen Feiertage der Region mitzaehlen
    'bridge' => 1,               // Brueckentage erkennen
    'own' => array(),            // eigene Zeitraeume: [{name, von, bis, typ}]
    /* Gruppe 1 / MQTT (1.2.27; Entwurf ff E4, Hausherr 18.10.2026 "MQTT ab Werk ein, nur fuer neue Installationen"):
     * ab Werk 1. Eine bestehende Konfiguration traegt den Schluessel schon und bleibt, wie sie ist - das += in
     * fer_config() greift nur, wo er fehlt. Das Musterprojekt haengt ganz am MQTT-Weg; aus = keine Werte und nach
     * etwa 25 Minuten die Stoermeldung. Ohne Gateway kostet es nichts (eine Protokollzeile "kein brauchbarer
     * UDP-Eingangsport"). Bis 1.2.26: 0. */
    'mqtt_enabled' => 1,
    'mqtt_topic' => 'ferien',
    'notify' => array(),
    'tts' => array(),
    'aktionstoken' => '',        // schuetzt ?say= und ?ptest= (unangemeldeter Endpunkt)

    /* --- ab 1.2.0 ---------------------------------------------------
     * Alle neuen Schluessel haben BEWUSST den Vorgabewert, der das
     * bisherige Verhalten fortsetzt. Sie fehlen in jeder bestehenden
     * Konfiguration; das += oben traegt sie nach, und nichts aendert
     * sich, bis jemand sie in der Oberflaeche umstellt. Das ist der
     * Aktualisierungsfall, den eine Neuinstallation nie durchlaeuft.
     */
    'subdivision2' => '',        // zweite Region, NUR Schulferien (zweites Kind,
                                 // anderes Bundesland). Leer = aus.
    'group' => '',               // Schulart (groupCode der Datenquelle), z. B.
                                 // DE-MV-ABS/DE-MV-BBS. Leer = alle.
    'bridge_mode' => 'klassisch', // 'klassisch' = Fr nach Do-Feiertag / Mo vor
                                 // Di-Feiertag, wie bisher.
                                 // 'erweitert' = zusaetzlich Werktage, die
                                 // zwischen zwei freien Bloecken liegen
                                 // (die Tage zwischen Weihnachten und Neujahr).
    'bridge_luecke' => 4,        // erweitert: hoechstens so viele Werktage am Stueck.
                                 // Die 4 ist gemessen, nicht gegriffen: die Tage
                                 // zwischen Weihnachten und Neujahr sind eine Kette
                                 // aus VIER Werktagen (28.-31.12.2026, 27.-30.12.2027).
                                 // Mit 3 faellt genau der Fall heraus, um dessentwillen
                                 // es den erweiterten Modus gibt.
                                 // Gemessen fuer DE-BY ueber 366 Tage:
                                 //   Luecke 1 ->   2 Brueckentage
                                 //   Luecke 2 ->   6
                                 //   Luecke 3 ->  12 (Weihnachten fehlt)
                                 //   Luecke 4 ->  32 (Weihnachten dabei)
                                 //   Luecke 5 -> 254 - jede gewoehnliche Woche hat
                                 //               fuenf Werktage, damit waere alles
                                 //               eine Bruecke. Deshalb ist 4 die
                                 //               Obergrenze, nicht nur die Vorgabe.
    'typ_streng' => 0,           // 1 = nur 'Public' zaehlt als gesetzlicher
                                 // Feiertag; 'Bank' und 'Optional' nicht.
                                 // Fuer DE/AT ohne Wirkung - gemessen am
                                 // 18.08.2026: DE-BY 2026 liefert 14 Eintraege,
                                 // ausnahmslos Public. Wirkung hat es in LU
                                 // (Karfreitag ist dort 'Bank') und CH ('Optional').
    'halbtag_frei' => 1,         // 1 = ein halber Feiertag zaehlt als frei, wie bisher
    'ics_url' => '',             // Kalender-Abonnement (ICS) fuer eigene Termine
    'ics_typ' => 'urlaub',       // wie die Kalendereintraege gewertet werden
    'ics_filter' => '',          // nur Termine, deren Titel dies enthaelt (leer = alle)
    'urlaub_vorlauf' => 1,       // Tage vor der Rueckkehr, an denen URLAUBHEIM=1 wird

    /* --- neu in 1.2.27 (Gruppe 1, Befehlsbruecke; Entwurf gemeinsam 2.3/2.11) ----------------------------
     * "Befehle ueber MQTT annehmen" (Reiter MQTT). Ab Werk aus: jeder im Heimnetz kann ueber den UDP-Eingang des
     * MQTT-Gateways Befehle senden. Erst mit dem Haken laeuft bin/befehlsbruecke.php (Waechter in bin/cron.php). */
    'befehle_mqtt_ein' => 0,

    /* --- neu in 1.2.28 (Gruppe 19; Entwurf 2.8) - alle ab Werk 0 ------------------------------------------------------
     * endpunkt_ein: "HTTP-Weg fuer Loxone annehmen" (Reiter Einbindung). Eine Neuinstallation antwortet auf ferien.php mit
     *   403; eine bestehende Anlage behaelt den Weg (Lese-Regel in fer_config() und postupgrade.sh: Datei mit Inhalt ohne
     *   den Schluessel -> 1). Abfahrts-Assistent und AWM-Abfuhr lesen ohne ihn die Bibliothek.
     * haus_kalender_ein: "Kalendertage an andere Plugins geben (haus/kalender/...)" (Reiter MQTT, V1).
     * ics_jaehrlich: "Jaehrlich wiederkehrende Ganztagstermine uebernehmen (nur mit Filterwort)" (V4 Option E). */
    'endpunkt_ein' => 0,
    'haus_kalender_ein' => 0,
    'ics_jaehrlich' => 0,
);
}

/**
 * Traegt diese Datei ueberhaupt etwas?
 *
 * Nicht "ist sie leer?", sondern "laesst sie sich als JSON-Objekt mit
 * mindestens einem Schluessel lesen?". Der Unterschied ist gemessen
 * (18.09.2026, WSL/Ubuntu, PHP 8.3.6, Pruefung-FerienFeiertage-1.2.13):
 * eine ABGESCHNITTENE ferien.json - weder leer noch "{}", aber fuer
 * json_decode unbrauchbar - ging bis 1.2.12 an BEIDEN Heilstellen vorbei.
 * Der unangemeldete Endpunkt antwortete danach
 * "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET", und der naechste Aufruf der
 * Oberflaeche wuerfelte ein neues Aktionstoken und kopierte es ueber die
 * Zweitschrift: jede Loxone-Adresse mit dem alten Token bekommt HTTP 403.
 * Bauart: Sprachsteuerung 0.11.7 (sp_inhalt_oder_null()), Intercom 2.2.11.
 *
 * Rueckgabe: die gelesenen Daten oder null, wenn die Datei nichts traegt.
 */
function fer_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) { return null; }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') { return null; }
    $d = json_decode($roh, true);
    if (!is_array($d) || $d === array()) { return null; }
    return $d;
}

/**
 * Traegt diese Konfiguration das, was nur sie tragen kann?
 *
 * Das Aktionstoken. Es steht in JEDER Loxone-Adresse dieses Plugins
 * (?say=, ?ptest=, ?selftest=); geht es verloren, scheitern alle virtuellen
 * Eingaenge im Miniserver, und es gibt keinen Weg, es zurueckzurechnen.
 * Alles andere - Bundesland, Meldezeit, eigene Termine - laesst sich in der
 * Oberflaeche noch einmal eintragen.
 *
 * Eine Konfiguration OHNE Token gibt es auf keinem Weg der Oberflaeche:
 * index.php fuellt es beim ersten Seitenaufbau. Steht dort keines, ist die
 * Datei nicht aus einem gespeicherten Stand hervorgegangen - dann wird aus
 * der Zweitschrift geheilt, statt ein NEUES Token zu wuerfeln.
 */
function fer_config_hat_inhalt($c)
{
    return is_array($c) && $c !== array()
        && trim((string) (isset($c['aktionstoken']) ? $c['aktionstoken'] : '')) !== '';
}

/**
 * Die Heilungsentscheidung - EINE Funktion, ZWEI Aufrufer.
 *
 * Aufgerufen wird sie aus fer_config() (gilt fuer jeden Weg, auch fuer den
 * unangemeldeten Endpunkt und den Cron) und ganz oben in
 * webfrontend/htmlauth/index.php, wo bis 1.2.12 eine wortgleiche Abschrift
 * derselben Entscheidung stand. Zwei Abschriften laufen auseinander; die
 * eine greift dann an der anderen vorbei.
 *
 * Entschieden wird nach INHALT, nicht nach Form: nicht "fehlt die Datei,
 * ist sie leer oder '{}'", sondern "traegt sie noch das Aktionstoken".
 * Geheilt wird nur aus einer Zweitschrift, die selbst Inhalt traegt - ein
 * Stand ohne Aktionstoken darf keinen anderen ersetzen, in keiner der
 * beiden Richtungen. Was vorher in der Datei stand, wird nicht weggeworfen,
 * sondern liegt als ferien.json.kaputt daneben (0600: es koennen Zugangs-
 * daten darin stehen). Der reine Aktualisierungsfall "{}" hinterlaesst
 * keine .kaputt-Datei - dort ging nichts verloren.
 *
 * Rueckgabe: true, wenn wirklich geheilt wurde.
 */
function fer_selbstheilung()
{
    $p = fer_paths();
    if (fer_config_hat_inhalt(fer_inhalt_oder_null($p['config']))) {
        return false;
    }
    if (!fer_config_hat_inhalt(fer_inhalt_oder_null($p['backup']))) {
        return false;
    }
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    $alt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $rest = preg_replace('/\s+/', '', $alt);
    $verdraengt = ($rest !== '' && $rest !== '{}' && $rest !== '[]');
    /* C8/C9 (Durchgang 30.09.2026): ueber denselben Weg wie jede andere
     * Konfigurationsschreibung - Nebendatei, 0600 vor dem Inhalt,
     * Laengenvergleich, rename (fer_datei_schreiben). Bis 1.2.15 stand hier
     * copy(): die Konfiguration entstand mit den Vorgaberechten (644 neben
     * einer Zweitschrift mit 600, auch aus dem unangemeldeten Endpunkt), und
     * ein gleichzeitiger Leser sah sie halb geschrieben. */
    if ($verdraengt) {
        fer_datei_schreiben($p['config'] . '.kaputt', $alt, 0600);
    }
    $zweit = @file_get_contents($p['backup']);
    if (!is_string($zweit) || !fer_datei_schreiben($p['config'], $zweit, 0600)) {
        return false;
    }
    fer_log('Die Konfiguration trug kein Aktionstoken und wurde aus der Zweitschrift '
        . 'wiederhergestellt: ' . $p['backup']
        . ($verdraengt ? ' (der vorherige Inhalt liegt unter ' . $p['config'] . '.kaputt)' : '')
        . '.');
    return true;
}

/**
 * Was die Zweitschrift traegt und der neue Stand nicht.
 *
 * Leere Rueckgabe heisst: die Zweitschrift darf erneuert werden. Verglichen
 * wird, ob ein SCHLUESSEL fehlt oder leer ist, nicht ob sich ein Wert
 * geaendert hat. Ein leeres Aktionstoken gibt es auf keinem Weg der
 * Oberflaeche und gilt deshalb als fehlend: gemessen am 18.09.2026 schrieb
 * der Knopf "Speichern" bei unlesbarer Konfiguration ein leeres Token in
 * die Zweitschrift und zerstoerte damit den einzigen Rueckweg.
 *
 * Bauart uebernommen aus Sprachsteuerung 0.11.7 / Intercom 2.2.11: eine
 * Zweitschrift MIT Inhalt wird nie durch einen Stand OHNE Inhalt ersetzt.
 * Das Speichern selbst wird nicht verhindert - nur der Rueckweg bleibt
 * stehen, und das Protokoll sagt es.
 */
function fer_zweitschrift_fehlt($sicherung, array $neu, array $felder)
{
    $z = fer_inhalt_oder_null($sicherung);
    if ($z === null) { return array(); }
    $fehlt = array();
    foreach ($felder as $feld) {
        if (!array_key_exists($feld, $z)) { continue; }
        $hat_z = is_string($z[$feld]) ? (trim($z[$feld]) !== '') : !empty($z[$feld]);
        if (!$hat_z) { continue; }
        $hat_n = array_key_exists($feld, $neu)
               && (is_string($neu[$feld]) ? (trim($neu[$feld]) !== '') : true);
        if (!$hat_n) { $fehlt[] = $feld; }
    }
    return $fehlt;
}

/** Die Zweitschrift erneuern - oder begruendet nicht. */
function fer_zweitschrift_ziehen($quelle, $ziel, array $neu, array $felder, $rechte = null)
{
    $fehlt = fer_zweitschrift_fehlt($ziel, $neu, $felder);
    if ($fehlt) {
        fer_log('WARNUNG: Die Zweitschrift bleibt unveraendert - der gespeicherte Stand '
            . 'traegt nicht, was dort steht (' . implode(', ', $fehlt) . '): ' . $ziel);
        return false;
    }
    /* Nicht copy(): copy() oeffnet das Ziel mit O_TRUNC, die heile
     * Zweitschrift ist sofort leer und wird erst danach gefuellt. Scheitert
     * das Schreiben (volle Karte), bleibt sie mit 0 Byte zurueck - gemessen
     * 18.09.2026 in WSL unter ulimit -f 0 (Pruefung-FerienFeiertage-1.2.14,
     * Fall P5; Bauart Bestand-2026-09-18/klasse-D, Abschnitt 4b). Deshalb wie
     * fer_json_schreiben(): Nebendatei, dann rename(). Die Nebendatei bekommt
     * die Rechte VOR dem Inhalt, und zwar die der Quelle (CLAUDE.md 9:
     * gleiche Rechte wie das Original) - copy() legte eine fehlende
     * Zweitschrift mit den Vorgaberechten an, gemessen 644 neben einer
     * Konfiguration mit 600 (Fall P7). */
    $roh = @file_get_contents($quelle);
    if ($roh === false) { return false; }
    /* I7 (Durchgang 30.09.2026): die Zweitschrift traegt das Aktionstoken und
     * bekommt immer 0600 - dieselben Rechte, die die Konfiguration seit 1.2.16
     * auf jedem Schreibweg bekommt. */
    $modus = ($rechte !== null) ? $rechte : 0600;
    $tmp = $ziel . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.neu';
    if (@file_put_contents($tmp, '') === false) { return false; }
    if ($modus) { @chmod($tmp, $modus); }
    if (@file_put_contents($tmp, $roh) !== strlen($roh)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function fer_config() {
    $p = fer_paths();
    fer_selbstheilung();
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    /* Gruppe 19 (1.2.28, Entscheidung 2, E-E16): eine Konfiguration MIT Inhalt ohne endpunkt_ein stammt aus einer
     * frueheren Fassung - der HTTP-Weg bleibt dort an (Uebergang; postupgrade.sh schreibt den Wert zusaetzlich fest).
     * Keine Datei oder der postinstall-Rohling "{}" -> Vorgabe 0. EINE Stelle fuer Oberflaeche, Cron, ferien.php und
     * Befehlsbruecke; eine aus der Zweitschrift geheilte Bestandsdatei bekommt ebenfalls 1. */
    if ($cfg && !array_key_exists('endpunkt_ein', $cfg)) { $cfg['endpunkt_ein'] = 1; }
    $cfg += fer_vorgaben();
    if (!is_array($cfg['own'])) { $cfg['own'] = array(); }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['notify'] += array(
        'audio' => 0,
        'push' => 0,
        'time' => '19:00',           // Vorabend-Meldung
        'freetag' => 1,              // melden, wenn morgen schulfrei ist
        'ferienstart' => 1,          // melden am Vorabend des Ferienbeginns
        'bridge_month' => 1,         // im Januar die Brueckentage des Jahres melden
    );
    /* Nr. 36 b, Stufe 2: die Vorgaben des Blocks tts kommen aus der gemeinsamen
     * Sprachausgabe - dieselben Schluessel wie bis 1.2.22 (dazu sonos_zone/sonos_laut
     * seit Modul 1.1.0, hier ohne Wirkung). Ab Werk weiterhin "musicserver" ohne
     * Adresse: gesendet wird erst mit eingetragener Adresse. Vervollstaendigt, nicht
     * ersetzt - gespeicherte Werte bleiben, wie sie sind. Die Sprechtoken sind
     * Geheimnisse: nie im Formular, nicht in der Sicherung. */
    list($cfg['tts']) = ansage_vervollstaendigen($cfg['tts'], 'musicserver');
    return $cfg;
}

/**
 * JSON in eine Datei schreiben - ganz oder gar nicht.
 *
 * Zwei Fallen stecken darin, und das Plugin ist bis 1.0.1 in beide getreten:
 *
 * 1. json_encode liefert bei ungueltigem UTF-8 nicht etwa eine Ausnahme,
 *    sondern false. file_put_contents($pfad, false) schreibt daraufhin eine
 *    Datei mit NULL Bytes - und gibt 0 zurueck, nicht false. Der Aufrufer
 *    haelt das fuer einen Erfolg und hat eine leere Datei.
 * 2. Wird direkt in die Zieldatei geschrieben, kann ein gleichzeitig
 *    lesender Prozess sie halb gefuellt erwischen. Beim Zustand passiert das
 *    regelmaessig: der Cron schreibt state.json, waehrend Loxone ferien.php
 *    abruft. Ergebnis: json_decode scheitert, und Loxone bekommt Nullen.
 *
 * Deshalb: erst in eine eigene Datei mit unverwechselbarem Namen (Prozess-
 * nummer plus Zufall - zwei Cron-Laeufe duerfen sich nicht gegenseitig die
 * Zwischendatei wegziehen), dann rename(). rename() ist innerhalb eines
 * Dateisystems unteilbar: ein Leser sieht entweder die alte oder die neue
 * Datei, nie eine halbe.
 */
function fer_json_schreiben($pfad, $daten, $modus = 0600) {
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        fer_log('FEHLER: ' . basename($pfad) . ' konnte nicht erzeugt werden ('
            . json_last_error_msg() . ') - die vorhandene Datei bleibt unveraendert.');
        return false;
    }
    return fer_datei_schreiben($pfad, $js, $modus);
}

/**
 * Eine Datei ganz oder gar nicht schreiben (C8, C9, C10; Durchgang 30.09.2026).
 *
 * Nebendatei mit Prozessnummer und Zufall, Rechte VOR dem Inhalt, dann der
 * Inhalt, dann der Laengenvergleich - "file_put_contents() !== false" ist
 * kein Erfolg, eine volle Karte schreibt kuerzer -, dann rename(). Scheitert
 * ein Schritt, wird die Nebendatei entfernt; es bleibt nichts liegen. Bis
 * 1.2.15 hiess ein Fehlschlag hier "=== false", und je Versuch blieb eine
 * leere Nebendatei liegen (gemessen im 8-kB-tmpfs). Konfiguration,
 * Zweitschrift, Heilung, termine.json, kalender.json und state.json gehen
 * alle ueber diese Funktion.
 *
 * Rueckgabe: true nur, wenn die Zieldatei jetzt genau diesen Inhalt traegt.
 */
function fer_datei_schreiben($pfad, $inhalt, $modus = 0600) {
    $inhalt = (string) $inhalt;
    $verz = dirname($pfad);
    if (!is_dir($verz)) { @mkdir($verz, 0775, true); }
    $tmp = $pfad . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, '') === false) {
        @unlink($tmp);
        fer_log('FEHLER: ' . $tmp . ' liess sich nicht anlegen - Platz? Rechte?');
        return false;
    }
    if ($modus !== null) { @chmod($tmp, $modus); }
    $n = @file_put_contents($tmp, $inhalt);
    if ($n !== strlen($inhalt)) {
        @unlink($tmp);
        fer_log('FEHLER: ' . basename($pfad) . ' liess sich nicht vollstaendig schreiben ('
            . ($n === false ? 'kein Byte' : $n . ' von ' . strlen($inhalt) . ' Byte')
            . ') - Platz? Rechte? Die vorhandene Datei bleibt unveraendert.');
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        fer_log('FEHLER: ' . basename($pfad) . ' liess sich nicht ersetzen.');
        return false;
    }
    return true;
}

/**
 * Eine Sperre, damit sich zwei Laeufe nicht ueberholen.
 *
 * Der Minutencron startet cron.php jede Minute neu. Ein Durchlauf kann
 * laenger dauern: fer_http wartet bis zu 20 s je Endpunkt, bei zwei
 * Endpunkten sind das 40 s, dazu kommt im Zweifel noch eine Ansage mit
 * 10 s. Haengt openholidaysapi.org, stapeln sich die Laeufe - und jeder von
 * ihnen schreibt am Ende in dieselben Dateien.
 *
 * Rueckgabe: der offene Dateizeiger (den der Aufrufer offen halten muss,
 * denn mit ihm faellt die Sperre) oder false, wenn schon jemand laeuft.
 */
function fer_sperre($name = 'cron') {
    $f = fer_tmpdir() . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.lock';
    $fh = @fopen($f, 'c');
    if ($fh === false) {
        // Nicht stillschweigend weiterlaufen: ohne Sperre ist der Schaden
        // groesser als ohne Lauf, und ohne Meldung sucht niemand danach.
        fer_log('WARNUNG: Sperrdatei ' . $f . ' laesst sich nicht oeffnen - '
              . 'Platz im Verzeichnis und Eigentuemer pruefen.');
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/**
 * Der Zwischenordner (I9, Durchgang 30.09.2026).
 *
 * Fest ist /tmp/ferien (fer_paths()). Gehoert er einem anderen Benutzer -
 * etwa weil ein Lauf nach einem Neustart von Hand als root gestartet wurde -
 * oder ist er nicht beschreibbar, tat das Plugin bis 1.2.15 bis zum naechsten
 * Neustart nichts mehr: jede Sperre scheiterte, jeder Lauf meldete BUSY, und
 * das Protokoll bekam jede Minute dieselbe Warnung. Jetzt weicht es auf
 * <ordner>-<uid> aus und sagt das EINMAL im Protokoll (der Merker liegt im
 * Ausweichordner) und im Reiter Test (Zeile "Zwischenordner").
 */
function fer_tmpdir() {
    $p = fer_paths();
    $t = $p['tmp'];
    if (!is_dir($t)) { @mkdir($t, 0775, true); }
    if (fer_tmp_taugt($t)) { return $t; }
    $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'eigen';
    $aus = $t . '-' . $uid;
    if (!is_dir($aus)) { @mkdir($aus, 0700, true); }
    if (!fer_tmp_taugt($aus)) { return $t; }
    $GLOBALS['fer_tmp_ausweich'] = array($t, $aus);
    fer_tmp_ausweich_melden($t, $aus);
    return $aus;
}

/** Ist der Ordner da, beschreibbar und - wo messbar - der eigene? */
function fer_tmp_taugt($t) {
    clearstatcache(true, $t);
    if (!is_dir($t) || !is_writable($t)) { return false; }
    if (function_exists('posix_geteuid') && @fileowner($t) !== posix_geteuid()) { return false; }
    foreach (array('cron.lock', 'state.json') as $n) {
        if (is_file($t . '/' . $n) && !is_writable($t . '/' . $n)) { return false; }
    }
    return true;
}

/** Die Ausweichmeldung, gebremst: nur wenn sie sich aendert. Nicht ueber
 *  fer_log_if_changed() - das fragt selbst nach fer_tmpdir(). */
function fer_tmp_ausweich_melden($t, $aus) {
    $merk = $aus . '/last_tmpordner.txt';
    $zeile = 'WARNUNG: Der Zwischenordner ' . $t . ' ist nicht beschreibbar oder gehoert einem '
           . 'anderen Benutzer - das Plugin arbeitet mit ' . $aus . '. Den Ordner ' . $t
           . ' bitte entfernen; er entsteht dann mit den richtigen Rechten neu.';
    if (is_file($merk) && (string) @file_get_contents($merk) === $zeile) { return; }
    fer_log($zeile);
    @file_put_contents($merk, $zeile);
}
function fer_datadir() {
    $p = fer_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    return $p['datadir'];
}

/* ---------------- Protokoll ---------------- */

function fer_log($msg) {
    $p = fer_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function fer_log_if_changed($key, $line) {
    $f = fer_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) {
        fer_log($key . ': ' . $line);
        @file_put_contents($f, $line);
    }
}

/* ---------------- Datenabruf (OpenHolidays API) ---------------- */

function fer_http($url, $tmo = 20) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $tmo, 'user_agent' => 'LoxBerry Ferien-Plugin', 'follow_location' => 1,
        'header' => "Accept: application/json\r\n",
    )));
    return @file_get_contents($url, false, $ctx);
}

/**
 * Wie fer_http(), liefert aber den HTTP-Status mit (O4, Reiter Test).
 *
 * Die Selbstpruefung hielt bis 1.2.15 jede fehlende Antwort fuer eine
 * Abweisung ("$falsch === false"): ein Endpunkt, der ein falsches Token mit
 * HTTP 500 beantwortete, bekam einen Haken. Der Status kommt hier aus
 * stream_get_meta_data(), nicht aus $http_response_header (unter PHP 8.5
 * missbilligt). Rueckgabe: array(Status oder 0, Inhalt oder '').
 */
function fer_http_status($url, $tmo = 3) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $tmo, 'user_agent' => 'LoxBerry Ferien-Plugin', 'ignore_errors' => true,
        'follow_location' => 0,
    )));
    $fh = @fopen($url, 'r', false, $ctx);
    if ($fh === false) { return array(0, ''); }
    $meta = stream_get_meta_data($fh);
    $inhalt = (string) @stream_get_contents($fh);
    fclose($fh);
    $code = 0;
    foreach ((array) (isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array()) as $z) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $z, $m)) { $code = (int) $m[1]; }
    }
    return array($code, $inhalt);
}

function fer_datafile() {
    return fer_datadir() . '/termine.json';
}

/* ---------------- Termindatei: Stand, Region, Abdeckung (1.2.16) ---------------- */

/** Der Abrufabstand: eine Woche. Entscheidung 4 (angepasst an Kalenderdaten)
 *  rechnet WARN ab dem Dreifachen davon. */
define('FER_ABRUF_TAKT', 7 * 86400);

/** Die Termindatei roh lesen - array oder null. */
function fer_termine_roh() {
    $f = fer_datafile();
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Die Region, fuer die abgerufen wird - so normiert, wie fer_fetch() sie in
 *  die Anfrage schreibt. */
function fer_region($cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $land = preg_replace('/[^A-Z]/', '', strtoupper(is_string($cfg['country']) ? $cfg['country'] : '')) ?: 'DE';
    $sub = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(is_string($cfg['subdivision']) ? $cfg['subdivision'] : ''));
    $gruppe = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(is_string($cfg['group']) ? $cfg['group'] : ''));
    $sub2 = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(is_string($cfg['subdivision2']) ? $cfg['subdivision2'] : ''));
    if ($sub2 === $sub || empty($cfg['school'])) { $sub2 = ''; }
    return array('land' => $land, 'sub' => $sub, 'gruppe' => $gruppe, 'sub2' => $sub2);
}

/**
 * Gehoert die Termindatei zur eingestellten Region? (I2, I5, C3)
 *
 * Bis 1.2.15 wurde das nirgends gefragt. Eine Termindatei einer anderen
 * Region - aus einer liegengebliebenen Update-Sicherung oder aus einem Abruf
 * in der Update-Luecke mit den Vorgaben - galt bis zu sieben Tage als
 * gueltig, und Loxone bekam die Ferien eines anderen Bundeslandes.
 */
function fer_region_passt($d, $region) {
    if (!is_array($d) || !isset($d['land'], $d['sub'])) { return false; }
    return (string) $d['land'] === $region['land']
        && (string) $d['sub'] === $region['sub']
        && (string) (isset($d['gruppe']) ? $d['gruppe'] : '') === $region['gruppe'];
}

/** Der Zeitpunkt des letzten GELUNGENEN Abrufs (C4). Termindateien vor 1.2.16
 *  kennen 'stand_ok' nicht; dort ist 'stand' der Zeitpunkt des Schreibens. */
function fer_stand_ok($d) {
    if (!is_array($d)) { return ''; }
    if (array_key_exists('stand_ok', $d)) { return (string) $d['stand_ok']; }
    return isset($d['stand']) ? (string) $d['stand'] : '';
}

/** Tage seit dem letzten gelungenen Abruf, -1 = keiner bekannt. */
function fer_alter_tage($d) {
    $s = fer_stand_ok($d);
    $t = $s !== '' ? strtotime($s) : false;
    if ($t === false) { return -1; }
    return max(0, (int) round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $t))) / 86400));
}

/** Tragen die gespeicherten Daten heute UND morgen? (Entscheidung 4, angepasst
 *  an Kalenderdaten: OK haengt an der Abdeckung, nicht am Alter.) */
function fer_termine_abdeckung($d) {
    if (!is_array($d) || empty($d['bis'])) { return false; }
    if (!empty($d['luecken'])) { return false; }
    if (empty($d['ferien']) && empty($d['feiertage'])) { return false; }
    return (string) $d['bis'] >= date('Y-m-d', strtotime('+1 day'));
}

/** Frisch genug, um den Abruf auszulassen: vollstaendig, in den letzten 7
 *  Tagen gelungen, reicht mehr als 60 Tage voraus. */
function fer_termine_frisch($d) {
    if (!fer_termine_abdeckung($d) || !empty($d['teilausfall'])) { return false; }
    $s = fer_stand_ok($d);
    $t = $s !== '' ? strtotime($s) : false;
    if ($t === false || time() - $t >= FER_ABRUF_TAKT) { return false; }
    return (string) $d['bis'] > date('Y-m-d', strtotime('+60 days'));
}

/* ---------------- Neuversuch mit steigendem Abstand (C4, C7) ---------------- */

function fer_abruf_merker() { return fer_datadir() . '/abruf.json'; }

/** Sekunden bis zum naechsten erlaubten Versuch, 0 = jetzt. */
function fer_abruf_bremse() {
    $f = fer_abruf_merker();
    if (!is_file($f)) { return 0; }
    $m = json_decode((string) @file_get_contents($f), true);
    if (!is_array($m) || !isset($m['naechster'])) { return 0; }
    return max(0, (int) $m['naechster'] - time());
}

/** Einen misslungenen Versuch vermerken - naechster nach 1 h, 6 h, dann 24 h -
 *  und genau EINE Zeile ins Protokoll schreiben. */
function fer_abruf_fehlschlag($teile, $zusatz) {
    $f = fer_abruf_merker();
    $m = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $n = (is_array($m) && isset($m['fehl'])) ? (int) $m['fehl'] + 1 : 1;
    $stufen = array(3600, 6 * 3600, 24 * 3600);
    $warten = $stufen[min($n, count($stufen)) - 1];
    fer_json_schreiben($f, array('fehl' => $n, 'zuletzt' => time(), 'naechster' => time() + $warten,
                                 'teile' => array_values($teile)));
    fer_log('Abruf gescheitert (' . implode(', ', $teile) . ')' . $zusatz
        . ' - naechster Versuch in ' . (int) ($warten / 3600) . ' h (Fehlversuch ' . $n . ').');
}

function fer_abruf_gelungen() {
    $f = fer_abruf_merker();
    if (is_file($f)) { @unlink($f); }
}

/**
 * Den Namen eines Eintrags in der gewuenschten Sprache holen.
 *
 * Ausgelagert, weil ihn seit 1.2.0 drei Stellen brauchen: die beiden
 * Endpunkte der Hauptregion und der Abruf der zweiten Region. Eine zweite
 * Abschrift waere eine zweite Stelle, die auseinanderlaufen kann.
 */
function fer_name($e, $lang) {
    if (!isset($e['name'][0]['text'])) { return ''; }
    foreach ($e['name'] as $n) {
        if (isset($n['language']) && strtoupper($n['language']) === strtoupper($lang)) {
            return (string) $n['text'];
        }
    }
    return (string) $e['name'][0]['text'];
}

/**
 * Aus einer Antwort der Datenquelle einen eigenen Datensatz bauen.
 *
 * Bis 1.1.7 bestand er aus genau drei Schluesseln - von, bis, name. Alles
 * andere wurde verworfen, obwohl die Quelle es mitliefert. Gemessen am
 * 18.08.2026 an der Schnittstelle selbst fuehrt ein Eintrag ausserdem:
 *
 *   type           Public | Bank | Optional | School | EndOfLessons | BackToSchool
 *   temporalScope  FullDay | HalfDay
 *   comment        z. B. "ab 12:00 Uhr" - die Uhrzeit des halben Tages steht
 *                  NUR hier, es gibt kein eigenes Feld dafuer
 *   nationwide     gilt im ganzen Land
 *
 * Was daran haengt, ist nicht theoretisch: Luxemburg fuehrt Karfreitag als
 * 'Bank' (kein allgemein arbeitsfreier Tag), die Schweiz drei Halbtage,
 * Frankreich sechs 'EndOfLessons'-Zeitraeume, die sich mit den Ferien
 * ueberlappen und ohne 'type' als zweiter Ferienblock mitzaehlen.
 *
 * Fuer die eigene Anlage ist es folgenlos - DE-BY 2026 liefert 14 Eintraege,
 * ausnahmslos Public und FullDay. Es wird erst zum Fehler, wenn jemand das
 * Laenderfeld benutzt, und das bietet zehn Laender an.
 */
function fer_rec($e, $name) {
    $hinweis = '';
    if (isset($e['comment']) && is_array($e['comment'])) {
        foreach ($e['comment'] as $c) {
            if (isset($c['text']) && trim((string) $c['text']) !== '') {
                $hinweis = trim((string) $c['text']);
                break;
            }
        }
    }
    return array(
        'von'  => substr((string) $e['startDate'], 0, 10),
        'bis'  => substr((string) (isset($e['endDate']) ? $e['endDate'] : $e['startDate']), 0, 10),
        'name' => (string) $name,
        'art'  => isset($e['type']) ? (string) $e['type'] : '',
        'halbtag' => (isset($e['temporalScope']) && $e['temporalScope'] === 'HalfDay') ? 1 : 0,
        'hinweis' => $hinweis,
        'bundesweit' => !empty($e['nationwide']) ? 1 : 0,
    );
}

/**
 * Laedt Ferien und Feiertage fuer die naechsten 18 Monate.
 *
 * Rueckgabe: [ok, quelle]. ok = 1 heisst: es liegen Daten der eingestellten
 * Region vor, die heute und morgen abdecken. quelle ist
 *   'cache'          nichts zu tun, der Stand ist frisch
 *   'gebremst'       ein Neuversuch ist noch nicht faellig
 *   'frisch'         alles geholt
 *   'teilweise'      ein Teilabruf scheiterte, sein gespeicherter Stand blieb
 *   'cache-fallback' alles scheiterte, der gespeicherte Stand gilt weiter
 *   'FEHLGESCHLAGEN' nichts da, oder die Datei liess sich nicht schreiben
 *
 * WAS SICH IN 1.2.16 GEAENDERT HAT (C3, C4, C7, C10; Durchgang 30.09.2026)
 *
 * - Teilausfall: scheiterte bis 1.2.15 nur einer von zwei Endpunkten, wurde
 *   sein Topf LEER geschrieben, und die Datei galt sieben Tage als frisch -
 *   mitten in den Ferien FERIEN=0 und SCHULTAG=1 (gemessen: SchoolHolidays
 *   500). Jetzt behaelt ein gescheiterter Teil seinen gespeicherten Stand,
 *   steht im Protokoll, und die Datei gilt nicht als frisch.
 * - Kein touch mehr: der Zeitpunkt des letzten gelungenen Abrufs steht in der
 *   Datei ('stand_ok'). Der touch machte einen gescheiterten Abruf zu einem
 *   Stand "vor 0 Tagen".
 * - Neuversuch mit steigendem Abstand (1 h, 6 h, 24 h) statt jede Minute
 *   (ohne Daten: 2880 Anfragen und 1440 Protokollzeilen am Tag) oder erst
 *   nach sieben Tagen (mit Daten).
 * - Die Region der Termindatei wird geprueft; eine fremde gilt nicht.
 * - Ein gescheitertes Schreiben ist ein Fehlschlag, kein 'frisch'.
 */
function fer_fetch($force = false) {
    $cfg = fer_config();
    $f = fer_datafile();
    $alt = fer_termine_roh();
    $region = fer_region($cfg);
    $passt = fer_region_passt($alt, $region);
    if (!$force) {
        if ($passt && fer_termine_frisch($alt)) {
            return array(1, 'cache');
        }
        if (fer_abruf_bremse() > 0) {
            return array(($passt && fer_termine_abdeckung($alt)) ? 1 : 0, 'gebremst');
        }
    }
    $von = date('Y-m-d', strtotime('-30 days'));
    $bis = date('Y-m-d', strtotime('+18 months'));
    $land = $region['land'];
    $sub = $region['sub'];
    $lang = preg_replace('/[^A-Z]/', '', strtoupper(is_string($cfg['lang']) ? $cfg['lang'] : '')) ?: 'DE';
    $base = 'https://openholidaysapi.org/';
    $q = 'countryIsoCode=' . rawurlencode($land) . '&languageIsoCode=' . rawurlencode($lang)
       . ($sub !== '' ? '&subdivisionCode=' . rawurlencode($sub) : '')
       . '&validFrom=' . $von . '&validTo=' . $bis;
    /* Schulart (groupCode). Die Datenquelle fuehrt das nur dort, wo ein Land
     * seine Ferien nach Schulart trennt - gemessen am 18.08.2026 sind das in
     * Deutschland genau zwei Gruppen, beide fuer Mecklenburg-Vorpommern
     * (DE-MV-ABS "Allgemeinbildende Schulen", DE-MV-BBS "Berufliche Schulen").
     * Ohne den Zusatz bekommt man dort beide vermischt. */
    $gruppe = $region['gruppe'];
    $qs = $q . ($gruppe !== '' ? '&groupCode=' . rawurlencode($gruppe) : '');
    $out = array('von' => $von, 'bis' => $bis, 'stand' => date('c'), 'stand_ok' => '',
                 'land' => $land, 'sub' => $sub, 'gruppe' => $gruppe,
                 'ferien' => array(), 'feiertage' => array(), 'ferien2' => array(),
                 'sub2' => '', 'teilausfall' => array(), 'luecken' => array());
    $fehl = array();
    $geholt = 0;
    foreach (array('school' => 'SchoolHolidays', 'public' => 'PublicHolidays') as $key => $ep) {
        if (empty($cfg[$key])) {
            continue;
        }
        $topf = ($ep === 'SchoolHolidays') ? 'ferien' : 'feiertage';
        $js = fer_http($base . $ep . '?' . ($ep === 'SchoolHolidays' ? $qs : $q));
        $d = @json_decode((string) $js, true);
        if (!is_array($d)) {
            $fehl[] = $topf;
            continue;
        }
        $geholt++;
        foreach ($d as $e) {
            if (!isset($e['startDate'])) {
                continue;
            }
            // Oertliche Feiertage (z. B. Augsburger Friedensfest) nur uebernehmen,
            // wenn der Arbeitsort passt oder ausdruecklich alle gewuenscht sind
            if ($ep === 'PublicHolidays' && isset($e['regionalScope']) && $e['regionalScope'] === 'Local') {
                $passt_ort = !empty($cfg['local_holidays']);
                if (!$passt_ort && (string) $cfg['locality'] !== '' && strpos((string) $cfg['locality'], '-') !== false) {
                    foreach ((array) (isset($e['subdivisions']) ? $e['subdivisions'] : array()) as $sd) {
                        if (isset($sd['code']) && $sd['code'] === $cfg['locality']) { $passt_ort = true; break; }
                    }
                }
                if (!$passt_ort) { continue; }
            }
            $name = fer_name($e, $lang);
            $out[$topf][] = fer_rec($e, $name);
        }
    }

    /* Zweite Region - NUR Schulferien.
     *
     * Der Fall ist ein Haushalt mit zwei Kindern in zwei Bundeslaendern (oder
     * ein Pendler mit Arbeitsort anderswo). Gesetzliche Feiertage werden
     * BEWUSST nicht ein zweites Mal geholt: sie haengen am Wohnort, nicht an
     * der Schule, und zwei Feiertagslisten wuerden die Brueckentags- und
     * Schulfrei-Rechnung mehrdeutig machen. Was die zweite Region liefert,
     * steht getrennt in 'ferien2' und geht als eigene Feldgruppe an Loxone.
     */
    $sub2 = $region['sub2'];
    if ($sub2 !== '') {
        $q2 = 'countryIsoCode=' . rawurlencode($land) . '&languageIsoCode=' . rawurlencode($lang)
            . '&subdivisionCode=' . rawurlencode($sub2)
            . '&validFrom=' . $von . '&validTo=' . $bis;
        $js2 = fer_http($base . 'SchoolHolidays?' . $q2);
        $d2 = @json_decode((string) $js2, true);
        if (is_array($d2)) {
            $geholt++;
            $out['sub2'] = $sub2;
            foreach ($d2 as $e) {
                if (!isset($e['startDate'])) { continue; }
                $name = fer_name($e, $lang);
                $out['ferien2'][] = fer_rec($e, $name);
            }
            usort($out['ferien2'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
        } else {
            $fehl[] = 'ferien2';
        }
    }
    if ($fehl && $geholt === 0) {
        if ($passt && is_array($alt)) {
            fer_abruf_fehlschlag($fehl, ' - der gespeicherte Stand vom '
                . substr(fer_stand_ok($alt), 0, 10) . ' gilt weiter');
            return array(fer_termine_abdeckung($alt) ? 1 : 0, 'cache-fallback');
        }
        fer_abruf_fehlschlag($fehl, ' - es sind keine Daten dieser Region gespeichert');
        return array(0, 'FEHLGESCHLAGEN');
    }
    /* Teilausfall (C3): ein gescheiterter Teil behaelt seinen GESPEICHERTEN
     * Stand - aber nur, wenn der zur eingestellten Region gehoert. Die Datei
     * reicht dann nur so weit wie der aeltere Teil, traegt den Ausfall in
     * 'teilausfall' und gilt nicht als frisch; ihr 'stand_ok' bleibt der des
     * letzten vollstaendigen Abrufs. Ein Teil ohne gespeicherten Stand steht
     * in 'luecken' - dann ist OK=0. */
    foreach ($fehl as $topf) {
        $alt_passt = $passt && isset($alt[$topf]) && is_array($alt[$topf])
            && ($topf !== 'ferien2' || (string) (isset($alt['sub2']) ? $alt['sub2'] : '') === $sub2);
        if ($alt_passt) {
            $out[$topf] = $alt[$topf];
            if ($topf === 'ferien2') { $out['sub2'] = $sub2; }
            if (!empty($alt['bis']) && (string) $alt['bis'] < $out['bis']) { $out['bis'] = (string) $alt['bis']; }
        } else {
            $out['luecken'][] = $topf;
        }
    }
    $out['teilausfall'] = $fehl;
    $out['stand_ok'] = $fehl ? ($passt ? fer_stand_ok($alt) : '') : date('c');
    usort($out['ferien'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
    usort($out['feiertage'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
    if (!fer_json_schreiben($f, $out)) {
        fer_abruf_fehlschlag(array('termine.json'), ' - die Termindatei liess sich nicht schreiben');
        return array(($passt && fer_termine_abdeckung($alt)) ? 1 : 0, 'FEHLGESCHLAGEN');
    }
    @unlink(fer_tmpdir() . '/state.json');
    $zeile = count($out['ferien']) . ' Ferienzeitraeume, '
        . count($out['feiertage']) . ' Feiertage (' . $land . ($sub !== '' ? '/' . $sub : '')
        . ($gruppe !== '' ? ', Schulart ' . $gruppe : '') . ', bis ' . $out['bis'] . ')'
        . ($out['sub2'] !== '' ? ' + ' . count($out['ferien2']) . ' Ferienzeitraeume fuer ' . $out['sub2'] : '');
    if ($fehl) {
        fer_abruf_fehlschlag($fehl, ($out['luecken']
            ? ' - ohne gespeicherten Stand: ' . implode(', ', $out['luecken'])
            : ' - der gespeicherte Stand dieser Teile gilt weiter') . '. In der Datei: ' . $zeile);
        return array(fer_termine_abdeckung($out) ? 1 : 0, 'teilweise');
    }
    fer_abruf_gelungen();
    fer_log('Daten abgerufen: ' . $zeile);
    return array(1, 'frisch');
}

/* ---------------- Eigene Termine aus einem Kalender (ICS) ---------------- */

/**
 * Ein Kalender-Abonnement lesen und daraus eigene Termine machen.
 *
 * Warum ueberhaupt: die sechs Zeilen der Oberflaeche muessen von Hand
 * gepflegt werden, im Format 2026-08-03. Wer sich vertippt, verliert die
 * Zeile beim Speichern kommentarlos. Der Urlaub steht ohnehin im
 * Familienkalender - Google, Nextcloud und iCloud geben ihn als ICS heraus.
 *
 * Was diese Funktion BEWUSST nicht kann, und warum es hier steht statt in
 * einer Wunschliste:
 *
 *  - Wiederholungen (RRULE) werden uebersprungen. Ein Urlaub wiederholt sich
 *    nicht, und eine halbe Wiederholungsrechnung waere schlimmer als keine:
 *    sie erzeugte Termine, die es nicht gibt. Seit 1.2.28 (V4 Option E) mit
 *    einer Ausnahme: der Haken ics_jaehrlich UND ein Filterwort uebernehmen
 *    einfache FREQ=YEARLY-Ganztagstermine (fer_ics_jaehrlich()).
 *  - Termine mit Uhrzeit werden uebersprungen, auch VALUE=DATE-TIME. Dieses
 *    Plugin rechnet in ganzen Tagen; ein Zahnarzttermin um 14 Uhr ist kein
 *    Urlaubstag. Genommen werden nur Ganztagstermine (DTSTART;VALUE=DATE oder
 *    DTSTART mit genau 8 Ziffern). Abgesagte Termine (STATUS:CANCELLED)
 *    zaehlen nicht, Erinnerungen (VALARM) ebenso wenig.
 *  - Zeitzonen spielen damit keine Rolle.
 *
 * DTEND ist bei Ganztagsterminen EXKLUSIV - ein eintaegiger Termin am 3.8.
 * hat DTSTART 20260803 und DTEND 20260804. Wer das uebersieht, verlaengert
 * jeden Urlaub um einen Tag; die Haussteuerung faehrt dann einen Tag zu
 * spaet wieder hoch. Deshalb wird hier ein Tag abgezogen.
 *
 * Rueckgabe: Liste im selben Aufbau wie cfg['own'], oder leer.
 */
function fer_ics_lesen($text, $filter = '', $jaehrlich = false, $heute = null, &$zahl = null) {
    /* Gruppe 19 (1.2.28; FE11, E2, E3, Entscheidung 1, Entwurf 3.5/7.3, E-E3/E-E4):
     *  - Bloecke werden nur an einer Zeile "BEGIN:VEVENT" geteilt, Eigenschaften nur am Zeilenanfang gelesen ("(rrule)"
     *    im Beschreibungstext ist keine Wiederholung mehr);
     *  - Erinnerungen (VALARM) tragen eigene SUMMARY/DESCRIPTION und werden vor der Auswertung entfernt;
     *  - abgesagte Termine (STATUS:CANCELLED) zaehlen nicht;
     *  - Ganztag nur mit genau 8 Ziffern: VALUE=DATE-TIME und ...T140000 fallen weg (E2); DTSTART ohne VALUE=DATE mit
     *    genau 8 Ziffern gilt als Ganztag ("lax", E-E4 - 8 Ziffern ohne T sind nur als Datum lesbar);
     *  - DURATION:P<n>D bzw. P<n>W statt DTEND (E3);
     *  - RRULE:FREQ=YEARLY nur mit dem Haken ics_jaehrlich UND einem Filterwort (V4 Option E: Geburtstage und Jahrestage
     *    sind ebenfalls jaehrliche Ganztagstermine - unterscheiden lassen sie sich nur ueber den Titel), sonst wie bisher
     *    uebersprungen. $zahl bekommt array('jaehrlich' => uebernommene, 'jaehrlich_weg' => uebersprungene
     *    Serientermine) fuer den Reiter Test. */
    $aus = array();
    $zahl = array('jaehrlich' => 0, 'jaehrlich_weg' => 0);
    if ($heute === null) { $heute = date('Y-m-d'); }
    // Fortsetzungszeilen aufloesen: ICS bricht lange Zeilen um und ruecket die
    // Fortsetzung mit einem Leerzeichen oder Tabulator ein.
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $text = preg_replace('/\n[ \t]/', '', $text);
    $bloecke = preg_split('/^BEGIN:VEVENT[ \t]*$/m', $text);
    array_shift($bloecke);
    foreach ($bloecke as $b) {
        $e = strpos($b, "\nEND:VEVENT");
        if ($e !== false) { $b = substr($b, 0, $e); }
        $b = "\n" . preg_replace('/^BEGIN:VALARM[ \t]*$.*?^END:VALARM[ \t]*$/ms', '', $b) . "\n";
        if (preg_match('/\nSTATUS(?:;[^:\n]*)?:[ \t]*CANCELLED[ \t]*\n/i', $b)) { continue; }
        if (!preg_match('/\nDTSTART((?:;[^:\n]*)?):(\d{8})([^\n]*)\n/i', $b, $ms)) { continue; }
        if (trim($ms[3]) !== '') { continue; }                       // ...T140000 -> Uhrzeit
        $par = strtoupper($ms[1]);
        $hat_date = (bool) preg_match('/;VALUE=DATE(?:;|$)/', $par);
        if (!$hat_date && strpos($par, ';VALUE=') !== false) { continue; }   // VALUE=DATE-TIME o. a.
        if (!checkdate((int) substr($ms[2], 4, 2), (int) substr($ms[2], 6, 2), (int) substr($ms[2], 0, 4))) { continue; }
        $von = substr($ms[2], 0, 4) . '-' . substr($ms[2], 4, 2) . '-' . substr($ms[2], 6, 2);
        $tage = 1;
        if (preg_match('/\nDTEND((?:;[^:\n]*)?):(\d{8})[ \t]*\n/i', $b, $me)) {
            $roh = substr($me[2], 0, 4) . '-' . substr($me[2], 4, 2) . '-' . substr($me[2], 6, 2);
            // DTEND ist exklusiv - siehe Kommentar oben.
            $tage = max(1, (int) round((strtotime($roh) - strtotime($von)) / 86400));
        } elseif (preg_match('/\nDURATION(?:;[^:\n]*)?:[ \t]*P(\d{1,3})([DW])[ \t]*\n/i', $b, $md)) {
            $tage = max(1, (int) $md[1] * (strtoupper($md[2]) === 'W' ? 7 : 1));
        }
        $tage = min($tage, 366);
        $name = '';
        if (preg_match('/\nSUMMARY[^:\n]*:(.*)/', $b, $mn)) {
            $name = trim(str_replace(array('\\,', '\\;', '\\n', '\\N'), array(',', ';', ' ', ' '), $mn[1]));
        }
        if ($name === '') { $name = 'Kalendertermin'; }
        if ($filter !== '' && stripos($name, $filter) === false) { continue; }
        $starts = array($von);
        if (preg_match('/\nRRULE[;:]([^\n]*)\n/i', $b, $mr)) {
            $starts = ($jaehrlich && $filter !== '') ? fer_ics_jaehrlich($mr[1], $von, $b, $heute) : null;
            if ($starts === null) { $zahl['jaehrlich_weg']++; continue; }
            $zahl['jaehrlich']++;
        }
        foreach ($starts as $s) {
            $aus[] = array('von' => $s, 'bis' => date('Y-m-d', strtotime($s . ' +' . ($tage - 1) . ' day')), 'name' => $name);
        }
    }
    usort($aus, function ($a, $b) { return strcmp($a['von'], $b['von']); });
    return $aus;
}

/**
 * Den Kalender zwischenspeichern.
 *
 * Der Zwischenspeicher liegt unter data/ und NICHT unter /tmp: /tmp ist auf
 * dem LoxBerry eine Ramdisk, und nach jedem Neustart waeren die Termine weg,
 * bis der Kalender wieder erreichbar ist. Faellt der Abruf aus, gilt der
 * letzte erfolgreiche Stand weiter - dieselbe Ueberlegung wie bei den
 * Ferien selbst.
 *
 * C13 (Durchgang 30.09.2026): Bis 1.2.15 fragte fer_ics_holen() den Kalender
 * bei jedem Aufbau der Oberflaeche und bei ?json=1, sobald der Zwischenspeicher
 * aelter als 6 h war - mit 20 s Zeitgrenze und ohne den Fehlversuch zu
 * vermerken. Antwortete die Adresse nicht, wartete JEDER Aufruf erneut
 * (gemessen: Oberflaeche 20-40 s, ?json=1 20 s; Abfahrts-Assistent und
 * AWM-Abfuhr geben nach 4 s auf). Jetzt:
 *  - fer_ics_holen() liest NUR den gespeicherten Stand (Oberflaeche,
 *    Endpunkt, andere Plugins) und fragt nie das Netz;
 *  - fer_ics_abrufen() fragt den Kalender, nur aus dem Cron, mit 5 s
 *    Zeitgrenze; ein Fehlversuch wird gestempelt und fruehestens nach einer
 *    Stunde wiederholt, ein Erfolg nach 6 h aufgefrischt.
 */
define('FER_ICS_TAKT', 6 * 3600);
define('FER_ICS_NEUVERSUCH', 3600);

function fer_ics_datei() { return fer_datadir() . '/kalender.json'; }

/** Kennung der eingestellten Kalenderquelle (Adresse und Filter). */
function fer_ics_quelle($cfg) {
    $url = is_string($cfg['ics_url']) ? trim($cfg['ics_url']) : '';
    /* Gruppe 19 (1.2.28, V4): nur mit ics_jaehrlich = 1 kommt "\nJ" dazu - die Kennung bestehender Anlagen bleibt gleich,
     * und ein Umschalten holt den Kalender beim naechsten Lauf neu. */
    return $url === '' ? '' : md5($url . "\n" . (is_string($cfg['ics_filter']) ? trim($cfg['ics_filter']) : '')
        . (!empty($cfg['ics_jaehrlich']) ? "\nJ" : ''));
}

/** Der gespeicherte Kalenderstand - ohne Netz. $force ist nur noch der
 *  Vollstaendigkeit halber da (Aufrufer aus 1.2.x). */
function fer_ics_holen($force = false) {
    $cfg = fer_config();
    $url = is_string($cfg['ics_url']) ? trim($cfg['ics_url']) : '';
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return array(); }
    $c = fer_ics_lesen_datei();
    if (!isset($c['termine']) || !is_array($c['termine'])) { return array(); }
    if (isset($c['quelle']) && (string) $c['quelle'] !== fer_ics_quelle($cfg)) { return array(); }
    return $c['termine'];
}

function fer_ics_lesen_datei() {
    $f = fer_ics_datei();
    $c = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($c) ? $c : array();
}

/** Den Kalender fragen - nur aus dem Cron. Rueckgabe: 'aus', 'nicht_faellig',
 *  'frisch' oder 'fehler'. */
function fer_ics_abrufen($force = false) {
    $cfg = fer_config();
    $url = is_string($cfg['ics_url']) ? trim($cfg['ics_url']) : '';
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return 'aus'; }
    $f = fer_ics_datei();
    $c = fer_ics_lesen_datei();
    $quelle = fer_ics_quelle($cfg);
    $gleich = isset($c['quelle']) ? ((string) $c['quelle'] === $quelle) : isset($c['termine']);
    $ok_ts = isset($c['ok_ts']) ? (int) $c['ok_ts'] : (($gleich && is_file($f)) ? (int) filemtime($f) : 0);
    $faellig = $force || !$gleich || time() - $ok_ts >= FER_ICS_TAKT;
    if ($faellig && !$force && !empty($c['fehler']) && isset($c['versuch_quelle'], $c['versuch_ts'])
        && (string) $c['versuch_quelle'] === $quelle && time() - (int) $c['versuch_ts'] < FER_ICS_NEUVERSUCH) {
        $faellig = false;
    }
    if (!$faellig) { return 'nicht_faellig'; }
    $roh = fer_http($url, 5);
    if ($roh === false || stripos((string) $roh, 'BEGIN:VCALENDAR') === false) {
        $c['versuch_quelle'] = $quelle;
        $c['versuch_ts'] = time();
        $c['fehler'] = ($roh === false) ? 'nicht erreichbar (5 s)' : 'keine ICS-Datei';
        if (!$gleich) { $c['termine'] = array(); $c['quelle'] = $quelle; $c['ok_ts'] = 0; $c['stand'] = ''; }
        fer_json_schreiben($f, $c);
        fer_log_if_changed('ics', 'Kalender ' . $c['fehler'] . ': ' . fer_ics_url_kurz($url)
            . ' - naechster Versuch fruehestens in 1 h'
            . (!empty($c['termine']) ? ', der gespeicherte Stand gilt weiter' : ''));
        return 'fehler';
    }
    $fer_iz = array();
    $termine = fer_ics_lesen($roh, is_string($cfg['ics_filter']) ? trim($cfg['ics_filter']) : '',
        !empty($cfg['ics_jaehrlich']), date('Y-m-d'), $fer_iz);
    $vorher = isset($c['termine']) ? $c['termine'] : null;
    fer_json_schreiben($f, array('stand' => date('c'), 'ok_ts' => time(), 'quelle' => $quelle,
        'versuch_quelle' => $quelle, 'versuch_ts' => time(), 'fehler' => '', 'termine' => $termine,
        'jaehrlich' => (int) $fer_iz['jaehrlich'], 'jaehrlich_weg' => (int) $fer_iz['jaehrlich_weg']));
    if ($vorher !== $termine) { @unlink(fer_tmpdir() . '/state.json'); }
    fer_log_if_changed('ics', count($termine) . ' Termine aus dem Kalender uebernommen');
    return 'frisch';
}

/** Die Kalenderadresse fuers Protokoll: nur Rechner und Anfang des Pfads -
 *  bei Google und iCloud steht das Geheimnis im Pfad (O8). */
function fer_ics_url_kurz($url) {
    $t = @parse_url((string) $url);
    if (!is_array($t) || !isset($t['host'])) { return '(Adresse)'; }
    return (isset($t['scheme']) ? $t['scheme'] : 'http') . '://' . $t['host'] . '/...';
}

/**
 * Ostersonntag eines Jahres (Gauss/Meeus, ohne PHP-Kalendererweiterung).
 * Basis fuer Fronleichnam (Ostern + 60 Tage) in den Gemeinden, die die
 * OpenHolidays-API nicht abbildet.
 */
function fer_ostern($jahr) {
    $a = $jahr % 19; $b = intdiv($jahr, 100); $c = $jahr % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $monat = intdiv($h + $l - 7 * $m + 114, 31); $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
}

/**
 * Oertliche Sonderfaelle, die die API NICHT abbildet, nachtragen bzw. entfernen.
 * Die API kennt nur Bundesland-Ebene (plus Augsburg als einzigen lokalen Fall):
 *  - Mariae Himmelfahrt gilt in Bayern NUR in ueberwiegend katholischen Gemeinden
 *    (ca. 1.700 von rund 2.050) - in den uebrigen ist es KEIN Feiertag.
 *  - Fronleichnam ist in Sachsen und Thueringen NUR in bestimmten katholischen
 *    Gemeinden Feiertag - die API liefert es dort gar nicht.
 */
function fer_locality_fix($d) {
    $cfg = fer_config();
    $loc = (string) $cfg['locality'];
    if ($loc === 'BY-EV') {
        $raus = array();
        foreach ((array) $d['feiertage'] as $i => $e) {
            if (stripos($e['name'], 'Himmelfahrt') !== false && stripos($e['name'], 'Christi') === false) {
                $raus[] = $i;
            }
        }
        foreach (array_reverse($raus) as $i) { unset($d['feiertage'][$i]); }
        $d['feiertage'] = array_values($d['feiertage']);
    }
    if ($loc === 'SN-KATH' || $loc === 'TH-KATH') {
        $vorhanden = array();
        foreach ((array) $d['feiertage'] as $e) { $vorhanden[$e['von']] = 1; }
        for ($j = (int) date('Y') - 1; $j <= (int) date('Y') + 2; $j++) {
            $fron = date('Y-m-d', strtotime(fer_ostern($j) . ' +60 days'));
            if (!isset($vorhanden[$fron])) {
                $d['feiertage'][] = array('von' => $fron, 'bis' => $fron, 'name' => 'Fronleichnam', 'ortlich' => 1);
            }
        }
        usort($d['feiertage'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
    }
    return $d;
}

function fer_data() {
    $fer_z = fer_zone_an();
    try {
        $fer_d = fer_data_innen();
        /* Ferien-b1/Ferien-1 (Verbesserungsbau 01.10.2026): ein FREMDES
         * Plugin, das die Bibliothek einbindet (AWM-Abfuhr, Abfahrts-
         * Assistent), wird fuer den Reiter Test vermerkt - einmal je Prozess,
         * ohne Ausgabe und ohne Wirkung auf den Rueckgabewert
         * (fer_leser_bibliothek()). */
        fer_leser_bibliothek($fer_d);
        return $fer_d;
    } finally {
        fer_zone_aus($fer_z);
    }
}

function fer_data_innen() {
    $cfg = fer_config();
    $d = fer_termine_roh();
    $region = fer_region($cfg);
    /* Nur Daten der EINGESTELLTEN Region (I2, I5): eine Termindatei einer
     * anderen Region zaehlt wie keine. */
    if (!fer_region_passt($d, $region)) {
        $d = null;
    }
    if (!is_array($d)) {
        $d = array('ferien' => array(), 'feiertage' => array(), 'bis' => '', 'stand' => '');
    }
    foreach (array('ferien', 'feiertage') as $fer_topf) {
        if (!isset($d[$fer_topf]) || !is_array($d[$fer_topf])) { $d[$fer_topf] = array(); }
    }
    if (!isset($d['ferien2']) || !is_array($d['ferien2'])
        || (string) (isset($d['sub2']) ? $d['sub2'] : '') !== $region['sub2']) {
        $d['ferien2'] = array();
    }
    /* Liegen Daten der QUELLE vor? Nur sie tragen OK (C5): ein eigener Termin
     * oder ein Kalendereintrag allein ist keine gueltige Datenlage. Gefragt
     * wird deshalb VOR dem Einmischen der eigenen Termine. */
    $d['quelle_da'] = ($d['ferien'] || $d['feiertage']) ? 1 : 0;
    $d['abdeckung'] = ($d['quelle_da'] && fer_termine_abdeckung($d)) ? 1 : 0;
    $d['stand_ok'] = $d['quelle_da'] ? fer_stand_ok($d) : '';
    $d['alter_tage'] = $d['quelle_da'] ? fer_alter_tage($d) : -1;
    if (!isset($d['teilausfall']) || !is_array($d['teilausfall'])) { $d['teilausfall'] = array(); }
    // Eigene Zeitraeume ergaenzen
    if (!isset($d['urlaub']) || !is_array($d['urlaub'])) { $d['urlaub'] = array(); }

    /* Eintragsarten aussieben - nur wenn ausdruecklich verlangt.
     *
     * Der Vorgabewert ist 0, also KEINE Aussiebung. Das ist Absicht: eine
     * bestehende Anlage soll nach dem Update nicht ploetzlich einen Feiertag
     * weniger kennen. Wer die Aussiebung einschaltet, bekommt in der
     * Oberflaeche vorher gesagt, wie viele Eintraege SEINER Region das
     * betrifft - bei DE/AT sind es null.
     *
     * Ein leeres 'art' bedeutet "kommt nicht aus der Datenquelle" (eigener
     * Termin) oder "aus einer Termindatei vor 1.2.0" und bleibt deshalb immer
     * drin. Sonst haetten alle bestehenden Anlagen nach dem Update, aber vor
     * dem naechsten Abruf, gar keine Feiertage mehr.
     */
    if (!empty($cfg['typ_streng'])) {
        $d['feiertage'] = array_values(array_filter((array) $d['feiertage'], function ($e) {
            $a = isset($e['art']) ? (string) $e['art'] : '';
            return $a === '' || $a === 'Public';
        }));
        $sieb = function ($e) {
            $a = isset($e['art']) ? (string) $e['art'] : '';
            return $a === '' || $a === 'School';
        };
        $d['ferien'] = array_values(array_filter((array) $d['ferien'], $sieb));
        $d['ferien2'] = array_values(array_filter((array) $d['ferien2'], $sieb));
    }

    /* Termine aus dem Kalender-Abonnement - vor den handgepflegten, damit
     * die Oberflaeche sie in derselben Liste zeigt. Sie tragen 'ics', damit
     * man ihnen ansieht, woher sie kommen. */
    $ics_typ = in_array((string) $cfg['ics_typ'], array('ferien', 'feiertag', 'urlaub'), true)
        ? (string) $cfg['ics_typ'] : 'urlaub';
    foreach (fer_ics_holen() as $k) {
        $rec = array('von' => $k['von'], 'bis' => $k['bis'], 'name' => $k['name'],
                     'eigen' => 1, 'ics' => 1, 'art' => '');
        if ($ics_typ === 'feiertag') {
            $d['feiertage'][] = $rec;
        } elseif ($ics_typ === 'urlaub') {
            $rec['urlaub'] = 1;
            $d['urlaub'][] = $rec;
            $d['ferien'][] = $rec;
        } else {
            $d['ferien'][] = $rec;
        }
    }

    foreach ((array) $cfg['own'] as $o) {
        $o = (array) $o;
        $von = isset($o['von']) ? trim((string) $o['von']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) {
            continue;
        }
        $bis = (isset($o['bis']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $o['bis'])) ? $o['bis'] : $von;
        $rec = array('von' => $von, 'bis' => $bis,
                     'name' => trim((string) (isset($o['name']) ? $o['name'] : '')) !== '' ? trim((string) $o['name']) : 'Eigener Termin',
                     'eigen' => 1);
        $typ = isset($o['typ']) ? (string) $o['typ'] : 'ferien';
        if ($typ === 'feiertag') {
            $d['feiertage'][] = $rec;
        } elseif ($typ === 'urlaub') {
            // Urlaub = Abwesenheit. Zaehlt zusaetzlich wie Ferien, damit Wecker,
            // Briefing und Schulfrei-Logik waehrend der Abwesenheit stimmen.
            $rec['urlaub'] = 1;
            $d['urlaub'][] = $rec;
            $d['ferien'][] = $rec;
        } else {
            $d['ferien'][] = $rec;
        }
    }
    /* Nach dem Zusammenfuehren neu sortieren.
     *
     * Bis 1.1.7 wurden eigene Termine hinten angehaengt und die Liste blieb,
     * wie sie war. fer_state() nimmt aber den ERSTEN Eintrag, dessen 'bis'
     * nicht in der Vergangenheit liegt - und das war dann der naechste
     * Eintrag der Datenquelle, nicht der naechste ueberhaupt. Wer im Mai
     * Betriebsferien eintrug, bekam als FERIENIN trotzdem die Zahl bis zu
     * den Sommerferien. Aufgefallen beim Einbauen des Kalender-Abonnements,
     * das dasselbe Problem noch verschaerft haette.
     */
    $nach_datum = function ($x, $y) { return strcmp($x['von'], $y['von']); };
    usort($d['ferien'], $nach_datum);
    usort($d['feiertage'], $nach_datum);
    if ($d['urlaub']) { usort($d['urlaub'], $nach_datum); }
    if ($d['ferien2']) { usort($d['ferien2'], $nach_datum); }
    return fer_locality_fix($d);
}

/* ---------------- Auswertung ---------------- */

/** Trifft ein Zeitraum auf das Datum (Y-m-d) zu? Rueckgabe: der EINTRAG oder null.
 *
 * Seit 1.2.0 braucht der Aufrufer mehr als den Namen: die Art des Eintrags
 * und die Kennzeichnung als halber Tag haengen am Eintrag, nicht am Datum.
 * BERICHTIGT 1.2.16 (C15): hier stand, der Namens-Helfer darunter bleibe fuer
 * "alle bisherigen Aufrufstellen". Gezaehlt hatte er null Aufrufer; alle 12
 * Aufrufstellen dieser Datei nutzen diese Funktion und lesen den Namen
 * selbst. Der tote Helfer ist entfernt. */
function fer_match_e($liste, $tag) {
    foreach ((array) $liste as $e) {
        if ($tag >= $e['von'] && $tag <= $e['bis']) {
            return $e;
        }
    }
    return null;
}

/** Ist der Tag ein Werktag (Mo-Fr)? */
function fer_werktag($tag) {
    $w = (int) date('N', strtotime($tag));
    return $w <= 5;
}

/**
 * Zaehlt ein Eintrag als freier Tag?
 *
 * Ein halber Feiertag ist die einzige Stelle, an der das nicht schon aus dem
 * Vorhandensein folgt. Bis 1.1.7 zaehlte er als ganzer freier Tag, weil das
 * Plugin 'temporalScope' gar nicht kannte - deshalb ist der Vorgabewert von
 * 'halbtag_frei' die 1 und nicht die 0. Wer bis 12 Uhr arbeitet, stellt es
 * um und bekommt an diesem Tag SCHULFREI=0 und HALBTAG=1.
 */
function fer_zaehlt_frei($e, $cfg = null) {
    if ($e === null) { return false; }
    if ($cfg === null) { $cfg = fer_config(); }
    if (!empty($e['halbtag']) && empty($cfg['halbtag_frei'])) { return false; }
    return true;
}

/** Ist der Tag ohne Ruecksicht auf Schulferien frei - Wochenende oder Feiertag?
 *
 * Das ist die Grundlage der Brueckenrechnung. Schulferien gehoeren BEWUSST
 * nicht dazu: waehrend der Sommerferien waere sonst jeder Werktag von
 * "freien" Tagen umgeben, und die Brueckenliste haette 40 Eintraege ohne
 * jeden Wert fuer die Urlaubsplanung. */
function fer_frei_ohne_ferien($d, $tag, $cfg = null) {
    if (!fer_werktag($tag)) { return true; }
    return fer_zaehlt_frei(fer_match_e($d['feiertage'], $tag), $cfg);
}

/**
 * Brueckentag.
 *
 * KLASSISCH (Vorgabe, unveraendert seit 1.0): Werktag, der unmittelbar
 * zwischen einem Feiertag und dem Wochenende liegt - Freitag nach einem
 * Donnerstags-Feiertag, Montag vor einem Dienstags-Feiertag.
 *
 * ERWEITERT (neu in 1.2.0, ab Werk AUS): jeder Werktag, der zu einer Kette
 * von hoechstens 'bridge_luecke' Werktagen gehoert, die auf BEIDEN Seiten
 * von freien Tagen begrenzt ist.
 *
 * Warum es das gibt: gemessen am 18.08.2026 gegen die echten Daten fuer
 * DE-BY findet die klassische Regel in 900 Tagen genau DREI Brueckentage.
 * Sie uebersieht dabei geschlossen den 28.-31.12.2026 und den 27.-30.12.2027
 * - also genau die Tage, fuer die Menschen Urlaub nehmen. Insgesamt lagen
 * 16 Werktage zwischen zwei Feiertagen, ohne gemeldet zu werden.
 *
 * Warum es trotzdem ab Werk aus ist: BRUECKE und MBRUECKE gehen an den
 * Miniserver, wo sie ueblicherweise an einem Schwellwertschalter haengen.
 * Eine Umstellung wuerde auf JEDER bestehenden Anlage die Zahl der Impulse
 * veraendern, ohne dass jemand danach gefragt hat.
 */
function fer_bridge($d, $tag, $cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    if (!fer_werktag($tag)) { return 0; }
    if (fer_zaehlt_frei(fer_match_e($d['feiertage'], $tag), $cfg)) { return 0; }

    $w = (int) date('N', strtotime($tag));
    $vor = date('Y-m-d', strtotime($tag . ' -1 day'));
    $nach = date('Y-m-d', strtotime($tag . ' +1 day'));
    $fvor  = fer_zaehlt_frei(fer_match_e($d['feiertage'], $vor), $cfg);
    $fnach = fer_zaehlt_frei(fer_match_e($d['feiertage'], $nach), $cfg);
    if ($w === 5 && $fvor)  { return 1; } // Fr nach Do-Feiertag
    if ($w === 1 && $fnach) { return 1; } // Mo vor Di-Feiertag

    if ((string) $cfg['bridge_mode'] !== 'erweitert') { return 0; }

    /* Die Kette der Werktage um diesen Tag herum abschreiten - erst
     * rueckwaerts bis zum ersten freien Tag, dann vorwaerts. Die harte
     * Obergrenze von 14 Schritten je Richtung steht da, weil eine Schleife
     * ueber fremde Daten ohne Obergrenze frueher oder spaeter haengt. */
    /* Die Obergrenze 4 ist gemessen: eine gewoehnliche Woche hat fuenf
     * Werktage, mit 5 waere jeder Werktag des Jahres ein Brueckentag
     * (254 statt 32 in 366 Tagen). Siehe fer_config(). */
    $luecke = max(1, min(4, (int) $cfg['bridge_luecke']));
    $kette = 1;
    for ($i = 1; $i <= 14; $i++) {
        $t = date('Y-m-d', strtotime($tag . ' -' . $i . ' day'));
        if (fer_frei_ohne_ferien($d, $t, $cfg)) { break; }
        $kette++;
        if ($kette > $luecke) { return 0; }
    }
    if ($i > 14) { return 0; }   // kein freier Tag gefunden - keine Bruecke
    for ($k = 1; $k <= 14; $k++) {
        $t = date('Y-m-d', strtotime($tag . ' +' . $k . ' day'));
        if (fer_frei_ohne_ferien($d, $t, $cfg)) { break; }
        $kette++;
        if ($kette > $luecke) { return 0; }
    }
    if ($k > 14) { return 0; }
    return 1;
}

/**
 * Wie viele freie Tage am Stueck beginnen an diesem Tag?
 *
 * Der Wert, an dem eine Heizungsabsenkung haengt - und der einzige, der ein
 * Wochenende von den Sommerferien unterscheidet. Gemessen fuer DE-BY ueber
 * 500 Tage: 44 Bloecke mit genau 2 Tagen, aber neun mit 9 Tagen und mehr,
 * der laengste 45. Ein Merker "heute ist frei" kann das nicht ausdruecken.
 *
 * Die Obergrenze von 90 Tagen ist eine harte Schleifengrenze, kein Messwert:
 * sie liegt weit ueber allem, was vorkommt (laengster gemessener Block 45),
 * und verhindert, dass fehlerhafte Fremddaten die Schleife festhalten.
 */
function fer_frei_am_stueck($d, $tag, $cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $n = 0;
    for ($i = 0; $i < 90; $i++) {
        $t = date('Y-m-d', strtotime($tag . ' +' . $i . ' day'));
        $frei = !fer_werktag($t)
             || fer_zaehlt_frei(fer_match_e($d['ferien'], $t), $cfg)
             || fer_zaehlt_frei(fer_match_e($d['feiertage'], $t), $cfg);
        if (!$frei) { break; }
        $n++;
    }
    return $n;
}

/**
 * Alle Kennzahlen eines Tages.
 *
 * C14 (Durchgang 30.09.2026): AWM-Abfuhr ruft fer_day($d, date('Ymd')) auf,
 * gespeichert ist aber JJJJ-MM-TT. Verglichen wird als Zeichenkette, und
 * '20261003' traf keinen Feiertag, dafuer JEDEN Eintrag, der ueber einen
 * Jahreswechsel reicht - gemessen: an jedem Schultag 2026 ferien=1. Jetzt
 * wird JJJJMMTT und JJJJ-MM-TT angenommen und auf JJJJ-MM-TT gebracht; jede
 * andere Form ergibt null und eine Protokollzeile, nie ein falsches Ergebnis.
 * Die Zeitzone des Aufrufers bleibt unberuehrt (C16).
 */
function fer_day($d, $tag, $cfg = null) {
    $norm = fer_tag_norm($tag);
    if ($norm === null) {
        fer_log_if_changed('tagform', 'fer_day(): Datum in unbekannter Form abgewiesen ('
            . (is_scalar($tag) ? substr((string) $tag, 0, 40) : gettype($tag))
            . ') - erwartet JJJJ-MM-TT oder JJJJMMTT.');
        return null;
    }
    $fer_z = fer_zone_an();
    try {
        return fer_day_innen($d, $norm, $cfg);
    } finally {
        fer_zone_aus($fer_z);
    }
}

/** JJJJMMTT oder JJJJ-MM-TT -> JJJJ-MM-TT; alles andere -> null. */
function fer_tag_norm($tag) {
    if (!is_string($tag) && !is_int($tag)) { return null; }
    $t = (string) $tag;
    if (preg_match('/^(\d{4})(\d{2})(\d{2})\z/', $t, $m) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $t, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
    }
    return null;
}

function fer_day_innen($d, $tag, $cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $eF = fer_match_e($d['ferien'], $tag);
    $eH = fer_match_e($d['feiertage'], $tag);
    $eU = fer_match_e(isset($d['urlaub']) ? $d['urlaub'] : array(), $tag);
    $eF2 = fer_match_e(isset($d['ferien2']) ? $d['ferien2'] : array(), $tag);
    $we = !fer_werktag($tag);

    /* Zaehlt der Eintrag als frei? Bei einem halben Feiertag haengt das an
     * der Einstellung - siehe fer_zaehlt_frei(). Vorhanden ist er in beiden
     * Faellen, deshalb sind 'feiertag' und 'schulfrei' zwei verschiedene
     * Fragen und nicht mehr dieselbe. */
    $fFrei  = fer_zaehlt_frei($eF, $cfg);
    $hFrei  = fer_zaehlt_frei($eH, $cfg);
    $f2Frei = fer_zaehlt_frei($eF2, $cfg);
    $frei  = ($fFrei || $hFrei || $we) ? 1 : 0;
    $frei2 = ($f2Frei || $hFrei || $we) ? 1 : 0;
    $halbtag = ((!empty($eH['halbtag'])) || (!empty($eF['halbtag']))) ? 1 : 0;

    return array(
        'datum' => $tag,
        'ferien' => $eF !== null ? 1 : 0,
        'ferien_name' => $eF !== null ? (string) $eF['name'] : '',
        'feiertag' => $eH !== null ? 1 : 0,
        'feiertag_name' => $eH !== null ? (string) $eH['name'] : '',
        'feiertag_art' => ($eH !== null && isset($eH['art'])) ? (string) $eH['art'] : '',
        'hinweis' => ($eH !== null && isset($eH['hinweis'])) ? (string) $eH['hinweis'] : '',
        'wochenende' => $we ? 1 : 0,
        'schulfrei' => $frei,
        'schultag' => $frei ? 0 : 1,
        'bruecke' => fer_bridge($d, $tag, $cfg),
        'urlaub' => $eU !== null ? 1 : 0,
        'urlaub_name' => $eU !== null ? (string) $eU['name'] : '',
        // --- neu in 1.2.0 ---
        'wochentag' => (int) date('N', strtotime($tag)),   // 1 = Montag ... 7 = Sonntag
        'halbtag' => $halbtag,
        'freitage' => fer_frei_am_stueck($d, $tag, $cfg),
        'ferien2' => $eF2 !== null ? 1 : 0,
        'ferien2_name' => $eF2 !== null ? (string) $eF2['name'] : '',
        'schulfrei2' => $frei2,
        'schultag2' => $frei2 ? 0 : 1,
    );
}

/** Kompletter Zustand (Cache bis Tageswechsel). Die Zeitzone des Aufrufers
 *  bleibt unberuehrt (C16). */
function fer_state($force = false) {
    $fer_z = fer_zone_an();
    try {
        $fer_st = fer_state_innen($force);
        /* Ferien-b1/Ferien-1: wie in fer_data() - der Abfahrts-Assistent ruft
         * nur fer_state(), und das liest meist state.json, ohne fer_data()
         * zu fragen. Einmal je Prozess (fer_leser_bibliothek()). */
        fer_leser_bibliothek($fer_st);
        return $fer_st;
    } finally {
        fer_zone_aus($fer_z);
    }
}

function fer_state_innen($force = false) {
    $cfg = fer_config();
    $cache = fer_tmpdir() . '/state.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 3600) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['heute']['datum']) && $c['heute']['datum'] === date('Y-m-d')) {
            return $c;
        }
    }
    $d = fer_data();
    $heute = date('Y-m-d');
    $st = array(
        /* OK (Entscheidung 4, angepasst an Kalenderdaten; C5): 1 nur, wenn
         * Daten der QUELLE fuer die eingestellte Region heute und morgen
         * abdecken. Bis 1.2.15 hing OK an "Liste nicht leer" - ein eigener
         * Termin allein ergab OK=1, und Daten, die gestern endeten, auch. */
        'ok' => !empty($d['abdeckung']) ? 1 : 0,
        'heute' => fer_day($d, $heute),
        'morgen' => fer_day($d, date('Y-m-d', strtotime('+1 day'))),
        'stand' => isset($d['stand']) ? $d['stand'] : '',
        'reicht_bis' => isset($d['bis']) ? $d['bis'] : '',
        'warnung' => 0,
        'ts' => time(),
        'quelle_da' => !empty($d['quelle_da']) ? 1 : 0,
        'stand_ok' => isset($d['stand_ok']) ? (string) $d['stand_ok'] : '',
        'alter_tage' => isset($d['alter_tage']) ? (int) $d['alter_tage'] : -1,
        'teilausfall' => isset($d['teilausfall']) ? (array) $d['teilausfall'] : array(),
    );
    // Naechste Ferien / laufende Ferien
    $st['naechste'] = array('name' => '', 'von' => '', 'bis' => '', 'in' => -1, 'dauer' => 0, 'rest' => 0);
    foreach ((array) $d['ferien'] as $e) {
        if ($e['bis'] < $heute) {
            continue;
        }
        $tage = (int) round((strtotime($e['von']) - strtotime($heute)) / 86400);
        $dauer = (int) round((strtotime($e['bis']) - strtotime($e['von'])) / 86400) + 1;
        $rest = $e['von'] <= $heute ? (int) round((strtotime($e['bis']) - strtotime($heute)) / 86400) + 1 : 0;
        $st['naechste'] = array('name' => $e['name'], 'von' => $e['von'], 'bis' => $e['bis'],
                                'in' => max(0, $tage), 'dauer' => $dauer, 'rest' => $rest);
        break;
    }
    /* Die naechsten Ferien NACH den laufenden.
     *
     * 'naechste' oben bricht beim ersten Eintrag ab, dessen 'bis' nicht
     * vergangen ist - waehrend der Ferien ist das der laufende Eintrag, und
     * FERIENIN steht dann auf 0. Fuer den Wecker ist das richtig, fuer die
     * Heizungsplanung nicht: die will wissen, wann die naechste lange Pause
     * beginnt, und zwar gerade dann, wenn eine laeuft. Deshalb ein eigener
     * Wert statt einer Aenderung an FERIENIN - das haenge in Loxone an
     * bestehenden Bausteinen. */
    $st['naechste_nach'] = array('name' => '', 'von' => '', 'bis' => '', 'in' => -1, 'dauer' => 0);
    foreach ((array) $d['ferien'] as $e) {
        if ($e['von'] <= $heute) {
            continue;                       // laufend oder vergangen
        }
        $st['naechste_nach'] = array('name' => $e['name'], 'von' => $e['von'], 'bis' => $e['bis'],
            'in' => max(0, (int) round((strtotime($e['von']) - strtotime($heute)) / 86400)),
            'dauer' => (int) round((strtotime($e['bis']) - strtotime($e['von'])) / 86400) + 1);
        break;
    }

    // Ferien der zweiten Region (zweites Kind, anderes Bundesland)
    $st['naechste2'] = array('name' => '', 'von' => '', 'bis' => '', 'in' => -1, 'rest' => 0);
    foreach ((array) (isset($d['ferien2']) ? $d['ferien2'] : array()) as $e) {
        if ($e['bis'] < $heute) {
            continue;
        }
        $st['naechste2'] = array('name' => $e['name'], 'von' => $e['von'], 'bis' => $e['bis'],
            'in' => max(0, (int) round((strtotime($e['von']) - strtotime($heute)) / 86400)),
            'rest' => $e['von'] <= $heute ? (int) round((strtotime($e['bis']) - strtotime($heute)) / 86400) + 1 : 0);
        break;
    }

    // Naechster Feiertag - und der uebernaechste
    $st['feiertag_naechster'] = array('name' => '', 'datum' => '', 'in' => -1);
    $st['feiertag_zweiter'] = array('name' => '', 'datum' => '', 'in' => -1);
    $gefunden = 0;
    foreach ((array) $d['feiertage'] as $e) {
        if ($e['bis'] < $heute) {
            continue;
        }
        $satz = array('name' => $e['name'], 'datum' => $e['von'],
            'in' => max(0, (int) round((strtotime($e['von']) - strtotime($heute)) / 86400)));
        if ($gefunden === 0) {
            $st['feiertag_naechster'] = $satz;
            $gefunden = 1;
        } else {
            /* Der uebernaechste ist erst dann einer, wenn er auf einen
             * ANDEREN Tag faellt. An Weihnachten stehen zwei Feiertage
             * nebeneinander, und wer die Muellabfuhr danach ausrichtet,
             * braucht die zweite Verschiebung, nicht denselben Tag zweimal. */
            if ($satz['datum'] === $st['feiertag_naechster']['datum']) { continue; }
            $st['feiertag_zweiter'] = $satz;
            break;
        }
    }
    // Urlaub / Abwesenheit
    $st['urlaub'] = array('name' => '', 'von' => '', 'bis' => '', 'in' => -1, 'dauer' => 0,
                          'rest' => 0, 'aktiv' => 0, 'letzter_tag' => 0, 'morgen' => 0);
    foreach ((array) (isset($d['urlaub']) ? $d['urlaub'] : array()) as $e) {
        if ($e['bis'] < $heute) {
            continue;
        }
        $tage = (int) round((strtotime($e['von']) - strtotime($heute)) / 86400);
        $dauer = (int) round((strtotime($e['bis']) - strtotime($e['von'])) / 86400) + 1;
        $aktiv = ($e['von'] <= $heute && $e['bis'] >= $heute) ? 1 : 0;
        $rest = $aktiv ? (int) round((strtotime($e['bis']) - strtotime($heute)) / 86400) + 1 : 0;
        $st['urlaub'] = array(
            'name' => $e['name'], 'von' => $e['von'], 'bis' => $e['bis'],
            'in' => max(0, $tage), 'dauer' => $dauer, 'rest' => $rest, 'aktiv' => $aktiv,
            'letzter_tag' => ($aktiv && $e['bis'] === $heute) ? 1 : 0,
            'morgen' => 0,
        );
        break;
    }
    $st['urlaub']['morgen'] = (int) $st['morgen']['urlaub'];
    // Brueckentage der naechsten 12 Monate
    $st['brueckentage'] = array();
    if (!empty($cfg['bridge'])) {
        for ($i = 0; $i < 366; $i++) {
            $t = date('Y-m-d', strtotime("+$i day"));
            if (fer_bridge($d, $t, $cfg)) {
                $st['brueckentage'][] = $t;
            }
        }
    }
    /* Tage bis zum naechsten Brueckentag. Bis 1.1.7 ging von der ganzen
     * Brueckenliste NICHTS an Loxone - sie stand nur in der Oberflaeche.
     * -1 heisst "keiner bekannt", nicht "heute". */
    $st['bruecke_in'] = -1;
    foreach ($st['brueckentage'] as $t) {
        if ($t >= $heute) {
            $st['bruecke_in'] = (int) round((strtotime($t) - strtotime($heute)) / 86400);
            break;
        }
    }

    /* Die beiden Uebergaenge.
     *
     * FERIENENDE ist das Gegenstueck zu URLAUBENDE, das es seit 1.1.0 gibt:
     * heute ist der letzte Ferientag. MERSTERSCHULTAG ist der Abend davor
     * aus Sicht des Weckers - morgen geht die Schule wieder los.
     *
     * Die Bedingung fragt ausdruecklich nach 'ferien' und nicht nach
     * 'schulfrei': sonst waere jeder Sonntagabend ein "erster Schultag" und
     * der Merker damit wertlos. */
    $st['ferienende'] = ($st['heute']['ferien'] && !$st['morgen']['ferien']) ? 1 : 0;
    /* C11 (Durchgang 30.09.2026): bis 1.2.15 hiess die Bedingung "heute
     * Ferien UND morgen Schultag". Enden die Ferien an einem Freitag, liegt
     * das Wochenende dazwischen, und der Merker kam nie (gemessen: Freitag,
     * Samstag und Sonntagabend jeweils 0). Jetzt: morgen Schultag, und seit
     * dem letzten Ferientag lagen nur freie Tage. */
    $st['merster_schultag'] = fer_erster_schultag_morgen($d, $heute, $st['morgen'], $cfg);

    /* Vorwaermen zur Rueckkehr.
     *
     * URLAUBENDE springt erst am letzten Urlaubstag auf 1. Ein Haus, das
     * erst dann anfaengt zu heizen, ist bei der Ankunft kalt - die README
     * verspricht seit 1.1.0 das Gegenteil. URLAUBHEIM geht 'urlaub_vorlauf'
     * Tage frueher an; bei der Vorgabe 1 ist das der vorletzte Urlaubstag,
     * und URLAUBENDE bleibt unveraendert. */
    $vorlauf = max(0, min(14, (int) $cfg['urlaub_vorlauf']));
    $st['urlaub']['heim'] = ($st['urlaub']['aktiv'] && $st['urlaub']['rest'] > 0
                             && $st['urlaub']['rest'] <= $vorlauf + 1) ? 1 : 0;
    /* WARN (Entscheidung 4, angepasst an Kalenderdaten): die Daten reichen
     * weniger als 60 Tage voraus, ODER der letzte GELUNGENE Abruf ist aelter
     * als das Dreifache des Abrufabstands (3 x 7 Tage). Gemessen wird an
     * 'stand_ok' in der Datei, nicht an ihrer Aenderungszeit (C4). */
    if ($st['ok'] && $st['reicht_bis'] !== '' && $st['reicht_bis'] < date('Y-m-d', strtotime('+60 days'))) {
        $st['warnung'] = 1;
    }
    if ($st['quelle_da'] && ($st['alter_tage'] < 0 || $st['alter_tage'] * 86400 > 3 * FER_ABRUF_TAKT)) {
        $st['warnung'] = 1;
    }
    fer_json_schreiben($cache, $st);
    fer_log_if_changed('zustand', 'heute frei=' . $st['heute']['schulfrei'] . ' (' . $st['heute']['ferien_name']
        . $st['heute']['feiertag_name'] . ') | morgen frei=' . $st['morgen']['schulfrei']
        . ' | Urlaub=' . $st['urlaub']['aktiv'] . ($st['urlaub']['aktiv'] ? ' (' . $st['urlaub']['name'] . ', noch ' . $st['urlaub']['rest'] . ' Tage)' : ''));
    return $st;
}

/**
 * Ist morgen der erste Schultag nach Ferien? (C11)
 *
 * Morgen ist Schultag, und rueckwaerts ab heute liegen bis zum letzten
 * Ferientag nur freie Tage (Wochenende, Feiertag). Ein gewoehnlicher
 * Schultag dazwischen beendet die Suche mit 0. Hoechstens 30 Tage zurueck -
 * eine Schleife ueber fremde Daten braucht eine harte Grenze.
 */
function fer_erster_schultag_morgen($d, $heute, $morgen, $cfg) {
    if (empty($morgen['schultag'])) { return 0; }
    for ($i = 0; $i < 30; $i++) {
        $t = date('Y-m-d', strtotime($heute . ' -' . $i . ' day'));
        if (fer_zaehlt_frei(fer_match_e($d['ferien'], $t), $cfg)) { return 1; }
        if (!fer_frei_ohne_ferien($d, $t, $cfg)) { return 0; }
    }
    return 0;
}

/* ---------------- MQTT ---------------- */

function fer_mqtt_publish($st = null, $nur_lebenszeichen = false) {
    /* Rueckgabe seit 1.2.16 (M1): die Zahl der gesendeten Nachrichten. Der
     * Cron schreibt Signatur und Merker nur fort, wenn wirklich gesendet
     * wurde - bis 1.2.15 auch bei ausgeschaltetem MQTT, und nach dem
     * Einschalten blieb es dann bis zu 30 min still. */
    $cfg = fer_config();
    if (empty($cfg['mqtt_enabled'])) {
        return 0;
    }
    $p = fer_paths();
    if ($p['lbhome'] === '') {
        return 0;
    }
    if ($st === null) { $st = fer_state(); }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    // is_array() vor dem verschachtelten Zugriff.
    //
    // Zur Einordnung: ein toedlicher Fehler drohte hier NICHT. Waere
    // $gen['Mqtt'] eine Zeichenkette, gaebe isset($gen['Mqtt']['Udpinport'])
    // seit PHP 7.1 schlicht false zurueck - isset() loest bei unzulaessigen
    // Zeichenketten-Positionen keinen Fehler aus, das ist ausdruecklich so
    // dokumentiert. Der Zugriff dahinter wird dann gar nicht erst erreicht.
    //
    // Ein anderer Fall ist aber real: bei einer Zeichenkette mit Inhalt
    // wuerde 'Udpinport' zu Position 0 verrechnet, isset waere WAHR, und der
    // Port ergaebe sich aus dem ersten Buchstaben. Das Plugin schickte seine
    // Meldungen dann an einen ausgewuerfelten Port. Dagegen hilft is_array,
    // und deshalb steht es jetzt da.
    $udpport = 0;
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt']) && isset($gen['Mqtt']['Udpinport'])) {
        $udpport = (int) $gen['Mqtt']['Udpinport'];
    }
    if (!$udpport && isset($gen['mqtt']) && is_array($gen['mqtt']) && isset($gen['mqtt']['udpinport'])) {
        $udpport = (int) $gen['mqtt']['udpinport'];
    }
    if ($udpport < 1 || $udpport > 65535) {
        fer_log_if_changed('mqtt', 'kein brauchbarer UDP-Eingangsport in der general.json'
            . ' - ist das MQTT-Gateway eingerichtet?');
        return 0;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'ferien';

    /* Die Nachrichten entstehen seit 1.2.27 in fer_mqtt_nachrichten() (Gruppe 1: dieselbe Menge prueft die
     * Selbstpruefung gegen die MQTT-Vorlage). Die Themenliste kommt dort aus fer_felder() - siehe den langen
     * Kommentar dort. Bis 1.1.7 stand hier eine zweite, von Hand gepflegte Liste; sie war um vier Werte kuerzer
     * als die des HTTP-Weges, und weil beide auf 27 Eintraege kamen, ist es niemandem aufgefallen. */
    $m = fer_mqtt_nachrichten($st, $nur_lebenszeichen);

    /* Gruppe 19 (1.2.28; E4/E-E9, Regeln/03): stream_socket_client('udp://...') statt socket_create() - die
     * PHP-Erweiterung sockets wird nicht mehr gebraucht (bis 1.2.27 durch function_exists() abgesichert). */
    $s = @stream_socket_client('udp://127.0.0.1:' . $udpport, $fer_en, $fer_es, 1);
    if ($s === false) {
        fer_log_if_changed('mqtt', 'UDP-Ausgang zum MQTT-Gateway liess sich nicht oeffnen (Port ' . $udpport . ').');
        return 0;
    }
    $gesendet = 0;
    $fer_n = count($m);
    $fer_i = 0;
    /* 5 ms Pause (FE9, Entscheidung 1): der UDP-Eingang des Gateways V1 verwarf bei vollen Stoessen Datagramme
     * (Regeln/07, Kandidatenliste); unter V2 ohne Belang, schadet nicht. Nur beim Vollsatz, nicht nach dem letzten
     * Datagramm - das Lebenszeichen (vier Datagramme) geht ohne Pause. Bauart BatterieBMS 0.9.21 / Marstek 1.1.17. */
    foreach ($m as $k => $v) {
        $fer_i++;
        /* M3: erst saeubern, dann auf leer pruefen - ein Name, der nur aus
         * einem Zeilenumbruch bestand, ging bis 1.2.15 als LEERE Nutzlast
         * hinaus. Nie eine leere Nutzlast; ohne Aussage steht '-'. */
        $nl = fer_mqtt_nutzlast($v);
        if ($nl === '') { $nl = '-'; }
        $msg = 'publish ' . fer_mqtt_thema($prefix . '/' . $k) . ' ' . $nl;
        if (@fwrite($s, $msg) === strlen($msg)) {
            $gesendet++;
        }
        if (!$nur_lebenszeichen && $fer_i < $fer_n) { usleep(5000); }
    }
    fclose($s);
    if (!$nur_lebenszeichen) {
        fer_log_if_changed('mqtt', $gesendet . ' von ' . count($m) . ' Werten gesendet (Port ' . $udpport . ')'
            . (empty($st['quelle_da']) ? ' - ohne Quelldaten nur ok = 0 und das Lebenszeichen' : ''));
    }
    return $gesendet;
}

/**
 * Die MQTT-Nachrichten eines Laufs, Thema (ohne Praefix) => Wert. EINE Stelle fuer den Sender (fer_mqtt_publish())
 * und die Selbstpruefung "Passt die MQTT-Vorlage zu den gesendeten Themen?" (Gruppe 1, 1.2.27).
 *
 * Gruppe 1 / MQTT (Entwurf ff 2.3 und E3, VERHALTEN nur auf dem MQTT-Weg): OHNE Daten der Quelle (quelle_da = 0) gehen
 * nur ok = 0 und das Lebenszeichen hinaus, keine geratenen Werte. Bis 1.2.26 schickte MQTT in diesem Fall ALLE Werte -
 * feiertag = 0, ferien = 0 und schultag aus dem Wochentag geraten -, waehrend der HTTP-Weg seit 1.2.16 nur
 * "FERIEN;OK=0;GRUND=KEINE_DATEN" mit 503 antwortet (ferien.php). Folge war: "Wecker an Schultagen" weckte bei
 * fehlenden Daten auch an einem Feiertag. Regeln/07: "Ueber MQTT geht bei einer Stoerung nur das Signal hinaus, nicht
 * die Werte." Die uebrigen Eingaenge in Loxone behalten ihren Wert; die Sperre "Stoerung bestaetigt" greift nach 600 s.
 */
function fer_mqtt_nachrichten($st, $nur_lebenszeichen = false) {
    $m = array();
    if (!$nur_lebenszeichen) {
        $felder = fer_felder();
        if (empty($st['quelle_da'])) {
            $m[$felder['OK'][5]] = 0;
        } else {
            foreach (fer_werte($st) as $name => $wert) {
                if (isset($felder[$name][5]) && $felder[$name][5] !== '') {
                    $m[$felder[$name][5]] = $wert;
                }
            }
            // Dazu die Textwerte, die ein virtueller HTTP-Eingang nicht lesen kann.
            $m = array_merge($m, fer_mqtt_texte($st));
        }
    }
    // M2: das Lebenszeichen - bei JEDEM Lauf, fluechtig.
    return array_merge($m, fer_mqtt_lebenszeichen($st));
}

/**
 * Das Lebenszeichen (M2, Durchgang 30.09.2026), fluechtig und bei jedem Lauf.
 *
 * Alle Werte dieser Linie sind Tageswerte. Blieb bis 1.2.15 der Cron stehen
 * oder ging der Stoss um Mitternacht im UDP-Eingang verloren, zeigte Loxone
 * den Stand von gestern und konnte es nicht erkennen. Jetzt:
 *   status/ts  Zeitpunkt dieses Laufs (Unix-Sekunden)
 *   datum      der Tag, zu dem die Heute-Werte gehoeren (JJJJMMTT)
 * Beide gehen mit 'publish' hinaus, nie zurueckbehalten (Entscheidung 3/8).
 */
function fer_mqtt_lebenszeichen($st) {
    /* Gruppe 1 / MQTT (1.2.27; Entwurf ff 2.2 und E1): dazu status/zaehler - derselbe Laufzaehler wie ZAEHLER der
     * Loxone-Zeile (fer_zaehler(false), 0..999, -1 = noch kein Lauf; hochgezaehlt einmal je Lauf in bin/cron.php). Er
     * geht bei jedem Lauf hinaus, auch bei unveraenderter Signatur, nie zurueckbehalten, und steht NICHT in der
     * Signatur (Regeln/07: ein jede Minute wechselnder Wert gehoert nicht hinein). ZAEHLER behaelt in fer_felder() sein
     * leeres Thema - sonst ginge er zweimal hinaus. In Loxone haengt die Analogwertvalidierung der Musterseite daran
     * (ferien_status_zaehler); status/ts allein haette Loxone in Ortszeit verrechnen muessen. */
    /* Gruppe 19 (1.2.28, E6, Hausform G15-G18): ok laeuft mit dem Lebenszeichen, damit "Noncached" im Gateway V2 greift
     * und ein verlorenes ok heilt. Ohne Quelldaten bleibt ok = 0 (fer_state()). fer_mqtt_nachrichten() haengt das
     * Lebenszeichen per array_merge an - ok steht danach einmal in der Liste. Die Signatur (bin/cron.php) nimmt nur die
     * drei fluechtigen Themen datum, status/ts und status/zaehler heraus; ok bleibt darin. */
    return array(
        'ok' => (int) (isset($st['ok']) ? $st['ok'] : 0),
        'datum' => str_replace('-', '', (string) (isset($st['heute']['datum']) ? $st['heute']['datum'] : date('Y-m-d'))),
        'status/ts' => time(),
        'status/zaehler' => fer_zaehler(false),
    );
}

/**
 * Ein Thema fuer das LoxBerry-MQTT-Gateway.
 *
 * Das Gateway liest die UDP-Zeile als drei Teile: Verb, Thema, Rest. Getrennt
 * wird an Leerzeichen - ein Leerzeichen IM Thema verschiebt alles dahinter.
 * mqtt_topic ist zwar in der Oberflaeche schon gefiltert, aber die Schluessel
 * kommen aus dem Feld-Bauplan und koennten sich spaeter aendern. Gefiltert
 * wird deshalb dort, wo es zaehlt: unmittelbar vor dem Senden.
 */
function fer_mqtt_thema($thema) {
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $thema);
    return trim(preg_replace('#/+#', '/', $t), '/');
}

/**
 * Eine Nutzlast fuer das MQTT-Gateway.
 *
 * Zeilenumbrueche muessen weg: das Gateway liest zeilenweise. Ein Umbruch
 * mitten in der Nutzlast macht aus einer Nachricht zwei - die zweite beginnt
 * nicht mit 'publish' und wird verworfen, der Rest des Wertes ist verloren.
 *
 * Das ist hier keine Theorie: 'name', 'ferien_name', 'urlaub_name' und
 * 'feiertag_name' stammen aus der OpenHolidays-Antwort bzw. aus einem selbst
 * eingetragenen Termin. Leerzeichen darin sind voellig in Ordnung (das
 * Gateway nimmt den ganzen Rest der Zeile als Nutzlast) - Umbrueche nicht.
 */
function fer_mqtt_nutzlast($wert) {
    $w = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $wert);
    return trim(preg_replace('/ {2,}/', ' ', $w));
}

/* ---------------- Ansage (TTS) - gemeinsame Sprachausgabe, Stufe 2 ----------------
 *
 * Bis 1.2.22 hatte jede Ausgabeart ihren eigenen Zweig (Music Server, Alexa-NG,
 * Google-Lautsprecher) mit eigenem Protokoll, eigener Merkdatei und eigener Zeile im
 * Reiter Test. Seit 1.2.23 spricht ansage_sprechen(): eine Bewertung fuer alle Arten
 * (Alexa-NG/Chromecast nur HTTP 200 und SPRECHEN;OK=1, Music Server und Vorlagen
 * HTTP 2xx), Adresse und Vorlage vor jedem Senden erneut auf das Heimnetz geprueft,
 * keine Weiterleitung, kein Proxy, 10 s. Faellt ein anderes Plugin aus, entfaellt die
 * Ansage - kein stiller Wechsel auf einen anderen Lautsprecher, keine Wiederholung.
 * Ins Protokoll kommt nur ansage_kurz(): Art, Stand, Zeichenzahl, HTTP-Code,
 * Antwortzeile bzw. Kennung - nie Text, nie Token, nie die Adresse des Music Servers.
 */

/** Der Webport des LoxBerry (general.json, Webserver.Port oder WEBSERVER.Port); ohne Angabe 80. */
function fer_webport() {
    $p = fer_paths();
    return ansage_webport($p['lbhome'] !== '' ? $p['lbhome'] . '/config/system/general.json' : '');
}

/**
 * Eine Ansage ueber die eingestellte Ausgabeart. Rueckgabe: das Ergebnis des Moduls
 * (stand 1 = gesendet, 0 = gescheitert, -1 = nichts gesendet ohne Fehler: aus,
 * Original-Audioserver).
 */
function fer_say($text) {
    $cfg = fer_config();
    $r = ansage_sprechen($text, $cfg['tts'], fer_ansage_k());
    fer_log('Ansage: ' . ansage_kurz($r));
    return $r;
}

/**
 * CODE und GRUND fuer die Antwort von ?say=1 - in derselben Form wie bis 1.2.22:
 * bei Alexa-NG nur im Fehlerfall, bei Google-Lautsprechern immer, sonst nicht.
 * GRUND ist der GRUND der Gegenseite oder die bisherige Kurzform (ZEIT,
 * KEINE_VERBINDUNG, KEIN_TOKEN, CHROMECAST_FEHLT), sonst "-". Nie Text oder Token.
 */
function fer_say_zusatz(array $r) {
    $m = $r['art'];
    if (!($m === 'cc4lox' || ($m === 'alexang' && $r['stand'] !== 1))) { return ''; }
    $grund = '-';
    $id = explode('|', (string) $r['kennung']);
    if (preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})/', (string) $r['zeile'], $g)) {
        $grund = $g[1];
    } elseif (preg_match('/_KEINE_ANTWORT\z/', $id[0])) {
        $grund = in_array('HTTP_ZEIT', $id, true) ? 'ZEIT' : 'KEINE_VERBINDUNG';
    } elseif (preg_match('/_KEIN_TOKEN\z/', $id[0])) {
        $grund = 'KEIN_TOKEN';
    } elseif ($id[0] === 'GOOGLE_FEHLT') {
        $grund = 'CHROMECAST_FEHLT';
    }
    return ';CODE=' . (int) $r['http'] . ';GRUND=' . $grund;
}

/**
 * Die Zeile "Ist die Sprachausgabe eingerichtet?" im Reiter Test (ansage_pruefzeile()).
 * Alexa-NG und Chromecast werden mit selftest=1 gefragt (prueft nur das Token, spricht
 * nicht, hoechstens 5 s; fer_selbsttest() laeuft nur bei offenem Reiter Test), der Music
 * Server nie - eine Probe dort spraeche. Dazu die letzte Ansage. Rueckgabe:
 * array(Frage, ok, Hinweis) mit ok true/false/null wie die uebrigen Zeilen, oder null:
 * wie bis 1.2.22 entfaellt die Zeile, wenn die Vorabend-Ansage aus ist und die Ausgabe
 * ueber eine Adresse ginge.
 */
function fer_pruefe_ansage($cfg) {
    $tts = (isset($cfg['tts']) && is_array($cfg['tts'])) ? $cfg['tts'] : array();
    $mode = (isset($tts['mode']) && is_string($tts['mode'])) ? $tts['mode'] : '';
    $an = !empty($cfg['notify']['audio']);
    if (!$an && !ansage_ist_ng($mode)) { return null; }
    $k = fer_ansage_k();
    $k['e'] = function ($s) { return (string) $s; };     // die Tabelle maskiert selbst (fe_e)
    list($stand, $txt) = ansage_pruefzeile($tts, true, $k);
    /* 1 Haken, 0 Kreuz; -1 (Hinweis: dort ausgeschaltet, letzte Ansage gescheitert)
     * bleibt ein Kreuz wie bis 1.2.22, ausser beim Original-Audioserver (Strich);
     * -2 (aus) ist ein Strich. */
    $ok = $stand === 1 ? true : ($stand === 0 ? false : (($stand === -1 && $mode !== 'audioserver') ? false : null));
    if (!$an) { $txt .= ' ' . fer_t('TTS.T_VORABEND_AUS'); }
    return array(fer_t('TTS.F_ANSAGE'), $ok, $txt);
}

/** Ansagetext fuer den Vorabend. */
function fer_announce_text($st = null) {
    if ($st === null) { $st = fer_state(); }
    $m = $st['morgen'];
    if ($m['feiertag']) {
        return 'Hallo! Morgen ist ' . $m['feiertag_name'] . ' - ein Feiertag. Es ist schulfrei.';
    }
    if ($m['ferien'] && !$st['heute']['ferien']) {
        return 'Hallo! Morgen beginnen die ' . $m['ferien_name'] . '. Es ist schulfrei.';
    }
    if ($m['ferien']) {
        return 'Hallo! Morgen ist schulfrei - ' . $m['ferien_name'] . '.';
    }
    if ($m['bruecke']) {
        return 'Hallo! Morgen ist ein Brueckentag - ein guter Tag zum Freinehmen.';
    }
    return '';
}

/**
 * Der heutige Zeitpunkt der Meldezeit als Zeitstempel.
 *
 * Eine Stelle fuer beide Verwendungen - das Meldefenster fuer Loxone und die
 * eigene Ansage. Bis 1.0.1 rechneten beide getrennt, und sie rechneten
 * unterschiedlich.
 */
function fer_ann_start() {
    $cfg = fer_config();
    $when = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time']) ? $cfg['notify']['time'] : '19:00';
    list($hh, $mm) = explode(':', $when);
    $hh = max(0, min(23, (int) $hh));
    $mm = max(0, min(59, (int) $mm));
    return mktime($hh, $mm, 0);
}

/**
 * Wie lange nach der Meldezeit darf noch angesagt werden?
 *
 * Der Minutencron ist nicht puenktlich. Ist der LoxBerry beschaeftigt oder
 * haengt ein anderes Plugin in der Warteschlange, kommt der Lauf statt um
 * 19:00:05 erst um 19:01:02 - und eine Pruefung auf die Minute genau laesst
 * die Ansage des Tages ersatzlos ausfallen.
 *
 * Eine Stunde Nachlauf faengt das ab, auch einen kurzen Stromausfall am
 * Abend. Sie ist zugleich die Obergrenze: ein LoxBerry, der erst um drei Uhr
 * nachts hochfaehrt, soll NICHT noch verkuenden, dass morgen schulfrei ist.
 * Dass die Ansage dann ausfaellt, ist die richtige Antwort.
 */
define('FER_ANN_NACHLAUF', 3600);

/**
 * Meldefenster fuer Loxone: 1 in den ersten 10 Minuten nach der Meldezeit.
 *
 * Die zehn Minuten bleiben BEWUSST stehen, obwohl die eigene Ansage eine
 * Stunde Nachlauf hat. Dieser Wert geht als ANN= an den Miniserver, und dort
 * haengt er ueblicherweise an einem Schwellwertschalter, der auf die Flanke
 * reagiert. Ein Fenster, das eine Stunde offen steht, waere kein Impuls mehr
 * und wuerde bestehende Loxone-Programme veraendern. Kommt der Cron sehr
 * spaet, sagt das Plugin also selbst noch an, waehrend ANN= schon wieder 0
 * ist - im Reiter Einbindung in Loxone steht das auch so.
 */
function fer_ann_active($st = null) {
    if ($st === null) { $st = fer_state(); }
    if (fer_announce_text($st) === '') {
        return 0;
    }
    $start = fer_ann_start();
    return (time() >= $start && time() < $start + 600) ? 1 : 0;
}

/** Ein Aktionstoken erzeugen.
 *
 * Der Zeichenvorrat laesst i, l, o und 0/1 weg: das Token steht in der
 * Oberflaeche zum Abschreiben, und diese Zeichen verwechselt man dabei.
 * Uebernommen aus dem Saugroboter-Plugin, damit alle Linien dasselbe tun.
 */
function fer_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

function fer_ptest_active() {
    $f = fer_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/**
 * Die vier Meldeflags an EINER Stelle: ann, audio, push, ptest.
 *
 * Sie standen bisher nur in der HTTP-Antwort. Wer auf MQTT umstellte,
 * verlor sie ersatzlos: kein Meldefenster, keine Freigaben und vor allem
 * kein PTEST, also keine Moeglichkeit mehr, den Push-Weg zu pruefen, ohne
 * auf den naechsten Ferienbeginn zu warten.
 *
 * Seit 1.1.7 liefert diese Funktion die Werte fuer beide Wege. Sie koennen
 * damit nicht mehr auseinanderlaufen - genau das war der Grund, sie
 * herauszuziehen statt die Rechnung ein zweites Mal hinzuschreiben.
 */
function fer_meldeflags($st = null)
{
    /* Welle Bild 18 (1.2.26; Entwurf Welle 18 ff, Entscheidung 15, vom Hausherrn am 17.10.2026 bestaetigt): ann
     * fragt jetzt auch fer_ann_erlaubt(). Bis 1.2.25 ging das Meldefenster an Loxone auf, sobald es einen Ansagetext
     * gab (morgen Feiertag, Ferien oder Brueckentag) - auch wenn im Plugin "Melden, wenn morgen schulfrei ist" bzw.
     * "Melden am Vorabend des Ferienbeginns" abgewaehlt war; nur die eigene Ansage hielt sich daran. Jetzt bleibt
     * ANN dort 0, wo der Anwender "nicht melden" gewaehlt hat - Zeile, MQTT und ?json=1 gleich. */
    $cfg = fer_config();
    if ($st === null) { $st = fer_state(); }
    return array(
        'ann'   => (fer_ann_active($st) && fer_ann_erlaubt($st, $cfg)) ? 1 : 0,
        'audio' => empty($cfg['notify']['audio']) ? 0 : 1,
        'push'  => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest' => fer_ptest_active(),
    );
}

/**
 * Darf zu diesem Anlass gemeldet werden?
 *
 * Bis 1.0.1 fragte diese Entscheidung nur 'freetag' ab - das Haekchen
 * "Melden am Vorabend des Ferienbeginns" in der Oberflaeche hatte damit
 * KEINE Wirkung. Wer nur den Ferienbeginn gemeldet haben wollte und
 * 'freetag' abwaehlte, bekam gar nichts mehr.
 *
 * Am Ferienbeginn gilt jetzt ODER, nicht ENTWEDER-ODER: es genuegt, wenn
 * EINES der beiden Haekchen gesetzt ist. Das ist Absicht. Ein UND oder ein
 * Umschalten haette bei jeder Anlage, die bisher nur 'freetag' gesetzt
 * hatte, eine Ansage STILL entfallen lassen - und ausgefallene Ansagen sind
 * genau das, was hier repariert werden soll. Niemand verliert etwas, was er
 * heute bekommt; wer 'ferienstart' allein setzt, bekommt endlich das, was
 * das Haekchen verspricht.
 */
function fer_ann_erlaubt($st, $cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $m = $st['morgen'];
    // Ferienbeginn: morgen Ferien, heute noch nicht.
    if (!empty($m['ferien']) && empty($st['heute']['ferien'])) {
        return (!empty($cfg['notify']['ferienstart']) || !empty($cfg['notify']['freetag'])) ? 1 : 0;
    }
    if (!empty($m['feiertag']) || !empty($m['ferien'])) {
        return !empty($cfg['notify']['freetag']) ? 1 : 0;
    }
    // Brueckentag: eigener Anlass, an das Januar-Haekchen NICHT gekoppelt -
    // das steuert nur die Jahresuebersicht.
    return !empty($m['bruecke']) ? 1 : 0;
}

/** Cron: Vorabend-Ansage (einmal taeglich) und Brueckentags-Uebersicht im Januar. */
function fer_announce_check() {
    $cfg = fer_config();
    $st = fer_state();
    if (!empty($cfg['notify']['audio'])) {
        // Verglichen wird ueber Zeitstempel, NICHT ueber date('H:i').
        //
        // Bis 1.0.1 stand hier date('H:i') === '19:00'. Traf der Cron die
        // Minute nicht - und der Minutencron trifft sie nicht zuverlaessig -,
        // fiel die Ansage des Tages ersatzlos aus. Aufgefallen ist das kaum,
        // weil es meistens klappte; genau das macht solche Fehler zaeh.
        //
        // Die Merkdatei said_<Datum> sorgt weiterhin dafuer, dass es bei
        // EINER Ansage je Tag bleibt, auch wenn der Cron sechzigmal
        // vorbeikommt.
        $start = fer_ann_start();
        if (time() >= $start && time() < $start + FER_ANN_NACHLAUF) {
            $flag = fer_tmpdir() . '/said_' . date('Ymd');
            if (!is_file($flag)) {
                @file_put_contents($flag, '1');
                $txt = fer_announce_text($st);
                $erlaubt = fer_ann_erlaubt($st, $cfg);
                if ($txt !== '' && $erlaubt) {
                    if (time() > $start + 90) {
                        fer_log('Ansage verspaetet (' . (int) ((time() - $start) / 60)
                            . ' min nach der Meldezeit) - der Minutencron war nicht puenktlich.');
                    }
                    fer_say($txt);
                }
            }
        }
    }
    // Einmal im Januar: Brueckentage des Jahres ins Protokoll (und optional Ansage)
    if (!empty($cfg['notify']['bridge_month']) && date('n') === '1' && $st['brueckentage']) {
        $flag = fer_tmpdir() . '/bridge_' . date('Y');
        if (!is_file($flag) && (int) date('G') >= 8) {
            @file_put_contents($flag, '1');
            $liste = array();
            foreach ($st['brueckentage'] as $t) {
                if (substr($t, 0, 4) === date('Y')) {
                    $liste[] = date('d.m.', strtotime($t));
                }
            }
            if ($liste) {
                fer_log('Brueckentage ' . date('Y') . ': ' . implode(', ', $liste));
                if (!empty($cfg['notify']['audio'])) {
                    fer_say('Hallo! In diesem Jahr gibt es ' . count($liste) . ' Brueckentage: ' . implode(', ', $liste) . '.');
                }
            }
        }
    }
    foreach (glob(fer_tmpdir() . '/said_*') ?: array() as $f) {
        if (basename($f) !== 'said_' . date('Ymd')) { @unlink($f); }
    }
}

/** Auswahlliste der Regionen (fuer die Oberflaeche). */
function fer_subdivisions($land = 'DE') {
    $cache = fer_tmpdir() . '/subs_' . preg_replace('/[^A-Z]/', '', strtoupper($land)) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 30 * 86400) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && $c) { return $c; }
    }
    $js = fer_http('https://openholidaysapi.org/Subdivisions?countryIsoCode=' . rawurlencode($land) . '&languageIsoCode=DE', 15);
    $d = @json_decode((string) $js, true);
    $out = array();
    if (is_array($d)) {
        foreach ($d as $e) {
            $name = isset($e['name'][0]['text']) ? $e['name'][0]['text'] : (isset($e['shortName']) ? $e['shortName'] : '');
            if (isset($e['code']) && $name !== '') {
                $out[$e['code']] = $name;
            }
        }
    }
    if ($out) {
        fer_json_schreiben($cache, $out);
    }
    return $out;
}

/** Auswahlliste der Schularten (groupCode) - fuer die Oberflaeche.
 *
 * Die meisten Laender fuehren keine; dann bleibt die Liste leer und die
 * Oberflaeche zeigt das Feld gar nicht erst an. Gemessen am 18.08.2026
 * liefert Deutschland genau zwei, beide fuer Mecklenburg-Vorpommern.
 */
function fer_gruppen($land = 'DE') {
    $cache = fer_tmpdir() . '/groups_' . preg_replace('/[^A-Z]/', '', strtoupper($land)) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 30 * 86400) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) { return $c; }
    }
    $js = fer_http('https://openholidaysapi.org/Groups?countryIsoCode=' . rawurlencode($land)
        . '&languageIsoCode=DE', 15);
    $d = @json_decode((string) $js, true);
    $out = array();
    if (is_array($d)) {
        foreach ($d as $e) {
            $name = fer_name($e, 'DE');
            if (isset($e['code']) && $name !== '') { $out[$e['code']] = $name; }
        }
        // Auch eine LEERE Antwort wird gemerkt - sonst fragt die Oberflaeche
        // bei jedem Aufbau erneut nach, und bei den meisten Laendern ist die
        // Antwort dauerhaft leer.
        fer_json_schreiben($cache, $out);
    }
    return $out;
}

/**
 * Wie viele Eintraege der VORHANDENEN Daten betrifft eine Einstellung?
 *
 * Damit die Oberflaeche nicht "koennte etwas aendern" schreiben muss,
 * sondern "betrifft auf dieser Anlage 0 Eintraege". Gelesen wird die rohe
 * Termindatei, NICHT fer_data() - das siebt ja bereits nach derselben
 * Einstellung und wuerde dann immer null melden.
 */
function fer_artstatistik() {
    $f = fer_datafile();
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $aus = array('feiertage_fremd' => 0, 'ferien_fremd' => 0, 'halbtage' => 0,
                 'arten' => array(), 'gesamt' => 0);
    if (!is_array($d)) { return $aus; }
    foreach (array('feiertage', 'ferien', 'ferien2') as $topf) {
        foreach ((array) (isset($d[$topf]) ? $d[$topf] : array()) as $e) {
            $aus['gesamt']++;
            $a = isset($e['art']) ? (string) $e['art'] : '';
            if ($a !== '') { $aus['arten'][$a] = (isset($aus['arten'][$a]) ? $aus['arten'][$a] : 0) + 1; }
            if (!empty($e['halbtag'])) { $aus['halbtage']++; }
            if ($topf === 'feiertage') {
                if ($a !== '' && $a !== 'Public') { $aus['feiertage_fremd']++; }
            } else {
                if ($a !== '' && $a !== 'School') { $aus['ferien_fremd']++; }
            }
        }
    }
    return $aus;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function fer_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function fer_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/. Gruppe 19 (1.2.28, FE2 d):
        // Wurzel und Ordner aus fer_paths() - bis 1.2.27 stand hier als
        // Rueckfall ein fester Systempfad.
        $fp = fer_paths();
        $pfad = $fp['lbhome'] !== '' ? $fp['lbhome'] . '/templates/plugins/' . $fp['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . fer_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ---------------- Die Feldliste: EINE Quelle fuer drei Wege ---------------- */

/**
 * name => array(analog, min, max, einheit, kommentar, MQTT-Thema).
 *
 * Aus dieser Liste entstehen seit 1.2.0 ALLE drei Wege: die Textzeile fuer
 * den Miniserver, die MQTT-Themen und die Importdatei fuer Loxone Config.
 *
 * WARUM DAS SO SEIN MUSS
 *
 * Bis 1.1.7 stand die MQTT-Liste getrennt in fer_mqtt_publish(). Die
 * Fassung 1.1.7 hat vier fehlende Merker nachgetragen und in die README
 * geschrieben, der MQTT-Weg liefere jetzt alles, was der HTTP-Weg liefert.
 * Nachgemessen am 18.08.2026 stimmte das nicht: beide Wege fuehrten 27
 * Werte, aber nicht dieselben. Ueber MQTT fehlten WOCHENENDE, MBRUECKE,
 * FERIENDAUER und URLAUBDAUER; dafuer trug MQTT vier Namenstexte, die es
 * ueber HTTP nicht gibt. Dass beide Seiten auf 27 kamen, war Zufall - und
 * genau deshalb ist es niemandem aufgefallen.
 *
 * Zwei Listen koennen auseinanderlaufen, eine nicht. Der Reiter Test misst
 * die Deckung ausserdem nach (fer_selbsttest).
 *
 * ZUR REIHENFOLGE - sie ist Teil der Schnittstelle
 *
 * Loxone sucht in der Zeile die woertliche Zeichenkette der
 * Befehlserkennung (z. B. "FERIEN=") und nimmt den ERSTEN Treffer. In
 * dieser Zeile steht "FERIEN=" aber auch als Teil von "MFERIEN=". Es geht
 * nur gut, weil jedes Heute-Feld VOR seinem M-Gegenstueck steht. Wer
 * umsortiert, liefert Loxone stillschweigend den Wert von morgen als den
 * von heute - ohne Fehlermeldung, nur mit falschen Weckern.
 *
 * Dieser Absatz stand bis 1.1.7 als Warnung in ferien.php und wurde von
 * nichts geprueft. Seit 1.2.0 prueft ihn fer_reihenfolge_pruefen() an der
 * fertigen Zeile, und der Reiter Test zeigt das Ergebnis.
 *
 * ZU MIN = -1
 *
 * FERIENIN, FEIERTAGIN, URLAUBIN, BRUECKEIN, FERIENNAECHSTEIN, FEIERTAG2IN
 * und FERIEN2IN liefern -1, wenn es nichts zu zaehlen gibt. Bis 1.1.7 stand
 * in der Importdatei trotzdem MinVal="0" - und URLAUBIN=-1 ist der
 * Normalzustand JEDER Anlage, in der kein Urlaub eingetragen ist. Die
 * massgebliche Ausfuhr aus Loxone Config (VI_Rasenmaeher, 12.08.2026) traegt
 * an genau den Feldern, die -1 liefern koennen, MinVal="-1". Danach richtet
 * sich das hier.
 */
function fer_felder() {
    return array(
        // Name              analog min  max  Einheit  Bedeutung                                MQTT-Thema
        'OK'          => array(0,  0,   1,   '',     '1 = Daten gueltig',                      'ok'),
        'FERIEN'      => array(0,  0,   1,   '',     'heute Ferien',                           'ferien'),
        'FEIERTAG'    => array(0,  0,   1,   '',     'heute Feiertag',                         'feiertag'),
        'WOCHENENDE'  => array(0,  0,   1,   '',     'heute Wochenende',                       'wochenende'),
        'SCHULFREI'   => array(0,  0,   1,   '',     'heute schulfrei',                        'schulfrei'),
        'SCHULTAG'    => array(0,  0,   1,   '',     'heute Schultag',                         'schultag'),
        'BRUECKE'     => array(0,  0,   1,   '',     'heute Brueckentag',                      'bruecke'),
        'MFERIEN'     => array(0,  0,   1,   '',     'morgen Ferien',                          'morgen_ferien'),
        'MFEIERTAG'   => array(0,  0,   1,   '',     'morgen Feiertag',                        'morgen_feiertag'),
        'MSCHULFREI'  => array(0,  0,   1,   '',     'morgen schulfrei',                       'morgen_schulfrei'),
        'MSCHULTAG'   => array(0,  0,   1,   '',     'morgen Schultag',                        'morgen_schultag'),
        'MBRUECKE'    => array(0,  0,   1,   '',     'morgen Brueckentag',                     'morgen_bruecke'),
        'FERIENIN'    => array(1, -1, 365, 'Tage',   'naechste Ferien beginnen in (0 = laufen, -1 = keine bekannt)', 'ferien_in'),
        'FERIENREST'  => array(1,  0, 365, 'Tage',   'laufende Ferien: Resttage',              'ferien_rest'),
        'FERIENDAUER' => array(1,  0, 365, 'Tage',   'naechste/laufende Ferien: Dauer',        'ferien_dauer'),
        'FEIERTAGIN'  => array(1, -1, 365, 'Tage',   'naechster Feiertag in',                  'feiertag_in'),
        'URLAUB'      => array(0,  0,   1,   '',     'heute Urlaub (eigene Termine)',          'urlaub'),
        'MURLAUB'     => array(0,  0,   1,   '',     'morgen Urlaub',                          'morgen_urlaub'),
        'URLAUBIN'    => array(1, -1, 365, 'Tage',   'naechster Urlaub beginnt in (-1 = keiner eingetragen)', 'urlaub_in'),
        'URLAUBREST'  => array(1,  0, 365, 'Tage',   'laufender Urlaub: Resttage',             'urlaub_rest'),
        'URLAUBDAUER' => array(1,  0, 365, 'Tage',   'Urlaub: Dauer',                          'urlaub_dauer'),
        'URLAUBENDE'  => array(0,  0,   1,   '',     'letzter Urlaubstag',                     'urlaub_letzter_tag'),
        'WARN'        => array(0,  0,   1,   '',     'Warnhinweis aktiv',                      'warnung'),
        'ANN'         => array(0,  0,   1,   '',     'Meldefenster aktiv',                     'ann'),
        'AUDIO'       => array(0,  0,   1,   '',     'Ansage freigegeben',                     'audio'),
        'PUSH'        => array(0,  0,   1,   '',     'Push freigegeben',                       'push'),
        'PTEST'       => array(0,  0,   1,   '',     'Test-Push ausloesen',                    'ptest'),

        /* --- neu in 1.2.0, hinten angehaengt -----------------------------
         * Hinten, weil das die einzige Stelle ist, an der ein neues Feld
         * kein bestehendes verdecken kann - und weil ein bestehender
         * Miniserver die Zeile dann unveraendert weiterliest. Die
         * Kollisionsfreiheit ist geprueft, nicht angenommen. */
        'WOCHENTAG'   => array(1,  1,   7,   '',     'Wochentag heute (1 = Montag ... 7 = Sonntag)', 'wochentag'),
        'MWOCHENTAG'  => array(1,  1,   7,   '',     'Wochentag morgen',                       'morgen_wochentag'),
        'FREITAGE'    => array(1,  0, 366, 'Tage',   'freie Tage am Stueck ab heute (0 = heute ist Arbeitstag)', 'freitage'),
        'MFREITAGE'   => array(1,  0, 366, 'Tage',   'freie Tage am Stueck ab morgen',         'morgen_freitage'),
        'FERIENENDE'  => array(0,  0,   1,   '',     'heute ist der letzte Ferientag',         'ferien_ende'),
        'MERSTERSCHULTAG' => array(0, 0, 1, '',      'morgen ist der erste Schultag nach den Ferien', 'morgen_erster_schultag'),
        'HALBTAG'     => array(0,  0,   1,   '',     'heute ist ein halber Feiertag',          'halbtag'),
        'MHALBTAG'    => array(0,  0,   1,   '',     'morgen ist ein halber Feiertag',         'morgen_halbtag'),
        'BRUECKEIN'   => array(1, -1, 366, 'Tage',   'naechster Brueckentag in (-1 = keiner bekannt)', 'bruecke_in'),
        'FERIENNAECHSTEIN' => array(1, -1, 365, 'Tage', 'naechste Ferien NACH den laufenden beginnen in', 'ferien_naechste_in'),
        'FEIERTAG2IN' => array(1, -1, 365, 'Tage',   'uebernaechster Feiertag in',             'feiertag2_in'),
        'URLAUBHEIM'  => array(0,  0,   1,   '',     'Vorwaermen zur Rueckkehr aus dem Urlaub', 'urlaub_heim'),
        'FERIEN2'     => array(0,  0,   1,   '',     'heute Ferien in der zweiten Region',     'ferien2'),
        'MFERIEN2'    => array(0,  0,   1,   '',     'morgen Ferien in der zweiten Region',    'morgen_ferien2'),
        'SCHULFREI2'  => array(0,  0,   1,   '',     'heute schulfrei in der zweiten Region',  'schulfrei2'),
        'MSCHULFREI2' => array(0,  0,   1,   '',     'morgen schulfrei in der zweiten Region', 'morgen_schulfrei2'),
        'SCHULTAG2'   => array(0,  0,   1,   '',     'heute Schultag in der zweiten Region',   'schultag2'),
        'MSCHULTAG2'  => array(0,  0,   1,   '',     'morgen Schultag in der zweiten Region',  'morgen_schultag2'),
        'FERIEN2IN'   => array(1, -1, 365, 'Tage',   'naechste Ferien der zweiten Region in',  'ferien2_in'),

        /* --- neu in 1.2.16, hinten angehaengt (Entscheidung 4, angepasst an
         * Kalenderdaten): das Alter des letzten GELUNGENEN Abrufs. Ein
         * gescheiterter Abruf frischt es nicht auf. "ALTER_TAGE=" steckt in
         * keinem anderen Feldnamen. */
        'ALTER_TAGE'  => array(1, -1, 3650, 'Tage',  'Tage seit dem letzten gelungenen Abruf (-1 = noch keiner)', 'alter_tage'),

        /* --- neu in 1.2.26, hinten angehaengt (Welle Bild 18, Entwurf Welle 18 ff, Entscheidung 3; Hausherr
         * 17.10.2026): das Lebenszeichen. Alle anderen Werte sind Tageswerte - steht der LoxBerry oder sein
         * Minutenlauf, behaelt Loxone sie, und nichts verraet es. ZAEHLER steigt je Minutenlauf um 1 (0..999,
         * -1 = noch kein Lauf); eine Analogwertvalidierung in Loxone merkt, wenn er stehen bleibt. Bauart wie
         * die Schwesterlinie AWM-Abfuhr (awm_zaehler()). Das Thema bleibt LEER: ein Wert, der sich jede Minute
         * aendert, gehoert nicht in die MQTT-Signatur (Regeln/07) - ueber MQTT gibt es status/ts.
         * "ZAEHLER=" steckt in keinem anderen Feldnamen. */
        'ZAEHLER'     => array(1, -1, 999, '',      'Lebenszeichen: Laufzaehler 0 bis 999, +1 je Minutenlauf', ''),
    );
}

/**
 * Das Lebenszeichen ZAEHLER (Welle Bild 18, 1.2.26; Entwurf Welle 18 ff, Entscheidung 3). $weiter = true zaehlt ihn
 * hoch - das tut nur der Minutenlauf (bin/cron.php), einmal je Lauf. Gelesen wird er von fer_werte() fuer die
 * Loxone-Zeile.
 *
 * Bauart wie awm_zaehler() der Schwesterlinie AWM-Abfuhr: 0..999 im Kreis, -1 = noch kein Lauf. Die Datei liegt im
 * Zwischenordner (fer_tmpdir(), unter /tmp), nicht im Datenordner: sie wird jede Minute geschrieben, und das gehoert
 * auf die Ramdisk, nicht auf die SD-Karte.
 */
function fer_zaehler($weiter = false)
{
    $f = fer_tmpdir() . '/zaehler.txt';
    $n = is_file($f) ? (int) @file_get_contents($f) : -1;
    if (!$weiter) {
        return ($n >= 0 && $n <= 999) ? $n : -1;
    }
    $n = ($n < 0 || $n >= 999) ? 0 : $n + 1;
    if (!fer_datei_schreiben($f, (string) $n)) {
        fer_log('Lebenszeichen: der Zaehler liess sich nicht schreiben (' . $f . ').');
    }
    return $n;
}

/**
 * Die Textwerte, die es NUR ueber MQTT gibt.
 *
 * Ein virtueller HTTP-Eingang in Loxone liest Zahlen, keine Zeichenketten -
 * deshalb stehen die Namen nicht in fer_felder(). Sie hier aufzufuehren
 * statt sie in fer_mqtt_publish() zu verstecken hat einen Grund: der
 * Reiter Test vergleicht beide Wege und muss wissen, was absichtlich nur
 * auf einem von beiden steht. Sonst meldet er jedes Mal vier Fehlstellen.
 */
function fer_mqtt_texte($st) {
    /* M3 (Durchgang 30.09.2026): "kein Name = '-'" wird NACH dem Saeubern
     * gefragt. Bis 1.2.15 davor: ein Name aus nur einem Zeilenumbruch galt als
     * vorhanden und ging leer hinaus. */
    $s = function ($w) { return fer_mqtt_nutzlast($w); };
    $oder = function ($w) { return $w !== '' ? $w : '-'; };
    $fn = $s($st['heute']['feiertag_name']);
    return array(
        'name' => $fn !== '' ? $fn : $oder($s($st['heute']['ferien_name'])),
        'ferien_name'   => $oder($s($st['naechste']['name'])),
        'urlaub_name'   => $oder($s($st['urlaub']['name'])),
        'feiertag_name' => $oder($s($st['feiertag_naechster']['name'])),
        'ferien2_name'  => $oder($s($st['naechste2']['name'])),
        'feiertag_hinweis' => $oder($s($st['heute']['hinweis'])),
    );
}

/**
 * Der Wert jedes Feldes aus fer_felder() - fuer BEIDE Wege.
 *
 * Hier und nirgends sonst wird entschieden, was ein Feld bedeutet. Die
 * Textzeile fuer den Miniserver und die MQTT-Meldung nehmen dasselbe
 * Ergebnis; sie koennen damit nicht mehr verschiedene Zahlen tragen.
 */
function fer_werte($st, $flags = null) {
    if ($flags === null) { $flags = fer_meldeflags($st); }
    $h = $st['heute'];
    $m = $st['morgen'];
    return array(
        'OK' => (int) $st['ok'],
        'FERIEN' => (int) $h['ferien'],
        'FEIERTAG' => (int) $h['feiertag'],
        'WOCHENENDE' => (int) $h['wochenende'],
        'SCHULFREI' => (int) $h['schulfrei'],
        'SCHULTAG' => (int) $h['schultag'],
        'BRUECKE' => (int) $h['bruecke'],
        'MFERIEN' => (int) $m['ferien'],
        'MFEIERTAG' => (int) $m['feiertag'],
        'MSCHULFREI' => (int) $m['schulfrei'],
        'MSCHULTAG' => (int) $m['schultag'],
        'MBRUECKE' => (int) $m['bruecke'],
        'FERIENIN' => (int) $st['naechste']['in'],
        'FERIENREST' => (int) $st['naechste']['rest'],
        'FERIENDAUER' => (int) $st['naechste']['dauer'],
        'FEIERTAGIN' => (int) $st['feiertag_naechster']['in'],
        'URLAUB' => (int) $h['urlaub'],
        'MURLAUB' => (int) $m['urlaub'],
        'URLAUBIN' => (int) $st['urlaub']['in'],
        'URLAUBREST' => (int) $st['urlaub']['rest'],
        'URLAUBDAUER' => (int) $st['urlaub']['dauer'],
        'URLAUBENDE' => (int) $st['urlaub']['letzter_tag'],
        'WARN' => (int) $st['warnung'],
        'ANN' => (int) $flags['ann'],
        'AUDIO' => (int) $flags['audio'],
        'PUSH' => (int) $flags['push'],
        'PTEST' => (int) $flags['ptest'],
        'WOCHENTAG' => (int) $h['wochentag'],
        'MWOCHENTAG' => (int) $m['wochentag'],
        'FREITAGE' => (int) $h['freitage'],
        'MFREITAGE' => (int) $m['freitage'],
        'FERIENENDE' => (int) $st['ferienende'],
        'MERSTERSCHULTAG' => (int) $st['merster_schultag'],
        'HALBTAG' => (int) $h['halbtag'],
        'MHALBTAG' => (int) $m['halbtag'],
        'BRUECKEIN' => (int) $st['bruecke_in'],
        'FERIENNAECHSTEIN' => (int) $st['naechste_nach']['in'],
        'FEIERTAG2IN' => (int) $st['feiertag_zweiter']['in'],
        'URLAUBHEIM' => (int) $st['urlaub']['heim'],
        'FERIEN2' => (int) $h['ferien2'],
        'MFERIEN2' => (int) $m['ferien2'],
        'SCHULFREI2' => (int) $h['schulfrei2'],
        'MSCHULFREI2' => (int) $m['schulfrei2'],
        'SCHULTAG2' => (int) $h['schultag2'],
        'MSCHULTAG2' => (int) $m['schultag2'],
        'FERIEN2IN' => (int) $st['naechste2']['in'],
        'ALTER_TAGE' => isset($st['alter_tage']) ? (int) $st['alter_tage'] : -1,
        'ZAEHLER' => fer_zaehler(false),
    );
}

/**
 * Die fertige Textzeile fuer den Miniserver.
 *
 * Entsteht aus fer_felder() und fer_werte(), nicht aus einem printf mit 46
 * Argumenten in fester Reihenfolge. Ein Argument zu verschieben war bis
 * 1.1.7 die leichteste Art, allen Feldern dahinter den falschen Wert zu
 * geben, ohne dass irgendwo etwas auffaellt.
 */
function fer_zeile($st, $flags = null) {
    $w = fer_werte($st, $flags);
    $t = 'FERIEN';
    foreach (fer_felder() as $name => $f) {
        $t .= ';' . $name . '=' . (isset($w[$name]) ? (int) $w[$name] : 0);
    }
    return $t . "\n";
}

/**
 * Prueft die Praefix-Falle an der FERTIGEN Zeile.
 *
 * Gemessen wird nicht der Name, sondern das, was Loxone tut: den ersten
 * Treffer von "<NAME>=" nehmen. Gehoert der zu einem anderen Feld, ist das
 * ein Befund. Rueckgabe: Liste der verdeckten Felder (leer = in Ordnung).
 *
 * Geeicht in drei Richtungen (18.08.2026): die bekannte Verdrehung
 * MFERIEN vor FERIEN wird rot, ein neu erfundener Fall MFERIEN2 vor FERIEN2
 * wird rot, die ausgelieferte Reihenfolge bleibt gruen - und dieselbe Liste
 * rueckwaerts wird rot.
 */
function fer_reihenfolge_pruefen($namen = null) {
    if ($namen === null) { $namen = array_keys(fer_felder()); }
    $z = 'FERIEN';
    foreach ($namen as $i => $n) { $z .= ';' . $n . '=' . ($i + 1); }
    $aus = array();
    foreach ($namen as $n) {
        $erster = strpos($z, $n . '=');
        $eigen  = strpos($z, ';' . $n . '=');
        if ($eigen === false || $erster !== $eigen + 1) { $aus[] = $n; }
    }
    return $aus;
}
/* ---------------- Selbstpruefung (Reiter Test) ---------------- */

/**
 * Beantwortet OHNE Loxone: traegt die Einrichtung?
 *
 * Aufbau je Zeile: array(frage, ok, hinweis). ok ist true (Haken),
 * false (Kreuz) oder null (Hinweis - "geht mich nichts an").
 *
 * DREI REGELN, DIE HIER EINGEBAUT SIND
 *
 * 1. Die Ursache steht VOR der Wirkung. "Sind ueberhaupt Daten da" kommt
 *    vor "stimmen die Felder" - wer die Reihenfolge umdreht, schickt den
 *    Leser in die falsche Ecke.
 * 2. Eine Zusammenfassung darf nicht besser aussehen als ihr schlechtester
 *    Punkt. Unklare Lagen zaehlen als null und werden NICHT zu den
 *    bestandenen gezaehlt - sonst entsteht ein "22 von 22", waehrend nichts
 *    funktioniert.
 * 3. Wer einen leeren Befund erklaert, muss die Erklaerung belegen koennen.
 *    "Keine Ferien gefunden" ist nur dann in Ordnung, wenn Schulferien
 *    abgeschaltet sind - und das wird nachgesehen, nicht angenommen.
 */
function fer_selbsttest($basis = '', $vorher = null) {
    $cfg = fer_config();
    $z = array();
    $add = function ($frage, $ok, $hinweis = '') use (&$z) {
        $z[] = array('frage' => $frage, 'ok' => $ok, 'hinweis' => $hinweis);
    };
    $p = fer_paths();
    $ordner = basename(dirname($p['config']));

    /* --- 0. Pflichtzeilen (O4, Durchgang 30.09.2026) --------------------
     * Konfiguration heil - gelesen VOR der Selbstheilung (index.php reicht die
     * Lage herein); ohne sie wird jetzt gelesen und das auch gesagt. */
    $nach = false;
    if (!is_array($vorher)) { $vorher = fer_konfig_lage(); $nach = true; }
    $heil = !empty($vorher['lesbar']) && !empty($vorher['token']) && empty($vorher['kaputt']);
    $lage_txt = empty($vorher['da']) ? fer_t('PRUEF.KONFIG_FEHLT')
        : (empty($vorher['lesbar']) ? fer_t('PRUEF.KONFIG_UNLESBAR')
        : (empty($vorher['token']) ? fer_t('PRUEF.KONFIG_OHNE_TOKEN') : fer_t('PRUEF.KONFIG_LESBAR')));
    $add(fer_t('PRUEF.F_KONFIG'), $heil,
        $lage_txt . (empty($vorher['kaputt']) ? '' : ' ' . sprintf(fer_t('PRUEF.H_KAPUTT'), implode(', ', $vorher['kaputt'])))
        . ' ' . fer_t($nach ? 'PRUEF.H_KONFIG_NACH' : 'PRUEF.H_KONFIG_VOR'));

    // Cron-Eintrag: glob ueber alle cron.*min-Ordner, nicht nur cron.01min.
    if ($p['lbhome'] !== '') {
        $cron = glob($p['lbhome'] . '/system/cron/cron.*min/' . $ordner) ?: array();
        $gut = array();
        foreach ($cron as $c) {
            if (is_file($c) && strpos((string) @file_get_contents($c), 'cron.php') !== false
                && (DIRECTORY_SEPARATOR === '\\' || is_executable($c))) {
                $gut[] = $c;
            }
        }
        $add(fer_t('PRUEF.F_CRON'), count($gut) === 1 && count($cron) === 1,
            count($cron) === 0 ? fer_t('PRUEF.H_CRON_FEHLT')
            : sprintf(fer_t(count($gut) === 1 && count($cron) === 1 ? 'PRUEF.H_CRON_OK' : 'PRUEF.H_CRON_FEHL'),
                      implode(', ', $cron)));
    } else {
        $add(fer_t('PRUEF.F_CRON'), null, fer_t('PRUEF.H_OHNE_WURZEL'));
    }

    // Formularmerkmal je POST-Formular, gezaehlt im Quelltext der Oberflaeche.
    $ui = '';
    foreach (array($p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $ordner . '/index.php',
                   dirname(__DIR__) . '/htmlauth/index.php') as $k) {
        if ($p['lbhome'] === '' && strpos($k, '/webfrontend/htmlauth/plugins/') === 0) { continue; }
        if (is_file($k)) { $ui = (string) @file_get_contents($k); break; }
    }
    list($nform, $ohne, $fmt_gut) = fer_pruef_formulare($ui);
    $add(fer_t('PRUEF.F_FORMULARE'), $ui === '' ? null : ($nform > 0 && $ohne === 0 && $fmt_gut),
        $ui === '' ? fer_t('PRUEF.H_FORMULARE_UNKLAR')
        : ($nform === 0 ? fer_t('PRUEF.H_FORMULARE_LEER')
        : sprintf(fer_t($ohne === 0 && $fmt_gut ? 'PRUEF.H_FORMULARE_OK' : 'PRUEF.H_FORMULARE_FEHL'), $nform - $ohne, $nform)));

    // Zwischenordner (I9)
    $tmp = fer_tmpdir();
    $aus = isset($GLOBALS['fer_tmp_ausweich']) ? $GLOBALS['fer_tmp_ausweich'] : null;
    $add(fer_t('PRUEF.F_TMP'), $aus === null,
        $aus === null ? $tmp : sprintf(fer_t('PRUEF.H_TMP_AUSWEICH'), $aus[0], $aus[1]));

    /* --- 1. Die Daten selbst ------------------------------------------- */
    $d = fer_data();
    $nf = 0; $nh = 0;
    foreach ((array) $d['ferien'] as $e) { if (empty($e['eigen'])) { $nf++; } }
    foreach ((array) $d['feiertage'] as $e) { if (empty($e['eigen'])) { $nh++; } }
    $roh = fer_termine_roh();
    $fremd = is_array($roh) && !fer_region_passt($roh, fer_region($cfg));
    $add(fer_t('PRUEF.F_DATEN'), !empty($d['quelle_da']),
        $fremd ? sprintf(fer_t('PRUEF.H_DATEN_FREMD'), (string) (isset($roh['land']) ? $roh['land'] : '?'),
                         (string) (isset($roh['sub']) ? $roh['sub'] : '?'))
               : sprintf(fer_t('PRUEF.H_DATEN'), $nf, $nh));

    // Leere Ferienliste erklaeren - aber nur, wenn die Erklaerung stimmt.
    if ($nf === 0 && !empty($d['quelle_da'])) {
        $add(fer_t('PRUEF.F_KEINE_FERIEN'),
            empty($cfg['school']) ? null : false,
            fer_t(empty($cfg['school']) ? 'PRUEF.H_KEINE_FERIEN_AUS' : 'PRUEF.H_KEINE_FERIEN_AN'));
    }

    $st = fer_state();
    $reicht = (string) $st['reicht_bis'];
    $weit = !empty($st['quelle_da']) && $reicht !== '' && $reicht >= date('Y-m-d', strtotime('+60 days'));
    $add(fer_t('PRUEF.F_REICHT'), $weit,
        empty($st['quelle_da']) ? fer_t('PRUEF.H_REICHT_OHNE')
        : sprintf(fer_t('PRUEF.H_REICHT'), $reicht !== '' ? $reicht : '-'));

    /* "zuletzt geholt" nach dem Stand des letzten GELUNGENEN Abrufs (O4, C4) -
     * nicht nach der Aenderungszeit der Datei, die ein gescheiterter Abruf bis
     * 1.2.15 auffrischte. */
    $alter = (int) $st['alter_tage'];
    $bremse = fer_abruf_bremse();
    $teil = !empty($st['teilausfall']) ? ' ' . sprintf(fer_t('PRUEF.H_TEILAUSFALL'), implode(', ', (array) $st['teilausfall'])) : '';
    $neu = $bremse > 0 ? ' ' . sprintf(fer_t('PRUEF.H_NEUVERSUCH'), (int) ceil($bremse / 60)) : '';
    $add(fer_t('PRUEF.F_ALTER'), $alter >= 0 && $alter <= 8 && $teil === '',
        ($alter < 0 ? fer_t('PRUEF.H_ALTER_NIE')
                    : sprintf(fer_t('PRUEF.H_ALTER'), $alter, substr((string) $st['stand_ok'], 0, 16)))
        . $teil . $neu);

    /* --- 2. Die Schnittstelle zu Loxone -------------------------------- */
    $verdeckt = fer_reihenfolge_pruefen();
    $add(fer_t('PRUEF.F_VERDECKT'), count($verdeckt) === 0,
        count($verdeckt) === 0
            ? sprintf(fer_t('PRUEF.H_VERDECKT_OK'), count(fer_felder()))
            : sprintf(fer_t('PRUEF.H_VERDECKT_FEHL'), implode(', ', $verdeckt)));

    // Deckt der MQTT-Weg alles ab, was der HTTP-Weg fuehrt?
    /* Welle Bild 18 (1.2.26): das Lebenszeichen ZAEHLER traegt bewusst kein Thema (Entwurf Welle 18 ff,
     * Entscheidung 3, wie AWM). Gruppe 1 (1.2.27; Entwurf ff 2.2): die gewollte Zuordnung ist ZAEHLER <-> status/zaehler
     * - erlaubt ist das leere Thema nur, solange das Lebenszeichen status/zaehler wirklich gesendet wird
     * (fer_mqtt_lebenszeichen()). Jedes andere Feld ohne Thema bleibt ein Befund. */
    $ohne_t = array();
    $mit_t = 0;
    $lz_themen = array_keys(fer_mqtt_lebenszeichen($st));
    foreach (fer_felder() as $name => $fd) {
        if (!isset($fd[5]) || $fd[5] === '') {
            if (!($name === 'ZAEHLER' && in_array('status/zaehler', $lz_themen, true))) { $ohne_t[] = $name; }
        } else {
            $mit_t++;
        }
    }
    $add(fer_t('PRUEF.F_MQTT_DECKUNG'), count($ohne_t) === 0,
        count($ohne_t) === 0
            ? sprintf(fer_t('PRUEF.H_MQTT_DECKUNG_OK'), $mit_t, count(fer_mqtt_texte($st)))
            : sprintf(fer_t('PRUEF.H_MQTT_DECKUNG_FEHL'), implode(', ', $ohne_t)));

    // Ist die Importdatei fuer Loxone Config wohlgeformt - mit Hinweistext
    // und Kommentaren bis 40 Zeichen (O7)?
    $vorlage = fer_vorlage();
    $wohl = null;
    $lang = 0; $ohne_h = 0; $nb = 0;
    if (function_exists('simplexml_load_string')) {
        $vorher_x = libxml_use_internal_errors(true);
        $x = simplexml_load_string($vorlage[1]);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher_x);
        $wohl = $x !== false;
        if ($wohl) {
            foreach ($x->VirtualInHttpCmd as $c) {
                $nb++;
                /* 1.2.26: in Zeichen, nicht in Byte - die Kommentare tragen jetzt Umlaute (Sprachdatei). */
                if (preg_match_all('/./su', (string) $c['Comment']) > 40) { $lang++; }
                if (trim((string) $c['HintText']) === '') { $ohne_h++; }
            }
        }
    }
    $add(fer_t('PRUEF.F_VORLAGE'), $wohl === null ? null : ($wohl && $nb > 0 && $lang === 0 && $ohne_h === 0),
        $wohl === null ? fer_t('PRUEF.H_VORLAGE_OHNE_XML')
                       : sprintf(fer_t('PRUEF.H_VORLAGE'), $vorlage[0], $nb, $lang, $ohne_h));

    /* --- 3. Der eigene Endpunkt, wirklich aufgerufen -------------------- */
    $soll = is_string($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    $add(fer_t('PRUEF.F_TOKEN'), $soll !== '',
        fer_t($soll !== '' ? 'PRUEF.H_TOKEN_JA' : 'PRUEF.H_TOKEN_NEIN'));

    /* Gruppe 19 (1.2.28, Entwurf 3.2): bei abgeschaltetem HTTP-Weg (endpunkt_ein <> 1) eine graue Zeile ohne Aufruf -
     * ferien.php antwortet dann mit 403 ENDPUNKT_AUS, und das ist gewollt. */
    if ((int) $cfg['endpunkt_ein'] !== 1) {
        $add(fer_t('PRUEF.F_ENDPUNKT'), null, fer_t('PRUEF.H_ENDPUNKT_AUS'));
    } elseif ($basis !== '' && $soll !== '') {
        /* Drei Sekunden, nicht acht: diese Pruefung laeuft bei jedem
         * Seitenaufbau des Reiters, und im Fehlerfall wartet der Anwender
         * sonst vor einer leeren Seite. Beurteilt wird der HTTP-STATUS (O4):
         * gut ist 200 mit OK=1 bzw. 403 mit ERR=TOKEN - keine Antwort ist
         * weder das eine noch das andere. */
        list($c1, $a1) = fer_http_status(rtrim($basis, '/') . '/ferien.php?selftest=1&token=' . rawurlencode($soll), 3);
        $add(fer_t('PRUEF.F_ENDPUNKT'), $c1 === 0 ? null : ($c1 === 200 && strpos($a1, 'OK=1') !== false),
            $c1 === 0 ? fer_t('PRUEF.H_ENDPUNKT_STUMM')
                      : sprintf(fer_t('PRUEF.H_ENDPUNKT'), $c1, trim(substr($a1, 0, 80))));
        if ($c1 !== 0) {
            list($c2, $a2) = fer_http_status(rtrim($basis, '/') . '/ferien.php?selftest=1&token=falsch', 3);
            $add(fer_t('PRUEF.F_FALSCH'), $c2 === 0 ? null : ($c2 === 403 && strpos($a2, 'ERR=TOKEN') !== false),
                sprintf(fer_t('PRUEF.H_FALSCH'), $c2, trim(substr($a2, 0, 80))));
        }
    }

    /* Gruppe 1 / MQTT (1.2.27): passt die Namenstabelle des Reiters "Einbindung in Loxone" zu den gesendeten Themen?
     * Zwei Quellen: die Namen der Tabelle (fer_mqtt_namenstabelle(), aus fer_felder() Spalte 6, der Zuordnung ZAEHLER ->
     * status/zaehler und fer_mqtt_texte()) gegen die Nachrichten des Senders (fer_mqtt_nachrichten(), aus fer_werte()
     * und dem Lebenszeichen) mit Quelldaten. Ein Name, der nie gesendet wird, ergaebe in Loxone einen toten Eingang. */
    $nt = fer_mqtt_namenstabelle($cfg);
    $gesendet = array();
    $pf_v = fer_mqtt_praefix($cfg);
    foreach (array_keys(fer_mqtt_nachrichten(array_merge($st, array('quelle_da' => 1)))) as $th) {
        $gesendet[fer_mqtt_gateway_name($pf_v, $th)] = true;
    }
    $fremd_t = array();
    foreach ($nt as $ne) {
        if (!isset($gesendet[$ne[0]])) { $fremd_t[] = $ne[0]; }
    }
    $add(fer_t('PRUEF.F_NAMEN_MQTT'), count($nt) > 0 && count($fremd_t) === 0,
        count($fremd_t) === 0 ? sprintf(fer_t('PRUEF.H_NAMEN_MQTT_OK'), count($nt))
                              : sprintf(fer_t('PRUEF.H_NAMEN_MQTT_FEHL'), implode(', ', $fremd_t)));

    /* --- 4. MQTT ------------------------------------------------------- */
    if (!empty($cfg['mqtt_enabled'])) {
        $auto = fer_mqtt_gateway_autostart();
        $add(fer_t('PRUEF.F_AUTOSTART'), $auto === null ? null : $auto,
            fer_t($auto === null ? 'PRUEF.H_GEN_UNLESBAR' : ($auto ? 'PRUEF.JA' : 'PRUEF.H_AUTOSTART_NEIN')));
        $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
        $port = 0;
        if (isset($gen['Mqtt']) && is_array($gen['Mqtt']) && isset($gen['Mqtt']['Udpinport'])) {
            $port = (int) $gen['Mqtt']['Udpinport'];
        }
        $add(fer_t('PRUEF.F_PORT'), $port >= 1 && $port <= 65535,
            $port ? sprintf(fer_t('PRUEF.H_PORT'), $port) : fer_t('PRUEF.H_PORT_KEINER'));
    } else {
        $add('MQTT', null, fer_t('PRUEF.H_MQTT_AUS'));
    }

    /* --- 5. Die neuen Wege ---------------------------------------------- */
    if (is_string($cfg['ics_url']) && trim($cfg['ics_url']) !== '') {
        /* Nur der GESPEICHERTE Stand (C13) - die Selbstpruefung fragt den
         * Kalender nicht selbst. */
        $k = fer_ics_holen();
        $kd = fer_ics_lesen_datei();
        $fehler = isset($kd['fehler']) ? (string) $kd['fehler'] : '';
        $add(fer_t('PRUEF.F_ICS'), count($k) > 0 && $fehler === '',
            sprintf(fer_t('PRUEF.H_ICS'), count($k), isset($kd['stand']) && $kd['stand'] !== '' ? substr((string) $kd['stand'], 0, 16) : '-')
            . ((isset($kd['jaehrlich']) || isset($kd['jaehrlich_weg']))
               ? ' ' . sprintf(fer_t('PRUEF.H_ICS_JAEHRLICH'), (int) (isset($kd['jaehrlich']) ? $kd['jaehrlich'] : 0),
                               (int) (isset($kd['jaehrlich_weg']) ? $kd['jaehrlich_weg'] : 0)) : '')
            . ($fehler !== '' ? ' ' . sprintf(fer_t('PRUEF.H_ICS_FEHLER'), $fehler) : ''));
    }
    /* Gruppe 19 (1.2.28; FE11, E2, E3, V4): der Kalenderleser gegen feste Faelle (beidseitig geeicht wie AWM A12). */
    $fer_icsf = fer_ics_selbstpruefung();
    $add(fer_t('PRUEF.F_ICS_REGELN'), count($fer_icsf) === 0,
        count($fer_icsf) === 0 ? sprintf(fer_t('PRUEF.H_ICS_REGELN_OK'), count(fer_ics_selbstfaelle()))
                               : sprintf(fer_t('PRUEF.H_ICS_REGELN_FEHL'), implode(', ', $fer_icsf)));
    if (is_string($cfg['subdivision2']) && trim($cfg['subdivision2']) !== '') {
        $n2 = count((array) (isset($d['ferien2']) ? $d['ferien2'] : array()));
        $add(fer_t('PRUEF.F_REGION2'), $n2 > 0,
            sprintf(fer_t('PRUEF.H_REGION2'), $n2, $cfg['subdivision2']));
    }

    /* Gruppe 1 (1.2.27, Befehlsbruecke): nimmt sie Befehle an? "aus" (Haken, ab Werk) ist ein Hinweis, kein Kreuz. */
    $bl = fer_bruecke_lage();
    $add(fer_t('PRUEF.F_BRUECKE'), $bl[0], $bl[1]);

    /* Gruppe 19 (1.2.28): Noncached genau fuer ok (Hausform G15-G18), und die Haus-Themen haus/kalender: Themenliste
     * gegen die Werte in beide Richtungen (Regeln/07), kein Wert '', nur ganze Zahlen; bei Haken an "Broker erreichbar"
     * (eine Sitzung ohne Senden - ohne Kennwort, ohne Wert in der Zeile). */
    $fer_nc = array();
    foreach (fer_mqtt_hakenliste($cfg) as $fer_h) {
        if (isset($fer_h[4]) && $fer_h[4] === 'N') { $fer_nc[] = $fer_h[1]; }
    }
    $fer_nc_soll = array(fer_mqtt_gateway_name(fer_mqtt_praefix($cfg), 'ok'));
    $add(fer_t('PRUEF.F_NONCACHED'), $fer_nc === $fer_nc_soll,
        sprintf(fer_t($fer_nc === $fer_nc_soll ? 'PRUEF.H_NONCACHED_OK' : 'PRUEF.H_NONCACHED_FEHL'), implode(', ', $fer_nc)));
    $fer_hw = fer_haus_werte($st, $cfg, fer_zaehler(false));
    $fer_ht = fer_haus_themen();
    $fer_hf = array_merge(array_keys(array_diff_key($fer_ht, $fer_hw)), array_keys(array_diff_key($fer_hw, $fer_ht)));
    foreach ($fer_hw as $fer_hk => $fer_v) {
        if (!is_int($fer_v) || (string) $fer_v === '') { $fer_hf[] = $fer_hk; }
    }
    $add(fer_t('PRUEF.F_HAUS'), count($fer_hf) === 0,
        count($fer_hf) === 0 ? sprintf(fer_t('PRUEF.H_HAUS_OK'), count($fer_ht))
                             : sprintf(fer_t('PRUEF.H_HAUS_FEHL'), implode(', ', array_unique($fer_hf))));
    if (!empty($cfg['haus_kalender_ein']) && !empty($cfg['mqtt_enabled'])) {
        $fer_hs = fer_haus_sitzung(array());
        $add(fer_t('PRUEF.F_HAUS_BROKER'), $fer_hs['lage'] === 'ok',
            $fer_hs['lage'] === 'ok' ? fer_t('PRUEF.JA') : sprintf(fer_t('PRUEF.H_HAUS_BROKER_NEIN'), $fer_hs['lage']));
    }

    /* --- 6. Ansage ------------------------------------------------------ */
    /* Nr. 36 b, Stufe 2: eine Zeile fuer alle Ausgabearten (fer_pruefe_ansage()). */
    $az = fer_pruefe_ansage($cfg);
    if ($az !== null) {
        $add($az[0], $az[1], $az[2]);
    }

    return $z;
}

/** Die Lage der Konfigurationsdatei - fuer die Pflichtzeile "heil". */
function fer_konfig_lage() {
    $p = fer_paths();
    $da = is_file($p['config']);
    $roh = $da ? (string) @file_get_contents($p['config']) : '';
    $dd = $da ? json_decode($roh, true) : null;
    $kaputt = array();
    foreach (glob($p['config'] . '.kaputt*') ?: array() as $k) { $kaputt[] = basename($k); }
    return array('da' => $da, 'lesbar' => is_array($dd), 'token' => fer_config_hat_inhalt($dd),
                 'kaputt' => $kaputt);
}

/**
 * Traegt jedes POST-Formular der Oberflaeche das Formularmerkmal? (O4)
 * Gezaehlt im Quelltext (Bauform audi/au_pruef_formulare): ein Formular
 * traegt es, wenn sein Block fer_fmt() ruft - und fer_fmt() muss das Feld
 * wirklich mit Wert ausgeben. Rueckgabe: array(Formulare, ohne, fmt_gut).
 */
function fer_pruef_formulare($quelle) {
    $q = (string) $quelle;
    $fmt = fer_fmt();
    $fmt_gut = strpos($fmt, 'name="fmt"') !== false && preg_match('/value="[0-9a-f]{64}"/', $fmt) === 1;
    $n = 0;
    $ohne = 0;
    foreach (preg_split('/<form\b/i', $q) as $i => $teil) {
        if ($i === 0) { continue; }
        $ende = stripos($teil, '</form>');
        $blk = ($ende === false) ? $teil : substr($teil, 0, $ende);
        if (!preg_match('/^[^>]*method="post"/i', $blk)) { continue; }
        $n++;
        if (strpos($blk, 'fer_fmt()') === false) { $ohne++; }
    }
    return array($n, $ohne, $fmt_gut);
}

/** Zaehlt die Selbstpruefung aus. Ein Hinweis (null) zaehlt NICHT als bestanden. */
function fer_selbsttest_bilanz($zeilen) {
    $gut = $schlecht = $offen = 0;
    foreach ($zeilen as $z) {
        if ($z['ok'] === null) { $offen++; }
        elseif ($z['ok']) { $gut++; }
        else { $schlecht++; }
    }
    return array('gut' => $gut, 'schlecht' => $schlecht, 'offen' => $offen,
                 'gesamt' => $gut + $schlecht);
}

/**
 * Der Suchtext einer Loxone-Befehlserkennung - an EINER Stelle.
 *
 * Vor dem Feldnamen steht das Semikolon, mit dem die Antwortzeile ihre
 * Felder trennt. Ohne dieses Zeichen haengt die Richtigkeit an der
 * REIHENFOLGE: Loxone nimmt die erste Fundstelle, und der Name SCHULTAG
 * steckt als Endstueck auch in MSCHULTAG. Bis 1.2.0 ging das gut, weil
 * jedes Heute-Feld vor seinem M-Gegenstueck steht - aber das war eine
 * Wette auf die Sortierung, und beim naechsten neuen Feld waere die Falle
 * wieder offen gewesen. Mit dem Trennzeichen ist die Frage strukturell
 * erledigt: in der Antwortzeile steht vor JEDEM Feldnamen eines, auch vor
 * dem ersten.
 *
 * Der Anlass steht in REGELN_3 A11, gemessen am 20.08.2026 an drei fremden
 * Linien: ein Kilometerstand las die Inspektionsvorgabe, weil sein Suchtext
 * ohne Trennzeichen zuerst auf das laengere Feld traf. Beide Zahlen sahen
 * aus wie ein Kilometerstand; gemeldet hat sich nichts.
 *
 * EINE Stelle, weil die Regel sonst auseinanderlaeuft: die Importdatei und
 * die Tabelle im Reiter Einbindung zeigen denselben Text. In den betroffenen
 * Linien wurde seinerzeit die Vorlage berichtigt und die Oberflaeche nicht.
 *
 * Der Erklaertext verzichtet bewusst darauf, die Schreibweise auszuschreiben:
 * suchmuster_pruefen.py sucht danach und wuerde den Kommentar mitzaehlen.
 * Wer sie sehen will, liest die Rueckgabe eine Zeile weiter unten.
 */
function fer_check($feld) {
    return '\i;' . $feld . '=\i\v';
}

/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 *  CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 *  Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht. */
function fer_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="' . fer_vx(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . fer_vx($kopf['title']) . '" ';
    $o .= 'Comment="' . fer_vx(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fer_vx(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . fer_vx(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . fer_vx($c['title']) . '" ';
        $o .= 'Comment="' . fer_vx($c['comment']) . '" ';
        $o .= 'Check="' . fer_vx($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . fer_vx(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        /* O7 (Durchgang 30.09.2026): die Erklaerung steht im Hinweistext, der
         * Kommentar ist die kurze Beschriftung (hoechstens 40 Zeichen - Loxone
         * macht ihn zum Kachelnamen und schneidet ihn ab). */
        $o .= 'HintText="' . fer_vx(isset($c['hint']) ? $c['hint'] : '') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function fer_vx($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Die kurze Beschriftung je Feld fuer die Importdatei (O7): hoechstens 40
 * Zeichen samt Einheit. Bis 1.2.15 stand dort die volle Bedeutung - acht von
 * 46 Kommentaren waren laenger (bis 67 Zeichen), und Loxone schnitt den
 * Kachelnamen ab. Die volle Bedeutung steht jetzt im Hinweistext.
 */
function fer_kurztext($name) {
    /* Welle Bild 18 (1.2.26; Entwurf Welle 18 ff, Entscheidung 12): die kurzen Beschriftungen stehen in der
     * Sprachdatei ([T12] VL_K_<Feld>; deutsch mit Umlauten, englisch uebersetzt) - Sprachregel 13.10.2026: Titel fest,
     * Kommentare uebersetzt. Bis 1.2.25 standen sie hier fest deutsch und ohne Umlaute. */
    $k = fer_t('T12.VL_K_' . $name);
    return $k !== 'T12.VL_K_' . $name ? $k : $name;
}

/**
 * Die Baustein-Listen fuer den Reiter "Einbindung in Loxone" (Welle Bild 18, 1.2.26; Hausherr 11.10.2026:
 * Vollausstattung, ein absoluter Laie soll das Maximum leicht uebernehmen koennen; Entwurf Welle 18 ff,
 * Entscheidungen 1, 4, 14 und 16). Drei Seiten aus dem LoxBerry-Plugins Musterprojekt, dort in Loxone Config
 * gebaut und verbunden (Musterprojekt/baustein_listen.txt, Abschnitte "Ferien und Feiertage" 36, "... Meldungen" 33
 * und "... Haus" 35 Zeilen, wortgleich). Je Tabellenzeile EIN Sprachschluessel BAUSTEIN.Z<seite>_<nn> =
 * "Typ | Name | Parameter | Eingaenge" ohne Auszeichnung; index.php gibt jede Zelle und jeden Satz maskiert aus.
 *
 * Die Liste zeigt das MQTT-Praefix ab Werk ("ferien"). Wie das MQTT-Gateway den Namen bildet, wird das fuehrende
 * "ferien_" im Namen der Virtuellen Texteingaenge und das Abo "ferien/#" durch das eingestellte Praefix ersetzt;
 * ab Werk bleibt alles wortgleich mit der Liste. FERIEN_... (Titel der Vorlage) bleibt.
 *
 * Bis 1.2.25 stand im Reiter eine Tabelle mit 80 Zeilen (Behaelter, 47 Befehle, 32 Logikzeilen), die zwoelf
 * Schwellwertschalter an 0/1-Felder setzte - genau die Bauart, die am 07.09.2026 an dieser Linie im Haus eine
 * Push-Meldung je Abholung (alle 300 s) erzeugte (Regeln/09) -, und die keine Ausfallerkennung kannte.
 *
 * Rueckgabe: je Seite 'kopf', 'seite' (Name der Musterseite), 'bild', 'schritte' und 'hinweise' (Saetze),
 * 'zeilen' (je array(Nummer, Typ, Name, Parameter, Eingaenge)) - alles reiner Text.
 */
function fer_bausteinliste()
{
    $cfg = fer_config();
    /* Gruppe 1 / MQTT (1.2.27): seit die Tabellen MQTT-Namen tragen (ferien_ok, ferien/befehl/ansage ...), folgt
     * jedes "ferien/" und "ferien_" der Zellen, Kaesten und Hinweise dem eingestellten Praefix - nicht nur das Abo
     * "ferien/#". Ausgenommen sind Stellen hinter "/" oder einem Buchstaben (Adressen /plugins/ferien/...). Ab Werk
     * bleibt alles wortgleich mit der Liste. Praefix aus fer_mqtt_praefix() (derselben Rechnung wie der Sender). */
    $pf = fer_mqtt_praefix($cfg);
    $flach = str_replace('/', '_', $pf);
    $seiten = array(
        array('kopf' => fer_t('BAUSTEIN.BS1_KOPF'), 'seite' => 'Ferien und Feiertage', 'bild' => 'einbindung_loxone.png', 'z' => 1, 'n' => 36,
              'schritte' => array(
                fer_t('BAUSTEIN.BS1_S1'),
                fer_t('BAUSTEIN.BS1_S2'),
                fer_t('BAUSTEIN.BS1_S3'),
                fer_t('BAUSTEIN.BS1_S4'),
                fer_t('BAUSTEIN.BS1_S5'),
                fer_t('BAUSTEIN.BS1_S6'),
                fer_t('BAUSTEIN.BS1_S7')),
              'hinweise' => array(
                fer_t('BAUSTEIN.BS1_H1'),
                fer_t('BAUSTEIN.BS1_H2'),
                fer_t('BAUSTEIN.BS1_H3'),
                fer_t('BAUSTEIN.BS1_H4'),
                fer_t('BAUSTEIN.BS1_H5'),
                fer_t('BAUSTEIN.BS1_H6'))),
        array('kopf' => fer_t('BAUSTEIN.BS2_KOPF'), 'seite' => 'Ferien und Feiertage Meldungen', 'bild' => 'einbindung_loxone_meldungen.png', 'z' => 2, 'n' => 33,
              'schritte' => array(
                fer_t('BAUSTEIN.BS2_S1'),
                fer_t('BAUSTEIN.BS2_S2'),
                fer_t('BAUSTEIN.BS2_S3'),
                fer_t('BAUSTEIN.BS2_S4'),
                fer_t('BAUSTEIN.BS2_S5'),
                fer_t('BAUSTEIN.BS2_S6'),
                fer_t('BAUSTEIN.BS2_S7')),
              'hinweise' => array(
                fer_t('BAUSTEIN.BS2_H1'),
                fer_t('BAUSTEIN.BS2_H2'),
                fer_t('BAUSTEIN.BS2_H3'),
                fer_t('BAUSTEIN.BS2_H4'),
                fer_t('BAUSTEIN.BS2_H5'))),
        array('kopf' => fer_t('BAUSTEIN.BS3_KOPF'), 'seite' => 'Ferien und Feiertage Haus', 'bild' => 'einbindung_loxone_haus.png', 'z' => 3, 'n' => 35,
              'schritte' => array(
                fer_t('BAUSTEIN.BS3_S1'),
                fer_t('BAUSTEIN.BS3_S2'),
                fer_t('BAUSTEIN.BS3_S3'),
                fer_t('BAUSTEIN.BS3_S4'),
                fer_t('BAUSTEIN.BS3_S5'),
                fer_t('BAUSTEIN.BS3_S6')),
              'hinweise' => array(
                fer_t('BAUSTEIN.BS3_H1'),
                fer_t('BAUSTEIN.BS3_H2'),
                fer_t('BAUSTEIN.BS3_H3'),
                fer_t('BAUSTEIN.BS3_H4'),
                fer_t('BAUSTEIN.BS3_H5'))),
    );
    $aus = array();
    $ersetzen = function ($x) use ($pf, $flach) {
        if ($pf === 'ferien') { return $x; }
        $x = preg_replace('#(?<![A-Za-z0-9_/])ferien/#', $pf . '/', $x);
        return preg_replace('#(?<![A-Za-z0-9_/])ferien_#', $flach . '_', $x);
    };
    foreach ($seiten as $s) {
        $zeilen = array();
        for ($i = 1; $i <= $s['n']; $i++) {
            $c = array_pad(explode(' | ', fer_t(sprintf('BAUSTEIN.Z%d_%02d', $s['z'], $i)), 4), 4, '');
            if (strpos($c[1], 'ferien_') === 0) { $c[1] = $flach . substr($c[1], 6); }
            $zeilen[] = array($i, $c[0], $c[1], $ersetzen($c[2]), $ersetzen($c[3]));
        }
        $aus[] = array('kopf' => $s['kopf'], 'seite' => $s['seite'], 'bild' => $s['bild'],
                       'schritte' => array_map($ersetzen, $s['schritte']),
                       'hinweise' => array_map($ersetzen, $s['hinweise']), 'zeilen' => $zeilen);
    }
    return $aus;
}

/** Hausstandard: Gateway-Autostart aus general.json (PLUGIN_HAUSREGELN Abschnitt 3). */
function fer_mqtt_gateway_autostart() {
    /* Die Wurzel wird GELESEN - LBHOMEDIR, sonst aufwaerts gesucht
     * (fer_paths()) -, nicht auf einen festen Systempfad geraten. Bis 1.2.13
     * stand hier ein harter Rueckfall: ohne LBHOMEDIR (Cron, Kommandozeile)
     * las die Funktion eine general.json, die es nicht gab, und meldete
     * "nicht pruefbar", waehrend fer_selbsttest() dieselbe Datei ueber
     * fer_paths() fand. Gemessen 18.09.2026 in WSL (PHP 8.3.6, env -i) und
     * unter Windows-PHP 7.4.33/8.4.24: null statt false
     * (Pruefung-FerienFeiertage-1.2.14, P1/G1; Bestand-2026-09-18/klasse-H). */
    $p = fer_paths();
    if ($p['lbhome'] === '') { return null; }
    $gj = $p['lbhome'] . '/config/system/general.json';
    if (!is_file($gj)) { return null; }
    $d = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d) || !isset($d['Mqtt'])) { return null; }
    return !empty($d['Mqtt']['Gatewayautostart']);
}

/**
 * Kopf der Importvorlage: Titel, Hinweistext, Adresse, Abfragezyklus. EINE
 * Quelle fuer die Importdatei und die Baustein-Liste im Reiter "Einbindung in
 * Loxone" (X-8, 02.10.2026) - bis 1.2.20 stand der Kopf nur als Literal in
 * fer_vorlage(), und die Liste nannte den Titel gar nicht.
 */
function fer_vorlage_kopf() {
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    /* Gruppe 19 (1.2.28, FE5): der Ordner aus fer_paths() - LBPPLUGINDIR ist am Geraet nie eine Umgebungsvariable,
     * eine Zweitinstallation (ferien_01) bekam bis 1.2.27 die Adresse der ersten. */
    $ordner = fer_paths()['plugin'];
    /* Welle Bild 18 (1.2.26): der Hinweistext aus der Sprachdatei (Entscheidung 12); 'host' und 'ordner' dazu fuer die
     * Ausgangsvorlage (fer_vorlage_vo()) - die Importdatei schreibt sie nicht. */
    return array(
        'title' => 'Ferien und Feiertage',
        'hint' => fer_t('T12.VL_KOPF_HINT'),
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/ferien.php',
        'polling' => '300',
        'host' => $host,
        'ordner' => $ordner,
    );
}

/**
 * Die Befehle des virtuellen HTTP-Eingangs - EINE Quelle fuer die Importdatei
 * und die Baustein-Liste (X-8, 02.10.2026). 'feld' liest nur die Liste; die
 * Vorlage schreibt es nicht.
 */
function fer_eingangsbefehle() {
    $cmds = array();
    foreach (fer_felder() as $name => $f) {
        // Das sechste Element ist das MQTT-Thema; die Importdatei braucht es
        // nicht. Ausgeschrieben statt per list(), damit beim naechsten
        // Anbau auffaellt, dass die Liste sechs Spalten hat.
        $analog  = $f[0];
        $min     = $f[1];
        $max     = $f[2];
        $einheit = $f[3];
        /* Welle Bild 18 (1.2.26; Entwurf Welle 18 ff, Entscheidung 12): Kommentar und Hinweistext aus der Sprachdatei
         * ([T12] VL_K_/VL_H_<Feld>), Unit <v.0> - alle Felder sind ganze Zahlen (bis 1.2.25 "<v.1>", also "12.0 Tage").
         * Der Hinweistext nennt bei Feldern, die -1 tragen koennen, was -1 heisst; beim Lebenszeichen ZAEHLER
         * "noch kein Lauf". Form wie Musterprojekt/vorlagen_w18/VI_ferien.xml. */
        $kurz = fer_kurztext($name);
        $text = fer_t('T12.VL_H_' . $name);
        $minus = ($name === 'ZAEHLER') ? fer_t('T12.VL_MINUS1_ZAEHLER') : fer_t('T12.LX_MINUS1');
        $cmds[] = array(
            'title' => 'FERIEN_' . $name,
            'comment' => $kurz . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'hint' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : '')
                    . ($min < 0 ? ' - ' . $minus : ''),
            'check' => fer_check($name),
            'unit' => ($einheit !== '' ? '<v.0> ' . $einheit : '<v.0>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
            'feld' => $name,
        );
    }
    return $cmds;
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function fer_vorlage() {
    return array('VI_ferien.xml', fer_xml_virtual_in_http(fer_vorlage_kopf() + array(
        'comment' => sprintf(fer_t('T12.VL_KOPF_KOMMENTAR'), date('d.m.Y')),
    ), fer_eingangsbefehle()));
}

/* ---------------- Gruppe 1 / MQTT: Vorlage, Hakenliste, Abo, Befehle (1.2.27) ----------------
 *
 * Hausherr 18.10.2026: Werte UND Befehle zwischen Plugin und Loxone gehen ueber MQTT; Musterprojekt, Vorlagen und Haus
 * nutzen nur MQTT, der HTTP-Weg bleibt fuer bestehende Anlagen. Gateway V1 und V2 werden beide unterstuetzt. Entwurf
 * Musterprojekt/entwurf_gruppe1_ff.txt (E1-E16 wie empfohlen) und entwurf_gruppe1_gemeinsam.txt (Befehlsbruecke).
 */

/**
 * Das Praefix der MQTT-Themen - EINE Rechnung fuer Sender (fer_mqtt_publish() rechnet fer_mqtt_thema(<topic> . '/' .
 * <thema>), was dasselbe ergibt), Vorlage, Hakenliste, Abo-Datei, Baustein-Liste und Befehlsbruecke (Regeln/07
 * "Themennamen aus EINER Funktion"). Leer = 'ferien'.
 */
function fer_mqtt_praefix($cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $t = trim((string) (isset($cfg['mqtt_topic']) && is_scalar($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : ''));
    $p = fer_mqtt_thema($t !== '' ? $t : 'ferien');
    return $p !== '' ? $p : 'ferien';
}

/** Der Name, den das MQTT-Gateway (V1 und V2) dem Thema gibt: "/" und "%" werden "_" (Regeln/07, mqtt_gateway.py
 *  build_vi_name). Das Thema baut dieselbe Funktion wie der Sender (fer_mqtt_thema()). */
function fer_mqtt_gateway_name($praefix, $thema) {
    return str_replace(array('/', '%'), '_', fer_mqtt_thema($praefix . '/' . $thema));
}

/**
 * Die Zahlenthemen fuer Loxone, Thema => Feld, in der Reihenfolge von fer_felder() (Entwurf ff 2.1): jedes Feld mit
 * Thema (Spalte 6) und ZAEHLER mit dem Lebenszeichen status/zaehler (2.2) - 48 Werte, genau die 48 Zahleneingaenge
 * der drei Musterseiten-Behaelter. Eine Importvorlage entsteht daraus nicht (Hausherr 19.10.2026); sie speisen
 * Namenstabelle und Hakenliste. Nicht dabei: die Textthemen (fer_mqtt_textthemen()), datum und status/ts.
 */
function fer_mqtt_vorlage_themen() {
    $aus = array();
    foreach (fer_felder() as $name => $f) {
        if (isset($f[5]) && $f[5] !== '') {
            $aus[$f[5]] = $name;
        } elseif ($name === 'ZAEHLER') {
            $aus['status/zaehler'] = $name;
        }
    }
    return $aus;
}

/** Die Textthemen (ohne Praefix) in der Reihenfolge von fer_mqtt_texte() - aus derselben Funktion gelesen, mit einem
 *  leeren Zustand (nur die Namen werden gebraucht). */
function fer_mqtt_textthemen() {
    $leer = array('heute' => array('feiertag_name' => '', 'ferien_name' => '', 'hinweis' => ''),
                  'naechste' => array('name' => ''), 'urlaub' => array('name' => ''),
                  'feiertag_naechster' => array('name' => ''), 'naechste2' => array('name' => ''));
    return array_keys(fer_mqtt_texte($leer));
}

/**
 * Die Hakenliste fuer Gateway V2 (Entwurf ff 2.5 und E6): die 48 Zahlenthemen und die Textthemen, die die
 * Musterseiten als Virtuellen Texteingang tragen (heute drei: name, feiertag_name, ferien_name). Welche das sind und
 * welche Datenpunkte auf den Musterseiten verdrahtet sind, wird an den Namen der Baustein-Liste abgelesen
 * (fer_bausteinliste()), nicht von Hand gefuehrt. Je Eintrag array(Thema mit Praefix, Gateway-Name, auf den
 * Musterseiten, Textthema, Kennzeichen). Kein Datenpunkt braucht "Zuruecksetzen nach Senden": ptest steht 300 s auf 1
 * und faellt, ann ist ein 10-Minuten-Fenster - echte Wechsel, kein wiederholter gleicher Wert. Kennzeichen (Gruppe 19,
 * 1.2.28): 'N' = "Noncached" im Gateway, nur fuer ok (Hausform G15-G18: ok traegt fast immer 1; ohne Noncached kaeme
 * nach einem Neustart des Miniservers keine neue 1 an), sonst ''.
 */
function fer_mqtt_hakenliste($cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $pf = fer_mqtt_praefix($cfg);
    $seiten = array();
    foreach (fer_bausteinliste() as $s) {
        foreach ($s['zeilen'] as $z) { $seiten[$z[2]] = true; }
    }
    $aus = array();
    foreach (array_keys(fer_mqtt_vorlage_themen()) as $t) {
        $n = fer_mqtt_gateway_name($pf, $t);
        $aus[] = array(fer_mqtt_thema($pf . '/' . $t), $n, isset($seiten[$n]), false, $t === 'ok' ? 'N' : '');
    }
    /* Die Texte in der Reihenfolge, in der die Musterseiten sie fuehren (Seite "... Meldungen" Nr. 31-33). */
    $texte = array();
    foreach (fer_mqtt_textthemen() as $t) { $texte[fer_mqtt_gateway_name($pf, $t)] = $t; }
    foreach (array_keys($seiten) as $n) {
        if (isset($texte[$n])) { $aus[] = array(fer_mqtt_thema($pf . '/' . $texte[$n]), $n, true, true, ''); }
    }
    return $aus;
}

/**
 * Die Namenstabelle fuer den Reiter "Einbindung in Loxone" (Gruppe 1, 1.2.27; Entscheidung des Hausherrn 19.10.2026).
 *
 * Fuer Werte ueber das MQTT-Gateway gibt es KEINE Importvorlage (kein VirtualHttpIn mit http://localhost): die
 * LoxBerry-Anleitung (wiki.loxberry.de, widget_mqtt) und Regeln/07 verlangen einen einfachen "Virtuellen Eingang" je
 * Wert, Name = Name, den das Gateway vergibt. Diese Tabelle nennt jeden Namen zum Kopieren, mit Bedeutung,
 * Einheit/Bereich und Art (Zahl -> Virtueller Eingang, Text -> Virtueller Texteingang). Quelle der Zahlen:
 * fer_mqtt_vorlage_themen() (fer_felder() Spalte 6 und status/zaehler fuer ZAEHLER), Bedeutung und Einheit wie der
 * HTTP-Befehl desselben Feldes (Sprachdatei [T12] VL_H_<Feld>); dazu alle Textthemen (fer_mqtt_textthemen()).
 * Je Eintrag array(Gateway-Name, Bedeutung, Einheit/Bereich, 'zahl'|'text', auf den Musterseiten).
 */
function fer_mqtt_namenstabelle($cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    $pf = fer_mqtt_praefix($cfg);
    $seiten = array();
    foreach (fer_bausteinliste() as $s) {
        foreach ($s['zeilen'] as $z) { $seiten[$z[2]] = true; }
    }
    $felder = fer_felder();
    $aus = array();
    foreach (fer_mqtt_vorlage_themen() as $thema => $feld) {
        $f = $felder[$feld];
        $n = fer_mqtt_gateway_name($pf, $thema);
        $text = fer_t('T12.VL_H_' . $feld);
        if ($feld === 'ZAEHLER') { $text .= ' - ' . fer_t('T12.VL_MQTT_ZAEHLER'); }
        $bereich = $f[1] . ' … ' . $f[2] . ($f[3] !== '' ? ' ' . $f[3] : '');
        if (!$f[0] && $f[1] === 0 && $f[2] === 1) { $bereich = '0/1'; }
        $aus[] = array($n, $text, $bereich, 'zahl', isset($seiten[$n]));
    }
    foreach (fer_mqtt_textthemen() as $t) {
        $n = fer_mqtt_gateway_name($pf, $t);
        $aus[] = array($n, fer_t('T12.EB_TXT_' . strtoupper($t)), fer_t('T12.EB_TXT_STRICH'), 'text', isset($seiten[$n]));
    }
    return $aus;
}

/**
 * Die Abo-Datei des MQTT-Gateways V1: config/plugins/<ordner>/mqtt_subscriptions.cfg (Entwurf ff E7; Bauform
 * Einspeisebremse 0.9.20 eb_abo_datei(), Regeln/07). Das Gateway V1 liest sie selbst und abonniert jede Zeile; das
 * Archiv bringt sie mit "ferien/#" mit. Weicht das Praefix ab, wird sie nachgeschrieben - nur wenn sie abweicht, mit
 * Protokollzeile (Speichern im Reiter MQTT und Minutenlauf). Rueckgabe array(Pfad, traegt das Abo).
 */
function fer_abo_datei($praefix, $schreiben = false) {
    $p = fer_paths();
    $ordner = dirname($p['config']);
    $pfad = $ordner . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/') . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $roh !== $soll . "\n" && is_dir($ordner)) {
        if (fer_datei_schreiben($pfad, $soll . "\n", 0644)) {
            fer_log('Gateway-Abo gesetzt: ' . $soll . ' (' . basename($pfad) . ')');
            $da = true;
        }
    }
    return array($pfad, $da);
}

/**
 * Ansage jetzt sprechen - die Verarbeitung von ?say=1 (ferien.php) als Funktion, EINE fuer den HTTP-Weg und die
 * Befehlsbruecke (Entwurf ff Abschnitt 3 und E8: "dieselbe Pruefung, keine zweite Logik"). Das Token prueft nur der
 * HTTP-Weg, den MQTT-Weg der Haken "Befehle ueber MQTT annehmen". Spricht die Vorabend-Ansage, ohne Anlass einen
 * Testsatz. Rueckgabe array('ok', 'grund', 'r' => Ergebnis von fer_say(), 'text'); ferien.php baut daraus dieselbe
 * Antwort wie bis 1.2.26. 'grund' (ASCII, fuer die Quittung "abgelehnt: <grund>") nur ohne Erfolg: die Kennung der
 * gemeinsamen Sprachausgabe (z. B. AUS), nie Text oder Token. Rechnet in Europe/Berlin und stellt die Zone danach
 * zurueck (die Befehlsbruecke ist ein eigener Prozess).
 */
function fer_befehl_ansage($quelle = 'http') {
    $fer_z = fer_zone_an();
    try {
        $st = fer_state();
        $text = fer_announce_text($st);
        if ($text === '') {
            $text = 'Hallo! Dies ist eine Testansage des Ferien-Plugins. Morgen ist ein ganz normaler Tag.';
        }
        if ($quelle === 'mqtt') { fer_log('Ansage angefordert ueber MQTT (Befehlsbruecke).'); }
        $r = fer_say($text);
        $ok = $r['stand'] === 1;
        $grund = '';
        if (!$ok) {
            $k = explode('|', (string) $r['kennung']);
            $grund = substr(strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $k[0])), 0, 40);
            if ($grund === '') { $grund = $r['stand'] === -1 ? 'NICHT_GESENDET' : 'NICHT_GESPROCHEN'; }
        }
        return array('ok' => $ok, 'grund' => $grund, 'r' => $r, 'text' => $text);
    } finally {
        fer_zone_aus($fer_z);
    }
}

/**
 * Test-Push ausloesen - die Verarbeitung von ?ptest=1 (ferien.php) als Funktion fuer beide Wege (wie
 * fer_befehl_ansage()). Setzt PTEST fuer fuenf Minuten und meldet sofort ueber MQTT, statt bis zu einer Minute auf den
 * Cron zu warten. Die Protokollzeile des HTTP-Wegs bleibt wortgleich; ueber MQTT kommt " - ueber MQTT" dazu.
 * Rueckgabe array('ok', 'grund'); ok = der Merker liess sich schreiben (der HTTP-Weg antwortet wie bisher OK=1).
 */
function fer_befehl_testpush($quelle = 'http') {
    $fer_z = fer_zone_an();
    try {
        $ok = @file_put_contents(fer_tmpdir() . '/ptest', '1') !== false;
        fer_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)'
            . ($quelle === 'mqtt' ? ' - ueber MQTT (Befehlsbruecke)' : ''));
        fer_mqtt_publish();
        return array('ok' => $ok, 'grund' => $ok ? '' : 'MERKER_NICHT_GESCHRIEBEN');
    } finally {
        fer_zone_aus($fer_z);
    }
}

/**
 * Die Befehlsbruecke als Bibliothek laden (BB_NUR_LADEN; Stammfassung Werkzeuge/gemeinsam/befehlsbruecke.php, byte-gleich
 * unter bin/). Installiert liegt sie unter bin/plugins/<ordner>/, in einem Arbeitsordner unter bin/ neben
 * webfrontend/. Rueckgabe true, wenn bb_waechter() danach da ist.
 */
function fer_bruecke_laden() {
    if (function_exists('bb_waechter')) { return true; }
    $p = fer_paths();
    $kand = array();
    if ($p['lbhome'] !== '') {
        $kand[] = $p['lbhome'] . '/bin/plugins/' . basename(dirname($p['config'])) . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste(): EINE Quelle fuer Reiter, Vorlage und Musterprojekt). Rueckgabe
 *  array(Liste oder null, Fehlertext). */
function fer_bruecke_liste() {
    if (!fer_bruecke_laden()) { return array(null, fer_t('BEFEHL.T_NICHT_DA')); }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) { return array(null, $fehler); }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(null, $fehl[0]); }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/**
 * Die Bruecke sofort schalten (Reiter MQTT, nach dem Speichern; wie Abfuhrkalender awm_bruecke_starten()). Soll sie laufen
 * (Haken "Befehle ueber MQTT annehmen" und MQTT an): befehlsbruecke.sh start - startet nur nach --soll, nie als root, nie
 * waehrend eines Updates, nie zweimal (Sperre und argumentweise Probe im Startskript). Soll sie nicht laufen und laeuft
 * sie: befehlsbruecke.sh stop. Warum hier und nicht nur im Waechter: der Waechter startet nach einem Start hoechstens alle
 * 600 s neu (Stammfassung, Neustart-Bremse) - wer den Haken aus- und wieder einschaltet, haette sonst bis zu 10 Minuten
 * keine Bruecke. Rueckgabe array(rc oder null = nichts zu tun, 'start'|'stop'|'', Ausgabe).
 */
function fer_bruecke_schalten($cfg = null) {
    if ($cfg === null) { $cfg = fer_config(); }
    if (!fer_bruecke_laden()) { return array(1, '', fer_t('BEFEHL.T_NICHT_DA')); }
    $p = bb_pfade();
    if (!$p) { return array(1, '', 'keine LoxBerry-Installation erkannt'); }
    $soll = !empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_enabled']);
    if ($soll) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) { return array(null, '', ''); }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Die Vorlage der MQTT-Befehle (bb_vorlage(): eigener Ausgang "Ferien und Feiertage MQTT-Befehle" an
 *  /dev/udp/<LoxBerry>/<Udpinport>, Pulse mit fester 1). Rueckgabe array(name, inhalt) oder null. */
function fer_vorlage_befehle() {
    list($l) = fer_bruecke_liste();
    if ($l === null) { return null; }
    $kopf = fer_vorlage_kopf();
    $bp = bb_pfade();
    $port = $bp ? bb_broker($bp)['udpin'] : 11884;
    return array('VQ_ferien_mqtt.xml', bb_vorlage($l, $kopf['host'], $port));
}

/**
 * Lage der Befehlsbruecke fuer den Reiter Test und die Selbstpruefung (Entwurf gemeinsam 2.11). Gemessen wird das
 * Erzeugnis wie im Waechter: Dienst laeuft (argumentweise), zustand.json juenger als 180 s, Abo steht. Rueckgabe
 * array(ok true/false/null, Text) - null heisst "aus" (Haken) bzw. nicht feststellbar, und zaehlt nicht als bestanden.
 */
function fer_bruecke_lage() {
    if (!fer_bruecke_laden()) { return array(false, fer_t('BEFEHL.T_NICHT_DA')); }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) { return array(null, fer_t('BEFEHL.T_AUS')); }
    if ($soll !== 0) { return array(false, sprintf(fer_t('BEFEHL.T_DEFEKT'), $grund)); }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(fer_t('BEFEHL.T_LAEUFT'), implode(' ', $pids),
            is_string($zu['abo']) ? $zu['abo'] : fer_mqtt_praefix() . '/befehl/#',
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = isset($zu['letzter']) && is_array($zu['letzter']) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(fer_t('BEFEHL.T_LETZTER'), (string) $lz['name'],
                isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-',
                isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . fer_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE
        ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo' . (is_array($zu) && !empty($zu['fehler']) ? ': ' . $zu['fehler'] : '')));
    return array(false, sprintf(fer_t('BEFEHL.T_STEHT'), $wie));
}

/**
 * Virtueller Ausgang (Welle Bild 18, 1.2.26; Entwurf Welle 18 ff, Entscheidung 8). Form wie die Ausfuhr aus Loxone
 * Config und wie Musterprojekt/vorlagen_w18/VQ_ferien_aktionen.xml: Wurzel VirtualOut mit HintText, Title, Comment,
 * Address, CmdInit, CloseAfterSend="true", CmdSep; erstes Kind Info templateType="3"; je Befehl Title, Comment,
 * CmdOnMethod, CmdOffMethod, CmdOn, CmdOnHTTP, CmdOnPost, CmdOff, CmdOffHTTP, CmdOffPost, CmdAnswer, Analog, Repeat,
 * RepeatRate, HintText (digital, nur CmdOn). CRLF und Tabulator wie fer_xml_virtual_in_http().
 */
function fer_xml_virtual_out($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . fer_vx($kopf['title']) . '" Comment="' . fer_vx($kopf['comment'])
        . '" Address="' . fer_vx($kopf['address']) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd Title="' . fer_vx($c['title']) . '" Comment="' . fer_vx($c['comment'])
            . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . fer_vx($c['on']) . '" CmdOnHTTP="" CmdOnPost=""'
            . ' CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0"'
            . ' HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die Ausgangsvorlage "Ferien und Feiertage Aktionen" (Welle Bild 18, 1.2.26; Entwurf Welle 18 ff, Entscheidung 8):
 * die zwei Aufrufe, mit denen Loxone das Plugin anspricht - Ansage jetzt (?say=1) und Test-Push (?ptest=1), je mit
 * dem Aktionstoken. Titel fest deutsch (Sprachregel 13.10.2026), Kommentare aus der Sprachdatei. ?refresh=1 bewusst
 * nicht (Bremse 300 s, die Daten kommen woechentlich; "Jetzt abrufen" im Reiter Einstellungen genuegt). Kein
 * Verhalten aendert sich - die Adressen gibt es seit 1.1.x. Nach "Neues Token erzeugen" neu herunterladen.
 * Rueckgabe: array(name, inhalt).
 */
function fer_vorlage_vo() {
    $kopf = fer_vorlage_kopf();
    $cfg = fer_config();
    $token = is_string($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    $basis = '/plugins/' . $kopf['ordner'] . '/ferien.php?';
    return array('VQ_ferien_aktionen.xml', fer_xml_virtual_out(array(
        'title' => 'Ferien und Feiertage Aktionen',
        'comment' => fer_t('T12.VL_VO_KOMMENTAR'),
        'address' => 'http://' . $kopf['host'],
    ), array(
        array('title' => 'Ansage jetzt sprechen', 'comment' => fer_t('T12.VL_VO_K_SAY'),
              'on' => $basis . 'say=1&token=' . rawurlencode($token)),
        array('title' => 'Test-Push auslösen', 'comment' => fer_t('T12.VL_VO_K_PTEST'),
              'on' => $basis . 'ptest=1&token=' . rawurlencode($token)),
    )));
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function fer_gateway_fassung()
{
    /* Gruppe 1 / MQTT (1.2.27; Entwurf ff E15, Regeln/07 Z. 58): die Wurzel kommt aus fer_paths() - derselben Quelle
     * wie fer_mqtt_gateway_autostart() (LBHOMEDIR, sonst aufwaerts gesucht). Bis 1.2.26 las diese Funktion nur
     * getenv('LBHOMEDIR'); ohne die Umgebung (Cron, Kommandozeile) nannte der Reiter dann beide Faelle, waehrend die
     * Autostart-Pruefung dieselbe general.json fand. */
    $p = fer_paths();
    $home = $p['lbhome'];
    if ($home === '' && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function fer_abo_text()
{
    $f = fer_gateway_fassung();
    if ($f <= 0) {
        return fer_t('T12.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(fer_t('T12.ABO_GEMESSEN'), $f) . '</span>';
    return fer_t($f >= 2 ? 'T12.ABO_V2' : 'T12.MQ_OHNE') . $gemessen;
}


/**
 * Den ganzen Konfigurationsstand ablegen - und sagen, ob es geklappt hat.
 *
 * Bisher schrieb diese Linie mitten in index.php. Das Zurueckspielen einer
 * Sicherung braucht aber EINE Stelle, sonst steht die Pruefung "hat es
 * geklappt?" an vier Orten verschieden da.
 *
 * Der Schreibweg ist der, den die Linie ohnehin benutzt - hier wird kein
 * Verhalten geaendert, nur ein vorhandenes zusammengefasst.
 */
function fer_config_speichern($cfg)
{
    $p = fer_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    /* C8/C9 (Durchgang 30.09.2026): Nebendatei, 0600 vor dem Inhalt,
     * Laengenvergleich, rename. Bis 1.2.15 schrieb diese Stelle - wie die
     * vier Handler in index.php - direkt in die Datei: ein gleichzeitiger
     * Leser (Cron, Endpunkt) hielt die halbe Datei fuer kaputt, heilte aus
     * der aelteren Zweitschrift, und das Speichern war verloren (gemessen 61
     * bzw. 97 von 3000). Alle Konfigurationsschreibungen gehen jetzt hierher. */
    return fer_datei_schreiben($p['config'], $js, 0600);
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 *
 * X-3 (Verbesserungsbau 01.10.2026): $namen (optional, per Verweis) bekommt
 * die NAMEN der beanstandeten Einstellungen (bei notify/tts die Unterfelder,
 * z. B. "tts.port") - fuer die Warnung am Knopf "Einstellungen sichern". Die
 * Namen tragen nie einen Wert. Schluessel, die mit "_" beginnen, sind
 * Kopfzeilen und werden uebergangen (bis dahin: "fremd").
 */
function fer_sicherung_lesen($roh, $geltend = null, &$namen = null)
{
    if (!is_array($namen)) { $namen = array(); }
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(fer_t('TEXT.SICH_KEIN_JSON')), 0, array());
    }
    $neu = fer_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;       // Kopfzeile (_warnung), keine Einstellung
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(fer_t('TEXT.SICH_FREMD'), $k);
            $namen[] = $k;
            continue;
        }
        /* C2 (Durchgang 30.09.2026): ein LEERES Token aus einer Sicherung.
         * Bis 1.2.15 wurde es angenommen; die Zweitschrift-Wache liess die
         * alte Zweitschrift stehen, die naechste fer_config() heilte daraus,
         * und das Zurueckspielen war still rueckgaengig gemacht - waehrend die
         * Seite "24 Werte uebernommen" meldete. Jetzt: das GELTENDE Token
         * bleibt, und die Meldung sagt es (Muster Ecowitt 0.9.15: uebernehmen
         * und benennen). Ist keines eingerichtet, wird abgewiesen. */
        if ($k === 'aktionstoken' && is_string($w) && trim($w) === '') {
            $g = (is_array($geltend) && isset($geltend['aktionstoken']) && is_string($geltend['aktionstoken']))
                ? trim($geltend['aktionstoken']) : '';
            if ($g === '') {
                $mangel[] = fer_t('TEXT.SICH_TOKEN_LEER_KEINS');
                $namen[] = $k;
                continue;
            }
            $neu[$k] = $g;
            $hinweise[] = fer_t('TEXT.SICH_TOKEN_LEER');
            $anzahl++;
            continue;
        }
        /* Ansage-3: ebenso das Sprechtoken fuer Google-Lautsprecher - benannt,
         * nie mit dem Wert; "" ist keines. Weiter unten (nach dem Alexa-NG-Block,
         * damit beide genannt werden) wird der Schluessel dann uebergangen. */
        $fer_gtm = false;
        if ($k === 'tts' && is_array($w) && array_key_exists('google_token', $w)) {
            if ($w['google_token'] !== '') {
                $mangel[] = fer_t('GOOGLE.SICH_TOKEN');
                $namen[] = 'tts.google_token';
                $fer_gtm = true;
            } else {
                unset($w['google_token']);
            }
        }
        /* Ansage-2: das Alexa-NG-Sprechtoken steht in keiner Sicherung dieses
         * Plugins. Bringt eine Datei eines mit, wird sie abgewiesen - benannt,
         * nie mit dem Wert; ein leeres Feld ist keines. Das geltende bleibt
         * (unten, nach der Schleife). */
        if ($k === 'tts' && is_array($w) && array_key_exists('alexa_token', $w)) {
            if ($w['alexa_token'] !== '') {
                $mangel[] = fer_t('ALEXA.SICH_TOKEN');
                $namen[] = 'tts.alexa_token';
                continue;
            }
            unset($w['alexa_token']);
        }
        if ($fer_gtm) { continue; }
        /* C1: jeder Wert wie im Formular - Typ, Muster, Bereich, Auswahl.
         * Bis 1.2.15 wurde nur der SCHLUESSEL geprueft: ein Token als Liste
         * wurde am Endpunkt zu "Array" und oeffnete ihn, ein Land als Liste
         * brachte unter PHP 8.5 jeden Seitenaufbau zum Absturz. */
        list($ok, $wert, $grund) = fer_wert_pruefen($k, $w);
        if (!$ok) {
            $mangel[] = sprintf(fer_t('TEXT.SICH_WERT'), $k, $grund);
            /* X-3: bei notify/tts die Unterfelder benennen - mit derselben
             * Pruefung, nur ohne den Grund (der zeigt den Wert). */
            $unter = array();
            if (($k === 'notify' || $k === 'tts') && is_array($w)) {
                foreach ($w as $uk => $uw) {
                    list($uok) = fer_wert_pruefen($k . '.' . $uk, $uw);
                    if (!$uok) { $unter[] = $k . '.' . $uk; }
                }
            }
            $namen = array_merge($namen, $unter ? $unter : array($k));
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    if ($anzahl > 0 && $neu['subdivision2'] !== '' && $neu['subdivision2'] === $neu['subdivision']) {
        $mangel[] = sprintf(fer_t('TEXT.SICH_WERT'), 'subdivision2', fer_t('MELD.W_REGION2_GLEICH'));
        $namen[] = 'subdivision2';
    }
    if ($anzahl === 0) {
        $mangel[] = fer_t('TEXT.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts. */
    /* Gruppe 19 (1.2.28, N1/E-E14): Schluessel, die die Fassung der Sicherung noch nicht kannte
     * (fer_spaet_schluessel()), sind keine Beanstandung - es gilt der GELTENDE Wert (sonst die Vorgabe), und der
     * Hinweis nennt sie. Bis 1.2.27 liess sich deshalb schon keine 1.2.26-Sicherung zurueckspielen (befehle_mqtt_ein). */
    $fehlend = array();
    $spaet = array();
    foreach (array_keys(fer_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            if (in_array($fk, fer_spaet_schluessel(), true)) {
                if (is_array($geltend) && array_key_exists($fk, $geltend)) {
                    list($gok, $gw) = fer_wert_pruefen($fk, $geltend[$fk]);
                    if ($gok) { $neu[$fk] = $gw; }
                }
                $spaet[] = $fk;
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if ($spaet) { $hinweise[] = sprintf(fer_t('TEXT.SICH_SPAET'), implode(', ', $spaet)); }
    if ($fehlend) {
        $mangel[] = sprintf(fer_t('TEXT.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
        $namen = array_merge($namen, $fehlend);
    }
    /* Die Meldungen sind reiner Text: die Oberflaeche maskiert sie genau
     * einmal (O3 - bis 1.2.15 wurde ein fremder Schluessel zweimal maskiert). */
    /* Ansage-2: das geltende Sprechtoken bleibt (es steht in keiner Sicherung). */
    if (!$mangel && is_array($neu['tts'])) {
        $alt_tok = (is_array($geltend) && isset($geltend['tts']) && is_array($geltend['tts'])
            && isset($geltend['tts']['alexa_token']) && ansage_token_ok($geltend['tts']['alexa_token']))
            ? $geltend['tts']['alexa_token'] : '';
        $neu['tts']['alexa_token'] = $alt_tok;
        if ($alt_tok !== '') { $hinweise[] = fer_t('ALEXA.SICH_BLEIBT'); }
        /* Ansage-3: ebenso das geltende Sprechtoken fuer Google-Lautsprecher. */
        $alt_gtok = (is_array($geltend) && isset($geltend['tts']) && is_array($geltend['tts'])
            && isset($geltend['tts']['google_token']) && ansage_token_ok($geltend['tts']['google_token']))
            ? $geltend['tts']['google_token'] : '';
        $neu['tts']['google_token'] = $alt_gtok;
        if ($alt_gtok !== '') { $hinweise[] = fer_t('GOOGLE.SICH_BLEIBT'); }
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/**
 * X-3 (Verbesserungsbau 01.10.2026): Welche gespeicherten Einstellungen wuerde
 * das eigene Zurueckspielen abweisen?
 *
 * Gefragt wird DIESELBE Funktion wie beim Zurueckspielen (fer_sicherung_lesen())
 * mit genau dem Inhalt, den "Einstellungen sichern" liefert - der vollen
 * Konfiguration. Rueckgabe: die Namen (leer: die Sicherung liesse sich
 * zurueckspielen). Nie Werte. Anlass: ein Wert aus einer frueheren Fassung
 * (etwa ein Port 70000 oder eine webcal://-Adresse) stand bis dahin
 * unbemerkt in jeder Sicherung, und aufgefallen waere es erst beim Umzug.
 */
function fer_rueckspiel_altwerte($voll = null)
{
    if ($voll === null) { $voll = fer_config(); }
    // Ansage-2/3: wie "Einstellungen sichern" ohne die Sprechtoken (Nr. 36 b: eine Quelle).
    if (isset($voll['tts']) && is_array($voll['tts'])) { $voll['tts'] = ansage_sicherung_bereinigen($voll['tts']); }
    $js = json_encode($voll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return array('(JSON)'); }
    $namen = array();
    $erg = fer_sicherung_lesen($js, $voll, $namen);
    if ($erg[0] === null && !$namen) { $namen[] = '?'; }
    return array_values(array_unique($namen));
}

/** Die angebotenen Laender (Formular und Sicherung). */
function fer_laender() {
    return array('DE', 'AT', 'CH', 'LU', 'BE', 'NL', 'FR', 'IT', 'PL', 'CZ');
}

/**
 * EINE Wertpruefung fuer Formular und Sicherung (C1, O2; Durchgang 30.09.2026).
 *
 * Abgewiesen wird, nie zurechtgebogen: 100.4 wird nicht zu 100, "de-DE" nicht
 * zu "dede", "DE BY!" nicht zu "DEBY". Zahlen kommen als Zahl oder als
 * Ziffernfolge (Formularfelder sind Text); true, eine Kommazahl oder eine
 * Liste sind keine Zahl. Unterfelder heissen "tts.port", "notify.time".
 *
 * Rueckgabe: array(ok, Wert in gespeicherter Form, Grund als Text).
 */
function fer_wert_pruefen($k, $w)
{
    $typ = function ($w) {
        if (is_array($w)) { return fer_t('MELD.TYP_LISTE'); }
        if (is_bool($w)) { return fer_t('MELD.TYP_WAHR'); }
        if ($w === null) { return fer_t('MELD.TYP_NULL'); }
        if (is_float($w)) { return fer_t('MELD.TYP_KOMMA'); }
        return fer_t('MELD.TYP_TEXT');
    };
    $zeig = function ($w) {
        return is_scalar($w) && !is_bool($w) ? '"' . substr((string) $w, 0, 60) . '"' : '';
    };
    $zahl = function ($w, $min, $max) use ($typ, $zeig) {
        if (is_int($w)) {
            $n = $w;
        } elseif (is_string($w) && preg_match('/^\d{1,9}\z/', trim($w))) {
            $n = (int) trim($w);
        } else {
            return array(false, null, sprintf(fer_t('MELD.W_ZAHL'), trim($zeig($w) . ' ' . (is_string($w) || is_int($w) ? '' : '(' . $typ($w) . ')')), $min, $max));
        }
        if ($n < $min || $n > $max) {
            return array(false, null, sprintf(fer_t('MELD.W_BEREICH'), $n, $min, $max));
        }
        return array(true, $n, '');
    };
    $text = function ($w, $muster, $form, $leer_ok) use ($typ, $zeig) {
        if (!is_string($w)) {
            return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
        }
        $v = trim($w);
        if ($v === '' && $leer_ok) { return array(true, '', ''); }
        if (!preg_match($muster, $v)) {
            return array(false, null, sprintf(fer_t('MELD.W_FORM'), $zeig($w), $form));
        }
        return array(true, $v, '');
    };
    $auswahl = function ($w, $liste) use ($typ, $zeig) {
        if (!is_string($w)) {
            return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
        }
        if (!in_array($w, $liste, true)) {
            return array(false, null, sprintf(fer_t('MELD.W_AUSWAHL'), $zeig($w), implode(', ', array_map(function ($x) { return $x === '' ? fer_t('MELD.LEER') : $x; }, $liste))));
        }
        return array(true, $w, '');
    };
    $region = '/^[A-Z]{2}(?:-[A-Z0-9]{1,10}){1,3}\z/';
    $ohne_steuer = '/^[^\x00-\x1F\x7F]*\z/u';
    switch ($k) {
        case 'country':
            return $auswahl($w, fer_laender());
        case 'subdivision':
        case 'subdivision2':
        case 'group':
            return $text($w, $region, fer_t('MELD.FORM_REGION'), true);
        case 'lang':
            return $auswahl($w, array('DE', 'EN', 'FR', 'IT', 'NL', 'PL', 'CS'));
        case 'school': case 'public': case 'local_holidays': case 'bridge':
        case 'typ_streng': case 'halbtag_frei': case 'mqtt_enabled': case 'befehle_mqtt_ein':
        case 'endpunkt_ein': case 'haus_kalender_ein': case 'ics_jaehrlich':
        case 'notify.audio': case 'notify.push': case 'notify.freetag':
        case 'notify.ferienstart': case 'notify.bridge_month':
            return $zahl($w, 0, 1);
        case 'locality':
            return $auswahl($w, array('', 'DE-BY-AU', 'BY-EV', 'SN-KATH', 'TH-KATH'));
        case 'bridge_mode':
            return $auswahl($w, array('klassisch', 'erweitert'));
        case 'bridge_luecke':
            return $zahl($w, 1, 4);
        case 'urlaub_vorlauf':
            return $zahl($w, 0, 14);
        case 'ics_typ':
            return $auswahl($w, array('ferien', 'feiertag', 'urlaub'));
        case 'ics_url':
            return $text($w, '#^https?://[^\s\x00-\x1F\x7F]{1,2040}\z#i', fer_t('MELD.FORM_URL'), true);
        case 'ics_filter':
            $r = $text($w, $ohne_steuer, fer_t('MELD.FORM_TEXT'), true);
            if ($r[0] && strlen($r[1]) > 100) { return array(false, null, sprintf(fer_t('MELD.W_LAENGE'), 100)); }
            return $r;
        case 'mqtt_topic':
            return $text($w, '#^[A-Za-z0-9_\-]{1,64}(?:/[A-Za-z0-9_\-]{1,64}){0,4}\z#', fer_t('MELD.FORM_THEMA'), false);
        case 'aktionstoken':
            if (!is_string($w)) {
                return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
            }
            if ($w === 'Array' || !preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $w)) {
                return array(false, null, sprintf(fer_t('MELD.W_FORM'), '', fer_t('MELD.FORM_TOKEN')));
            }
            return array(true, $w, '');
        case 'notify.time':
            $r = $text($w, '/^([01]?\d|2[0-3]):[0-5]\d\z/', 'SS:MM (00:00-23:59)', false);
            return $r;
        case 'tts.mode': case 'tts.ip': case 'tts.port': case 'tts.zones': case 'tts.volume':
        case 'tts.lang': case 'tts.template': case 'tts.alexa_geraet': case 'tts.alexa_laut':
        case 'tts.google_geraet': case 'tts.google_laut': case 'tts.sonos_zone': case 'tts.sonos_laut':
        case 'tts.sonox_raum': case 'tts.sonox_laut': case 'tts.sonox_sprache':
            /* Nr. 36 b, Stufe 2: dieselbe Pruefung wie beim Speichern und vor dem Senden
             * (ansage_wert_pruefen(): Ausgabeart aus fer_ansage_modi(), Adresse und Vorlage
             * im Heimnetz, Zonen, Lautstaerke 1-100). Der Grund nennt nie den Wert. Die
             * Sprechtoken kommen hier nie an: fer_sicherung_lesen() weist sie vorher ab. */
            $ag = '';
            $s = substr($k, 4);
            $p = ansage_wert_pruefen(array($s => $w), $ag, fer_ansage_modi());
            if ($p === null) {
                $ag = (string) preg_replace('/^UNTER\|[^|]*\|/', '', $ag);
                return array(false, null, ansage_kennung_text($ag, fer_ansage_k()));
            }
            return array(true, $p[$s], '');
        case 'notify':
        case 'tts':
            if (!is_array($w) || ($w !== array() && array_keys($w) === range(0, count($w) - 1))) {
                return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
            }
            $soll = $k === 'notify'
                ? array('audio', 'push', 'time', 'freetag', 'ferienstart', 'bridge_month')
                // Nr. 36 b, Stufe 2: die Schluessel des Moduls ohne die Sprechtoken
                : array_values(array_diff(array_keys(ansage_vorgaben()), ansage_geheim()));
            $aus = array();
            $gruende = array();
            foreach ($w as $uk => $uw) {
                if (!in_array((string) $uk, $soll, true)) {
                    $gruende[] = sprintf(fer_t('TEXT.SICH_FREMD'), $k . '.' . $uk);
                    continue;
                }
                list($ok, $v, $g) = fer_wert_pruefen($k . '.' . $uk, $uw);
                if (!$ok) { $gruende[] = $k . '.' . $uk . ': ' . $g; continue; }
                $aus[$uk] = $v;
            }
            if ($gruende) { return array(false, null, implode('; ', $gruende)); }
            return array(true, $aus, '');
        case 'own':
            if (!is_array($w) || ($w !== array() && array_keys($w) !== range(0, count($w) - 1))) {
                return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
            }
            if (count($w) > 6) { return array(false, null, sprintf(fer_t('MELD.W_OWN_ZAHL'), count($w))); }
            $aus = array();
            foreach ($w as $i => $o) {
                if (!is_array($o)) { return array(false, null, sprintf(fer_t('MELD.W_OWN_ZEILE'), $i + 1, $typ($o))); }
                foreach ($o as $ok_ => $ov) {
                    if (!in_array((string) $ok_, array('name', 'von', 'bis', 'typ'), true)) {
                        return array(false, null, sprintf(fer_t('MELD.W_OWN_FELD'), $i + 1, (string) $ok_));
                    }
                }
                $name = isset($o['name']) ? $o['name'] : '';
                $von = isset($o['von']) ? $o['von'] : null;
                $bis = isset($o['bis']) && $o['bis'] !== '' ? $o['bis'] : $von;
                $t = isset($o['typ']) ? $o['typ'] : 'ferien';
                if (!is_string($name) || strlen($name) > 100 || !preg_match($ohne_steuer, $name)
                    || fer_tag_norm($von) === null || !is_string($von) || fer_tag_norm($von) !== $von
                    || !is_string($bis) || fer_tag_norm($bis) !== $bis || $bis < $von
                    || !in_array($t, array('ferien', 'feiertag', 'urlaub'), true)) {
                    return array(false, null, sprintf(fer_t('MELD.W_OWN_WERT'), $i + 1));
                }
                $aus[] = array('name' => trim($name), 'von' => $von, 'bis' => $bis, 'typ' => $t);
            }
            return array(true, $aus, '');
    }
    return array(false, null, fer_t('MELD.W_UNBEKANNT'));
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function fer_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = fer_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. Seit 1.2.16 ueber
     * fer_datei_schreiben() (Nebendatei mit Prozessnummer; bis dahin setzte
     * dieser Weg die Rechte erst NACH dem Inhalt, C10). */
    fer_datei_schreiben($datei, $neu, 0600);
    $wort = $neu;
    return $wort;
}

function fer_formtoken()
{
    $grund = fer_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function fer_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(fer_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function fer_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = fer_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return fer_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return fer_t('WACHE.FALSCH');
    }
    return '';
}

/* Der Escape-Helfer gehoert in die Bibliothek, nicht in
 * index.php: sonst steht er dem Endpunkt und jedem weiteren
 * Aufrufer nicht zur Verfuegung (Hausform, REGELN_2). */
function fe_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }


/* ==================================================================
 * LESER DIESES PLUGINS (Ferien-b1 + Ferien-1, Verbesserungsbau 01.10.2026)
 * ==================================================================
 *
 * Der Reiter Test zeigt, welche anderen Plugins Ferien lesen, auf welchem
 * Weg, wann zuletzt und mit welchem Ergebnis. Dazu vermerkt das Plugin jeden
 * lesenden Aufruf:
 *   - am Endpunkt (Zeile, ?json=1, ?debug=1) NACH der Antwort, erkannt am
 *     Parameter quelle=, am Referer oder an der Kennung (User-Agent);
 *   - beim Einbinden der Bibliothek durch ein fremdes Plugin (fer_data(),
 *     fer_state()), erkannt am Ordner des aufrufenden Skripts.
 * Vermerkt werden Name, Weg, Zeitpunkt, Ergebnis und Zahl der Aufrufe - nie
 * ein Token, eine Adresse oder eine Anfragezeile. Ein quelle=, das dem
 * Aktionstoken gleicht, gilt als nicht erkannt; eine Kennung, die das Token
 * enthaelt, wird nicht vermerkt.
 *
 * Belegt im Quelltext der Leser (nur gelesen, 01.10.2026):
 *   AWM-Abfuhr 1.4.17, awm_daytype(): bindet zuerst die Bibliothek ein
 *     (fer_data(), fer_day()), sonst ?json=1 mit dem User-Agent
 *     "LoxBerry Abfuhrkalender" (awm_http_kopf()).
 *   Abfahrts-Assistent 1.6.19, abfahrt_daytype(): zuerst ?json=1 mit dem
 *     User-Agent "LoxBerry Abfahrts-Assistent", sonst die Bibliothek
 *     (fer_state()).
 * Keiner der beiden schickt einen Referer oder quelle=. Die Bewaesserung
 * (0.9.37) liest Ferien nicht - ihr Quelltext verweist nirgends auf dieses
 * Plugin.
 *
 * Das Vermerken darf das Antwortverhalten fuer keinen Leser aendern: keine
 * Ausgabe, keine Kopfzeile, kein anderer Rueckgabewert, kein neuer Ordner,
 * keine Protokollzeile. Geschrieben wird nur in den vorhandenen
 * Zwischenordner, und nur, wenn er dem eigenen Benutzer gehoert und
 * beschreibbar ist (fer_tmp_taugt()); sonst wird nichts vermerkt. Jeder
 * Fehler endet still.
 * ================================================================== */

/** Die im Quelltext belegten Leser: Ordner => Titel und Kennung (User-Agent). */
function fer_leser_bekannt()
{
    return array(
        'awmabfuhr' => array('titel' => 'AWM-Abfuhr', 'ua' => 'LoxBerry Abfuhrkalender'),
        'abfahrtsassistent' => array('titel' => 'Abfahrts-Assistent', 'ua' => 'LoxBerry Abfahrts-Assistent'),
    );
}

/** Der eigene Ordnername (installiert: ferien). */
function fer_leser_eigen()
{
    $n = basename(__DIR__);
    return ($n === 'html' || $n === '') ? 'ferien' : $n;
}

/** Der Ordner, in dem vermerkt wird, oder '' (dann wird nichts vermerkt).
 *  Bewusst NICHT fer_tmpdir(): das legt Ordner an und weicht aus - ein
 *  fremder Prozess soll hier nichts anlegen und nichts protokollieren. */
function fer_leser_ordner()
{
    $pf = fer_paths();
    $t = isset($pf['tmp']) ? (string) $pf['tmp'] : '';
    if ($t === '' || !fer_tmp_taugt($t)) { return ''; }
    return $t;
}

/** Der Plugin-Ordner aus einem Skriptpfad (.../plugins/<ordner>/...), sonst ''. */
function fer_leser_aus_pfad($pfad)
{
    $pfad = str_replace('\\', '/', (string) $pfad);
    if (preg_match('#/(?:webfrontend/html|webfrontend/htmlauth|bin)/plugins/([A-Za-z0-9_.\-]{1,40})/#', $pfad, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/** Ein kurzer Anzeigetext ohne Steuer- und Sonderzeichen; enthaelt der
 *  Ausgangstext das Token, bleibt er leer. */
function fer_leser_text($s, $max, $tok)
{
    $s = (string) $s;
    if ($tok !== '' && strpos($s, $tok) !== false) { return ''; }
    $s = preg_replace('/[^A-Za-z0-9 .\/_()+;:,\-]/', '', substr($s, 0, 200));
    return substr((string) $s, 0, $max);
}

/**
 * Einen Abruf vermerken. $weg: zeile | json | bibliothek. $ergebnis: der
 * HTTP-Status oder 'daten' / 'keine_daten'. Rueckgabe: true, wenn vermerkt.
 * Unter einer Sperre (nicht blockierend, fuenf Versuche), Nebendatei mit
 * Prozessnummer, Rechte vor dem Inhalt, Laengenvergleich, rename - still.
 */
function fer_leser_merken($name, $weg, $ergebnis, $erkannt, $hinweis = '')
{
    $ord = fer_leser_ordner();
    if ($ord === '') { return false; }
    $f = $ord . '/leser.json';
    $fh = @fopen($ord . '/leser.lock', 'c');
    if ($fh === false) { return false; }
    $gesperrt = false;
    for ($i = 0; $i < 5; $i++) {
        if (@flock($fh, LOCK_EX | LOCK_NB)) { $gesperrt = true; break; }
        usleep(20000);
    }
    if (!$gesperrt) { @fclose($fh); return false; }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d) || !isset($d['leser']) || !is_array($d['leser'])) { $d = array('leser' => array()); }
    $k = $name . '|' . $weg;
    $alt = (isset($d['leser'][$k]) && is_array($d['leser'][$k])) ? $d['leser'][$k] : array();
    $jetzt = time();
    $d['leser'][$k] = array(
        'name' => (string) $name, 'weg' => (string) $weg, 'zeit' => $jetzt,
        'ergebnis' => (string) $ergebnis, 'erkannt' => (string) $erkannt, 'hinweis' => (string) $hinweis,
        'anzahl' => (isset($alt['anzahl']) ? (int) $alt['anzahl'] : 0) + 1,
        'seit' => isset($alt['seit']) ? (int) $alt['seit'] : $jetzt,
    );
    if (count($d['leser']) > 16) {
        uasort($d['leser'], function ($a, $b) {
            return (int) (isset($b['zeit']) ? $b['zeit'] : 0) - (int) (isset($a['zeit']) ? $a['zeit'] : 0);
        });
        $d['leser'] = array_slice($d['leser'], 0, 16, true);
    }
    $js = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = false;
    if ($js !== false) {
        $tmp = $f . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
        if (@file_put_contents($tmp, '') !== false) {
            @chmod($tmp, 0600);
            $ok = (@file_put_contents($tmp, $js) === strlen($js)) && @rename($tmp, $f);
            if (!$ok) { @unlink($tmp); }
        }
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return $ok;
}

/** fer_data()/fer_state() in einem FREMDEN Prozess: einmal je Prozess vermerken. Der
 *  eigene Endpunkt, der Cron und die Oberflaeche liegen unter
 *  .../plugins/<eigener Ordner>/ und werden uebergangen. */
function fer_leser_bibliothek($d)
{
    static $fertig = false;
    if ($fertig) { return; }
    $fertig = true;
    try {
        $skript = (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME']))
            ? $_SERVER['SCRIPT_FILENAME'] : '';
        if ($skript !== '') {
            $echt = @realpath($skript);
            if ($echt !== false) { $skript = $echt; }
        } else {
            $inc = get_included_files();
            $skript = isset($inc[0]) ? (string) $inc[0] : '';
        }
        $name = fer_leser_aus_pfad($skript);
        if ($name === fer_leser_eigen() || $name === 'ferien') { return; }
        $erkannt = 'skript';
        $hinweis = '';
        if ($name === '') {
            $name = 'unbekannt';
            $erkannt = '';
            $hinweis = fer_leser_text(basename(str_replace('\\', '/', $skript)), 40, '');
        }
        fer_leser_merken($name, 'bibliothek', (is_array($d) && !empty($d['quelle_da'])) ? 'daten' : 'keine_daten',
            $erkannt, $hinweis);
    } catch (\Throwable $e) {
        return;
    }
}

/** Ein lesender Aufruf des Endpunkts - aus der Abschaltfunktion von
 *  ferien.php, also nach der Antwort. $weg: zeile | json. */
function fer_leser_http($weg)
{
    try {
        $ua = (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']))
            ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua === 'LoxBerry Ferien-Plugin') { return; }      // die eigene Selbstpruefung
        $cfg = fer_config();
        $tok = (isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken'])) ? $cfg['aktionstoken'] : '';
        $name = '';
        $erkannt = '';
        $hinweis = '';
        $q = (isset($_GET['quelle']) && is_string($_GET['quelle'])) ? trim($_GET['quelle']) : '';
        if ($q !== '' && preg_match('/^[A-Za-z0-9_.\-]{1,40}\z/', $q) && ($tok === '' || !hash_equals($tok, $q))) {
            $name = strtolower($q);
            $erkannt = 'quelle';
        }
        if ($name === '' && isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
            $rpfad = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
            if (is_string($rpfad) && preg_match('#/plugins/([A-Za-z0-9_.\-]{1,40})/#', $rpfad, $m)) {
                $name = strtolower($m[1]);
                $erkannt = 'referer';
            }
        }
        if ($name === '' && $ua !== '') {
            foreach (fer_leser_bekannt() as $ordner => $b) {
                if (strpos($ua, $b['ua']) === 0) { $name = $ordner; $erkannt = 'ua'; break; }
            }
        }
        if ($name === fer_leser_eigen() || $name === 'ferien') { return; }
        if ($name === '') {
            $name = 'unbekannt';
            $hinweis = fer_leser_text($ua, 40, $tok);
        }
        $code = http_response_code();
        fer_leser_merken($name, $weg, is_int($code) ? $code : 0, $erkannt, $hinweis);
    } catch (\Throwable $e) {
        return;
    }
}

/** Fuer den Reiter Test: die belegten Leser (mit "installiert?") und alle
 *  vermerkten, der juengste Abruf zuerst. */
function fer_leser_liste()
{
    $pf = fer_paths();
    $ord = fer_leser_ordner();
    $eintraege = array();
    if ($ord !== '' && is_file($ord . '/leser.json')) {
        $d = json_decode((string) @file_get_contents($ord . '/leser.json'), true);
        if (is_array($d) && isset($d['leser']) && is_array($d['leser'])) {
            foreach ($d['leser'] as $e) {
                if (!is_array($e) || !isset($e['name'], $e['weg'], $e['zeit']) || !is_string($e['name'])
                    || !is_string($e['weg']) || !preg_match('/^[a-z0-9_.\-]{1,40}\z/', $e['name'])) {
                    continue;
                }
                $e += array('ergebnis' => '', 'erkannt' => '', 'hinweis' => '', 'anzahl' => 0);
                foreach (array('ergebnis', 'erkannt', 'hinweis') as $sk) {
                    $e[$sk] = is_scalar($e[$sk]) ? (string) $e[$sk] : '';
                }
                $e['zeit'] = (int) $e['zeit'];
                $e['anzahl'] = (int) $e['anzahl'];
                $eintraege[] = $e;
            }
        }
    }
    usort($eintraege, function ($a, $b) { return $b['zeit'] - $a['zeit']; });
    $lb = isset($pf['lbhome']) ? (string) $pf['lbhome'] : '';
    $zeilen = array();
    $bekannt = fer_leser_bekannt();
    foreach ($bekannt as $ordner => $b) {
        $inst = ($lb !== '') ? is_dir($lb . '/webfrontend/html/plugins/' . $ordner) : null;
        $n = 0;
        foreach ($eintraege as $e) {
            if ($e['name'] !== $ordner) { continue; }
            $zeilen[] = array('titel' => $b['titel'], 'installiert' => $inst, 'eintrag' => $e);
            $n++;
        }
        if ($n === 0) { $zeilen[] = array('titel' => $b['titel'], 'installiert' => $inst, 'eintrag' => null); }
    }
    foreach ($eintraege as $e) {
        if (isset($bekannt[$e['name']])) { continue; }
        /* '' = trifft nicht zu (ein nicht erkannter Leser hat keinen Ordner). */
        $inst = $e['name'] === 'unbekannt' ? ''
            : (($lb !== '') ? is_dir($lb . '/webfrontend/html/plugins/' . $e['name']) : null);
        $zeilen[] = array('titel' => $e['name'], 'installiert' => $inst, 'eintrag' => $e);
    }
    return array('ordner' => $ord !== '' ? $ord : (string) (isset($pf['tmp']) ? $pf['tmp'] : ''),
                 'ordner_ok' => $ord !== '', 'zeilen' => $zeilen);
}


/* ==================================================================
 * PROBE GEGEN DIE QUELLE (Ferien-a1, Verbesserungsbau 01.10.2026)
 * ==================================================================
 *
 * Ein Knopf im Reiter Test fragt die Quelle EINMAL fuer Bayern (DE-BY) und
 * zeigt die Rohantwort gekuerzt. Anlass: Ferienende an Freitagen (C11) und
 * die Gemeindecodes der oertlichen Feiertage waren im Durchgang nur an einer
 * Attrappe gemessen. Die Probe schreibt NICHT in die Termindatei und nicht in
 * state.json; sie legt nur ihr Ergebnis im Zwischenordner ab
 * (quellprobe.json, 0600) und schreibt eine Protokollzeile. Hoechstens eine
 * Probe je Minute; eine juengere wird gezeigt statt neu gefragt.
 * ================================================================== */

/** Die Datei mit dem Ergebnis der letzten Probe. */
function fer_quellprobe_datei() { return fer_tmpdir() . '/quellprobe.json'; }

/** Eine Zeichenkette auf hoechstens $n Byte kuerzen, ohne ein UTF-8-Zeichen zu
 *  zerschneiden. */
function fer_kurz_utf8($s, $n)
{
    $s = (string) $s;
    if (strlen($s) <= $n) { return $s; }
    $k = substr($s, 0, $n);
    for ($i = 0; $i < 4 && !preg_match('//u', $k); $i++) { $k = substr($k, 0, -1); }
    return $k;
}

/** Die letzte Probe lesen und jeden Wert auf seinen Typ bringen - oder null. */
function fer_quellprobe_lesen()
{
    $f = fer_quellprobe_datei();
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['zeit'], $d['teile']) || !is_array($d['teile'])) { return null; }
    $teile = array();
    foreach ($d['teile'] as $t) {
        if (!is_array($t) || !isset($t['endpunkt']) || !is_string($t['endpunkt'])) { continue; }
        $e = array();
        foreach ((isset($t['eintraege']) && is_array($t['eintraege'])) ? $t['eintraege'] : array() as $x) {
            if (!is_array($x)) { continue; }
            $z = array();
            foreach (array('von', 'bis', 'name', 'bereich', 'codes', 'art') as $sk) {
                $z[$sk] = (isset($x[$sk]) && is_scalar($x[$sk])) ? (string) $x[$sk] : '';
            }
            $z['wt_bis'] = isset($x['wt_bis']) ? (int) $x['wt_bis'] : 0;
            $e[] = $z;
        }
        $teile[] = array(
            'endpunkt' => $t['endpunkt'],
            'url' => (isset($t['url']) && is_string($t['url'])) ? $t['url'] : '',
            'status' => isset($t['status']) ? (int) $t['status'] : 0,
            'ms' => isset($t['ms']) ? (int) $t['ms'] : 0,
            'bytes' => isset($t['bytes']) ? (int) $t['bytes'] : 0,
            'ok' => !empty($t['ok']) ? 1 : 0,
            'anzahl' => isset($t['anzahl']) ? (int) $t['anzahl'] : -1,
            'roh' => (isset($t['roh']) && is_string($t['roh'])) ? $t['roh'] : '',
            'eintraege' => $e,
        );
    }
    return array('zeit' => (int) $d['zeit'],
                 'region' => (isset($d['region']) && is_string($d['region'])) ? $d['region'] : '', 'teile' => $teile);
}

/**
 * Die Probe. Rueckgabe: array(Art, Ergebnis). Art: 'ok' (beide Teile
 * beantwortet), 'teilweise', 'fehl', 'gebremst' (die letzte Probe ist keine
 * Minute alt; Ergebnis ist dann sie).
 */
function fer_quellprobe()
{
    $alt = fer_quellprobe_lesen();
    if ($alt !== null) {
        $seit = time() - $alt['zeit'];
        if ($seit >= 0 && $seit < 60) { return array('gebremst', $alt); }
    }
    $jahr = (int) date('Y');
    $q = 'countryIsoCode=DE&languageIsoCode=DE&subdivisionCode=DE-BY&validFrom=' . $jahr
       . '-01-01&validTo=' . ($jahr + 1) . '-12-31';
    $teile = array();
    $gut = 0;
    $zeile = array();
    foreach (array('SchoolHolidays', 'PublicHolidays') as $ep) {
        $url = 'https://openholidaysapi.org/' . $ep . '?' . $q;
        $t0 = microtime(true);
        list($code, $roh) = fer_http_status($url, 15);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $roh = (string) $roh;
        $js = json_decode($roh, true);
        $liste = (is_array($js) && ($js === array() || array_keys($js) === range(0, count($js) - 1))) ? $js : null;
        $ok = ((int) $code === 200 && $liste !== null);
        if ($ok) { $gut++; }
        $ausw = array();
        foreach ($liste !== null ? $liste : array() as $e) {
            if (!is_array($e) || !isset($e['startDate']) || !is_string($e['startDate'])) { continue; }
            $von = substr($e['startDate'], 0, 10);
            $bis = substr((isset($e['endDate']) && is_string($e['endDate'])) ? $e['endDate'] : $e['startDate'], 0, 10);
            $ts = strtotime($bis);
            $codes = array();
            foreach ((isset($e['subdivisions']) && is_array($e['subdivisions'])) ? $e['subdivisions'] : array() as $sd) {
                if (is_array($sd) && isset($sd['code']) && is_string($sd['code'])) { $codes[] = $sd['code']; }
            }
            $ausw[] = array(
                'von' => $von, 'bis' => $bis, 'wt_bis' => $ts !== false ? (int) date('N', $ts) : 0,
                'name' => fer_kurz_utf8(fer_name($e, 'DE'), 80),
                'bereich' => (isset($e['regionalScope']) && is_string($e['regionalScope'])) ? $e['regionalScope'] : '',
                'codes' => fer_kurz_utf8(implode(', ', array_slice($codes, 0, 12)), 200),
                'art' => (isset($e['type']) && is_string($e['type'])) ? $e['type'] : '',
            );
        }
        $teile[] = array('endpunkt' => $ep, 'url' => $url, 'status' => (int) $code, 'ms' => $ms,
                         'bytes' => strlen($roh), 'ok' => $ok ? 1 : 0,
                         'anzahl' => $liste === null ? -1 : count($liste),
                         'roh' => fer_kurz_utf8($roh, 1200), 'eintraege' => array_slice($ausw, 0, 60));
        $zeile[] = $ep . ' HTTP ' . (int) $code . ($liste !== null ? ' (' . count($liste) . ' Eintraege)' : ' (kein JSON)');
    }
    $erg = array('zeit' => time(), 'region' => 'DE-BY', 'teile' => $teile);
    fer_json_schreiben(fer_quellprobe_datei(), $erg, 0600);
    fer_log('Probe gegen die Quelle (DE-BY, Reiter Test): ' . implode(', ', $zeile)
        . ' - die Termindatei bleibt unveraendert.');
    return array($gut === 2 ? 'ok' : ($gut === 1 ? 'teilweise' : 'fehl'), fer_quellprobe_lesen());
}

/* ==================================================================
 * Gruppe 19 (1.2.28): Sicherung mit Spaetschluesseln (N1), Ansichten (N2), jaehrliche Kalendertermine (V4),
 * Anbieter haus/kalender/* (V1) - Entwurf entwurf_gruppe19_fe.txt, Entscheidungen G19 1-7, E-E1-E-E20.
 * ================================================================== */

/**
 * Schluessel, die eine aeltere Sicherung nicht tragen kann (N1, E-E14): befehle_mqtt_ein seit 1.2.27, endpunkt_ein,
 * haus_kalender_ein und ics_jaehrlich seit 1.2.28. Fehlen sie in einer Sicherung, gilt der GELTENDE Wert (sonst die
 * Vorgabe), mit Hinweis - eine Sicherung aus 1.2.26/1.2.27 bleibt zurueckspielbar und schaltet nichts still um
 * (endpunkt_ein einer Bestandsanlage bliebe sonst nicht 1). Bauart awm_spaet_schluessel() (Gruppe 18 N2/N3).
 */
function fer_spaet_schluessel()
{
    return array('befehle_mqtt_ein', 'endpunkt_ein', 'haus_kalender_ein', 'ics_jaehrlich');
}

/**
 * Die lesenden Ansichten Zeile, JSON und Debug (N2, E-E8) - EINE Stelle fuer ferien.php (nach Sperre und Tokenteil)
 * und die Oberflaeche (index.php?ansicht=..., hinter der LoxBerry-Anmeldung, unabhaengig von endpunkt_ein). Die Ausgabe
 * ist byte-gleich mit ferien.php bis 1.2.27 (Vorher/Nachher-Probe im Bau). Rueckgabe array(HTTP-Code, Content-Type,
 * Text). $refresh/$quelle nur fuer ferien.php?refresh=1&debug=1.
 */
function fer_ansicht($art, $refresh = false, $quelle = '-')
{
    $klar = function ($w) { return is_scalar($w) ? (string) $w : '?'; };
    if ($art === 'json') {
        $st = fer_state($refresh);
        /* 1.2.16: ohne Daten der Quelle kein Zustand mit erfundenen Werten. */
        if (empty($st['quelle_da'])) {
            return array(503, 'application/json; charset=utf-8',
                json_encode(array('ok' => 0, 'grund' => 'KEINE_DATEN'), JSON_PRETTY_PRINT) . "\n");
        }
        $fl = fer_meldeflags($st);
        $st['ann'] = $fl['ann'];
        $st['ptest'] = fer_ptest_active();
        return array(200, 'application/json; charset=utf-8',
            json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    $st = fer_state($refresh);
    $cfg = fer_config();
    $flags = fer_meldeflags($st);
    $ohne = empty($st['quelle_da']);
    $o = '';
    if ($art === 'debug') {
        $d = fer_data();
        $o .= 'DEBUG  Region: ' . $klar($cfg['country']) . '/' . $klar($cfg['subdivision'])
           . '  Abruf: ' . $quelle
           . '  Stand: ' . substr((string) $st['stand'], 0, 19)
           . '  zuletzt gelungen: ' . ($st['stand_ok'] !== '' ? substr((string) $st['stand_ok'], 0, 19) : '-')
           . ' (' . (int) $st['alter_tage'] . ' Tage)'
           . (!empty($st['teilausfall']) ? '  Teilausfall: ' . implode(', ', (array) $st['teilausfall']) : '')
           . '  Daten bis: ' . $st['reicht_bis'] . "\n";
        $o .= 'HEUTE  ' . $st['heute']['datum'] . ': schulfrei=' . $st['heute']['schulfrei']
           . ' Ferien=' . ($st['heute']['ferien_name'] !== '' ? $st['heute']['ferien_name'] : '-')
           . ' Feiertag=' . ($st['heute']['feiertag_name'] !== '' ? $st['heute']['feiertag_name'] : '-')
           . ' Brueckentag=' . $st['heute']['bruecke'] . "\n";
        $o .= 'MORGEN ' . $st['morgen']['datum'] . ': schulfrei=' . $st['morgen']['schulfrei']
           . ' Ferien=' . ($st['morgen']['ferien_name'] !== '' ? $st['morgen']['ferien_name'] : '-')
           . ' Feiertag=' . ($st['morgen']['feiertag_name'] !== '' ? $st['morgen']['feiertag_name'] : '-') . "\n\n";
        $o .= "Kommende Ferien:\n";
        foreach ((array) $d['ferien'] as $e) {
            if ($e['bis'] < date('Y-m-d')) { continue; }
            $o .= sprintf("  %s bis %s  %s%s%s\n", $e['von'], $e['bis'], $e['name'],
                !empty($e['ics']) ? '  (aus dem Kalender)' : (!empty($e['eigen']) ? '  (eigener Termin)' : ''),
                (isset($e['art']) && $e['art'] !== '' && $e['art'] !== 'School') ? '  [Art: ' . $e['art'] . ']' : '');
        }
        if (!empty($d['ferien2'])) {
            $o .= "\nKommende Ferien der zweiten Region (" . $cfg['subdivision2'] . "):\n";
            foreach ((array) $d['ferien2'] as $e) {
                if ($e['bis'] < date('Y-m-d')) { continue; }
                $o .= sprintf("  %s bis %s  %s\n", $e['von'], $e['bis'], $e['name']);
            }
        }
        $o .= "\nKommende Feiertage:\n";
        foreach ((array) $d['feiertage'] as $e) {
            if ($e['bis'] < date('Y-m-d')) { continue; }
            $o .= sprintf("  %s  %s%s%s%s\n", $e['von'], $e['name'],
                !empty($e['eigen']) ? '  (eigener Termin)' : '',
                (isset($e['art']) && $e['art'] !== '' && $e['art'] !== 'Public') ? '  [Art: ' . $e['art'] . ']' : '',
                !empty($e['halbtag']) ? ('  [halber Tag' . (!empty($e['hinweis']) ? ': ' . $e['hinweis'] : '') . ']') : '');
        }
        $o .= "\nUrlaub (Abwesenheit):\n";
        if (empty($d['urlaub'])) {
            $o .= "  keine Urlaubszeitraeume eingetragen\n";
        } else {
            foreach ((array) $d['urlaub'] as $e) {
                if ($e['bis'] < date('Y-m-d')) { continue; }
                $o .= sprintf("  %s bis %s  %s\n", $e['von'], $e['bis'], $e['name']);
            }
            $o .= sprintf("  aktiv=%d in=%d rest=%d letzter Tag=%d\n", $st['urlaub']['aktiv'],
                $st['urlaub']['in'], $st['urlaub']['rest'], $st['urlaub']['letzter_tag']);
        }
        if ($st['brueckentage']) {
            $o .= "\nBrueckentage der naechsten 12 Monate:\n  " . implode(', ', $st['brueckentage']) . "\n";
        }
        $o .= "\n";
    }
    /* 1.2.16 (C5): ohne Daten der Quelle 503 und nur die Kennung - keine geratenen Werte. */
    $o .= $ohne ? "FERIEN;OK=0;GRUND=KEINE_DATEN\n" : fer_zeile($st, $flags);
    return array($ohne ? 503 : 200, 'text/plain; charset=utf-8', $o);
}

/**
 * V4 (Option E, Hausherr 25.10.2026): Beginntage einer jaehrlichen Regel im Fenster [heute - 366 Tage, heute + 400 Tage].
 * Nur FREQ=YEARLY mit hoechstens INTERVAL=1, UNTIL, COUNT, WKST; jeder andere Teil (BYMONTH, BYDAY, BYSETPOS, INTERVAL
 * <> 1 ...) und EXDATE ausser in der DATE-Form -> null: der Termin wird wie bisher uebersprungen (keine halbe
 * Wiederholungsrechnung, Grundsatz aus fer_ics_lesen()). 29.02. nur in Schaltjahren. kalender.json wird alle 6 h neu
 * gerechnet (FER_ICS_TAKT) - das Fenster reicht.
 */
function fer_ics_jaehrlich($regel, $von, $b, $heute)
{
    $teile = array();
    foreach (explode(';', strtoupper(trim((string) $regel))) as $t) {
        if ($t === '') { continue; }
        $kv = explode('=', $t, 2);
        if (count($kv) !== 2) { return null; }
        $teile[$kv[0]] = $kv[1];
    }
    if (!isset($teile['FREQ']) || $teile['FREQ'] !== 'YEARLY') { return null; }
    foreach (array_keys($teile) as $k) {
        if (!in_array($k, array('FREQ', 'INTERVAL', 'UNTIL', 'COUNT', 'WKST'), true)) { return null; }
    }
    if (isset($teile['INTERVAL']) && $teile['INTERVAL'] !== '1') { return null; }
    $bis_regel = null;
    if (isset($teile['UNTIL'])) {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $teile['UNTIL'], $mu)) { return null; }
        $bis_regel = $mu[1] . '-' . $mu[2] . '-' . $mu[3];
    }
    $anzahl = 0;
    if (isset($teile['COUNT'])) {
        if (!preg_match('/^\d{1,4}$/', $teile['COUNT']) || (int) $teile['COUNT'] < 1) { return null; }
        $anzahl = (int) $teile['COUNT'];
    }
    $ausfall = array();
    if (preg_match_all('/\nEXDATE((?:;[^:\n]*)?):([^\n]*)\n/i', $b, $mx, PREG_SET_ORDER)) {
        foreach ($mx as $x) {
            foreach (explode(',', $x[2]) as $d) {
                if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($d), $md)) { return null; }
                $ausfall[$md[1] . '-' . $md[2] . '-' . $md[3]] = true;
            }
        }
    }
    $j0 = (int) substr($von, 0, 4);
    $mt = substr($von, 5);
    $unten = date('Y-m-d', strtotime($heute . ' -366 day'));
    $oben = date('Y-m-d', strtotime($heute . ' +400 day'));
    $aus = array();
    $n = 0;
    for ($j = $j0; $j <= (int) substr($oben, 0, 4); $j++) {
        if (!checkdate((int) substr($mt, 0, 2), (int) substr($mt, 3, 2), $j)) { continue; }
        $d = sprintf('%04d-%s', $j, $mt);
        $n++;
        if ($anzahl && $n > $anzahl) { break; }
        if ($bis_regel !== null && $d > $bis_regel) { break; }
        if (isset($ausfall[$d])) { continue; }
        if ($d < $unten || $d > $oben) { continue; }
        $aus[] = $d;
    }
    return $aus;
}

/**
 * Die Faelle der Selbstpruefung fuer den Kalenderleser (FE11, E2, E3, E-E4, V4; Entwurf 3.5 und 7.3): erfundene Termine,
 * je Fall array(Kennung, ICS-Text, Filter, Haken ics_jaehrlich, Soll). Soll = "von..bis Name" je Termin, mit "|"
 * getrennt, leer = kein Termin. Beidseitig geeicht: gegen fer_ics_lesen() bis 1.2.27 sind K1-K5, K7 und V4c rot.
 */
function fer_ics_selbstfaelle()
{
    $v = function ($rumpf) {
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:probe-g19-1\r\n" . $rumpf . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    };
    return array(
        array('K0', $v("DTSTART;VALUE=DATE:20260803\r\nDTEND;VALUE=DATE:20260806\r\nSUMMARY:Urlaub Probe\r\n"), '', false,
              '2026-08-03..2026-08-05 Urlaub Probe'),
        array('K1', $v("DTSTART;VALUE=DATE:20260810\r\nDTEND;VALUE=DATE:20260812\r\nSTATUS:CANCELLED\r\nSUMMARY:Urlaub abgesagt\r\n"),
              '', false, ''),
        array('K2', $v("DTSTART;VALUE=DATE:20260817\r\nDTEND;VALUE=DATE:20260819\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\n"
              . "SUMMARY:Erinnerung Koffer packen\r\nTRIGGER:-P1D\r\nEND:VALARM\r\nSUMMARY:Urlaub Ostsee\r\n"), '', false,
              '2026-08-17..2026-08-18 Urlaub Ostsee'),
        array('K2f', $v("DTSTART;VALUE=DATE:20260817\r\nDTEND;VALUE=DATE:20260819\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\n"
              . "SUMMARY:Erinnerung Koffer packen\r\nTRIGGER:-P1D\r\nEND:VALARM\r\nSUMMARY:Urlaub Ostsee\r\n"), 'Urlaub', false,
              '2026-08-17..2026-08-18 Urlaub Ostsee'),
        array('K3', $v("DTSTART:20260824\r\nDTEND:20260825\r\nSUMMARY:Brueckentag frei\r\n"), '', false,
              '2026-08-24..2026-08-24 Brueckentag frei'),
        array('K4', $v("DTSTART;VALUE=DATE:20260901\r\nDTEND;VALUE=DATE:20260903\r\nSUMMARY:Ausflug\r\n"
              . "DESCRIPTION:kein Serientermin (rrule) - nur einmal\r\n"), '', false, '2026-09-01..2026-09-02 Ausflug'),
        array('K5', $v("DTSTART;VALUE=DATE-TIME:20260907T140000\r\nDTEND;VALUE=DATE-TIME:20260907T150000\r\nSUMMARY:Zahnarzt\r\n"),
              '', false, ''),
        array('K6', $v("DTSTART;VALUE=DATE:20261224\r\nDTEND;VALUE=DATE:20261225\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:Betriebsruhe\r\n"),
              '', false, ''),
        array('K7', $v("DTSTART;VALUE=DATE:20261005\r\nDURATION:P5D\r\nSUMMARY:Herbsturlaub\r\n"), '', false,
              '2026-10-05..2026-10-09 Herbsturlaub'),
        array('K7b', $v("DTSTART;VALUE=DATE:20261102\r\nDURATION:P1W\r\nSUMMARY:Kur\r\n"), '', false,
              '2026-11-02..2026-11-08 Kur'),
        array('V4a', $v("DTSTART;VALUE=DATE:19600312\r\nDTEND;VALUE=DATE:19600313\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:Geburtstag Oma\r\n"),
              '', true, ''),
        array('V4b', $v("DTSTART;VALUE=DATE:19600312\r\nDTEND;VALUE=DATE:19600313\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:Geburtstag Oma\r\n"),
              'Betriebsruhe', true, ''),
        array('V4c', $v("DTSTART;VALUE=DATE:20241224\r\nDTEND;VALUE=DATE:20241227\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:Betriebsruhe Weihnachten\r\n"),
              'Betriebsruhe', true,
              '2025-12-24..2025-12-26 Betriebsruhe Weihnachten|2026-12-24..2026-12-26 Betriebsruhe Weihnachten'),
        array('V4d', $v("DTSTART;VALUE=DATE:20201224\r\nDTEND;VALUE=DATE:20201225\r\nRRULE:FREQ=YEARLY;UNTIL=20241231\r\n"
              . "SUMMARY:Betriebsruhe alt\r\n"), 'Betriebsruhe', true, ''),
        array('V4e', $v("DTSTART;VALUE=DATE:20260704\r\nDTEND;VALUE=DATE:20260705\r\nRRULE:FREQ=YEARLY;BYMONTH=7;BYDAY=1SA\r\n"
              . "SUMMARY:Betriebsruhe Sommerfest\r\n"), 'Betriebsruhe', true, ''),
        array('V4f', $v("DTSTART;VALUE=DATE:20260930\r\nDTEND;VALUE=DATE:20261001\r\nRRULE:FREQ=YEARLY\r\nEXDATE;VALUE=DATE:20270930\r\n"
              . "SUMMARY:Betriebsruhe Jubilaeum\r\n"), 'Betriebsruhe', true, '2026-09-30..2026-09-30 Betriebsruhe Jubilaeum'),
    );
}

/** Rechnet die Faelle aus fer_ics_selbstfaelle() mit dem festen Tag 25.10.2026. Rueckgabe: Kennungen der Abweichungen. */
function fer_ics_selbstpruefung()
{
    $fehl = array();
    foreach (fer_ics_selbstfaelle() as $f) {
        $ist = array();
        foreach (fer_ics_lesen($f[1], $f[2], $f[3], '2026-10-25') as $t) {
            $ist[] = $t['von'] . '..' . $t['bis'] . ' ' . $t['name'];
        }
        if (implode('|', $ist) !== $f[4]) { $fehl[] = $f[0]; }
    }
    return $fehl;
}

/* ---------- Anbieter haus/kalender/* (V1, Entscheidung 3; Entwurf 2.3-2.7) ----------
 * Ab Werk aus (Haken haus_kalender_ein im Reiter MQTT, wirkt nur mit "Zustand per MQTT veroeffentlichen"). Nur Zahlen,
 * nie Namen. Gesendet per kurzer TCP-Sitzung direkt an den Broker, nicht ueber den UDP-Eingang des Gateways: ein
 * verlorenes Datagramm liesse einen retained Tageswert einen Tag falsch stehen, und das Abraeumen braucht das
 * Ruecklesen ohnehin. Kein mosquitto_pub (kein Kennwort auf einer Befehlszeile), die Befehlsbruecke bleibt unveraendert.
 * Fremde haus-Themen fasst diese Linie nie an; haus/tag/ bleibt den BLE-Tags vorbehalten. */

/** Die eigenen Haus-Themen, Thema => retained (true) / fluechtig (false). Reihenfolge = Sendereihenfolge. EINE Liste
 *  fuer Senden, Abraeumen, Reiter Test und Selbstpruefung. */
function fer_haus_themen()
{
    $t = array('haus/kalender/datum' => true);
    foreach (array('heute', 'morgen') as $tag) {
        foreach (array('feiertag', 'ruhetag', 'frei', 'urlaub') as $w) {
            $t['haus/kalender/' . $tag . '/' . $w] = true;
        }
    }
    $t['haus/kalender/ferien_in'] = true;
    $t['haus/kalender/ferien_rest'] = true;
    $t['haus/kalender/feiertag_in'] = true;
    $t['haus/kalender/zaehler'] = false;
    return $t;
}

/** Die zwoelf retained Themen (ohne den Zaehler) - abgeraeumt werden nur diese. */
function fer_haus_retained()
{
    return array_keys(array_filter(fer_haus_themen()));
}

/** Ist dieser Tagessatz ein gesetzlicher Feiertag im Sinne der Plugin-Einstellung? Ein halber nur, wenn er dort als
 *  frei zaehlt (halbtag_frei); eigene Termine der Art "Feiertag" zaehlen mit. */
function fer_haus_feiertag($tag, $cfg)
{
    if (empty($tag['feiertag'])) { return 0; }
    if (!empty($tag['halbtag']) && empty($cfg['halbtag_frei'])) { return 0; }
    return 1;
}

/**
 * Die Werte der Haus-Themen aus dem Zustand (fer_state()) - Thema => ganze Zahl. ok = 1 (Daten der Quelle decken heute
 * und morgen): volle Aussage. Sonst ist jede Zahl, die an der Quelle haengt, -1 ("ohne Aussage", Nr. 15); urlaub kommt
 * aus eigenen Daten und bleibt 0/1 (E-E6). datum ist immer der Tag der Heute-Werte. ferien_in = FERIENIN (eigene
 * Termine "Ferien"/"Urlaub" zaehlen mit, E-E7), ferien_rest = FERIENREST nur bei laufenden Ferien, feiertag_in =
 * FEIERTAGIN.
 */
function fer_haus_werte($st, $cfg, $zaehler)
{
    $ok = !empty($st['ok']);
    $w = array();
    $w['haus/kalender/datum'] = (int) str_replace('-', '',
        (string) (isset($st['heute']['datum']) ? $st['heute']['datum'] : date('Y-m-d')));
    foreach (array('heute', 'morgen') as $tag) {
        $s = (isset($st[$tag]) && is_array($st[$tag])) ? $st[$tag] : array();
        $f = fer_haus_feiertag($s, $cfg);
        $w['haus/kalender/' . $tag . '/feiertag'] = $ok ? $f : -1;
        $w['haus/kalender/' . $tag . '/ruhetag'] = $ok
            ? (($f || (isset($s['wochentag']) && (int) $s['wochentag'] === 7)) ? 1 : 0) : -1;
        $w['haus/kalender/' . $tag . '/frei'] = $ok ? (!empty($s['schulfrei']) ? 1 : 0) : -1;
        $w['haus/kalender/' . $tag . '/urlaub'] = !empty($s['urlaub']) ? 1 : 0;
    }
    $in = isset($st['naechste']['in']) ? (int) $st['naechste']['in'] : -1;
    $w['haus/kalender/ferien_in'] = $ok ? $in : -1;
    $w['haus/kalender/ferien_rest'] = $ok ? ($in === 0 ? (int) $st['naechste']['rest'] : 0) : -1;
    $w['haus/kalender/feiertag_in'] = $ok
        ? (isset($st['feiertag_naechster']['in']) ? (int) $st['feiertag_naechster']['in'] : -1) : -1;
    $w['haus/kalender/zaehler'] = ((int) $zaehler >= 0 && (int) $zaehler <= 999) ? (int) $zaehler : 0;
    return $w;
}

/** Was geht in diesem Lauf hinaus? Vollversand (ohne Merker, nach dem Einschalten, alle 30 min) oder nur Geaendertes;
 *  zaehler immer. $merker = Thema => zuletzt erfolgreich gesendeter Wert. Rueckgabe Thema => array(Wert, retain). */
function fer_haus_auswahl($werte, $merker, $voll)
{
    $th = fer_haus_themen();
    $aus = array();
    foreach ($werte as $t => $v) {
        if (empty($th[$t])) { $aus[$t] = array((string) $v, false); continue; }
        if ($voll || !is_array($merker) || !array_key_exists($t, $merker) || (string) $merker[$t] !== (string) $v) {
            $aus[$t] = array((string) $v, true);
        }
    }
    return $aus;
}

/**
 * Eine kurze Sitzung am Broker (Entwurf 2.3, E-E13): Abschrift von awm_haus_sitzung() aus AWM-Abfuhr 1.4.26
 * (vb_g18_aw_bau) ohne Fensterteil. CONNECT, PUBLISH der eigenen Haus-Themen, SUBSCRIBE der zurueckzulesenden,
 * zurueckbehaltene Werte bis SUBACK + 1 s, DISCONNECT.
 *   $senden  Thema => array(Wert, retain) - eine leere Nutzlast nur mit retain (Abraeumen), ein Thema mit # oder + nie
 *   $rueck   genaue Themen (kein # und kein +), deren zurueckbehaltene Werte gelesen werden
 * Rueckgabe array('lage' => ok | kein_broker | abgelehnt | zeit, 'werte' => Thema => Wert (nur retained PUBLISH,
 * Kopfbyte & 1 = 1), 'gesendet' => n). MQTT 3.1.1 von Hand, QoS 0, saubere Sitzung, Client-Kennung 'ferhaus<pid>',
 * Keepalive 10 s, Verbindungsaufbau und CONNACK je 2 s, SUBACK 3 s mit jedem Rueckgabebyte < 0x80. Zugang aus
 * general.json (Mqtt.Brokerhost/-port/-user/-pass); das Kennwort steht nur im CONNECT-Paket, nie in einem Protokoll und
 * nie auf einer Kommandozeile.
 */
function fer_haus_sitzung(array $senden, array $rueck = array())
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'gesendet' => 0);
    $p = fer_paths();
    if ($p['lbhome'] === '' || $p['general'] === '' || !is_file($p['general'])) { return $aus; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross]) && is_scalar($m[$gross])) { return (string) $m[$gross]; }
        return (isset($m[$klein]) && is_scalar($m[$klein])) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 2);
    $aus['lage'] = 'zeit';

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    /* Wartet, bis etwas zu lesen da ist - hoechstens bis $bis (microtime). Erst dann wird ein Paket gelesen: ein
     * Zeitablauf mitten in einem Paket brachte den Leser sonst aus dem Tritt (Bauart awm_haus_sitzung()). */
    $bereit = function ($bis) use ($s) {
        $rest = $bis - microtime(true);
        if ($rest <= 0) { return false; }
        $r = array($s); $w = null; $x = null;
        $n = @stream_select($r, $w, $x, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        return $n !== false && $n > 0;
    };
    $ende_sitzung = function ($lage) use ($s, &$aus) {
        $aus['lage'] = $lage;
        @fwrite($s, chr(0xE0) . chr(0));
        fclose($s);
        return $aus;
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('ferhaus' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) === false) {
        fclose($s);
        return $aus;
    }
    $ack = $bereit(microtime(true) + 2.0) ? $paket() : null;
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) { return $ende_sitzung('zeit'); }
    if (ord($ack[1][1]) !== 0) { return $ende_sitzung('abgelehnt'); }

    foreach ($senden as $t => $e) {
        $e = array_values((array) $e);
        $t = (string) $t;
        $w = isset($e[0]) ? (string) $e[0] : '';
        $r = !empty($e[1]);
        if ($t === '' || strpbrk($t, "#+\0") !== false || ($w === '' && !$r)) { continue; }
        $rumpf = $zk($t) . $w;
        if (@fwrite($s, chr(0x30 | ($r ? 1 : 0)) . $laenge(strlen($rumpf)) . $rumpf) === false) {
            return $ende_sitzung('zeit');
        }
        $aus['gesendet']++;
    }

    $soll = array();
    foreach ($rueck as $t) {
        $t = (string) $t;
        if ($t !== '' && strpbrk($t, "#+\0") === false) { $soll[$t] = true; }
    }
    if ($soll) {
        $verarbeiten = function ($pk) use (&$aus, $soll) {
            if (strlen($pk[1]) < 2 || !($pk[0] & 1)) { return; }
            $tl = unpack('n', substr($pk[1], 0, 2));
            $t = substr($pk[1], 2, $tl[1]);
            if (!isset($soll[$t])) { return; }
            $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
            $aus['werte'][$t] = (string) substr($pk[1], $versatz);
        };
        $sub = pack('n', 1);
        foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
        if (@fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub) === false) { return $ende_sitzung('zeit'); }
        $bestaetigt = false;
        $bis = microtime(true) + 3.0;
        while (!$bestaetigt && $bereit($bis)) {
            $pk = $paket();
            if ($pk === null) { break; }
            $art = $pk[0] >> 4;
            if ($art === 9) {
                /* Je Filter ein Rueckgabebyte; ab 0x80 abgelehnt (etwa durch eine ACL). */
                $rc = (string) substr($pk[1], 2);
                $nr = (strlen($pk[1]) >= 2) ? (int) unpack('n', substr($pk[1], 0, 2))[1] : 0;
                if ($nr !== 1 || strlen($rc) !== count($soll)) { return $ende_sitzung('abgelehnt'); }
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) { return $ende_sitzung('abgelehnt'); }
                }
                $bestaetigt = true;
            } elseif ($art === 3) {
                $verarbeiten($pk);
            }
        }
        if (!$bestaetigt) { return $ende_sitzung('zeit'); }
        $bis = microtime(true) + 1.0;
        while ($bereit($bis)) {
            $pk = $paket();
            if ($pk === null) { break; }
            if (($pk[0] >> 4) === 3) { $verarbeiten($pk); }
        }
    }
    return $ende_sitzung('ok');
}

/** Merker des Anbieters (Thema => zuletzt erfolgreich gesendeter Wert, Zeit des letzten Vollversands) und der letzten
 *  Sitzung - im Zwischenordner (0600 ueber fer_json_schreiben()); postupgrade.sh und postinstall.sh raeumen sie mit. */
function fer_haus_merker_datei()
{
    return fer_tmpdir() . '/haus_letzte.json';
}

function fer_haus_sitzung_datei()
{
    return fer_tmpdir() . '/haus_sitzung.json';
}

/** Den Merker "war Anbieter" (_haus_frueher) in der Konfiguration setzen oder entfernen - mit Zweitschrift. Unterstrich:
 *  fer_sicherung_lesen() uebergeht ihn, fer_vorgaben() kennt ihn nicht. Rueckgabe true, wenn geschrieben wurde. */
function fer_haus_frueher_setzen($ja)
{
    $p = fer_paths();
    $c = fer_config();
    if ((int) !empty($c['_haus_frueher']) === ($ja ? 1 : 0)) { return false; }
    if ($ja) { $c['_haus_frueher'] = 1; } else { unset($c['_haus_frueher']); }
    if (!fer_config_speichern($c)) { return false; }
    fer_zweitschrift_ziehen($p['config'], $p['backup'], $c, array('aktionstoken'));
    return true;
}

/**
 * Anbieter haus/kalender/* im Minutenlauf (bin/cron.php nach dem MQTT-Block). Nur mit haus_kalender_ein = 1 UND
 * mqtt_enabled = 1 UND einer erkannten LoxBerry-Wurzel; sonst wird der Merker verworfen (das Einschalten schickt dann
 * den vollen Satz), und war diese Installation Anbieter (_haus_frueher), raeumt der Lauf hoechstens alle 10 Minuten die
 * zwoelf retained Themen ab. Gesendet werden die geaenderten Themen, der volle Satz ohne Merker (erster Lauf, Neustart,
 * Update, nach dem Einschalten) und alle 30 Minuten (heilt einen Broker ohne Persistenz), dazu in JEDER Sitzung
 * haus/kalender/zaehler (nicht retained, derselbe Zaehler wie status/zaehler). Der Merker wird NUR nach einer Sitzung
 * mit lage 'ok' fortgeschrieben. Rueckgabe: Ergebnis der Sitzung oder null.
 */
function fer_haus_lauf($st, $cfg)
{
    $p = fer_paths();
    $mf = fer_haus_merker_datei();
    if (empty($cfg['haus_kalender_ein']) || empty($cfg['mqtt_enabled']) || $p['lbhome'] === '') {
        if (is_file($mf)) { @unlink($mf); }
        if (!empty($cfg['_haus_frueher']) && $p['lbhome'] !== '') {
            $vf = fer_tmpdir() . '/haus_leeren_versuch';
            clearstatcache(true, $vf);
            $alt = is_file($vf) ? (int) filemtime($vf) : 0;
            if ($alt <= 0 || $alt > time() || time() - $alt >= 600) {
                @touch($vf);
                $e = fer_haus_leeren();
                fer_log('Haus-Themen haus/kalender: Abraeumen im Minutenlauf - ' . $e['lage']
                    . ($e['offen'] ? ' (' . implode(', ', $e['offen']) . ')' : '') . '.');
                if (in_array($e['lage'], array('geleert', 'nichts'), true)) { fer_haus_frueher_setzen(false); }
            }
        }
        return null;
    }
    $werte = fer_haus_werte($st, $cfg, fer_zaehler(false));
    $vorher = null;
    $voll_zeit = 0;
    if (is_file($mf)) {
        $d = json_decode((string) @file_get_contents($mf), true);
        if (is_array($d) && isset($d['werte'], $d['voll']) && is_array($d['werte'])) {
            $vorher = $d['werte'];
            $voll_zeit = (int) $d['voll'];
        }
    }
    $voll = ($vorher === null || $voll_zeit <= 0 || $voll_zeit > time() || time() - $voll_zeit > 1800);
    $r = fer_haus_sitzung(fer_haus_auswahl($werte, $vorher, $voll));
    if ($r['lage'] === 'ok') {
        $merk = $werte;
        unset($merk['haus/kalender/zaehler']);
        fer_json_schreiben($mf, array('werte' => $merk, 'voll' => $voll ? time() : $voll_zeit, 'zeit' => time()));
        if (empty($cfg['_haus_frueher'])) { fer_haus_frueher_setzen(true); }
    }
    fer_json_schreiben(fer_haus_sitzung_datei(), array('zeit' => time(), 'lage' => $r['lage'],
        'gesendet' => (int) $r['gesendet'], 'voll' => $voll ? 1 : 0));
    fer_log_if_changed('haus', $r['lage'] === 'ok' ? 'Haus-Themen haus/kalender: Sitzung am Broker ok'
        : 'Haus-Themen haus/kalender: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute');
    return $r;
}

/**
 * Die zwoelf retained Themen abraeumen (Entwurf 2.6, Nr. 15): erst zuruecklesen; ist nichts belegt -> 'nichts'. Sonst
 * PUBLISH mit Retain-Bit und LEERER Nutzlast fuer die belegten Themen und in derselben Sitzung zuruecklesen, bis zu drei
 * Runden. Nur die eigenen Namen aus fer_haus_themen(), nie haus/#, nie fremde Themen. Rueckgabe array('lage' =>
 * geleert | nichts | rest | kein_broker, 'offen' => belegte Themen, 'grund' => Lage der gescheiterten Sitzung).
 */
function fer_haus_leeren($runden = 3)
{
    $th = fer_haus_retained();
    $belegt = function ($w) {
        $o = array();
        foreach ($w as $t => $v) { if ((string) $v !== '') { $o[] = $t; } }
        return $o;
    };
    $r = fer_haus_sitzung(array(), $th);
    if ($r['lage'] !== 'ok') { return array('lage' => 'kein_broker', 'offen' => array(), 'grund' => $r['lage']); }
    $offen = $belegt($r['werte']);
    if (!$offen) { return array('lage' => 'nichts', 'offen' => array(), 'grund' => ''); }
    $lage = 'rest';
    $grund = '';
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) { $senden[$t] = array('', true); }
        $r = fer_haus_sitzung($senden, $th);
        if ($r['lage'] !== 'ok') { $lage = 'kein_broker'; $grund = $r['lage']; continue; }
        $offen = $belegt($r['werte']);
        if (!$offen) { return array('lage' => 'geleert', 'offen' => array(), 'grund' => ''); }
        $lage = 'rest';
    }
    return array('lage' => $lage, 'offen' => $offen, 'grund' => $grund);
}

/**
 * Folgen einer Aenderung von haus_kalender_ein/mqtt_enabled (Reiter MQTT, Zurueckspielen einer Sicherung): wird die
 * Installation Anbieter, verfaellt der Merker (der naechste Minutenlauf schickt den vollen Satz); hoert sie auf, Anbieter
 * zu sein, und war sie es (Haken und MQTT an) oder traegt sie _haus_frueher, raeumt sie die zwoelf Themen mit Ruecklesen
 * ab. Rueckgabe array(Meldung oder '', 'ok'|'warn', neuer Merker _haus_frueher 0/1 oder null = unveraendert).
 */
function fer_haus_folgen($alt, $neu)
{
    $war = !empty($alt['haus_kalender_ein']) && !empty($alt['mqtt_enabled']);
    $frueher = !empty($alt['_haus_frueher']);
    $ist = !empty($neu['haus_kalender_ein']) && !empty($neu['mqtt_enabled']);
    if ($ist) {
        @unlink(fer_haus_merker_datei());
        return array('', 'ok', null);
    }
    @unlink(fer_haus_merker_datei());
    if (!$war && !$frueher) { return array('', 'ok', null); }
    $e = fer_haus_leeren();
    fer_log('Haus-Themen haus/kalender: Abraeumen ' . $e['lage']
        . ($e['offen'] ? ' (' . implode(', ', $e['offen']) . ')' : '') . '.');
    if ($e['lage'] === 'geleert') { return array(sprintf(fer_t('MQTT.HAUS_M_GELEERT'), count(fer_haus_retained())), 'ok', 0); }
    if ($e['lage'] === 'nichts') { return array(fer_t('MQTT.HAUS_M_NICHTS'), 'ok', 0); }
    if ($e['lage'] === 'rest') { return array(sprintf(fer_t('MQTT.HAUS_M_REST'), implode(', ', $e['offen'])), 'warn', 1); }
    return array(fer_t('MQTT.HAUS_M_KEIN_BROKER'), 'warn', 1);
}

/** Deinstallation (cron.php --haus-leeren): nur, wenn diese Installation Anbieter ist oder war. Ausgabe fuer das
 *  Installationsprotokoll; Rueckgabe immer 0 - die Deinstallation laeuft weiter. */
function fer_haus_leeren_ausgabe()
{
    $cfg = fer_config();
    if (empty($cfg['haus_kalender_ein']) && empty($cfg['_haus_frueher'])) {
        echo "<INFO> haus/kalender: diese Installation war nie Anbieter - nichts zu leeren.\n";
        return 0;
    }
    $e = fer_haus_leeren();
    if ($e['lage'] === 'geleert') {
        echo "<OK> haus/kalender: geleert (vom Broker bestaetigt, " . count(fer_haus_retained()) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo "<INFO> haus/kalender: nichts zu leeren (der Broker haelt keines der Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo "<WARNING> haus/kalender: noch zurueckbehalten - bitte von Hand leeren: " . implode(', ', $e['offen'])
           . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo "<WARNING> haus/kalender: der Broker war nicht erreichbar (" . $e['grund'] . ") - bitte von Hand leeren: "
           . implode(', ', fer_haus_retained()) . "\n";
    }
    return 0;
}

/** Die Zeile "Haus-Themen haus/kalender" fuer den Reiter Test: array(Stand -1 grau/aus, 1 gruen/ok, 0 gelb/gescheitert,
 *  Text). Aus haus_letzte.json und haus_sitzung.json; ohne Kennwort, ohne Namen. */
function fer_haus_testzeile($cfg)
{
    if (empty($cfg['haus_kalender_ein'])) { return array(-1, fer_t('MQTT.HAUS_T_AUS')); }
    if (empty($cfg['mqtt_enabled'])) { return array(-1, fer_t('MQTT.HAUS_T_MQTT_AUS')); }
    $sz = json_decode((string) @file_get_contents(fer_haus_sitzung_datei()), true);
    $mz = json_decode((string) @file_get_contents(fer_haus_merker_datei()), true);
    if (!is_array($sz) || !isset($sz['lage'], $sz['zeit'])) { return array(0, fer_t('MQTT.HAUS_T_NIE')); }
    $w = (is_array($mz) && isset($mz['werte']) && is_array($mz['werte'])) ? $mz['werte'] : array();
    $g = function ($t) use ($w) { return isset($w[$t]) ? (string) $w[$t] : '-'; };
    $txt = sprintf(fer_t('MQTT.HAUS_T_AN'),
        (is_array($mz) && isset($mz['zeit'])) ? date('d.m.Y H:i:s', (int) $mz['zeit']) : '-',
        $g('haus/kalender/datum'), $g('haus/kalender/heute/frei'), $g('haus/kalender/morgen/frei'),
        date('H:i:s', (int) $sz['zeit']), (string) $sz['lage']);
    return array($sz['lage'] === 'ok' && time() - (int) $sz['zeit'] <= 150 ? 1 : 0, $txt);
}
