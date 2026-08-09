<?php
/**
 * Ferien und Feiertage - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Daten woechentlich nachladen (und automatisch, wenn sie bald auslaufen).
 * 2. Zustand aktualisieren, bei Aenderung per MQTT melden (sonst halbstuendlich).
 * 3. Vorabend-Ansage und Brueckentags-Uebersicht im Januar.
 *
 * WARUM DIESE DATEI IN bin/ LIEGT UND NICHT MEHR IN webfrontend/html/
 *
 * Aufgerufen wird sie ausschliesslich vom Minutencron, und zwar ueber die
 * PHP-Kommandozeile - nicht ueber HTTP. Im HTML-Verzeichnis war sie darueber
 * hinaus fuer jeden im Heimnetz abrufbar, und ein Aufruf stoesst einen
 * vollstaendigen Durchlauf an: Abruf bei openholidaysapi.org, MQTT-Meldung,
 * im Zweifel eine Ansage ueber den Audioserver. Ein Weg, der von aussen nicht
 * gebraucht wird, sollte von aussen auch nicht erreichbar sein.
 */

/*
 * ferien_lib.php bleibt im HTML-Verzeichnis - dort liegt auch ferien.php, der
 * Endpunkt fuer den Miniserver. LoxBerry ersetzt die Marke bei der
 * Installation durch den Plugin-HTML-Pfad; laeuft dieses Skript aus dem
 * ausgepackten Archiv heraus, steht sie noch unveraendert da, und der Pfad
 * wird relativ zu dieser Datei gebildet.
 */
$fer_htmldir = 'REPLACELBPHTMLDIR';
if (strpos($fer_htmldir, 'REPLACE') === 0 || !is_file($fer_htmldir . '/ferien_lib.php')) {
    $fer_htmldir = dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($fer_htmldir . '/ferien_lib.php')) {
    fwrite(STDERR, "ferien_lib.php nicht gefunden (gesucht in $fer_htmldir)\n");
    exit(1);
}
require_once $fer_htmldir . '/ferien_lib.php';

/*
 * Nur ein Lauf gleichzeitig.
 *
 * Der Minutencron startet dieses Skript jede Minute neu, ein Durchlauf kann
 * aber deutlich laenger dauern: fer_http wartet je Endpunkt bis zu 20 s, bei
 * Schulferien UND Feiertagen sind das 40 s, dazu kommt im Zweifel eine
 * Ansage mit weiteren 10 s. Haengt openholidaysapi.org, stapeln sich die
 * Laeufe - und jeder von ihnen schreibt am Ende in dieselben Dateien.
 *
 * Ist schon einer unterwegs, endet dieser Lauf ruhig. Das ist kein Fehler,
 * sondern der Normalfall bei einer langsamen Gegenstelle, und gehoert
 * deshalb auch nicht ins Protokoll - sonst stuende es alle sechzig Sekunden
 * darin.
 */
$fer_lock = fer_sperre('cron');
if ($fer_lock === false) {
    echo "BUSY\n";
    exit(0);
}

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

flock($fer_lock, LOCK_UN);
fclose($fer_lock);
echo "OK\n";
