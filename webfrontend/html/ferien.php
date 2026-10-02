<?php
/**
 * Ferien und Feiertage - Miniserver-Endpunkt
 *
 * Aufrufe:
 *   (ohne Parameter) -> eine Zeile FERIEN;NAME=WERT;... mit allen Feldern aus
 *                       fer_felder(). Welche das sind, steht dort und NUR dort -
 *                       diese Aufzaehlung waere sonst eine zweite Stelle, die
 *                       veralten kann.
 *
 *                       Die wichtigsten:
 *                       SCHULTAG=1       heute ist ein normaler Schul-/Arbeitstag
 *                       MSCHULTAG=1      MORGEN ist Schultag (Wecker, Abendlogik)
 *                       FREITAGE=n       freie Tage am Stueck ab heute - die Zahl,
 *                                        die ein Wochenende von den Sommerferien
 *                                        unterscheidet (Heizungsabsenkung)
 *                       MERSTERSCHULTAG=1 morgen geht die Schule wieder los
 *                       URLAUBHEIM=1     Vorwaermen zur Rueckkehr
 *                       BRUECKE=1        Brueckentag
 *                       WARN=1           Daten reichen weniger als 60 Tage voraus
 *   ?debug=1         -> Ferien- und Feiertagsliste im Klartext
 *   ?json=1          -> kompletter Zustand als JSON
 *   &quelle=NAME     -> zu jedem lesenden Aufruf: aendert die Antwort nicht,
 *                       vermerkt nur den Namen des Lesers fuer den Reiter Test
 *                       (seit dem Verbesserungsbau 01.10.2026)
 *
 * Ohne Daten der Quelle fuer die eingestellte Region antworten die Zeile und
 * ?json=1 mit HTTP 503 und GRUND=KEINE_DATEN (Regeln/07, seit 1.2.16) - nicht
 * mit 200 und Werten, die aus eigenen Terminen oder dem Wochentag geraten
 * sind. Mit Daten 200; OK=0 heisst dann: sie decken heute oder morgen nicht.
 *
 * Die Quelle wird in keinem abfragenden Aufruf gefragt (C5, seit 1.2.16):
 * abgerufen wird nur im Cron, ueber den Knopf "Jetzt abrufen" und ueber
 * ?refresh=1 mit Token. Bis 1.2.15 holte schon ein gewoehnlicher Aufruf ohne
 * Termindatei, und haengende Quelle hiess 40 s Wartezeit.
 *
 * Die Aufrufe, die etwas AUSLOESEN oder SCHREIBEN, verlangen ein Token aus dem
 * Reiter "Einbindung in Loxone". Ohne passendes Token antworten sie mit
 * HTTP 403. Die abfragenden Aufrufe bleiben offen - sie aendern nichts.
 *
 *   ?refresh=1&token=T -> Daten sofort neu abrufen (schreibt termine.json);
 *                         hoechstens einmal je 5 Minuten, sonst HTTP 429.
 *                         Bis 1.2.15 ohne Token und ohne Bremse (C6).
 *
 *   ?say=1&token=T     -> Test: Vorabend-Ansage abspielen
 *   ?ptest=1&token=T   -> Test-Pushnachricht ausloesen (PTEST=1 fuer 5 Minuten)
 *   ?selftest=1&token=T -> nur pruefen, ob das Token stimmt; loest nichts aus
 */

require_once __DIR__ . '/ferien_lib.php';

/* C16 (1.2.16): die Bibliothek stellt beim Einbinden keine Zeitzone mehr ein.
 * Dieser Endpunkt ist ein eigener Prozess und rechnet in Europe/Berlin. */
date_default_timezone_set('Europe/Berlin');

/** Ist ein gueltiges Aktionstoken mitgeschickt worden?
 *
 * Ohne eingerichtetes Token ist die Antwort NEIN - ein leeres Soll darf
 * nicht auf ein leeres Ist passen, sonst schuetzt die Pruefung genau die
 * Anlage nicht, bei der noch nie jemand ein Token gesetzt hat. Die
 * Oberflaeche legt beim ersten Aufruf eines an.
 *
 * C12 (1.2.16): is_string() VOR jeder Umwandlung, auf Soll und Ist. Bis
 * 1.2.15 ergab "token[]=x" eine Warnung "Array to string conversion" im
 * Ausgabestrom und damit HTTP 200 statt 403; ein Soll aus einer Sicherung mit
 * dem Token als Liste wurde zu "Array" und oeffnete den Endpunkt.
 */
function fer_token_ok() {
    $cfg = fer_config();
    $soll = (isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken'])) ? $cfg['aktionstoken'] : '';
    if ($soll === '') { return false; }
    $ist = isset($_GET['token']) ? $_GET['token'] : '';
    if (!is_string($ist)) { return false; }
    return hash_equals($soll, $ist);
}

/**
 * ?refresh=1 - nur mit Token und hoechstens einmal je 5 Minuten (C6).
 *
 * Der Aufruf fragt die fremde Quelle und schreibt termine.json. Bis 1.2.15
 * ging das ohne Token und ohne Bremse: ein flatternder Baustein erzeugte je
 * Aufruf zwei Anfragen an openholidaysapi.org, parallel zum Cron - und genau
 * auf diesem Weg entstand der gemessene Teilausfall (C3).
 * Rueckgabe: true = jetzt abrufen; sonst endet die Anfrage hier mit 403/429.
 */
function fer_refresh_pruefen() {
    if (!isset($_GET['refresh'])) { return false; }
    if (!fer_token_ok()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "REFRESH;OK=0;ERR=TOKEN\n";
        exit;
    }
    $merk = fer_tmpdir() . '/refresh_letzt';
    clearstatcache(true, $merk);
    $alter = is_file($merk) ? time() - (int) filemtime($merk) : 86400;
    if ($alter >= 0 && $alter < 300) {
        http_response_code(429);
        header('Retry-After: ' . (300 - $alter));
        header('Content-Type: text/plain; charset=utf-8');
        echo 'REFRESH;OK=0;ERR=GEBREMST;WARTEN=' . (300 - $alter) . "\n";
        exit;
    }
    @touch($merk);
    return true;
}

$fer_refresh = fer_refresh_pruefen();
$fer_quelle = '-';
if ($fer_refresh) {
    list($fer_rok, $fer_quelle) = fer_fetch(true);
}

/* Ferien-b1/Ferien-1 (Verbesserungsbau 01.10.2026): lesende Aufrufe (Zeile,
 * ?debug=1, ?json=1) werden fuer den Reiter Test vermerkt - wer, auf welchem
 * Weg, wann, mit welchem Status. ERST NACH der Antwort (Abschaltfunktion) und
 * ohne jede Ausgabe: die Antwort bleibt fuer jeden Leser dieselbe. Ausloesende
 * Aufrufe (?selftest, ?say, ?ptest) werden nicht vermerkt. Das Token wird nie
 * vermerkt (fer_leser_http()). */
$fer_leser_weg = isset($_GET['json']) ? 'json'
    : ((isset($_GET['selftest']) || isset($_GET['say']) || isset($_GET['ptest'])) ? '' : 'zeile');
if ($fer_leser_weg !== '' && function_exists('fer_leser_http')) {
    register_shutdown_function(function () use ($fer_leser_weg) { fer_leser_http($fer_leser_weg); });
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = fer_state($fer_refresh);
    /* Ohne Daten der Quelle: 503 statt eines Zustands aus eigenen Terminen
     * (C5). Der Abfahrts-Assistent und AWM-Abfuhr lesen dann ueber ihren
     * Rueckweg (Bibliothek) - ok=0 wie bisher. */
    if (empty($st['quelle_da'])) {
        http_response_code(503);
        echo json_encode(array('ok' => 0, 'grund' => 'KEINE_DATEN'), JSON_PRETTY_PRINT) . "\n";
        exit;
    }
    $st['ann'] = fer_ann_active($st);
    $st['ptest'] = fer_ptest_active();
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich etwas auszuloesen.
 */
if (isset($_GET['selftest'])) {
    $fe_cfg_st = fer_config();
    $fe_soll_st = (isset($fe_cfg_st['aktionstoken']) && is_string($fe_cfg_st['aktionstoken']))
        ? $fe_cfg_st['aktionstoken'] : '';
    if ($fe_soll_st === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    $fe_ist_st = isset($_GET['token']) ? $_GET['token'] : '';
    if (!is_string($fe_ist_st) || !hash_equals($fe_soll_st, $fe_ist_st)) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

if (isset($_GET['say'])) {
    /* Seit 1.1.7 tokenpflichtig: der Aufruf laesst das Haus sprechen. Ohne
     * Token konnte jedes Geraet im Heimnetz die Ansage ausloesen. */
    if (!fer_token_ok()) {
        http_response_code(403);
        echo "SAY;OK=0;ERR=TOKEN\n";
        exit;
    }
    $st = fer_state();
    $text = fer_announce_text($st);
    if ($text === '') {
        $text = 'Hallo! Dies ist eine Testansage des Ferien-Plugins. Morgen ist ein ganz normaler Tag.';
    }
    $ok = fer_say($text);
    /* Ansage-2: bei Alexa-NG nennt die Zeile im Fehlerfall HTTP-Code und GRUND
     * (nie das Token); die anderen Ausgabearten antworten wie bisher. */
    $zus = '';
    $cfg_s = fer_config();
    if (!$ok && $cfg_s['tts']['mode'] === 'alexang') {
        $l = fer_alexa_letzte();
        $zus = ';CODE=' . ($l !== null ? (int) $l['code'] : 0) . ';GRUND=' . fer_alexa_grund_kurz($l);
    }
    /* Ansage-3: bei Google-Lautsprecher nennt die Zeile immer HTTP-Code und GRUND
     * (UNVERAENDERT, TEXT_NULL, Fehler); statt des Ansagetexts nur seine Laenge
     * (wie im Protokoll). Die anderen Ausgabearten antworten wie bisher. */
    if ($cfg_s['tts']['mode'] === 'cc4lox') {
        $l = fer_alexa_letzte('google_letzte.json');
        echo 'SAY;OK=' . ($ok ? 1 : 0) . ';CODE=' . ($l !== null ? (int) $l['code'] : 0)
            . ';GRUND=' . fer_google_grund_kurz($l) . ';TEXTLAENGE=' . fer_google_textlaenge($text) . "\n";
        exit;
    }
    /* Nr. 36 b / Nr. 40: vom Ansagetext nur seine Laenge; beim Original-Audioserver ist der
     * Text die Antwort (Loxone Config spricht ihn ueber den Textgenerator). */
    if ($cfg_s['tts']['mode'] === 'audioserver') {
        echo 'SAY;OK=' . ($ok ? 1 : 0) . $zus . ";TEXT=$text\n";
    } else {
        echo 'SAY;OK=' . ($ok ? 1 : 0) . $zus . ';TEXTLAENGE=' . ansage_zeichen($text) . "\n";
    }
    exit;
}

if (isset($_GET['ptest'])) {
    /* Seit 1.1.7 tokenpflichtig - Hausstandard fuer alle Aktionsendpunkte.
     * Der Aufruf setzt PTEST=1 fuer fuenf Minuten; das Loxone-Programm
     * schickt daraufhin eine echte Pushnachricht, und seit dieser Fassung
     * geht zusaetzlich sofort eine MQTT-Meldung heraus. Ohne Token konnte
     * jedes Geraet im Netz dem Anwender Meldungen aufs Telefon schicken. */
    if (!fer_token_ok()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    @file_put_contents(fer_tmpdir() . '/ptest', '1');
    fer_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    /* Sofort melden, statt bis zu einer Minute auf den Cron zu warten.
     * Ueber HTTP holt sich der Miniserver den Merker beim naechsten Abruf;
     * ueber MQTT muss ihn das Plugin schicken - und ein Test, der erst eine
     * Minute spaeter wirkt, sieht aus wie ein Test, der nicht wirkt. */
    fer_mqtt_publish();
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 4) verdrahtet ist.\n";
    exit;
}

/* Kein Abruf mehr an dieser Stelle (C5): die Zeile liest den gespeicherten
 * Stand. Bis 1.2.15 stand hier fer_fetch() - ohne Termindatei ging damit
 * jeder Aufruf ins Netz. */
$st = fer_state($fer_refresh);
$cfg = fer_config();
/* Dieselbe Quelle wie die MQTT-Meldung - siehe fer_meldeflags(). */
$flags = fer_meldeflags($st);
$fer_ohne = empty($st['quelle_da']);
if ($fer_ohne) {
    http_response_code(503);
}

if (isset($_GET['debug'])) {
    $d = fer_data();
    echo 'DEBUG  Region: ' . fe_e_klar($cfg['country']) . '/' . fe_e_klar($cfg['subdivision'])
       . '  Abruf: ' . $fer_quelle
       . '  Stand: ' . substr((string) $st['stand'], 0, 19)
       . '  zuletzt gelungen: ' . ($st['stand_ok'] !== '' ? substr((string) $st['stand_ok'], 0, 19) : '-')
       . ' (' . (int) $st['alter_tage'] . ' Tage)'
       . (!empty($st['teilausfall']) ? '  Teilausfall: ' . implode(', ', (array) $st['teilausfall']) : '')
       . '  Daten bis: ' . $st['reicht_bis'] . "\n";
    echo 'HEUTE  ' . $st['heute']['datum'] . ': schulfrei=' . $st['heute']['schulfrei']
       . ' Ferien=' . ($st['heute']['ferien_name'] !== '' ? $st['heute']['ferien_name'] : '-')
       . ' Feiertag=' . ($st['heute']['feiertag_name'] !== '' ? $st['heute']['feiertag_name'] : '-')
       . ' Brueckentag=' . $st['heute']['bruecke'] . "\n";
    echo 'MORGEN ' . $st['morgen']['datum'] . ': schulfrei=' . $st['morgen']['schulfrei']
       . ' Ferien=' . ($st['morgen']['ferien_name'] !== '' ? $st['morgen']['ferien_name'] : '-')
       . ' Feiertag=' . ($st['morgen']['feiertag_name'] !== '' ? $st['morgen']['feiertag_name'] : '-') . "\n\n";
    echo "Kommende Ferien:\n";
    foreach ((array) $d['ferien'] as $e) {
        if ($e['bis'] < date('Y-m-d')) { continue; }
        printf("  %s bis %s  %s%s%s\n", $e['von'], $e['bis'], $e['name'],
            !empty($e['ics']) ? '  (aus dem Kalender)' : (!empty($e['eigen']) ? '  (eigener Termin)' : ''),
            (isset($e['art']) && $e['art'] !== '' && $e['art'] !== 'School') ? '  [Art: ' . $e['art'] . ']' : '');
    }
    if (!empty($d['ferien2'])) {
        echo "\nKommende Ferien der zweiten Region (" . $cfg['subdivision2'] . "):\n";
        foreach ((array) $d['ferien2'] as $e) {
            if ($e['bis'] < date('Y-m-d')) { continue; }
            printf("  %s bis %s  %s\n", $e['von'], $e['bis'], $e['name']);
        }
    }
    echo "\nKommende Feiertage:\n";
    foreach ((array) $d['feiertage'] as $e) {
        if ($e['bis'] < date('Y-m-d')) { continue; }
        printf("  %s  %s%s%s%s\n", $e['von'], $e['name'],
            !empty($e['eigen']) ? '  (eigener Termin)' : '',
            (isset($e['art']) && $e['art'] !== '' && $e['art'] !== 'Public') ? '  [Art: ' . $e['art'] . ']' : '',
            !empty($e['halbtag']) ? ('  [halber Tag' . (!empty($e['hinweis']) ? ': ' . $e['hinweis'] : '') . ']') : '');
    }
    echo "\nUrlaub (Abwesenheit):\n";
    if (empty($d['urlaub'])) {
        echo "  keine Urlaubszeitraeume eingetragen\n";
    } else {
        foreach ((array) $d['urlaub'] as $e) {
            if ($e['bis'] < date('Y-m-d')) { continue; }
            printf("  %s bis %s  %s\n", $e['von'], $e['bis'], $e['name']);
        }
        printf("  aktiv=%d in=%d rest=%d letzter Tag=%d\n", $st['urlaub']['aktiv'],
            $st['urlaub']['in'], $st['urlaub']['rest'], $st['urlaub']['letzter_tag']);
    }
    if ($st['brueckentage']) {
        echo "\nBrueckentage der naechsten 12 Monate:\n  " . implode(', ', $st['brueckentage']) . "\n";
    }
    echo "\n";
}

/*
 * Die Zeile entsteht seit 1.2.0 aus fer_zeile() und damit aus fer_felder().
 *
 * Vorher stand hier ein printf mit 27 Platzhaltern und 27 Argumenten in
 * getrennter Reihenfolge - die leichteste Art, allen Feldern hinter einer
 * verschobenen Stelle den falschen Wert zu geben, ohne dass irgendwo etwas
 * auffaellt. Jetzt stehen Name, Bedeutung, Grenzen, MQTT-Thema und Wert an
 * EINER Stelle, und die Loxone-Vorlage entsteht aus derselben.
 *
 * ACHTUNG - die REIHENFOLGE der Felder ist Teil der Schnittstelle.
 *
 * Loxone sucht in der Zeile die wortwoertliche Zeichenkette der
 * Befehlserkennung (z. B. "FERIEN=") und nimmt den ERSTEN Treffer. In dieser
 * Zeile steht "FERIEN=" aber auch als Teil von "MFERIEN=" - genauso
 * "FEIERTAG=" in "MFEIERTAG=", "SCHULFREI=" in "MSCHULFREI=" und so fort.
 * Es geht nur gut, weil jedes Heute-Feld VOR seinem M-Gegenstueck steht.
 *
 * Dieser Absatz war bis 1.1.7 eine Bitte an den naechsten Leser. Seit 1.2.0
 * prueft fer_reihenfolge_pruefen() es an der fertigen Zeile nach, und der
 * Reiter Test zeigt das Ergebnis - eine Regel, die ein Werkzeug prueft, ist
 * hinterlegt; eine Regel in Prosa ist eine Hoffnung.
 */
if ($fer_ohne) {
    /* 503 ohne Daten (C5, Regeln/07): keine Werte, nur der Grund. Loxone
     * behaelt dann seine letzten Werte - auch vor dem ersten Abruf gibt es
     * kein SCHULTAG=1 mehr, das aus dem Wochentag geraten ist. */
    echo "FERIEN;OK=0;GRUND=KEINE_DATEN\n";
    exit;
}
echo fer_zeile($st, $flags);

/** Ein Wert fuer die Debug-Zeile - auch wenn er (aus einer alten Datei) keine
 *  Zeichenkette ist. */
function fe_e_klar($w) {
    return is_scalar($w) ? (string) $w : '?';
}
