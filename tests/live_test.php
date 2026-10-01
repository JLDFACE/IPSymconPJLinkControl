<?php

/**
 * Live-Test gegen einen echten Projektor.
 *
 *   php tests/live_test.php --host=192.168.2.118 --vendor=PANASONIC
 *   php tests/live_test.php --host=192.168.2.118 --extras          (AVMT/FREZ/Diagnose an)
 *   php tests/live_test.php --host=192.168.2.118 --switch=31       (auf logischen Wert schalten)
 *   php tests/live_test.php --host=192.168.2.118 --avmute=on
 *   php tests/live_test.php --host=192.168.2.118 --power=on
 *
 * Ohne --switch/--power/--avmute/--freeze wird ausschliesslich gelesen.
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../PJLinkProjector/module.php';

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    } elseif (preg_match('/^--(.+)$/', $arg, $m)) {
        $opt[$m[1]] = '1';
    }
}

$host     = $opt['host']     ?? '192.168.2.118';
$vendor   = $opt['vendor']   ?? 'PANASONIC';
$password = $opt['password'] ?? '';
$extras   = isset($opt['extras']) || isset($opt['avmute']) || isset($opt['freeze']);

function titel($t)
{
    echo PHP_EOL . '=== ' . $t . ' ===' . PHP_EOL;
}

function zeile($k, $v)
{
    if (is_bool($v)) {
        $v = $v ? 'true' : 'false';
    }
    printf("  %-22s %s\n", $k, (string)$v);
}

function ja($wert)
{
    return in_array(strtolower((string)$wert), ['1', 'on', 'true', 'ein', 'ja'], true);
}

IPSKernel::reset();
IPSKernel::$echoLog = true;

$m = new PJLinkProjector();
$m->Create();
$m->TestSetProperty('Host', $host);
$m->TestSetProperty('Vendor', $vendor);
$m->TestSetProperty('Password', $password);
$m->TestSetProperty('PollSlow', 15);
$m->TestSetProperty('PollFast', 2);
if ($extras) {
    $m->TestSetProperty('EnableAVMute', true);
    $m->TestSetProperty('EnableFreeze', true);
    $m->TestSetProperty('EnableDiagnostics', true);
    $m->TestSetProperty('DiagInterval', 30);
}
// Epson Web Control (Lichtleistung): --webpassword=... [--webuser=EPSONWEB]
if (isset($opt['webpassword'])) {
    $m->TestSetProperty('EnableBrightness', true);
    $m->TestSetProperty('WebUser', $opt['webuser'] ?? 'EPSONWEB');
    $m->TestSetProperty('WebPassword', $opt['webpassword']);
    $m->TestSetProperty('AutoBrightnessEnable', false);
}

titel('Konfiguration');
zeile('Host', $host);
zeile('Vendor', $vendor);
zeile('Zusatzfunktionen', $extras ? 'AVMT + FREZ + Diagnose' : 'aus');

titel('ApplyChanges');
$m->ApplyChanges();

titel('Poll');
$m->Poll();

titel('Variablen');
foreach ($m->TestDumpVariables() as $ident => $wert) {
    if (strpos($ident, '__') === 0) {
        continue;
    }
    zeile($ident, $wert);
}

titel('Interne Merker');
foreach ($m->TestDumpVariables() as $ident => $wert) {
    if (strpos($ident, '__') !== 0) {
        continue;
    }
    zeile($ident, $wert);
}

titel('Input-Profil (was die Visu anbietet)');
$profil = 'PJP.Input.' . $m->InstanceID;
if (isset(IPSKernel::$profiles[$profil])) {
    $assoc = IPSKernel::$profiles[$profil]['assoc'];
    ksort($assoc);
    if ($assoc === []) {
        echo '  (leer)' . PHP_EOL;
    }
    foreach ($assoc as $wert => $name) {
        zeile('Wert ' . $wert, $name);
    }
} else {
    echo '  Profil fehlt!' . PHP_EOL;
}

titel('Timer');
zeile('PollTimer (ms)', $m->GetTimerInterval('PollTimer'));

// ---- Optional: schalten ----
if (isset($opt['power'])) {
    $ein = ja($opt['power']);
    titel('Power ' . ($ein ? 'EIN' : 'AUS'));
    $m->RequestAction('Power', $ein);
    foreach (['Power', 'PowerState', 'Busy', 'Online'] as $i) {
        zeile($i, $m->GetValue($i));
    }
}

if (isset($opt['avmute'])) {
    $ein = ja($opt['avmute']);
    titel('AV-Mute ' . ($ein ? 'EIN' : 'AUS'));
    $m->RequestAction('AVMute', $ein);
    zeile('AVMute (Sollwert)', $m->GetValue('AVMute'));
    sleep(1);
    $m->Poll();
    zeile('AVMute (nach Poll)', $m->GetValue('AVMute'));
}

// Lichtleistung: --lightmode=<0|1|2|5> und/oder --lightlevel=<0..250>
if (isset($opt['lightmode']) || isset($opt['lightlevel'])) {
    titel('Lichtleistung');
    zeile('vorher Modus', $m->GetValue('LightMode'));
    zeile('vorher Pegel', $m->GetValue('LightLevel'));
    if (isset($opt['lightmode'])) {
        $m->RequestAction('LightMode', (int)$opt['lightmode']);
        zeile('Modus gesetzt', (int)$opt['lightmode']);
    }
    if (isset($opt['lightlevel'])) {
        $m->RequestAction('LightLevel', (int)$opt['lightlevel']);
        zeile('Pegel gesetzt', (int)$opt['lightlevel']);
    }
    zeile('nachher Modus', $m->GetValue('LightMode'));
    zeile('nachher Pegel', $m->GetValue('LightLevel'));
}

if (isset($opt['freeze'])) {
    $ein = ja($opt['freeze']);
    titel('Standbild ' . ($ein ? 'EIN' : 'AUS'));
    $m->RequestAction('Freeze', $ein);
    zeile('Freeze (Sollwert)', $m->GetValue('Freeze'));
    sleep(1);
    $m->Poll();
    zeile('Freeze (nach Poll)', $m->GetValue('Freeze'));
}

// Kompletter Aus-/Ein-Zyklus inklusive Cool-down und Warm-up. Laesst den
// Projektor am Ende im eingeschalteten Zustand zurueck.
if (isset($opt['powercycle'])) {
    $zustaende = [0 => 'Aus', 1 => 'An', 2 => 'Cool-down', 3 => 'Warm-up'];

    foreach ([false, true] as $ziel) {
        titel('Power ' . ($ziel ? 'EIN' : 'AUS'));
        $m->RequestAction('Power', $ziel);
        zeile('Sollwert Power', $m->GetValue('Power'));

        $erwartet = $ziel ? 1 : 0;
        $start = time();
        for ($i = 1; $i <= 90; $i++) {
            sleep(3);
            $m->Poll();
            $ps = (int)$m->GetValue('PowerState');
            printf("  %3ds: PowerState=%-10s Power=%-5s Busy=%-5s Online=%s\n",
                time() - $start,
                $zustaende[$ps] ?? $ps,
                $m->GetValue('Power') ? 'true' : 'false',
                $m->GetValue('Busy') ? 'true' : 'false',
                $m->GetValue('Online') ? 'true' : 'false');
            if ($ps === $erwartet) {
                printf("  -> Ziel nach %d s erreicht.\n", time() - $start);
                break;
            }
        }
    }
}

if (isset($opt['switch'])) {
    $ziel = (int)$opt['switch'];
    titel('Quelle auf logisch ' . $ziel);
    $m->RequestAction('Input', $ziel);
    foreach (['Input', 'Power', 'PowerState', 'Busy'] as $i) {
        zeile($i, $m->GetValue($i));
    }

    $runden = (int)($opt['wait'] ?? 10);
    for ($i = 1; $i <= $runden; $i++) {
        sleep(2);
        $m->Poll();
        printf("  Poll %2d: Input=%s PowerState=%s Busy=%s CmdInput=%s\n",
            $i, $m->GetValue('Input'), $m->GetValue('PowerState'),
            $m->GetValue('Busy') ? 'true' : 'false', $m->GetValue('__CmdInput'));
        if ((int)$m->GetValue('__CmdInput') === 0) {
            echo '  -> Umschaltung abgeschlossen.' . PHP_EOL;
            break;
        }
    }
}

titel('Fertig');
