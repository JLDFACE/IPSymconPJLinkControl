<?php

/**
 * PJLink Projector (Sony/Epson) - SymBox-kompatibel (PHP 7.x Stil)
 *
 * Fix UX:
 * - Input flippt nicht mehr hin und her:
 *   Wenn ein Input-Wechsel pending ist (__CmdInput != 0), überschreibt Poll() die UI-Variable "Input"
 *   nicht mit dem alten Ist-Wert, bis der Projektor die Soll-Quelle erreicht hat.
 */

class PJLinkProjector extends IPSModule
{
    // ---------- Lifecycle ----------
    public function Create()
    {
        parent::Create();

        // Properties (Konfig)
        $this->RegisterPropertyString('Vendor', 'SONY'); // SONY|EPSON
        $this->RegisterPropertyString('Host', '192.168.1.50');
        $this->RegisterPropertyInteger('Port', 4352);
        $this->RegisterPropertyString('Password', '');

        // Input Codes Override (0 = Default je Vendor)
        $this->RegisterPropertyInteger('CodeHDMI1', 0);
        $this->RegisterPropertyInteger('CodeHDMI2', 0);
        $this->RegisterPropertyInteger('CodeHDBT',  0);

        $this->RegisterPropertyInteger('InputDelay', 10);

        // Polling Defaults
        $this->RegisterPropertyInteger('PollFast', 5);
        $this->RegisterPropertyInteger('PollSlow', 15);

        // Nach Statusänderung noch X Sekunden schnell pollen
        $this->RegisterPropertyInteger('FastAfterChange', 30);

        // Timer ruft Poll() über Prefix-Funktion auf
        $this->RegisterTimer('PollTimer', 0, 'PJP_Poll($_IPS[\'TARGET\']);');

        // Profile anlegen
        $this->EnsureProfiles();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Sichtbare Variablen
        $this->RegisterVariableBoolean('Power', 'Projektor Power', '~Switch');
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('PowerState', 'Projektor Power Status', 'PJP.PowerState');

        $this->RegisterVariableInteger('Input', 'Projektor Quelle', 'PJP.Input.Logical');
        $this->EnableAction('Input');

        $this->RegisterVariableBoolean('Busy', 'Projektor Busy', '~Switch');

        // Online/Diagnose
        $this->RegisterVariableBoolean('Online', 'Projektor Online', '');
        $this->RegisterVariableString('LastError', 'Projektor LastError', '');
        $this->RegisterVariableInteger('LastOKTimestamp', 'Projektor Last OK', '~UnixTimestamp');
        $this->RegisterVariableInteger('ErrorCounter', 'Projektor ErrorCounter', '');

        // Icons (optional)
        @IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        @IPS_SetIcon($this->GetIDForIdent('LastError'), 'Warning');
        @IPS_SetIcon($this->GetIDForIdent('LastOKTimestamp'), 'Clock');
        @IPS_SetIcon($this->GetIDForIdent('ErrorCounter'), 'Counter');

        // Interne Merker
        $this->RegisterVariableBoolean('__CmdPower', '__CMD Power', '~Switch');
        IPS_SetHidden($this->GetIDForIdent('__CmdPower'), true);

        // __CmdInput speichert den echten PJLink Device-Code (z. B. 31, 32, 36 …)
        $this->RegisterVariableInteger('__CmdInput', '__CMD Input (DeviceCode)', '');
        IPS_SetHidden($this->GetIDForIdent('__CmdInput'), true);

        // Neu: Soll-Input als logischer Wert (1..3) für stabile UI während pending Umschaltung
        $this->RegisterVariableInteger('__CmdInputLogical', '__CMD Input (Logical)', '');
        IPS_SetHidden($this->GetIDForIdent('__CmdInputLogical'), true);

        $this->RegisterVariableInteger('__PowerOnTS', '__PowerOn TS', '');
        IPS_SetHidden($this->GetIDForIdent('__PowerOnTS'), true);

        $this->RegisterVariableInteger('__LastPwr', '__Last PowerState', '');
        IPS_SetHidden($this->GetIDForIdent('__LastPwr'), true);

        // Timestamp der letzten erkannten Statusänderung (für FastAfterChange)
        $this->RegisterVariableInteger('__LastChangeTS', '__Last Change TS', '');
        IPS_SetHidden($this->GetIDForIdent('__LastChangeTS'), true);

        // Timer aktivieren
        $this->SetPollInterval($this->ReadPropertyInteger('PollSlow'));
    }

    // ---------- Actions ----------
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Power') {
            $this->HandlePowerAction((bool)$Value);
            return;
        }

        if ($Ident === 'Input') {
            $this->HandleInputAction((int)$Value);
            return;
        }

        throw new Exception('Unknown Ident: ' . $Ident);
    }

    private function HandlePowerAction($wantOn)
    {
        // Cool-down Sperre
        $pwrState = (int)$this->GetValue('PowerState');
        if ($pwrState === 2) {
            $this->SetValue('Power', (bool)$this->GetValue('__CmdPower'));
            $this->LogMessage('Power-Schalten während Cool-down ignoriert.', KL_WARNING);
            return;
        }

        $this->SetValue('__CmdPower', (bool)$wantOn);
        $this->SetValue('Power', (bool)$wantOn);

        $this->SetValue('__LastChangeTS', time());
        $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));

        // Befehl SOFORT senden (nicht erst beim nächsten Poll warten)
        $this->SendPowerCommandNow($wantOn);

        $this->Poll();
    }

    private function HandleInputAction($logical)
    {
        $logical = (int)$logical;
        $deviceCode = $this->MapInputToDevice($logical);
        if ($deviceCode === 0) {
            $this->LogMessage('Input Mapping ist 0 (Codes prüfen).', KL_WARNING);
            return;
        }

        // Soll-Input speichern (Device + Logical)
        $this->SetValue('__CmdInput', $deviceCode);
        $this->SetValue('__CmdInputLogical', $logical);

        // UI: sofort Sollwert anzeigen (bleibt stabil bis erreicht)
        $this->SetValue('Input', $logical);

        // Input wählen => Power-Soll EIN
        if (!(bool)$this->GetValue('__CmdPower')) {
            $this->SetValue('__CmdPower', true);
            $this->SetValue('Power', true);
        }

        $this->SetValue('__LastChangeTS', time());
        $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));

        // Befehle SOFORT senden (nicht erst beim nächsten Poll warten)
        $this->SendInputCommandNow($deviceCode);

        $this->Poll();
    }

    // ---------- Sofort-Befehle (ohne Poll-Verzögerung) ----------
    private function SendPowerCommandNow($wantOn)
    {
        try {
            $host = trim($this->ReadPropertyString('Host'));
            if ($host === '') return;

            $port = (int)$this->ReadPropertyInteger('Port');
            $pw   = (string)$this->ReadPropertyString('Password');
            $timeout = 2;

            $this->PJLinkSetPower($host, $port, $pw, $wantOn ? 1 : 0, $timeout);
        } catch (Exception $e) {
            $this->LogMessage('Sofort-Power-Befehl fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    private function SendInputCommandNow($deviceCode)
    {
        try {
            $host = trim($this->ReadPropertyString('Host'));
            if ($host === '') return;

            $port = (int)$this->ReadPropertyInteger('Port');
            $pw   = (string)$this->ReadPropertyString('Password');
            $timeout = 2;

            // Prüfen ob Projektor bereit ist (Power State)
            $pwrState = (int)$this->GetValue('PowerState');

            // Wenn aus: erst einschalten
            if ($pwrState === 0) {
                $this->PJLinkSetPower($host, $port, $pw, 1, $timeout);
                // Input wird beim nächsten Poll gesetzt (nach Warmup)
                return;
            }

            // Wenn Warmup: Input wird automatisch beim nächsten Poll gesetzt
            if ($pwrState === 3) {
                return;
            }

            // Wenn An: Input-Delay prüfen
            if ($pwrState === 1) {
                $delay = (int)$this->ReadPropertyInteger('InputDelay');
                $ts = (int)$this->GetValue('__PowerOnTS');
                $elapsed = ($ts > 0) ? (time() - $ts) : 9999;

                // Nur senden wenn Delay abgelaufen
                if ($elapsed >= $delay) {
                    $this->PJLinkSetInput($host, $port, $pw, $deviceCode, $timeout);
                }
                // Sonst wartet ApplyLogic beim nächsten Poll
            }
        } catch (Exception $e) {
            $this->LogMessage('Sofort-Input-Befehl fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    // ---------- Polling / Main ----------
    public function Poll()
    {
        $self = $this;

        $ok = $this->WithLock('poll', function () use ($self) {

            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') {
                    throw new Exception('Host ist leer.');
                }

                $port = (int)$self->ReadPropertyInteger('Port');
                $pw   = (string)$self->ReadPropertyString('Password');
                $timeout = 2;

                // Vorwerte für Change-Detection
                $prevPowerState   = (int)$self->GetValue('PowerState');
                $prevInputLogical = (int)$self->GetValue('Input');

                // Sollwerte
                $wantDeviceInput  = (int)$self->GetValue('__CmdInput');
                $wantLogicalInput = (int)$self->GetValue('__CmdInputLogical');

                // 1) PowerState lesen (gilt als Online, wenn erfolgreich)
                $pwrState = $self->PJLinkGetPower($host, $port, $pw, $timeout);
                $self->SetValue('PowerState', $pwrState);

                // Online & Diagnose: Erfolg
                $self->SetOnlineOk();

                // Delay-Start nur bei echtem Power-On (0 -> !=0)
                $last = (int)$self->GetValue('__LastPwr');
                if ($last === 0 && $pwrState !== 0) {
                    $self->SetValue('__PowerOnTS', time());
                }
                $self->SetValue('__LastPwr', $pwrState);

                // 2) Input lesen (nur wenn nicht AUS), ERR3 tolerant
                $curDeviceInput = null;
                if ($pwrState !== 0) {
                    $curDeviceInput = $self->PJLinkGetInputOrNull($host, $port, $pw, $timeout);
                }

                // Power Bool nur bei stabil 0/1 synchronisieren
                // ABER: Nur wenn nicht im Übergang (Warmup/Cooldown) UND Soll == Ist
                $wantPower = (bool)$self->GetValue('__CmdPower');
                if ($pwrState === 0 && !$wantPower) {
                    $self->SetValue('Power', false);
                }
                if ($pwrState === 1 && $wantPower) {
                    $self->SetValue('Power', true);
                }

                // Ist-Input mappen
                $curLogical = 0;
                if ($curDeviceInput !== null) {
                    $curLogical = $self->UnmapInputToLogical((int)$curDeviceInput);
                }

                // === UX-FIX: UI-Input nicht "zurückflippen" solange Umschaltung pending ===
                // Wenn __CmdInput != 0:
                // - erst dann UI aktualisieren, wenn Ist == Soll (dann wird Soll gelöscht)
                // - ansonsten UI so lassen (zeigt Sollwert, bleibt stabil)
                if ($wantDeviceInput !== 0) {
                    if ($curDeviceInput !== null && (int)$curDeviceInput === (int)$wantDeviceInput) {
                        // erreicht: UI auf Ist (entspricht Soll) und Soll löschen
                        if ($curLogical !== 0) {
                            $self->SetValue('Input', $curLogical);
                        } elseif ($wantLogicalInput !== 0) {
                            // Fallback: zumindest logisch
                            $self->SetValue('Input', $wantLogicalInput);
                        }
                        $self->SetValue('__CmdInput', 0);
                        $self->SetValue('__CmdInputLogical', 0);
                    } else {
                        // pending: UI NICHT mit altem Ist überschreiben
                        // (Optional) Wenn UI noch 0 ist, setze auf Soll
                        if ((int)$self->GetValue('Input') === 0 && $wantLogicalInput !== 0) {
                            $self->SetValue('Input', $wantLogicalInput);
                        }
                    }
                } else {
                    // kein pending: UI darf den Ist-Input spiegeln
                    if ($curLogical !== 0) {
                        $self->SetValue('Input', $curLogical);
                    }
                }

                // Busy berechnen (Warmup/Cooldown/PendingInput)
                $wantOn = (bool)$self->GetValue('__CmdPower');
                $pendingInput = ($wantOn && (int)$self->GetValue('__CmdInput') !== 0);
                $busy = ($pwrState === 2 || $pwrState === 3 || $pendingInput);
                $self->SetValue('Busy', $busy);

                // Change-Detection: PowerState/Input haben sich geändert?
                $changed = false;

                if ((int)$pwrState !== (int)$prevPowerState) {
                    $changed = true;
                }

                // Input Änderung nur anhand des Ist-Inputs (wenn nicht pending) oder wenn erreicht
                $newInputLogical = (int)$self->GetValue('Input');
                if ((int)$newInputLogical !== (int)$prevInputLogical) {
                    $changed = true;
                }

                if ($changed) {
                    $self->SetValue('__LastChangeTS', time());
                }

                // Logik anwenden (schalten)
                $self->ApplyLogic($host, $port, $pw, $timeout, $pwrState, $curDeviceInput);

                // Polling-Strategie
                $self->ApplyPollingStrategy();

            } catch (Exception $e) {

                $self->SetOnlineError($e->getMessage());
                $self->SetPollInterval($self->ReadPropertyInteger('PollFast'));
            }
        });

        if ($ok === false) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
        }
    }

    private function ApplyLogic($ip, $port, $pw, $timeout, $pwrState, $curDeviceInput)
    {
        $wantOn    = (bool)$this->GetValue('__CmdPower');
        $wantInput = (int)$this->GetValue('__CmdInput'); // Device Code

        // Cool-down: nichts forcieren, schnell pollen
        if ((int)$pwrState === 2) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // OFF gewünscht
        if (!$wantOn) {
            if ((int)$pwrState === 0) {
                return;
            }
            $this->PJLinkSetPower($ip, (int)$port, $pw, 0, (int)$timeout);
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // ON gewünscht
        if ((int)$pwrState === 0) {
            $this->PJLinkSetPower($ip, (int)$port, $pw, 1, (int)$timeout);
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // Warm-up: warten
        if ((int)$pwrState === 3) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // An: Input ggf. setzen
        if ((int)$pwrState === 1 && $wantInput !== 0) {

            $cur = ($curDeviceInput === null) ? 0 : (int)$curDeviceInput;

            // Nur schalten, wenn wir einen aktuellen Input kennen und er abweicht
            if ($cur !== 0 && $cur !== $wantInput) {

                // Input-Delay nur nach echtem Power-On
                $delay = (int)$this->ReadPropertyInteger('InputDelay');
                $ts = (int)$this->GetValue('__PowerOnTS');
                $elapsed = ($ts > 0) ? (time() - $ts) : 9999;

                if ($elapsed < $delay) {
                    $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                    return;
                }

                $this->PJLinkSetInput($ip, (int)$port, $pw, $wantInput, (int)$timeout);
                $this->SetValue('__LastChangeTS', time());
                $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                return;
            }
        }
    }

    private function ApplyPollingStrategy()
    {
        $online = (bool)$this->GetValue('Online');
        $busy   = (bool)$this->GetValue('Busy');
        $pwr    = (int)$this->GetValue('PowerState');

        $fastAfter  = (int)$this->ReadPropertyInteger('FastAfterChange');
        $lastChange = (int)$this->GetValue('__LastChangeTS');

        if (!$online) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        if ($busy || $pwr === 2 || $pwr === 3) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        if ($fastAfter > 0 && $lastChange > 0 && (time() - $lastChange) < $fastAfter) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        $this->SetPollInterval($this->ReadPropertyInteger('PollSlow'));
    }

    private function SetOnlineOk()
    {
        if (!(bool)$this->GetValue('Online')) {
            $this->SetValue('Online', true);
        }

        if ((int)$this->GetValue('ErrorCounter') !== 0) {
            $this->SetValue('ErrorCounter', 0);
        }

        if ((string)$this->GetValue('LastError') !== '') {
            $this->SetValue('LastError', '');
        }

        $this->SetValue('LastOKTimestamp', time());
    }

    private function SetOnlineError($message)
    {
        $this->SetValue('Online', false);
        $this->SetValue('Busy', false);

        $cnt = (int)$this->GetValue('ErrorCounter');
        $cnt++;
        $this->SetValue('ErrorCounter', $cnt);

        $msg = (string)$message;
        if ((string)$this->GetValue('LastError') !== $msg) {
            $this->SetValue('LastError', $msg);
        }
    }

    // ---------- Profiles ----------
    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('PJP.PowerState')) {
            IPS_CreateVariableProfile('PJP.PowerState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.PowerState', 'Power');
        }
        IPS_SetVariableProfileAssociation('PJP.PowerState', 0, 'Aus', '', 0);
        IPS_SetVariableProfileAssociation('PJP.PowerState', 1, 'An', '', 0);
        IPS_SetVariableProfileAssociation('PJP.PowerState', 2, 'Cool-down', '', 0);
        IPS_SetVariableProfileAssociation('PJP.PowerState', 3, 'Warm-up', '', 0);

        if (!IPS_VariableProfileExists('PJP.Input.Logical')) {
            IPS_CreateVariableProfile('PJP.Input.Logical', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.Input.Logical', 'TV');
            IPS_SetVariableProfileAssociation('PJP.Input.Logical', 1, 'HDMI 1', '', 0);
            IPS_SetVariableProfileAssociation('PJP.Input.Logical', 2, 'HDMI 2', '', 0);
            IPS_SetVariableProfileAssociation('PJP.Input.Logical', 3, 'HDBaseT', '', 0);
        }

        // Zeitstempel als formatierte Zeit anzeigen
        if (!IPS_VariableProfileExists('PJP.UnixTimestamp')) {
            IPS_CreateVariableProfile('PJP.UnixTimestamp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.UnixTimestamp', 'Clock');
            IPS_SetVariableProfileText('PJP.UnixTimestamp', '', '');
        }
    }

    // ---------- Input Mapping ----------
    private function MapInputToDevice($logical)
    {
        $vendor = (string)$this->ReadPropertyString('Vendor');

        $defHDMI1 = ($vendor === 'SONY') ? 31 : 32;
        $defHDMI2 = ($vendor === 'SONY') ? 32 : 33;
        $defHDBT  = ($vendor === 'SONY') ? 36 : 56;

        $c1 = (int)$this->ReadPropertyInteger('CodeHDMI1');
        $c2 = (int)$this->ReadPropertyInteger('CodeHDMI2');
        $c3 = (int)$this->ReadPropertyInteger('CodeHDBT');

        if ($c1 === 0) $c1 = $defHDMI1;
        if ($c2 === 0) $c2 = $defHDMI2;
        if ($c3 === 0) $c3 = $defHDBT;

        if ((int)$logical === 1) return $c1;
        if ((int)$logical === 2) return $c2;
        if ((int)$logical === 3) return $c3;
        return 0;
    }

    private function UnmapInputToLogical($deviceCode)
    {
        $c1 = (int)$this->MapInputToDevice(1);
        $c2 = (int)$this->MapInputToDevice(2);
        $c3 = (int)$this->MapInputToDevice(3);

        if ((int)$deviceCode === $c1) return 1;
        if ((int)$deviceCode === $c2) return 2;
        if ((int)$deviceCode === $c3) return 3;
        return 0;
    }

    // ---------- PJLink ----------
    private function PJLinkSend($ip, $port, $password, $cmd, $timeoutSec)
    {
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($ip, (int)$port, $errno, $errstr, (int)$timeoutSec);
        if (!$fp) {
            throw new Exception("PJLink Verbindung fehlgeschlagen zu $ip:$port - $errstr ($errno)");
        }

        stream_set_timeout($fp, (int)$timeoutSec);

        $handshake = trim((string)fgets($fp, 512));
        if (strpos($handshake, 'PJLINK ') !== 0) {
            fclose($fp);
            throw new Exception("Ungültiger PJLink Handshake: '$handshake'");
        }

        $authPrefix = '';
        if (preg_match('/^PJLINK 1 ([0-9A-Fa-f]{8,})$/', $handshake, $m)) {
            $authPrefix = md5($m[1] . (string)$password);
        }

        fwrite($fp, $authPrefix . (string)$cmd . "\r");
        $resp = trim((string)fgets($fp, 512));
        fclose($fp);

        if ($resp === 'PJLINK ERRA') {
            throw new Exception('PJLink Authentifizierung fehlgeschlagen (Passwort prüfen).');
        }

        return $resp;
    }

    private function PJLinkGetPower($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1POWR ?", $timeout);
        if (preg_match('/%1POWR=([0-3])/', $r, $m)) {
            return (int)$m[1];
        }
        throw new Exception("POWR? unerwartet: $r");
    }

    private function PJLinkSetPower($ip, $port, $pw, $onOff, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1POWR " . (int)$onOff, $timeout);
        if (strpos($r, "%1POWR=OK") === 0) return;
        throw new Exception("POWR set unerwartet: $r");
    }

    private function PJLinkGetInputOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INPT ?", $timeout);

        if (preg_match('/%1INPT=([0-9]+)/', $r, $m)) {
            return (int)$m[1];
        }
        if (strpos($r, "%1INPT=ERR3") === 0) {
            return null;
        }
        throw new Exception("INPT? unerwartet: $r");
    }

    private function PJLinkSetInput($ip, $port, $pw, $input, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INPT " . (int)$input, $timeout);
        if (strpos($r, "%1INPT=OK") === 0) return;
        if (strpos($r, "%1INPT=ERR3") === 0) {
            throw new Exception("INPT set nicht verfügbar (ERR3) – Projektor noch nicht bereit.");
        }
        throw new Exception("INPT set unerwartet: $r");
    }

    // ---------- Timer ----------
    private function SetPollInterval($seconds)
    {
        $s = (int)$seconds;
        if ($s < 1) $s = 1;
        $this->SetTimerInterval('PollTimer', $s * 1000);
    }

    // ---------- Lock (Semaphore Fix) ----------
    private function WithLock($name, $fn)
    {
        $key = 'PJP_' . $this->InstanceID . '_' . (string)$name;

        if (!IPS_SemaphoreEnter($key, 200)) {
            $this->LogMessage('Semaphore busy (' . $name . ') – wird beim nächsten Poll ausgeführt.', KL_DEBUG);
            return false;
        }

        try {
            $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }

        return true;
    }
}
