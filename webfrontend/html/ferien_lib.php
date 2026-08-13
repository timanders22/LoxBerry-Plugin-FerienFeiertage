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
    $cfg += array(
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
    );
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
    $out = array('von' => $von, 'bis' => $bis, 'stand' => date('c'),
                 'land' => $land, 'sub' => $sub, 'ferien' => array(), 'feiertage' => array());
    $fehler = 0;
    foreach (array('school' => 'SchoolHolidays', 'public' => 'PublicHolidays') as $key => $ep) {
        if (empty($cfg[$key])) {
            continue;
        }
        $js = fer_http($base . $ep . '?' . $q);
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
            $name = '';
            if (isset($e['name'][0]['text'])) {
                foreach ($e['name'] as $n) {
                    if (isset($n['language']) && strtoupper($n['language']) === $lang) { $name = $n['text']; break; }
                }
                if ($name === '') { $name = $e['name'][0]['text']; }
            }
            $rec = array('von' => substr($e['startDate'], 0, 10),
                         'bis' => substr(isset($e['endDate']) ? $e['endDate'] : $e['startDate'], 0, 10),
                         'name' => $name);
            if ($ep === 'SchoolHolidays') { $out['ferien'][] = $rec; } else { $out['feiertage'][] = $rec; }
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
        . count($out['feiertage']) . ' Feiertage (' . $land . ($sub !== '' ? '/' . $sub : '') . ', bis ' . $bis . ')');
    return array(1, 'frisch');
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
    $cfg = fer_config();
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
    if ($d['urlaub']) {
        usort($d['urlaub'], function ($x, $y) { return strcmp($x['von'], $y['von']); });
    }
    return fer_locality_fix($d);
}

/* ---------------- Auswertung ---------------- */

/** Trifft ein Zeitraum auf das Datum (Y-m-d) zu? Rueckgabe: Name oder ''. */
function fer_match($liste, $tag) {
    foreach ((array) $liste as $e) {
        if ($tag >= $e['von'] && $tag <= $e['bis']) {
            return (string) $e['name'];
        }
    }
    return '';
}

/** Ist der Tag ein Werktag (Mo-Fr)? */
function fer_werktag($tag) {
    $w = (int) date('N', strtotime($tag));
    return $w <= 5;
}

/**
 * Brueckentag: Werktag, der zwischen einem Feiertag und dem Wochenende liegt
 * (Freitag nach Donnerstag-Feiertag bzw. Montag vor Dienstag-Feiertag).
 */
function fer_bridge($d, $tag) {
    if (!fer_werktag($tag) || fer_match($d['feiertage'], $tag) !== '') {
        return 0;
    }
    $w = (int) date('N', strtotime($tag));
    $vor = date('Y-m-d', strtotime($tag . ' -1 day'));
    $nach = date('Y-m-d', strtotime($tag . ' +1 day'));
    if ($w === 5 && fer_match($d['feiertage'], $vor) !== '') { return 1; } // Fr nach Do-Feiertag
    if ($w === 1 && fer_match($d['feiertage'], $nach) !== '') { return 1; } // Mo vor Di-Feiertag
    return 0;
}

/** Alle Kennzahlen eines Tages. */
function fer_day($d, $tag) {
    $ferien = fer_match($d['ferien'], $tag);
    $urlaub = fer_match(isset($d['urlaub']) ? $d['urlaub'] : array(), $tag);
    $feiertag = fer_match($d['feiertage'], $tag);
    $we = !fer_werktag($tag);
    $frei = ($ferien !== '' || $feiertag !== '' || $we) ? 1 : 0;
    return array(
        'datum' => $tag,
        'ferien' => $ferien !== '' ? 1 : 0,
        'ferien_name' => $ferien,
        'feiertag' => $feiertag !== '' ? 1 : 0,
        'feiertag_name' => $feiertag,
        'wochenende' => $we ? 1 : 0,
        'schulfrei' => $frei,
        'schultag' => $frei ? 0 : 1,
        'bruecke' => fer_bridge($d, $tag),
        'urlaub' => $urlaub !== '' ? 1 : 0,
        'urlaub_name' => $urlaub,
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
    // Naechster Feiertag
    $st['feiertag_naechster'] = array('name' => '', 'datum' => '', 'in' => -1);
    foreach ((array) $d['feiertage'] as $e) {
        if ($e['bis'] < $heute) {
            continue;
        }
        $st['feiertag_naechster'] = array('name' => $e['name'], 'datum' => $e['von'],
            'in' => max(0, (int) round((strtotime($e['von']) - strtotime($heute)) / 86400)));
        break;
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
            if (fer_bridge($d, $t)) {
                $st['brueckentage'][] = $t;
            }
        }
    }
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
    $m = array(
        'ok' => $st['ok'], 'warnung' => $st['warnung'],
        'ferien' => $st['heute']['ferien'], 'feiertag' => $st['heute']['feiertag'],
        'schulfrei' => $st['heute']['schulfrei'], 'schultag' => $st['heute']['schultag'],
        'bruecke' => $st['heute']['bruecke'],
        'name' => ($st['heute']['feiertag_name'] !== '' ? $st['heute']['feiertag_name']
                  : ($st['heute']['ferien_name'] !== '' ? $st['heute']['ferien_name'] : '-')),
        'morgen_schulfrei' => $st['morgen']['schulfrei'], 'morgen_schultag' => $st['morgen']['schultag'],
        'morgen_feiertag' => $st['morgen']['feiertag'], 'morgen_ferien' => $st['morgen']['ferien'],
        'ferien_in' => $st['naechste']['in'], 'ferien_rest' => $st['naechste']['rest'],
        'ferien_name' => $st['naechste']['name'] !== '' ? $st['naechste']['name'] : '-',
        'urlaub' => $st['heute']['urlaub'], 'morgen_urlaub' => $st['morgen']['urlaub'],
        'urlaub_in' => $st['urlaub']['in'], 'urlaub_rest' => $st['urlaub']['rest'],
        'urlaub_letzter_tag' => $st['urlaub']['letzter_tag'],
        'urlaub_name' => $st['urlaub']['name'] !== '' ? $st['urlaub']['name'] : '-',
        'feiertag_in' => $st['feiertag_naechster']['in'],
        'feiertag_name' => $st['feiertag_naechster']['name'] !== '' ? $st['feiertag_naechster']['name'] : '-',
    );
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
    if ((string) $tts['ip'] === '') {
        return '';
    }
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

function fer_ptest_active() {
    $f = fer_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
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

/* ---------------- Loxone-Vorlage (Hausstandard "Alles auf einmal anlegen") ---------------- */
/** name => array(analog, min, max, einheit, kommentar).
 *  Die Suchmuster funktionieren nur, weil in der Ausgabezeile jedes
 *  Heute-Feld VOR seinem M-Gegenstueck steht (siehe Kommentar in ferien.php). */
function fer_felder() {
    return array(
        'OK'          => array(0, 0, 1,   '',     '1 = Daten gueltig'),
        'FERIEN'      => array(0, 0, 1,   '',     'heute Ferien'),
        'FEIERTAG'    => array(0, 0, 1,   '',     'heute Feiertag'),
        'WOCHENENDE'  => array(0, 0, 1,   '',     'heute Wochenende'),
        'SCHULFREI'   => array(0, 0, 1,   '',     'heute schulfrei'),
        'SCHULTAG'    => array(0, 0, 1,   '',     'heute Schultag'),
        'BRUECKE'     => array(0, 0, 1,   '',     'heute Brueckentag'),
        'MFERIEN'     => array(0, 0, 1,   '',     'morgen Ferien'),
        'MFEIERTAG'   => array(0, 0, 1,   '',     'morgen Feiertag'),
        'MSCHULFREI'  => array(0, 0, 1,   '',     'morgen schulfrei'),
        'MSCHULTAG'   => array(0, 0, 1,   '',     'morgen Schultag'),
        'MBRUECKE'    => array(0, 0, 1,   '',     'morgen Brueckentag'),
        'FERIENIN'    => array(1, 0, 365, 'Tage', 'naechste Ferien beginnen in'),
        'FERIENREST'  => array(1, 0, 365, 'Tage', 'laufende Ferien: Resttage'),
        'FERIENDAUER' => array(1, 0, 365, 'Tage', 'naechste/laufende Ferien: Dauer'),
        'FEIERTAGIN'  => array(1, 0, 365, 'Tage', 'naechster Feiertag in'),
        'URLAUB'      => array(0, 0, 1,   '',     'heute Urlaub (eigene Termine)'),
        'MURLAUB'     => array(0, 0, 1,   '',     'morgen Urlaub'),
        'URLAUBIN'    => array(1, 0, 365, 'Tage', 'naechster Urlaub beginnt in'),
        'URLAUBREST'  => array(1, 0, 365, 'Tage', 'laufender Urlaub: Resttage'),
        'URLAUBDAUER' => array(1, 0, 365, 'Tage', 'Urlaub: Dauer'),
        'URLAUBENDE'  => array(0, 0, 1,   '',     'letzter Urlaubstag'),
        'WARN'        => array(0, 0, 1,   '',     'Warnhinweis aktiv'),
        'ANN'         => array(0, 0, 1,   '',     'Meldefenster aktiv'),
        'AUDIO'       => array(0, 0, 1,   '',     'Ansage freigegeben'),
        'PUSH'        => array(0, 0, 1,   '',     'Push freigegeben'),
        'PTEST'       => array(0, 0, 1,   '',     'Test-Push ausloesen'),
    );
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
        list($analog, $min, $max, $einheit, $text) = $f;
        $cmds[] = array(
            'title' => 'FERIEN_' . $name,
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => '\i' . $name . '=\i\v',
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
