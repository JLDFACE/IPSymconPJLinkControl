<?php
declare(strict_types=1);

class PJLinkProjector extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyString('Vendor', 'SONY'); // SONY|EPSON
        $this->RegisterPropertyString('Host', '192.168.1.50');
        $this->RegisterPropertyInteger('Port', 4352);
        $this->RegisterPropertyString('Password', '');

        // Optional: Override Input Codes (0 = default je Vendor)
        $this->RegisterPropertyInteger('CodeHDMI1', 0);
        $this->RegisterPropertyInteger('CodeHDMI2', 0);
        $this->RegisterPropertyInteger('CodeHDBT',  0);

        $this->RegisterPropertyInteger('InputDelay', 10);
        $this->RegisterPropertyInteger('PollFast', 5);
        $this->RegisterPropertyInteger('PollSlow', 60);

        // Timer
        $this->RegisterTimer('PollTimer', 0, 'PJP_Poll($_IPS[\'TARGET\']);');

        // Profile
        $this->EnsureProfiles();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Sichtbar
        $this->RegisterVariableBoolean('Power', 'Projektor Power', '~Switch');
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('PowerState', 'Projektor Power Status', 'PJP.PowerState');

        // Logische Auswahl 1..3 (HDMI1/HDMI2/HDBT), intern gemappt
        $this->RegisterVariableInteger('Input', 'Projektor Quelle', 'PJP.Input.Logical');
        $this->EnableAction('Input');

        $this->RegisterVariableBoolean('Busy', 'Projektor Busy', '~Switch');

        // Intern
        $this->RegisterVariableBoolean('__CmdPower', '__CMD Power', '~Switch');
        IPS_SetHidden($this->GetIDForIdent('__CmdPower'), true);

        $this->RegisterVariableInteger('__CmdInput', '__CMD Input', '');
        IPS_SetHidden($this->GetIDForIdent('__CmdInput'), true);

        $this->RegisterVariableInteger('__PowerOnTS', '__PowerOn TS', '');
        IPS_SetHidden($this->GetIDForIdent('__PowerOnTS'), true);

        $this->RegisterVariableInteger('__LastPwr', '__Last PowerState', '');
        IPS_SetHidden($this->GetIDForIdent('__LastPwr'), true);

        // Start Polling
        $this->SetPollInterval($this->ReadPropertyInteger('PollSlow'));
    }

    // ---- Public (Form Button / Timer) ----
    public function Poll(): void
    {
        $this->WithLock('poll', function (): void {

            $host = trim($this->ReadPropertyString('Host'));
            if ($host === '') {
                $this->LogMessage('Host ist leer', KL_ERROR);
                return;
            }

            $port = $this->ReadPropertyInteger('Port');
            $pw   = $this->ReadPropertyString('Password');
            $t    = 2;

            // 1) Status: Power zuerst
            $pwrState = $this->PJLinkGetPower($host, $port, $pw, $t);
            $this->SetValue('PowerState', $pwrState);

            // Delay-Start nur bei echtem Power-On (0 -> !=0)
            $last = (int)$this->GetValue('__LastPwr');
            if ($last === 0 && $pwrState !== 0) {
                $this->SetValue('__PowerOnTS', time());
            }
            $this->SetValue('__LastPwr', $pwrState);

            // 2) Input nur wenn nicht AUS; ERR3 tolerant
            $curDeviceInput = null;
            if ($pwrState !== 0) {
                $curDeviceInput = $this->PJLinkGetInputOrNull($host, $port, $pw, $t);
            }

            // Kombinierte Power (Boolean) nur bei stabil 0/1 syncen
            if ($pwrState === 0) $this->SetValue('Power', false);
            if ($pwrState === 1) $this->SetValue('Power', true);

            // Anzeige "Input" bleibt der logische Slot 1..3, sofern wir mappen können
            if ($curDeviceInput !== null) {
                $logical = $this->UnmapInputToLogical($curDeviceInput);
                if ($logical !== 0) {
                    $this->SetValue('Input', $logical);
                }
            }

            // (1) Soll-Quelle löschen sobald erreicht (intern)
            $wantDeviceInput = (int)$this->GetValue('__CmdInput');
            if ($pwrState === 1 && $wantDeviceInput !== 0 && $curDeviceInput !== null && $wantDeviceInput === $curDeviceInput) {
                $this->SetValue('__CmdInput', 0);
            }

            // Busy berechnen
            $wantOn = (bool)$this->GetValue('__CmdPower');
            $pendingInput = ($wantOn && (int)$this->GetValue('__CmdInput') !== 0);
            $busy = ($pwrState === 2 || $pwrState === 3 || $pendingInput);
            $this->SetValue('Busy', $busy);

            // Logik anwenden
            $this->ApplyLogic($host, $port, $pw, $t, $pwrState, $curDeviceInput);
        });
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Power':
                // Sperre während Cool-down (nur forcen verhindern)
                $pwrState = (int)$this->GetValue('PowerState');
                if ($pwrState === 2) {
                    // UI auf internen Sollwert zurück
                    $this->SetValue('Power', (bool)$this->GetValue('__CmdPower'));
                    $this->LogMessage('Power-Schalten während Cool-down ignoriert.', KL_WARNING);
                    return;
                }

                $want = (bool)$Value;
                $this->SetValue('__CmdPower', $want);
                $this->SetValue('Power', $want);

                $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                $this->Poll();
                break;

            case 'Input':
                // Value ist logischer Slot (1=HDMI1, 2=HDMI2, 3=HDBT)
                $logical = (int)$Value;
                $deviceCode = $this->MapInputToDevice($logical);
                if ($deviceCode === 0) {
                    $this->LogMessage('Input Mapping ist 0 (Codes prüfen).', KL_WARNING);
                    return;
                }

                $this->SetValue('__CmdInput', $deviceCode);
                $this->SetValue('Input', $logical);

                // Quelle wählen => Power-Soll EIN
                if (!(bool)$this->GetValue('__CmdPower')) {
                    $this->SetValue('__CmdPower', true);
                    $this->SetValue('Power', true);
                }

                $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                $this->Poll();
                break;

            default:
                throw new Exception('Unknown Ident: ' . $Ident);
        }
    }

    // ---- Logik ----
    private function ApplyLogic(string $ip, int $port, string $pw, int $timeout, int $pwrState, ?int $curDeviceInput): void
    {
        $wantOn = (bool)$this->GetValue('__CmdPower');
        $wantInput = (int)$this->GetValue('__CmdInput');

        // Cool-down: nichts forcieren
        if ($pwrState === 2) { $this->SetPollInterval($this->ReadPropertyInteger('PollFast')); return; }

        // OFF gewünscht
        if (!$wantOn) {
            if ($pwrState === 0) { $this->SetPollInterval($this->ReadPropertyInteger('PollSlow')); return; }
            $this->PJLinkSetPower($ip, $port, $pw, 0, $timeout);
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // ON gewünscht
        if ($pwrState === 0) {
            $this->PJLinkSetPower($ip, $port, $pw, 1, $timeout);
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
            return;
        }

        // Warm-up: warten
        if ($pwrState === 3) { $this->SetPollInterval($this->ReadPropertyInteger('PollFast')); return; }

        // An: Input setzen (mit Delay nur nach echtem Power-On)
        if ($pwrState === 1 && $wantInput !== 0) {
            $cur = $curDeviceInput ?? 0;
            if ($cur !== 0 && $cur !== $wantInput) {
                $delay = $this->ReadPropertyInteger('InputDelay');
                $ts = (int)$this->GetValue('__PowerOnTS');
                $elapsed = ($ts > 0) ? (time() - $ts) : 9999;

                if ($elapsed < $delay) { $this->SetPollInterval($this->ReadPropertyInteger('PollFast')); return; }

                $this->PJLinkSetInput($ip, $port, $pw, $wantInput, $timeout);
                $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                return;
            }
        }

        $this->SetPollInterval($this->ReadPropertyInteger('PollSlow'));
    }

    // ---- Profiles ----
    private function EnsureProfiles(): void
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
    }

    // ---- Input Mapping (Vendor Defaults + Overrides) ----
    private function MapInputToDevice(int $logical): int
    {
        $vendor = $this->ReadPropertyString('Vendor');

        // Default Codes
        $defHDMI1 = ($vendor === 'SONY') ? 31 : 32;
        $defHDMI2 = ($vendor === 'SONY') ? 32 : 33;
        $defHDBT  = ($vendor === 'SONY') ? 36 : 56;

        // Overrides (0 => default)
        $cHDMI1 = $this->ReadPropertyInteger('CodeHDMI1') ?: $defHDMI1;
        $cHDMI2 = $this->ReadPropertyInteger('CodeHDMI2') ?: $defHDMI2;
        $cHDBT  = $this->ReadPropertyInteger('CodeHDBT')  ?: $defHDBT;

        switch ($logical) {
            case 1: return $cHDMI1;
            case 2: return $cHDMI2;
            case 3: return $cHDBT;
            default: return 0;
        }
    }

    private function UnmapInputToLogical(int $deviceCode): int
    {
        // Rückmapping anhand der effektiven Codes
        $c1 = $this->MapInputToDevice(1);
        $c2 = $this->MapInputToDevice(2);
        $c3 = $this->MapInputToDevice(3);

        if ($deviceCode === $c1) return 1;
        if ($deviceCode === $c2) return 2;
        if ($deviceCode === $c3) return 3;
        return 0; // unbekannt -> Anzeige bleibt unverändert
    }

    // ---- PJLink ----
    private function PJLinkSend(string $ip, int $port, string $password, string $cmd, int $timeout): string
    {
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new Exception("PJLink Verbindung fehlgeschlagen zu $ip:$port - $errstr ($errno)");
        }
        stream_set_timeout($fp, $timeout);

        $handshake = trim((string)fgets($fp, 512)); // PJLINK 0|1
        if (strpos($handshake, 'PJLINK ') !== 0) {
            fclose($fp);
            throw new Exception("Ungültiger PJLink Handshake: '$handshake'");
        }

        $authPrefix = '';
        if (preg_match('/^PJLINK 1 ([0-9A-Fa-f]{8,})$/', $handshake, $m)) {
            $authPrefix = md5($m[1] . $password);
        }

        fwrite($fp, $authPrefix . $cmd . "\r");
        $resp = trim((string)fgets($fp, 512));
        fclose($fp);

        if ($resp === 'PJLINK ERRA') {
            throw new Exception('PJLink Authentifizierung fehlgeschlagen (Passwort prüfen).');
        }

        return $resp;
    }

    private function PJLinkGetPower(string $ip, int $port, string $pw, int $t): int
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1POWR ?", $t);
        if (preg_match('/%1POWR=([0-3])/', $r, $m)) return (int)$m[1];
        throw new Exception("POWR? unerwartet: $r");
    }

    private function PJLinkSetPower(string $ip, int $port, string $pw, int $onOff, int $t): void
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1POWR " . $onOff, $t);
        if (strpos($r, "%1POWR=OK") === 0) return;
        throw new Exception("POWR set unerwartet: $r");
    }

    private function PJLinkGetInputOrNull(string $ip, int $port, string $pw, int $t): ?int
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INPT ?", $t);
        if (preg_match('/%1INPT=([0-9]+)/', $r, $m)) return (int)$m[1];
        if (strpos($r, "%1INPT=ERR3") === 0) return null; // Standby/unavailable
        throw new Exception("INPT? unerwartet: $r");
    }

    private function PJLinkSetInput(string $ip, int $port, string $pw, int $input, int $t): void
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INPT " . $input, $t);
        if (strpos($r, "%1INPT=OK") === 0) return;
        if (strpos($r, "%1INPT=ERR3") === 0) throw new Exception("INPT set nicht verfügbar (ERR3) – Projektor noch nicht bereit.");
        throw new Exception("INPT set unerwartet: $r");
    }

    // ---- Timer/Lock ----
    private function SetPollInterval(int $seconds): void
    {
        $this->SetTimerInterval('PollTimer', max(1, $seconds) * 1000);
    }

    private function WithLock(string $name, callable $fn): void
    {
        $key = 'PJP_' . $this->InstanceID . '_' . $name;
        if (!IPS_SemaphoreEnter($key, 3000)) {
            throw new Exception('Semaphore timeout');
        }
        try {
            $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }
}

// Timer/Form Hook
function PJP_Poll(int $InstanceID): void
{
    $inst = IPS_GetInstance($InstanceID);
    // Modulmethoden werden direkt über IPS_RequestAction nicht aufgerufen; daher via Module-Funktion:
    // IP-Symcon ruft hier die globale Funktion, wir leiten an die Instanzmethode weiter.
    // @phpstan-ignore-next-line
    IPS_RunScriptText(''); // no-op placeholder
    // Direktaufruf:
    $obj = IPS_GetObject($InstanceID);
    // Sauberer Weg: IPS_RequestAction auf Dummy ist nicht sinnvoll; wir nutzen CallModuleMethod:
    IPS_CallModuleMethod($InstanceID, 'Poll', []);
}
