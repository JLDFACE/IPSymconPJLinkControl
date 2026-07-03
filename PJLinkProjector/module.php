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

        // Epson Web Control (Helligkeit / Lichtleistung) - nur Epson-Modelle mit Web Control
        $this->RegisterPropertyBoolean('EnableBrightness', false);
        $this->RegisterPropertyString('WebUser', 'EPSONWEB');
        $this->RegisterPropertyString('WebPassword', '');
        $this->RegisterPropertyBoolean('WebHTTPS', false);

        // Automatische Anpassung an Raumhelligkeit (KNX-Lux -> Lichtleistung)
        $this->RegisterPropertyBoolean('AutoBrightnessEnable', false);
        $this->RegisterPropertyInteger('AmbientVariableID', 0);
        $this->RegisterPropertyString('AutoCurve', '[{"Lux":20,"Level":80},{"Lux":150,"Level":170},{"Lux":500,"Level":250}]');
        $this->RegisterPropertyInteger('AutoSmoothPercent', 30);
        $this->RegisterPropertyInteger('AutoDeadband', 5);
        $this->RegisterPropertyInteger('AutoMinInterval', 15);
        $this->RegisterPropertyInteger('AutoManualPauseMinutes', 30);

        // Input Codes Override (0 = Default je Vendor)
        $this->RegisterPropertyInteger('CodeHDMI1', 0);
        $this->RegisterPropertyInteger('CodeHDMI2', 0);
        $this->RegisterPropertyInteger('CodeHDBT',  0);

        $this->RegisterPropertyInteger('InputDelay', 10);

        // Polling Defaults
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('PollSlow', 15);

        // Nach Statusänderung noch X Sekunden schnell pollen
        $this->RegisterPropertyInteger('FastAfterChange', 30);

        // Fehlermeldungen drosseln (Sekunden, 0 = keine Drosselung)
        $this->RegisterPropertyInteger('ErrorLogCooldown', 60);

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

        // Diagnose
        $this->RegisterVariableString('AvailableInputs', 'Verfügbare Inputs', '');

        // Helligkeit / Lichtleistung (Epson Web Control) - nur wenn aktiviert
        if ($this->ReadPropertyBoolean('EnableBrightness')) {
            $this->RegisterVariableInteger('LightMode', 'Lichtleistung Modus', 'PJP.LightMode');
            $this->EnableAction('LightMode');

            $this->RegisterVariableInteger('LightLevel', 'Lichtleistung Pegel', 'PJP.LightLevel');
            $this->EnableAction('LightLevel');

            @IPS_SetIcon($this->GetIDForIdent('LightMode'), 'Sun');
            @IPS_SetIcon($this->GetIDForIdent('LightLevel'), 'Intensity');

            // Laufzeit-Schalter für die automatische Raumhelligkeits-Regelung
            if ($this->ReadPropertyBoolean('AutoBrightnessEnable')) {
                $abExisted = ((int)@$this->GetIDForIdent('AutoBrightness') > 0);
                $this->RegisterVariableBoolean('AutoBrightness', 'Auto-Helligkeit', '~Switch');
                $this->EnableAction('AutoBrightness');
                @IPS_SetIcon($this->GetIDForIdent('AutoBrightness'), 'Sun');
                if (!$abExisted) {
                    $this->SetValue('AutoBrightness', true); // bei Erstanlage aktiv
                }
            } else {
                $this->MaybeUnregister('AutoBrightness');
            }
        } else {
            $this->MaybeUnregister('LightMode');
            $this->MaybeUnregister('LightLevel');
            $this->MaybeUnregister('AutoBrightness');
        }

        // Interner Merker: Automatik pausiert bis (Unix-TS) nach manueller Änderung
        $this->RegisterVariableInteger('__AutoPausedUntil', '__Auto Paused Until', '');
        IPS_SetHidden($this->GetIDForIdent('__AutoPausedUntil'), true);

        // Nachrichten-/Referenz-Registrierung für den Helligkeitssensor (VM_UPDATE) neu aufsetzen.
        // Robust: eine bereits gelöschte/verwaiste Objekt-ID darf ApplyChanges niemals abbrechen.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg == VM_UPDATE) {
                    try {
                        $this->UnregisterMessage($senderID, VM_UPDATE);
                    } catch (Throwable $e) {
                        // verwaiste Registrierung (Objekt existiert nicht mehr) -> ignorieren
                    }
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            try {
                $this->UnregisterReference($refID);
            } catch (Throwable $e) {
                // verwaiste Referenz -> ignorieren
            }
        }
        $ambID = (int)$this->ReadPropertyInteger('AmbientVariableID');
        if ($this->ReadPropertyBoolean('EnableBrightness')
            && $this->ReadPropertyBoolean('AutoBrightnessEnable')
            && $ambID > 0 && @IPS_VariableExists($ambID)) {
            try {
                $this->RegisterReference($ambID);
                $this->RegisterMessage($ambID, VM_UPDATE);
            } catch (Throwable $e) {
                $this->LogMessage('Helligkeitssensor konnte nicht registriert werden: ' . $e->getMessage(), KL_WARNING);
            }
        }

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

        // Merkt den Ist-Input zum Zeitpunkt des Umschaltbefehls (für manuelle Override-Erkennung)
        $this->RegisterVariableInteger('__CmdInputPrevDevice', '__CMD Input (PrevDevice)', '');
        IPS_SetHidden($this->GetIDForIdent('__CmdInputPrevDevice'), true);

        $this->RegisterVariableInteger('__PowerOnTS', '__PowerOn TS', '~UnixTimestamp');
        IPS_SetHidden($this->GetIDForIdent('__PowerOnTS'), true);

        $this->RegisterVariableInteger('__LastPwr', '__Last PowerState', '');
        IPS_SetHidden($this->GetIDForIdent('__LastPwr'), true);

        $this->RegisterVariableInteger('__LastDeviceInput', '__Last Device Input', '');
        IPS_SetHidden($this->GetIDForIdent('__LastDeviceInput'), true);

        // Timestamp der letzten erkannten Statusänderung (für FastAfterChange)
        $this->RegisterVariableInteger('__LastChangeTS', '__Last Change TS', '~UnixTimestamp');
        IPS_SetHidden($this->GetIDForIdent('__LastChangeTS'), true);

        // Timer aktivieren
        $this->SetPollInterval($this->ReadPropertyInteger('PollSlow'));

        // Helligkeitswerte einmal initial aus dem Gerät ziehen (falls aktiviert & an)
        $this->RefreshLightNow();
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

        if ($Ident === 'LightMode') {
            $this->SetLightMode((int)$Value);
            return;
        }

        if ($Ident === 'LightLevel') {
            $this->SetLightLevel((int)$Value);
            return;
        }

        if ($Ident === 'AutoBrightness') {
            $this->SetValue('AutoBrightness', (bool)$Value);
            if ((bool)$Value) {
                // Beim Einschalten sofort regeln und evtl. laufende Pause aufheben
                $this->SetValue('__AutoPausedUntil', 0);
                $this->AutoRegulate();
            }
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

        $prevDeviceInput = (int)$this->GetValue('__LastDeviceInput');
        if ($prevDeviceInput === 0) {
            $prevLogical = (int)$this->GetValue('Input');
            $prevDeviceInput = $this->MapInputToDevice($prevLogical);
        }

        // Soll-Input speichern (Device + Logical)
        $this->SetValue('__CmdInput', $deviceCode);
        $this->SetValue('__CmdInputLogical', $logical);
        $this->SetValue('__CmdInputPrevDevice', $prevDeviceInput);

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
        $self = $this;
        $this->WithLock('poll', function () use ($self, $wantOn) {
            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') return;

                $port = (int)$self->ReadPropertyInteger('Port');
                $pw   = (string)$self->ReadPropertyString('Password');
                $timeout = 2;

                $self->PJLinkSetPower($host, $port, $pw, $wantOn ? 1 : 0, $timeout);
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('Sofort-Power-Befehl', $e);
            }
        });
    }

    private function SendInputCommandNow($deviceCode)
    {
        $self = $this;
        $this->WithLock('poll', function () use ($self, $deviceCode) {
            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') return;

                $port = (int)$self->ReadPropertyInteger('Port');
                $pw   = (string)$self->ReadPropertyString('Password');
                $timeout = 2;

                // Prüfen ob Projektor bereit ist (Power State)
                $pwrState = (int)$self->GetValue('PowerState');

                // Wenn aus: erst einschalten
                if ($pwrState === 0) {
                    $self->PJLinkSetPower($host, $port, $pw, 1, $timeout);
                    // Input wird beim nächsten Poll gesetzt (nach Warmup)
                    return;
                }

                // Wenn Warmup: Input wird automatisch beim nächsten Poll gesetzt
                if ($pwrState === 3) {
                    return;
                }

                // Wenn An: Input-Delay prüfen
                if ($pwrState === 1) {
                    $delay = (int)$self->ReadPropertyInteger('InputDelay');
                    $ts = (int)$self->GetValue('__PowerOnTS');
                    $elapsed = ($ts > 0) ? (time() - $ts) : 9999;

                    // Nur senden wenn Delay abgelaufen
                    if ($elapsed >= $delay) {
                        $self->PJLinkSetInput($host, $port, $pw, $deviceCode, $timeout);
                    }
                    // Sonst wartet ApplyLogic beim nächsten Poll
                }
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('Sofort-Input-Befehl', $e);
            }
        });
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

                // Projektor hat sich selbst ausgeschaltet -> Sollzustand zurücksetzen
                if ($pwrState === 0 && $prevPowerState === 1 && (bool)$self->GetValue('__CmdPower')) {
                    $self->SetValue('__CmdPower', false);
                    $self->SetValue('__CmdInput', 0);
                    $self->SetValue('__CmdInputLogical', 0);
                    $self->SetValue('__CmdInputPrevDevice', 0);
                    $self->SetValue('Power', false);
                    $wantDeviceInput = 0;
                    $wantLogicalInput = 0;
                }

                // Delay-Start nur bei echtem Power-On (0 -> !=0)
                $last = (int)$self->GetValue('__LastPwr');
                if ($last === 0 && $pwrState !== 0) {
                    $self->SetValue('__PowerOnTS', time());
                }

                // Manuelle Abschaltung erkennen (mit Cooldown):
                // Cooldown (2) -> Aus (0) obwohl __CmdPower noch true
                // => externe Abschaltung, Sollwert synchronisieren
                if ($last === 2 && $pwrState === 0 && (bool)$self->GetValue('__CmdPower')) {
                    $self->SetValue('__CmdPower', false);
                    $self->SetValue('Power', false);
                    $self->SetValue('__CmdInput', 0);
                    $self->SetValue('__CmdInputLogical', 0);
                    $self->SetValue('__CmdInputPrevDevice', 0);
                    $wantDeviceInput = 0;
                    $wantLogicalInput = 0;
                    $self->LogMessage('Manuelle Abschaltung am Gerät erkannt (nach Cooldown) – Sollwert auf AUS gesetzt.', KL_NOTIFY);
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
                    if ($curDeviceInput !== null) {
                        $prevDeviceInput = (int)$self->GetValue('__CmdInputPrevDevice');

                        // Manuelle Quellenwahl am Gerät: Input geändert, aber nicht auf Soll -> Soll zurücksetzen
                        if ($prevDeviceInput !== 0
                            && (int)$curDeviceInput !== (int)$wantDeviceInput
                            && (int)$curDeviceInput !== (int)$prevDeviceInput) {
                            $self->SetValue('__CmdInput', 0);
                            $self->SetValue('__CmdInputLogical', 0);
                            $self->SetValue('__CmdInputPrevDevice', 0);
                            if ($curLogical !== 0) {
                                $self->SetValue('Input', $curLogical);
                            }
                        } elseif ((int)$curDeviceInput === (int)$wantDeviceInput) {
                            // erreicht: UI auf Ist (entspricht Soll) und Soll löschen
                            if ($curLogical !== 0) {
                                $self->SetValue('Input', $curLogical);
                            } elseif ($wantLogicalInput !== 0) {
                                // Fallback: zumindest logisch
                                $self->SetValue('Input', $wantLogicalInput);
                            }
                            $self->SetValue('__CmdInput', 0);
                            $self->SetValue('__CmdInputLogical', 0);
                            $self->SetValue('__CmdInputPrevDevice', 0);
                        } else {
                            // pending: UI NICHT mit altem Ist überschreiben
                            // (Optional) Wenn UI noch 0 ist, setze auf Soll
                            if ((int)$self->GetValue('Input') === 0 && $wantLogicalInput !== 0) {
                                $self->SetValue('Input', $wantLogicalInput);
                            }
                        }
                    } else {
                        // pending ohne Ist-Input: UI nicht überschreiben
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

                // Letzten Ist-Input merken
                if ($curDeviceInput !== null) {
                    $self->SetValue('__LastDeviceInput', (int)$curDeviceInput);
                }

                // Polling-Strategie
                $self->ApplyPollingStrategy();

            } catch (Exception $e) {

                if ($self->IsTransientPJLinkError($e->getMessage()) && $self->IsLikelyPowerTransition()) {
                    $self->LogMessage('PJLink transient during power transition.', KL_DEBUG);
                    $self->SetPollInterval($self->ReadPropertyInteger('PollFast'));
                    return;
                }

                $self->SetOnlineError($e->getMessage());
                $self->SetPollInterval($self->ReadPropertyInteger('PollFast'));
            }
        });

        if ($ok === false) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
        }

        // Helligkeit/Lichtleistung getrennt aktualisieren (eigener Kanal, darf Poll nie stören)
        $this->PollLight();
        $this->AutoRegulate();
    }

    public function RefreshAvailableInputs()
    {
        $result = '';
        $self = $this;

        $ok = $this->WithLock('poll', function () use ($self, &$result) {
            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') {
                    throw new Exception('Host ist leer.');
                }

                $port = (int)$self->ReadPropertyInteger('Port');
                $pw   = (string)$self->ReadPropertyString('Password');
                $timeout = 2;

                $inputs = $self->PJLinkGetAvailableInputs($host, $port, $pw, $timeout);
                $result = implode(' ', $inputs);
                $self->SetValue('AvailableInputs', $result);
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('INST-Abfrage', $e);
            }
        });

        if ($ok === false) {
            return '';
        }

        return $result;
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

                try {
                    $this->PJLinkSetInput($ip, (int)$port, $pw, $wantInput, (int)$timeout);
                    $this->SetValue('__LastChangeTS', time());
                    $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
                    return;
                } catch (Exception $e) {
                    if ($this->IsInputParameterError($e->getMessage())) {
                    $this->SetValue('__CmdInput', 0);
                    $this->SetValue('__CmdInputLogical', 0);
                    $this->SetValue('__CmdInputPrevDevice', 0);

                    $curLogical = ($curDeviceInput === null) ? 0 : $this->UnmapInputToLogical((int)$curDeviceInput);
                    if ($curLogical !== 0) {
                        $this->SetValue('Input', $curLogical);
                        }

                        $this->LogWarningThrottled('Input-Befehl abgewiesen (ERR2) – Inputcode prüfen.');
                        return;
                    }
                    throw $e;
                }
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
            $this->SetBuffer('WarnMsg', '');
            $this->SetBuffer('WarnTs', '0');
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

    private function LogWarningThrottled($message)
    {
        $cooldown = (int)$this->ReadPropertyInteger('ErrorLogCooldown');
        if ($cooldown <= 0) {
            $this->LogMessage($message, KL_WARNING);
            return;
        }

        $lastMsg = (string)$this->GetBuffer('WarnMsg');
        $lastTs = (int)$this->GetBuffer('WarnTs');
        $now = time();

        if ($message !== $lastMsg || $lastTs === 0 || ($now - $lastTs) >= $cooldown) {
            $this->LogMessage($message, KL_WARNING);
            $this->SetBuffer('WarnMsg', $message);
            $this->SetBuffer('WarnTs', (string)$now);
        }
    }

    private function HandleImmediateCommandError($label, Exception $e)
    {
        $msg = $e->getMessage();
        if ($this->IsTransientPJLinkError($msg)) {
            $this->LogMessage($label . ' übersprungen (Projektor nicht bereit/erreichbar).', KL_DEBUG);
            return;
        }

        $this->LogWarningThrottled($label . ' fehlgeschlagen: ' . $msg);
    }

    private function IsTransientPJLinkError($message)
    {
        if (strpos($message, 'Ungültiger PJLink Handshake') === 0) return true;
        if (strpos($message, 'PJLink Verbindung fehlgeschlagen') === 0) return true;
        return false;
    }

    private function IsLikelyPowerTransition()
    {
        $pwrState = (int)$this->GetValue('PowerState');
        $wantOn = (bool)$this->GetValue('__CmdPower');
        if ($pwrState === 2 || $pwrState === 3) return true;
        if ($wantOn && $pwrState === 0) return true;
        if (!$wantOn && $pwrState === 1) return true;

        $fastAfter = (int)$this->ReadPropertyInteger('FastAfterChange');
        $lastChange = (int)$this->GetValue('__LastChangeTS');
        if ($fastAfter > 0 && $lastChange > 0 && (time() - $lastChange) < $fastAfter) return true;
        return false;
    }

    private function IsInputParameterError($message)
    {
        if (strpos($message, '(ERR2)') !== false) return true;
        if (strpos($message, '%1INPT=ERR2') !== false) return true;
        return false;
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

        // Lichtleistungs-Modus (Epson LUMINANCE): 0/1/2 = Presets, 5 = Custom
        if (!IPS_VariableProfileExists('PJP.LightMode')) {
            IPS_CreateVariableProfile('PJP.LightMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.LightMode', 'Sun');
        }
        IPS_SetVariableProfileAssociation('PJP.LightMode', 0, 'Hoch (Normal)', '', 0);
        IPS_SetVariableProfileAssociation('PJP.LightMode', 1, 'Eco', '', 0);
        IPS_SetVariableProfileAssociation('PJP.LightMode', 2, 'Mittel', '', 0);
        IPS_SetVariableProfileAssociation('PJP.LightMode', 5, 'Custom', '', 0);

        // Lichtleistungs-Pegel (Epson LUMLEVEL) 0..250 als Slider
        if (!IPS_VariableProfileExists('PJP.LightLevel')) {
            IPS_CreateVariableProfile('PJP.LightLevel', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.LightLevel', 'Intensity');
            IPS_SetVariableProfileText('PJP.LightLevel', '', '');
        }
        IPS_SetVariableProfileValues('PJP.LightLevel', 0, 250, 1);
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

    private function PJLinkGetAvailableInputs($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INST ?", $timeout);

        if (preg_match('/%1INST=([0-9A-Za-z ]*)/', $r, $m)) {
            $list = trim($m[1]);
            if ($list === '') {
                return [];
            }
            return preg_split('/\s+/', $list);
        }
        if (strpos($r, "%1INST=ERR3") === 0) {
            throw new Exception("INST nicht verfügbar (ERR3).");
        }
        throw new Exception("INST? unerwartet: $r");
    }

    private function PJLinkSetInput($ip, $port, $pw, $input, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1INPT " . (int)$input, $timeout);
        if (strpos($r, "%1INPT=OK") === 0) return;
        if (strpos($r, "%1INPT=ERR2") === 0) {
            throw new Exception("INPT set ungültig (ERR2) – Eingangscode nicht akzeptiert.");
        }
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

    // ---------- Epson Web Control: Helligkeit / Lichtleistung ----------
    // Öffentliche Methoden -> per PJP_SetLightMode($id, $mode) / PJP_SetLightLevel($id, $level) aufrufbar

    // Öffentliche Setter = "manuelle" Bedienung -> pausieren ggf. die Automatik
    public function SetLightMode($mode)
    {
        if (!$this->ReadPropertyBoolean('EnableBrightness')) {
            $this->LogMessage('Helligkeitssteuerung ist deaktiviert.', KL_WARNING);
            return;
        }
        $this->MarkManualOverride();
        $this->ApplyLightMode((int)$mode);
        $this->RefreshLightNow(); // Modus + zugehörigen Pegel sofort aus dem Gerät nachziehen
    }

    public function SetLightLevel($level)
    {
        if (!$this->ReadPropertyBoolean('EnableBrightness')) {
            $this->LogMessage('Helligkeitssteuerung ist deaktiviert.', KL_WARNING);
            return;
        }
        $this->MarkManualOverride();
        $this->ApplyLightLevel((int)$level);
        $this->RefreshLightNow();
    }

    private function ApplyLightMode($mode)
    {
        $mode = (int)$mode;
        if (!in_array($mode, [0, 1, 2, 5], true)) {
            $this->LogMessage('Ungültiger Lichtleistungs-Modus: ' . $mode, KL_WARNING);
            return;
        }
        try {
            $this->EpsonWebSet('_OSD_LUMINANCE=' . sprintf('%02d', $mode));
            $this->SetValue('LightMode', $mode);
        } catch (Exception $e) {
            $this->HandleImmediateCommandError('LUMINANCE set', $e);
        }
    }

    private function ApplyLightLevel($level)
    {
        $level = (int)$level;
        if ($level < 0)   $level = 0;
        if ($level > 250) $level = 250;
        try {
            // Der numerische Pegel wirkt nur im Custom-Modus (05) -> ggf. automatisch aktivieren
            if ((int)$this->GetValue('LightMode') !== 5) {
                $this->EpsonWebSet('_OSD_LUMINANCE=05');
                $this->SetValue('LightMode', 5);
            }
            $this->EpsonWebSet('_OSD_LUMLEVEL=' . $level);
            $this->SetValue('LightLevel', $level);
        } catch (Exception $e) {
            $this->HandleImmediateCommandError('LUMLEVEL set', $e);
        }
    }

    // ---------- Automatische Raumhelligkeits-Regelung ----------
    // Wird durch VM_UPDATE des Sensors (MessageSink) und als Fallback im Poll ausgelöst.
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE
            && (int)$SenderID === (int)$this->ReadPropertyInteger('AmbientVariableID')) {
            $this->AutoRegulate();
        }
    }

    private function MarkManualOverride()
    {
        if (!$this->ReadPropertyBoolean('AutoBrightnessEnable')) return;
        $min = (int)$this->ReadPropertyInteger('AutoManualPauseMinutes');
        if ($min > 0 && @$this->GetIDForIdent('__AutoPausedUntil')) {
            $this->SetValue('__AutoPausedUntil', time() + $min * 60);
        }
    }

    private function AutoRegulate()
    {
        if (!$this->ReadPropertyBoolean('EnableBrightness')) return;
        if (!$this->ReadPropertyBoolean('AutoBrightnessEnable')) return;

        // Laufzeit-Schalter
        if (@$this->GetIDForIdent('AutoBrightness') && !$this->GetValue('AutoBrightness')) return;

        // Pause nach manueller Änderung
        if (@$this->GetIDForIdent('__AutoPausedUntil')
            && time() < (int)$this->GetValue('__AutoPausedUntil')) return;

        // Nur regeln wenn Projektor an ist
        if ((int)$this->GetValue('PowerState') !== 1) return;

        $ambID = (int)$this->ReadPropertyInteger('AmbientVariableID');
        if ($ambID <= 0 || !@IPS_VariableExists($ambID)) return;

        $lux = (float)@GetValue($ambID);
        if ($lux < 0) $lux = 0.0;

        // Glättung (exponentieller gleitender Mittelwert)
        $alpha = (int)$this->ReadPropertyInteger('AutoSmoothPercent');
        if ($alpha < 1)   $alpha = 1;
        if ($alpha > 100) $alpha = 100;
        $a    = $alpha / 100.0;
        $prev = $this->GetBuffer('AutoLuxEMA');
        $ema  = ($prev === '') ? $lux : ($a * $lux + (1 - $a) * (float)$prev);
        $this->SetBuffer('AutoLuxEMA', (string)$ema);

        $target = $this->InterpolateCurve($ema);
        if ($target === null) return;
        $target = (int)round($target);
        if ($target < 0)   $target = 0;
        if ($target > 250) $target = 250;

        // Totband gegen aktuellen Pegel
        $cur = (int)$this->GetValue('LightLevel');
        $db  = (int)$this->ReadPropertyInteger('AutoDeadband');
        if (abs($target - $cur) < max(1, $db)) return;

        // Rate-Limit zwischen Stellbefehlen
        $now    = time();
        $lastTs = (int)$this->GetBuffer('AutoLastSetTS');
        $minIv  = (int)$this->ReadPropertyInteger('AutoMinInterval');
        if ($lastTs > 0 && ($now - $lastTs) < max(1, $minIv)) return;
        $this->SetBuffer('AutoLastSetTS', (string)$now);

        try {
            $this->ApplyLightLevel($target); // ohne MarkManualOverride -> keine Selbst-Pause
        } catch (Exception $e) {
            $this->LogMessage('Auto-Regelung fehlgeschlagen: ' . $e->getMessage(), KL_DEBUG);
        }
    }

    // Stückweise lineare Interpolation über die konfigurierten Lux/Pegel-Stützpunkte
    private function InterpolateCurve($lux)
    {
        $raw = json_decode((string)$this->ReadPropertyString('AutoCurve'), true);
        if (!is_array($raw) || count($raw) === 0) return null;

        $pts = [];
        foreach ($raw as $p) {
            if (!isset($p['Lux']) || !isset($p['Level'])) continue;
            $pts[] = ['lux' => (float)$p['Lux'], 'lvl' => (float)$p['Level']];
        }
        if (count($pts) === 0) return null;

        usort($pts, function ($x, $y) {
            return $x['lux'] <=> $y['lux'];
        });

        $n = count($pts);
        if ($lux <= $pts[0]['lux'])      return $pts[0]['lvl'];
        if ($lux >= $pts[$n - 1]['lux']) return $pts[$n - 1]['lvl'];

        for ($i = 0; $i < $n - 1; $i++) {
            $lo = $pts[$i];
            $hi = $pts[$i + 1];
            if ($lux >= $lo['lux'] && $lux <= $hi['lux']) {
                $span = $hi['lux'] - $lo['lux'];
                if ($span <= 0) return $lo['lvl'];
                $t = ($lux - $lo['lux']) / $span;
                return $lo['lvl'] + $t * ($hi['lvl'] - $lo['lvl']);
            }
        }
        return $pts[$n - 1]['lvl'];
    }

    // Liest Modus + Pegel aus der Web Control und aktualisiert die Variablen.
    // Darf den normalen PJLink-Betrieb niemals stören (eigenes try/catch, gedrosselt).
    private function PollLight()
    {
        if (!$this->ReadPropertyBoolean('EnableBrightness')) return;

        // Nur wenn Projektor an ist
        if ((int)$this->GetValue('PowerState') !== 1) return;

        // Drosselung: höchstens alle 12s abfragen (unabhängig vom Poll-Takt)
        $now  = time();
        $last = (int)$this->GetBuffer('LightPollTS');
        if ($last > 0 && ($now - $last) < 12) return;
        $this->SetBuffer('LightPollTS', (string)$now);

        $this->ReadLightInto();
    }

    // Liest Modus + Pegel SOFORT aus dem Gerät und aktualisiert die Variablen
    // (ohne Drosselung). Für unmittelbares UI-Feedback nach einem Set und initial.
    public function RefreshLightNow()
    {
        if (!$this->ReadPropertyBoolean('EnableBrightness')) return;
        if ((int)$this->GetValue('PowerState') !== 1) return;
        $this->SetBuffer('LightPollTS', (string)time()); // Drossel-Timer zurücksetzen
        $this->ReadLightInto();
    }

    private function ReadLightInto()
    {
        try {
            $mode = $this->EpsonWebGet('LUMINANCE?');
            if ($mode !== null && $mode !== 'ERR' && preg_match('/^\d+$/', $mode)) {
                $this->SetValueIfChanged('LightMode', (int)$mode);
            }
            $lvl = $this->EpsonWebGet('LUMLEVEL?');
            if ($lvl !== null && $lvl !== 'ERR' && preg_match('/^\d+$/', $lvl)) {
                $this->SetValueIfChanged('LightLevel', (int)$lvl);
            }
        } catch (Throwable $e) {
            $this->LogMessage('Light-Abfrage fehlgeschlagen: ' . $e->getMessage(), KL_DEBUG);
        }
    }

    private function SetValueIfChanged($ident, $value)
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // ---------- Epson Web Control HTTP Low-Level ----------
    private function EpsonWebGet($cmd)
    {
        $body = $this->EpsonWebRequest('/cgi-bin/json_query', 'jsoncallback=' . rawurlencode($cmd));
        $j = @json_decode($body, true);
        if (is_array($j) && isset($j['projector']['feature'])) {
            $f = $j['projector']['feature'];
            if (!empty($f['error'])) return 'ERR';
            return isset($f['reply']) ? (string)$f['reply'] : null;
        }
        return null;
    }

    private function EpsonWebSet($param)
    {
        $eq = strpos($param, '=');
        if ($eq === false) {
            $query = rawurlencode($param);
        } else {
            $query = rawurlencode(substr($param, 0, $eq)) . '=' . rawurlencode(substr($param, $eq + 1));
        }
        $this->EpsonWebRequest('/cgi-bin/directsend', $query);
        return true;
    }

    private function EpsonWebRequest($path, $query)
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') throw new Exception('Host ist leer.');

        $https  = (bool)$this->ReadPropertyBoolean('WebHTTPS');
        $base   = ($https ? 'https' : 'http') . '://' . $host;
        $url    = $base . $path . ($query !== '' ? ('?' . $query) : '');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->ReadPropertyString('WebUser') . ':' . $this->ReadPropertyString('WebPassword'));
        // CSRF-Schutz der Epson Web Control verlangt einen gültigen Referer
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: ' . $base . '/cgi-bin/webconf']);
        if ($https) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('Epson Web Control HTTP-Fehler: ' . $err);
        }
        if ($code === 401) {
            throw new Exception('Epson Web Control Auth fehlgeschlagen (WebUser/WebPassword prüfen).');
        }
        if ($code === 403) {
            throw new Exception('Epson Web Control 403 (Referer/CSRF).');
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception('Epson Web Control HTTP ' . $code);
        }
        return (string)$body;
    }

    private function MaybeUnregister($ident)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) {
            $this->UnregisterVariable($ident);
        }
    }
}
