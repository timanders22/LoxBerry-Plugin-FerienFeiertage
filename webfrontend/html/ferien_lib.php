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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
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
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'ferien';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/ferien.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/ferien.log',
            'datadir' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/ferien',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/ferien.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/ferien.backup.json',
        'log' => sys_get_temp_dir() . '/ferien/ferien.log',
        'datadir' => sys_get_temp_dir() . '/ferien/data',
        'tmp' => sys_get_temp_dir() . '/ferien',
        'lbhome' => '',
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
    'mqtt_enabled' => 0,
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
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '',
                         // Ansage-2: Alexa-NG (ab Werk nicht gewaehlt). Das Sprechtoken
                         // ist ein Geheimnis: nie im Formular, nicht in der Sicherung.
                         'alexa_geraet' => '', 'alexa_token' => '', 'alexa_laut' => -1);
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
 *    sie erzeugte Termine, die es nicht gibt.
 *  - Termine mit Uhrzeit werden uebersprungen. Dieses Plugin rechnet in
 *    ganzen Tagen; ein Zahnarzttermin um 14 Uhr ist kein Urlaubstag. Genommen
 *    werden nur Ganztagstermine (DTSTART;VALUE=DATE).
 *  - Zeitzonen spielen damit keine Rolle.
 *
 * DTEND ist bei Ganztagsterminen EXKLUSIV - ein eintaegiger Termin am 3.8.
 * hat DTSTART 20260803 und DTEND 20260804. Wer das uebersieht, verlaengert
 * jeden Urlaub um einen Tag; die Haussteuerung faehrt dann einen Tag zu
 * spaet wieder hoch. Deshalb wird hier ein Tag abgezogen.
 *
 * Rueckgabe: Liste im selben Aufbau wie cfg['own'], oder leer.
 */
function fer_ics_lesen($text, $filter = '') {
    $aus = array();
    // Fortsetzungszeilen aufloesen: ICS bricht lange Zeilen um und ruecket die
    // Fortsetzung mit einem Leerzeichen oder Tabulator ein.
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $text = preg_replace('/\n[ \t]/', '', $text);
    $bloecke = preg_split('/BEGIN:VEVENT/', $text);
    array_shift($bloecke);
    foreach ($bloecke as $b) {
        $b = substr($b, 0, strpos($b, 'END:VEVENT') === false ? strlen($b) : strpos($b, 'END:VEVENT'));
        if (stripos($b, 'RRULE') !== false) { continue; }
        if (!preg_match('/DTSTART[^:\n]*;VALUE=DATE[^:\n]*:(\d{8})/i', $b, $ms)) { continue; }
        $von = substr($ms[1], 0, 4) . '-' . substr($ms[1], 4, 2) . '-' . substr($ms[1], 6, 2);
        $bis = $von;
        if (preg_match('/DTEND[^:\n]*;VALUE=DATE[^:\n]*:(\d{8})/i', $b, $me)) {
            $roh = substr($me[1], 0, 4) . '-' . substr($me[1], 4, 2) . '-' . substr($me[1], 6, 2);
            // DTEND ist exklusiv - siehe Kommentar oben.
            $bis = date('Y-m-d', strtotime($roh . ' -1 day'));
            if ($bis < $von) { $bis = $von; }
        }
        $name = '';
        if (preg_match('/\nSUMMARY[^:\n]*:(.*)/', "\n" . $b, $mn)) {
            $name = trim(str_replace(array('\\,', '\\;', '\\n', '\\N'), array(',', ';', ' ', ' '), $mn[1]));
        }
        if ($name === '') { $name = 'Kalendertermin'; }
        if ($filter !== '' && stripos($name, $filter) === false) { continue; }
        $aus[] = array('von' => $von, 'bis' => $bis, 'name' => $name);
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
    return $url === '' ? '' : md5($url . "\n" . (is_string($cfg['ics_filter']) ? trim($cfg['ics_filter']) : ''));
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
    $termine = fer_ics_lesen($roh, is_string($cfg['ics_filter']) ? trim($cfg['ics_filter']) : '');
    $vorher = isset($c['termine']) ? $c['termine'] : null;
    fer_json_schreiben($f, array('stand' => date('c'), 'ok_ts' => time(), 'quelle' => $quelle,
        'versuch_quelle' => $quelle, 'versuch_ts' => time(), 'fehler' => '', 'termine' => $termine));
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

    /* Die Themenliste entsteht aus fer_felder() - siehe den langen Kommentar
     * dort. Bis 1.1.7 stand hier eine zweite, von Hand gepflegte Liste; sie
     * war um vier Werte kuerzer als die des HTTP-Weges, und weil beide auf
     * 27 Eintraege kamen, ist es niemandem aufgefallen. */
    $m = array();
    if (!$nur_lebenszeichen) {
        $felder = fer_felder();
        foreach (fer_werte($st) as $name => $wert) {
            if (isset($felder[$name][5]) && $felder[$name][5] !== '') {
                $m[$felder[$name][5]] = $wert;
            }
        }
        // Dazu die Textwerte, die ein virtueller HTTP-Eingang nicht lesen kann.
        $m = array_merge($m, fer_mqtt_texte($st));
    }
    // M2: das Lebenszeichen - bei JEDEM Lauf, fluechtig.
    $m = array_merge($m, fer_mqtt_lebenszeichen($st));

    /* function_exists() vor socket_create().
     *
     * Ein vorangestelltes @ unterdrueckt Meldungen, aber es faengt keinen
     * "Call to undefined function" - das ist ein toedlicher Fehler, und der
     * Cron-Lauf waere an dieser Zeile ohne Eintrag im Protokoll gestorben.
     * Auf einem LoxBerry ohne php-sockets ist das kein Sonderfall. */
    if (!function_exists('socket_create')) {
        fer_log_if_changed('mqtt', 'Die PHP-Erweiterung sockets fehlt - ohne sie'
            . ' laesst sich das MQTT-Gateway nicht ueber UDP ansprechen.'
            . ' Der HTTP-Weg (ferien.php) ist davon nicht betroffen.');
        return 0;
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        fer_log_if_changed('mqtt', 'UDP-Socket liess sich nicht anlegen -'
            . ' fehlt die PHP-Erweiterung sockets?');
        return 0;
    }
    $gesendet = 0;
    foreach ($m as $k => $v) {
        /* M3: erst saeubern, dann auf leer pruefen - ein Name, der nur aus
         * einem Zeilenumbruch bestand, ging bis 1.2.15 als LEERE Nutzlast
         * hinaus. Nie eine leere Nutzlast; ohne Aussage steht '-'. */
        $nl = fer_mqtt_nutzlast($v);
        if ($nl === '') { $nl = '-'; }
        $msg = 'publish ' . fer_mqtt_thema($prefix . '/' . $k) . ' ' . $nl;
        if (@socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport) !== false) {
            $gesendet++;
        }
    }
    socket_close($s);
    if (!$nur_lebenszeichen) {
        fer_log_if_changed('mqtt', $gesendet . ' von ' . count($m) . ' Werten gesendet (Port ' . $udpport . ')');
    }
    return $gesendet;
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
    return array(
        'datum' => str_replace('-', '', (string) (isset($st['heute']['datum']) ? $st['heute']['datum'] : date('Y-m-d'))),
        'status/ts' => time(),
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

/* ---------------- Ansage (TTS) - identisch zu den anderen Plugins ---------------- */

function fer_tts_url($text) {
    $cfg = fer_config();
    $tts = $cfg['tts'];
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null;
    }
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. Vorher wurde nur im
     * Modus musicserver je Zone getrimmt; in den Vorlagen-Modi ging die
     * Eingabe roh in {zones} - aus "2, 4, 6" wurde eine Adresse mit
     * Leerzeichen. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
    if ($mode === 'musicserver') {
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') { continue; }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    /* Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
     * Vorher stand die Pruefung unbedingt am Anfang der Funktion - eine
     * eigene Vorlage ohne {ip} war damit unbenutzbar (AWM-1.2.0-Fund,
     * hier nachgezogen). */
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)), $tpl);
}

function fer_say($text) {
    /* Ansage-2: Alexa-NG hat keine Ansage-Adresse, sondern einen POST mit
     * Sprechtoken (fer_alexa_sprechen() protokolliert selbst, ohne Token). */
    $cfg_a = fer_config();
    if (isset($cfg_a['tts']['mode']) && $cfg_a['tts']['mode'] === 'alexang') {
        return fer_alexa_sprechen($text);
    }
    $url = fer_tts_url($text);
    if ($url === null) {
        fer_log('Ansage: Modus "Original Loxone Audioserver" - Ausgabe erfolgt ueber Loxone Config');
        return false;
    }
    if ($url === '') {
        fer_log('Ansage uebersprungen: keine TTS-IP konfiguriert');
        return false;
    }
    $r = fer_http($url, 10);
    fer_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

/* ---------------- Ausgabeart Alexa-NG (Ansage-2, ab Werk nicht gewaehlt) ----------------
 *
 * Das Plugin LoxBerry-Plugin-Alexa-NG (Ordner alexang,
 * https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG) laesst Amazon-
 * Echo-Geraete sprechen. Die Ansage geht per POST an seinen Endpunkt auf
 * DIESEM LoxBerry (Webport aus general.json): aktion=sprechen, token, geraet
 * (leer = Standardgeraet von Alexa-NG, dann nicht mitgeschickt), text,
 * optional laut. Erfolg ist nur eine Antwort, die mit SPRECHEN;OK=1 beginnt.
 *
 * Das Sprechtoken ist ein Kennwort des anderen Plugins: es steht nie in einer
 * Adresse (Adressen landen in Zugriffsprotokollen), nie im Formular, nie im
 * Protokoll, nie in der Sicherung. Faellt Alexa-NG aus (403, 503, 200 mit
 * OK=0, keine Verbindung, Zeitueberschreitung), entfaellt die Ansage - es gibt
 * keinen stillen Wechsel auf einen anderen Lautsprecher -, und Protokoll,
 * Reiter Test und ?say= nennen HTTP-Code und GRUND.
 */

/** Der Webport des LoxBerry (general.json, Webserver.Port); ohne Angabe 80. */
function fer_webport() {
    $p = fer_paths();
    if ($p['lbhome'] !== '') {
        $d = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
        if (is_array($d) && isset($d['Webserver']) && is_array($d['Webserver'])
            && isset($d['Webserver']['Port']) && is_scalar($d['Webserver']['Port'])
            && (int) $d['Webserver']['Port'] >= 1 && (int) $d['Webserver']['Port'] <= 65535) {
            return (int) $d['Webserver']['Port'];
        }
    }
    return 80;
}

/** Die Adresse des Alexa-NG-Endpunkts - ohne Token. */
function fer_alexa_adresse() {
    return 'http://127.0.0.1:' . fer_webport() . '/plugins/alexang/index.php';
}

/** Form des Sprechtokens: 8 bis 128 Zeichen aus A-Z a-z 0-9 _ - (Alexa-NG erzeugt 24 Hexzeichen). */
function fer_alexa_token_ok($t) {
    return is_string($t) && preg_match('/^[A-Za-z0-9_\-]{8,128}\z/', $t) === 1;
}

/**
 * Ein POST an Alexa-NG. Rueckgabe: array(HTTP-Status, erste Antwortzeile, Art)
 * mit Art 'antwort' (Status > 0), 'verbindung' (keine Verbindung) oder 'zeit'
 * (keine Antwort in $tmo Sekunden). Ohne Weiterleitung. Der Status kommt aus
 * stream_get_meta_data(), nicht aus $http_response_header (PHP 8.5). Die
 * Antwortzeile wird um ein etwa darin stehendes Token bereinigt und auf
 * harmlose Zeichen gekuerzt, bevor sie irgendwo hingeht.
 */
function fer_alexa_rufen(array $felder, $tmo) {
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
        'content' => http_build_query($felder, '', '&'),
        'timeout' => $tmo, 'user_agent' => 'LoxBerry Ferien-Plugin',
        'ignore_errors' => true, 'follow_location' => 0,
    )));
    $t0 = microtime(true);
    $fh = @fopen(fer_alexa_adresse(), 'r', false, $ctx);
    if ($fh === false) {
        return array(0, '', (microtime(true) - $t0) >= $tmo - 0.5 ? 'zeit' : 'verbindung');
    }
    $meta = stream_get_meta_data($fh);
    $inhalt = (string) @stream_get_contents($fh, 8192);
    $nach = stream_get_meta_data($fh);
    fclose($fh);
    $code = 0;
    foreach ((array) (isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array()) as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
    }
    if ($inhalt === '' && !empty($nach['timed_out'])) {
        return array(0, '', 'zeit');
    }
    $zeilen = preg_split('/\r?\n/', trim($inhalt));
    $erste = trim((string) $zeilen[0]);
    if (isset($felder['token']) && is_string($felder['token']) && $felder['token'] !== '') {
        $erste = str_replace($felder['token'], '***', $erste);
    }
    $erste = substr(preg_replace('/[^A-Za-z0-9;=_.:,*\- ]/', '', $erste), 0, 160);
    return array($code, $erste, $code > 0 ? 'antwort' : 'verbindung');
}

/** GRUND=... aus einer Antwortzeile von Alexa-NG ('' ohne). */
function fer_alexa_grund($zeile) {
    return preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})/', (string) $zeile, $m) ? $m[1] : '';
}

/** Ein Ergebnis fuer das Protokoll (ASCII, wie alle Protokollzeilen) - nie mit Token. */
function fer_alexa_befund($code, $zeile, $art, $tmo) {
    if ($art === 'zeit') { return 'Zeitueberschreitung, keine Antwort nach ' . (int) $tmo . ' s'; }
    if ($art === 'verbindung') { return 'keine Verbindung zu ' . fer_alexa_adresse() . ' (Alexa-NG installiert?)'; }
    if ($art === 'kein_token') { return 'kein Sprechtoken gespeichert'; }
    $g = fer_alexa_grund($zeile);
    return 'HTTP ' . (int) $code . (strpos((string) $zeile, ';OK=0') !== false ? ', OK=0' : '')
        . ($g !== '' ? ', GRUND=' . $g : ($zeile !== '' ? ', Antwort "' . substr((string) $zeile, 0, 60) . '"' : ''));
}

/** Dasselbe in der Sprache der Oberflaeche (Reiter Test). */
function fer_alexa_testtext($code, $zeile, $art, $tmo) {
    if ($art === 'zeit') { return sprintf(fer_t('ALEXA.T_ZEIT'), (int) $tmo); }
    if ($art === 'verbindung') { return sprintf(fer_t('ALEXA.T_KEINE_VERBINDUNG'), fer_alexa_adresse()); }
    if ($art === 'kein_token') { return fer_t('ALEXA.T_KEIN_TOKEN'); }
    return fer_alexa_befund($code, $zeile, $art, $tmo);
}

/** Das Ergebnis der letzten Ansage ablegen (Zwischenordner, 0600) - nie Token oder Text. */
function fer_alexa_merken($ok, $code, $zeile, $art) {
    fer_json_schreiben(fer_tmpdir() . '/alexa_letzte.json', array(
        'zeit' => time(), 'ok' => $ok ? 1 : 0, 'code' => (int) $code,
        'grund' => fer_alexa_grund($zeile), 'art' => (string) $art, 'zeile' => (string) $zeile,
    ));
}

/** Das Ergebnis der letzten Ansage oder null. */
function fer_alexa_letzte() {
    $f = fer_tmpdir() . '/alexa_letzte.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['zeit'], $d['ok'], $d['code'], $d['art'])) { return null; }
    return array('zeit' => (int) $d['zeit'], 'ok' => !empty($d['ok']), 'code' => (int) $d['code'],
                 'grund' => (isset($d['grund']) && is_string($d['grund'])) ? $d['grund'] : '',
                 'art' => (string) $d['art'],
                 'zeile' => (isset($d['zeile']) && is_string($d['zeile'])) ? $d['zeile'] : '');
}

/** GRUND fuer die Antwortzeile von ?say=: der von Alexa-NG oder ein eigener. */
function fer_alexa_grund_kurz($l) {
    if (!is_array($l)) { return '-'; }
    if ($l['grund'] !== '') { return $l['grund']; }
    $eigen = array('zeit' => 'ZEIT', 'verbindung' => 'KEINE_VERBINDUNG', 'kein_token' => 'KEIN_TOKEN');
    return isset($eigen[$l['art']]) ? $eigen[$l['art']] : '-';
}

/** Eine Ansage ueber Alexa-NG (10 s). Rueckgabe true nur bei SPRECHEN;OK=1. */
function fer_alexa_sprechen($text) {
    $cfg = fer_config();
    $t = $cfg['tts'];
    $tok = (isset($t['alexa_token']) && is_string($t['alexa_token'])) ? $t['alexa_token'] : '';
    if (!fer_alexa_token_ok($tok)) {
        fer_alexa_merken(false, 0, '', 'kein_token');
        fer_log('Ansage uebersprungen: Ausgabeart Alexa-NG, aber kein Sprechtoken gespeichert');
        return false;
    }
    $f = array('aktion' => 'sprechen', 'token' => $tok);
    $g = (isset($t['alexa_geraet']) && is_string($t['alexa_geraet'])) ? $t['alexa_geraet'] : '';
    if ($g !== '') { $f['geraet'] = $g; }
    $f['text'] = (string) $text;
    if (isset($t['alexa_laut']) && is_int($t['alexa_laut']) && $t['alexa_laut'] >= 0 && $t['alexa_laut'] <= 100) {
        $f['laut'] = $t['alexa_laut'];
    }
    list($code, $zeile, $art) = fer_alexa_rufen($f, 10);
    $ok = $code === 200 && strpos($zeile, 'SPRECHEN;OK=1') === 0;
    fer_alexa_merken($ok, $code, $zeile, $art);
    fer_log('Ansage gesendet (Alexa-NG' . ($g !== '' ? ', Geraet ' . $g : '') . '): "' . $text . '" -> '
        . ($ok ? 'OK' : 'FEHLER ' . fer_alexa_befund($code, $zeile, $art, 10)));
    return $ok;
}

/**
 * Die Zeile "Ansage ueber Alexa-NG" im Reiter Test - null, wenn Alexa-NG nicht
 * die Ausgabeart ist. Gefragt werden selftest=1 (prueft nur das Token, kein
 * Amazon) und aktion=status (Anmeldung bei Amazon), beide per POST, je
 * hoechstens 5 s. Dazu das Ergebnis der letzten echten Ansage: ein 503, ein
 * 200 mit OK=0 oder eine Zeitueberschreitung beim Senden steht damit auch
 * hier, nicht nur im Protokoll. Rueckgabe: array(Frage, ok, Hinweis).
 */
function fer_alexa_pruefzeile($cfg) {
    if (!isset($cfg['tts']['mode']) || $cfg['tts']['mode'] !== 'alexang') { return null; }
    $frage = fer_t('ALEXA.F_TEST');
    $aus = empty($cfg['notify']['audio']) ? ' ' . fer_t('ALEXA.T_AUDIO_AUS') : '';
    $tok = (isset($cfg['tts']['alexa_token']) && is_string($cfg['tts']['alexa_token'])) ? $cfg['tts']['alexa_token'] : '';
    if (!fer_alexa_token_ok($tok)) {
        return array($frage, false, fer_t('ALEXA.T_KEIN_TOKEN') . $aus);
    }
    list($c1, $z1, $a1) = fer_alexa_rufen(array('selftest' => '1', 'token' => $tok), 5);
    if ($c1 !== 200 || strpos($z1, 'SELFTEST;OK=1') !== 0) {
        return array($frage, false, sprintf(fer_t('ALEXA.T_SELFTEST_FEHL'), fer_alexa_testtext($c1, $z1, $a1, 5)) . $aus);
    }
    list($c2, $z2, $a2) = fer_alexa_rufen(array('aktion' => 'status'), 5);
    $ok = $c2 === 200 && strpos($z2, 'ALEXANG;OK=1') === 0;
    $txt = $ok ? fer_t('ALEXA.T_OK') : sprintf(fer_t('ALEXA.T_STATUS_FEHL'), fer_alexa_testtext($c2, $z2, $a2, 5));
    $l = fer_alexa_letzte();
    if ($l !== null) {
        $txt .= ' ' . sprintf(fer_t($l['ok'] ? 'ALEXA.T_LETZTE_OK' : 'ALEXA.T_LETZTE_FEHL'), date('d.m.Y H:i', $l['zeit']),
            $l['ok'] ? 'SPRECHEN;OK=1' : fer_alexa_testtext($l['code'], $l['zeile'], $l['art'], 10));
        if (!$l['ok']) { $ok = false; }
    }
    return array($frage, $ok, $txt . $aus);
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
    $cfg = fer_config();
    return array(
        'ann'   => fer_ann_active($st),
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
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
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
    );
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
    $ohne_t = array();
    foreach (fer_felder() as $name => $fd) {
        if (!isset($fd[5]) || $fd[5] === '') { $ohne_t[] = $name; }
    }
    $add(fer_t('PRUEF.F_MQTT_DECKUNG'), count($ohne_t) === 0,
        count($ohne_t) === 0
            ? sprintf(fer_t('PRUEF.H_MQTT_DECKUNG_OK'), count(fer_felder()), count(fer_mqtt_texte($st)))
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
                if (strlen((string) $c['Comment']) > 40) { $lang++; }
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

    if ($basis !== '' && $soll !== '') {
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

    /* --- 4. MQTT ------------------------------------------------------- */
    if (!empty($cfg['mqtt_enabled'])) {
        $add(fer_t('PRUEF.F_SOCKETS'), function_exists('socket_create'),
            fer_t(function_exists('socket_create') ? 'PRUEF.JA' : 'PRUEF.H_SOCKETS_NEIN'));
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
            . ($fehler !== '' ? ' ' . sprintf(fer_t('PRUEF.H_ICS_FEHLER'), $fehler) : ''));
    }
    if (is_string($cfg['subdivision2']) && trim($cfg['subdivision2']) !== '') {
        $n2 = count((array) (isset($d['ferien2']) ? $d['ferien2'] : array()));
        $add(fer_t('PRUEF.F_REGION2'), $n2 > 0,
            sprintf(fer_t('PRUEF.H_REGION2'), $n2, $cfg['subdivision2']));
    }

    /* --- 6. Ansage ------------------------------------------------------ */
    /* Ansage-2: ist Alexa-NG die Ausgabeart, gibt es keine Ansage-Adresse zu
     * bilden; die eigene Zeile fragt Alexa-NG selbst (fer_alexa_pruefzeile()). */
    $alexa_z = fer_alexa_pruefzeile($cfg);
    if ($alexa_z !== null) {
        $add($alexa_z[0], $alexa_z[1], $alexa_z[2]);
    } elseif (!empty($cfg['notify']['audio'])) {
        $url = fer_tts_url('Probe');
        $add(fer_t('PRUEF.F_ANSAGE'), $url === null ? null : ($url !== ''),
            fer_t($url === null ? 'PRUEF.H_ANSAGE_AUDIOSERVER' : ($url !== '' ? 'PRUEF.JA' : 'PRUEF.H_ANSAGE_OHNE_IP')));
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
    static $k = array(
        'OK' => 'Daten der Quelle gueltig', 'FERIEN' => 'heute Ferien', 'FEIERTAG' => 'heute Feiertag',
        'WOCHENENDE' => 'heute Wochenende', 'SCHULFREI' => 'heute schulfrei', 'SCHULTAG' => 'heute Schultag',
        'BRUECKE' => 'heute Brueckentag', 'MFERIEN' => 'morgen Ferien', 'MFEIERTAG' => 'morgen Feiertag',
        'MSCHULFREI' => 'morgen schulfrei', 'MSCHULTAG' => 'morgen Schultag', 'MBRUECKE' => 'morgen Brueckentag',
        'FERIENIN' => 'Ferien beginnen in', 'FERIENREST' => 'laufende Ferien: Resttage',
        'FERIENDAUER' => 'Ferien: Dauer', 'FEIERTAGIN' => 'naechster Feiertag in', 'URLAUB' => 'heute Urlaub',
        'MURLAUB' => 'morgen Urlaub', 'URLAUBIN' => 'Urlaub beginnt in', 'URLAUBREST' => 'laufender Urlaub: Resttage',
        'URLAUBDAUER' => 'Urlaub: Dauer', 'URLAUBENDE' => 'letzter Urlaubstag', 'WARN' => 'Warnhinweis aktiv',
        'ANN' => 'Meldefenster aktiv', 'AUDIO' => 'Ansage freigegeben', 'PUSH' => 'Push freigegeben',
        'PTEST' => 'Test-Push ausloesen', 'WOCHENTAG' => 'Wochentag heute (1 = Mo)',
        'MWOCHENTAG' => 'Wochentag morgen (1 = Mo)', 'FREITAGE' => 'freie Tage am Stueck ab heute',
        'MFREITAGE' => 'freie Tage am Stueck ab morgen', 'FERIENENDE' => 'heute letzter Ferientag',
        'MERSTERSCHULTAG' => 'morgen erster Schultag', 'HALBTAG' => 'heute halber Feiertag',
        'MHALBTAG' => 'morgen halber Feiertag', 'BRUECKEIN' => 'naechster Brueckentag in',
        'FERIENNAECHSTEIN' => 'Ferien nach den laufenden in', 'FEIERTAG2IN' => 'uebernaechster Feiertag in',
        'URLAUBHEIM' => 'Vorwaermen zur Rueckkehr', 'FERIEN2' => 'heute Ferien Region 2',
        'MFERIEN2' => 'morgen Ferien Region 2', 'SCHULFREI2' => 'heute schulfrei Region 2',
        'MSCHULFREI2' => 'morgen schulfrei Region 2', 'SCHULTAG2' => 'heute Schultag Region 2',
        'MSCHULTAG2' => 'morgen Schultag Region 2', 'FERIEN2IN' => 'Ferien Region 2 in',
        'ALTER_TAGE' => 'Tage seit letztem Abruf',
    );
    return isset($k[$name]) ? $k[$name] : $name;
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

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function fer_vorlage() {
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = getenv('LBPPLUGINDIR') ?: 'ferien';
    $cmds = array();
    foreach (fer_felder() as $name => $f) {
        // Das sechste Element ist das MQTT-Thema; die Importdatei braucht es
        // nicht. Ausgeschrieben statt per list(), damit beim naechsten
        // Anbau auffaellt, dass die Liste sechs Spalten hat.
        $analog  = $f[0];
        $min     = $f[1];
        $max     = $f[2];
        $einheit = $f[3];
        $text    = $f[4];
        $kurz = fer_kurztext($name);
        $cmds[] = array(
            'title' => 'FERIEN_' . $name,
            'comment' => $kurz . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'hint' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : '')
                    . ($min < 0 ? ' - ' . fer_t('T12.LX_MINUS1') : ''),
            'check' => fer_check($name),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_ferien.xml', fer_xml_virtual_in_http(array(
        'title' => 'Ferien und Feiertage',
        'hint' => 'Schulferien, Feiertage, Brueckentage und Urlaub vom LoxBerry-Plugin Ferien und Feiertage. '
                . 'Eine Zahl gilt nur zusammen mit OK=1.',
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/ferien.php',
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Ferien und Feiertage (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
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
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
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
    $fehlend = array();
    foreach (array_keys(fer_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(fer_t('TEXT.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
        $namen = array_merge($namen, $fehlend);
    }
    /* Die Meldungen sind reiner Text: die Oberflaeche maskiert sie genau
     * einmal (O3 - bis 1.2.15 wurde ein fremder Schluessel zweimal maskiert). */
    /* Ansage-2: das geltende Sprechtoken bleibt (es steht in keiner Sicherung). */
    if (!$mangel && is_array($neu['tts'])) {
        $alt_tok = (is_array($geltend) && isset($geltend['tts']) && is_array($geltend['tts'])
            && isset($geltend['tts']['alexa_token']) && fer_alexa_token_ok($geltend['tts']['alexa_token']))
            ? $geltend['tts']['alexa_token'] : '';
        $neu['tts']['alexa_token'] = $alt_tok;
        if ($alt_tok !== '') { $hinweise[] = fer_t('ALEXA.SICH_BLEIBT'); }
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
    // Ansage-2: wie "Einstellungen sichern" ohne das Alexa-NG-Sprechtoken.
    if (isset($voll['tts']) && is_array($voll['tts'])) { unset($voll['tts']['alexa_token']); }
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
        case 'typ_streng': case 'halbtag_frei': case 'mqtt_enabled':
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
        case 'tts.mode':
            return $auswahl($w, array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang'));
        case 'tts.alexa_geraet':
            /* Ansage-2: leer = Standardgeraet von Alexa-NG; sonst bis 200 Byte
             * ohne Steuerzeichen (Normalname, Komma-Liste, gruppe:<name>, alle). */
            $r = $text($w, $ohne_steuer, fer_t('MELD.FORM_TEXT'), true);
            if ($r[0] && strlen($r[1]) > 200) { return array(false, null, sprintf(fer_t('MELD.W_LAENGE'), 200)); }
            return $r;
        case 'tts.alexa_laut':
            /* -1 = die Lautstaerke des Geraets bleibt (im Formular: leer); sonst 0..100. */
            if ($w === -1) { return array(true, -1, ''); }
            return $zahl($w, 0, 100);
        case 'tts.alexa_token':
            /* Nur das Formular fragt hiernach; der Grund zeigt den Wert nie. */
            if (!is_string($w)) {
                return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
            }
            if (!fer_alexa_token_ok($w)) {
                return array(false, null, sprintf(fer_t('MELD.W_FORM'), '', fer_t('ALEXA.FORM_TOKEN')));
            }
            return array(true, $w, '');
        case 'tts.ip':
            return $text($w, '/^[A-Za-z0-9.\-]{1,253}\z/', fer_t('MELD.FORM_IP'), true);
        case 'tts.port':
            return $zahl($w, 1, 65535);
        case 'tts.zones':
            return $text($w, '/^[0-9~, ]{1,100}\z/', fer_t('MELD.FORM_ZONEN'), false);
        case 'tts.volume':
            return $zahl($w, 1, 100);
        case 'tts.lang':
            return $text($w, '/^[a-z]{2}\z/', fer_t('MELD.FORM_SPRACHE'), false);
        case 'tts.template':
            $r = $text($w, $ohne_steuer, fer_t('MELD.FORM_TEXT'), true);
            if ($r[0] && strlen($r[1]) > 500) { return array(false, null, sprintf(fer_t('MELD.W_LAENGE'), 500)); }
            return $r;
        case 'notify':
        case 'tts':
            if (!is_array($w) || ($w !== array() && array_keys($w) === range(0, count($w) - 1))) {
                return array(false, null, sprintf(fer_t('MELD.W_TYP'), $typ($w)));
            }
            $soll = $k === 'notify'
                ? array('audio', 'push', 'time', 'freetag', 'ferienstart', 'bridge_month')
                : array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template',
                        'alexa_geraet', 'alexa_laut');     // Ansage-2 (ohne Sprechtoken)
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
