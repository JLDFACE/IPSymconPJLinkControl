<?php

/**
 * Schreibtisch-Projektor: ein kleiner PJLink-Server zum Testen.
 *
 * Damit lassen sich Faelle pruefen, die sich an echter Hardware nicht oder nur
 * schwer herstellen lassen - ein Class-1-Geraet, aktivierte Authentifizierung,
 * ERR-Antworten, ein Geraet das mit CRLF statt CR abschliesst.
 *
 *   php tests/fake_projector.php --port=14352 --class=1 --inputs="31 32"
 *
 * Optionen:
 *   --port=<n>          Port (Vorgabe 14352)
 *   --class=1|2         PJLink-Klasse (Vorgabe 2)
 *   --password=<pw>     aktiviert die Authentifizierung
 *   --inputs="31 32"    Eingangsliste fuer INST
 *   --terminator=cr|crlf  Zeilenende der Antworten (Vorgabe cr)
 *   --power=0|1         Startzustand
 *   --input=<code>      aktiver Eingang
 *   --erst=<6 Ziffern>  Fehlerstatus
 *   --avmt-reject=<n>   AVMT-Gruppe, die mit ERR2 abgelehnt wird (z. B. 1)
 *   --freez-err3        FREZ antwortet immer mit ERR3
 *   --quiet-on=<cmd>    dieser Befehl bleibt einmal unbeantwortet (z. B. "%1INPT")
 *   --log=<datei>       Mitschrift aller Befehle
 */

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    } elseif (preg_match('/^--(.+)$/', $arg, $m)) {
        $opt[$m[1]] = '1';
    }
}

$port       = (int)($opt['port'] ?? 14352);
$klasse     = (int)($opt['class'] ?? 2);
$passwort   = (string)($opt['password'] ?? '');
$terminator = (($opt['terminator'] ?? 'cr') === 'crlf') ? "\r\n" : "\r";
$logDatei   = $opt['log'] ?? '';

$state = [
    'POWR'   => (string)($opt['power'] ?? '1'),
    'INPT'   => (string)($opt['input'] ?? '31'),
    'AVMT'   => '30',
    'FREZ'   => '0',
    'ERST'   => (string)($opt['erst'] ?? '000000'),
    'LAMP'   => (string)($opt['lamp'] ?? '1234 1'),
    'FILT'   => '00120',
    'IRES'   => '1920x1080',
    'RRES'   => '1920x1200',
    'INST'   => (string)($opt['inputs'] ?? '11 31 32 33 41 51'),
    'NAME'   => 'Testprojektor',
    'INF1'   => 'Testhersteller',
    'INF2'   => 'Modell-X',
    'INFO'   => 'Version:9.99',
    'SNUM'   => 'SN-TEST-1',
    'SVER'   => 'MAIN:9.99',
];

$namen = [
    '11' => 'COMPUTER', '31' => 'HDMI1', '32' => 'HDMI2',
    '33' => 'DIGITAL LINK', '41' => 'MEMORY VIEWER', '51' => 'NETWORK',
];

// Befehle, die das Geraet gar nicht kennt - antwortet mit ERR1 wie ein aelteres Modell
$unsupported = [];
if (!empty($opt['unsupported'])) {
    $unsupported = array_map('trim', explode(',', strtoupper($opt['unsupported'])));
}

$avmtReject = isset($opt['avmt-reject']) ? (int)$opt['avmt-reject'] : 0;
$freezErr3  = isset($opt['freez-err3']);
$quietOn    = (string)($opt['quiet-on'] ?? '');
$quietUsed  = false;

// Port 0 = das Betriebssystem sucht einen freien Port. Der Test verwendet das,
// damit ein haengengebliebener Simulator aus einem abgebrochenen Lauf nicht
// stillschweigend die Antworten uebernimmt (Windows erlaubt das doppelte Binden).
$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Server auf Port $port nicht moeglich: $errstr ($errno)\n");
    exit(1);
}

$adresse = stream_socket_get_name($server, false);
$port = (int)substr($adresse, strrpos($adresse, ':') + 1);

function schreibeLog($datei, $text)
{
    if ($datei !== '') {
        @file_put_contents($datei, $text . "\n", FILE_APPEND);
    }
}

function leseZeile($fp)
{
    $out = '';
    while (true) {
        $c = fread($fp, 1);
        if ($c === '' || $c === false) {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out']) || feof($fp)) {
                return ($out === '') ? null : $out;
            }
            usleep(1000);
            continue;
        }
        if ($c === "\r" || $c === "\n") {
            if ($out === '') {
                continue;
            }
            return $out;
        }
        $out .= $c;
        if (strlen($out) > 512) {
            return $out;
        }
    }
}

// Bereitschaft melden, damit der Test nicht blind wartet
echo "READY $port\n";
flush();

while (true) {
    $client = @stream_socket_accept($server, 30);
    if (!$client) {
        continue;
    }
    stream_set_timeout($client, 3);

    if ($passwort !== '') {
        $seed = sprintf('%08x', random_int(0, 0xFFFFFFFF));
        fwrite($client, 'PJLINK 1 ' . $seed . $terminator);
    } else {
        $seed = '';
        fwrite($client, 'PJLINK 0' . $terminator);
    }

    while (true) {
        $zeile = leseZeile($client);
        if ($zeile === null) {
            break;
        }

        // Authentifizierungs-Praefix abtrennen und pruefen
        if ($passwort !== '') {
            if (!preg_match('/^([0-9a-f]{32})(%.*)$/i', $zeile, $m)) {
                fwrite($client, 'PJLINK ERRA' . $terminator);
                break;
            }
            if (strtolower($m[1]) !== md5($seed . $passwort)) {
                fwrite($client, 'PJLINK ERRA' . $terminator);
                break;
            }
            $zeile = $m[2];
        }

        schreibeLog($logDatei, $zeile);

        // Ziffern gehoeren dazu: INF1, INF2 sind gueltige PJLink-Befehle
        if (!preg_match('/^%([12])([A-Z0-9]{4})\s*(.*)$/', $zeile, $m)) {
            fwrite($client, 'PJLINK ERR1' . $terminator);
            continue;
        }

        $cmdKlasse = (int)$m[1];
        $cmd       = $m[2];
        $param     = trim($m[3]);
        $kopf      = '%' . $cmdKlasse . $cmd . '=';

        // Genau einmal stumm bleiben (simuliert den Panasonic beim Umschalten)
        if ($quietOn !== '' && !$quietUsed && strpos($zeile, $quietOn) === 0) {
            $quietUsed = true;
            continue;
        }

        // Class-2-Befehl an einem Class-1-Geraet
        if ($cmdKlasse > $klasse) {
            fwrite($client, $kopf . 'ERR1' . $terminator);
            continue;
        }

        // Befehl, den dieses Modell gar nicht kennt
        if (in_array($cmd, $unsupported, true)) {
            fwrite($client, $kopf . 'ERR1' . $terminator);
            continue;
        }

        if ($cmd === 'CLSS') {
            fwrite($client, '%1CLSS=' . $klasse . $terminator);
            continue;
        }

        if ($cmd === 'INNM') {
            $code = ltrim($param, '?');
            fwrite($client, $kopf . ($namen[$code] ?? 'ERR2') . $terminator);
            continue;
        }

        if ($param === '?') {
            fwrite($client, $kopf . ($state[$cmd] ?? 'ERR1') . $terminator);
            continue;
        }

        // Setzen
        if ($cmd === 'POWR') {
            $state['POWR'] = ($param === '1') ? '1' : '0';
            fwrite($client, $kopf . 'OK' . $terminator);
            continue;
        }

        if ($cmd === 'INPT') {
            if (!in_array($param, preg_split('/\s+/', $state['INST']), true)) {
                fwrite($client, $kopf . 'ERR2' . $terminator);
                continue;
            }
            $state['INPT'] = $param;
            fwrite($client, $kopf . 'OK' . $terminator);
            continue;
        }

        if ($cmd === 'AVMT') {
            $gruppe = (int)substr($param, 0, 1);
            if ($avmtReject > 0 && $gruppe === $avmtReject) {
                fwrite($client, $kopf . 'ERR2' . $terminator);
                continue;
            }
            $state['AVMT'] = ((int)substr($param, 1, 1) === 1) ? ($gruppe . '1') : '30';
            fwrite($client, $kopf . 'OK' . $terminator);
            continue;
        }

        if ($cmd === 'FREZ') {
            if ($freezErr3) {
                fwrite($client, $kopf . 'ERR3' . $terminator);
                continue;
            }
            $state['FREZ'] = ($param === '1') ? '1' : '0';
            fwrite($client, $kopf . 'OK' . $terminator);
            continue;
        }

        fwrite($client, $kopf . 'ERR1' . $terminator);
    }

    fclose($client);
}
