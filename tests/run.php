<?php

/**
 * Testlauf gegen den Schreibtisch-Projektor (tests/fake_projector.php).
 *
 *   php tests/run.php
 *
 * Deckt ab, was sich an echter Hardware nicht herstellen laesst: Class-1-Geraete,
 * Authentifizierung, CRLF-Zeilenenden, abgelehnte AVMT-Varianten, ausbleibende
 * Antworten und den Umzug alter Eingangsnamen.
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../PJLinkProjector/module.php';

$bestanden = 0;
$gefallen  = 0;

function pruefe($name, $ok, $detail = '')
{
    global $bestanden, $gefallen;
    if ($ok) {
        $bestanden++;
        echo "  [ok]   $name\n";
    } else {
        $gefallen++;
        echo "  [FEHL] $name" . ($detail !== '' ? (' -> ' . $detail) : '') . "\n";
    }
}

function gleich($name, $erwartet, $ist)
{
    $e = is_bool($erwartet) ? var_export($erwartet, true) : (string)$erwartet;
    $i = is_bool($ist) ? var_export($ist, true) : (string)$ist;
    pruefe($name, $erwartet === $ist, "erwartet '$e', ist '$i'");
}

function abschnitt($t)
{
    echo PHP_EOL . "--- $t ---" . PHP_EOL;
}

/** Startet den Schreibtisch-Projektor und wartet, bis er bereit meldet. */
function starteProjektor(array $args)
{
    // Port 0: das Betriebssystem waehlt. Der Simulator meldet den echten Port zurueck.
    $cmd = [PHP_BINARY, __DIR__ . '/fake_projector.php', '--port=0'];
    foreach ($args as $k => $v) {
        $cmd[] = ($v === true) ? "--$k" : "--$k=$v";
    }

    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new Exception('Schreibtisch-Projektor liess sich nicht starten.');
    }

    stream_set_blocking($pipes[1], false);
    $frist = microtime(true) + 10;
    $port = 0;
    while (microtime(true) < $frist) {
        $zeile = fgets($pipes[1]);
        if ($zeile !== false && preg_match('/^READY (\d+)/', $zeile, $m)) {
            $port = (int)$m[1];
            break;
        }
        usleep(20000);
    }
    if ($port === 0) {
        proc_terminate($proc);
        throw new Exception('Schreibtisch-Projektor meldete sich nicht bereit.');
    }

    return ['proc' => $proc, 'pipes' => $pipes, 'port' => $port];
}

function stoppeProjektor($p)
{
    foreach ($p['pipes'] as $pipe) {
        @fclose($pipe);
    }
    @proc_terminate($p['proc']);
    @proc_close($p['proc']);
}

/** Frisches Modul gegen einen laufenden Schreibtisch-Projektor. */
function neuesModul($port, array $props = [])
{
    IPSKernel::reset();
    $m = new PJLinkProjector();
    $m->Create();
    $m->TestSetProperty('Host', '127.0.0.1');
    $m->TestSetProperty('Port', $port);
    $m->TestSetProperty('Vendor', 'PANASONIC');
    foreach ($props as $k => $v) {
        $m->TestSetProperty($k, $v);
    }
    return $m;
}

function profilVon($m)
{
    $name = 'PJP.Input.' . $m->InstanceID;
    $assoc = IPSKernel::$profiles[$name]['assoc'] ?? [];
    ksort($assoc);
    return $assoc;
}

// ---------------------------------------------------------------- Class 2
abschnitt('Class-2-Geraet: alle Eingaenge, Namen vom Geraet, Diagnose');
$p = starteProjektor(['class' => 2, 'erst' => '100020']);
try {
    $m = neuesModul($p['port'], [
        'EnableAVMute' => true, 'EnableFreeze' => true,
        'EnableDiagnostics' => true, 'DiagInterval' => 30,
    ]);
    $m->ApplyChanges();
    $m->Poll();

    gleich('Online', true, $m->GetValue('Online'));
    gleich('PowerState', 1, $m->GetValue('PowerState'));
    gleich('Input logisch (31 -> Platz 1)', 1, $m->GetValue('Input'));
    gleich('Verfuegbare Eingaenge', '11 31 32 33 41 51', $m->GetValue('AvailableInputs'));

    $profil = profilVon($m);
    gleich('Anzahl waehlbarer Eingaenge', 6, count($profil));
    gleich('Platz 1 traegt Geraetenamen', 'HDMI1', $profil[1] ?? '');
    gleich('Platz 3 ist DIGITAL LINK', 'DIGITAL LINK', $profil[3] ?? '');
    gleich('Zusatzeingang 11 heisst COMPUTER', 'COMPUTER', $profil[11] ?? '');
    gleich('Zusatzeingang 51 heisst NETWORK', 'NETWORK', $profil[51] ?? '');
    pruefe('SDI ohne Code faellt weg', !isset($profil[4]));

    gleich('Fehlerstatus im Klartext', 'Lüfter: Warnung, Filter: Fehler', $m->GetValue('ErrorStatus'));
    gleich('Stoerung gemeldet', true, $m->GetValue('HasFault'));
    gleich('Betriebsstunden', 1234, $m->GetValue('LampHours'));
    gleich('Filterstunden', 120, $m->GetValue('FilterHours'));
    gleich('Eingangsaufloesung', '1920x1080', $m->GetValue('InputResolution'));
    pruefe('Geraeteinfo gefuellt',
        strpos($m->GetValue('DeviceInfo'), 'Testhersteller Modell-X') === 0,
        $m->GetValue('DeviceInfo'));
    pruefe('Seriennummer in der Geraeteinfo',
        strpos($m->GetValue('DeviceInfo'), 'SN-TEST-1') !== false);

    // Umschalten auf einen Zusatzeingang
    $m->RequestAction('Input', 41);
    $m->Poll();
    gleich('Auf MEMORY VIEWER umgeschaltet', 41, $m->GetValue('Input'));
    gleich('Sollwert abgearbeitet', 0, $m->GetValue('__CmdInput'));

    // Zurueck auf den klassischen Platz
    $m->RequestAction('Input', 2);
    $m->Poll();
    gleich('Auf HDMI2 (Platz 2) umgeschaltet', 2, $m->GetValue('Input'));

    // AV-Mute
    $m->RequestAction('AVMute', true);
    $m->Poll();
    gleich('AV-Mute an', true, $m->GetValue('AVMute'));
    $m->RequestAction('AVMute', false);
    $m->Poll();
    gleich('AV-Mute aus', false, $m->GetValue('AVMute'));

    // Standbild
    $m->RequestAction('Freeze', true);
    $m->Poll();
    gleich('Standbild an', true, $m->GetValue('Freeze'));
    $m->RequestAction('Freeze', false);
    $m->Poll();
    gleich('Standbild aus', false, $m->GetValue('Freeze'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Class 1
abschnitt('Class-1-Geraet: keine Class-2-Befehle, Fallback-Beschriftung');
$p = starteProjektor(['class' => 1, 'inputs' => '31 32 33']);
try {
    $m = neuesModul($p['port'], [
        'EnableFreeze' => true, 'EnableDiagnostics' => true, 'DiagInterval' => 30,
    ]);
    $m->ApplyChanges();
    $m->Poll();

    gleich('Online', true, $m->GetValue('Online'));
    $profil = profilVon($m);
    gleich('Drei Eingaenge', 3, count($profil));
    gleich('Platz 1 mit Fallback-Namen', 'HDMI 1', $profil[1] ?? '');
    gleich('Platz 3 mit Fallback-Namen', 'HDBaseT', $profil[3] ?? '');

    gleich('Betriebsstunden auch ohne Class 2', 1234, $m->GetValue('LampHours'));
    gleich('Filterstunden bleiben leer', 0, $m->GetValue('FilterHours'));
    gleich('Aufloesung bleibt leer', '', $m->GetValue('InputResolution'));
    pruefe('Geraeteinfo ohne Seriennummer',
        strpos($m->GetValue('DeviceInfo'), 'SN-') === false,
        $m->GetValue('DeviceInfo'));
    pruefe('Geraeteinfo nennt Class 1',
        strpos($m->GetValue('DeviceInfo'), 'PJLink Class 1') !== false,
        $m->GetValue('DeviceInfo'));

    IPSKernel::$log = [];
    $m->RequestAction('Freeze', true);
    gleich('Standbild bleibt aus', false, $m->GetValue('Freeze'));
    pruefe('Hinweis auf Class 2 im Log',
        count(array_filter(IPSKernel::$log, function ($z) {
            return strpos($z, 'Class-2') !== false;
        })) > 0, implode(' | ', IPSKernel::$log));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Auth
abschnitt('Authentifizierung');
$p = starteProjektor(['password' => 'geheim']);
try {
    $m = neuesModul($p['port'], ['Password' => 'geheim']);
    $m->ApplyChanges();
    $m->Poll();
    gleich('Mit richtigem Passwort online', true, $m->GetValue('Online'));

    $m2 = neuesModul($p['port'], ['Password' => 'falsch']);
    $m2->ApplyChanges();
    $m2->Poll();
    gleich('Mit falschem Passwort offline', false, $m2->GetValue('Online'));
    pruefe('Passwort-Hinweis in LastError',
        strpos($m2->GetValue('LastError'), 'Authentifizierung') !== false,
        $m2->GetValue('LastError'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- CRLF
abschnitt('Geraet mit CRLF statt CR');
$p = starteProjektor(['terminator' => 'crlf']);
try {
    $m = neuesModul($p['port']);
    $m->ApplyChanges();
    $m->Poll();
    gleich('Online trotz CRLF', true, $m->GetValue('Online'));
    gleich('PowerState gelesen', 1, $m->GetValue('PowerState'));
    gleich('Eingangsliste gelesen', '11 31 32 33 41 51', $m->GetValue('AvailableInputs'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- AVMT-Variante
abschnitt('AVMT: Geraet lehnt Bild+Ton ab, Modul weicht aus');
$p = starteProjektor(['avmt-reject' => '3']);
try {
    $m = neuesModul($p['port'], ['EnableAVMute' => true]);
    $m->ApplyChanges();
    $m->Poll();

    $m->RequestAction('AVMute', true);
    $m->Poll();
    gleich('AV-Mute trotz abgelehnter Gruppe 3 aktiv', true, $m->GetValue('AVMute'));
    gleich('Funktionierende Variante gemerkt', 11, $m->ReadAttributeInteger('AVMuteVariant'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- FREZ ERR3
abschnitt('Standbild wird abgelehnt (ERR3) - Sollwert zurueckgenommen');
$p = starteProjektor(['freez-err3' => true]);
try {
    $m = neuesModul($p['port'], ['EnableFreeze' => true]);
    $m->ApplyChanges();
    $m->Poll();

    $m->RequestAction('Freeze', true);
    gleich('Standbild bleibt aus', false, $m->GetValue('Freeze'));
    gleich('Projektor bleibt online', true, $m->GetValue('Online'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Stumme Antwort
abschnitt('Ausbleibende Antwort waehrend eines Uebergangs');
$p = starteProjektor(['quiet-on' => '%1INPT']);
try {
    $m = neuesModul($p['port']);
    $m->ApplyChanges();
    $m->Poll();

    IPSKernel::$log = [];
    $m->Poll();
    gleich('Bleibt online', true, $m->GetValue('Online'));
    pruefe('Keine Ausfall-Warnung im Log',
        count(array_filter(IPSKernel::$log, function ($z) {
            return strpos($z, 'nicht mehr erreichbar') !== false;
        })) === 0, implode(' | ', IPSKernel::$log));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Kann nichts davon
abschnitt('Geraet kennt keine der Zusatzfunktionen - darf keine Fehler schreiben');
$p = starteProjektor(['unsupported' => 'AVMT,FREZ,ERST,LAMP,FILT,IRES,SNUM,SVER,INNM']);
try {
    $m = neuesModul($p['port'], [
        'EnableAVMute' => true, 'EnableFreeze' => true,
        'EnableDiagnostics' => true, 'DiagInterval' => 30,
    ]);
    $m->ApplyChanges();

    IPSKernel::$log = [];
    for ($i = 0; $i < 3; $i++) {
        $m->SetBuffer('DiagTS', '0'); // Drossel aus, damit die Diagnose jedes Mal laeuft
        $m->Poll();
    }

    gleich('Projektor bleibt online', true, $m->GetValue('Online'));
    gleich('Kein Fehlertext hinterlegt', '', $m->GetValue('LastError'));
    gleich('Fehlerzaehler bleibt 0', 0, $m->GetValue('ErrorCounter'));
    gleich('Power weiterhin gelesen', 1, $m->GetValue('PowerState'));
    gleich('Quelle weiterhin gelesen', 1, $m->GetValue('Input'));

    $laut = array_filter(IPSKernel::$log, function ($z) {
        return strpos($z, 'WARN') === 0 || strpos($z, 'ERROR') === 0;
    });
    pruefe('Keine Warnung und kein Fehler im Meldungslog',
        count($laut) === 0, implode(' | ', $laut));

    // Die Variablen bleiben schlicht auf ihrem Anfangswert stehen
    gleich('Betriebsstunden bleiben 0', 0, $m->GetValue('LampHours'));
    gleich('Fehlerstatus bleibt leer', '', $m->GetValue('ErrorStatus'));
    gleich('AV-Mute bleibt aus', false, $m->GetValue('AVMute'));

    // Bedient jemand die Funktion trotzdem, gibt es genau eine gedrosselte Warnung
    IPSKernel::$log = [];
    $m->RequestAction('AVMute', true);
    $warn = array_filter(IPSKernel::$log, function ($z) {
        return strpos($z, 'WARN') === 0;
    });
    pruefe('Handbedienung meldet sich genau einmal', count($warn) === 1, implode(' | ', $warn));
    gleich('Sollwert zurueckgenommen', false, $m->GetValue('AVMute'));
    gleich('Projektor trotzdem online', true, $m->GetValue('Online'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Migration
abschnitt('Alte Eingangsnamen (nach Platz) ziehen auf Geraetecodes um');
$p = starteProjektor([]);
try {
    IPSKernel::reset();
    $m = new PJLinkProjector();
    $m->Create();
    $m->TestSetProperty('Host', '127.0.0.1');
    $m->TestSetProperty('Port', $p['port']);
    $m->TestSetProperty('Vendor', 'PANASONIC');
    // Stand vor der Erweiterung: Namen nach logischem Platz, Geraeteliste bekannt
    $m->WriteAttributeString('DeviceInputs', json_encode([11, 31, 32, 33, 41, 51]));
    $m->WriteAttributeString('InputNames', json_encode([1 => 'Laptop', 2 => 'Dose Wand', 3 => 'Regie']));

    $m->ApplyChanges();

    $namen = json_decode($m->ReadAttributeString('InputNames'), true);
    gleich('Platz 1 -> Code 31', 'Laptop', $namen['31'] ?? '');
    gleich('Platz 2 -> Code 32', 'Dose Wand', $namen['32'] ?? '');
    gleich('Platz 3 -> Code 33', 'Regie', $namen['33'] ?? '');

    $profil = profilVon($m);
    gleich('Alte Beschriftung bleibt sichtbar', 'Laptop', $profil[1] ?? '');
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Profil aufraeumen
abschnitt('Weggefallener Eingang verschwindet aus der Auswahl');
$p1 = starteProjektor(['inputs' => '11 31 32 33 41 51']);
try {
    $m = neuesModul($p1['port']);
    $m->ApplyChanges();
    $m->Poll();
    gleich('Zunaechst sechs Eingaenge', 6, count(profilVon($m)));
} finally {
    stoppeProjektor($p1);
}

$p2 = starteProjektor(['inputs' => '31 32']);
try {
    // Gleiches Modul, gleiche Attribute - nur ein anderes Geraet dahinter
    $m->TestSetProperty('Port', $p2['port']);
    $m->RefreshAvailableInputs();

    $profil = profilVon($m);
    gleich('Jetzt nur noch zwei Eingaenge', 2, count($profil));
    pruefe('COMPUTER ist raus', !isset($profil[11]));
    pruefe('NETWORK ist raus', !isset($profil[51]));
    pruefe('DIGITAL LINK ist raus', !isset($profil[3]));
    gleich('HDMI 1 bleibt', 'HDMI1', $profil[1] ?? '');
} finally {
    stoppeProjektor($p2);
}

// ---------------------------------------------------------------- Warm-up
abschnitt('Warm-up: Sollwert wird uebernommen, schnell gepollt');
$p = starteProjektor(['power' => '3']);
try {
    $m = neuesModul($p['port'], ['PollFast' => 2, 'PollSlow' => 15]);
    $m->ApplyChanges();
    $m->Poll();

    gleich('PowerState Warm-up', 3, $m->GetValue('PowerState'));
    gleich('Busy gesetzt', true, $m->GetValue('Busy'));
    gleich('Sollwert auf Ein', true, $m->GetValue('__CmdPower'));
    gleich('Schneller Poll-Takt', 2000, $m->GetTimerInterval('PollTimer'));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Offline
abschnitt('Geraet nicht erreichbar');
$m = neuesModul(14999); // niemand hoert hier zu
$m->ApplyChanges();
$m->Poll();
gleich('Offline erkannt', false, $m->GetValue('Online'));
pruefe('Fehler vermerkt', $m->GetValue('LastError') !== '', $m->GetValue('LastError'));
gleich('Fehlerzaehler hochgezaehlt', 1, $m->GetValue('ErrorCounter'));

// ---------------------------------------------------------------- Fazit
echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
printf("%d bestanden, %d gefallen\n", $bestanden, $gefallen);
exit($gefallen === 0 ? 0 : 1);
