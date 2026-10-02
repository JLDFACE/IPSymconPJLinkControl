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

// ---------------------------------------------------------------- Epson ohne HDBaseT
abschnitt('Epson EB-L265F: kein HDBaseT (56), dafuer LAN (52) - kein falscher Ersatz');
// Belegung und Namen genau so, wie der echte EB-L265F sie meldet
$p = starteProjektor([
    'inputs' => '11 12 21 32 33 41 44 52 53 57 58',
    'input'  => '32',
    'names'  => '11:Computer1,12:Computer2,21:Video,32:HDMI1,33:HDMI2,41:USB,44:Spotlight,'
              . '52:LAN,53:USB Display,57:Screen Mirroring1,58:Screen Mirroring2',
]);
try {
    $m = neuesModul($p['port'], ['Vendor' => 'EPSON']);
    $m->ApplyChanges();
    $m->Poll();

    $profil = profilVon($m);
    pruefe('HDBaseT-Platz entfaellt', !isset($profil[3]), $profil[3] ?? '');
    pruefe('SDI-Platz entfaellt', !isset($profil[4]), $profil[4] ?? '');
    gleich('HDMI 1 auf Platz 1 (Epson 32)', 'HDMI1', $profil[1] ?? '');
    gleich('HDMI 2 auf Platz 2 (Epson 33)', 'HDMI2', $profil[2] ?? '');
    pruefe('LAN mit eigenem Wert 52', isset($profil[52]), implode(',', array_keys($profil)));
    gleich('Alle elf Geraeteeingaenge waehlbar', 11, count($profil));
    gleich('Ist-Eingang 32 als Platz 1', 1, $m->GetValue('Input'));

    // Ein Skript, das noch "3 = HDBaseT" schickt, darf nicht auf LAN schalten
    IPSKernel::$log = [];
    $m->RequestAction('Input', 3);
    $m->Poll();
    gleich('Platz 3 schaltet nichts um', 1, $m->GetValue('Input'));
    gleich('Kein Sollwert haengen geblieben', 0, $m->GetValue('__CmdInput'));
    pruefe('Hinweis, dass es den Eingang nicht gibt',
        count(array_filter(IPSKernel::$log, function ($z) {
            return strpos($z, 'gibt es an diesem Projektor nicht') !== false;
        })) === 1, implode(' | ', IPSKernel::$log));

    $m->RequestAction('Input', 52);
    $m->Poll();
    gleich('Auf LAN (52) direkt schaltbar', 52, $m->GetValue('Input'));
} finally {
    stoppeProjektor($p);
}

abschnitt('Epson mit SDI (Art EB-PU2216): SDI auf 34 oder auf anderem Code');
foreach ([['34', 'SDI auf dem Epson-Standard 34'], ['35', 'SDI auf abweichendem Code 35']] as $fall) {
    list($sdi, $titel) = $fall;
    $p = starteProjektor([
        'inputs' => "11 32 $sdi 56",
        'input'  => '32',
        'names'  => "11:Computer,32:HDMI,$sdi:SDI,56:HDBaseT",
    ]);
    try {
        $m = neuesModul($p['port'], ['Vendor' => 'EPSON']);
        $m->ApplyChanges();
        $m->Poll();
        $profil = profilVon($m);
        gleich("$titel: Platz 4 heisst SDI", 'SDI', $profil[4] ?? '');
        gleich("$titel: Platz 3 ist HDBaseT (56)", 'HDBaseT', $profil[3] ?? '');
        pruefe("$titel: SDI nicht doppelt", !isset($profil[(int)$sdi]));
        pruefe("$titel: HDMI-2-Platz greift nicht nach SDI", !isset($profil[2]), $profil[2] ?? '');

        $m->RequestAction('Input', 4);
        $m->Poll();
        gleich("$titel: Platz 4 schaltet auf SDI", 4, $m->GetValue('Input'));
        gleich("$titel: Sollwert abgearbeitet", 0, $m->GetValue('__CmdInput'));
    } finally {
        stoppeProjektor($p);
    }
}

abschnitt('Sony-Fall bleibt: HDBaseT 36 fehlt, 33 springt ein (Digitalgruppe)');
$p = starteProjektor(['inputs' => '31 32 33']);
try {
    $m = neuesModul($p['port'], ['Vendor' => 'SONY']);
    $m->ApplyChanges();
    $m->Poll();
    $profil = profilVon($m);
    pruefe('Platz 3 belegt', isset($profil[3]), implode(',', array_keys($profil)));
    gleich('Platz 3 ist Code 33', 'DIGITAL LINK', $profil[3] ?? '');
    pruefe('33 nicht doppelt als eigener Wert', !isset($profil[33]));
} finally {
    stoppeProjektor($p);
}

// ---------------------------------------------------------------- Web Control nicht erreichbar
// Laeuft der Test ohne curl (portables PHP ohne php.ini), prueft das genau den Fall
// "Plattform ohne curl". Mit curl scheitert die Anfrage an 127.0.0.1:80 - fuer das
// Modul derselbe Fall: Helligkeit geht nicht, alles andere muss weiterlaufen.
abschnitt('Lichtleistung ohne erreichbare Web Control (bzw. ohne curl): kein Absturz');
$p = starteProjektor(['input' => '32']);
try {
    $m = neuesModul($p['port'], [
        'Vendor' => 'EPSON', 'EnableBrightness' => true,
        'WebUser' => 'EPSONWEB', 'WebPassword' => 'x',
        'AutoBrightnessEnable' => true,
    ]);
    $sensor = IPSKernel::makeVariable(300.0);
    $m->TestSetProperty('AmbientVariableID', $sensor);
    $m->ApplyChanges();

    $absturz = '';
    try {
        $m->Poll();
        $m->Poll();
        $m->RequestAction('LightLevel', 200);
        $m->RequestAction('LightMode', 1);
    } catch (Throwable $e) {
        $absturz = get_class($e) . ': ' . $e->getMessage();
    }
    gleich('Kein Absturz bei Poll und Helligkeitsaktion', '', $absturz);
    gleich('Projektor bleibt online', true, $m->GetValue('Online'));
    gleich('Kein PJLink-Fehler vermerkt', '', $m->GetValue('LastError'));
    gleich('Quelle weiterhin gelesen', 1, $m->GetValue('Input'));

    // Nebenbefund: Epson-Voreinstellung (SDI = 34) an einem Geraet ohne 34, aber mit freiem 31
    $profil = profilVon($m);
    pruefe('Freies HDMI (31) landet nicht auf dem SDI-Platz', !isset($profil[4]), $profil[4] ?? '');
    pruefe('31 bleibt als eigener Eingang waehlbar', isset($profil[31]));
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

// ---------------------------------------------------------------- Epson-Rohwerte
abschnitt('Epson TEMP?/ERR? auswerten (Werte vom EB-L265F)');
IPSKernel::reset();
$m = new PJLinkProjector();
$m->Create();
$temp = new ReflectionMethod($m, 'ParseEpsonAirTemp');
$temp->setAccessible(true);
$fehler = new ReflectionMethod($m, 'FormatEpsonError');
$fehler->setAccessible(true);

// Abgleich mit Epson Projector Management: 0x31 -> 24,5 °C, 0x32 -> 25,0 °C
gleich('0x31 = 24,5 °C', 24.5, $temp->invoke($m, '31 2F XX 4C 4A 4A 58 62 5A 54 4B XX @ 41 36 4B 55 49 @ 02 06 '));
gleich('0x32 = 25,0 °C', 25.0, $temp->invoke($m, '32 2E XX 4C 4A 4A 58 62 5A 54 4B XX @ 41 36 4B 55 49 @ 02 06'));
gleich('Fuehler ohne Wert (XX) ergibt nichts', null, $temp->invoke($m, 'XX 2E XX 4C'));
gleich('ERR ergibt nichts', null, $temp->invoke($m, 'ERR'));
gleich('Keine Antwort ergibt nichts', null, $temp->invoke($m, null));
gleich('Leere Antwort ergibt nichts', null, $temp->invoke($m, ''));

gleich('ERR 00', '00 – kein Fehler', $fehler->invoke($m, '00'));
gleich('ERR 04', '04 – Innentemperatur zu hoch', $fehler->invoke($m, '04'));
gleich('ERR 0c klein geschrieben', '0C – Luftstrom zu gering', $fehler->invoke($m, '0c'));
gleich('Unbekannter Code bleibt sichtbar', '2A – siehe Epson-Handbuch', $fehler->invoke($m, '2A'));

// ---------------------------------------------------------------- Objektiv (Web Control)

/** Startet die Schreibtisch-Web-Control (php -S) mit einem Anfangszustand. */
function starteWebControl(array $state = [])
{
    $stateFile = tempnam(sys_get_temp_dir(), 'pjp_wc_state');
    $logFile   = tempnam(sys_get_temp_dir(), 'pjp_wc_log');
    file_put_contents($stateFile, json_encode($state));
    file_put_contents($logFile, '');

    // Freien Port vom Betriebssystem holen
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);

    $env = array_merge(getenv(), ['FAKE_WC_STATE' => $stateFile, 'FAKE_WC_LOG' => $logFile]);
    $pipes = [];
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_webcontrol.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

    $frist = microtime(true) + 10;
    while (microtime(true) < $frist) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            return ['proc' => $proc, 'pipes' => $pipes, 'port' => $port, 'state' => $stateFile, 'log' => $logFile];
        }
        usleep(50000);
    }
    proc_terminate($proc);
    throw new Exception('Schreibtisch-Web-Control meldete sich nicht.');
}

function stoppeWebControl($w)
{
    foreach ($w['pipes'] as $pipe) {
        @fclose($pipe);
    }
    proc_terminate($w['proc']);
    proc_close($w['proc']);
    @unlink($w['state']);
    @unlink($w['log']);
}

/** Alle directsend-Befehle seit Start (ohne json_query-Abfragen). */
function gesendet($w)
{
    $zeilen = array_filter(explode("\n", (string)file_get_contents($w['log'])));
    $befehle = [];
    foreach ($zeilen as $z) {
        if (strpos($z, '/cgi-bin/directsend?') === 0) {
            $befehle[] = substr($z, strlen('/cgi-bin/directsend?'));
        }
    }
    return array_values($befehle);
}

function wcZustand($w)
{
    return json_decode((string)file_get_contents($w['state']), true);
}

/** Modul mit Objektivsteuerung gegen die Schreibtisch-Web-Control, Projektor an. */
function objektivModul($w, array $props = [])
{
    IPSKernel::reset();
    $m = new PJLinkProjector();
    $m->Create();
    foreach (array_merge([
        'Host' => '127.0.0.1', 'Vendor' => 'EPSON', 'EnableLens' => true,
        'WebUser' => 'EPSONWEB', 'WebPassword' => 'x', 'WebPort' => $w['port'],
    ], $props) as $k => $v) {
        $m->TestSetProperty($k, $v);
    }
    $m->ApplyChanges();          // Projektor noch aus: nichts lesen, nichts senden
    $m->SetValue('PowerState', 1);
    return $m;
}

if (!function_exists('curl_init')) {
    abschnitt('Objektivsteuerung: uebersprungen (PHP ohne curl)');
} else {

abschnitt('Objektiv: Positionen lesen, Zoom/Fokus absolut, Shift als Differenz');
$w = starteWebControl();
try {
    $m = objektivModul($w);
    gleich('Im Standby nichts gesendet', 0, count(gesendet($w)));

    $m->RefreshLens();
    gleich('Zoom gelesen', 12, $m->GetValue('LensZoom'));
    gleich('Fokus gelesen', 153, $m->GetValue('LensFocus'));
    gleich('Shift H gelesen', 32521, $m->GetValue('LensShiftH'));
    gleich('Shift V gelesen', 34374, $m->GetValue('LensShiftV'));

    $m->RequestAction('LensZoom', 20);
    $m->RequestAction('LensFocus', 160);
    gleich('Zoom absolut gesendet', 'IMZOOM=20', gesendet($w)[0] ?? '');
    gleich('Fokus absolut gesendet', 'IMFOCUS=160', gesendet($w)[1] ?? '');

    // Richtung noch unbekannt: erst 5 Counts probieren, dann den Rest
    $m->RequestAction('LensShiftH', 32546);
    $m->RequestAction('LensShiftV', 34300);
    gleich('Shift H: Probeschritt', 'IMHLENS=INC 5', gesendet($w)[2] ?? '');
    gleich('Shift H: Rest als INC', 'IMHLENS=INC 20', gesendet($w)[3] ?? '');
    gleich('Shift V: Probeschritt', 'IMLENS=DEC 5', gesendet($w)[4] ?? '');
    gleich('Shift V: Rest als DEC', 'IMLENS=DEC 69', gesendet($w)[5] ?? '');

    $z = wcZustand($w);
    gleich('Geraet steht auf Zoom 20', 20, $z['zoom']);
    gleich('Geraet steht auf Shift H 32546', 32546, $z['h']);
    gleich('Geraet steht auf Shift V 34300', 34300, $z['v']);

    $m->RequestAction('LensShiftH', 32546);
    gleich('Shift aufs aktuelle Ziel sendet nichts', 6, count(gesendet($w)));

    $m->RequestAction('LensShiftH', 32521);
    gleich('Richtung gelernt: kein Probeschritt mehr', 'IMHLENS=DEC 25', gesendet($w)[6] ?? '');

    $m->RefreshLens();
    gleich('Nach dem Lesen: Zoom 20', 20, $m->GetValue('LensZoom'));
    gleich('Nach dem Lesen: Shift V 34300', 34300, $m->GetValue('LensShiftV'));

    $m->SetValue('PowerState', 0);
    $m->RequestAction('LensZoom', 30);
    $m->RequestAction('LensShiftH', 30000);
    gleich('Projektor aus: kein Befehl', 7, count(gesendet($w)));
    gleich('Projektor aus: Zoom-Variable unveraendert', 20, $m->GetValue('LensZoom'));
} finally {
    stoppeWebControl($w);
}

abschnitt('Objektiv: Shift-Richtung umgekehrt (wie PU2216B im SKUZ)');
$w = starteWebControl(['invert' => ['h']]);
try {
    $m = objektivModul($w);
    $m->RequestAction('LensShiftH', 32546);
    gleich('Probeschritt laeuft in die falsche Richtung', 'IMHLENS=INC 5', gesendet($w)[0] ?? '');
    gleich('Rest korrigiert als DEC 30', 'IMHLENS=DEC 30', gesendet($w)[1] ?? '');
    gleich('Geraet steht auf dem Ziel', 32546, wcZustand($w)['h']);
    gleich('Richtung gemerkt', '{"LensShiftH":-1}', $m->ReadAttributeString('LensShiftDir'));

    $m->RequestAction('LensShiftH', 32521);
    gleich('Zweite Fahrt direkt richtig', 'IMHLENS=INC 25', gesendet($w)[2] ?? '');
    gleich('Wieder auf dem Ausgangswert', 32521, wcZustand($w)['h']);

    $m->RequestAction('LensShiftV', 34376);
    gleich('Kleiner Weg (<= 5) ohne Probe', 'IMLENS=INC 2', gesendet($w)[3] ?? '');
} finally {
    stoppeWebControl($w);
}

abschnitt('Objektiv: falsch gemerkte Richtung wird im Poll erkannt und korrigiert');
$w = starteWebControl(['invert' => ['h']]);
try {
    $m = objektivModul($w);
    $m->WriteAttributeString('LensShiftDir', '{"LensShiftH":1}'); // z. B. Projektionsart umgestellt
    $m->RequestAction('LensShiftH', 32546);
    gleich('Mit alter Richtung gesendet', 'IMHLENS=INC 25', gesendet($w)[0] ?? '');
    gleich('Geraet laeuft weg', 32496, wcZustand($w)['h']);

    $m->RefreshLens();
    gleich('Im Poll korrigiert', 'IMHLENS=DEC 50', gesendet($w)[1] ?? '');
    gleich('Geraet doch auf dem Ziel', 32546, wcZustand($w)['h']);
    gleich('Richtung umgelernt', '{"LensShiftH":-1}', $m->ReadAttributeString('LensShiftDir'));
    gleich('Variable zeigt das Ziel', 32546, $m->GetValue('LensShiftH'));

    $m->RefreshLens();
    gleich('Keine zweite Korrektur', 2, count(gesendet($w)));
} finally {
    stoppeWebControl($w);
}

abschnitt('Objektiv: Ziel bleibt waehrend der Fahrt stehen, abgelehntes Ziel nicht ewig');
$w = starteWebControl(['reject' => ['IMFOCUS']]);
try {
    $m = objektivModul($w);
    $m->RefreshLens();
    $m->RequestAction('LensFocus', 999);
    gleich('Ziel sofort angezeigt', 999, $m->GetValue('LensFocus'));

    $m->RefreshLens();
    gleich('Waehrend der Frist: Ziel bleibt (kein Zwischenwert)', 999, $m->GetValue('LensFocus'));

    // Frist abgelaufen -> wieder ehrlicher Gerätewert
    $m->SetBuffer('LensPending', json_encode(['LensFocus' => ['target' => 999, 'until' => time() - 1]]));
    $m->RefreshLens();
    gleich('Nach der Frist: echter Wert vom Geraet', 153, $m->GetValue('LensFocus'));
} finally {
    stoppeWebControl($w);
}

abschnitt('Objektiv: Bereich aus der Konfiguration, ohne Bereich Eingabefeld');
$w = starteWebControl();
try {
    $m = objektivModul($w);
    $zoomProfil = 'PJP.LensZoom.' . $m->InstanceID;
    gleich('Zoom ohne Bereich: 0..0 (Eingabefeld)', '0,0,1', implode(',', IPSKernel::$profiles[$zoomProfil]['values']));
    $m->TestSetProperty('LensZoomMax', 400);
    $m->ApplyChanges();
    gleich('Zoom mit Bereich 400', '0,400,1', implode(',', IPSKernel::$profiles[$zoomProfil]['values']));
    gleich('Shift 16 Bit', '0,65535,1', implode(',', IPSKernel::$profiles['PJP.LensShift']['values']));
} finally {
    stoppeWebControl($w);
}

abschnitt('Objektivspeicher: Belegung, Namen, Laden, Speichern');
$w = starteWebControl(['lpstatus' => 0x0010, 'names' => ['5' => '53 61 61 6c'],
                       'mem' => ['5' => ['zoom' => 40, 'focus' => 170, 'h' => 32768, 'v' => 32768]]]);
try {
    $m = objektivModul($w);
    $m->RefreshLensMemory();
    $assoc = IPSKernel::$profiles['PJP.LensMemory.' . $m->InstanceID]['assoc'];
    gleich('Platz 5 belegt, Name dekodiert', '5: Saal', $assoc[5] ?? '');
    gleich('Platz 1 leer markiert', 'Speicher 1 (leer)', $assoc[1] ?? '');
    gleich('Neun Plaetze', 9, count($assoc));

    $m->RequestAction('LensMemory', 1);
    gleich('Leeren Platz nicht laden', 0, count(gesendet($w)));

    $m->RequestAction('LensMemory', 5);
    gleich('Belegten Platz laden', 'POPLP=5', gesendet($w)[0] ?? '');
    gleich('Geraet faehrt auf die gespeicherte Position', 40, wcZustand($w)['zoom']);

    $m->SaveLensMemory(2, 'Bühne');
    gleich('Speichern', 'PUSHLP=2', gesendet($w)[1] ?? '');
    gleich('Name als Hex', 'NAMELP=2 42 c3 bc 68 6e 65', gesendet($w)[2] ?? '');
    $assoc = IPSKernel::$profiles['PJP.LensMemory.' . $m->InstanceID]['assoc'];
    gleich('Platz 2 danach belegt mit Namen', '2: Bühne', $assoc[2] ?? '');

    $m->SaveLensMemory(10, '');
    gleich('Platz 10 gibt es nicht', 3, count(gesendet($w)));

    // Beschriftung überlebt ein Modul-Update (Destroy räumt Instanzprofile ab)
    unset(IPSKernel::$profiles['PJP.LensMemory.' . $m->InstanceID]);
    $m->ApplyChanges();
    $assoc = IPSKernel::$profiles['PJP.LensMemory.' . $m->InstanceID]['assoc'];
    gleich('Nach Profilverlust wieder beschriftet', '5: Saal', $assoc[5] ?? '');
} finally {
    stoppeWebControl($w);
}

}

abschnitt('Objektiv: Hilfsfunktionen');
IPSKernel::reset();
$m = new PJLinkProjector();
$m->Create();
$parse = new ReflectionMethod($m, 'ParseLensReply');
$parse->setAccessible(true);
$shift = new ReflectionMethod($m, 'LensShiftCommand');
$shift->setAccessible(true);
$enc = new ReflectionMethod($m, 'EncodeLensName');
$enc->setAccessible(true);
$dec = new ReflectionMethod($m, 'DecodeLensName');
$dec->setAccessible(true);

gleich('"153 04" -> 153', 153, $parse->invoke($m, '153 04')['pos']);
gleich('"153 04" -> Status 04', '04', $parse->invoke($m, '153 04')['status']);
gleich('ERR -> nichts', null, $parse->invoke($m, 'ERR'));
gleich('Leer -> nichts', null, $parse->invoke($m, ''));
gleich('Zwei Zahlen (LENS?-Format) -> nichts', null, $parse->invoke($m, '2813 34374'));
gleich('Shift hoch', 'INC 25', $shift->invoke($m, 100, 125));
gleich('Shift runter', 'DEC 25', $shift->invoke($m, 125, 100));
gleich('Shift schon da', null, $shift->invoke($m, 5, 5));
gleich('Shift hoch, INC senkt', 'DEC 25', $shift->invoke($m, 100, 125, -1));
gleich('Shift runter, INC senkt', 'INC 25', $shift->invoke($m, 125, 100, -1));
gleich('Richtung unbekannt wie INC hebt', 'INC 25', $shift->invoke($m, 100, 125, 0));
gleich('Name kodieren', '53 61 61 6c', $enc->invoke($m, 'Saal'));
gleich('Name dekodieren', 'Saal', $dec->invoke($m, '53 61 61 6C'));
gleich('Kein Hex bleibt, wie es ist', 'Saal 1', $dec->invoke($m, 'Saal 1'));

// ---------------------------------------------------------------- Fazit
echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
printf("%d bestanden, %d gefallen\n", $bestanden, $gefallen);
exit($gefallen === 0 ? 0 : 1);
