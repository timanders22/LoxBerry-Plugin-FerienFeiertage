<?php
/**
 * Ferien und Feiertage - Miniserver-Endpunkt
 *
 * Aufrufe:
 *   (ohne Parameter) -> FERIEN;OK=..;FERIEN=..;FEIERTAG=..;WOCHENENDE=..;SCHULFREI=..;SCHULTAG=..;BRUECKE=..;
 *                        URLAUB=..;MURLAUB=..;URLAUBIN=..;URLAUBREST=..;URLAUBENDE=..;
 *                       MFERIEN=..;MFEIERTAG=..;MSCHULFREI=..;MSCHULTAG=..;MBRUECKE=..;
 *                       FERIENIN=..;FERIENREST=..;FERIENDAUER=..;FEIERTAGIN=..;WARN=..;ANN=..;AUDIO=..;PUSH=..;PTEST=..
 *                       SCHULTAG=1  -> heute ist ein normaler Schul-/Arbeitstag
 *                       MSCHULTAG=1 -> MORGEN ist Schultag (fuer Wecker und Abendlogik)
 *                       BRUECKE=1   -> Brueckentag (Werktag zwischen Feiertag und Wochenende)
 *                       WARN=1      -> Daten reichen weniger als 60 Tage in die Zukunft
 *   ?debug=1         -> Ferien- und Feiertagsliste im Klartext
 *   ?refresh=1       -> Daten sofort neu abrufen
 *   ?json=1          -> kompletter Zustand als JSON
 *   ?say=1           -> Test: Vorabend-Ansage abspielen
 *   ?ptest=1         -> Test-Pushnachricht ausloesen (PTEST=1 fuer 5 Minuten)
 */

require_once __DIR__ . '/ferien_lib.php';

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    fer_fetch(isset($_GET['refresh']));
    $st = fer_state(isset($_GET['refresh']));
    $st['ann'] = fer_ann_active($st);
    $st['ptest'] = fer_ptest_active();
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (isset($_GET['say'])) {
    $st = fer_state();
    $text = fer_announce_text($st);
    if ($text === '') {
        $text = 'Hallo! Dies ist eine Testansage des Ferien-Plugins. Morgen ist ein ganz normaler Tag.';
    }
    $ok = fer_say($text);
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ";TEXT=$text\n";
    exit;
}

if (isset($_GET['ptest'])) {
    @file_put_contents(fer_tmpdir() . '/ptest', '1');
    fer_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten)');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 4) verdrahtet ist.\n";
    exit;
}

list($ok, $quelle) = fer_fetch(isset($_GET['refresh']));
$st = fer_state(isset($_GET['refresh']));
$cfg = fer_config();

if (isset($_GET['debug'])) {
    $d = fer_data();
    echo 'DEBUG  Region: ' . $cfg['country'] . '/' . $cfg['subdivision'] . '  Quelle: ' . $quelle
       . '  Stand: ' . substr((string) $st['stand'], 0, 19) . '  Daten bis: ' . $st['reicht_bis'] . "\n";
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
        printf("  %s bis %s  %s%s\n", $e['von'], $e['bis'], $e['name'], !empty($e['eigen']) ? '  (eigener Termin)' : '');
    }
    echo "\nKommende Feiertage:\n";
    foreach ((array) $d['feiertage'] as $e) {
        if ($e['bis'] < date('Y-m-d')) { continue; }
        printf("  %s  %s%s\n", $e['von'], $e['name'], !empty($e['eigen']) ? '  (eigener Termin)' : '');
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
 * ACHTUNG - die REIHENFOLGE der Felder ist Teil der Schnittstelle.
 *
 * Loxone sucht in der Zeile die wortwoertliche Zeichenkette der
 * Befehlserkennung (z. B. "FERIEN=") und nimmt den ERSTEN Treffer. In dieser
 * Zeile steht "FERIEN=" aber auch als Teil von "MFERIEN=" - genauso
 * "FEIERTAG=" in "MFEIERTAG=", "SCHULFREI=" in "MSCHULFREI=", "SCHULTAG=" in
 * "MSCHULTAG=", "BRUECKE=" in "MBRUECKE=" und "URLAUB=" in "MURLAUB=".
 *
 * Gutgegangen ist das bisher nur deshalb, weil das Feld fuer HEUTE jeweils
 * VOR seinem M-Gegenstueck steht. Wer die Felder umsortiert - etwa um sie
 * huebscher zu gruppieren -, liefert Loxone stillschweigend den Wert von
 * morgen als den von heute. Es gaebe keine Fehlermeldung, nur falsche
 * Wecker.
 *
 * Wer hier etwas aendert, prueft danach: fuer jedes Feld muss der erste
 * Treffer von "<NAME>=" auch zu diesem Feld gehoeren.
 */
printf("FERIEN;OK=%d;FERIEN=%d;FEIERTAG=%d;WOCHENENDE=%d;SCHULFREI=%d;SCHULTAG=%d;BRUECKE=%d;MFERIEN=%d;MFEIERTAG=%d;MSCHULFREI=%d;MSCHULTAG=%d;MBRUECKE=%d;FERIENIN=%d;FERIENREST=%d;FERIENDAUER=%d;FEIERTAGIN=%d;URLAUB=%d;MURLAUB=%d;URLAUBIN=%d;URLAUBREST=%d;URLAUBDAUER=%d;URLAUBENDE=%d;WARN=%d;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d\n",
    $st['ok'],
    $st['heute']['ferien'], $st['heute']['feiertag'], $st['heute']['wochenende'],
    $st['heute']['schulfrei'], $st['heute']['schultag'], $st['heute']['bruecke'],
    $st['morgen']['ferien'], $st['morgen']['feiertag'], $st['morgen']['schulfrei'],
    $st['morgen']['schultag'], $st['morgen']['bruecke'],
    $st['naechste']['in'], $st['naechste']['rest'], $st['naechste']['dauer'],
    $st['feiertag_naechster']['in'],
    $st['heute']['urlaub'], $st['morgen']['urlaub'],
    $st['urlaub']['in'], $st['urlaub']['rest'], $st['urlaub']['dauer'], $st['urlaub']['letzter_tag'],
    $st['warnung'],
    fer_ann_active($st),
    empty($cfg['notify']['audio']) ? 0 : 1,
    empty($cfg['notify']['push']) ? 0 : 1,
    fer_ptest_active());
