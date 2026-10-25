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
 *
 * Seit 1.2.28 (Gruppe 19, Entscheidung 2) antwortet dieser Endpunkt nur mit dem Haken "HTTP-Weg fuer Loxone annehmen"
 * (endpunkt_ein, Reiter Einbindung): Neuinstallation aus, Update an. Abgeschaltet antwortet JEDE Art mit HTTP 403 und
 * "<ART>;OK=0;ERR=ENDPUNKT_AUS" - nie JSON mit 'heute': Abfahrts-Assistent und AWM-Abfuhr fallen dann auf die
 * Bibliothek zurueck. ?json/?debug mit Urlaubsnamen sind damit fuer Neuanlagen nicht mehr unangemeldet lesbar; die
 * Oberflaeche zeigt dieselben Ansichten hinter der Anmeldung (index.php?ansicht=..., fer_ansicht()).
 */

require_once __DIR__ . '/ferien_lib.php';

/* C16 (1.2.16): die Bibliothek stellt beim Einbinden keine Zeitzone mehr ein.
 * Dieser Endpunkt ist ein eigener Prozess und rechnet in Europe/Berlin. */
date_default_timezone_set('Europe/Berlin');

/* endpunkt_ein (1.2.28, Hausform G9-G18, Entscheidung 2, E-E16): VOR ?refresh und vor der Leser-Merkung - ein
 * abgewiesener Aufruf wird nicht als Leser vermerkt und fragt die Quelle nicht. Kein Protokoll je Aufruf (Loxone
 * fragt zyklisch), kein Zugriff auf den Zustand. Die Lese-Regel (Bestand ohne Schluessel -> 1) steht in fer_config(). */
$fer_ep_cfg = fer_config();
if ((int) (isset($fer_ep_cfg['endpunkt_ein']) ? $fer_ep_cfg['endpunkt_ein'] : 0) !== 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $fer_ep_art = isset($_GET['selftest']) ? 'SELFTEST' : (isset($_GET['say']) ? 'SAY'
        : (isset($_GET['ptest']) ? 'PTEST' : (isset($_GET['refresh']) ? 'REFRESH' : 'FERIEN')));
    echo $fer_ep_art . ";OK=0;ERR=ENDPUNKT_AUS\n";
    exit;
}

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
    /* Seit 1.2.28 aus fer_ansicht() (N2): ohne Daten der Quelle 503 und {"ok":0,"grund":"KEINE_DATEN"} statt eines
     * Zustands aus eigenen Terminen (C5) - Abfahrts-Assistent und AWM-Abfuhr lesen dann ueber die Bibliothek; ann aus
     * fer_meldeflags() (1.2.26). Ausgabe byte-gleich mit 1.2.27. */
    list($fer_ac, $fer_typ, $fer_at) = fer_ansicht('json', $fer_refresh);
    header('Content-Type: ' . $fer_typ);
    if ($fer_ac !== 200) { http_response_code($fer_ac); }
    echo $fer_at;
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
    /* Gruppe 1 / MQTT (1.2.27; Entwurf ff E8): die Verarbeitung steht in fer_befehl_ansage() - derselben Funktion,
     * die die Befehlsbruecke fuer ferien/befehl/ansage ruft. Antwort unveraendert. */
    $fer_b = fer_befehl_ansage('http');
    $text = $fer_b['text'];
    /* Nr. 36 b, Stufe 2: gesprochen ueber die gemeinsame Sprachausgabe; die Antwort
     * bleibt in der Form bis 1.2.22 (fer_say_zusatz()). Vom Ansagetext nur seine Laenge;
     * beim Original-Audioserver ist der Text die Antwort (Loxone Config spricht ihn ueber
     * den Textgenerator). */
    $r = $fer_b['r'];
    $ok = $r['stand'] === 1;
    $zus = fer_say_zusatz($r);
    if ($r['art'] === 'audioserver') {
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
    /* Merker, Protokollzeile und sofortige MQTT-Meldung stehen seit 1.2.27 in fer_befehl_testpush() - derselben
     * Funktion, die die Befehlsbruecke fuer ferien/befehl/testpush ruft (Gruppe 1, Entwurf ff E8). Sofort melden,
     * statt bis zu einer Minute auf den Cron zu warten: ueber HTTP holt sich der Miniserver den Merker beim naechsten
     * Abruf; ueber MQTT muss ihn das Plugin schicken - und ein Test, der erst eine Minute spaeter wirkt, sieht aus wie
     * ein Test, der nicht wirkt. Antwort unveraendert. */
    fer_befehl_testpush('http');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 4) verdrahtet ist.\n";
    exit;
}

/* Kein Abruf an dieser Stelle (C5): Zeile und ?debug=1 lesen den gespeicherten Stand. Seit 1.2.28 (N2, E-E8) aus
 * fer_ansicht() - derselben Stelle, die die Oberflaeche hinter der Anmeldung nutzt (index.php?ansicht=zeile|debug);
 * die Ausgabe ist byte-gleich mit 1.2.27 (Vorher/Nachher-Probe). Die Zeile entsteht aus fer_zeile() und damit aus
 * fer_felder(); die REIHENFOLGE der Felder ist Teil der Schnittstelle (Loxone nimmt den ersten Treffer von "FERIEN=",
 * jedes Heute-Feld steht vor seinem M-Gegenstueck - geprueft von fer_reihenfolge_pruefen(), Reiter Test). Ohne Daten
 * der Quelle HTTP 503 und nur "FERIEN;OK=0;GRUND=KEINE_DATEN" (C5, Regeln/07): Loxone behaelt seine letzten Werte. */
list($fer_ac, , $fer_at) = fer_ansicht(isset($_GET['debug']) ? 'debug' : 'zeile', $fer_refresh, $fer_quelle);
if ($fer_ac !== 200) { http_response_code($fer_ac); }
echo $fer_at;
