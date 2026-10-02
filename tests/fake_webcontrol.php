<?php

/**
 * Schreibtisch-Web-Control: beantwortet die Objektivbefehle wie ein Epson EB-PU2216B.
 *
 *   php -S 127.0.0.1:<port> tests/fake_webcontrol.php
 *
 * Zustand und Mitschrift liegen in Dateien, weil der PHP-Server jede Anfrage in einem
 * frischen Prozess bearbeitet:
 *   FAKE_WC_STATE  JSON mit zoom, focus, h, v, lpstatus (Bitmuster), names (Platz => Hex),
 *                  mem (Platz => Positionen), reject (Liste der Befehle, die das Gerät ignoriert),
 *                  invert (Achsen, an denen INC den Wert senkt - wie am PU2216B im SKUZ: ["h"])
 *   FAKE_WC_LOG    jede Anfrage als eine Zeile "pfad?befehl" (URL-dekodiert)
 *
 * Wie das Original: json_query antwortet mit {"projector":{"feature":{...}}},
 * directsend mit leerem Inhalt - ob ein Befehl wirkte, zeigt erst das Nachlesen.
 */

$stateFile = (string)getenv('FAKE_WC_STATE');
$logFile   = (string)getenv('FAKE_WC_LOG');

$state = json_decode((string)@file_get_contents($stateFile), true);
if (!is_array($state)) {
    $state = [];
}
$state += [
    'zoom' => 12, 'focus' => 153, 'h' => 32521, 'v' => 34374,
    'lpstatus' => 0, 'names' => [], 'mem' => [], 'reject' => [], 'invert' => [],
];

$pfad  = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY));

if ($logFile !== '') {
    file_put_contents($logFile, $pfad . '?' . $query . "\n", FILE_APPEND);
}

function antwort($reply, $error = false)
{
    header('Content-Type: application/json');
    echo json_encode(['projector' => ['feature' => [
        'name' => 'esc/vp21', 'query' => '', 'reply' => (string)$reply, 'error' => (bool)$error,
    ]]]);
}

if ($pfad === '/cgi-bin/json_query') {
    $cmd = preg_replace('/^jsoncallback=/', '', $query);
    $achsen = ['IMZOOM?' => 'zoom', 'IMFOCUS?' => 'focus', 'IMHLENS?' => 'h', 'IMLENS?' => 'v'];

    if (isset($achsen[$cmd])) {
        antwort($state[$achsen[$cmd]] . ' 04');
    } elseif ($cmd === 'IMLPSTATUS?') {
        antwort(sprintf('%04X', (int)$state['lpstatus']));
    } elseif (preg_match('/^NAMELP\? (\d+)$/', $cmd, $m)) {
        $platz = (int)$m[1];
        if ($platz < 1 || $platz > 9) {
            antwort('ERR', true);
        } elseif (isset($state['names'][$platz])) {
            antwort($state['names'][$platz]);
        } else {
            antwort('', true); // wie das Gerät: Platz da, aber ohne Namen
        }
    } else {
        antwort('ERR', true);
    }
    exit;
}

if ($pfad === '/cgi-bin/directsend') {
    $teile = explode('=', $query, 2);
    $cmd = $teile[0];
    $wert = isset($teile[1]) ? $teile[1] : '';

    if (!in_array($cmd, $state['reject'], true)) {
        $absolut = ['IMZOOM' => 'zoom', 'IMFOCUS' => 'focus'];
        $relativ = ['IMHLENS' => 'h', 'IMLENS' => 'v'];

        if (isset($absolut[$cmd]) && preg_match('/^\d+$/', $wert)) {
            $state[$absolut[$cmd]] = (int)$wert;
        } elseif (isset($relativ[$cmd]) && preg_match('/^(INC|DEC) (\d+)$/', $wert, $m)) {
            $achse = $relativ[$cmd];
            $richtung = ($m[1] === 'INC' ? 1 : -1) * (in_array($achse, $state['invert'], true) ? -1 : 1);
            $state[$achse] += $richtung * (int)$m[2];
        } elseif ($cmd === 'PUSHLP' && preg_match('/^[1-9]$/', $wert)) {
            $state['mem'][$wert] = ['zoom' => $state['zoom'], 'focus' => $state['focus'],
                                    'h' => $state['h'], 'v' => $state['v']];
            $state['lpstatus'] = (int)$state['lpstatus'] | (1 << ((int)$wert - 1));
        } elseif ($cmd === 'POPLP' && isset($state['mem'][$wert])) {
            foreach ($state['mem'][$wert] as $k => $v) {
                $state[$k] = $v;
            }
        } elseif ($cmd === 'NAMELP' && preg_match('/^([1-9]) (.+)$/', $wert, $m)) {
            $state['names'][$m[1]] = $m[2];
        }
    }

    file_put_contents($stateFile, json_encode($state));
    exit; // leerer Inhalt, HTTP 200
}

http_response_code(404);
