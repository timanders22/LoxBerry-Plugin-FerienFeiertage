<?php
/**
 * Ferien und Feiertage - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Daten woechentlich nachladen (und automatisch, wenn sie bald auslaufen).
 * 2. Zustand aktualisieren, bei Aenderung per MQTT melden (sonst halbstuendlich).
 * 3. Vorabend-Ansage und Brueckentags-Uebersicht im Januar.
 */

require_once __DIR__ . '/ferien_lib.php';

$st = fer_state();
// Nachladen, wenn die Daten bald auslaufen (hoechstens einmal taeglich versuchen)
$force = false;
if (!empty($st['warnung'])) {
    $flag = fer_tmpdir() . '/renew_' . date('Ymd');
    if (!is_file($flag)) {
        @file_put_contents($flag, '1');
        $force = true;
        fer_log('Daten laufen bald aus - hole neue Ferien-/Feiertagsdaten');
    }
}
fer_fetch($force);
$st = fer_state($force);

fer_announce_check();

$sig = json_encode(array($st['heute'], $st['morgen'], $st['naechste'], $st['ok'], $st['warnung']));
$sigf = fer_tmpdir() . '/mqtt_sig.txt';
$beat = fer_tmpdir() . '/mqtt_beat';
$old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
    fer_mqtt_publish($st);
    @file_put_contents($sigf, $sig);
    @touch($beat);
}

foreach (glob(fer_tmpdir() . '/renew_*') ?: array() as $f) {
    if (basename($f) !== 'renew_' . date('Ymd')) { @unlink($f); }
}
echo "OK\n";
