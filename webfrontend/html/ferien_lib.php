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
date_default_timezone_set('Europe/Berlin');


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
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/ferien',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/ferien.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/ferien.backup.json',
        'log' => sys_get_temp_dir() . '/ferien/ferien.log',
        'data' => sys_get_temp_dir() . '/ferien/data',
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

function fer_config() {
    $p = fer_paths();
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
    }
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
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
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
function fer_json_schreiben($pfad, $daten) {
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        fer_log('FEHLER: ' . basename($pfad) . ' konnte nicht erzeugt werden ('
            . json_last_error_msg() . ') - die vorhandene Datei bleibt unveraendert.');
        return false;
    }
    $verz = dirname($pfad);
    if (!is_dir($verz)) { @mkdir($verz, 0775, true); }
    $tmp = $pfad . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $js) === false) {
        fer_log('FEHLER: ' . $tmp . ' liess sich nicht schreiben - Platz? Rechte?');
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

function fer_tmpdir() {
    $p = fer_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    return $p['tmp'];
}
function fer_datadir() {
    $p = fer_paths();
    if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
    return $p['data'];
}

/* ---------------- Protokoll ---------------- */

function fer_log($msg) {
    $p = fer_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
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

function fer_datafile() {
    return fer_datadir() . '/termine.json';
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
 * Rueckgabe: [ok, quelle]. Die Daten liegen persistent in data/termine.json,
 * damit das Plugin auch ohne Internet weiterarbeitet.
 */
function fer_fetch($force = false) {
    $cfg = fer_config();
    $f = fer_datafile();
    if (!$force && is_file($f) && time() - filemtime($f) < 7 * 86400) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d) && !empty($d['bis']) && $d['bis'] > date('Y-m-d', strtotime('+60 days'))) {
            return array(1, 'cache');
        }
    }
    $von = date('Y-m-d', strtotime('-30 days'));
    $bis = date('Y-m-d', strtotime('+18 months'));
    $land = preg_replace('/[^A-Z]/', '', strtoupper((string) $cfg['country'])) ?: 'DE';
    $sub = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string) $cfg['subdivision']));
    $lang = preg_replace('/[^A-Z]/', '', strtoupper((string) $cfg['lang'])) ?: 'DE';
    $base = 'https://openholidaysapi.org/';
    $q = 'countryIsoCode=' . rawurlencode($land) . '&languageIsoCode=' . rawurlencode($lang)
       . ($sub !== '' ? '&subdivisionCode=' . rawurlencode($sub) : '')
       . '&validFrom=' . $von . '&validTo=' . $bis;
    /* Schulart (groupCode). Die Datenquelle fuehrt das nur dort, wo ein Land
     * seine Ferien nach Schulart trennt - gemessen am 18.08.2026 sind das in
     * Deutschland genau zwei Gruppen, beide fuer Mecklenburg-Vorpommern
     * (DE-MV-ABS "Allgemeinbildende Schulen", DE-MV-BBS "Berufliche Schulen").
     * Ohne den Zusatz bekommt man dort beide vermischt. */
    $gruppe = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string) $cfg['group']));
    $qs = $q . ($gruppe !== '' ? '&groupCode=' . rawurlencode($gruppe) : '');
    $out = array('von' => $von, 'bis' => $bis, 'stand' => date('c'),
                 'land' => $land, 'sub' => $sub, 'gruppe' => $gruppe,
                 'ferien' => array(), 'feiertage' => array(), 'ferien2' => array(),
                 'sub2' => '');
    $fehler = 0;
    foreach (array('school' => 'SchoolHolidays', 'public' => 'PublicHolidays') as $key => $ep) {
        if (empty($cfg[$key])) {
            continue;
        }
        $js = fer_http($base . $ep . '?' . ($ep === 'SchoolHolidays' ? $qs : $q));
        $d = @json_decode((string) $js, true);
        if (!is_array($d)) {
            $fehler++;
            continue;
        }
        foreach ($d as $e) {
            if (!isset($e['startDate'])) {
                continue;
            }
            // Oertliche Feiertage (z. B. Augsburger Friedensfest) nur uebernehmen,
            // wenn der Arbeitsort passt oder ausdruecklich alle gewuenscht sind
            if ($ep === 'PublicHolidays' && isset($e['regionalScope']) && $e['regionalScope'] === 'Local') {
                $passt = !empty($cfg['local_holidays']);
                if (!$passt && (string) $cfg['locality'] !== '' && strpos((string) $cfg['locality'], '-') !== false) {
                    foreach ((array) (isset($e['subdivisions']) ? $e['subdivisions'] : array()) as $sd) {
                        if (isset($sd['code']) && $sd['code'] === $cfg['locality']) { $passt = true; break; }
                    }
                }
                if (!$passt) { continue; }
            }
            $name = fer_name($e, $lang);
            $rec = fer_rec($e, $name);
            if ($ep === 'SchoolHolidays') { $out['ferien'][] = $rec; } else { $out['feiertage'][] = $rec; }
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
    $sub2 = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string) $cfg['subdivision2']));
    if ($sub2 !== '' && $sub2 !== $sub && !empty($cfg['school'])) {
        $q2 = 'countryIsoCode=' . rawurlencode($land) . '&languageIsoCode=' . rawurlencode($lang)
            . '&subdivisionCode=' . rawurlencode($sub2)
            . '&validFrom=' . $von . '&validTo=' . $bis;
        $js2 = fer_http($base . 'SchoolHolidays?' . $q2);
        $d2 = @json_decode((string) $js2, true);
        if (is_array($d2)) {
            $out['sub2'] = $sub2;
            foreach ($d2 as $e) {
                if (!isset($e['startDate'])) { continue; }
                $name = fer_name($e, $lang);
                $out['ferien2'][] = fer_rec($e, $name);
            }
            usort($out['ferien2'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
        } else {
            $fehler++;
        }
    }
    if ($fehler && !$out['ferien'] && !$out['feiertage']) {
        if (is_file($f)) {
            @touch($f);
            fer_log('Abruf fehlgeschlagen - nutze gespeicherte Daten');
            return array(1, 'cache-fallback');
        }
        fer_log('Abruf FEHLGESCHLAGEN und keine Daten gespeichert');
        return array(0, 'FEHLGESCHLAGEN');
    }
    usort($out['ferien'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
    usort($out['feiertage'], function ($a, $b) { return strcmp($a['von'], $b['von']); });
    fer_json_schreiben($f, $out);
    @unlink(fer_tmpdir() . '/state.json');
    fer_log('Daten abgerufen: ' . count($out['ferien']) . ' Ferienzeitraeume, '
        . count($out['feiertage']) . ' Feiertage (' . $land . ($sub !== '' ? '/' . $sub : '')
        . ($gruppe !== '' ? ', Schulart ' . $gruppe : '') . ', bis ' . $bis . ')'
        . ($out['sub2'] !== '' ? ' + ' . count($out['ferien2']) . ' Ferienzeitraeume fuer ' . $out['sub2'] : ''));
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
 * Den Kalender holen und zwischenspeichern.
 *
 * Der Zwischenspeicher liegt unter data/ und NICHT unter /tmp: /tmp ist auf
 * dem LoxBerry eine Ramdisk, und nach jedem Neustart waeren die Termine weg,
 * bis der Kalender wieder erreichbar ist. Faellt der Abruf aus, gilt der
 * letzte erfolgreiche Stand weiter - dieselbe Ueberlegung wie bei den
 * Ferien selbst.
 */
function fer_ics_holen($force = false) {
    $cfg = fer_config();
    $url = trim((string) $cfg['ics_url']);
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return array(); }
    $f = fer_datadir() . '/kalender.json';
    if (!$force && is_file($f) && time() - filemtime($f) < 6 * 3600) {
        $c = json_decode((string) file_get_contents($f), true);
        if (is_array($c) && isset($c['termine'])) { return (array) $c['termine']; }
    }
    $roh = fer_http($url, 20);
    if ($roh === false || stripos((string) $roh, 'BEGIN:VCALENDAR') === false) {
        fer_log_if_changed('ics', 'Kalender nicht erreichbar oder keine ICS-Datei: ' . $url);
        if (is_file($f)) {
            $c = json_decode((string) file_get_contents($f), true);
            if (is_array($c) && isset($c['termine'])) { return (array) $c['termine']; }
        }
        return array();
    }
    $termine = fer_ics_lesen($roh, trim((string) $cfg['ics_filter']));
    fer_json_schreiben($f, array('stand' => date('c'), 'termine' => $termine));
    fer_log_if_changed('ics', count($termine) . ' Termine aus dem Kalender uebernommen');
    return $termine;
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
    $f = fer_datafile();
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($d)) {
        $d = array('ferien' => array(), 'feiertage' => array(), 'bis' => '', 'stand' => '');
    }
    // Eigene Zeitraeume ergaenzen
    if (!isset($d['urlaub']) || !is_array($d['urlaub'])) { $d['urlaub'] = array(); }
    if (!isset($d['ferien2']) || !is_array($d['ferien2'])) { $d['ferien2'] = array(); }
    $cfg = fer_config();

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
 * fer_match() darunter bleibt unveraendert - es gibt weiterhin den Namen
 * zurueck, und alle bisherigen Aufrufstellen rechnen damit weiter. */
function fer_match_e($liste, $tag) {
    foreach ((array) $liste as $e) {
        if ($tag >= $e['von'] && $tag <= $e['bis']) {
            return $e;
        }
    }
    return null;
}

/** Trifft ein Zeitraum auf das Datum (Y-m-d) zu? Rueckgabe: Name oder ''. */
function fer_match($liste, $tag) {
    $e = fer_match_e($liste, $tag);
    return $e === null ? '' : (string) $e['name'];
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

/** Alle Kennzahlen eines Tages. */
function fer_day($d, $tag, $cfg = null) {
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

/** Kompletter Zustand (Cache bis Tageswechsel). */
function fer_state($force = false) {
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
        'ok' => ($d['ferien'] || $d['feiertage']) ? 1 : 0,
        'heute' => fer_day($d, $heute),
        'morgen' => fer_day($d, date('Y-m-d', strtotime('+1 day'))),
        'stand' => isset($d['stand']) ? $d['stand'] : '',
        'reicht_bis' => isset($d['bis']) ? $d['bis'] : '',
        'warnung' => 0,
        'ts' => time(),
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
    $st['merster_schultag'] = ($st['heute']['ferien'] && $st['morgen']['schultag']) ? 1 : 0;

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
    // Warnung, wenn die Daten bald auslaufen
    if ($st['ok'] && $st['reicht_bis'] !== '' && $st['reicht_bis'] < date('Y-m-d', strtotime('+60 days'))) {
        $st['warnung'] = 1;
    }
    fer_json_schreiben($cache, $st);
    fer_log_if_changed('zustand', 'heute frei=' . $st['heute']['schulfrei'] . ' (' . $st['heute']['ferien_name']
        . $st['heute']['feiertag_name'] . ') | morgen frei=' . $st['morgen']['schulfrei']
        . ' | Urlaub=' . $st['urlaub']['aktiv'] . ($st['urlaub']['aktiv'] ? ' (' . $st['urlaub']['name'] . ', noch ' . $st['urlaub']['rest'] . ' Tage)' : ''));
    return $st;
}

/* ---------------- MQTT ---------------- */

function fer_mqtt_publish($st = null) {
    $cfg = fer_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = fer_paths();
    if ($p['lbhome'] === '') {
        return;
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
        return;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'ferien';

    /* Die Themenliste entsteht aus fer_felder() - siehe den langen Kommentar
     * dort. Bis 1.1.7 stand hier eine zweite, von Hand gepflegte Liste; sie
     * war um vier Werte kuerzer als die des HTTP-Weges, und weil beide auf
     * 27 Eintraege kamen, ist es niemandem aufgefallen. */
    $m = array();
    $felder = fer_felder();
    foreach (fer_werte($st) as $name => $wert) {
        if (isset($felder[$name][5]) && $felder[$name][5] !== '') {
            $m[$felder[$name][5]] = $wert;
        }
    }
    // Dazu die Textwerte, die ein virtueller HTTP-Eingang nicht lesen kann.
    $m = array_merge($m, fer_mqtt_texte($st));

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
        return;
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        fer_log_if_changed('mqtt', 'UDP-Socket liess sich nicht anlegen -'
            . ' fehlt die PHP-Erweiterung sockets?');
        return;
    }
    $gesendet = 0;
    foreach ($m as $k => $v) {
        $msg = 'publish ' . fer_mqtt_thema($prefix . '/' . $k) . ' ' . fer_mqtt_nutzlast($v);
        if (@socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport) !== false) {
            $gesendet++;
        }
    }
    socket_close($s);
    fer_log_if_changed('mqtt', $gesendet . ' von ' . count($m) . ' Werten gesendet (Port ' . $udpport . ')');
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
    return array(
        'name' => ($st['heute']['feiertag_name'] !== '' ? $st['heute']['feiertag_name']
                  : ($st['heute']['ferien_name'] !== '' ? $st['heute']['ferien_name'] : '-')),
        'ferien_name'   => $st['naechste']['name'] !== '' ? $st['naechste']['name'] : '-',
        'urlaub_name'   => $st['urlaub']['name'] !== '' ? $st['urlaub']['name'] : '-',
        'feiertag_name' => $st['feiertag_naechster']['name'] !== '' ? $st['feiertag_naechster']['name'] : '-',
        'ferien2_name'  => $st['naechste2']['name'] !== '' ? $st['naechste2']['name'] : '-',
        'feiertag_hinweis' => $st['heute']['hinweis'] !== '' ? $st['heute']['hinweis'] : '-',
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
function fer_selbsttest($basis = '') {
    $cfg = fer_config();
    $z = array();
    $add = function ($frage, $ok, $hinweis = '') use (&$z) {
        $z[] = array('frage' => $frage, 'ok' => $ok, 'hinweis' => $hinweis);
    };

    /* --- 1. Die Daten selbst ------------------------------------------- */
    $d = fer_data();
    $nf = count((array) $d['ferien']);
    $nh = count((array) $d['feiertage']);
    $add('Sind Ferien- oder Feiertagsdaten vorhanden?', ($nf + $nh) > 0,
        $nf . ' Ferienzeitraeume, ' . $nh . ' Feiertage');

    // Leere Ferienliste erklaeren - aber nur, wenn die Erklaerung stimmt.
    if ($nf === 0) {
        $add('Keine Ferien gefunden - ist das erklaerbar?',
            empty($cfg['school']) ? null : false,
            empty($cfg['school'])
                ? 'Schulferien sind in den Einstellungen abgeschaltet, das ist also richtig so.'
                : 'Schulferien sind eingeschaltet, es kamen aber keine. Region pruefen und neu abrufen.');
    }

    $st = fer_state();
    $reicht = (string) $st['reicht_bis'];
    $add('Reichen die Daten weit genug in die Zukunft?', empty($st['warnung']),
        $reicht !== '' ? 'bis ' . $reicht : 'kein Enddatum in der Termindatei');

    $f = fer_datafile();
    $alter = is_file($f) ? (int) floor((time() - filemtime($f)) / 86400) : -1;
    $add('Wann wurden die Daten zuletzt geholt?', $alter >= 0 && $alter <= 8,
        $alter < 0 ? 'noch nie - der Abruf ist nie durchgelaufen'
                   : 'vor ' . $alter . ' Tagen (der Cron holt woechentlich nach)');

    /* --- 2. Die Schnittstelle zu Loxone -------------------------------- */
    $verdeckt = fer_reihenfolge_pruefen();
    $add('Verdeckt in der Loxone-Zeile ein Feld ein anderes?', count($verdeckt) === 0,
        count($verdeckt) === 0
            ? count(fer_felder()) . ' Felder geprueft, jedes findet sich selbst zuerst'
            : 'verdeckt: ' . implode(', ', $verdeckt));

    // Deckt der MQTT-Weg alles ab, was der HTTP-Weg fuehrt?
    $ohne = array();
    foreach (fer_felder() as $name => $fd) {
        if (!isset($fd[5]) || $fd[5] === '') { $ohne[] = $name; }
    }
    $add('Traegt der MQTT-Weg dieselben Werte wie der HTTP-Weg?', count($ohne) === 0,
        count($ohne) === 0
            ? count(fer_felder()) . ' Felder, dazu ' . count(fer_mqtt_texte($st)) . ' Textwerte, die es nur ueber MQTT gibt'
            : 'ohne MQTT-Thema: ' . implode(', ', $ohne));

    // Ist die Importdatei fuer Loxone Config wohlgeformt?
    $vorlage = fer_vorlage();
    $wohl = false;
    if (function_exists('simplexml_load_string')) {
        $vorher = libxml_use_internal_errors(true);
        $wohl = simplexml_load_string($vorlage[1]) !== false;
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
    }
    $add('Ist die Importdatei fuer Loxone Config wohlgeformt?',
        function_exists('simplexml_load_string') ? $wohl : null,
        function_exists('simplexml_load_string')
            ? $vorlage[0] . ', ' . strlen($vorlage[1]) . ' Zeichen'
            : 'simplexml fehlt in dieser PHP-Installation - nicht pruefbar');

    /* --- 3. Der eigene Endpunkt, wirklich aufgerufen -------------------- */
    $soll = (string) $cfg['aktionstoken'];
    $add('Ist ein Aktionstoken eingerichtet?', $soll !== '',
        $soll !== '' ? 'ja - ?say= und ?ptest= sind damit geschuetzt'
                     : 'nein - die Oberflaeche legt beim naechsten Aufruf eines an');

    if ($basis !== '' && $soll !== '') {
        /* Drei Sekunden, nicht acht: diese Pruefung laeuft bei jedem
         * Seitenaufbau des Reiters, und im Fehlerfall wartet der Anwender
         * sonst vor einer leeren Seite. Die zweite Frage wird nur gestellt,
         * wenn die erste ueberhaupt eine Antwort bekommen hat. */
        $antwort = fer_http(rtrim($basis, '/') . '/ferien.php?selftest=1&token=' . rawurlencode($soll), 3);
        $erreicht = ($antwort !== false && $antwort !== '');
        $add('Antwortet der eigene Endpunkt?', $erreicht ? (strpos((string) $antwort, 'OK=1') !== false) : null,
            $erreicht ? trim((string) $antwort)
                      : 'keine Antwort in 3 s. Am Geraet ist das ein Befund; in einem '
                      . 'Pruefaufbau mit eingebautem PHP-Server dagegen normal, weil der '
                      . 'nur eine Anfrage zugleich bedienen kann.');
        if ($erreicht) {
            $falsch = fer_http(rtrim($basis, '/') . '/ferien.php?selftest=1&token=falsch', 3);
            $add('Weist der Endpunkt ein falsches Token ab?',
                $falsch === false || strpos((string) $falsch, 'ERR=TOKEN') !== false,
                'erwartet wird HTTP 403 mit ERR=TOKEN');
        }
    }

    /* --- 4. MQTT ------------------------------------------------------- */
    if (!empty($cfg['mqtt_enabled'])) {
        $add('Ist die PHP-Erweiterung sockets vorhanden?', function_exists('socket_create'),
            function_exists('socket_create') ? 'ja' : 'nein - ohne sie geht ueber MQTT nichts hinaus');
        $auto = fer_mqtt_gateway_autostart();
        $add('Startet das MQTT-Gateway automatisch mit?', $auto === null ? null : $auto,
            $auto === null ? 'general.json nicht lesbar - nicht pruefbar'
                           : ($auto ? 'ja' : 'nein - nach einem Neustart kommt nichts an'));
        $p = fer_paths();
        $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
        $port = 0;
        if (isset($gen['Mqtt']) && is_array($gen['Mqtt']) && isset($gen['Mqtt']['Udpinport'])) {
            $port = (int) $gen['Mqtt']['Udpinport'];
        }
        $add('Steht ein UDP-Eingangsport des Gateways fest?', $port >= 1 && $port <= 65535,
            $port ? 'Port ' . $port : 'keiner gefunden - ist das Gateway eingerichtet?');
    } else {
        $add('MQTT', null, 'nicht eingeschaltet - die Zeilen dazu entfallen');
    }

    /* --- 5. Die neuen Wege ---------------------------------------------- */
    if (trim((string) $cfg['ics_url']) !== '') {
        $k = fer_ics_holen();
        $add('Liefert das Kalender-Abonnement Termine?', count($k) > 0,
            count($k) . ' Ganztagstermine uebernommen (Termine mit Uhrzeit und'
            . ' Wiederholungen werden bewusst uebergangen)');
    }
    if (trim((string) $cfg['subdivision2']) !== '') {
        $n2 = count((array) (isset($d['ferien2']) ? $d['ferien2'] : array()));
        $add('Liefert die zweite Region Ferien?', $n2 > 0,
            $n2 . ' Zeitraeume fuer ' . $cfg['subdivision2']);
    }

    /* --- 6. Ansage ------------------------------------------------------ */
    if (!empty($cfg['notify']['audio'])) {
        $url = fer_tts_url('Probe');
        $add('Laesst sich eine Ansage-Adresse bilden?', $url === null ? null : ($url !== ''),
            $url === null ? 'Modus "Original Loxone Audioserver" - die Ansage macht Loxone selbst'
                          : ($url !== '' ? 'ja' : 'nein - es fehlt die IP des Audio-Servers'));
    }

    return $z;
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
    $o .= '<VirtualInHttp HintText="" ';
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
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function fer_vx($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Hausstandard: Gateway-Autostart aus general.json (PLUGIN_HAUSREGELN Abschnitt 3). */
function fer_mqtt_gateway_autostart() {
    $home = getenv('LBHOMEDIR') ?: '/opt/loxberry';
    $gj = $home . '/config/system/general.json';
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
        $cmds[] = array(
            'title' => 'FERIEN_' . $name,
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => fer_check($name),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_ferien.xml', fer_xml_virtual_in_http(array(
        'title' => 'Ferien und Feiertage',
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
    @mkdir(dirname($p['config']), 0775, true);
    return (bool) (@file_put_contents($p['config'], $js) !== false);
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
 */
function fer_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(fer_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = fer_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(fer_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = fer_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
