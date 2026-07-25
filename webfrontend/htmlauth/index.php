<?php
/**
 * Ferien und Feiertage - Admin-Oberflaeche (v1.0.0)
 * Reiter: Einstellungen | Einbindung in Loxone | Brueckentage | Kommende Ferien |
 *         Kommende Feiertage | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein fe_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$fe_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
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

if ((!is_file($fe_cfgfile) || trim((string) @file_get_contents($fe_cfgfile)) === '' || trim((string) @file_get_contents($fe_cfgfile)) === '{}') && is_file($fe_bkfile)) {
    @mkdir($fe_cfgdir, 0775, true);
    @copy($fe_bkfile, $fe_cfgfile);
}

$fe_saved = false; $fe_err = ''; $fe_note = '';
$fe_tab = preg_match('/^tab-(settings|loxone|bridge|vacation|holiday|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($fe_logfile), 0775, true);
    @file_put_contents($fe_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $fe_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetchnow']) && function_exists('fer_fetch')) {
    list($fe_ok, $fe_q) = fer_fetch(true);
    fer_state(true);
    $fe_note = $fe_ok ? ('Daten abgerufen (' . $fe_q . ').') : 'Abruf FEHLGESCHLAGEN - Internetverbindung pruefen (Protokoll beachten).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $fe_new = array();
    $fe_new['country'] = preg_replace('/[^A-Za-z]/', '', (string) (isset($_POST['country']) ? $_POST['country'] : 'DE')) ?: 'DE';
    $fe_new['country'] = strtoupper($fe_new['country']);
    $fe_new['subdivision'] = preg_replace('/[^A-Za-z0-9\-]/', '', (string) (isset($_POST['subdivision']) ? $_POST['subdivision'] : ''));
    $fe_new['lang'] = 'DE';
    $fe_new['school'] = isset($_POST['school']) ? 1 : 0;
    $fe_new['public'] = isset($_POST['public']) ? 1 : 0;
    $fe_loc = (string) (isset($_POST['locality']) ? $_POST['locality'] : '');
    $fe_new['locality'] = in_array($fe_loc, array('', 'DE-BY-AU', 'BY-EV', 'SN-KATH', 'TH-KATH'), true) ? $fe_loc : '';
    $fe_new['local_holidays'] = isset($_POST['local_holidays']) ? 1 : 0;
    $fe_new['bridge'] = isset($_POST['bridge']) ? 1 : 0;
    $fe_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $fe_new['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'ferien')) ?: 'ferien';
    // Eigene Termine
    $fe_new['own'] = array();
    $fe_on = isset($_POST['own_name']) ? (array) $_POST['own_name'] : array();
    $fe_ov = isset($_POST['own_von']) ? (array) $_POST['own_von'] : array();
    $fe_ob = isset($_POST['own_bis']) ? (array) $_POST['own_bis'] : array();
    $fe_ot = isset($_POST['own_typ']) ? (array) $_POST['own_typ'] : array();
    for ($fe_i = 0; $fe_i < 6; $fe_i++) {
        $v = trim((string) (isset($fe_ov[$fe_i]) ? $fe_ov[$fe_i] : ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { continue; }
        $b = trim((string) (isset($fe_ob[$fe_i]) ? $fe_ob[$fe_i] : ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) { $b = $v; }
        $fe_new['own'][] = array(
            'name' => trim((string) (isset($fe_on[$fe_i]) ? $fe_on[$fe_i] : '')),
            'von' => $v, 'bis' => $b,
            'typ' => in_array((string) (isset($fe_ot[$fe_i]) ? $fe_ot[$fe_i] : ''), array('feiertag', 'urlaub'), true) ? (string) $fe_ot[$fe_i] : 'ferien',
        );
    }
    $fe_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'time' => preg_match('/^\d{1,2}:\d{2}$/', (string) (isset($_POST['notify_time']) ? $_POST['notify_time'] : '')) ? $_POST['notify_time'] : '19:00',
        'freetag' => isset($_POST['n_freetag']) ? 1 : 0,
        'ferienstart' => isset($_POST['n_ferienstart']) ? 1 : 0,
        'bridge_month' => isset($_POST['n_bridge']) ? 1 : 0,
    );
    $fe_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $fe_new['tts'] = array(
        'mode' => in_array($fe_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $fe_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    if (!is_dir($fe_cfgdir)) { @mkdir($fe_cfgdir, 0775, true); }
    $fe_json = json_encode($fe_new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($fe_cfgfile, $fe_json) !== false) {
        $fe_saved = true;
        @copy($fe_cfgfile, $fe_bkfile);
        @unlink('/tmp/ferien/state.json');
        @unlink('/tmp/ferien/termine.json');
    } else {
        $fe_err = 'Konfiguration konnte nicht gespeichert werden: ' . $fe_cfgfile;
    }
}

$fe_cfg = function_exists('fer_config') ? fer_config() : array();
if (!is_array($fe_cfg)) { $fe_cfg = array(); }
$fe_cfg += array('country' => 'DE', 'subdivision' => 'DE-BY', 'school' => 1, 'public' => 1,
    'locality' => '', 'local_holidays' => 0, 'bridge' => 1, 'own' => array(), 'mqtt_enabled' => 0, 'mqtt_topic' => 'ferien',
    'notify' => array(), 'tts' => array());
$fe_notify = is_array($fe_cfg['notify']) ? $fe_cfg['notify'] : array();
$fe_notify += array('audio' => 0, 'push' => 0, 'time' => '19:00', 'freetag' => 1, 'ferienstart' => 1, 'bridge_month' => 1);
$fe_tts = is_array($fe_cfg['tts']) ? $fe_cfg['tts'] : array();
$fe_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$fe_st = function_exists('fer_state') ? fer_state() : array();
$fe_subs = function_exists('fer_subdivisions') ? fer_subdivisions($fe_cfg['country']) : array();
$fe_loglines = array();
if (is_file($fe_logfile)) {
    $fe_loglines = array_slice(array_reverse(file($fe_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

function fe_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function fe_d($iso) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $iso) ? date('d.m.Y', strtotime($iso)) : '-'; }

$fe_frame = class_exists('LBWeb', false);
if ($fe_frame) { LBWeb::lbheader('Ferien und Feiertage', 'https://wiki.loxberry.de/', ''); }
$fe_host = fe_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.fe-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.fe-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.fe-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.fe-wrap input[type=text], .fe-wrap input[type=number], .fe-wrap select, .fe-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.fe-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.fe-row { display: flex; gap: 12px; flex-wrap: wrap; }
.fe-row > div { flex: 1; min-width: 150px; }
.fe-row > div > label:not([style]) { min-height: 2.6em; display: flex; align-items: flex-end; }
.fe-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.fe-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.fe-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.fe-err { background: #ffebee; border: 1px solid #ef9a9a; }
.fe-warn { background: #fff8e1; border: 1px solid #ffe082; }
.fe-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.fe-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.fe-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.fe-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.fe-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.fe-tab.fe-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.fe-pane { display: none; padding-top: 4px; }
.fe-pane.fe-active { display: block; }
.fe-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.fe-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.fe-tbl { border-collapse: collapse; margin: 8px 0; }
.fe-tbl th, .fe-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.fe-tbl th { background: #f0f0f0; }
.fe-wrap .fe-btn, .fe-wrap a.fe-btn, .fe-wrap button { text-shadow: none !important; box-shadow: none !important; }
.fe-wrap a.fe-btn, .fe-wrap a.fe-btn:visited, .fe-wrap a.fe-btn:hover { color: #fff !important; text-decoration: none; }
</style>
<div class="fe-wrap">

<?php if ($fe_saved) { ?><div class="fe-alert fe-ok"><b>Konfiguration gespeichert</b> (inkl. Sicherungskopie f&uuml;r Updates). Die Daten werden beim n&auml;chsten Abruf neu geladen.</div><?php } ?>
<?php if ($fe_note !== '') { ?><div class="fe-alert fe-ok"><?= fe_e($fe_note) ?></div><?php } ?>
<?php if ($fe_err !== '') { ?><div class="fe-alert fe-err"><b>Fehler:</b> <?= fe_e($fe_err) ?></div><?php } ?>

<?php if (!empty($fe_st)) { ?>
<div class="fe-alert fe-info">
<?php if ($fe_st['ok']) { ?>
<b>Heute (<?= fe_e(fe_d($fe_st['heute']['datum'])) ?>):</b>
<?= $fe_st['heute']['schulfrei'] ? '<b>schulfrei</b>' : 'normaler Schul-/Arbeitstag' ?>
<?= $fe_st['heute']['feiertag'] ? ' &middot; Feiertag: <b>' . fe_e($fe_st['heute']['feiertag_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['ferien'] ? ' &middot; Ferien: <b>' . fe_e($fe_st['heute']['ferien_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['bruecke'] ? ' &middot; <b>Br&uuml;ckentag</b>' : '' ?><br>
<b>Morgen:</b> <?= $fe_st['morgen']['schulfrei'] ? '<b>schulfrei</b>' : 'Schul-/Arbeitstag' ?>
<?= $fe_st['morgen']['feiertag'] ? ' (' . fe_e($fe_st['morgen']['feiertag_name']) . ')' : '' ?>
<?= $fe_st['morgen']['ferien'] ? ' (' . fe_e($fe_st['morgen']['ferien_name']) . ')' : '' ?><br>
<?php if ($fe_st['naechste']['in'] >= 0) { ?>
<?= $fe_st['naechste']['rest'] > 0
    ? 'Laufende Ferien: <b>' . fe_e($fe_st['naechste']['name']) . '</b> noch <b>' . (int) $fe_st['naechste']['rest'] . ' Tage</b> (bis ' . fe_e(fe_d($fe_st['naechste']['bis'])) . ')'
    : 'N&auml;chste Ferien: <b>' . fe_e($fe_st['naechste']['name']) . '</b> in <b>' . (int) $fe_st['naechste']['in'] . ' Tagen</b> (' . fe_e(fe_d($fe_st['naechste']['von'])) . ' bis ' . fe_e(fe_d($fe_st['naechste']['bis'])) . ', ' . (int) $fe_st['naechste']['dauer'] . ' Tage)' ?><br>
<?php } ?>
<?php if ($fe_st['feiertag_naechster']['in'] >= 0) { ?>
N&auml;chster Feiertag: <b><?= fe_e($fe_st['feiertag_naechster']['name']) ?></b> in <?= (int) $fe_st['feiertag_naechster']['in'] ?> Tagen (<?= fe_e(fe_d($fe_st['feiertag_naechster']['datum'])) ?>)<br>
<?php } ?>
<span class="fe-small">Daten reichen bis <?= fe_e(fe_d($fe_st['reicht_bis'])) ?> &middot; Stand <?= fe_e(substr((string) $fe_st['stand'], 0, 10)) ?></span>
<?php } else { ?>
<b>Noch keine Daten geladen.</b> Bitte unten Land und Bundesland w&auml;hlen, speichern und &bdquo;Jetzt abrufen&ldquo; klicken.
<?php } ?>
</div>
<?php if (!empty($fe_st['warnung'])) { ?><div class="fe-alert fe-warn"><b>Achtung:</b> Die Ferien-/Feiertagsdaten reichen weniger als 60 Tage in die Zukunft. Das Plugin l&auml;dt automatisch nach &mdash; falls das scheitert (Protokoll), bitte die Internetverbindung pr&uuml;fen.</div><?php } ?>
<?php } ?>

<div class="fe-tabs">
    <div class="fe-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="fe-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="fe-tab" data-pane="tab-bridge">Br&uuml;ckentage</div>
    <div class="fe-tab" data-pane="tab-vacation">Kommende Ferien</div>
    <div class="fe-tab" data-pane="tab-holiday">Kommende Feiertage</div>
    <div class="fe-tab" data-pane="tab-test">Test</div>
    <div class="fe-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Einstellungen ================= -->
<div class="fe-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Region</h2>
<div class="fe-row">
    <div>
        <label>Land</label>
        <select data-role="none" name="country">
<?php foreach (array('DE' => 'Deutschland', 'AT' => '&Ouml;sterreich', 'CH' => 'Schweiz', 'LU' => 'Luxemburg',
                     'BE' => 'Belgien', 'NL' => 'Niederlande', 'FR' => 'Frankreich', 'IT' => 'Italien',
                     'PL' => 'Polen', 'CZ' => 'Tschechien') as $fe_k => $fe_v) { ?>
            <option value="<?= $fe_k ?>"<?= $fe_cfg['country'] === $fe_k ? ' selected' : '' ?>><?= $fe_v ?></option>
<?php } ?>
        </select>
        <div class="fe-small">Nach dem Wechsel speichern &mdash; danach stehen unten die passenden Regionen zur Auswahl.</div>
    </div>
    <div>
        <label>Bundesland / Region</label>
<?php if ($fe_subs) { ?>
        <select data-role="none" name="subdivision">
            <option value="">(ganzes Land, nur bundesweite Feiertage)</option>
<?php foreach ($fe_subs as $fe_code => $fe_name) { ?>
            <option value="<?= fe_e($fe_code) ?>"<?= $fe_cfg['subdivision'] === $fe_code ? ' selected' : '' ?>><?= fe_e($fe_name) ?> (<?= fe_e($fe_code) ?>)</option>
<?php } ?>
        </select>
<?php } else { ?>
        <input data-role="none" type="text" name="subdivision" value="<?= fe_e($fe_cfg['subdivision']) ?>" placeholder="z. B. DE-BY">
        <div class="fe-small">Liste konnte nicht geladen werden &mdash; Code direkt eintragen (DE-BW, DE-BY, DE-BE, DE-HH, DE-NW &hellip;).</div>
<?php } ?>
    </div>
</div>
<div class="fe-row" style="margin-top:8px;">
    <div>
        <label>Arbeitsort / Gemeinde (&ouml;rtliche Sonderf&auml;lle)</label>
        <select data-role="none" name="locality">
            <option value=""<?= $fe_cfg['locality'] === '' ? ' selected' : '' ?>>Alle anderen St&auml;dte und Gemeinden</option>
            <option value="DE-BY-AU"<?= $fe_cfg['locality'] === 'DE-BY-AU' ? ' selected' : '' ?>>Stadtgebiet Augsburg &mdash; mit Friedensfest (8.&nbsp;August)</option>
            <option value="BY-EV"<?= $fe_cfg['locality'] === 'BY-EV' ? ' selected' : '' ?>>Bayern: &uuml;berwiegend evangelische Gemeinde &mdash; OHNE Mari&auml; Himmelfahrt</option>
            <option value="SN-KATH"<?= $fe_cfg['locality'] === 'SN-KATH' ? ' selected' : '' ?>>Sachsen: katholische Gemeinde im sorbischen Siedlungsgebiet &mdash; MIT Fronleichnam</option>
            <option value="TH-KATH"<?= $fe_cfg['locality'] === 'TH-KATH' ? ' selected' : '' ?>>Th&uuml;ringen: Eichsfeld bzw. katholische Gemeinde &mdash; MIT Fronleichnam</option>
        </select>
        <div class="fe-small">Drei Feiertage gelten <b>nicht im ganzen Bundesland</b>, sondern nur in bestimmten Gemeinden.
        Die Datenquelle kennt davon nur Augsburg &mdash; die beiden anderen F&auml;lle rechnet das Plugin selbst:<br>
        &bull; <b>Mari&auml; Himmelfahrt</b> ist in Bayern nur in den rund 1.700 &uuml;berwiegend katholischen Gemeinden
        Feiertag; in den &uuml;brigen rund 350 nicht. Wer dort wohnt, w&auml;hlt &bdquo;&uuml;berwiegend evangelische Gemeinde&ldquo;.<br>
        &bull; <b>Fronleichnam</b> ist in Sachsen nur in den katholischen Gemeinden des sorbischen Siedlungsgebiets
        (Landkreis Bautzen) und in Th&uuml;ringen nur im Eichsfeld sowie einigen Gemeinden des Unstrut-Hainich- und
        Wartburgkreises Feiertag &mdash; dort die passende Zeile w&auml;hlen.<br>
        &bull; <b>Friedensfest</b> gilt nur im Stadtgebiet Augsburg.<br>
        Im Zweifel hilft die Gemeinde- oder Stadtverwaltung weiter. F&uuml;r alle anderen Orte bleibt die erste Zeile richtig.</div>
    </div>
</div>
<div style="margin-top:8px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="school" <?= !empty($fe_cfg['school']) ? 'checked' : '' ?>> Schulferien auswerten
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="public" <?= !empty($fe_cfg['public']) ? 'checked' : '' ?>> Gesetzliche Feiertage auswerten
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="local_holidays" <?= !empty($fe_cfg['local_holidays']) ? 'checked' : '' ?>> Auch nur &ouml;rtliche Feiertage
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="bridge" <?= !empty($fe_cfg['bridge']) ? 'checked' : '' ?>> Br&uuml;ckentage erkennen
    </label>
    <div class="fe-small">&bdquo;Auch nur &ouml;rtliche Feiertage&ldquo; nimmt <b>alle</b> ortsgebundenen Feiertage der Region mit, unabh&auml;ngig vom gew&auml;hlten Arbeitsort &mdash; normalerweise ausgeschaltet lassen und stattdessen oben den Arbeitsort w&auml;hlen.
    Ein <b>Br&uuml;ckentag</b> ist ein Werktag zwischen Feiertag und Wochenende (Freitag nach einem Donnerstags-Feiertag bzw. Montag vor einem Dienstags-Feiertag).</div>
</div>
<div class="fe-alert fe-info" style="margin-top:10px;">Datenquelle: <b>openholidaysapi.org</b> &mdash; amtliche Ferien- und Feiertagsdaten,
kostenlos und ohne Konto. Das Plugin l&auml;dt automatisch 18 Monate im Voraus und speichert sie lokal, damit es auch ohne Internet weiterl&auml;uft.
Die j&auml;hrliche Handpflege alter Ferientermine entf&auml;llt damit.</div>

<h2>Eigene Termine (optional)</h2>
<div class="fe-small">F&uuml;r Betriebsferien, Urlaub oder schulfreie Tage, die nicht im amtlichen Kalender stehen.<br>
&bull; <b>Wie Ferien</b> z&auml;hlt in <span class="fe-mono">FERIEN</span>/<span class="fe-mono">SCHULFREI</span>.<br>
&bull; <b>Wie Feiertag</b> zus&auml;tzlich in <span class="fe-mono">FEIERTAG</span>.<br>
&bull; <b>Urlaub (abwesend)</b> bedeutet: Das Haus ist leer. Zus&auml;tzlich zu &bdquo;wie Ferien&ldquo; wird
<span class="fe-mono">URLAUB=1</span> gesetzt, damit Loxone automatisch in den <b>Urlaubsmodus</b> gehen kann &mdash;
Anwesenheitssimulation, Temperaturabsenkung, Steckdosen aus. Mit <span class="fe-mono">URLAUBENDE=1</span> am letzten
Urlaubstag l&auml;sst sich das Haus rechtzeitig wieder vorw&auml;rmen. Verdrahtung siehe Reiter
&bdquo;Einbindung in Loxone&ldquo;, Schritt&nbsp;4d.<br>
Das Datum bezeichnet ganze Tage: Abreise ist der erste, R&uuml;ckkehr der letzte Tag. Damit das Haus bei der Ankunft
warm ist, hebt man den Urlaubsmodus in Loxone am letzten Tag &uuml;ber <span class="fe-mono">URLAUBENDE</span> wieder
auf (Schritt&nbsp;4d) &mdash; ein Vorziehen des &bdquo;bis&ldquo;-Datums ist daf&uuml;r nicht n&ouml;tig.</div>
<table class="fe-tbl" style="width:100%;">
<tr><th style="width:30%;">Bezeichnung</th><th style="width:20%;">von (JJJJ-MM-TT)</th><th style="width:20%;">bis</th><th style="width:24%;">Art</th></tr>
<?php for ($fe_i = 0; $fe_i < 6; $fe_i++) {
    $fe_o = isset($fe_cfg['own'][$fe_i]) ? (array) $fe_cfg['own'][$fe_i] : array();
    $fe_o += array('name' => '', 'von' => '', 'bis' => '', 'typ' => 'ferien'); ?>
<tr>
<td><input data-role="none" type="text" name="own_name[]" value="<?= fe_e($fe_o['name']) ?>" placeholder="<?= $fe_i === 0 ? 'z. B. Betriebsferien' : '' ?>"></td>
<td><input data-role="none" type="text" name="own_von[]" value="<?= fe_e($fe_o['von']) ?>" placeholder="2026-08-03"></td>
<td><input data-role="none" type="text" name="own_bis[]" value="<?= fe_e($fe_o['bis']) ?>" placeholder="2026-08-14"></td>
<td><select data-role="none" name="own_typ[]">
    <option value="ferien"<?= ($fe_o['typ'] !== 'feiertag' && $fe_o['typ'] !== 'urlaub') ? ' selected' : '' ?>>wie Ferien</option>
    <option value="feiertag"<?= $fe_o['typ'] === 'feiertag' ? ' selected' : '' ?>>wie Feiertag</option>
    <option value="urlaub"<?= $fe_o['typ'] === 'urlaub' ? ' selected' : '' ?>>Urlaub (abwesend)</option>
</select></td>
</tr>
<?php } ?>
</table>

<h2>Benachrichtigungen</h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($fe_notify['audio']) ? 'checked' : '' ?>> Audioausgabe aktiv
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($fe_notify['push']) ? 'checked' : '' ?>> Push-Nachricht aktiv
    </label>
    <div class="fe-small">Beides an = Ansage + Push. Nur eines an = nur diese Ausgabe. Beides aus = keine Meldung.
    Die Ansage spricht das Plugin selbst; den Push verschickt der Miniserver &uuml;ber <span class="fe-mono">ANN=1</span> (Anleitung Schritt 4).</div>
</div>
<div class="fe-row">
    <div>
        <label>Meldezeit am Vorabend</label>
        <input data-role="none" type="text" name="notify_time" value="<?= fe_e($fe_notify['time']) ?>" placeholder="19:00">
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;">&nbsp;</label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_freetag" <?= !empty($fe_notify['freetag']) ? 'checked' : '' ?>> Melden, wenn morgen schulfrei ist
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_ferienstart" <?= !empty($fe_notify['ferienstart']) ? 'checked' : '' ?>> Melden am Vorabend des Ferienbeginns
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_bridge" <?= !empty($fe_notify['bridge_month']) ? 'checked' : '' ?>> Im Januar die Br&uuml;ckentage des Jahres melden
        </label>
    </div>
</div>

<h2>Sprachausgabe</h2>
<div class="fe-row">
    <div>
        <label>Audio-Ausgabe</label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="feTtsMode()">
            <option value="musicserver"<?= $fe_tts['mode'] === 'musicserver' ? ' selected' : '' ?>>Loxone Music Server (klassisch)</option>
            <option value="ms4h"<?= $fe_tts['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $fe_tts['mode'] === 'audioserver' ? ' selected' : '' ?>>Original Loxone Audioserver (via Loxone Config)</option>
            <option value="custom"<?= $fe_tts['mode'] === 'custom' ? ' selected' : '' ?>>Eigene URL-Vorlage</option>
        </select>
    </div>
    <div>
        <label>IP des Audio-Servers</label>
        <input data-role="none" type="text" name="tts_ip" value="<?= fe_e($fe_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $fe_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="fe-row">
    <div>
        <label>Zonen</label>
        <input data-role="none" type="text" name="tts_zones" value="<?= fe_e($fe_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="fe-small">Zonennummern mit Komma (z.&nbsp;B. <span class="fe-mono">2,4,6</span>) &mdash; die Lautst&auml;rke kommt aus dem Feld daneben. Optional je Zone eigene Lautst&auml;rke: <span class="fe-mono">Zone~Lautst&auml;rke</span> (z.&nbsp;B. <span class="fe-mono">2~25,4~40</span>). Leerzeichen nach dem Komma sind erlaubt &mdash; <span class="fe-mono">2,4,6</span> und <span class="fe-mono">2, 4, 6</span> funktionieren beide.</div>
    </div>
    <div>
        <label>Lautst&auml;rke (%)</label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $fe_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label>Sprache</label>
        <input data-role="none" type="text" name="tts_lang" value="<?= fe_e($fe_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label>URL-Vorlage (f&uuml;r Audioserver4Home/MS4H bzw. eigene Ausgabe)</label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= fe_e($fe_tts['template']) ?></textarea>
    <div class="fe-small">Platzhalter: <span class="fe-mono">{ip} {port} {zones} {vol} {lang} {text}</span>. Leer = Standard-Vorlage.</div>
</div>
<div id="tts_audioserver_hint" class="fe-alert fe-info" style="display:none;">
    Der originale Loxone Audioserver bietet <b>keine HTTP-TTS-Schnittstelle</b>. In diesem Modus spricht das Plugin NICHT selbst;
    die Sprachausgabe baut man in Loxone Config: Textgenerator &rarr; TTS-Eingang, ausgel&ouml;st &uuml;ber <span class="fe-mono">ANN=1</span>.
</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($fe_cfg['mqtt_enabled']) ? 'checked' : '' ?>> Zustand per MQTT ver&ouml;ffentlichen
</label>
<div class="fe-row" style="margin-top:6px;">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= fe_e($fe_cfg['mqtt_topic']) ?>" placeholder="ferien">
        <div class="fe-small">Nutzt das <b>LoxBerry MQTT Gateway</b>. Ver&ouml;ffentlicht u.&nbsp;a.
        <span class="fe-mono"><?= fe_e($fe_cfg['mqtt_topic']) ?>/schulfrei</span>, <span class="fe-mono">/schultag</span>,
        <span class="fe-mono">/feiertag</span>, <span class="fe-mono">/ferien</span>, <span class="fe-mono">/bruecke</span>,
        <span class="fe-mono">/morgen_schulfrei</span>, <span class="fe-mono">/ferien_in</span>, <span class="fe-mono">/ferien_rest</span>,
        <span class="fe-mono">/name</span>.</div>
    </div>
</div>

<button data-role="none" class="fe-btn" type="submit">Speichern</button>
</form>
<form method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="fe-btn" type="submit" style="background:#607d8b;margin-top:0;">Jetzt abrufen</button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="fe-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Der Miniserver bekommt fertig ausgewertete Schalter: <b>Ist heute schulfrei? Ist morgen Schultag?</b>
Damit lassen sich Wecker, Morgen-Briefing, Rollladen- und Heizzeiten automatisch an Ferien und Feiertage anpassen &mdash;
ohne dass man je wieder Termine von Hand pflegen muss.</p>

<div class="fe-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;Ferien und Feiertage&ldquo;</b> (Abfrage alle 300 s)
<table class="fe-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="fe-mono">http://<?= $fe_host ?>/plugins/<?= fe_e($fe_plugin) ?>/ferien.php</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
</div>

<div class="fe-step"><b>Schritt 2: Befehlserkennungen</b> (<span class="fe-mono">\i...\i</span> = Suchtext, <span class="fe-mono">\v</span> = Zahl dahinter)
<table class="fe-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="fe-mono">\iSCHULTAG=\i\v</span></td><td><b>1 = heute ist ein normaler Schul-/Arbeitstag</b> (Werktag, keine Ferien, kein Feiertag)</td></tr>
<tr><td><span class="fe-mono">\iMSCHULTAG=\i\v</span></td><td><b>1 = MORGEN ist Schultag</b> &mdash; der wichtigste Wert f&uuml;r Wecker und Abendlogik</td></tr>
<tr><td><span class="fe-mono">\iSCHULFREI=\i\v</span> / <span class="fe-mono">\iMSCHULFREI=\i\v</span></td><td>1 = heute bzw. morgen schulfrei (Ferien, Feiertag oder Wochenende)</td></tr>
<tr><td><span class="fe-mono">\iFERIEN=\i\v</span> / <span class="fe-mono">\iMFERIEN=\i\v</span></td><td>1 = heute bzw. morgen Schulferien</td></tr>
<tr><td><span class="fe-mono">\iFEIERTAG=\i\v</span> / <span class="fe-mono">\iMFEIERTAG=\i\v</span></td><td>1 = heute bzw. morgen gesetzlicher Feiertag</td></tr>
<tr><td><span class="fe-mono">\iWOCHENENDE=\i\v</span></td><td>1 = Samstag oder Sonntag</td></tr>
<tr><td><span class="fe-mono">\iBRUECKE=\i\v</span> / <span class="fe-mono">\iMBRUECKE=\i\v</span></td><td>1 = Br&uuml;ckentag (heute bzw. morgen)</td></tr>
<tr><td><span class="fe-mono">\iFERIENIN=\i\v</span></td><td>Tage bis zu den n&auml;chsten Ferien (0 = laufen gerade)</td></tr>
<tr><td><span class="fe-mono">\iFERIENREST=\i\v</span> / <span class="fe-mono">\iFERIENDAUER=\i\v</span></td><td>verbleibende bzw. gesamte Ferientage</td></tr>
<tr><td><span class="fe-mono">\iFEIERTAGIN=\i\v</span></td><td>Tage bis zum n&auml;chsten Feiertag</td></tr>
<tr><td><span class="fe-mono">\iURLAUB=\i\v</span> / <span class="fe-mono">\iMURLAUB=\i\v</span></td><td><b>1 = Abwesenheit (Urlaubsmodus) heute bzw. morgen</b> &mdash; aus einem eigenen Termin der Art &bdquo;Urlaub (abwesend)&ldquo;</td></tr>
<tr><td><span class="fe-mono">\iURLAUBIN=\i\v</span></td><td>Tage bis zur Abreise (0 = Urlaub l&auml;uft)</td></tr>
<tr><td><span class="fe-mono">\iURLAUBREST=\i\v</span> / <span class="fe-mono">\iURLAUBDAUER=\i\v</span></td><td>verbleibende bzw. gesamte Urlaubstage</td></tr>
<tr><td><span class="fe-mono">\iURLAUBENDE=\i\v</span></td><td>1 = <b>letzter Urlaubstag</b> (morgen ist man zur&uuml;ck) &mdash; Ausl&ouml;ser zum Vorw&auml;rmen</td></tr>
<tr><td><span class="fe-mono">\iANN=\i\v</span></td><td>1 = Meldefenster (10 min ab Meldezeit, wenn morgen frei ist) &mdash; Ausl&ouml;ser f&uuml;r den Push</td></tr>
<tr><td><span class="fe-mono">\iPUSH=\i\v</span> / <span class="fe-mono">\iAUDIO=\i\v</span> / <span class="fe-mono">\iPTEST=\i\v</span></td><td>Freigaben aus der Plugin-Konfiguration bzw. Test-Push</td></tr>
<tr><td><span class="fe-mono">\iOK=\i\v</span> / <span class="fe-mono">\iWARN=\i\v</span></td><td>1 = Daten vorhanden / Daten laufen bald aus</td></tr>
</table>
</div>

<div class="fe-step"><b>Schritt 3: Kacheln f&uuml;r die App</b><br>
FERIENIN und FERIENREST als Analoganzeigen mit Einheit <span class="fe-mono">&lt;v.0&gt; Tage</span> (&bdquo;Ferien in X Tagen&ldquo; ist
erfahrungsgem&auml;&szlig; die beliebteste Kachel im Haushalt), SCHULTAG und SCHULFREI als Digitalanzeigen.
</div>

<div class="fe-step"><b>Schritt 4: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br>
<b>4a) Wecker und Briefing nur an Schultagen</b>
<table class="fe-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S1</td><td>Morgen ist Schultag</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; MSCHULTAG</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Heute ist Schultag</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; SCHULTAG</td></tr>
<tr><td>UND U1</td><td>Wecker freigeben</td><td>&rarr; auf den Freigabe-Eingang des Weckers bzw. der Wecker-Zeitschaltuhr</td><td>S2 &amp; (eigener Schalter &bdquo;Wecker aktiv&ldquo;)</td></tr>
<tr><td>UND U2</td><td>Morgen-Briefing freigeben</td><td>&rarr; ersetzt die bisherige Ferien-/Feiertagslogik im Briefing</td><td>S2 &amp; (Briefing-Schalter)</td></tr>
<tr><td>NICHT N1 + UND U3</td><td>Sp&auml;te Zeiten an freien Tagen</td><td>&rarr; z. B. Rollladen erst 8:30 statt 7:00, Heizung sp&auml;ter hoch</td><td>N1 &larr; S2, U3: N1 &amp; (Zeitimpuls)</td></tr>
</table>
<b>4b) Vorabend-Meldung &bdquo;morgen ist frei&ldquo;</b>
<table class="fe-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S3</td><td>Meldefenster aktiv</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; ANN</td></tr>
<tr><td>Schwellwertschalter S4</td><td>Push freigegeben</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSH</td></tr>
<tr><td>UND U4</td><td>Frei-Meldung jetzt</td><td></td><td>S3 &amp; S4</td></tr>
<tr><td>ODER O1</td><td>Push-Sammler</td><td>einzige Quelle des Benachrichtigungs-Bausteins!</td><td>U4</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Morgen ist frei&ldquo;</td><td>Text z. B. &bdquo;Morgen ist schulfrei &mdash; der Wecker bleibt aus.&ldquo;</td><td>&larr; O1</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST</td></tr>
</table>
<b>4c) Ferien-Countdown und Br&uuml;ckentage</b>
<table class="fe-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Statusbaustein</td><td>Ferien-Kachel</td><td>Text: &bdquo;Noch &lt;v1.0&gt; Tage bis zu den Ferien&ldquo; bzw. bei laufenden Ferien &bdquo;Noch &lt;v2.0&gt; Ferientage&ldquo;</td><td>I1 &larr; FERIENIN, I2 &larr; FERIENREST</td></tr>
<tr><td>Schwellwertschalter S5 + Impuls</td><td>Br&uuml;ckentag-Hinweis</td><td>S5 an MBRUECKE; mit einem Zeitimpuls (z. B. 18:00) UND-verkn&uuml;pfen &rarr; Push &bdquo;Morgen ist Br&uuml;ckentag&ldquo;</td><td>&larr; MBRUECKE</td></tr>
<tr><td>Schwellwertschalter S6</td><td>Ferienmodus (Anwesenheit)</td><td>an FERIEN &mdash; z. B. Heizprogramm oder Beschattung anders fahren</td><td>&larr; FERIEN</td></tr>
</table>
<b>4d) Urlaubsmodus (Abwesenheit)</b>
<table class="fe-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S7</td><td>Urlaub aktiv</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; URLAUB</td></tr>
<tr><td>Schwellwertschalter S8</td><td>Letzter Urlaubstag</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; URLAUBENDE</td></tr>
<tr><td>ODER O2</td><td>Urlaubsmodus</td><td>Sammelt Kalender-Urlaub und den Handschalter, damit man den Modus jederzeit selbst ein-/ausschalten kann</td><td>S7 &amp; (Merker &bdquo;Urlaub manuell&ldquo;)</td></tr>
<tr><td>Anwesenheitssimulation</td><td>&mdash;</td><td>&rarr; Eingang &bdquo;Aktivieren&ldquo;</td><td>&larr; O2</td></tr>
<tr><td>Intelligente Raumregelung</td><td>Heizung</td><td>&rarr; Eingang f&uuml;r den Betriebsart-/Absenkbefehl (Urlaubs- bzw. Sparmodus)</td><td>&larr; O2</td></tr>
<tr><td>NICHT N2 + UND U5</td><td>Vorw&auml;rmen zur R&uuml;ckkehr</td><td>S8 hebt die Absenkung am letzten Urlaubstag wieder auf, damit das Haus bei der Ankunft warm ist</td><td>N2 &larr; S8, U5: O2 &amp; N2 &rarr; Absenkung</td></tr>
<tr><td>UND U6 / Steckdosen</td><td>Verbraucher abschalten</td><td>&rarr; Aus-Befehl an Steckdosen, Handtuchheizung, Warmwasser-Zirkulation usw.</td><td>&larr; O2</td></tr>
<tr><td>Statusbaustein</td><td>Urlaubs-Kachel</td><td>Text: &bdquo;Urlaub &mdash; noch &lt;v1.0&gt; Tage&ldquo; bzw. &bdquo;Abreise in &lt;v2.0&gt; Tagen&ldquo;</td><td>I1 &larr; URLAUBREST, I2 &larr; URLAUBIN</td></tr>
</table>
<div class="fe-small">Wichtig: Den Urlaubsmodus nie direkt aus URLAUB speisen, sondern immer &uuml;ber das ODER O2 &mdash;
sonst l&auml;sst er sich bei einer kurzfristigen Planungs&auml;nderung nicht von Hand &uuml;bersteuern.
Sicherheitsrelevante Dinge (Alarmanlage scharf schalten) sollten <b>nicht</b> allein am Kalender h&auml;ngen,
sondern zus&auml;tzlich an der echten Anwesenheitserkennung.</div>
<b>Praxis-Erfahrungen zum Benachrichtigungs-Baustein:</b> Er sendet nur bei einer 0&rarr;1-Flanke.
NIEMALS mehrere Quellen direkt an den Eingang legen &mdash; eine dauerhaft aktive Quelle verschluckt alle weiteren
Ausl&ouml;ser. Immer erst im ODER-Baustein sammeln. F&uuml;r den Test (PTEST) einen EIGENEN Baustein verwenden.
</div>

<div class="fe-step"><b>Schritt 5: MQTT-Alternative + JSON</b><br>
Alle Werte gibt es auch &uuml;ber das LoxBerry MQTT Gateway (Reiter Einstellungen &rarr; MQTT) und als JSON
f&uuml;r Drittsoftware: <span class="fe-mono">http://<?= $fe_host ?>/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?json=1</span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="fe-pane" id="tab-test">
<h2>Test</h2>
<p>
<a class="fe-btn" style="display:inline-block;margin-right:8px;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="fe-btn" style="display:inline-block;margin-right:8px;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?debug=1" target="_blank">Debug (alle Termine)</a>
<a class="fe-btn" style="display:inline-block;margin-right:8px;background:#607d8b;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?refresh=1&amp;debug=1" target="_blank">Neu abrufen + Debug</a>
<a class="fe-btn" style="display:inline-block;background:#607d8b;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?json=1" target="_blank">JSON-Ansicht</a>
</p>
<p>
<a class="fe-btn" style="display:inline-block;margin-right:8px;background:#e65100;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?say=1" target="_blank">Test-Ansage</a>
<a class="fe-btn" style="display:inline-block;background:#e65100;" href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?ptest=1" target="_blank">Test-Pushnachricht</a>
</p>
</div>

<!-- ================= Brueckentage ================= -->
<div class="fe-pane" id="tab-bridge">
<h2>Br&uuml;ckentage der n&auml;chsten 12 Monate</h2>
<div class="fe-small" style="margin-bottom:8px;">Werktage zwischen Feiertag und Wochenende &mdash; mit einem Urlaubstag ergeben sie ein langes Wochenende.</div>
<?php if (!empty($fe_st['brueckentage'])) { ?>
<table class="fe-tbl"><tr><th>Datum</th><th>Wochentag</th><th>in Tagen</th><th>ergibt</th></tr>
<?php foreach ((array) $fe_st['brueckentage'] as $fe_t) {
    $fe_ts = strtotime($fe_t);
    $fe_in = (int) floor(($fe_ts - strtotime(date('Y-m-d'))) / 86400);
    $fe_wt = (int) date('N', $fe_ts);
    $fe_erg = $fe_wt === 5 ? 'langes Wochenende (Do&ndash;So)' : ($fe_wt === 1 ? 'langes Wochenende (Sa&ndash;Di)' : '4 freie Tage am St&uuml;ck'); ?>
<tr><td><?= fe_e(fe_d($fe_t)) ?></td><td><?= fe_e(date('D', $fe_ts)) ?></td>
<td><?= $fe_in <= 0 ? 'heute' : $fe_in ?></td><td><?= $fe_erg ?></td></tr>
<?php } ?></table>
<div class="fe-small" style="margin-top:8px;">Ein Brückentag ist ein Werktag, der zwischen einem gesetzlichen Feiertag und dem
Wochenende eingeklemmt ist &mdash; also der Freitag nach einem Donnerstags-Feiertag oder der Montag vor einem Dienstags-Feiertag.
Wer diesen einen Tag Urlaub nimmt, hat vier freie Tage am St&uuml;ck.</div>
<?php } else { ?>
<div class="fe-alert fe-info">Zurzeit sind keine Br&uuml;ckentage bekannt.
Pr&uuml;fen Sie unter <b>Einstellungen</b>, ob &bdquo;Br&uuml;ckentage erkennen&ldquo; aktiviert und ein Bundesland gew&auml;hlt ist,
und holen Sie im Reiter <b>Test</b> die Daten neu ab.</div>
<?php } ?>
</div>

<!-- ================= Kommende Ferien ================= -->
<div class="fe-pane" id="tab-vacation">
<h2>Kommende Ferien</h2>
<?php if (function_exists('fer_data')) { $fe_d = fer_data(); $fe_heute = date('Y-m-d'); ?>
<div class="fe-small" style="margin-bottom:8px;">Schulferien des gew&auml;hlten Bundeslandes sowie eigene Termine vom Typ &bdquo;wie Ferien&ldquo;. Laufende Zeitr&auml;ume stehen oben.</div>
<table class="fe-tbl"><tr><th>von</th><th>bis</th><th>Bezeichnung</th><th>Tage</th><th>Status</th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['ferien'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 14) { continue; }
    $fe_tage = (int) round((strtotime($fe_e2['bis']) - strtotime($fe_e2['von'])) / 86400) + 1;
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td><td><?= fe_e(fe_d($fe_e2['bis'])) ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['urlaub']) ? ' <span class="fe-small">(Urlaub &mdash; abwesend)</span>' : (!empty($fe_e2['eigen']) ? ' <span class="fe-small">(eigener Termin)</span>' : '') ?></td>
<td><?= $fe_tage ?></td>
<td><?= $fe_in <= 0 ? '<b>l&auml;uft</b>' : ('in ' . $fe_in . ' Tag' . ($fe_in === 1 ? '' : 'en')) ?></td></tr>
<?php } ?></table>
<?php } else { ?>
<div class="fe-alert fe-info">Die Bibliothek des Plugins wurde nicht gefunden &mdash; bitte das Plugin neu installieren.</div>
<?php } ?>
</div>

<!-- ================= Kommende Feiertage ================= -->
<div class="fe-pane" id="tab-holiday">
<h2>Kommende Feiertage</h2>
<?php if (function_exists('fer_data')) { if (!isset($fe_d)) { $fe_d = fer_data(); } $fe_heute = date('Y-m-d'); ?>
<div class="fe-small" style="margin-bottom:8px;">Gesetzliche Feiertage des gew&auml;hlten Bundeslandes (inklusive der Korrekturen f&uuml;r den eingestellten Arbeitsort) sowie eigene Termine vom Typ &bdquo;wie Feiertag&ldquo;.</div>
<table class="fe-tbl"><tr><th>Datum</th><th>Wochentag</th><th>Bezeichnung</th><th>in Tagen</th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['feiertage'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 17) { continue; }
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400);
    $fe_wt = (int) date('N', strtotime($fe_e2['von'])); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td>
<td><?= fe_e(date('D', strtotime($fe_e2['von']))) ?><?= ($fe_wt >= 6) ? ' <span class="fe-small">(f&auml;llt aufs Wochenende)</span>' : '' ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['eigen']) ? ' <span class="fe-small">(eigener Termin)</span>' : '' ?><?= !empty($fe_e2['ortlich']) ? ' <span class="fe-small">(nur &ouml;rtlich)</span>' : '' ?></td>
<td><?= $fe_in <= 0 ? '<b>heute</b>' : $fe_in ?></td></tr>
<?php } ?></table>
<?php } else { ?>
<div class="fe-alert fe-info">Die Bibliothek des Plugins wurde nicht gefunden &mdash; bitte das Plugin neu installieren.</div>
<?php } ?>
</div>

<!-- ================= Protokoll ================= -->
<div class="fe-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="fe-small" style="margin-bottom:8px;">Protokolliert werden Datenabrufe, Zustands&auml;nderungen, Ansagen und Fehler. Neueste Eintr&auml;ge oben (max. 300).<br>Datei: <span class="fe-mono"><?= fe_e($fe_logfile) ?></span></div>
<?php if ($fe_loglines) { ?>
<div class="fe-log"><?= fe_e(implode("\n", $fe_loglines)) ?></div>
<?php } else { ?>
<div class="fe-alert fe-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="fe-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
</div>

</div>
<script>
function feTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.fe-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('fe-active', t.dataset.pane === id); });
        document.querySelectorAll('.fe-pane').forEach(function (p) { p.classList.toggle('fe-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($fe_tab) ?>);
    feTtsMode();
})();
</script>
<?php
if ($fe_frame) { LBWeb::lbfooter(); }
