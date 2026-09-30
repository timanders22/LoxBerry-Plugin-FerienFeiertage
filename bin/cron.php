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

/* C16 (1.2.16): die Bibliothek stellt beim Einbinden keine Zeitzone mehr ein;
 * dieser Lauf ist ein eigener Prozess und rechnet in Europe/Berlin. */
date_default_timezone_set('Europe/Berlin');

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

/* Abruf (C4, C7; 1.2.16): fer_fetch() entscheidet selbst, ob er faellig ist -
 * woechentlich, frueher wenn die Daten bald auslaufen, ein Teil fehlte oder
 * die Region nicht passt, und nach einem Fehlschlag gebremst (1 h, 6 h, 24 h).
 * Das taegliche Erzwingen bei WARN (renew_<Datum>) ist entfallen: es umging
 * die Bremse, und WARN kommt seit 1.2.16 auch vom Alter des Stands. */
fer_fetch(false);
/* Der Kalender wird NUR hier gefragt (C13), mit 5 s Zeitgrenze. */
fer_ics_abrufen(false);
$st = fer_state();

fer_announce_check();

/* Die Meldeflags gehoeren in die Signatur, sonst waeren sie zwar in der
 * Nachricht - aber die Nachricht ginge nicht raus. ann und ptest aendern
 * sich naemlich OHNE Zustandswechsel, allein durch Zeitablauf. Ohne sie in
 * der Signatur bliebe ein ptest bis zum naechsten Zustandswechsel oder bis
 * zum halbstuendlichen Lebenszeichen liegen - sein Fenster ist aber nur
 * fuenf Minuten breit. */
$fer_cfg = fer_config();
$sigf = fer_tmpdir() . '/mqtt_sig.txt';
$beat = fer_tmpdir() . '/mqtt_beat';
if (empty($fer_cfg['mqtt_enabled'])) {
    /* M1 (1.2.16): solange MQTT aus ist, werden Signatur und Merker nicht
     * fortgeschrieben - und weggeraeumt. Bis 1.2.15 lief beides weiter, und
     * nach dem Einschalten blieb es bis zu 30 min still (Regeln/07: sofortiger
     * Vollversand nach dem Einschalten und nach einem Praefixwechsel). */
    @unlink($sigf);
    @unlink($beat);
} else {
    /* Praefix und Alter gehoeren in die Signatur (M1, M2): ein neues Praefix
     * sendet sofort den Vollsatz unter dem neuen Namen. */
    $sig = json_encode(array($st['heute'], $st['morgen'], $st['naechste'], $st['ok'], $st['warnung'],
                             fer_meldeflags($st), (string) $fer_cfg['mqtt_topic'], $st['alter_tage']));
    if ($sig === false) { $sig = 'unlesbar'; }
    $old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
    if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
        /* Signatur und Merker nur, wenn WIRKLICH gesendet wurde (M1). */
        if (fer_mqtt_publish($st) > 0) {
            fer_datei_schreiben($sigf, $sig, 0600);
            @touch($beat);
        }
    } else {
        /* M2: das Lebenszeichen (status/ts, datum) bei JEDEM Lauf. */
        fer_mqtt_publish($st, true);
    }
}

foreach (glob(fer_tmpdir() . '/renew_*') ?: array() as $f) {
    if (basename($f) !== 'renew_' . date('Ymd')) { @unlink($f); }
}

flock($fer_lock, LOCK_UN);
fclose($fer_lock);
echo "OK\n";
