<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Ferien und Feiertage (linieneigen).
 *
 * Gruppe 1 / MQTT (1.2.27; Entwurf ff Abschnitt 3, E8 und E9; Bauvorschrift Gruppe 1, Nachtrag 19.10.2026). Muster:
 * vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php. Die Bruecke selbst (bin/befehlsbruecke.php, .sh) ist die
 * gemeinsame Stammfassung und wird hier nicht veraendert.
 *
 * Zwei Befehle, beide Pulse (Loxone sendet feste 1) und beide nicht eingreifend: "ansage" spricht die Vorabend-Ansage
 * sofort (ohne Anlass einen Testsatz), "testpush" setzt PTEST fuer fuenf Minuten und meldet sofort ueber MQTT. Einen
 * zweiten Haken (Steuerfreigabe) hat diese Linie nicht. ?refresh=1 ist bewusst kein Befehl (Bremse 300 s, die Daten
 * kommen woechentlich; "Jetzt abrufen" im Reiter Einstellungen genuegt).
 *
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Weg in ferien.php (?say=1 bzw. ?ptest=1): fer_befehl_ansage() und
 * fer_befehl_testpush(), mit Quelle 'mqtt'. Das Aktionstoken prueft nur der HTTP-Weg; hier gilt der Haken
 * "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) zusammen mit "Zustand per MQTT veroeffentlichen".
 * Das Praefix kommt aus fer_mqtt_praefix() - derselben Rechnung wie die Themen des Senders.
 *
 * Die Datei setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('fer_config')) {
    /* Gruppe 19 (1.2.28, FE14; Bauform Abfahrts-Assistent 1.6.31): ausdruecklich LBHOMEDIR + LBPPLUGINDIR (Pruefstaende)
     * zuerst, dann installiert (bin/plugins/<ordner> -> webfrontend/html/plugins/<ordner>) ODER Archiv (bin/ neben
     * webfrontend/) - nicht mehr beide. */
    $fer_kand = array();
    $fer_lb = (string) getenv('LBHOMEDIR');
    $fer_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($fer_lb !== '' && $fer_ordner !== ''
        && !in_array($fer_ordner, array('.', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $fer_kand[] = $fer_lb . '/webfrontend/html/plugins/' . $fer_ordner . '/ferien_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $fer_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ferien_lib.php';
    } else {
        $fer_kand[] = dirname(__DIR__) . '/webfrontend/html/ferien_lib.php';
    }
    foreach ($fer_kand as $fer_k) {
        if (is_file($fer_k)) { require_once $fer_k; break; }
    }
}
// array('ok' => bool, 'grund' => string) der Linie -> array(ok, grund) der Bruecke
$fer_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
return array(
    'fassung' => 1,
    'linie'   => 'ferien',
    'plugin'  => 'Ferien und Feiertage',
    'geladen' => function_exists('fer_config') && function_exists('fer_befehl_ansage') && function_exists('fer_mqtt_praefix')
                 && function_exists('fer_befehl_testpush'),
    'einstellungen' => function () {
        $c = fer_config();
        return array('praefix'     => fer_mqtt_praefix($c),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_enabled']),
                     'eingreifend' => false,
                     'mqtt_ein'    => !empty($c['mqtt_enabled']));
    },
    'protokoll' => function ($stufe, $text) { fer_log(($stufe === 'INFO' ? '' : $stufe . ': ') . $text); },
    'befehle' => array(
        'ansage'   => array('art' => 'puls', 'eingreifend' => false,
                            'titel' => 'Ferien und Feiertage Ansage jetzt sprechen (MQTT)',
                            'kachel' => 'BEFEHL.K_ANSAGE', 'quittung' => 'ausgefuehrt',
                            'tun' => function ($w, $t) use ($fer_um) { return $fer_um(fer_befehl_ansage('mqtt')); }),
        'testpush' => array('art' => 'puls', 'eingreifend' => false,
                            'titel' => 'Ferien und Feiertage Test-Push auslösen (MQTT)',
                            'kachel' => 'BEFEHL.K_TESTPUSH', 'quittung' => 'ausgefuehrt',
                            'tun' => function ($w, $t) use ($fer_um) { return $fer_um(fer_befehl_testpush('mqtt')); }),
    ),
);
