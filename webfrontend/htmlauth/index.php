<?php
/**
 * Ferien und Feiertage - Admin-Oberflaeche (v1.2.1)
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Brueckentage |
 *         Kommende Ferien | Kommende Feiertage | Test | Logdateien
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein fe_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * DIESER BLOCK STAND BIS 1.1.7 WEIT UNTEN, UND DAS WAR EIN FEHLER.
 *
 * Aufgerufen wurde die Funktion in der Zeile unmittelbar hinter diesem
 * Kommentar, definiert war sie erst zweihundert Zeilen spaeter - und zwar
 * BEDINGT, in einem if. PHP zieht bedingt definierte Funktionen nicht nach
 * vorn. Ohne gesetztes LBHOMEDIR endete die Seite deshalb mit
 * "Call to undefined function lb_wurzel_ermitteln()", und es erschien gar
 * nichts. Betroffen war genau der Fall, den der Kommentar abdecken will.
 * Gemessen am 18.08.2026 mit PHP 7.4.33, mit Kontrollfall: mit LBHOMEDIR
 * laeuft die Seite, ohne stirbt sie in dieser Zeile.
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

$fe_lbhome = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
$fe_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($fe_lbhome && is_dir($fe_lbhome . '/config/plugins/' . $fe_plugin) === false) {
    $fe_plugin = basename(dirname(__DIR__));
    if (is_dir($fe_lbhome . '/config/plugins/' . $fe_plugin) === false) {
        $fe_plugin = 'ferien';
    }
}
if ($fe_lbhome) {
    $fe_sdk = $fe_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($fe_sdk)) {
        require_once $fe_sdk;
        require_once $fe_lbhome . '/libs/phplib/loxberry_web.php';
    }
    $fe_cfgdir = $fe_lbhome . '/config/plugins/' . $fe_plugin;
    $fe_bkfile = $fe_lbhome . '/config/plugins/' . $fe_plugin . '.backup.json';
    $fe_logfile = $fe_lbhome . '/log/plugins/' . $fe_plugin . '/ferien.log';
} else {
    $fe_cfgdir = dirname(dirname(__DIR__)) . '/config';
    $fe_bkfile = $fe_cfgdir . '/ferien.backup.json';
    $fe_logfile = sys_get_temp_dir() . '/ferien/ferien.log';
}
$fe_cfgfile = $fe_cfgdir . '/ferien.json';

foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $fe_plugin . '/ferien_lib.php',
    dirname(__DIR__) . '/html/ferien_lib.php',
) as $fe_cand) {
    if (is_file($fe_cand)) { require_once $fe_cand; break; }
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* Fehlt die Bibliothek, hier abbrechen - und zwar mit einem Satz, den man
 * lesen kann.
 *
 * Bis 1.1.7 stand fuer diesen Fall weiter unten ein freundlicher Hinweis in
 * zwei Reitern ("Die Bibliothek des Plugins wurde nicht geladen"). Er war
 * unerreichbar: die Reiterleiste ruft fer_t() auf, und fer_t() steht in
 * genau der Bibliothek, die dann fehlt. Die Seite starb also lange vorher
 * mit "Call to undefined function fer_t()". Ein Sicherheitsnetz, das erst
 * hinter der Absturzstelle gespannt ist, ist keines.
 *
 * Der Text ist bewusst nicht uebersetzt: die Uebersetzung kaeme aus
 * derselben fehlenden Datei. */
if (!function_exists('fer_config')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Ferien und Feiertage</h2>'
       . '<p><b>Die Programmbibliothek <code>ferien_lib.php</code> wurde nicht gefunden.</b></p>'
       . '<p>Gesucht wurde in:</p><ul>';
    foreach (array(
        dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $fe_plugin . '/ferien_lib.php',
        dirname(__DIR__) . '/html/ferien_lib.php',
    ) as $fe_cand) {
        echo '<li><code>' . htmlspecialchars($fe_cand, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Das Plugin ist damit nicht arbeitsfaehig. Bitte neu installieren; '
       . 'die Konfiguration bleibt dabei erhalten.</p>';
    exit;
}

/* Die Selbstheilung - EINE Entscheidung, hier nur aufgerufen.
 *
 * Bis 1.2.12 stand an dieser Stelle eine wortgleiche Abschrift der
 * Entscheidung aus fer_config(): "fehlt die Datei, ist sie leer oder '{}'".
 * Zwei Abschriften laufen auseinander, und beide entschieden nach der FORM
 * der Datei statt nach ihrem INHALT. Eine abgeschnittene ferien.json ist
 * weder leer noch "{}" - sie ging an beiden vorbei, und der Block weiter
 * unten ("Beim ersten Aufruf ein Token erzeugen") wuerfelte daraufhin ein
 * neues Aktionstoken und kopierte es ueber die Zweitschrift. Gemessen am
 * 18.09.2026 in WSL/Ubuntu unter PHP 8.3.6; die Einzelheiten stehen bei
 * fer_selbstheilung() in ferien_lib.php.
 *
 * Der function_exists-Vorbehalt ist der desselben Hauses wie in den
 * Handlern darunter: html/ und htmlauth/ liegen auf dem Geraet in
 * getrennten Baeumen, und ein Upgrade kann den einen vor dem anderen
 * erneuern. Fehlt die Funktion, wird NICHT geheilt - der Schutz faellt
 * geschlossen aus, statt auf die alte Bauart zurueckzufallen. */
/* C16 (1.2.16): die Bibliothek stellt beim Einbinden keine Zeitzone mehr ein;
 * diese Seite ist ein eigener Prozess und rechnet in Europe/Berlin. */
date_default_timezone_set('Europe/Berlin');

/* O4 (1.2.16): die Lage der Konfiguration VOR der Heilung festhalten. Die
 * Pflichtzeile "Konfiguration heil" im Reiter Test sah bis dahin nur den
 * Stand danach - eine geheilte Datei war immer heil. */
$fe_vor_heilung = function_exists('fer_konfig_lage') ? fer_konfig_lage() : null;
if (function_exists('fer_selbstheilung')) { fer_selbstheilung(); }

/* Die Zweitschrift wird an fuenf Stellen dieser Datei nachgezogen (die
 * fuenfte, "Einstellungen zurueckspielen", kam mit 1.2.14 dazu). Sie gehen
 * alle durch diesen einen Aufruf, damit die Wache nicht an einer von ihnen
 * vergessen wird: eine Zweitschrift MIT Aktionstoken darf nie durch
 * einen Stand OHNE ersetzt werden (fer_zweitschrift_ziehen(), ferien_lib.php).
 * Fehlt die Bibliothek, bleibt die Zweitschrift unangetastet. */
function fe_zweitschrift($quelle, $ziel, $stand)
{
    if (function_exists('fer_zweitschrift_ziehen')) {
        return fer_zweitschrift_ziehen($quelle, $ziel, (array) $stand, array('aktionstoken'));
    }
    return false;
}

$fe_saved = false; $fe_err = ''; $fe_note = ''; $fe_fehler = array();
/* X-2: $fe_x2 = die zurueckgekehrten Eingaben (GET nach der Umleitung),
 * $fe_x2neu = die Eingaben dieses POST nach einer Beanstandung, $fe_x2bean =
 * die Namen der beanstandeten Felder. */
$fe_x2 = null; $fe_x2neu = null; $fe_x2bean = array();
/* O3 (1.2.16): die Farbe einer Meldung folgt dem Ergebnis ('ok', 'warn',
 * 'err'); Hinweise, die keine Beanstandung einer Eingabe sind, stehen
 * getrennt. Bis 1.2.15 stand jede Meldung im gruenen Kasten - auch
 * "Abruf FEHLGESCHLAGEN" und "cache-fallback". */
$fe_note_art = 'ok'; $fe_hinweise = array();

/** Die Meldung zu einem Abruf - nach dem Ergebnis, nicht nach der Hoffnung. */
function fe_abruf_meldung($ok, $quelle)
{
    if ($quelle === 'frisch') { return array('ok', fer_t('MELD.ABRUF_FRISCH')); }
    if ($quelle === 'teilweise') { return array('warn', fer_t('MELD.ABRUF_TEILWEISE')); }
    if ($quelle === 'cache-fallback') { return array('warn', fer_t('MELD.ABRUF_ALT')); }
    return array('err', fer_t('MELD.ABRUF_FEHL'));
}

/* O1 (1.2.16): die Einmalmeldung fuer POST - Umleitung - GET. Sie liegt im
 * Datenordner (0600) und gilt 120 s; der GET liest sie genau einmal. */
function fe_einmal_datei() { return fer_datadir() . '/einmalmeldung.json'; }
function fe_einmal_schreiben($m)
{
    $m['zeit'] = time();
    return fer_json_schreiben(fe_einmal_datei(), $m, 0600);
}
function fe_einmal_lesen()
{
    $f = fe_einmal_datei();
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
    $liste = function ($l) {
        $o = array();
        foreach ((array) $l as $t) { if (is_string($t)) { $o[] = $t; } }
        return $o;
    };
    return array(
        'saved' => !empty($d['saved']),
        'err' => isset($d['err']) && is_string($d['err']) ? $d['err'] : '',
        'note' => isset($d['note']) && is_string($d['note']) ? $d['note'] : '',
        'note_art' => (isset($d['note_art']) && in_array($d['note_art'], array('ok', 'warn', 'err'), true)) ? $d['note_art'] : 'ok',
        'fehler' => $liste(isset($d['fehler']) ? $d['fehler'] : array()),
        'hinweise' => $liste(isset($d['hinweise']) ? $d['hinweise'] : array()),
        'eingaben' => fe_x2_pruefen(isset($d['eingaben']) ? $d['eingaben'] : null),
    );
}

/* X-2 (Regeln/04, Verbesserungsbau 01.10.2026): nach einer Beanstandung
 * stehen die eingetippten Werte wieder im Formular.
 *
 * fe_x2_felder()   - die Positivliste je Formular: Textfelder/Auswahlen und
 *                    Haken getrennt (ein Haken fehlt im POST, wenn er aus ist).
 * fe_x2_sammeln()  - liest die Eingaben aus $_POST, nur die der Liste.
 * fe_x2_pruefen()  - prueft, was aus der Einmalmeldung zurueckkommt.
 * fe_x2m()         - die Markierung eines beanstandeten Feldes.
 * fe_x2typ(), fe_x2zahl() - Zahlenfelder: ein beanstandeter Wert wie "abc"
 *                    liesse sich in einem type="number" nicht zeigen. */
function fe_x2_felder($form)
{
    if ($form === 'save') {
        $f = array('country', 'subdivision', 'lang', 'subdivision2', 'group', 'locality', 'bridge_mode',
                   'bridge_luecke', 'urlaub_vorlauf', 'ics_url', 'ics_typ', 'ics_filter', 'notify_time',
                   'tts_mode', 'tts_ip', 'tts_port', 'tts_zones', 'tts_volume', 'tts_lang', 'tts_template',
                   'tts_alexa_geraet', 'tts_alexa_laut',       // Ansage-2
                   'tts_google_geraet', 'tts_google_laut');    // Ansage-3
        for ($i = 0; $i < 6; $i++) {
            foreach (array('own_name', 'own_von', 'own_bis', 'own_typ') as $o) { $f[] = $o . '.' . $i; }
        }
        $h = array('school', 'public', 'local_holidays', 'bridge', 'typ_streng', 'halbtag_frei',
                   'notify_audio', 'notify_push', 'n_freetag', 'n_ferienstart', 'n_bridge',
                   'tts_alexa_token_loeschen', 'tts_google_token_loeschen');
        /* Ansage-2: das Sprechtoken ist ein Geheimnisfeld - es kann als
         * beanstandet MARKIERT werden, sein Wert reist nie mit (weder
         * fe_x2_sammeln() noch fe_x2_pruefen() nehmen ihn an). */
        return array($f, $h, array('tts_alexa_token', 'tts_google_token'));
    }
    if ($form === 'mqtt_save') {
        return array(array('mqtt_topic'), array('mqtt_enabled'));
    }
    return array(array(), array());
}

function fe_x2_sammeln($form)
{
    list($felder, $haken) = fe_x2_felder($form);
    $w = array();
    foreach ($felder as $k) {
        $teil = explode('.', $k);
        $v = isset($_POST[$teil[0]]) ? $_POST[$teil[0]] : null;
        if (isset($teil[1])) {
            $v = (is_array($v) && isset($v[(int) $teil[1]])) ? $v[(int) $teil[1]] : null;
        }
        $grenze = $k === 'ics_url' ? 2048 : ($k === 'tts_template' ? 600 : 256);
        if (!is_string($v) || strlen($v) > $grenze || !preg_match('//u', $v)) { continue; }
        $w[$k] = $v;
    }
    foreach ($haken as $k) { $w[$k] = isset($_POST[$k]) ? '1' : '0'; }
    return $w;
}

function fe_x2_pruefen($e)
{
    if (!is_array($e) || !isset($e['form'], $e['werte'], $e['bean']) || !is_string($e['form'])
        || !is_array($e['werte']) || !is_array($e['bean'])) {
        return null;
    }
    $fe_fx = fe_x2_felder($e['form']);
    list($felder, $haken) = $fe_fx;
    $geheim = isset($fe_fx[2]) ? $fe_fx[2] : array();    // Ansage-2: nur markierbar
    $erlaubt = array_merge($felder, $haken);
    if (!$erlaubt) { return null; }
    $w = array();
    foreach ($e['werte'] as $k => $v) {
        if (in_array((string) $k, $erlaubt, true) && is_string($v)) { $w[(string) $k] = $v; }
    }
    $b = array();
    foreach ($e['bean'] as $k) {
        if (is_string($k) && (in_array($k, $erlaubt, true) || in_array($k, $geheim, true))) { $b[] = $k; }
    }
    return array('form' => $e['form'], 'werte' => $w, 'bean' => $b);
}

function fe_x2m($feld)
{
    global $fe_x2;
    return (is_array($fe_x2) && in_array($feld, $fe_x2['bean'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function fe_x2typ($feld)
{
    global $fe_x2;
    return (is_array($fe_x2) && in_array($feld, $fe_x2['bean'], true)) ? 'text' : 'number';
}

function fe_x2zahl($feld, $wert)
{
    global $fe_x2;
    if (is_array($fe_x2) && array_key_exists($feld, $fe_x2['werte'])) { return fe_e($fe_x2['werte'][$feld]); }
    return (string) (int) $wert;
}

/** Der Wochentag in der Sprache der Oberflaeche (O5) - nicht date('D'). */
function fe_wochentag($ts) { return fer_t('TAG.T' . date('N', $ts)); }

/* Ferien-b1/Ferien-1 (Verbesserungsbau 01.10.2026): Anzeige der Leser im
 * Reiter Test (fer_leser_liste()). */
function fe_leser_weg($w)
{
    if ($w === 'json') { return fer_t('LESER.WEG_JSON'); }
    if ($w === 'bibliothek') { return fer_t('LESER.WEG_BIBLIOTHEK'); }
    return fer_t('LESER.WEG_ZEILE');
}
function fe_leser_zeit($ts)
{
    $s = max(0, time() - (int) $ts);
    if ($s < 3600) {
        $alter = sprintf(fer_t('LESER.ALTER_MIN'), (int) floor($s / 60));
    } elseif ($s < 2 * 86400) {
        $alter = sprintf(fer_t('LESER.ALTER_STD'), (int) floor($s / 3600));
    } else {
        $alter = sprintf(fer_t('LESER.ALTER_TAG'), (int) floor($s / 86400));
    }
    return sprintf(fer_t('LESER.VOR'), date('d.m.Y H:i', (int) $ts), $alter);
}
function fe_leser_ergebnis($e)
{
    if ($e === '200') { return fer_t('LESER.E_200'); }
    if ($e === '503') { return fer_t('LESER.E_503'); }
    if ($e === 'daten') { return fer_t('LESER.E_DATEN'); }
    if ($e === 'keine_daten') { return fer_t('LESER.E_KEINE'); }
    if (preg_match('/^\d{1,3}\z/', $e)) { return sprintf(fer_t('LESER.E_HTTP'), (int) $e); }
    return '-';
}
function fe_leser_erkannt($erk, $hinweis)
{
    $t = array('quelle' => 'LESER.ERK_QUELLE', 'referer' => 'LESER.ERK_REFERER', 'ua' => 'LESER.ERK_UA',
               'skript' => 'LESER.ERK_SKRIPT');
    $s = fer_t(isset($t[$erk]) ? $t[$erk] : 'LESER.ERK_UNBEKANNT');
    return $hinweis !== '' ? $s . ': ' . $hinweis : $s;
}

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$fer_wache = fer_wachposten();
if ($fer_wache !== '') {
    $fer_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($fer_reiter_merk !== null) {
        $_POST['activetab'] = $fer_reiter_merk;
    }
    /* O3 (1.2.16): die Abweisung ist ein Fehler, keine Beanstandung einer
     * gespeicherten Eingabe. Bis 1.2.15 stand sie unter "Gespeichert, aber
     * nicht alles wurde uebernommen" - gespeichert war nichts. */
    $fe_err = $fer_wache;
}


/* Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die Reiterleiste,
   den Bereich (sm-pane mit gleicher id) und diese Positivliste. Fehlt der
   Name hier, springt die Seite nach jedem Absenden zurueck auf Einstellungen. */
$fe_muster = '/^tab-(settings|mqtt|loxone|bridge|vacation|holiday|test|log)$/';
$fe_tab = preg_match($fe_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';

// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage']) && function_exists('fer_vorlage')) {
    list($fe_vname, $fe_vinhalt) = fer_vorlage();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $fe_vname . '"');
    echo $fe_vinhalt;
    exit;
}
// Die Reiter sind echte Verweise. Wer sie anklickt (oder ein Lesezeichen
// darauf setzt), landet ueber ?form= im richtigen Bereich - auch dann, wenn
// im Browser kein JavaScript laeuft.
if (isset($_GET['form']) && preg_match($fe_muster, 'tab-' . preg_replace('/[^a-z]/', '', (string) $_GET['form']))) {
    $fe_tab = 'tab-' . preg_replace('/[^a-z]/', '', (string) $_GET['form']);
}
/** Klasse fuer den gerade sichtbaren Reiter bzw. Bereich. */

function fe_aktiv($id) { global $fe_tab; return $fe_tab === $id ? ' sm-active' : ''; }

// ---------- Neues Aktionstoken erzeugen (ab 1.1.7) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $fe_cfg_tok = fer_config();
    $fe_cfg_tok['aktionstoken'] = fer_token_erzeugen();
    /* C8 (1.2.16): ueber fer_config_speichern() - Nebendatei, 0600,
     * Laengenvergleich, rename. O3: scheitert das Schreiben, sagt die Seite
     * es; bis 1.2.15 erschien bei schreibgeschuetzter Konfiguration gar
     * nichts, und das alte Token blieb. */
    if (fer_config_speichern($fe_cfg_tok)) {
        fe_zweitschrift($fe_cfgfile, $fe_bkfile, $fe_cfg_tok);
        $fe_note = fer_t('MELD.TOKEN_NEU');
    } else {
        $fe_err = sprintf(fer_t('MELD.TOKEN_NEU_FEHL'), $fe_cfgfile);
    }
    $fe_tab = 'tab-loxone';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($fe_logfile), 0775, true);
    if (@file_put_contents($fe_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n") !== false) {
        $fe_note = fer_t('MELD.LOG_GELEERT');
    } else {
        $fe_err = sprintf(fer_t('MELD.LOG_FEHL'), $fe_logfile);
    }
    $fe_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetchnow']) && function_exists('fer_fetch')) {
    list($fe_ok, $fe_q) = fer_fetch(true);
    fer_state(true);
    list($fe_note_art, $fe_note) = fe_abruf_meldung($fe_ok, $fe_q);
}

/* Ferien-a1 (Verbesserungsbau 01.10.2026): Probe gegen die Quelle (DE-BY).
 * Schreibt nicht in die Termindatei; das Ergebnis steht danach im Reiter Test. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quellprobe']) && function_exists('fer_quellprobe')) {
    list($fe_qart, $fe_qerg) = fer_quellprobe();
    if ($fe_qart === 'ok') {
        $fe_note_art = 'ok';
        $fe_note = fer_t('QPROBE.M_OK');
    } elseif ($fe_qart === 'teilweise') {
        $fe_note_art = 'warn';
        $fe_note = fer_t('QPROBE.M_TEIL');
    } elseif ($fe_qart === 'gebremst' && is_array($fe_qerg)) {
        $fe_note_art = 'warn';
        $fe_note = sprintf(fer_t('QPROBE.M_GEBREMST'), date('H:i:s', (int) $fe_qerg['zeit']),
            max(1, 60 - (time() - (int) $fe_qerg['zeit'])));
    } else {
        $fe_note_art = 'err';
        $fe_note = fer_t('QPROBE.M_FEHL');
    }
    $fe_tab = 'tab-test';
}

/* Ansage-3: Testansage ueber Google-Lautsprecher (Chromecast 4 Lox NG) mit den
 * GESPEICHERTEN Werten (Geraet, Lautstaerke, Sprechtoken) - derselbe Weg
 * fer_google_sprechen() wie die Vorabend-Ansage. Die Antwortzeile (HTTP-Code und
 * GRUND, nie Token oder Text) steht danach als Meldung im Reiter Test. POST -
 * Umleitung - GET wie alle Handler: F5 loest nichts erneut aus. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_test']) && function_exists('fer_google_sprechen')) {
    $fe_gok = fer_google_sprechen(fer_t('GOOGLE.TESTTEXT'));
    $fe_gl = fer_alexa_letzte('google_letzte.json');
    $fe_note_art = $fe_gok ? 'ok' : 'err';
    $fe_note = $fe_gok
        ? sprintf(fer_t('GOOGLE.M_TEST_OK'), $fe_gl !== null ? 'HTTP 200 ' . $fe_gl['zeile'] : '-')
        : sprintf(fer_t('GOOGLE.M_TEST_FEHL'),
            $fe_gl !== null ? fer_google_testtext($fe_gl['code'], $fe_gl['zeile'], $fe_gl['art'], 10) : '-');
    $fe_tab = 'tab-test';
}

// ---------- MQTT speichern (eigener Reiter seit 1.1.5, Hausstandard) ----------
// NICHT den save-Handler mitbenutzen: der setzt Haken per isset() und wuerde
// beim Absenden des MQTT-Formulars die Einstellungs-Haken auf 0 stellen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $fe_new = function_exists('fer_config') ? fer_config() : array();
    if (!is_array($fe_new)) { $fe_new = array(); }
    $fe_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    /* O2 (1.2.16): abweisen statt verbiegen. Bis 1.2.15 wurde "ferien/kueche"
     * still zu "ferien/kche", "ferien test" zu "ferientest" und "haus/ferien/#"
     * zu "haus/ferien/" - und alle Themen wanderten ohne ein Wort. Ein leeres
     * Feld heisst weiter: die Vorgabe "ferien". */
    $fe_te = isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'ferien';
    if (is_string($fe_te) && trim($fe_te) === '') { $fe_te = 'ferien'; }
    list($fe_tok, $fe_tw, $fe_tg) = fer_wert_pruefen('mqtt_topic', $fe_te);
    if ($fe_tok) {
        $fe_new['mqtt_topic'] = $fe_tw;
    } else {
        $fe_fehler[] = sprintf(fer_t('MELD.ABGEWIESEN'), fer_t('TEXT.TOPIC_PRFIX'), $fe_tg,
            is_string($fe_new['mqtt_topic']) ? $fe_new['mqtt_topic'] : 'ferien');
        $fe_x2bean[] = 'mqtt_topic';
    }
    if ($fe_fehler) {
        /* Entscheidung 16 (30.09.2026): bei einer Beanstandung wird NICHTS
         * gespeichert - auch der Haken nicht. Bis hierher wurde "MQTT an"
         * gespeichert, waehrend das Praefix abgewiesen war. Die Eingaben
         * kommen per X-2 zurueck ins Formular. */
        $fe_x2neu = array('form' => 'mqtt_save', 'werte' => fe_x2_sammeln('mqtt_save'),
                          'bean' => array_values(array_unique($fe_x2bean)));
    } elseif (fer_config_speichern($fe_new)) {
        $fe_saved = true;
        fe_zweitschrift($fe_cfgfile, $fe_bkfile, $fe_new);
        /* M1 (1.2.16): nach dem Einschalten und nach einem Praefixwechsel
         * sendet der naechste Lauf SOFORT den Vollsatz - nicht erst, wenn der
         * 30-min-Merker abgelaufen ist. */
        @unlink(fer_tmpdir() . '/mqtt_sig.txt');
        @unlink(fer_tmpdir() . '/mqtt_beat');
    } else {
        $fe_err = sprintf(fer_t('MELD.SPEICHERN_FEHL'), $fe_cfgfile);
    }
    $fe_tab = 'tab-mqtt';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    // Der Stand VOR dem Speichern - daran wird spaeter erkannt, ob sich die
    // Region geaendert hat und die Termine neu geholt werden muessen.
    $fe_vorher = function_exists('fer_config') ? fer_config() : array();
    if (!is_array($fe_vorher)) { $fe_vorher = array(); }
    /* Beanstandungen werden GESAMMELT und danach zusammen gemeldet - nicht
     * eine nach der anderen. Seit dem Verbesserungsbau 01.10.2026
     * (Entscheidung 16) wird bei einer Beanstandung NICHTS gespeichert, auch
     * nicht die uebrigen Felder; verloren geht dabei keine Eingabe: alle
     * eingetippten Werte stehen nach der Umleitung wieder im Formular, die
     * beanstandeten markiert (X-2, Regeln/04). Bis dahin wurden die
     * uebrigen Felder gespeichert und das beanstandete behielt seinen Wert. */
    $fe_new = array();
    /* O2 (Durchgang 30.09.2026): Eingaben werden abgewiesen und benannt, nie
     * still verbogen. Bis 1.2.15 wurden unter anderem 100.4 zu 100
     * (Lautstaerke), 70000 zu 65535 (Port), "de-DE" zu "dede", "XX" zu "DE",
     * "DE BY!" zu "DEBY" und 2.9 zu 2 (Brueckenluecke) - ohne eine Zeile auf
     * der Seite (13 gemessene Faelle). Jetzt prueft fer_wert_pruefen() -
     * dieselbe Pruefung wie beim Zurueckspielen einer Sicherung -, ein
     * abgewiesener Wert behaelt den bisherigen, und die Seite nennt Feld,
     * Grund und den geltenden Wert. Alle Beanstandungen werden gesammelt. */
    $fe_post = function ($k, $vorgabe) { return isset($_POST[$k]) ? $_POST[$k] : $vorgabe; };
    $fe_pruef = function ($schluessel, $wert, $alt, $name) use (&$fe_fehler, &$fe_x2bean) {
        list($ok, $v, $grund) = fer_wert_pruefen($schluessel, $wert);
        if ($ok) { return $v; }
        $fe_x2bean[] = str_replace('.', '_', $schluessel);     // X-2: notify.time -> notify_time
        $fe_fehler[] = sprintf(fer_t('MELD.ABGEWIESEN'), $name, $grund,
            (is_scalar($alt) && (string) $alt !== '') ? (string) $alt : fer_t('MELD.LEER'));
        return $alt;
    };
    $fe_alt = function ($k, $vorgabe) use ($fe_vorher) {
        return array_key_exists($k, $fe_vorher) ? $fe_vorher[$k] : $vorgabe;
    };
    $fe_new['country'] = $fe_pruef('country', $fe_post('country', 'DE'), $fe_alt('country', 'DE'), fer_t('TEXT.LAND'));
    $fe_new['subdivision'] = $fe_pruef('subdivision', $fe_post('subdivision', ''), $fe_alt('subdivision', 'DE-BY'),
        fer_t('TEXT.BUNDESLAND_REGION'));
    /* Sprache der Ferien- und Feiertagsnamen. Bis 1.1.7 fest 'DE'; bis
     * 1.2.15 wurde ein unbekannter Wert still zu 'DE'. */
    $fe_new['lang'] = $fe_pruef('lang', $fe_post('lang', ''), $fe_alt('lang', 'DE'), fer_t('T12.SPRACHE_NAMEN'));
    $fe_new['school'] = isset($_POST['school']) ? 1 : 0;
    $fe_new['public'] = isset($_POST['public']) ? 1 : 0;
    $fe_new['locality'] = $fe_pruef('locality', $fe_post('locality', ''), $fe_alt('locality', ''),
        fer_t('TEXT.ARBEITSORT_GEMEINDE_RTLICHE_SONDER'));
    $fe_new['local_holidays'] = isset($_POST['local_holidays']) ? 1 : 0;
    $fe_new['bridge'] = isset($_POST['bridge']) ? 1 : 0;

    /* --- ab 1.2.0 ---------------------------------------------------- */
    $fe_new['subdivision2'] = $fe_pruef('subdivision2', $fe_post('subdivision2', ''), $fe_alt('subdivision2', ''),
        fer_t('T12.REGION2'));
    if ($fe_new['subdivision2'] !== '' && $fe_new['subdivision2'] === $fe_new['subdivision']) {
        $fe_fehler[] = fer_t('MELD.REGION2_GLEICH');
        $fe_x2bean[] = 'subdivision2';
        $fe_new['subdivision2'] = '';
    }
    $fe_new['group'] = $fe_pruef('group', $fe_post('group', ''), $fe_alt('group', ''), fer_t('T12.SCHULART'));
    $fe_new['bridge_mode'] = $fe_pruef('bridge_mode', $fe_post('bridge_mode', 'klassisch'),
        $fe_alt('bridge_mode', 'klassisch'), fer_t('T12.BRUECKENMODUS'));
    /* Die Obergrenze 4 ist gemessen, nicht gegriffen: eine gewoehnliche Woche
     * hat fuenf Werktage, mit 5 waere jeder Werktag des Jahres ein
     * Brueckentag (254 statt 32 in 366 Tagen, DE-BY, 18.08.2026). */
    $fe_new['bridge_luecke'] = $fe_pruef('bridge_luecke', $fe_post('bridge_luecke', '4'), $fe_alt('bridge_luecke', 4),
        fer_t('T12.LUECKE'));
    $fe_new['typ_streng'] = isset($_POST['typ_streng']) ? 1 : 0;
    $fe_new['halbtag_frei'] = isset($_POST['halbtag_frei']) ? 1 : 0;
    /* Kalenderadresse: abweisen statt zurechtbiegen. Ein "webcal://"-Verweis,
     * wie ihn Apple ausgibt, ist keine Adresse, die file_get_contents oeffnen
     * kann - stillschweigend ein http davorzusetzen waere geraten. */
    list($fe_iok, $fe_iv) = fer_wert_pruefen('ics_url', $fe_post('ics_url', ''));
    if ($fe_iok) {
        $fe_new['ics_url'] = $fe_iv;
    } else {
        $fe_fehler[] = fer_t('MELD.ICS_URL');
        $fe_x2bean[] = 'ics_url';
        $fe_new['ics_url'] = is_string($fe_alt('ics_url', '')) ? $fe_alt('ics_url', '') : '';
    }
    $fe_new['ics_typ'] = $fe_pruef('ics_typ', $fe_post('ics_typ', 'urlaub'), $fe_alt('ics_typ', 'urlaub'), fer_t('T12.ICS_TYP'));
    $fe_new['ics_filter'] = $fe_pruef('ics_filter', $fe_post('ics_filter', ''), $fe_alt('ics_filter', ''), fer_t('T12.ICS_FILTER'));
    $fe_new['urlaub_vorlauf'] = $fe_pruef('urlaub_vorlauf', $fe_post('urlaub_vorlauf', '1'), $fe_alt('urlaub_vorlauf', 1),
        fer_t('T12.VORLAUF'));
    // MQTT wohnt seit 1.1.5 im eigenen Reiter mit eigenem Formular - hier
    // aus dem Bestand uebernehmen, sonst loescht 'Speichern' die Werte.
    $fe_new['mqtt_enabled'] = isset($fe_vorher['mqtt_enabled']) ? (int) $fe_vorher['mqtt_enabled'] : 0;
    $fe_new['mqtt_topic'] = isset($fe_vorher['mqtt_topic']) && $fe_vorher['mqtt_topic'] !== '' ? $fe_vorher['mqtt_topic'] : 'ferien';
    // Dasselbe gilt fuer das Aktionstoken (ab 1.1.7). $fe_new wird hier von
    // Grund auf neu gebaut - genau daran gingen am 13.08.2026 in drei anderen
    // Linien die Token verloren, und mit ihnen alle Adressen in Loxone.
    $fe_new['aktionstoken'] = isset($fe_vorher['aktionstoken']) ? (string) $fe_vorher['aktionstoken'] : '';
    // Eigene Termine
    $fe_new['own'] = array();
    $fe_on = isset($_POST['own_name']) ? (array) $_POST['own_name'] : array();
    $fe_ov = isset($_POST['own_von']) ? (array) $_POST['own_von'] : array();
    $fe_ob = isset($_POST['own_bis']) ? (array) $_POST['own_bis'] : array();
    $fe_ot = isset($_POST['own_typ']) ? (array) $_POST['own_typ'] : array();
    for ($fe_i = 0; $fe_i < 6; $fe_i++) {
        $fe_zname = trim(is_string(isset($fe_on[$fe_i]) ? $fe_on[$fe_i] : '') ? (string) (isset($fe_on[$fe_i]) ? $fe_on[$fe_i] : '') : '');
        $v = trim(is_string(isset($fe_ov[$fe_i]) ? $fe_ov[$fe_i] : '') ? (string) (isset($fe_ov[$fe_i]) ? $fe_ov[$fe_i] : '') : '');
        $b = trim(is_string(isset($fe_ob[$fe_i]) ? $fe_ob[$fe_i] : '') ? (string) (isset($fe_ob[$fe_i]) ? $fe_ob[$fe_i] : '') : '');
        if ($v === '' && $b === '' && $fe_zname === '') { continue; }   // leere Zeile, still uebergehen
        if (fer_tag_norm($v) !== $v) {
            /* Bis 1.1.7 verschwand so eine Zeile lautlos, und die
             * nachfolgenden rutschten eine Position nach oben. Der Anwender
             * sah nur "Konfiguration gespeichert" und eine Tabelle ohne
             * seine Eingabe. Melden ist richtig, blockieren nicht. Seit
             * 1.2.16 gilt auch ein Datum, das es nicht gibt (2026-02-30),
             * als falsch. */
            $fe_fehler[] = sprintf(fer_t($v === '' ? 'MELD.OWN_VON_LEER' : 'MELD.OWN_VON'), $fe_i + 1,
                $fe_zname !== '' ? $fe_zname : '-', $v);
            $fe_x2bean[] = 'own_von.' . $fe_i;
            continue;
        }
        if ($b !== '' && fer_tag_norm($b) !== $b) {
            $fe_fehler[] = sprintf(fer_t('MELD.OWN_BIS'), $fe_i + 1, $b);
            $fe_x2bean[] = 'own_bis.' . $fe_i;
            $b = $v;
        }
        if ($b === '') { $b = $v; }
        if ($b < $v) {
            $fe_fehler[] = sprintf(fer_t('MELD.OWN_TAUSCH'), $fe_i + 1);
            $fe_x2bean[] = 'own_von.' . $fe_i;
            $fe_x2bean[] = 'own_bis.' . $fe_i;
            $fe_tausch = $v; $v = $b; $b = $fe_tausch;
        }
        $fe_new['own'][] = array(
            'name' => $fe_zname,
            'von' => $v, 'bis' => $b,
            'typ' => in_array((string) (isset($fe_ot[$fe_i]) && is_string($fe_ot[$fe_i]) ? $fe_ot[$fe_i] : ''), array('feiertag', 'urlaub'), true) ? (string) $fe_ot[$fe_i] : 'ferien',
        );
    }

    /* Die Meldezeit: abweisen statt zurechtbiegen.
     *
     * Bis 1.1.7 stand hier nur ein Muster \d{1,2}:\d{2} mit stillem Rueckfall
     * auf 19:00. Gemessen am 18.08.2026 hiess das:
     *   "99:99" bestand die Pruefung und wurde gespeichert; fer_ann_start()
     *           klemmte es hinterher auf 23:59 - die Ansage kam also zu einer
     *           Zeit, die niemand eingestellt hatte.
     *   "25:00" ergab 23:00, ebenfalls lautlos.
     *   "7:5"   fiel durch das Muster und ergab wortlos wieder 19:00.
     * In allen drei Faellen sah der Anwender nach dem Speichern eine andere
     * Zeit als die eingegebene und erfuhr nicht, warum. */
    $fe_zeit_alt = isset($fe_vorher['notify']['time']) && is_string($fe_vorher['notify']['time']) ? $fe_vorher['notify']['time'] : '19:00';
    $fe_zeit = $fe_pruef('notify.time', $fe_post('notify_time', ''), $fe_zeit_alt, fer_t('TEXT.MELDEZEIT_AM_VORABEND'));
    $fe_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'time' => $fe_zeit,
        'freetag' => isset($_POST['n_freetag']) ? 1 : 0,
        'ferienstart' => isset($_POST['n_ferienstart']) ? 1 : 0,
        'bridge_month' => isset($_POST['n_bridge']) ? 1 : 0,
    );
    $fe_tts_alt = (isset($fe_vorher['tts']) && is_array($fe_vorher['tts'])) ? $fe_vorher['tts'] : array();
    $fe_tts_alt += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8,
                         'lang' => 'de', 'template' => '',
                         'alexa_geraet' => '', 'alexa_token' => '', 'alexa_laut' => -1,
                         'google_geraet' => '', 'google_token' => '', 'google_laut' => -1);
    $fe_new['tts'] = array(
        'mode' => $fe_pruef('tts.mode', $fe_post('tts_mode', 'musicserver'), $fe_tts_alt['mode'], fer_t('TEXT.AUDIO_AUSGABE')),
        'ip' => $fe_pruef('tts.ip', $fe_post('tts_ip', ''), $fe_tts_alt['ip'], fer_t('TEXT.IP_DES_AUDIO_SERVERS')),
        'port' => $fe_pruef('tts.port', $fe_post('tts_port', '7091'), $fe_tts_alt['port'], fer_t('TEXT.PORT')),
        'zones' => $fe_pruef('tts.zones', $fe_post('tts_zones', '1'), $fe_tts_alt['zones'], fer_t('TEXT.ZONEN')),
        'volume' => $fe_pruef('tts.volume', $fe_post('tts_volume', '8'), $fe_tts_alt['volume'], fer_t('TEXT.LAUTSTRKE')),
        'lang' => $fe_pruef('tts.lang', $fe_post('tts_lang', 'de'), $fe_tts_alt['lang'], fer_t('TEXT.SPRACHE')),
        'template' => $fe_pruef('tts.template', $fe_post('tts_template', ''), $fe_tts_alt['template'], fer_t('MELD.N_VORLAGE')),
    );
    /* Ansage-2: Ausgabeart Alexa-NG (ab Werk nicht gewaehlt). Abgewiesen wird
     * benannt, nie zurechtgebogen (Nr. 16/19): nichts gespeichert, Feld
     * markiert, Eingabe zurueck (X-2). Das Sprechtoken ist ein Kennwort: leer
     * lassen behaelt es, der Haken loescht es, es reist nie ins Formular
     * zurueck und steht in keiner Meldung. */
    $fe_new['tts']['alexa_geraet'] = $fe_pruef('tts.alexa_geraet', $fe_post('tts_alexa_geraet', ''),
        $fe_tts_alt['alexa_geraet'], fer_t('ALEXA.L_GERAET'));
    $fe_alr = $fe_post('tts_alexa_laut', '');
    if (is_string($fe_alr) && trim($fe_alr) === '') {
        $fe_new['tts']['alexa_laut'] = -1;        // leer = die Lautstaerke des Geraets bleibt
    } else {
        list($fe_alok, $fe_alw, $fe_alg) = fer_wert_pruefen('tts.alexa_laut', $fe_alr);
        if ($fe_alok) {
            $fe_new['tts']['alexa_laut'] = $fe_alw;
        } else {
            $fe_fehler[] = sprintf(fer_t('MELD.ABGEWIESEN'), fer_t('ALEXA.L_LAUT'), $fe_alg,
                (is_int($fe_tts_alt['alexa_laut']) && $fe_tts_alt['alexa_laut'] >= 0)
                    ? (string) $fe_tts_alt['alexa_laut'] : fer_t('ALEXA.LAUT_LEER'));
            $fe_x2bean[] = 'tts_alexa_laut';
            $fe_new['tts']['alexa_laut'] = $fe_tts_alt['alexa_laut'];
        }
    }
    $fe_new['tts']['alexa_token'] = is_string($fe_tts_alt['alexa_token']) ? $fe_tts_alt['alexa_token'] : '';
    if (isset($_POST['tts_alexa_token_loeschen'])) {
        $fe_new['tts']['alexa_token'] = '';
    } else {
        $fe_atr = $fe_post('tts_alexa_token', '');
        if (is_string($fe_atr)) { $fe_atr = trim($fe_atr); }
        if ($fe_atr !== '') {
            list($fe_atok, $fe_atw) = fer_wert_pruefen('tts.alexa_token', $fe_atr);
            if ($fe_atok) {
                $fe_new['tts']['alexa_token'] = $fe_atw;
            } else {
                // Die Meldung nennt nur die Art des Fehlers, nie den Wert.
                $fe_fehler[] = fer_t(is_string($fe_atr) ? 'ALEXA.M_TOKEN_FORM' : 'ALEXA.M_TOKEN_TYP');
                $fe_x2bean[] = 'tts_alexa_token';
            }
        }
    }
    if ($fe_new['tts']['mode'] === 'alexang' && !fer_alexa_token_ok($fe_new['tts']['alexa_token'])
        && !in_array('tts_alexa_token', $fe_x2bean, true)) {
        $fe_fehler[] = fer_t('ALEXA.M_TOKEN_FEHLT');
        $fe_x2bean[] = 'tts_alexa_token';
    }
    /* Ansage-3: Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG, ab Werk
     * nicht gewaehlt). Dieselben Regeln wie beim Alexa-NG-Block darueber: benannt
     * abweisen, nie zurechtbiegen (Nr. 16/19), Feld markiert, Eingabe zurueck
     * (X-2); das Sprechtoken ist ein Kennwort (leer = behalten, Haken = loeschen,
     * reist nie zurueck, steht in keiner Meldung). Ein eigenes Token, nicht das
     * von Alexa-NG. */
    $fe_new['tts']['google_geraet'] = $fe_pruef('tts.google_geraet', $fe_post('tts_google_geraet', ''),
        $fe_tts_alt['google_geraet'], fer_t('GOOGLE.L_GERAET'));
    $fe_glr = $fe_post('tts_google_laut', '');
    if (is_string($fe_glr) && trim($fe_glr) === '') {
        $fe_new['tts']['google_laut'] = -1;       // leer = Ansagelautstaerke des Chromecast-Plugins
    } else {
        list($fe_glok, $fe_glw, $fe_glg) = fer_wert_pruefen('tts.google_laut', $fe_glr);
        if ($fe_glok) {
            $fe_new['tts']['google_laut'] = $fe_glw;
        } else {
            $fe_fehler[] = sprintf(fer_t('MELD.ABGEWIESEN'), fer_t('GOOGLE.L_LAUT'), $fe_glg,
                (is_int($fe_tts_alt['google_laut']) && $fe_tts_alt['google_laut'] >= 0)
                    ? (string) $fe_tts_alt['google_laut'] : fer_t('GOOGLE.LAUT_LEER'));
            $fe_x2bean[] = 'tts_google_laut';
            $fe_new['tts']['google_laut'] = $fe_tts_alt['google_laut'];
        }
    }
    $fe_new['tts']['google_token'] = is_string($fe_tts_alt['google_token']) ? $fe_tts_alt['google_token'] : '';
    if (isset($_POST['tts_google_token_loeschen'])) {
        $fe_new['tts']['google_token'] = '';
    } else {
        $fe_gtr = $fe_post('tts_google_token', '');
        if (is_string($fe_gtr)) { $fe_gtr = trim($fe_gtr); }
        if ($fe_gtr !== '') {
            list($fe_gtok, $fe_gtw) = fer_wert_pruefen('tts.google_token', $fe_gtr);
            if ($fe_gtok) {
                $fe_new['tts']['google_token'] = $fe_gtw;
            } else {
                // Die Meldung nennt nur die Art des Fehlers, nie den Wert.
                $fe_fehler[] = fer_t(is_string($fe_gtr) ? 'GOOGLE.M_TOKEN_FORM' : 'GOOGLE.M_TOKEN_TYP');
                $fe_x2bean[] = 'tts_google_token';
            }
        }
    }
    if ($fe_new['tts']['mode'] === 'cc4lox' && !fer_alexa_token_ok($fe_new['tts']['google_token'])
        && !in_array('tts_google_token', $fe_x2bean, true)) {
        $fe_fehler[] = fer_t('GOOGLE.M_TOKEN_FEHLT');
        $fe_x2bean[] = 'tts_google_token';
    }
    /* C8 (1.2.16): ueber fer_config_speichern() - Nebendatei, 0600,
     * Laengenvergleich, rename. */
    if ($fe_fehler) {
        /* Entscheidung 16: nichts speichern, die Eingaben kommen per X-2
         * zurueck. Kein Abruf, kein Raeumen des Zwischenspeichers - es hat
         * sich ja nichts geaendert. */
        $fe_x2neu = array('form' => 'save', 'werte' => fe_x2_sammeln('save'),
                          'bean' => array_values(array_unique($fe_x2bean)));
    } elseif (fer_config_speichern($fe_new)) {
        $fe_saved = true;
        fe_zweitschrift($fe_cfgfile, $fe_bkfile, $fe_new);
        // Zwischenspeicher raeumen - und zwar den RICHTIGEN.
        //
        // Bis 1.0.1 stand hier zweimal ein fester Pfad unter /tmp/ferien/.
        // Fuer state.json ging das gut (dort liegt sie tatsaechlich), fuer
        // termine.json nicht: die Termindatei liegt in data/plugins/<ordner>/,
        // nicht in /tmp. Der Aufruf loeschte also nichts.
        //
        // Die Folge war ein Fehler, den man beim Einrichten sofort trifft:
        // Bundesland von Bayern auf Berlin umstellen, speichern - und es
        // aendert sich nichts. Denn fer_fetch() sieht eine Termindatei, die
        // keine sieben Tage alt ist, und liefert sie unveraendert zurueck.
        // Erst eine Woche spaeter oder nach "Jetzt abrufen" kamen die
        // richtigen Daten.
        //
        // Deshalb: die Pfade ueber die Funktionen holen, und wenn sich die
        // REGION geaendert hat, die Termindatei entfernen, damit der naechste
        // Abruf wirklich neu holt. Aendert sich nur die Meldezeit, bleibt sie
        // liegen - dann waere ein Abruf reine Last fuer die fremde
        // Schnittstelle.
        if (function_exists('fer_tmpdir')) {
            @unlink(fer_tmpdir() . '/state.json');
        }
        /* Was einen NEUEN ABRUF noetig macht - und was nicht.
         *
         * subdivision2 und group aendern die Abfrage an die Datenquelle,
         * gehoeren also hierher. typ_streng und halbtag_frei ausdruecklich
         * NICHT: sie werten die vorhandenen Daten anders aus, und ein Abruf
         * waere reine Last fuer eine fremde Schnittstelle. Der
         * Zwischenspeicher state.json wird trotzdem verworfen, gleich
         * unterhalb - sonst wirkte die Umstellung bis zu eine Stunde nicht. */
        $fe_regionsfelder = array('country', 'subdivision', 'subdivision2', 'group',
                                  'lang', 'school', 'public',
                                  'locality', 'local_holidays');
        // Kalender: aendert sich die Adresse oder der Filter, ist der
        // zwischengespeicherte Stand nicht mehr der gemeinte.
        foreach (array('ics_url', 'ics_filter') as $fe_if) {
            $fe_a = isset($fe_vorher[$fe_if]) ? (string) $fe_vorher[$fe_if] : '';
            if ($fe_a !== (string) $fe_new[$fe_if] && function_exists('fer_datadir')) {
                @unlink(fer_datadir() . '/kalender.json');
                break;
            }
        }
        $fe_region_neu = false;
        foreach ($fe_regionsfelder as $fe_rf) {
            $fe_alt = isset($fe_vorher[$fe_rf]) ? $fe_vorher[$fe_rf] : null;
            if ((string) $fe_alt !== (string) $fe_new[$fe_rf]) { $fe_region_neu = true; break; }
        }
        if ($fe_region_neu) {
            /* Erst holen, dann ersetzen (C3, C5; 1.2.16). Bis 1.2.15 wurde die
             * Termindatei VOR dem Abruf geloescht; fiel die Quelle aus, gab es
             * danach gar keine Daten, und der Endpunkt meldete SCHULTAG=1. Jetzt
             * bleibt die Datei liegen, gilt aber nicht mehr (andere Region,
             * fer_region_passt()): scheitert der Abruf, antwortet der Endpunkt
             * mit 503 statt mit den Ferien der alten Region. */
            fer_log('Region geaendert - die Daten werden neu geholt.');
            list($fe_rok, $fe_rq) = fer_fetch(true);
            fer_state(true);
            list($fe_note_art, $fe_note) = fe_abruf_meldung($fe_rok, $fe_rq);
            $fe_note = fer_t('MELD.REGION_NEU') . ' ' . $fe_note;
        }
    } else {
        $fe_err = sprintf(fer_t('MELD.SPEICHERN_FEHL'), $fe_cfgfile);
    }
}

$fe_cfg = function_exists('fer_config') ? fer_config() : array();
if (!is_array($fe_cfg)) { $fe_cfg = array(); }
/* Dieselbe Ruecksicht wie in fer_config(): jeder neue Schluessel hat den
 * Vorgabewert, der das bisherige Verhalten fortsetzt. Diese Liste ist die
 * zweite Stelle, an der sie stehen - sie greift, wenn fer_config() nicht
 * geladen werden konnte, und muss deshalb dasselbe sagen. */
$fe_cfg += array('country' => 'DE', 'subdivision' => 'DE-BY', 'lang' => 'DE', 'school' => 1, 'public' => 1,
    'locality' => '', 'local_holidays' => 0, 'bridge' => 1, 'own' => array(), 'mqtt_enabled' => 0, 'mqtt_topic' => 'ferien',
    'notify' => array(), 'tts' => array(), 'aktionstoken' => '',
    'subdivision2' => '', 'group' => '', 'bridge_mode' => 'klassisch', 'bridge_luecke' => 4,
    'typ_streng' => 0, 'halbtag_frei' => 1, 'ics_url' => '', 'ics_typ' => 'urlaub',
    'ics_filter' => '', 'urlaub_vorlauf' => 1);

/* Beim ERSTEN Aufruf ein Token erzeugen, damit die Adressen fuer Loxone
 * sofort benutzbar sind (es schuetzt ?say= und ?ptest= im unangemeldeten
 * ferien.php).
 *
 * "Beim ersten" heisst seit 1.2.13: nur, wenn nicht schon eine Zweitschrift
 * MIT Aktionstoken danebenliegt. Liegt eine, ist dies keine Erstinstallation,
 * sondern eine Anlage, deren Konfiguration unlesbar ist und deren
 * Selbstheilung nicht durchkam - kein Schreibrecht, volles Dateisystem. Ein
 * NEUES Token waere dann kein Anfang, sondern der endgueltige Verlust des
 * alten: es steht in jeder Loxone-Adresse und laesst sich nicht
 * zurueckrechnen.
 *
 * Die Zweitschrift-Wache faengt genau diesen Fall NICHT ab, und das ist
 * gemessen (18.09.2026, Messstelle "wache" des Pruefstands, beide
 * Heilstellen zurueckgebaut): ein frisch gewuerfeltes Token ist ein
 * gueltiger Wert und geht durch die Wache hindurch. Deshalb faellt der
 * Schutz hier geschlossen aus - es wird nichts gewuerfelt und nichts
 * geschrieben, und der Bediener bekommt den Grund zu lesen. Die naechste
 * gelungene Selbstheilung holt das alte Token zurueck. */
$fe_zweit_voll = function_exists('fer_config_hat_inhalt')
    && function_exists('fer_inhalt_oder_null')
    && fer_config_hat_inhalt(fer_inhalt_oder_null($fe_bkfile));
if (empty($fe_cfg['aktionstoken']) && $fe_zweit_voll) {
    $fe_hinweise[] = fer_t('WACHE.KEIN_TOKEN');
    if (function_exists('fer_log_if_changed')) {
        fer_log_if_changed('tokenschutz', 'Die Konfiguration traegt kein Aktionstoken, die '
            . 'Zweitschrift aber schon - es wird KEIN neues erzeugt: ' . $fe_bkfile);
    }
} elseif (empty($fe_cfg['aktionstoken'])) {
    $fe_cfg['aktionstoken'] = function_exists('fer_token_erzeugen')
        ? fer_token_erzeugen() : bin2hex(random_bytes(12));
    /* C8/C9 (1.2.16): ueber fer_config_speichern() - 0600 vor dem Inhalt. Bis
     * 1.2.15 entstand hier die erste Konfiguration mit 644 (gemessen am Geraet
     * 28.09.2026): das Aktionstoken war fuer jeden lokalen Benutzer lesbar. */
    if (fer_config_speichern($fe_cfg)) {
        fe_zweitschrift($fe_cfgfile, $fe_bkfile, $fe_cfg);
    }
}
$fe_notify = is_array($fe_cfg['notify']) ? $fe_cfg['notify'] : array();
$fe_notify += array('audio' => 0, 'push' => 0, 'time' => '19:00', 'freetag' => 1, 'ferienstart' => 1, 'bridge_month' => 1);
$fe_tts = is_array($fe_cfg['tts']) ? $fe_cfg['tts'] : array();
$fe_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '',
                 'alexa_geraet' => '', 'alexa_token' => '', 'alexa_laut' => -1,
                 'google_geraet' => '', 'google_token' => '', 'google_laut' => -1);
$fe_st = function_exists('fer_state') ? fer_state() : array();
/* Was die Aussieb-Einstellungen auf DIESER Anlage betreffen wuerden - nicht
 * "koennte etwas aendern", sondern eine Zahl. Gelesen wird die rohe
 * Termindatei, damit die Zahl auch dann stimmt, wenn schon gesiebt wird. */
$fe_stat = function_exists('fer_artstatistik') ? fer_artstatistik()
    : array('feiertage_fremd' => 0, 'ferien_fremd' => 0, 'halbtage' => 0, 'arten' => array(), 'gesamt' => 0);
/* C1 (1.2.16): bis 1.2.15 brachte ein Land als Liste (aus einer Sicherung)
 * unter PHP 8.5 JEDEN Seitenaufbau hier zum Absturz (TypeError in
 * strtoupper()), und die eigene Sicherung liess sich nicht mehr zurueckspielen,
 * weil deren Handler hinter der Absturzstelle stand. */
$fe_land = is_string($fe_cfg['country']) ? $fe_cfg['country'] : 'DE';
$fe_subs = function_exists('fer_subdivisions') ? fer_subdivisions($fe_land) : array();
$fe_loglines = array();
if (is_file($fe_logfile)) {
    $fe_loglines = array_slice(array_reverse(file($fe_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

function fe_d($iso) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $iso) ? date('d.m.Y', strtotime($iso)) : '-'; }

$fe_frame = class_exists('LBWeb', false);
$fe_host = fe_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fer_sichern'])) {
    /* X-3 (Verbesserungsbau 01.10.2026): wuerde das eigene Zurueckspielen die
     * Datei abweisen, sagt es der Kopf "_warnung" - nur mit den NAMEN der
     * Einstellungen. Geliefert wird trotzdem vollstaendig (Entscheidung 13):
     * wer umzieht, braucht die Datei, und die Werte lassen sich darin
     * berichtigen. fer_sicherung_lesen() uebergeht Schluessel mit "_". */
    $fer_voll = fer_config();
    /* Ansage-2: das Alexa-NG-Sprechtoken wird wie ein Kennwort behandelt und
     * geht NICHT mit; das Zurueckspielen behaelt das geltende. */
    // Ansage-3: ebenso das Sprechtoken fuer Google-Lautsprecher (Nr. 36 b: eine Quelle).
    if (isset($fer_voll['tts']) && is_array($fer_voll['tts'])) { $fer_voll['tts'] = ansage_sicherung_bereinigen($fer_voll['tts']); }
    $fer_altw = fer_rueckspiel_altwerte($fer_voll);
    if ($fer_altw) {
        $fer_voll = array('_warnung' => sprintf(fer_t('SICHWARN.KOPF'), implode(', ', $fer_altw))) + $fer_voll;
    }
    $fer_js = json_encode($fer_voll,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($fer_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ferienfeiertage_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $fer_js;
        exit;
    }
    $fe_err = fer_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fer_zurueck'])) {
    if (!isset($_FILES['fer_sicherung']) || !is_array($_FILES['fer_sicherung'])
        || !isset($_FILES['fer_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['fer_sicherung']['tmp_name'])) {
        $fe_err = fer_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['fer_sicherung']['size'] > 262144) {
        $fe_err = fer_t('TEXT.SICH_ZU_GROSS');
    } else {
        $fer_erg = fer_sicherung_lesen(
            (string) @file_get_contents($_FILES['fer_sicherung']['tmp_name']), fer_config());
        $fer_neu = $fer_erg[0];
        $fer_fehler = $fer_erg[1];
        $fer_n = $fer_erg[2];
        $fer_hin = isset($fer_erg[3]) ? (array) $fer_erg[3] : array();
        if ($fer_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. O3 (1.2.16): als Fehler, nicht im gruenen Kasten,
             * und genau einmal maskiert. */
            $fe_err = fer_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $fer_fehler);
        } elseif (fer_config_speichern($fer_neu)) {
            /* Die Zweitschrift mitziehen. Bis 1.2.13 blieb sie auf dem Stand
             * VOR dem Zurueckspielen stehen, und die naechste Selbstheilung
             * (abgeschnittene Konfiguration, Upgrade-Luecke) holte genau
             * diesen alten Stand zurueck - das Zurueckspielen war still
             * rueckgaengig gemacht, samt altem Aktionstoken. Gemessen
             * 18.09.2026 unter php-cgi 7.4.33 und 8.4.24
             * (Pruefung-FerienFeiertage-1.2.14, R1/R2). Die Wache in
             * fe_zweitschrift() gilt auch hier: eine Datei ohne Aktionstoken
             * ersetzt keine Zweitschrift mit (R3). */
            fe_zweitschrift($fe_cfgfile, $fe_bkfile, $fer_neu);
            /* C1/C2 (1.2.16): "uebernommen" erst, wenn Konfiguration UND
             * Zweitschrift geschrieben sind und das Ruecklesen den Stand
             * zeigt. Bis 1.2.15 meldete die Seite "24 Werte uebernommen",
             * waehrend die naechste fer_config() aus der alten Zweitschrift
             * heilte. */
            $fer_ist = fer_inhalt_oder_null($fe_cfgfile);
            $fer_zw = fer_inhalt_oder_null($fe_bkfile);
            if ($fer_ist == $fer_neu && $fer_zw == $fer_neu) {
                $fe_note = sprintf(fer_t('TEXT.SICH_UEBERNOMMEN'), $fer_n);
                $fe_hinweise = array_merge($fe_hinweise, $fer_hin);
                @unlink(fer_tmpdir() . '/state.json');
                @unlink(fer_tmpdir() . '/mqtt_sig.txt');
            } else {
                $fe_err = fer_t('TEXT.SICH_NICHT_ANGEKOMMEN');
            }
        } else {
            $fe_err = fer_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}


/* ---------- O1: POST - Umleitung - GET (Bauart D, Regeln/04) ----------
 *
 * "Jeder POST-Handler endet mit einer Umleitung." Bis 1.2.15 antworteten
 * alle sechs Handler mit HTTP 200 ohne Location; F5 nach "Neues Token"
 * wuerfelte erneut (gemessen: drei verschiedene Token aus einem POST), F5
 * nach "Jetzt abrufen" fragte die fremde Quelle noch einmal. Jetzt: das
 * Ergebnis als Einmalmeldung ablegen, 303 auf den Reiter, beim GET einmal
 * zeigen. Das gilt auch fuer einen POST ohne gueltiges Formularmerkmal. Die
 * Downloads (Vorlage, Sicherung) haben oben schon geendet. Laesst sich die
 * Einmalmeldung nicht ablegen, wird wie bisher ohne Umleitung gezeigt. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (fe_einmal_schreiben(array('saved' => $fe_saved ? 1 : 0, 'err' => $fe_err, 'note' => $fe_note,
            'note_art' => $fe_note_art, 'fehler' => array_values($fe_fehler),
            'hinweise' => array_values($fe_hinweise), 'eingaben' => $fe_x2neu))) {
        header('Location: index.php?form=' . substr($fe_tab, 4), true, 303);
        exit;
    }
    fer_log('Die Einmalmeldung liess sich nicht ablegen - die Seite wird ohne Umleitung gezeigt.');
    $fe_x2 = $fe_x2neu;
} else {
    $fe_einmal = fe_einmal_lesen();
    if ($fe_einmal !== null) {
        $fe_saved = $fe_saved || $fe_einmal['saved'];
        if ($fe_einmal['err'] !== '') { $fe_err = $fe_einmal['err']; }
        if ($fe_einmal['note'] !== '') { $fe_note = $fe_einmal['note']; $fe_note_art = $fe_einmal['note_art']; }
        $fe_fehler = array_merge($fe_einmal['fehler'], $fe_fehler);
        $fe_hinweise = array_merge($fe_einmal['hinweise'], $fe_hinweise);
        $fe_x2 = $fe_einmal['eingaben'];
    }
}

/* X-2: die Anzeige der Formulare. Ohne Beanstandung ist $fe_anz die
 * Konfiguration; nach einer Beanstandung stehen darin die eingetippten Werte
 * des beanstandeten Formulars (nur dessen Felder). Nur fuer die ANZEIGE -
 * gespeichert ist davon nichts. */
$fe_anz = $fe_cfg; $fe_nanz = $fe_notify; $fe_tanz = $fe_tts;
if (is_array($fe_x2)) {
    $fe_xw = $fe_x2['werte'];
    $fe_hinweise[] = fer_t('EINGABE.ZURUECK');
    if ($fe_x2['form'] === 'save') {
        foreach (array('country', 'subdivision', 'lang', 'subdivision2', 'group', 'locality', 'bridge_mode',
                       'bridge_luecke', 'urlaub_vorlauf', 'ics_url', 'ics_typ', 'ics_filter') as $fe_xk) {
            if (isset($fe_xw[$fe_xk])) { $fe_anz[$fe_xk] = $fe_xw[$fe_xk]; }
        }
        foreach (array('school', 'public', 'local_holidays', 'bridge', 'typ_streng', 'halbtag_frei') as $fe_xk) {
            if (isset($fe_xw[$fe_xk])) { $fe_anz[$fe_xk] = (int) $fe_xw[$fe_xk]; }
        }
        foreach (array('notify_time' => 'time') as $fe_xk => $fe_xz) {
            if (isset($fe_xw[$fe_xk])) { $fe_nanz[$fe_xz] = $fe_xw[$fe_xk]; }
        }
        foreach (array('notify_audio' => 'audio', 'notify_push' => 'push', 'n_freetag' => 'freetag',
                       'n_ferienstart' => 'ferienstart', 'n_bridge' => 'bridge_month') as $fe_xk => $fe_xz) {
            if (isset($fe_xw[$fe_xk])) { $fe_nanz[$fe_xz] = (int) $fe_xw[$fe_xk]; }
        }
        foreach (array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template', 'alexa_geraet', 'alexa_laut',
                       'google_geraet', 'google_laut') as $fe_xz) {
            if (isset($fe_xw['tts_' . $fe_xz])) { $fe_tanz[$fe_xz] = $fe_xw['tts_' . $fe_xz]; }
        }
        $fe_anz['own'] = array();
        for ($fe_xi = 0; $fe_xi < 6; $fe_xi++) {
            $fe_anz['own'][$fe_xi] = array(
                'name' => isset($fe_xw['own_name.' . $fe_xi]) ? $fe_xw['own_name.' . $fe_xi] : '',
                'von' => isset($fe_xw['own_von.' . $fe_xi]) ? $fe_xw['own_von.' . $fe_xi] : '',
                'bis' => isset($fe_xw['own_bis.' . $fe_xi]) ? $fe_xw['own_bis.' . $fe_xi] : '',
                'typ' => isset($fe_xw['own_typ.' . $fe_xi]) ? $fe_xw['own_typ.' . $fe_xi] : 'ferien');
        }
    } elseif ($fe_x2['form'] === 'mqtt_save') {
        if (isset($fe_xw['mqtt_topic'])) { $fe_anz['mqtt_topic'] = $fe_xw['mqtt_topic']; }
        if (isset($fe_xw['mqtt_enabled'])) { $fe_anz['mqtt_enabled'] = (int) $fe_xw['mqtt_enabled']; }
    }
}

if ($fe_frame) { LBWeb::lbheader('Ferien und Feiertage', 'https://wiki.loxberry.de/', 'help.html'); }

?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 150px; }
.sm-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important;
  display: inline-block; text-decoration: none !important; }
.sm-tab:visited, .sm-tab:hover { text-decoration: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
/* Wortgetreu aus VORLAGE_hausstandard.css.html (B54, 17.09.2026): jede Tabelle
   mit Eingabefeldern kommt in .sm-breit. lb-content schneidet seitlich ab; an
   BatterieBMS waren so zwei Spalten im Browser nicht erreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* Nachgetragen 05.09.2026: die Klasse stand im Quelltext, aber
   nirgends im Stilblock - der Kasten stand rahmenlos da. Wortlaut
   unveraendert aus VORLAGE_hausstandard.css.html. */
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
/* X-2 (Regeln/04, Verbesserungsbau 01.10.2026): ein beanstandetes Feld nach
   der Umleitung. EIGENE Zutat, nicht aus VORLAGE_hausstandard.css.html; nur
   background-color, damit der Pfeil der Auswahlfelder bleibt. */
.sm-wrap .sm-beanstandet { border: 2px solid #e0620d !important; background-color: #fdf4ec !important; }

</style>
<div class="sm-wrap">

<?php if ($fe_saved) { ?><div class="sm-alert sm-ok"><b><?php echo fer_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo fer_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES_DI'); ?></div><?php } ?>
<?php if ($fe_note !== '') { ?><div class="sm-alert <?= $fe_note_art === 'err' ? 'sm-err' : ($fe_note_art === 'warn' ? 'sm-warn' : 'sm-ok') ?>"><?= fe_e($fe_note) ?></div><?php } ?>
<?php if ($fe_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo fer_t('TEXT.FEHLER'); ?></b> <?= fe_e($fe_err) ?></div><?php } ?>
<?php /* Beanstandungen: gesammelt und gelb. Seit dem Verbesserungsbau
   01.10.2026 (Entscheidung 16) ist bei einer Beanstandung nichts gespeichert;
   die Ueberschrift sagt dann "Nicht uebernommen", und der Hinweis darunter
   sagt, dass die eingetippten Werte wieder im Formular stehen (X-2). */ ?>
<?php if ($fe_fehler) { ?><div class="sm-alert sm-warn"><b><?php echo fer_t($fe_saved ? 'TEXT.M_BEANSTANDUNG' : 'MELD.M_NICHT_GESPEICHERT'); ?></b>
<ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($fe_fehler as $fe_m) { ?><li><?= fe_e($fe_m) ?></li><?php } ?></ul></div><?php } ?>
<?php if ($fe_hinweise) { ?><div class="sm-alert sm-warn"><b><?php echo fer_t('MELD.M_HINWEIS'); ?></b>
<ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($fe_hinweise as $fe_m) { ?><li><?= fe_e($fe_m) ?></li><?php } ?></ul></div><?php } ?>

<?php if (!empty($fe_st)) { ?>
<div class="sm-alert sm-info">
<?php if (!empty($fe_st['quelle_da'])) { ?>
<?php if (empty($fe_st['ok'])) { ?><b><?php echo fer_t('MELD.OK0'); ?></b><br><?php } ?>
<?php /* ACHTUNG: 'heute' und 'schulfrei' sind ARRAY-SCHLUESSEL aus fer_state(),
   keine Anzeigetexte. Bis 1.0.1 standen hier fer_t('TEXT.HEUTE_2') bzw.
   fer_t('TEXT.SCHULFREI') - offensichtlich durch einen Durchlauf entstanden,
   der Zeichenketten pauschal in Sprachschluessel umgeschrieben hat. Auf
   Deutsch fiel das nicht auf, weil TEXT.HEUTE_2 zufaellig "heute" ergibt.
   Auf Englisch liefert derselbe Schluessel "today", der Zugriff geht ins
   Leere, und der gesamte Statuskasten bleibt leer. Diese Schluessel duerfen
   NIE uebersetzt werden. */ ?>
<b><?php echo fer_t('TEXT.HEUTE'); ?><?= fe_e(fe_d($fe_st['heute']['datum'])) ?>):</b>
<?= $fe_st['heute']['schulfrei'] ? '<b>' . fer_t('TEXT.SCHULFREI') . '</b>' : fer_t('TEXT.NORMALER_SCHULTAG') ?>
<?= $fe_st['heute']['feiertag'] ? ' &middot; ' . fer_t('TX.FEIERTAG') . ': <b>' . fe_e($fe_st['heute']['feiertag_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['ferien'] ? ' &middot; ' . fer_t('TX.FERIEN') . ': <b>' . fe_e($fe_st['heute']['ferien_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['bruecke'] ? ' &middot; <b>' . fer_t('TEXT.BRCKENTAG') . '</b>' : '' ?><br>
<b><?php echo fer_t('TEXT.MORGEN'); ?></b> <?= $fe_st['morgen']['schulfrei'] ? '<b>' . fer_t('TEXT.SCHULFREI') . '</b>' : fer_t('TX.SCHULTAG') ?>
<?= $fe_st['morgen']['feiertag'] ? ' (' . fe_e($fe_st['morgen']['feiertag_name']) . ')' : '' ?>
<?= $fe_st['morgen']['ferien'] ? ' (' . fe_e($fe_st['morgen']['ferien_name']) . ')' : '' ?><br>
<?php if ($fe_st['naechste']['in'] >= 0) { ?>
<?= $fe_st['naechste']['rest'] > 0
    ? sprintf(fer_t('TX.FERIEN_LAUFEN'), fe_e($fe_st['naechste']['name']), (int) $fe_st['naechste']['rest'], fe_e(fe_d($fe_st['naechste']['bis'])))
    : sprintf(fer_t('TX.FERIEN_KOMMEN'), fe_e($fe_st['naechste']['name']), (int) $fe_st['naechste']['in'], fe_e(fe_d($fe_st['naechste']['von'])), fe_e(fe_d($fe_st['naechste']['bis'])), (int) $fe_st['naechste']['dauer']) ?><br>
<?php } ?>
<?php if ($fe_st['feiertag_naechster']['in'] >= 0) { ?>
<?php echo fer_t('TEXT.NCHSTER_FEIERTAG'); ?> <b><?= fe_e($fe_st['feiertag_naechster']['name']) ?></b> in <?= (int) $fe_st['feiertag_naechster']['in'] ?> <?php echo fer_t('TEXT.TAGEN'); ?><?= fe_e(fe_d($fe_st['feiertag_naechster']['datum'])) ?>)<br>
<?php } ?>
<?php /* Die Zahl, die ein Wochenende von den Sommerferien unterscheidet.
   Sie steht bewusst hier oben und nicht in einem Reiter: es ist die einzige
   Angabe des Plugins, an der eine Heizungsentscheidung haengt. */ ?>
<?php if (isset($fe_st['heute']['freitage'])) { ?>
<b><?php echo fer_t('T12.SB_FREITAGE'); ?></b>
<?= (int) $fe_st['heute']['freitage'] > 0
    ? sprintf(fe_e(fer_t('T12.SB_FREITAGE_N')), (int) $fe_st['heute']['freitage'])
    : sprintf(fe_e(fer_t('T12.SB_FREITAGE_0')), (int) $fe_st['morgen']['freitage']) ?><br>
<?php } ?>
<?php if (!empty($fe_st['urlaub']['aktiv'])) { ?>
<b><?php echo fer_t('T12.SB_URLAUB'); ?></b>
<?= sprintf(fe_e(fer_t('T12.SB_URLAUB_N')), fe_e($fe_st['urlaub']['name']),
    (int) $fe_st['urlaub']['rest']) ?>
<?= !empty($fe_st['urlaub']['heim']) ? ' <b>' . fe_e(fer_t('T12.SB_HEIM')) . '</b>' : '' ?><br>
<?php } ?>
<span class="sm-small"><?php echo fer_t('TEXT.DATEN_REICHEN_BIS'); ?> <?= fe_e(fe_d($fe_st['reicht_bis'])) ?> <?php echo fer_t('TEXT.STAND'); ?> <?= fe_e(substr((string) $fe_st['stand_ok'], 0, 10)) ?><?= !empty($fe_st['teilausfall']) ? ' &middot; ' . fe_e(sprintf(fer_t('MELD.TEILAUSFALL'), implode(', ', (array) $fe_st['teilausfall']))) : '' ?></span>
<?php } else { ?>
<b><?php echo fer_t('TEXT.NOCH_KEINE_DATEN_GELADEN'); ?></b> <?php echo fer_t('TEXT.BITTE_UNTEN_LAND_UND_BUNDESLAND_WH'); ?>
<?php } ?>
</div>
<?php if (!empty($fe_st['warnung'])) { ?><div class="sm-alert sm-warn"><b><?php echo fer_t('TEXT.ACHTUNG'); ?></b> <?php echo fer_t('TEXT.DIE_FERIEN_FEIERTAGSDATEN_REICHEN_'); ?></div><?php } ?>
<?php } ?>

<?php /* Echte Verweise, keine <div>-Attrappen.
   Bis 1.0.1 waren die Reiter <div>-Elemente, die erst ein Klick-Empfaenger
   in JavaScript benutzbar machte. Alle Bereiche stehen aber auf
   display:none, und sm-active setzte ausschliesslich dieses JavaScript -
   ohne JavaScript war die Seite deshalb LEER. Jetzt entscheidet der Server,
   was sichtbar ist; das JavaScript schaltet nur noch schneller um, damit
   nicht jeder Reiterwechsel die Seite neu laedt. */ ?>
<div class="sm-tabs">
    <a class="sm-tab<?= fe_aktiv('tab-settings') ?>" data-pane="tab-settings" href="index.php?form=settings"><?php echo fer_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-mqtt') ?>" data-pane="tab-mqtt" href="index.php?form=mqtt"><?php echo fer_t('REITER.MQTT'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-loxone') ?>" data-pane="tab-loxone" href="index.php?form=loxone"><?php echo fer_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-bridge') ?>" data-pane="tab-bridge" href="index.php?form=bridge"><?php echo fer_t('REITER.BRUECKENTAGE'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-vacation') ?>" data-pane="tab-vacation" href="index.php?form=vacation"><?php echo fer_t('REITER.FERIEN'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-holiday') ?>" data-pane="tab-holiday" href="index.php?form=holiday"><?php echo fer_t('REITER.FEIERTAGE'); ?></a>
    <?php /* data-reload: dieser eine Reiter laedt die Seite wirklich neu.
       Die Selbstpruefung ruft den eigenen Endpunkt auf, und das soll sie nur
       dann tun, wenn jemand hinsieht - nicht bei jedem Seitenaufbau. Ohne das
       Neuladen waere der Reiter leer, weil sein Inhalt serverseitig gar nicht
       erst erzeugt wurde. */ ?>
    <a class="sm-tab<?= fe_aktiv('tab-test') ?>" data-pane="tab-test" data-reload="1" href="index.php?form=test"><?php echo fer_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= fe_aktiv('tab-log') ?>" data-pane="tab-log" href="index.php?form=log"><?php echo fer_t('REITER.LOG'); ?></a>
</div>

<!-- ================= <?php echo fer_t('TEXT.EINSTELLUNGEN'); ?> ================= -->
<div class="sm-pane<?= fe_aktiv('tab-settings') ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo fer_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>
<form action="index.php" method="post" autocomplete="off">
  <?php echo fer_fmt(); ?>
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo fer_t('TEXT.REGION'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.LAND'); ?></label>
        <select data-role="none" name="country"<?= fe_x2m('country') ?>>
<?php /* O5 (1.2.16): die Laendernamen aus der Sprachdatei. */
foreach (fer_laender() as $fe_k) { ?>
            <option value="<?= $fe_k ?>"<?= $fe_anz['country'] === $fe_k ? ' selected' : '' ?>><?= fe_e(fer_t('LAND.' . $fe_k)) ?></option>
<?php } ?>
        </select>
        <div class="sm-small"><?php echo fer_t('TEXT.NACH_DEM_WECHSEL_SPEICHERN_DANACH_'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.BUNDESLAND_REGION'); ?></label>
<?php if ($fe_subs) { ?>
        <select data-role="none" name="subdivision"<?= fe_x2m('subdivision') ?>>
            <option value=""><?php echo fer_t('TEXT.GANZES_LAND_NUR_BUNDESWEITE_FEIERT'); ?></option>
<?php /* O2 (1.2.16): eine gespeicherte Region, die nicht in der Liste steht,
   bleibt waehlbar. Bis 1.2.15 wurde sie beim naechsten unveraenderten
   Speichern still zu "Ganzes Land" - der Browser schickte die erste Zeile. */
if (is_string($fe_anz['subdivision']) && $fe_anz['subdivision'] !== '' && !isset($fe_subs[$fe_anz['subdivision']])) { ?>
            <option value="<?= fe_e($fe_anz['subdivision']) ?>" selected><?= fe_e($fe_anz['subdivision']) ?> (<?php echo fer_t('MELD.NICHT_IN_LISTE'); ?>)</option>
<?php } ?>
<?php foreach ($fe_subs as $fe_code => $fe_name) { ?>
            <option value="<?= fe_e($fe_code) ?>"<?= $fe_anz['subdivision'] === $fe_code ? ' selected' : '' ?>><?= fe_e($fe_name) ?> (<?= fe_e($fe_code) ?>)</option>
<?php } ?>
        </select>
<?php } else { ?>
        <input data-role="none" type="text" name="subdivision"<?= fe_x2m('subdivision') ?> value="<?= fe_e($fe_anz['subdivision']) ?>" placeholder="z. B. DE-BY">
        <div class="sm-small"><?php echo fer_t('TEXT.LISTE_KONNTE_NICHT_GELADEN_WERDEN_'); ?></div>
<?php } ?>
    </div>
    <div>
        <label><?php echo fer_t('T12.SPRACHE_NAMEN'); ?></label>
        <select data-role="none" name="lang"<?= fe_x2m('lang') ?>>
<?php foreach (array('DE' => 'Deutsch', 'EN' => 'English', 'FR' => 'Fran&ccedil;ais', 'IT' => 'Italiano',
                     'NL' => 'Nederlands', 'PL' => 'Polski', 'CS' => '&Ccaron;e&scaron;tina') as $fe_k => $fe_v) { ?>
            <option value="<?= $fe_k ?>"<?= $fe_anz['lang'] === $fe_k ? ' selected' : '' ?>><?= $fe_v ?></option>
<?php } ?>
        </select>
        <div class="sm-small"><?php echo fer_t('T12.SPRACHE_NAMEN_H'); ?></div>
    </div>
</div>

<div class="sm-row" style="margin-top:8px;">
    <div>
        <label><?php echo fer_t('T12.REGION2'); ?></label>
<?php if ($fe_subs) { ?>
        <select data-role="none" name="subdivision2"<?= fe_x2m('subdivision2') ?>>
            <option value=""><?php echo fer_t('T12.REGION2_AUS'); ?></option>
<?php if (is_string($fe_anz['subdivision2']) && $fe_anz['subdivision2'] !== '' && !isset($fe_subs[$fe_anz['subdivision2']])) { ?>
            <option value="<?= fe_e($fe_anz['subdivision2']) ?>" selected><?= fe_e($fe_anz['subdivision2']) ?> (<?php echo fer_t('MELD.NICHT_IN_LISTE'); ?>)</option>
<?php } ?>
<?php foreach ($fe_subs as $fe_code => $fe_name) { ?>
            <option value="<?= fe_e($fe_code) ?>"<?= $fe_anz['subdivision2'] === $fe_code ? ' selected' : '' ?>><?= fe_e($fe_name) ?> (<?= fe_e($fe_code) ?>)</option>
<?php } ?>
        </select>
<?php } else { ?>
        <input data-role="none" type="text" name="subdivision2"<?= fe_x2m('subdivision2') ?> value="<?= fe_e($fe_anz['subdivision2']) ?>" placeholder="z. B. DE-BW">
<?php } ?>
        <div class="sm-small"><?php echo fer_t('T12.REGION2_H'); ?></div>
    </div>
<?php $fe_gruppen = function_exists('fer_gruppen') ? fer_gruppen($fe_land) : array(); ?>
<?php if ($fe_gruppen) { ?>
    <div>
        <label><?php echo fer_t('T12.SCHULART'); ?></label>
        <select data-role="none" name="group"<?= fe_x2m('group') ?>>
            <option value=""><?php echo fer_t('T12.SCHULART_ALLE'); ?></option>
<?php foreach ($fe_gruppen as $fe_code => $fe_name) { ?>
            <option value="<?= fe_e($fe_code) ?>"<?= $fe_anz['group'] === $fe_code ? ' selected' : '' ?>><?= fe_e($fe_name) ?> (<?= fe_e($fe_code) ?>)</option>
<?php } ?>
        </select>
        <div class="sm-small"><?php echo fer_t('T12.SCHULART_H'); ?></div>
    </div>
<?php } else { ?>
    <?php /* Kein Feld ohne Wirkung: fuehrt das Land keine Schularten, gibt es
       auch nichts auszuwaehlen. Der bestehende Wert wird trotzdem
       mitgeschickt, damit ihn ein Speichern nicht loescht. */ ?>
    <input data-role="none" type="hidden" name="group" value="<?= fe_e($fe_anz['group']) ?>">
<?php } ?>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div>
        <label><?php echo fer_t('TEXT.ARBEITSORT_GEMEINDE_RTLICHE_SONDER'); ?></label>
        <select data-role="none" name="locality"<?= fe_x2m('locality') ?>>
            <option value=""<?= $fe_anz['locality'] === '' ? ' selected' : '' ?>><?php echo fer_t('TEXT.ALLE_ANDEREN_STDTE_UND_GEMEINDEN'); ?></option>
            <option value="DE-BY-AU"<?= $fe_anz['locality'] === 'DE-BY-AU' ? ' selected' : '' ?>><?php echo fer_t('TEXT.STADTGEBIET_AUGSBURG_MIT_FRIEDENSF'); ?></option>
            <option value="BY-EV"<?= $fe_anz['locality'] === 'BY-EV' ? ' selected' : '' ?>><?php echo fer_t('TEXT.BAYERN_BERWIEGEND_EVANGELISCHE_GEM'); ?></option>
            <option value="SN-KATH"<?= $fe_anz['locality'] === 'SN-KATH' ? ' selected' : '' ?>><?php echo fer_t('TEXT.SACHSEN_KATHOLISCHE_GEMEINDE_IM_SO'); ?></option>
            <option value="TH-KATH"<?= $fe_anz['locality'] === 'TH-KATH' ? ' selected' : '' ?>><?php echo fer_t('TEXT.THRINGEN_EICHSFELD_BZW_KATHOLISCHE'); ?></option>
        </select>
        <div class="sm-small"><?php echo fer_t('TEXT.DREI_FEIERTAGE_GELTEN'); ?> <b><?php echo fer_t('TEXT.NICHT_IM_GANZEN_BUNDESLAND'); ?></b><?php echo fer_t('TEXT.SONDERN_NUR_IN_BESTIMMTEN_GEMEINDE'); ?><br>
        <?php echo fer_t('TEXT.TEXT'); ?> <b><?php echo fer_t('TEXT.MARI_HIMMELFAHRT'); ?></b> <?php echo fer_t('TEXT.IST_IN_BAYERN_NUR_IN_DEN_RUND_1_70'); ?><br>
        &bull; <b><?php echo fer_t('TEXT.FRONLEICHNAM'); ?></b> <?php echo fer_t('TEXT.IST_IN_SACHSEN_NUR_IN_DEN_KATHOLIS'); ?><br>
        &bull; <b><?php echo fer_t('TEXT.FRIEDENSFEST'); ?></b> <?php echo fer_t('TEXT.GILT_NUR_IM_STADTGEBIET_AUGSBURG'); ?><br>
        <?php echo fer_t('TEXT.IM_ZWEIFEL_HILFT_DIE_GEMEINDE_ODER'); ?></div>
    </div>
</div>
<div style="margin-top:8px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="school" <?= !empty($fe_anz['school']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.SCHULFERIEN_AUSWERTEN'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="public" <?= !empty($fe_anz['public']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.GESETZLICHE_FEIERTAGE_AUSWERTEN'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="local_holidays" <?= !empty($fe_anz['local_holidays']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.AUCH_NUR_RTLICHE_FEIERTAGE'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="bridge" <?= !empty($fe_anz['bridge']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.BRCKENTAGE_ERKENNEN'); ?>
    </label>
    <div class="sm-small"><?php echo fer_t('TEXT.AUCH_NUR_RTLICHE_FEIERTAGE_NIMMT'); ?> <b><?php echo fer_t('MELD.ALLE'); ?></b> <?php echo fer_t('TEXT.ORTSGEBUNDENEN_FEIERTAGE_DER_REGIO'); ?> <b><?php echo fer_t('TEXT.BRCKENTAG'); ?></b> <?php echo fer_t('TEXT.IST_EIN_WERKTAG_ZWISCHEN_FEIERTAG_'); ?></div>
</div>
<div class="sm-alert sm-info" style="margin-top:10px;"><?php echo fer_t('TEXT.DATENQUELLE'); ?> <b><?php echo fer_t('TEXT.OPENHOLIDAYSAPI_ORG'); ?></b> <?php echo fer_t('TEXT.AMTLICHE_FERIEN_UND_FEIERTAGSDATEN'); ?></div>

<h2><?php echo fer_t('T12.H_AUSWERTUNG'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('T12.BRUECKENMODUS'); ?></label>
        <select data-role="none" name="bridge_mode"<?= fe_x2m('bridge_mode') ?> id="bridge_mode" onchange="feBruecke()">
            <option value="klassisch"<?= $fe_anz['bridge_mode'] !== 'erweitert' ? ' selected' : '' ?>><?php echo fer_t('T12.BM_KLASSISCH'); ?></option>
            <option value="erweitert"<?= $fe_anz['bridge_mode'] === 'erweitert' ? ' selected' : '' ?>><?php echo fer_t('T12.BM_ERWEITERT'); ?></option>
        </select>
        <div class="sm-small"><?php echo fer_t('T12.BM_H'); ?></div>
    </div>
    <div id="luecke_feld">
        <label><?php echo fer_t('T12.LUECKE'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('bridge_luecke') ?>" name="bridge_luecke"<?= fe_x2m('bridge_luecke') ?> min="1" max="4" value="<?= fe_x2zahl('bridge_luecke', $fe_anz['bridge_luecke']) ?>">
        <div class="sm-small"><?php echo fer_t('T12.LUECKE_H'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('T12.VORLAUF'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('urlaub_vorlauf') ?>" name="urlaub_vorlauf"<?= fe_x2m('urlaub_vorlauf') ?> min="0" max="14" value="<?= fe_x2zahl('urlaub_vorlauf', $fe_anz['urlaub_vorlauf']) ?>">
        <div class="sm-small"><?php echo fer_t('T12.VORLAUF_H'); ?></div>
    </div>
</div>
<div style="margin-top:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="typ_streng" <?= !empty($fe_anz['typ_streng']) ? 'checked' : '' ?>> <?php echo fer_t('T12.TYP_STRENG'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="halbtag_frei" <?= !empty($fe_anz['halbtag_frei']) ? 'checked' : '' ?>> <?php echo fer_t('T12.HALBTAG_FREI'); ?>
    </label>
    <div class="sm-small"><?php echo fer_t('T12.TYP_STRENG_H'); ?></div>
    <?php /* Keine Vermutung, sondern die Zahl aus den geladenen Daten dieser
       Anlage. Wer eine Auswahl trifft, soll vorher wissen, was sie ergibt. */ ?>
    <div class="sm-hinweis"><?= sprintf(fe_e(fer_t('T12.BETRIFFT')),
        (int) $fe_stat['feiertage_fremd'], (int) $fe_stat['ferien_fremd'],
        (int) $fe_stat['halbtage'], (int) $fe_stat['gesamt']) ?>
    <?php if ($fe_stat['arten']) { $fe_al = array();
        foreach ($fe_stat['arten'] as $fe_ak => $fe_an) { $fe_al[] = $fe_ak . ': ' . $fe_an; }
        echo '<br>' . fe_e(fer_t('T12.BETRIFFT_ARTEN')) . ' ' . fe_e(implode(', ', $fe_al)); } ?>
    </div>
</div>

<h2><?php echo fer_t('T12.H_KALENDER'); ?></h2>
<div class="sm-small"><?php echo fer_t('T12.ICS_ERKLAERUNG'); ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div style="flex:2;">
        <label><?php echo fer_t('T12.ICS_URL'); ?></label>
        <input data-role="none" type="text" name="ics_url"<?= fe_x2m('ics_url') ?> value="<?= fe_e($fe_anz['ics_url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
        <div class="sm-small"><?php echo fer_t('T12.ICS_URL_H'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('T12.ICS_TYP'); ?></label>
        <select data-role="none" name="ics_typ"<?= fe_x2m('ics_typ') ?>>
            <option value="urlaub"<?= $fe_anz['ics_typ'] === 'urlaub' ? ' selected' : '' ?>><?php echo fer_t('TEXT.URLAUB_ABWESEND'); ?></option>
            <option value="ferien"<?= $fe_anz['ics_typ'] === 'ferien' ? ' selected' : '' ?>><?php echo fer_t('TEXT.WIE_FERIEN_2'); ?></option>
            <option value="feiertag"<?= $fe_anz['ics_typ'] === 'feiertag' ? ' selected' : '' ?>><?php echo fer_t('TEXT.WIE_FEIERTAG_2'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo fer_t('T12.ICS_FILTER'); ?></label>
        <input data-role="none" type="text" name="ics_filter"<?= fe_x2m('ics_filter') ?> value="<?= fe_e($fe_anz['ics_filter']) ?>" placeholder="Urlaub">
        <div class="sm-small"><?php echo fer_t('T12.ICS_FILTER_H'); ?></div>
    </div>
</div>

<h2><?php echo fer_t('TEXT.EIGENE_TERMINE_OPTIONAL'); ?></h2>
<div class="sm-small"><?php echo fer_t('TEXT.FR_BETRIEBSFERIEN_URLAUB_ODER_SCHU'); ?><br>
&bull; <b><?php echo fer_t('TEXT.WIE_FERIEN'); ?></b> <?php echo fer_t('TEXT.ZHLT_IN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.FERIEN'); ?></span>/<span class="sm-mono"><?php echo fer_t('TEXT.SCHULFREI_2'); ?></span>.<br>
&bull; <b><?php echo fer_t('TEXT.WIE_FEIERTAG'); ?></b> <?php echo fer_t('TEXT.ZUSTZLICH_IN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.FEIERTAG'); ?></span>.<br>
&bull; <b><?php echo fer_t('TEXT.URLAUB_ABWESEND_2'); ?></b> <?php echo fer_t('TEXT.BEDEUTET_DAS_HAUS_IST_LEER_ZUSTZLI'); ?>
<span class="sm-mono"><?php echo fer_t('TEXT.URLAUB_1'); ?></span> <?php echo fer_t('TEXT.GESETZT_DAMIT_LOXONE_AUTOMATISCH_I'); ?> <b><?php echo fer_t('TEXT.URLAUBSMODUS'); ?></b> <?php echo fer_t('TEXT.GEHEN_KANN_ANWESENHEITSSIMULATION_'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.URLAUBENDE_1'); ?></span> <?php echo fer_t('TEXT.AM_LETZTEN_URLAUBSTAG_LSST_SICH_DA'); ?><br>
<?php echo fer_t('TEXT.DAS_DATUM_BEZEICHNET_GANZE_TAGE_AB'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.URLAUBENDE'); ?></span> <?php echo fer_t('TEXT.WIEDER_AUF_SCHRITT4D_EIN_VORZIEHEN'); ?></div>
<div class="sm-breit">
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:30%;"><?php echo fer_t('TEXT.BEZEICHNUNG'); ?></th><th style="width:20%;"><?php echo fer_t('TEXT.VON_JJJJ_MM_TT'); ?></th><th style="width:20%;"><?php echo fer_t('MELD.SP_BIS'); ?></th><th style="width:24%;"><?php echo fer_t('MELD.SP_ART'); ?></th></tr>
<?php for ($fe_i = 0; $fe_i < 6; $fe_i++) {
    $fe_o = isset($fe_anz['own'][$fe_i]) ? (array) $fe_anz['own'][$fe_i] : array();
    $fe_o += array('name' => '', 'von' => '', 'bis' => '', 'typ' => 'ferien'); ?>
<tr>
<td><input data-role="none" type="text" name="own_name[]"<?= fe_x2m('own_name.' . $fe_i) ?> value="<?= fe_e($fe_o['name']) ?>" placeholder="<?= $fe_i === 0 ? 'z. B. Betriebsferien' : '' ?>"></td>
<td><input data-role="none" type="text" name="own_von[]"<?= fe_x2m('own_von.' . $fe_i) ?> value="<?= fe_e($fe_o['von']) ?>" placeholder="2026-08-03"></td>
<td><input data-role="none" type="text" name="own_bis[]"<?= fe_x2m('own_bis.' . $fe_i) ?> value="<?= fe_e($fe_o['bis']) ?>" placeholder="2026-08-14"></td>
<td><select data-role="none" name="own_typ[]"<?= fe_x2m('own_typ.' . $fe_i) ?>>
    <option value="ferien"<?= ($fe_o['typ'] !== 'feiertag' && $fe_o['typ'] !== 'urlaub') ? ' selected' : '' ?>><?php echo fer_t('TEXT.WIE_FERIEN_2'); ?></option>
    <option value="feiertag"<?= $fe_o['typ'] === 'feiertag' ? ' selected' : '' ?>><?php echo fer_t('TEXT.WIE_FEIERTAG_2'); ?></option>
    <option value="urlaub"<?= $fe_o['typ'] === 'urlaub' ? ' selected' : '' ?>><?php echo fer_t('TEXT.URLAUB_ABWESEND'); ?></option>
</select></td>
</tr>
<?php } ?>
</table>
</div>

<h2><?php echo fer_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($fe_nanz['audio']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($fe_nanz['push']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo fer_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.ANN_1'); ?></span> <?php echo fer_t('TEXT.ANLEITUNG_SCHRITT_4'); ?></div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.MELDEZEIT_AM_VORABEND'); ?></label>
        <input data-role="none" type="text" name="notify_time"<?= fe_x2m('notify_time') ?> value="<?= fe_e($fe_nanz['time']) ?>" placeholder="19:00">
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;"><?php echo fer_t('TEXT.TEXT_2'); ?></label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_freetag" <?= !empty($fe_nanz['freetag']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.MELDEN_WENN_MORGEN_SCHULFREI_IST'); ?>
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_ferienstart" <?= !empty($fe_nanz['ferienstart']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.MELDEN_AM_VORABEND_DES_FERIENBEGIN'); ?>
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_bridge" <?= !empty($fe_nanz['bridge_month']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.IM_JANUAR_DIE_BRCKENTAGE_DES_JAHRE'); ?>
        </label>
    </div>
</div>

<h2><?php echo fer_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode"<?= fe_x2m('tts_mode') ?> id="tts_mode" onchange="feTtsMode()">
            <option value="musicserver"<?= $fe_tanz['mode'] === 'musicserver' ? ' selected' : '' ?>><?php echo fer_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $fe_tanz['mode'] === 'ms4h' ? ' selected' : '' ?>><?php echo fer_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $fe_tanz['mode'] === 'audioserver' ? ' selected' : '' ?>><?php echo fer_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $fe_tanz['mode'] === 'custom' ? ' selected' : '' ?>><?php echo fer_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
            <option value="alexang"<?= $fe_tanz['mode'] === 'alexang' ? ' selected' : '' ?>><?php echo fer_t('ALEXA.MODUS'); ?></option>
            <option value="cc4lox"<?= $fe_tanz['mode'] === 'cc4lox' ? ' selected' : '' ?>><?php echo fer_t('GOOGLE.MODUS'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip"<?= fe_x2m('tts_ip') ?> value="<?= fe_e($fe_tanz['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label><?php echo fer_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('tts_port') ?>" name="tts_port"<?= fe_x2m('tts_port') ?> value="<?= fe_x2zahl('tts_port', $fe_tanz['port']) ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones"<?= fe_x2m('tts_zones') ?> value="<?= fe_e($fe_tanz['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo fer_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo fer_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo fer_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo fer_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> <?php echo fer_t('MELD.UND'); ?> <span class="sm-mono">2, 4, 6</span> <?php echo fer_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('tts_volume') ?>" name="tts_volume"<?= fe_x2m('tts_volume') ?> value="<?= fe_x2zahl('tts_volume', $fe_tanz['volume']) ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo fer_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang"<?= fe_x2m('tts_lang') ?> value="<?= fe_e($fe_tanz['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo fer_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template"<?= fe_x2m('tts_template') ?> id="tts_template" rows="2" placeholder="<?php echo fer_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= fe_e($fe_tanz['template']) ?></textarea>
    <div class="sm-small"><?php echo fer_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo fer_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo fer_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <b><?php echo fer_t('TEXT.KEINE_HTTP_TTS_SCHNITTSTELLE'); ?></b><?php echo fer_t('TEXT.IN_DIESEM_MODUS_SPRICHT_DAS_PLUGIN'); ?> <span class="sm-mono">ANN=1</span>.
</div>
<?php /* Ansage-2: Ausgabeart Alexa-NG (ab Werk nicht gewaehlt). Das Sprechtoken
   reist NIE in die Seite: das Feld ist immer leer (Kennwortfeld), der
   Platzhalter sagt nur, ob eines gespeichert ist und wie lang es ist. */
$fe_atok_n = strlen(is_string($fe_tts['alexa_token']) ? $fe_tts['alexa_token'] : '');
$fe_alaut_anz = (is_array($fe_x2) && array_key_exists('tts_alexa_laut', $fe_x2['werte']))
    ? (string) $fe_x2['werte']['tts_alexa_laut']
    : ((is_int($fe_tanz['alexa_laut']) && $fe_tanz['alexa_laut'] >= 0) ? (string) $fe_tanz['alexa_laut'] : '');
$fe_aweg = is_array($fe_x2) && isset($fe_x2['werte']['tts_alexa_token_loeschen']) && $fe_x2['werte']['tts_alexa_token_loeschen'] === '1'; ?>
<div id="tts_alexa_rows">
<div class="sm-small"><?php echo fer_t('ALEXA.ERKL'); ?></div>
<div class="sm-row">
    <div>
        <label for="tts_alexa_geraet"><?php echo fer_t('ALEXA.L_GERAET'); ?></label>
        <input data-role="none" type="text" id="tts_alexa_geraet" name="tts_alexa_geraet"<?= fe_x2m('tts_alexa_geraet') ?> value="<?= fe_e($fe_tanz['alexa_geraet']) ?>" placeholder="kueche">
        <div class="sm-small"><?php echo fer_t('ALEXA.H_GERAET'); ?></div>
    </div>
    <div>
        <label for="tts_alexa_laut"><?php echo fer_t('ALEXA.L_LAUT'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('tts_alexa_laut') ?>" id="tts_alexa_laut" name="tts_alexa_laut"<?= fe_x2m('tts_alexa_laut') ?> value="<?= fe_e($fe_alaut_anz) ?>" min="0" max="100" placeholder="<?= fe_e(fer_t('ALEXA.P_LAUT')) ?>">
        <div class="sm-small"><?php echo fer_t('ALEXA.H_LAUT'); ?></div>
    </div>
    <div>
        <label for="tts_alexa_token"><?php echo fer_t('ALEXA.L_TOKEN'); ?></label>
        <input data-role="none" type="password" id="tts_alexa_token" name="tts_alexa_token"<?= fe_x2m('tts_alexa_token') ?> value="" autocomplete="new-password" placeholder="<?= fe_e($fe_atok_n > 0 ? sprintf(fer_t('ALEXA.P_TOKEN_DA'), $fe_atok_n) : fer_t('ALEXA.P_TOKEN_LEER')) ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;margin-top:4px;">
            <input data-role="none" type="checkbox" name="tts_alexa_token_loeschen" value="1"<?= $fe_aweg ? ' checked' : '' ?>> <?php echo fer_t('ALEXA.L_TOKEN_LOESCHEN'); ?>
        </label>
        <div class="sm-small"><?php echo fer_t('ALEXA.H_TOKEN'); ?></div>
    </div>
</div>
</div>
<?php /* Ansage-3: Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG, ab Werk
   nicht gewaehlt). Das Sprechtoken reist NIE in die Seite: das Feld ist immer
   leer (Kennwortfeld), der Platzhalter sagt nur, ob eines gespeichert ist und
   wie lang es ist. */
$fe_gtok_n = strlen(is_string($fe_tts['google_token']) ? $fe_tts['google_token'] : '');
$fe_glaut_anz = (is_array($fe_x2) && array_key_exists('tts_google_laut', $fe_x2['werte']))
    ? (string) $fe_x2['werte']['tts_google_laut']
    : ((is_int($fe_tanz['google_laut']) && $fe_tanz['google_laut'] >= 0) ? (string) $fe_tanz['google_laut'] : '');
$fe_gweg = is_array($fe_x2) && isset($fe_x2['werte']['tts_google_token_loeschen']) && $fe_x2['werte']['tts_google_token_loeschen'] === '1'; ?>
<div id="tts_google_rows">
<div class="sm-small"><?php echo fer_t('GOOGLE.ERKL'); ?></div>
<div class="sm-row">
    <div>
        <label for="tts_google_geraet"><?php echo fer_t('GOOGLE.L_GERAET'); ?></label>
        <input data-role="none" type="text" id="tts_google_geraet" name="tts_google_geraet"<?= fe_x2m('tts_google_geraet') ?> value="<?= fe_e($fe_tanz['google_geraet']) ?>" placeholder="Wohnzimmer">
        <div class="sm-small"><?php echo fer_t('GOOGLE.H_GERAET'); ?></div>
    </div>
    <div>
        <label for="tts_google_laut"><?php echo fer_t('GOOGLE.L_LAUT'); ?></label>
        <input data-role="none" type="<?= fe_x2typ('tts_google_laut') ?>" id="tts_google_laut" name="tts_google_laut"<?= fe_x2m('tts_google_laut') ?> value="<?= fe_e($fe_glaut_anz) ?>" min="0" max="100" placeholder="<?= fe_e(fer_t('GOOGLE.P_LAUT')) ?>">
        <div class="sm-small"><?php echo fer_t('GOOGLE.H_LAUT'); ?></div>
    </div>
    <div>
        <label for="tts_google_token"><?php echo fer_t('GOOGLE.L_TOKEN'); ?></label>
        <input data-role="none" type="password" id="tts_google_token" name="tts_google_token"<?= fe_x2m('tts_google_token') ?> value="" autocomplete="new-password" placeholder="<?= fe_e($fe_gtok_n > 0 ? sprintf(fer_t('GOOGLE.P_TOKEN_DA'), $fe_gtok_n) : fer_t('GOOGLE.P_TOKEN_LEER')) ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;margin-top:4px;">
            <input data-role="none" type="checkbox" name="tts_google_token_loeschen" value="1"<?= $fe_gweg ? ' checked' : '' ?>> <?php echo fer_t('GOOGLE.L_TOKEN_LOESCHEN'); ?>
        </label>
        <div class="sm-small"><?php echo fer_t('GOOGLE.H_TOKEN'); ?></div>
    </div>
</div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo fer_t('TEXT.SPEICHERN'); ?></button>
</form>
<form action="index.php" method="post" style="margin-top:8px;">
  <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="margin-top:0;"><?php echo fer_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<!-- ================= Reiter: MQTT (eigener Reiter seit 1.1.5, Hausstandard) ================= -->
<div class="sm-pane<?= fe_aktiv('tab-mqtt') ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>
<form action="index.php" method="post">
  <?php echo fer_fmt(); ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?php echo fer_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<?php if (function_exists('fer_mqtt_gateway_autostart') && fer_mqtt_gateway_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo fer_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($fe_anz['mqtt_enabled']) ? 'checked' : '' ?>> <?php echo fer_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo fer_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic"<?= fe_x2m('mqtt_topic') ?> value="<?= fe_e($fe_anz['mqtt_topic']) ?>" placeholder="ferien">
        <div class="sm-small"><?php echo fer_t('TEXT.NUTZT_DAS'); ?> <b><?php echo fer_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b>.</div>
    </div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo fer_t('TEXT.SPEICHERN'); ?></button>
</form>

<?php
/* Das Abo und die vollstaendige Themenliste.
 *
 * ZWEI FEHLER AUS 1.1.7 STECKEN IN DIESEM ABSCHNITT.
 *
 * Erstens fehlte das Abo ganz. Ohne den Eintrag im MQTT-Gateway kommt am
 * Miniserver nichts an, und das ist die haeufigste Fehlerursache ueberhaupt.
 *
 * Zweitens stand hier eine Aufzaehlung von neun Themen als SPRACHSCHLUESSEL -
 * und die englische Sprachdatei uebersetzte sie: /no_school, /schoolday,
 * /public_holiday. Veroeffentlicht wurden aber immer die deutschen Namen.
 * Wer die Oberflaeche auf Englisch stellte, abonnierte ferien/no_school und
 * bekam nie eine Nachricht, waehrend das Gateway ferien/schulfrei verteilte.
 * Acht der neun genannten Themen waren falsch; nur /name stimmte zufaellig.
 *
 * Deshalb kommt die Liste jetzt aus fer_felder() - derselben Quelle, aus der
 * der Sender seine Themen nimmt. Ein Thema, das hier steht, wird auch
 * gesendet; ein gesendetes fehlt hier nicht. Uebersetzt wird die SPALTE,
 * nicht der Themenname. */
$fe_praefix = trim((string) $fe_cfg['mqtt_topic']) !== '' ? trim((string) $fe_cfg['mqtt_topic']) : 'ferien';
?>
<div class="sm-step"><b><?php echo fer_t('T12.MQ_ABO'); ?></b><br>
<?php echo fer_t('T12.MQ_ABO_H'); ?>
<table class="sm-tbl">
<tr><th><?php echo fer_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo fer_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo fer_t('T12.MQ_EINTRAG'); ?></td><td><span class="sm-mono"><?= fe_e($fe_praefix) ?>/#</span></td></tr>
</table>
<b><?php echo fer_abo_text(); ?></b>
</div>

<h2><?php echo fer_t('T12.MQ_THEMEN'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?= sprintf(fe_e(fer_t('T12.MQ_THEMEN_H')),
    count(fer_felder()) + count(fer_mqtt_texte($fe_st)) + count(fer_mqtt_lebenszeichen($fe_st))) ?>
<?php echo fer_t('MELD.MQ_RETAIN_H'); ?></div>
<?php /* M4 (1.2.16): Spalte "zurueckbehalten" (Entscheidung 3). Alle Themen
   dieser Linie sind Tageswerte oder Dienstaussagen und gehen mit "publish"
   hinaus (Entscheidung 3/8) - die Spalte steht trotzdem da, damit man es
   nachlesen kann und ein spaeteres retained Thema hier auffaellt. */ ?>
<table class="sm-tbl" style="width:100%;">
<tr><th><?php echo fer_t('T12.MQ_THEMA'); ?></th><th><?php echo fer_t('T12.MQ_FELD'); ?></th><th><?php echo fer_t('TEXT.BEDEUTUNG'); ?></th><th><?php echo fer_t('MELD.MQ_RETAIN'); ?></th></tr>
<?php foreach (fer_felder() as $fe_fn => $fe_fd) { ?>
<tr><td><span class="sm-mono"><?= fe_e($fe_praefix . '/' . $fe_fd[5]) ?></span></td>
<td><span class="sm-mono"><?= fe_e($fe_fn) ?></span></td><td><?= fe_e($fe_fd[4]) ?></td><td><?php echo fer_t('MELD.NEIN'); ?></td></tr>
<?php } ?>
<?php foreach (fer_mqtt_texte($fe_st) as $fe_tt => $fe_tw) { ?>
<tr><td><span class="sm-mono"><?= fe_e($fe_praefix . '/' . $fe_tt) ?></span></td>
<td><span class="sm-small"><?php echo fer_t('T12.MQ_NUR_MQTT'); ?></span></td>
<td><?php echo fer_t('T12.MQ_TEXTWERT'); ?> <span class="sm-mono"><?= fe_e($fe_tw) ?></span></td><td><?php echo fer_t('MELD.NEIN'); ?></td></tr>
<?php } ?>
<?php foreach (fer_mqtt_lebenszeichen($fe_st) as $fe_tt => $fe_tw) { ?>
<tr><td><span class="sm-mono"><?= fe_e($fe_praefix . '/' . $fe_tt) ?></span></td>
<td><span class="sm-small"><?php echo fer_t('T12.MQ_NUR_MQTT'); ?></span></td>
<td><?php echo fer_t($fe_tt === 'datum' ? 'MELD.MQ_DATUM' : 'MELD.MQ_TS'); ?></td><td><?php echo fer_t('MELD.NEIN'); ?></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-pane<?= fe_aktiv('tab-loxone') ?>" id="tab-loxone">
<h2><?php echo fer_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo fer_t('TEXT.DER_MINISERVER_BEKOMMT_FERTIG_AUSG'); ?> <b><?php echo fer_t('TEXT.IST_HEUTE_SCHULFREI_IST_MORGEN_SCH'); ?></b>
<?php echo fer_t('TEXT.DAMIT_LASSEN_SICH_WECKER_MORGEN_BR'); ?></p>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo fer_t('TEXT.ABFRAGE_ALLE_300_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo fer_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo fer_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $fe_host ?><?php echo fer_t('TEXT.PLUGINS'); ?><?= fe_e($fe_plugin) ?><?php echo fer_t('TEXT.FERIEN_PHP'); ?></span></td></tr>
<tr><td><?php echo fer_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo fer_t('TEXT.300_SEKUNDEN'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_2_BEFEHLSERKENNUNGEN'); ?></b> (<span class="sm-mono">\i...\i</span> <?php echo fer_t('TEXT.SUCHTEXT'); ?> <span class="sm-mono">\v</span> <?php echo fer_t('TEXT.ZAHL_DAHINTER'); ?>
<?php
/* Die Tabelle entsteht aus fer_felder() - derselben Quelle wie die
   Textzeile, die MQTT-Themen und die Importdatei.

   Bis 1.1.7 stand sie von Hand da, mit einem Sprachschluessel je Zeile. Das
   hat zwei Nachteile, und beide sind in diesem Plugin eingetreten: die
   Anleitung veraltet still, sobald ein Feld dazukommt, und die englische
   Sprachdatei kann etwas anderes behaupten als der Code tut - genau so sind
   im Reiter MQTT acht falsche Themennamen entstanden.

   Uebersetzt werden jetzt die SPALTENUEBERSCHRIFTEN. Die Befehlserkennung
   ist Technik und in jeder Sprache dieselbe. */
?>
<table class="sm-tbl" style="width:100%;">
<tr><th><?php echo fer_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo fer_t('T12.LX_EINHEIT'); ?></th><th><?php echo fer_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (fer_felder() as $fe_fn => $fe_fd) { ?>
<tr><td><span class="sm-mono"><?= fe_e(fer_check($fe_fn)) ?></span></td>
<td class="sm-small"><?= $fe_fd[3] !== '' ? fe_e($fe_fd[3]) : ($fe_fd[0] ? '' : '0/1') ?></td>
<td><?= fe_e($fe_fd[4]) ?><?= ($fe_fd[1] < 0) ? ' <span class="sm-small">' . fe_e(fer_t('T12.LX_MINUS1')) . '</span>' : '' ?></td></tr>
<?php } ?>
</table>
<div class="sm-small" style="margin-top:6px;"><?= sprintf(fe_e(fer_t('T12.LX_ANZAHL')), count(fer_felder())) ?></div>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_3_KACHELN_FR_DIE_APP'); ?></b><br>
<?php echo fer_t('TEXT.FERIENIN_UND_FERIENREST_ALS_ANALOG'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.V_0_TAGE'); ?></span> <?php echo fer_t('TEXT.FERIEN_IN_X_TAGEN_IST_ERFAHRUNGSGE'); ?>
</div>

<h2><?php echo fer_t('TEXT.H_VORLAGE'); ?></h2>
<div class="sm-hinweis"><?php echo fer_t('TEXT.H_VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo fer_t('LEGENDE.TECHNIK'); ?></span>
</div>
<form action="index.php" method="post" style="margin-bottom:14px;">
  <?php echo fer_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo fer_t('TEXT.K_VORLAGE'); ?></button>
</form>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<?php /* X-8 (02.10.2026, Entscheidung 36): Komplette Baustein-Liste in der Hausform
         # | Baustein (Typ) | Name (Vorschlag) | Parameter | Eingaenge verbinden mit.
         Bis 1.2.20 fuenf Teiltabellen ohne Nummer; die Teilueberschriften 4a-4e
         stehen jetzt unter der Tabelle mit ihren Zeilennummern. Titel, Adresse,
         Suchtexte, Grenzen und Einheiten kommen aus DENSELBEN Funktionen wie die
         Importdatei (fer_vorlage_kopf(), fer_eingangsbefehle()). Typ, Name, Parameter
         und Verbindung der Logik stehen in [BAUSTEIN] der Sprachdateien; {Kennung}
         in einem Text wird zur laufenden Nummer "#n".
         Zeile: array(Kennung, Typ, Name, Parameter, Argumente, Verbindung). Ein Name
         als array('text' => ...) ist fertig (aus dem Code, maskiert) und geht nicht
         noch einmal durch fer_t(). */
$fe_bs_m = function ($s) { return '<span class="sm-mono">' . fe_e($s) . '</span>'; };
$fe_bs_kopf = fer_vorlage_kopf();
$fe_bs = array(
    array('VI', 'T_VI', array('text' => fe_e($fe_bs_kopf['title'])), 'P_VI',
          array($fe_bs_m($fe_bs_kopf['address']), fe_e($fe_bs_kopf['polling'])), 'V_KEINE'),
);
$fe_bs_letztes = 'VI';
foreach (fer_eingangsbefehle() as $fe_bc) {
    $fe_bs_letztes = 'F_' . $fe_bc['feld'];
    $fe_bs[] = array($fe_bs_letztes, 'T_VI_BEFEHL', array('text' => fe_e($fe_bc['title'])), 'P_VI_BEFEHL',
                     array(fe_e($fe_bc['comment']), $fe_bs_m($fe_bc['check']), (int) $fe_bc['min'],
                           (int) $fe_bc['max'], $fe_bs_m($fe_bc['unit'])), 'V_UNTER_VI');
}
foreach (array(
    // 4a) Wecker und Briefing nur an Schultagen
    array('S1', 'T_SCHWELLE', 'S1_NAME', 'P_SCHWELLE', array(), 'S1_VERB'),
    array('S2', 'T_SCHWELLE', 'S2_NAME', 'P_SCHWELLE', array(), 'S2_VERB'),
    array('U1', 'T_UND', 'U1_NAME', 'U1_PARAM', array(), 'U1_VERB'),
    array('U2', 'T_UND', 'U2_NAME', 'U2_PARAM', array(), 'U2_VERB'),
    array('N1', 'T_NICHT', 'N1_NAME', 'P_KEINE', array(), 'N1_VERB'),
    array('U3', 'T_UND', 'U3_NAME', 'U3_PARAM', array(), 'U3_VERB'),
    // 4b) Vorabend-Meldung
    array('S3', 'T_SCHWELLE', 'S3_NAME', 'P_SCHWELLE', array(), 'S3_VERB'),
    array('S4', 'T_SCHWELLE', 'S4_NAME', 'P_SCHWELLE', array(), 'S4_VERB'),
    array('U4', 'T_UND', 'U4_NAME', 'P_KEINE', array(), 'U4_VERB'),
    array('O1', 'T_ODER', 'O1_NAME', 'O1_PARAM', array(), 'O1_VERB'),
    array('NB1', 'T_BENACHR', 'NB1_NAME', 'NB1_PARAM', array(), 'NB1_VERB'),
    array('SP', 'T_SCHWELLE', 'SP_NAME', 'P_SCHWELLE', array(), 'SP_VERB'),
    array('NB2', 'T_BENACHR_TEST', 'NB2_NAME', 'NB2_PARAM', array(), 'NB2_VERB'),
    // 4c) Ferien-Countdown und Brueckentage
    array('ST1', 'T_STATUS', 'ST1_NAME', 'ST1_PARAM', array(), 'ST1_VERB'),
    array('S5', 'T_SCHWELLE', 'S5_NAME', 'P_SCHWELLE', array(), 'S5_VERB'),
    array('UB', 'T_UND', 'UB_NAME', 'P_KEINE', array(), 'UB_VERB'),
    array('NB3', 'T_BENACHR_OPT', 'NB3_NAME', 'NB3_PARAM', array(), 'NB3_VERB'),
    array('S6', 'T_SCHWELLE', 'S6_NAME', 'S6_PARAM', array(), 'S6_VERB'),
    // 4d) Urlaubsmodus (Abwesenheit)
    array('S7', 'T_SCHWELLE', 'S7_NAME', 'P_SCHWELLE', array(), 'S7_VERB'),
    array('S8', 'T_SCHWELLE', 'S8_NAME', 'P_SCHWELLE', array(), 'S8_VERB'),
    array('O2', 'T_ODER', 'O2_NAME', 'O2_PARAM', array(), 'O2_VERB'),
    array('AW', 'T_ANWESEND', 'AW_NAME', 'P_KEINE', array(), 'AW_VERB'),
    array('N2', 'T_NICHT', 'N2_NAME', 'P_KEINE', array(), 'N2_VERB'),
    array('U5', 'T_UND', 'U5_NAME', 'U5_PARAM', array(), 'U5_VERB'),
    array('RR', 'T_RAUM', 'RR_NAME', 'RR_PARAM', array(), 'RR_VERB'),
    array('VB', 'T_VERBR', 'VB_NAME', 'VB_PARAM', array(), 'VB_VERB'),
    array('ST2', 'T_STATUS', 'ST2_NAME', 'ST2_PARAM', array(), 'ST2_VERB'),
    // 4e) Neue Werte ab Fassung 1.2.0
    array('S9', 'T_SCHWELLE', 'S9_NAME', 'S9_PARAM', array(), 'S9_VERB'),
    array('S10', 'T_SCHWELLE', 'S10_NAME', 'P_SCHWELLE', array(), 'S10_VERB'),
    array('S11', 'T_SCHWELLE', 'S11_NAME', 'S11_PARAM', array(), 'S11_VERB'),
    array('ST3', 'T_STATUS', 'ST3_NAME', 'ST3_PARAM', array(), 'ST3_VERB'),
    array('S12', 'T_SCHWELLE', 'S12_NAME', 'P_SCHWELLE', array(), 'S12_VERB'),
) as $fe_z) { $fe_bs[] = $fe_z; }
$fe_bs_teile = array(
    array(fer_t('BAUSTEIN.ABSCHNITT_VI'), 'VI', $fe_bs_letztes),
    array(fer_t('TEXT.4A_WECKER_UND_BRIEFING_NUR_AN_SCHU'), 'S1', 'U3'),
    array(fer_t('TEXT.4B_VORABEND_MELDUNG_MORGEN_IST_FRE'), 'S3', 'NB2'),
    array(fer_t('TEXT.4C_FERIEN_COUNTDOWN_UND_BRCKENTAGE'), 'ST1', 'S6'),
    array(fer_t('TEXT.4D_URLAUBSMODUS_ABWESENHEIT'), 'S7', 'ST2'),
    array(fer_t('T12.BL_4E'), 'S9', 'S12'),
);
$fe_bs_nr = array();
foreach ($fe_bs as $fe_i => $fe_z) { $fe_bs_nr[$fe_z[0]] = $fe_i + 1; }
$fe_bs_t = function ($z) use ($fe_bs_nr) {
    if (is_array($z)) { return $z['text']; }
    return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($m) use ($fe_bs_nr) {
        return isset($fe_bs_nr[$m[1]]) ? '#' . $fe_bs_nr[$m[1]] : $m[0];
    }, (string) fer_t('BAUSTEIN.' . $z));
}; ?>
<?php echo $fe_bs_t('TEXT'); ?>
<table class="sm-tbl" style="width:100%;">
<tr><th>#</th><th><?php echo fer_t('BAUSTEIN.T_TYP'); ?></th><th><?php echo fer_t('BAUSTEIN.T_NAME'); ?></th><th><?php echo fer_t('BAUSTEIN.T_PARAM'); ?></th><th><?php echo fer_t('BAUSTEIN.T_VERB'); ?></th></tr>
<?php foreach ($fe_bs as $fe_i => $fe_z) {
    $fe_p = $fe_bs_t($fe_z[3]);
    if ($fe_z[4]) { $fe_p = vsprintf($fe_p, $fe_z[4]); } ?>
<tr><td><?= $fe_i + 1 ?></td><td><?php echo $fe_bs_t($fe_z[1]); ?></td><td><span class="sm-mono"><?php echo $fe_bs_t($fe_z[2]); ?></span></td><td><?php echo $fe_p; ?></td><td><?php echo $fe_bs_t($fe_z[5]); ?></td></tr>
<?php } ?>
</table>
<div class="sm-small" style="margin-top:6px;">
<?php foreach ($fe_bs_teile as $fe_tl) { ?>
<b><?php echo $fe_tl[0]; ?></b> <?= sprintf(fe_e(fer_t('BAUSTEIN.ZEILEN')), '#' . $fe_bs_nr[$fe_tl[1]], '#' . $fe_bs_nr[$fe_tl[2]]) ?><br>
<?php } ?>
</div>
<div class="sm-small" style="margin-top:6px;"><?php echo $fe_bs_t('ERLAEUTERUNG'); ?></div>
<div class="sm-small" style="margin-top:6px;"><?php echo fer_t('T12.BL_4E_H'); ?></div>
<b><?php echo fer_t('TEXT.PRAXIS_ERFAHRUNGEN_ZUM_BENACHRICHT'); ?></b> <?php echo fer_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_5_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo fer_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?> <span class="sm-mono">http://<?= $fe_host ?>/plugins/<?= fe_e($fe_plugin) ?><?php echo fer_t('TEXT.FERIEN_PHP_JSON_1'); ?></span>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.AKTIONSTOKEN'); ?></b><br>
<?php echo fer_t('TEXT.TOKEN_ERKLAERUNG'); ?>
<table class="sm-tbl">
<tr><th><?php echo fer_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo fer_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo fer_t('TEXT.AKTUELLES_TOKEN'); ?></td><td><span class="sm-mono"><?= fe_e($fe_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><span class="sm-mono">?say=1</span></td><td><span class="sm-mono">/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?say=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><span class="sm-mono">?ptest=1</span></td><td><span class="sm-mono">/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?ptest=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><span class="sm-mono">?selftest=1</span></td><td><span class="sm-mono">/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?selftest=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <?php /* Die Farbklassen greifen auf dem KNOPF, nicht auf der Reihe.
       Bis 1.1.7 stand sm-b-aktion am umgebenden div, und dieser eine Knopf
       war als einziger der Oberflaeche ein grauer Browserknopf ohne
       Mindestbreite - waehrend die Legende darueber eine Farbkennzeichnung
       versprach. */ ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo fer_t('TEXT.K_TOKEN_NEU'); ?></button>
  </form>
</div>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?= fe_aktiv('tab-test') ?>" id="tab-test">
<h2><?php echo fer_t('REITER.TEST'); ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo fer_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo fer_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>

<?php
/* Die Selbstpruefung.
 *
 * Sie laeuft NUR, wenn dieser Reiter serverseitig der aktive ist - eine
 * Pruefung, die den eigenen Endpunkt aufruft, gehoert nicht in jeden
 * Seitenaufbau. Die Zeitschranke betraegt drei Sekunden, und die zweite
 * Frage an den Endpunkt wird nur gestellt, wenn die erste ueberhaupt eine
 * Antwort bekommen hat.
 *
 * Die Bilanz zaehlt einen Hinweis NICHT als bestanden. Ein "22 von 22" ist
 * ein bekannter Weg, jemanden zu beruhigen, waehrend nichts funktioniert.
 */
if ($fe_tab === 'tab-test' && function_exists('fer_selbsttest')) {
    $fe_basis = 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')
              . '/plugins/' . $fe_plugin;
    $fe_pruef = fer_selbsttest($fe_basis, $fe_vor_heilung);
    $fe_bil = fer_selbsttest_bilanz($fe_pruef);
?>
<div class="sm-alert <?= $fe_bil['schlecht'] ? 'sm-warn' : 'sm-ok' ?>">
<b><?= sprintf(fe_e(fer_t('T12.ST_BILANZ')), (int) $fe_bil['gut'], (int) $fe_bil['gesamt']) ?></b>
<?php if ($fe_bil['offen']) { ?> <?= sprintf(fe_e(fer_t('T12.ST_OFFEN')), (int) $fe_bil['offen']) ?><?php } ?>
</div>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:34px;"></th><th><?php echo fer_t('T12.ST_FRAGE'); ?></th><th><?php echo fer_t('T12.ST_BEFUND'); ?></th></tr>
<?php foreach ($fe_pruef as $fe_z) { ?>
<tr><td style="text-align:center;font-weight:700;<?= $fe_z['ok'] === null ? 'color:#888;' : ($fe_z['ok'] ? 'color:#6dac20;' : 'color:#e0620d;') ?>"><?= $fe_z['ok'] === null ? '&ndash;' : ($fe_z['ok'] ? '&check;' : '&times;') ?></td>
<td><?= fe_e($fe_z['frage']) ?></td><td class="sm-small"><?= fe_e($fe_z['hinweis']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small" style="margin:6px 0 4px;"><?php echo fer_t('T12.ST_FUSSNOTE'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('T12.ST_NICHT_GELAUFEN'); ?>
<a href="index.php?form=test"><?php echo fer_t('T12.ST_JETZT'); ?></a></div>
<?php } ?>

<?php /* Ferien-b1/Ferien-1 (Verbesserungsbau 01.10.2026): wer liest dieses
   Plugin, auf welchem Weg, wann zuletzt, mit welchem Ergebnis. Eine Auskunft,
   keine Pruefzeile: sie zaehlt nicht in die Bilanz oben, und "noch kein Abruf
   vermerkt" ist kein Kreuz - ein Leser kann seltener fragen, als die Liste
   alt ist (sie beginnt nach einem Neustart leer). */
if (function_exists('fer_leser_liste')) { $fe_lsr = fer_leser_liste(); ?>
<h3 class="sm-h3" id="leser"><?php echo fer_t('LESER.H'); ?></h3>
<div class="sm-small"><?= fe_e(sprintf(fer_t('LESER.ERKL'), $fe_lsr['ordner'])) ?></div>
<?php if (!$fe_lsr['ordner_ok']) { ?><div class="sm-alert sm-warn"><?= fe_e(sprintf(fer_t('LESER.KEIN_ORDNER'), $fe_lsr['ordner'])) ?></div><?php } ?>
<table class="sm-tbl" style="width:100%;" id="leser-tabelle">
<tr><th><?php echo fer_t('LESER.SP_LESER'); ?></th><th><?php echo fer_t('LESER.SP_INST'); ?></th><th><?php echo fer_t('LESER.SP_WEG'); ?></th><th><?php echo fer_t('LESER.SP_ZULETZT'); ?></th><th><?php echo fer_t('LESER.SP_ERGEBNIS'); ?></th><th><?php echo fer_t('LESER.SP_ANZAHL'); ?></th><th><?php echo fer_t('LESER.SP_ERKANNT'); ?></th></tr>
<?php foreach ($fe_lsr['zeilen'] as $fe_lz) { $fe_le = $fe_lz['eintrag']; ?>
<tr><td><?= fe_e($fe_lz['titel'] === 'unbekannt' ? fer_t('LESER.UNBEKANNT') : $fe_lz['titel']) ?></td>
<td><?= fe_e($fe_lz['installiert'] === '' ? '-' : ($fe_lz['installiert'] === null ? fer_t('LESER.UNKLAR') : fer_t($fe_lz['installiert'] ? 'LESER.JA' : 'LESER.NEIN'))) ?></td>
<?php if ($fe_le === null) { ?><td colspan="5" class="sm-small"><?php echo fer_t('LESER.NOCH_NIE'); ?></td>
<?php } else { ?><td><?= fe_e(fe_leser_weg($fe_le['weg'])) ?></td><td><?= fe_e(fe_leser_zeit($fe_le['zeit'])) ?></td>
<td><?= fe_e(fe_leser_ergebnis($fe_le['ergebnis'])) ?></td><td><?= (int) $fe_le['anzahl'] ?></td>
<td class="sm-small"><?= fe_e(fe_leser_erkannt($fe_le['erkannt'], $fe_le['hinweis'])) ?></td><?php } ?></tr>
<?php } ?>
</table>
<div class="sm-small" style="margin:6px 0 4px;"><?php echo fer_t('LESER.HINWEIS_QUELLE'); ?></div>
<?php } ?>

<?php /* Ferien-a1 (Verbesserungsbau 01.10.2026): Probe gegen die Quelle
   (DE-BY). Grau: der Knopf aendert nichts an der Anlage - er fragt die Quelle
   und zeigt die Antwort; die Termindatei bleibt, wie sie ist. */ ?>
<h3 class="sm-h3" id="quellprobe"><?php echo fer_t('QPROBE.H'); ?></h3>
<div class="sm-small"><?php echo fer_t('QPROBE.ERKL'); ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="quellprobe" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="margin-top:0;"><?php echo fer_t('QPROBE.KNOPF'); ?></button>
</form>
</div>
<?php $fe_qp = function_exists('fer_quellprobe_lesen') ? fer_quellprobe_lesen() : null;
if ($fe_qp === null) { ?>
<div class="sm-small" id="quellprobe-leer"><?php echo fer_t('QPROBE.NOCH_KEINE'); ?></div>
<?php } else { ?>
<div class="sm-small" id="quellprobe-stand"><b><?= fe_e(sprintf(fer_t('QPROBE.STAND'), date('d.m.Y H:i:s', $fe_qp['zeit']), $fe_qp['region'])) ?></b></div>
<table class="sm-tbl" style="width:100%;" id="quellprobe-teile">
<tr><th><?php echo fer_t('QPROBE.SP_TEIL'); ?></th><th>HTTP</th><th><?php echo fer_t('QPROBE.SP_DAUER'); ?></th><th><?php echo fer_t('QPROBE.SP_GROESSE'); ?></th><th><?php echo fer_t('QPROBE.SP_EINTRAEGE'); ?></th></tr>
<?php foreach ($fe_qp['teile'] as $fe_qt) { ?>
<tr><td class="sm-mono"><?= fe_e($fe_qt['endpunkt']) ?></td>
<td><?= $fe_qt['status'] > 0 ? (int) $fe_qt['status'] : fe_e(fer_t('QPROBE.KEINE_ANTWORT')) ?></td>
<td><?= (int) $fe_qt['ms'] ?> ms</td><td><?= (int) $fe_qt['bytes'] ?> B</td>
<td><?= $fe_qt['anzahl'] >= 0 ? (int) $fe_qt['anzahl'] : '-' ?></td></tr>
<?php } ?>
</table>
<?php foreach ($fe_qp['teile'] as $fe_qt) { $fe_qe = $fe_qt['eintraege']; ?>
<div class="sm-small" style="margin-top:10px;"><b class="sm-mono"><?= fe_e($fe_qt['endpunkt']) ?></b> <span class="sm-mono"><?= fe_e($fe_qt['url']) ?></span></div>
<?php if ($fe_qt['endpunkt'] === 'SchoolHolidays' && $fe_qe) {
    $fe_qf = 0;
    foreach ($fe_qe as $fe_qx) { if ($fe_qx['wt_bis'] === 5) { $fe_qf++; } } ?>
<div class="sm-small quellprobe-freitag"><?= fe_e(sprintf(fer_t('QPROBE.FREITAG'), $fe_qf, count($fe_qe))) ?></div>
<table class="sm-tbl"><tr><th><?php echo fer_t('QPROBE.SP_VON'); ?></th><th><?php echo fer_t('QPROBE.SP_BIS'); ?></th><th><?php echo fer_t('QPROBE.SP_WT'); ?></th><th><?php echo fer_t('QPROBE.SP_NAME'); ?></th></tr>
<?php foreach ($fe_qe as $fe_qx) { ?>
<tr><td><?= fe_e($fe_qx['von']) ?></td><td><?= fe_e($fe_qx['bis']) ?></td><td><?= $fe_qx['wt_bis'] >= 1 && $fe_qx['wt_bis'] <= 7 ? fe_e(fer_t('TAG.T' . $fe_qx['wt_bis'])) : '-' ?></td><td><?= fe_e($fe_qx['name']) ?></td></tr>
<?php } ?>
</table>
<?php } elseif ($fe_qt['endpunkt'] === 'PublicHolidays' && $fe_qe) {
    $fe_qo = array();
    foreach ($fe_qe as $fe_qx) { if ($fe_qx['bereich'] !== '' && $fe_qx['bereich'] !== 'National') { $fe_qo[] = $fe_qx; } }
    if ($fe_qo) { ?>
<table class="sm-tbl"><tr><th><?php echo fer_t('QPROBE.SP_VON'); ?></th><th><?php echo fer_t('QPROBE.SP_NAME'); ?></th><th><?php echo fer_t('QPROBE.SP_BEREICH'); ?></th><th><?php echo fer_t('QPROBE.SP_CODES'); ?></th></tr>
<?php foreach ($fe_qo as $fe_qx) { ?>
<tr><td><?= fe_e($fe_qx['von']) ?></td><td><?= fe_e($fe_qx['name']) ?></td><td><?= fe_e($fe_qx['bereich']) ?></td><td class="sm-mono"><?= fe_e($fe_qx['codes'] !== '' ? $fe_qx['codes'] : '-') ?></td></tr>
<?php } ?>
</table>
<?php } else { ?>
<div class="sm-small"><?php echo fer_t('QPROBE.ORTLICH_KEINE'); ?></div>
<?php } ?>
<?php } ?>
<div class="sm-small" style="margin-top:4px;"><?= fe_e(sprintf(fer_t('QPROBE.ROH'), strlen($fe_qt['roh']), (int) $fe_qt['bytes'])) ?></div>
<div class="sm-log quellprobe-roh"><?= fe_e($fe_qt['roh']) ?></div>
<?php } ?>
<?php } ?>

<h3 class="sm-h3"><?php echo fer_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php" target="_blank"><?php echo fer_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?json=1" target="_blank"><?php echo fer_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo fer_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?debug=1" target="_blank"><?php echo fer_t('TEXT.DEBUG_ALLE_TERMINE'); ?></a>
</div>

<h3 class="sm-h3"><?php echo fer_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?say=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?>" target="_blank"><?php echo fer_t('TEXT.TEST_ANSAGE'); ?></a>
<?php /* Ansage-3: Testansage ueber Google-Lautsprecher - POST mit Formularmerkmal,
   die Antwortzeile steht danach oben als Meldung. Zu sehen, sobald die Ausgabeart
   gewaehlt oder ein Sprechtoken dafuer gespeichert ist. */
if ($fe_tts['mode'] === 'cc4lox' || (is_string($fe_tts['google_token']) && $fe_tts['google_token'] !== '')) { ?>
<form action="index.php" method="post" style="display:inline;">
  <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="google_test" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:0;"><?php echo fer_t('GOOGLE.KNOPF_TEST'); ?></button>
</form>
<?php } ?>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?ptest=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?>" target="_blank"><?php echo fer_t('TEXT.TEST_PUSHNACHRICHT'); ?></a>
<?php /* C6/O8 (1.2.16): ?refresh=1 fragt die Quelle und SCHREIBT termine.json
   - also mit Token und in der Reihe "Loest etwas aus", nicht mehr grau unter
   "Technische Auskunft". Hoechstens einmal je 5 Minuten. */ ?>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?refresh=1&amp;debug=1&amp;token=<?= fe_e($fe_cfg['aktionstoken']) ?>" target="_blank"><?php echo fer_t('TEXT.NEU_ABRUFEN_DEBUG'); ?></a>
</div>


</div>

<!-- ================= Brueckentage ================= -->
<div class="sm-pane<?= fe_aktiv('tab-bridge') ?>" id="tab-bridge">
<h2><?php echo fer_t('TEXT.BRCKENTAGE_DER_NCHSTEN_12_MONATE'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.WERKTAGE_ZWISCHEN_FEIERTAG_UND_WOC'); ?></div>
<?php /* Welcher Modus gilt, und was er ergibt - als Zahl, nicht als
   Versprechen. Der klassische Modus findet in DE-BY ueber 900 Tage genau
   drei Brueckentage und uebersieht dabei die Zeit zwischen Weihnachten und
   Neujahr geschlossen (gemessen 18.08.2026). Das soll man sehen, bevor man
   sich wundert, warum die Liste so kurz ist. */ ?>
<div class="sm-alert sm-info">
<b><?= fe_e($fe_cfg['bridge_mode'] === 'erweitert' ? fer_t('T12.BM_ERWEITERT') : fer_t('T12.BM_KLASSISCH')) ?></b>
&mdash; <?= sprintf(fe_e(fer_t('T12.BR_GEFUNDEN')), count((array) $fe_st['brueckentage'])) ?>
<?php if ($fe_cfg['bridge_mode'] === 'erweitert') { ?>
<?= ' ' . sprintf(fe_e(fer_t('T12.BR_LUECKE_GILT')), (int) $fe_cfg['bridge_luecke']) ?>
<?php } else { ?>
<br><span class="sm-small"><?php echo fer_t('T12.BR_HINWEIS_ERWEITERT'); ?></span>
<?php } ?>
</div>
<?php if (!empty($fe_st['brueckentage'])) { ?>
<table class="sm-tbl"><tr><th><?php echo fer_t('TEXT.DATUM'); ?></th><th><?php echo fer_t('TEXT.WOCHENTAG'); ?></th><th><?php echo fer_t('TEXT.IN_TAGEN'); ?></th><th><?php echo fer_t('TEXT.ERGIBT'); ?></th></tr>
<?php foreach ((array) $fe_st['brueckentage'] as $fe_t) {
    $fe_ts = strtotime($fe_t);
    $fe_in = (int) floor(($fe_ts - strtotime(date('Y-m-d'))) / 86400);
    $fe_wt = (int) date('N', $fe_ts);
    $fe_erg = $fe_wt === 5 ? fer_t('TX.WE_DOSO') : ($fe_wt === 1 ? fer_t('TX.WE_SADI') : fer_t('TX.WE_VIER')); ?>
<tr><td><?= fe_e(fe_d($fe_t)) ?></td><td><?= fe_e(fe_wochentag($fe_ts)) ?></td>
<td><?= $fe_in <= 0 ? fer_t('TX.HEUTE') : $fe_in ?></td><td><?= $fe_erg ?></td></tr>
<?php } ?></table>
<div class="sm-small" style="margin-top:8px;"><?php echo fer_t('TEXT.EIN_BRUECKENTAG_IST_EIN_WERKTAG_DE'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.ZURZEIT_SIND_KEINE_BRCKENTAGE_BEKA'); ?> <b><?php echo fer_t('REITER.EINSTELLUNGEN'); ?></b><?php echo fer_t('TEXT.OB_BRCKENTAGE_ERKENNEN_AKTIVIERT_U'); ?> <b><?php echo fer_t('REITER.TEST'); ?></b> <?php echo fer_t('TEXT.DIE_DATEN_NEU_AB'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.KOMMENDE_FERIEN'); ?> ================= -->
<div class="sm-pane<?= fe_aktiv('tab-vacation') ?>" id="tab-vacation">
<h2><?php echo fer_t('REITER.FERIEN'); ?></h2>
<?php if (function_exists('fer_data')) { $fe_d = fer_data(); $fe_heute = date('Y-m-d'); ?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.SCHULFERIEN_DES_GEWHLTEN_BUNDESLAN'); ?></div>
<table class="sm-tbl"><tr><th><?php echo fer_t('TX.SP_VON'); ?></th><th><?php echo fer_t('TX.SP_BIS'); ?></th><th><?php echo fer_t('TX.SP_BEZEICHNUNG'); ?></th><th><?php echo fer_t('TEXT.TAGE'); ?></th><th><?php echo fer_t('TX.SP_STATUS'); ?></th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['ferien'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 14) { continue; }
    $fe_tage = (int) round((strtotime($fe_e2['bis']) - strtotime($fe_e2['von'])) / 86400) + 1;
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td><td><?= fe_e(fe_d($fe_e2['bis'])) ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['urlaub']) ? ' <span class="sm-small">' . fer_t('TEXT.URLAUB_ABWESEND_3') . '</span>' : (!empty($fe_e2['eigen']) ? ' <span class="sm-small">' . fer_t('TEXT.EIGENER_TERMIN') . '</span>' : '') ?><?= !empty($fe_e2['ics']) ? ' <span class="sm-small">' . fer_t('T12.AUS_KALENDER') . '</span>' : '' ?></td>
<td><?= $fe_tage ?></td>
<td><?= $fe_in <= 0 ? '<b>' . fer_t('TEXT.LUFT') . '</b>' : sprintf(fer_t($fe_in === 1 ? 'TX.IN_TAG' : 'TX.IN_TAGEN'), $fe_in) ?></td></tr>
<?php } ?></table>

<?php /* Die zweite Region steht in einer EIGENEN Tabelle, nicht gemischt in
   der ersten. Zwei Bundeslaender in einer Liste liest sich wie ein
   Bundesland mit doppelt so vielen Ferien - und genau das soll niemand
   glauben. */ ?>
<?php if (!empty($fe_d['ferien2'])) { ?>
<h2><?= sprintf(fe_e(fer_t('T12.FE2_H')), fe_e($fe_cfg['subdivision2'])) ?></h2>
<table class="sm-tbl"><tr><th><?php echo fer_t('TX.SP_VON'); ?></th><th><?php echo fer_t('TX.SP_BIS'); ?></th><th><?php echo fer_t('TX.SP_BEZEICHNUNG'); ?></th><th><?php echo fer_t('TEXT.TAGE'); ?></th><th><?php echo fer_t('TX.SP_STATUS'); ?></th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['ferien2'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 14) { continue; }
    $fe_tage = (int) round((strtotime($fe_e2['bis']) - strtotime($fe_e2['von'])) / 86400) + 1;
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td><td><?= fe_e(fe_d($fe_e2['bis'])) ?></td>
<td><?= fe_e($fe_e2['name']) ?></td><td><?= $fe_tage ?></td>
<td><?= $fe_in <= 0 ? '<b>' . fer_t('TEXT.LUFT') . '</b>' : sprintf(fer_t($fe_in === 1 ? 'TX.IN_TAG' : 'TX.IN_TAGEN'), $fe_in) ?></td></tr>
<?php } ?></table>
<?php } elseif (trim((string) $fe_cfg['subdivision2']) !== '') { ?>
<div class="sm-alert sm-warn"><?= sprintf(fe_e(fer_t('T12.FE2_LEER')), fe_e($fe_cfg['subdivision2'])) ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.DIE_BIBLIOTHEK_DES_PLUGINS_WURDE_N'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.KOMMENDE_FEIERTAGE'); ?> ================= -->
<div class="sm-pane<?= fe_aktiv('tab-holiday') ?>" id="tab-holiday">
<h2><?php echo fer_t('REITER.FEIERTAGE'); ?></h2>
<?php if (function_exists('fer_data')) { if (!isset($fe_d)) { $fe_d = fer_data(); } $fe_heute = date('Y-m-d'); ?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.GESETZLICHE_FEIERTAGE_DES_GEWHLTEN'); ?></div>
<?php
/* Wie viele der kommenden Feiertage auf ein Wochenende fallen.
 *
 * Je Zeile stand das schon seit 1.1.0 - gezaehlt wurde es nie, und gerade
 * die Summe ist die Auskunft, die jemanden interessiert. Gezaehlt wird ueber
 * ALLE kommenden Feiertage, nicht nur die 18 angezeigten; sonst haengt die
 * Zahl an der Laenge der Tabelle. */
$fe_we_zahl = 0; $fe_ft_zahl = 0;
foreach ((array) $fe_d['feiertage'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute) { continue; }
    $fe_ft_zahl++;
    if ((int) date('N', strtotime($fe_e2['von'])) >= 6) { $fe_we_zahl++; }
}
?>
<div class="sm-alert sm-info"><?= sprintf(fe_e(fer_t('T12.FT_VERLOREN')), (int) $fe_we_zahl, (int) $fe_ft_zahl) ?></div>
<table class="sm-tbl" style="width:100%;"><tr><th><?php echo fer_t('TEXT.DATUM'); ?></th><th><?php echo fer_t('TEXT.WOCHENTAG'); ?></th><th><?php echo fer_t('TX.SP_BEZEICHNUNG'); ?></th><th><?php echo fer_t('TEXT.IN_TAGEN'); ?></th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['feiertage'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 17) { continue; }
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400);
    $fe_wt = (int) date('N', strtotime($fe_e2['von'])); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td>
<td><?= fe_e(fe_wochentag(strtotime($fe_e2['von']))) ?><?= ($fe_wt >= 6) ? ' <span class="sm-small">' . fer_t('TEXT.FLLT_AUFS_WOCHENENDE') . '</span>' : '' ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['eigen']) ? ' <span class="sm-small">' . fer_t('TEXT.EIGENER_TERMIN') . '</span>' : '' ?><?= !empty($fe_e2['ortlich']) ? ' <span class="sm-small">' . fer_t('TEXT.NUR_RTLICH') . '</span>' : '' ?><?php
    /* Art und Halbtag - die beiden Angaben, die die Datenquelle seit jeher
       mitliefert und die das Plugin bis 1.1.7 weggeworfen hat. Fuer DE/AT
       steht hier nie etwas; sichtbar wird es beim Laenderwechsel. */
    if (isset($fe_e2['art']) && $fe_e2['art'] !== '' && $fe_e2['art'] !== 'Public') {
        echo ' <span class="sm-small">[' . fe_e($fe_e2['art']) . ']</span>';
    }
    if (!empty($fe_e2['halbtag'])) {
        echo ' <span class="sm-small">' . fe_e(fer_t('T12.FT_HALBTAG'))
           . (!empty($fe_e2['hinweis']) ? ': ' . fe_e($fe_e2['hinweis']) : '') . '</span>';
    } ?></td>
<td><?= $fe_in <= 0 ? '<b>' . fer_t('TX.HEUTE') . '</b>' : $fe_in ?></td></tr>
<?php } ?></table>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.DIE_BIBLIOTHEK_DES_PLUGINS_WURDE_N'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?= fe_aktiv('tab-log') ?>" id="tab-log">
<h2><?php echo fer_t('REITER.LOG'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.PROTOKOLLIERT_WERDEN_DATENABRUFE_Z'); ?><br><?php echo fer_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= fe_e($fe_logfile) ?></span></div>
<?php if ($fe_loglines) { ?>
<div class="sm-log"><?= fe_e(implode("\n", $fe_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?php /* Orange, nicht rot: Rot ist im Hausstandard nicht vorgesehen -
       es liest sich als Warnung vor einer Gefahr, und ein geleertes
       Protokoll ist keine. Die Farbe sagt hier nur: dieser Knopf
       veraendert etwas. */ ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo fer_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
</form>
</div>
<?php /* I8 (1.2.16): die Fehlerausgabe des Cron (cron.err) - sobald etwas
   darin steht. */
$fe_cronerr = dirname($fe_logfile) . '/cron.err';
if (is_file($fe_cronerr) && @filesize($fe_cronerr) > 0) { ?>
<h3 class="sm-h3">cron.err</h3>
<div class="sm-log"><?= fe_e(implode("\n", array_slice(file($fe_cronerr, FILE_IGNORE_NEW_LINES) ?: array(), -100))) ?></div>
<?php } ?>
<?php /* O9 (1.2.16): Hinweis auf die Ramdisk und die Liste der LoxBerry-
   Logdateien (Regeln/04, Reiter Logdateien). */ ?>
<div class="sm-hinweis"><?php echo fer_t('MELD.LOG_RAMDISK'); ?></div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
</div>


<h2><?= fer_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= fer_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= fer_t('TEXT.SICH_WARNUNG') ?></div>
<?php /* X-3 (Verbesserungsbau 01.10.2026): wuerde das eigene Zurueckspielen die
   Sicherung abweisen, steht es gelb am Knopf - mit den Namen der
   Einstellungen, nie mit den Werten. DIESELBE Pruefung wie beim
   Zurueckspielen; gesichert wird trotzdem vollstaendig. */
$fe_altw = function_exists('fer_rueckspiel_altwerte') ? fer_rueckspiel_altwerte() : array();
if ($fe_altw) { ?>
<div class="sm-warnung" id="sicherung-altwerte"><?= fe_e(sprintf(fer_t('SICHWARN.KNOPF'), implode(', ', $fe_altw))) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo fer_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="fer_sichern" value="1"><?= fer_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo fer_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="fer_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="fer_zurueck" value="1"><?= fer_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>
<script>
function feTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var al = document.getElementById('tts_alexa_rows');    // Ansage-2
    if (al) { al.style.display = (m === 'alexang') ? 'block' : 'none'; }
    var gl = document.getElementById('tts_google_rows');   // Ansage-3
    if (gl) { gl.style.display = (m === 'cc4lox') ? 'block' : 'none'; }
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
function feBruecke() {
    // Das Lueckenfeld hat nur im erweiterten Modus eine Wirkung. Ein
    // Eingabefeld, das nichts bewirkt, ist schlimmer als keines.
    var m = document.getElementById('bridge_mode');
    var f = document.getElementById('luecke_feld');
    if (m && f) { f.style.display = (m.value === 'erweitert') ? '' : 'none'; }
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
        // Damit ein Absenden im richtigen Reiter zurueckkommt.
        document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            // Ohne JavaScript folgt der Browser dem href und der Server
            // liefert den richtigen Reiter. Mit JavaScript geht es schneller
            // ohne Neuladen - deshalb hier den Verweis abfangen.
            //
            // Ausnahme: Reiter mit data-reload. Deren Inhalt entsteht erst,
            // wenn der Server sie als aktiv kennt (Reiter Test - die
            // Selbstpruefung ruft den eigenen Endpunkt auf). Hier NICHT
            // abfangen, sonst zeigt der Reiter eine leere Seite.
            if (t.dataset.reload === '1') { return; }
            ev.preventDefault();
            activate(t.dataset.pane);
        });
    });
    activate(<?= json_encode($fe_tab) ?>);
    feTtsMode();
    feBruecke();
})();
</script>
<?php
if ($fe_frame) { LBWeb::lbfooter(); }
