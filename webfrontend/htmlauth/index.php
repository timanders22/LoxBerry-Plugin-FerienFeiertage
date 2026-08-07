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
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($fe_json !== false && @file_put_contents($fe_cfgfile, $fe_json) !== false) {
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
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo fer_t('TEXT.TEST'); ?> (Standard <?php echo fer_t('TEXT.ALLE'); ?>r Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($fe_saved) { ?><div class="sm-alert sm-ok"><b><?php echo fer_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo fer_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES_DI'); ?></div><?php } ?>
<?php if ($fe_note !== '') { ?><div class="sm-alert sm-ok"><?= fe_e($fe_note) ?></div><?php } ?>
<?php if ($fe_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo fer_t('TEXT.FEHLER'); ?></b> <?= fe_e($fe_err) ?></div><?php } ?>

<?php if (!empty($fe_st)) { ?>
<div class="sm-alert sm-info">
<?php if ($fe_st['ok']) { ?>
<b><?php echo fer_t('TEXT.HEUTE'); ?><?= fe_e(fe_d($fe_st[fer_t('TEXT.HEUTE_2')]['datum'])) ?>):</b>
<?= $fe_st['heute'][fer_t('TEXT.SCHULFREI')] ? '<b>schulfrei</b>' : 'normaler Schul-/Arbeitstag' ?>
<?= $fe_st['heute']['feiertag'] ? ' &middot; Feiertag: <b>' . fe_e($fe_st['heute']['feiertag_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['ferien'] ? ' &middot; Ferien: <b>' . fe_e($fe_st['heute']['ferien_name']) . '</b>' : '' ?>
<?= $fe_st['heute']['bruecke'] ? ' &middot; <b>Br&uuml;ckentag</b>' : '' ?><br>
<b><?php echo fer_t('TEXT.MORGEN'); ?></b> <?= $fe_st['morgen']['schulfrei'] ? '<b>schulfrei</b>' : 'Schul-/Arbeitstag' ?>
<?= $fe_st['morgen']['feiertag'] ? ' (' . fe_e($fe_st['morgen']['feiertag_name']) . ')' : '' ?>
<?= $fe_st['morgen']['ferien'] ? ' (' . fe_e($fe_st['morgen']['ferien_name']) . ')' : '' ?><br>
<?php if ($fe_st['naechste']['in'] >= 0) { ?>
<?= $fe_st['naechste']['rest'] > 0
    ? 'Laufende Ferien: <b>' . fe_e($fe_st['naechste']['name']) . '</b> noch <b>' . (int) $fe_st['naechste']['rest'] . ' Tage</b> (bis ' . fe_e(fe_d($fe_st['naechste']['bis'])) . ')'
    : 'N&auml;chste Ferien: <b>' . fe_e($fe_st['naechste']['name']) . '</b> in <b>' . (int) $fe_st['naechste']['in'] . ' Tagen</b> (' . fe_e(fe_d($fe_st['naechste']['von'])) . ' bis ' . fe_e(fe_d($fe_st['naechste']['bis'])) . ', ' . (int) $fe_st['naechste']['dauer'] . ' Tage)' ?><br>
<?php } ?>
<?php if ($fe_st['feiertag_naechster']['in'] >= 0) { ?>
<?php echo fer_t('TEXT.NCHSTER_FEIERTAG'); ?> <b><?= fe_e($fe_st['feiertag_naechster']['name']) ?></b> in <?= (int) $fe_st['feiertag_naechster']['in'] ?> <?php echo fer_t('TEXT.TAGEN'); ?><?= fe_e(fe_d($fe_st['feiertag_naechster']['datum'])) ?>)<br>
<?php } ?>
<span class="sm-small"><?php echo fer_t('TEXT.DATEN_REICHEN_BIS'); ?> <?= fe_e(fe_d($fe_st['reicht_bis'])) ?> <?php echo fer_t('TEXT.STAND'); ?> <?= fe_e(substr((string) $fe_st['stand'], 0, 10)) ?></span>
<?php } else { ?>
<b><?php echo fer_t('TEXT.NOCH_KEINE_DATEN_GELADEN'); ?></b> <?php echo fer_t('TEXT.BITTE_UNTEN_LAND_UND_BUNDESLAND_WH'); ?>
<?php } ?>
</div>
<?php if (!empty($fe_st['warnung'])) { ?><div class="sm-alert sm-warn"><b><?php echo fer_t('TEXT.ACHTUNG'); ?></b> <?php echo fer_t('TEXT.DIE_FERIEN_FEIERTAGSDATEN_REICHEN_'); ?></div><?php } ?>
<?php } ?>

<div class="sm-tabs">
    <div class="sm-tab" data-pane="tab-settings"><?php echo fer_t('REITER.EINSTELLUNGEN'); ?></div>
    <div class="sm-tab" data-pane="tab-loxone"><?php echo fer_t('REITER.LOXONE'); ?></div>
    <div class="sm-tab" data-pane="tab-bridge"><?php echo fer_t('REITER.BRUECKENTAGE'); ?></div>
    <div class="sm-tab" data-pane="tab-vacation"><?php echo fer_t('REITER.FERIEN'); ?></div>
    <div class="sm-tab" data-pane="tab-holiday"><?php echo fer_t('REITER.FEIERTAGE'); ?></div>
    <div class="sm-tab" data-pane="tab-test"><?php echo fer_t('REITER.TEST'); ?></div>
    <div class="sm-tab" data-pane="tab-log"><?php echo fer_t('REITER.LOG'); ?></div>
</div>

<!-- ================= <?php echo fer_t('TEXT.EINSTELLUNGEN'); ?> ================= -->
<div class="sm-pane" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo fer_t('TEXT.REGION'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.LAND'); ?></label>
        <select data-role="none" name="country">
<?php foreach (array('DE' => 'Deutschland', 'AT' => '&Ouml;sterreich', 'CH' => 'Schweiz', 'LU' => 'Luxemburg',
                     'BE' => 'Belgien', 'NL' => 'Niederlande', 'FR' => 'Frankreich', 'IT' => 'Italien',
                     'PL' => 'Polen', 'CZ' => 'Tschechien') as $fe_k => $fe_v) { ?>
            <option value="<?= $fe_k ?>"<?= $fe_cfg['country'] === $fe_k ? ' selected' : '' ?>><?= $fe_v ?></option>
<?php } ?>
        </select>
        <div class="sm-small"><?php echo fer_t('TEXT.NACH_DEM_WECHSEL_SPEICHERN_DANACH_'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.BUNDESLAND_REGION'); ?></label>
<?php if ($fe_subs) { ?>
        <select data-role="none" name="subdivision">
            <option value=""><?php echo fer_t('TEXT.GANZES_LAND_NUR_BUNDESWEITE_FEIERT'); ?></option>
<?php foreach ($fe_subs as $fe_code => $fe_name) { ?>
            <option value="<?= fe_e($fe_code) ?>"<?= $fe_cfg['subdivision'] === $fe_code ? ' selected' : '' ?>><?= fe_e($fe_name) ?> (<?= fe_e($fe_code) ?>)</option>
<?php } ?>
        </select>
<?php } else { ?>
        <input data-role="none" type="text" name="subdivision" value="<?= fe_e($fe_cfg['subdivision']) ?>" placeholder="z. B. DE-BY">
        <div class="sm-small"><?php echo fer_t('TEXT.LISTE_KONNTE_NICHT_GELADEN_WERDEN_'); ?></div>
<?php } ?>
    </div>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div>
        <label><?php echo fer_t('TEXT.ARBEITSORT_GEMEINDE_RTLICHE_SONDER'); ?></label>
        <select data-role="none" name="locality">
            <option value=""<?= $fe_cfg['locality'] === '' ? ' selected' : '' ?><?php echo fer_t('TEXT.ALLE_ANDEREN_STDTE_UND_GEMEINDEN'); ?></option>
            <option value="DE-BY-AU"<?= $fe_cfg['locality'] === 'DE-BY-AU' ? ' selected' : '' ?><?php echo fer_t('TEXT.STADTGEBIET_AUGSBURG_MIT_FRIEDENSF'); ?></option>
            <option value="BY-EV"<?= $fe_cfg['locality'] === 'BY-EV' ? ' selected' : '' ?><?php echo fer_t('TEXT.BAYERN_BERWIEGEND_EVANGELISCHE_GEM'); ?></option>
            <option value="SN-KATH"<?= $fe_cfg['locality'] === 'SN-KATH' ? ' selected' : '' ?><?php echo fer_t('TEXT.SACHSEN_KATHOLISCHE_GEMEINDE_IM_SO'); ?></option>
            <option value="TH-KATH"<?= $fe_cfg['locality'] === 'TH-KATH' ? ' selected' : '' ?><?php echo fer_t('TEXT.THRINGEN_EICHSFELD_BZW_KATHOLISCHE'); ?></option>
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
        <input data-role="none" type="checkbox" name="school" <?= !empty($fe_cfg['school']) ? 'checked' : '' ?><?php echo fer_t('TEXT.SCHULFERIEN_AUSWERTEN'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="public" <?= !empty($fe_cfg['public']) ? 'checked' : '' ?><?php echo fer_t('TEXT.GESETZLICHE_FEIERTAGE_AUSWERTEN'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;">
        <input data-role="none" type="checkbox" name="local_holidays" <?= !empty($fe_cfg['local_holidays']) ? 'checked' : '' ?><?php echo fer_t('TEXT.AUCH_NUR_RTLICHE_FEIERTAGE'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="bridge" <?= !empty($fe_cfg['bridge']) ? 'checked' : '' ?><?php echo fer_t('TEXT.BRCKENTAGE_ERKENNEN'); ?>
    </label>
    <div class="sm-small"><?php echo fer_t('TEXT.AUCH_NUR_RTLICHE_FEIERTAGE_NIMMT'); ?> <b>alle</b> <?php echo fer_t('TEXT.ORTSGEBUNDENEN_FEIERTAGE_DER_REGIO'); ?> <b><?php echo fer_t('TEXT.BRCKENTAG'); ?></b> <?php echo fer_t('TEXT.IST_EIN_WERKTAG_ZWISCHEN_FEIERTAG_'); ?></div>
</div>
<div class="sm-alert sm-info" style="margin-top:10px;"><?php echo fer_t('TEXT.DATENQUELLE'); ?> <b><?php echo fer_t('TEXT.OPENHOLIDAYSAPI_ORG'); ?></b> <?php echo fer_t('TEXT.AMTLICHE_FERIEN_UND_FEIERTAGSDATEN'); ?></div>

<h2><?php echo fer_t('TEXT.EIGENE_TERMINE_OPTIONAL'); ?></h2>
<div class="sm-small"><?php echo fer_t('TEXT.FR_BETRIEBSFERIEN_URLAUB_ODER_SCHU'); ?><br>
&bull; <b><?php echo fer_t('TEXT.WIE_FERIEN'); ?></b> <?php echo fer_t('TEXT.ZHLT_IN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.FERIEN'); ?></span>/<span class="sm-mono"><?php echo fer_t('TEXT.SCHULFREI_2'); ?></span>.<br>
&bull; <b><?php echo fer_t('TEXT.WIE_FEIERTAG'); ?></b> <?php echo fer_t('TEXT.ZUSTZLICH_IN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.FEIERTAG'); ?></span>.<br>
&bull; <b<?php echo fer_t('TEXT.URLAUB_ABWESEND_2'); ?></b> <?php echo fer_t('TEXT.BEDEUTET_DAS_HAUS_IST_LEER_ZUSTZLI'); ?>
<span class="sm-mono"><?php echo fer_t('TEXT.URLAUB_1'); ?></span> <?php echo fer_t('TEXT.GESETZT_DAMIT_LOXONE_AUTOMATISCH_I'); ?> <b><?php echo fer_t('TEXT.URLAUBSMODUS'); ?></b> <?php echo fer_t('TEXT.GEHEN_KANN_ANWESENHEITSSIMULATION_'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.URLAUBENDE_1'); ?></span> <?php echo fer_t('TEXT.AM_LETZTEN_URLAUBSTAG_LSST_SICH_DA'); ?><br>
<?php echo fer_t('TEXT.DAS_DATUM_BEZEICHNET_GANZE_TAGE_AB'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.URLAUBENDE'); ?></span> <?php echo fer_t('TEXT.WIEDER_AUF_SCHRITT4D_EIN_VORZIEHEN'); ?></div>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:30%;"><?php echo fer_t('TEXT.BEZEICHNUNG'); ?></th><th style="width:20%;"><?php echo fer_t('TEXT.VON_JJJJ_MM_TT'); ?></th><th style="width:20%;">bis</th><th style="width:24%;">Art</th></tr>
<?php for ($fe_i = 0; $fe_i < 6; $fe_i++) {
    $fe_o = isset($fe_cfg['own'][$fe_i]) ? (array) $fe_cfg['own'][$fe_i] : array();
    $fe_o += array('name' => '', 'von' => '', 'bis' => '', 'typ' => 'ferien'); ?>
<tr>
<td><input data-role="none" type="text" name="own_name[]" value="<?= fe_e($fe_o['name']) ?>" placeholder="<?= $fe_i === 0 ? 'z. B. Betriebsferien' : '' ?>"></td>
<td><input data-role="none" type="text" name="own_von[]" value="<?= fe_e($fe_o['von']) ?>" placeholder="2026-08-03"></td>
<td><input data-role="none" type="text" name="own_bis[]" value="<?= fe_e($fe_o['bis']) ?>" placeholder="2026-08-14"></td>
<td><select data-role="none" name="own_typ[]">
    <option value="ferien"<?= ($fe_o['typ'] !== 'feiertag' && $fe_o['typ'] !== 'urlaub') ? ' selected' : '' ?><?php echo fer_t('TEXT.WIE_FERIEN_2'); ?></option>
    <option value="feiertag"<?= $fe_o['typ'] === 'feiertag' ? ' selected' : '' ?><?php echo fer_t('TEXT.WIE_FEIERTAG_2'); ?></option>
    <option value="urlaub"<?= $fe_o['typ'] === 'urlaub' ? ' selected' : '' ?>><?php echo fer_t('TEXT.URLAUB_ABWESEND'); ?></option>
</select></td>
</tr>
<?php } ?>
</table>

<h2><?php echo fer_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($fe_notify['audio']) ? 'checked' : '' ?><?php echo fer_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($fe_notify['push']) ? 'checked' : '' ?><?php echo fer_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo fer_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.ANN_1'); ?></span> <?php echo fer_t('TEXT.ANLEITUNG_SCHRITT_4'); ?></div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.MELDEZEIT_AM_VORABEND'); ?></label>
        <input data-role="none" type="text" name="notify_time" value="<?= fe_e($fe_notify['time']) ?>" placeholder="19:00">
    </div>
    <div>
        <label style="min-height:2.6em;display:flex;align-items:flex-end;"><?php echo fer_t('TEXT.TEXT_2'); ?></label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_freetag" <?= !empty($fe_notify['freetag']) ? 'checked' : '' ?><?php echo fer_t('TEXT.MELDEN_WENN_MORGEN_SCHULFREI_IST'); ?>
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_ferienstart" <?= !empty($fe_notify['ferienstart']) ? 'checked' : '' ?><?php echo fer_t('TEXT.MELDEN_AM_VORABEND_DES_FERIENBEGIN'); ?>
        </label><br>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="n_bridge" <?= !empty($fe_notify['bridge_month']) ? 'checked' : '' ?><?php echo fer_t('TEXT.IM_JANUAR_DIE_BRCKENTAGE_DES_JAHRE'); ?>
        </label>
    </div>
</div>

<h2><?php echo fer_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="feTtsMode()">
            <option value="musicserver"<?= $fe_tts['mode'] === 'musicserver' ? ' selected' : '' ?><?php echo fer_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $fe_tts['mode'] === 'ms4h' ? ' selected' : '' ?><?php echo fer_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $fe_tts['mode'] === 'audioserver' ? ' selected' : '' ?><?php echo fer_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $fe_tts['mode'] === 'custom' ? ' selected' : '' ?><?php echo fer_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= fe_e($fe_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label><?php echo fer_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $fe_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo fer_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= fe_e($fe_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo fer_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo fer_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo fer_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo fer_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo fer_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo fer_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $fe_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo fer_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= fe_e($fe_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo fer_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo fer_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= fe_e($fe_tts['template']) ?></textarea>
    <div class="sm-small"><?php echo fer_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo fer_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo fer_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <b><?php echo fer_t('TEXT.KEINE_HTTP_TTS_SCHNITTSTELLE'); ?></b><?php echo fer_t('TEXT.IN_DIESEM_MODUS_SPRICHT_DAS_PLUGIN'); ?> <span class="sm-mono">ANN=1</span>.
</div>

<h2><?php echo fer_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($fe_cfg['mqtt_enabled']) ? 'checked' : '' ?><?php echo fer_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo fer_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= fe_e($fe_cfg['mqtt_topic']) ?>" placeholder="ferien">
        <div class="sm-small"><?php echo fer_t('TEXT.NUTZT_DAS'); ?> <b><?php echo fer_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b><?php echo fer_t('TEXT.VERFFENTLICHT_U_A'); ?>
        <span class="sm-mono"><?= fe_e($fe_cfg['mqtt_topic']) ?><?php echo fer_t('TEXT.SCHULFREI_3'); ?></span>, <span class="sm-mono"><?php echo fer_t('TEXT.SCHULTAG'); ?></span>,
        <span class="sm-mono"><?php echo fer_t('TEXT.FEIERTAG_2'); ?></span>, <span class="sm-mono"><?php echo fer_t('TEXT.FERIEN_2'); ?></span>, <span class="sm-mono"><?php echo fer_t('TEXT.BRUECKE'); ?></span>,
        <span class="sm-mono"><?php echo fer_t('TEXT.MORGEN_SCHULFREI'); ?></span>, <span class="sm-mono"><?php echo fer_t('TEXT.FERIEN_IN'); ?></span>, <span class="sm-mono"><?php echo fer_t('TEXT.FERIEN_REST'); ?></span>,
        <span class="sm-mono"><?php echo fer_t('TEXT.NAME'); ?></span>.</div>
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo fer_t('TEXT.SPEICHERN'); ?></button>
</form>
<form action="index.php" method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn" type="submit" style="background:#607d8b;margin-top:0;"><?php echo fer_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane" id="tab-loxone">
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
<table class="sm-tbl">
<tr><th><?php echo fer_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo fer_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.ISCHULTAG_I_V'); ?></span></td><td><b><?php echo fer_t('TEXT.1_HEUTE_IST_EIN_NORMALER_SCHUL_ARB'); ?></b> <?php echo fer_t('TEXT.WERKTAG_KEINE_FERIEN_KEIN_FEIERTAG'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IMSCHULTAG_I_V'); ?></span></td><td><b><?php echo fer_t('TEXT.1_MORGEN_IST_SCHULTAG'); ?></b> <?php echo fer_t('TEXT.DER_WICHTIGSTE_WERT_FR_WECKER_UND_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.ISCHULFREI_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IMSCHULFREI_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_HEUTE_BZW_MORGEN_SCHULFREI_FERIE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IFERIEN_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IMFERIEN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_HEUTE_BZW_MORGEN_SCHULFERIEN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IFEIERTAG_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IMFEIERTAG_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_HEUTE_BZW_MORGEN_GESETZLICHER_FE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IWOCHENENDE_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_SAMSTAG_ODER_SONNTAG'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IBRUECKE_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IMBRUECKE_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_BRCKENTAG_HEUTE_BZW_MORGEN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IFERIENIN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.TAGE_BIS_ZU_DEN_NCHSTEN_FERIEN_0_L'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IFERIENREST_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IFERIENDAUER_I_V'); ?></span></td><td><?php echo fer_t('TEXT.VERBLEIBENDE_BZW_GESAMTE_FERIENTAG'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IFEIERTAGIN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.TAGE_BIS_ZUM_NCHSTEN_FEIERTAG'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IURLAUB_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IMURLAUB_I_V'); ?></span></td><td><b><?php echo fer_t('TEXT.1_ABWESENHEIT_URLAUBSMODUS_HEUTE_B'); ?></b> <?php echo fer_t('TEXT.AUS_EINEM_EIGENEN_TERMIN_DER_ART_U'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IURLAUBIN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.TAGE_BIS_ZUR_ABREISE_0_URLAUB_LUFT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IURLAUBREST_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IURLAUBDAUER_I_V'); ?></span></td><td><?php echo fer_t('TEXT.VERBLEIBENDE_BZW_GESAMTE_URLAUBSTA'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IURLAUBENDE_I_V'); ?></span></td><td>1 = <b><?php echo fer_t('TEXT.LETZTER_URLAUBSTAG'); ?></b> <?php echo fer_t('TEXT.MORGEN_IST_MAN_ZURCK_AUSLSER_ZUM_V'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IANN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_MELDEFENSTER_10_MIN_AB_MELDEZEIT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IPUSH_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IAUDIO_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo fer_t('TEXT.FREIGABEN_AUS_DER_PLUGIN_KONFIGURA'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo fer_t('TEXT.IOK_I_V'); ?></span> / <span class="sm-mono"><?php echo fer_t('TEXT.IWARN_I_V'); ?></span></td><td><?php echo fer_t('TEXT.1_DATEN_VORHANDEN_DATEN_LAUFEN_BAL'); ?></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_3_KACHELN_FR_DIE_APP'); ?></b><br>
<?php echo fer_t('TEXT.FERIENIN_UND_FERIENREST_ALS_ANALOG'); ?> <span class="sm-mono"><?php echo fer_t('TEXT.V_0_TAGE'); ?></span> <?php echo fer_t('TEXT.FERIEN_IN_X_TAGEN_IST_ERFAHRUNGSGE'); ?>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<b><?php echo fer_t('TEXT.4A_WECKER_UND_BRIEFING_NUR_AN_SCHU'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo fer_t('TEXT.BAUSTEIN'); ?></th><th><?php echo fer_t('TEXT.NAME_2'); ?></th><th><?php echo fer_t('TEXT.EINSTELLUNG'); ?></th><th><?php echo fer_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo fer_t('TEXT.MORGEN_IST_SCHULTAG'); ?></td><td><?php echo fer_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo fer_t('TEXT.MSCHULTAG'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo fer_t('TEXT.HEUTE_IST_SCHULTAG'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo fer_t('TEXT.SCHULTAG_2'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.UND_U1'); ?></td><td><?php echo fer_t('TEXT.WECKER_FREIGEBEN'); ?></td><td><?php echo fer_t('TEXT.AUF_DEN_FREIGABE_EINGANG_DES_WECKE'); ?></td><td><?php echo fer_t('TEXT.S2_EIGENER_SCHALTER_WECKER_AKTIV'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.UND_U2'); ?></td><td><?php echo fer_t('TEXT.MORGEN_BRIEFING_FREIGEBEN'); ?></td><td><?php echo fer_t('TEXT.ERSETZT_DIE_BISHERIGE_FERIEN_FEIER'); ?></td><td><?php echo fer_t('TEXT.S2_BRIEFING_SCHALTER'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.NICHT_N1_UND_U3'); ?></td><td><?php echo fer_t('TEXT.SPTE_ZEITEN_AN_FREIEN_TAGEN'); ?></td><td><?php echo fer_t('TEXT.Z_B_ROLLLADEN_ERST_8_30_STATT_7_00'); ?></td><td><?php echo fer_t('TEXT.N1_S2_U3_N1_ZEITIMPULS'); ?></td></tr>
</table>
<b><?php echo fer_t('TEXT.4B_VORABEND_MELDUNG_MORGEN_IST_FRE'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo fer_t('TEXT.MELDEFENSTER_AKTIV'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo fer_t('TEXT.ANN'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S4'); ?></td><td><?php echo fer_t('TEXT.PUSH_FREIGEGEBEN'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo fer_t('TEXT.PUSH'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.UND_U4'); ?></td><td><?php echo fer_t('TEXT.FREI_MELDUNG_JETZT'); ?></td><td></td><td><?php echo fer_t('TEXT.S3_S4'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.ODER_O1'); ?></td><td><?php echo fer_t('TEXT.PUSH_SAMMLER'); ?></td><td><?php echo fer_t('TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN'); ?></td><td>U4</td></tr>
<tr><td><?php echo fer_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo fer_t('TEXT.PUSH_MORGEN_IST_FREI'); ?></td><td><?php echo fer_t('TEXT.TEXT_Z_B_MORGEN_IST_SCHULFREI_DER_'); ?></td><td><?php echo fer_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo fer_t('TEXT.TEST_PUSH'); ?></td><td><?php echo fer_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST'); ?></td></tr>
</table>
<b><?php echo fer_t('TEXT.4C_FERIEN_COUNTDOWN_UND_BRCKENTAGE'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo fer_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo fer_t('TEXT.FERIEN_KACHEL'); ?></td><td><?php echo fer_t('TEXT.TEXT_NOCH_V1_0_TAGE_BIS_ZU_DEN_FER'); ?></td><td><?php echo fer_t('TEXT.I1_FERIENIN_I2_FERIENREST'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S5_IMPULS'); ?></td><td><?php echo fer_t('TEXT.BRCKENTAG_HINWEIS'); ?></td><td><?php echo fer_t('TEXT.S5_AN_MBRUECKE_MIT_EINEM_ZEITIMPUL'); ?></td><td><?php echo fer_t('TEXT.MBRUECKE'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S6'); ?></td><td><?php echo fer_t('TEXT.FERIENMODUS_ANWESENHEIT'); ?></td><td><?php echo fer_t('TEXT.AN_FERIEN_Z_B_HEIZPROGRAMM_ODER_BE'); ?></td><td><?php echo fer_t('TEXT.FERIEN_3'); ?></td></tr>
</table>
<b><?php echo fer_t('TEXT.4D_URLAUBSMODUS_ABWESENHEIT'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S7'); ?></td><td><?php echo fer_t('TEXT.URLAUB_AKTIV'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo fer_t('TEXT.URLAUB'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.SCHWELLWERTSCHALTER_S8'); ?></td><td><?php echo fer_t('TEXT.LETZTER_URLAUBSTAG_2'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo fer_t('TEXT.URLAUBENDE_2'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.ODER_O2'); ?></td><td>Urlaubsmodus</td><td><?php echo fer_t('TEXT.SAMMELT_KALENDER_URLAUB_UND_DEN_HA'); ?></td><td><?php echo fer_t('TEXT.S7_MERKER_URLAUB_MANUELL'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.ANWESENHEITSSIMULATION'); ?></td><td><?php echo fer_t('TEXT.TEXT_3'); ?></td><td><?php echo fer_t('TEXT.EINGANG_AKTIVIEREN'); ?></td><td><?php echo fer_t('TEXT.O2'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.INTELLIGENTE_RAUMREGELUNG'); ?></td><td><?php echo fer_t('TEXT.HEIZUNG'); ?></td><td><?php echo fer_t('TEXT.EINGANG_FR_DEN_BETRIEBSART_ABSENKB'); ?></td><td>&larr; O2</td></tr>
<tr><td><?php echo fer_t('TEXT.NICHT_N2_UND_U5'); ?></td><td><?php echo fer_t('TEXT.VORWRMEN_ZUR_RCKKEHR'); ?></td><td><?php echo fer_t('TEXT.S8_HEBT_DIE_ABSENKUNG_AM_LETZTEN_U'); ?></td><td><?php echo fer_t('TEXT.N2_S8_U5_O2_N2_ABSENKUNG'); ?></td></tr>
<tr><td><?php echo fer_t('TEXT.UND_U6_STECKDOSEN'); ?></td><td><?php echo fer_t('TEXT.VERBRAUCHER_ABSCHALTEN'); ?></td><td><?php echo fer_t('TEXT.AUS_BEFEHL_AN_STECKDOSEN_HANDTUCHH'); ?></td><td>&larr; O2</td></tr>
<tr><td><?php echo fer_t('TEXT.STATUS'); ?>baustein</td><td><?php echo fer_t('TEXT.URLAUBS_KACHEL'); ?></td><td><?php echo fer_t('TEXT.TEXT_URLAUB_NOCH_V1_0_TAGE_BZW_ABR'); ?></td><td><?php echo fer_t('TEXT.I1_URLAUBREST_I2_URLAUBIN'); ?></td></tr>
</table>
<div class="sm-small"><?php echo fer_t('TEXT.WICHTIG_DEN_URLAUBSMODUS_NIE_DIREK'); ?> <b><?php echo fer_t('TEXT.NICHT'); ?></b> <?php echo fer_t('TEXT.ALLEIN_AM_KALENDER_HNGEN_SONDERN_Z'); ?></div>
<b><?php echo fer_t('TEXT.PRAXIS_ERFAHRUNGEN_ZUM_BENACHRICHT'); ?></b> <?php echo fer_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?>
</div>

<div class="sm-step"><b><?php echo fer_t('TEXT.SCHRITT_5_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo fer_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?> <span class="sm-mono">http://<?= $fe_host ?>/plugins/<?= fe_e($fe_plugin) ?><?php echo fer_t('TEXT.FERIEN_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane" id="tab-test">
<h2>Test</h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo fer_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo fer_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo fer_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo fer_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php" target="_blank"><?php echo fer_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?json=1" target="_blank"><?php echo fer_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo fer_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?debug=1" target="_blank"><?php echo fer_t('TEXT.DEBUG_ALLE_TERMINE'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?refresh=1&amp;debug=1" target="_blank"><?php echo fer_t('TEXT.NEU_ABRUFEN_DEBUG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo fer_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?say=1" target="_blank"><?php echo fer_t('TEXT.TEST_ANSAGE'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= fe_e($fe_plugin) ?>/ferien.php?ptest=1" target="_blank"><?php echo fer_t('TEXT.TEST_PUSHNACHRICHT'); ?></a>
</div>


</div>

<!-- ================= Brueckentage ================= -->
<div class="sm-pane" id="tab-bridge">
<h2><?php echo fer_t('TEXT.BRCKENTAGE_DER_NCHSTEN_12_MONATE'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.WERKTAGE_ZWISCHEN_FEIERTAG_UND_WOC'); ?></div>
<?php if (!empty($fe_st['brueckentage'])) { ?>
<table class="sm-tbl"><tr><th><?php echo fer_t('TEXT.DATUM'); ?></th><th><?php echo fer_t('TEXT.WOCHENTAG'); ?></th><th><?php echo fer_t('TEXT.IN_TAGEN'); ?></th><th><?php echo fer_t('TEXT.ERGIBT'); ?></th></tr>
<?php foreach ((array) $fe_st['brueckentage'] as $fe_t) {
    $fe_ts = strtotime($fe_t);
    $fe_in = (int) floor(($fe_ts - strtotime(date('Y-m-d'))) / 86400);
    $fe_wt = (int) date('N', $fe_ts);
    $fe_erg = $fe_wt === 5 ? 'langes Wochenende (Do&ndash;So)' : ($fe_wt === 1 ? 'langes Wochenende (Sa&ndash;Di)' : '4 freie Tage am St&uuml;ck'); ?>
<tr><td><?= fe_e(fe_d($fe_t)) ?></td><td><?= fe_e(date('D', $fe_ts)) ?></td>
<td><?= $fe_in <= 0 ? 'heute' : $fe_in ?></td><td><?= $fe_erg ?></td></tr>
<?php } ?></table>
<div class="sm-small" style="margin-top:8px;"><?php echo fer_t('TEXT.EIN_BRUECKENTAG_IST_EIN_WERKTAG_DE'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.ZURZEIT_SIND_KEINE_BRCKENTAGE_BEKA'); ?> <b>Einstellungen</b><?php echo fer_t('TEXT.OB_BRCKENTAGE_ERKENNEN_AKTIVIERT_U'); ?> <b>Test</b> <?php echo fer_t('TEXT.DIE_DATEN_NEU_AB'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.KOMMENDE_FERIEN'); ?> ================= -->
<div class="sm-pane" id="tab-vacation">
<h2>Kommende Ferien</h2>
<?php if (function_exists('fer_data')) { $fe_d = fer_data(); $fe_heute = date('Y-m-d'); ?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.SCHULFERIEN_DES_GEWHLTEN_BUNDESLAN'); ?></div>
<table class="sm-tbl"><tr><th>von</th><th>bis</th><th>Bezeichnung</th><th><?php echo fer_t('TEXT.TAGE'); ?></th><th>Status</th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['ferien'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 14) { continue; }
    $fe_tage = (int) round((strtotime($fe_e2['bis']) - strtotime($fe_e2['von'])) / 86400) + 1;
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td><td><?= fe_e(fe_d($fe_e2['bis'])) ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['urlaub']) ? ' <span class="sm-small">(Urlaub &mdash; abwesend)</span>' : (!empty($fe_e2['eigen']) ? ' <span class="sm-small">(eigener Termin)</span>' : '') ?></td>
<td><?= $fe_tage ?></td>
<td><?= $fe_in <= 0 ? '<b>l&auml;uft</b>' : ('in ' . $fe_in . ' Tag' . ($fe_in === 1 ? '' : 'en')) ?></td></tr>
<?php } ?></table>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.DIE_BIBLIOTHEK_DES_PLUGINS_WURDE_N'); ?></div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.KOMMENDE_FEIERTAGE'); ?> ================= -->
<div class="sm-pane" id="tab-holiday">
<h2>Kommende Feiertage</h2>
<?php if (function_exists('fer_data')) { if (!isset($fe_d)) { $fe_d = fer_data(); } $fe_heute = date('Y-m-d'); ?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.GESETZLICHE_FEIERTAGE_DES_GEWHLTEN'); ?></div>
<table class="sm-tbl"><tr><th>Datum</th><th>Wochentag</th><th>Bezeichnung</th><th>in Tagen</th></tr>
<?php $fe_n = 0; foreach ((array) $fe_d['feiertage'] as $fe_e2) {
    if ($fe_e2['bis'] < $fe_heute || $fe_n++ > 17) { continue; }
    $fe_in = (int) floor((strtotime($fe_e2['von']) - strtotime($fe_heute)) / 86400);
    $fe_wt = (int) date('N', strtotime($fe_e2['von'])); ?>
<tr><td><?= fe_e(fe_d($fe_e2['von'])) ?></td>
<td><?= fe_e(date('D', strtotime($fe_e2['von']))) ?><?= ($fe_wt >= 6) ? ' <span class="sm-small">(f&auml;llt aufs Wochenende)</span>' : '' ?></td>
<td><?= fe_e($fe_e2['name']) ?><?= !empty($fe_e2['eigen']) ? ' <span class="sm-small">(eigener Termin)</span>' : '' ?><?= !empty($fe_e2['ortlich']) ? ' <span class="sm-small">(nur &ouml;rtlich)</span>' : '' ?></td>
<td><?= $fe_in <= 0 ? '<b>heute</b>' : $fe_in ?></td></tr>
<?php } ?></table>
<?php } else { ?>
<div class="sm-alert sm-info">Die Bibliothek des Plugins wurde nicht gefunden &mdash; bitte das Plugin neu installieren.</div>
<?php } ?>
</div>

<!-- ================= <?php echo fer_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo fer_t('TEXT.PROTOKOLLIERT_WERDEN_DATENABRUFE_Z'); ?><br><?php echo fer_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= fe_e($fe_logfile) ?></span></div>
<?php if ($fe_loglines) { ?>
<div class="sm-log"><?= fe_e(implode("\n", $fe_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo fer_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo fer_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
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
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($fe_tab) ?>);
    feTtsMode();
})();
</script>
<?php
if ($fe_frame) { LBWeb::lbfooter(); }
