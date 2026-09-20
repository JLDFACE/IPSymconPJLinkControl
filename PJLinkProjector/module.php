<?php

/**
 * PJLink Projector (Sony/Epson/Panasonic) - SymBox-kompatibel (PHP 7.x Stil)
 *
 * Fix UX:
 * - Input flippt nicht mehr hin und her:
 *   Wenn ein Input-Wechsel pending ist (__CmdInput != 0), überschreibt Poll() die UI-Variable "Input"
 *   nicht mit dem alten Ist-Wert, bis der Projektor die Soll-Quelle erreicht hat.
 *
 * Eingänge:
 * - Die vier klassischen Plätze HDMI 1 / HDMI 2 / HDBaseT / SDI behalten die logischen Werte 1..4,
 *   damit bestehende Visualisierungen und Skripte unverändert weiterlaufen.
 * - Jeder weitere Eingang, den das Gerät über INST meldet (z. B. COMPUTER 11, MEMORY VIEWER 41,
 *   NETWORK 51 beim Panasonic PT-VMZ72), bekommt seinen PJLink-Code als logischen Wert. Codes sind
 *   immer >= 11 und kollidieren deshalb nie mit den Plätzen 1..4.
 */

class PJLinkProjector extends IPSModule
{
    // ---------- Lifecycle ----------
    public function Create()
    {
        parent::Create();

        // Properties (Konfig)
        $this->RegisterPropertyString('Vendor', 'SONY'); // SONY|EPSON|PANASONIC
        $this->RegisterPropertyString('Host', '192.168.1.50');
        $this->RegisterPropertyInteger('Port', 4352);
        $this->RegisterPropertyString('Password', '');

        // Zusatzfunktionen des PJLink-Standards (bewusst abschaltbar: bestehende
        // Installationen sollen nach einem Update nicht ungefragt neue Variablen bekommen)
        $this->RegisterPropertyBoolean('EnableAVMute', false);     // AVMT - Bild/Ton stumm (Shutter)
        $this->RegisterPropertyBoolean('EnableFreeze', false);     // FREZ - Standbild (nur Class 2)
        $this->RegisterPropertyBoolean('EnableDiagnostics', false);// LAMP/FILT/ERST/IRES + Geräteinfo
        $this->RegisterPropertyInteger('DiagInterval', 300);       // Abstand der Diagnose-Abfragen (Sek.)

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
        $this->RegisterPropertyInteger('CodeSDI',   0);

        $this->RegisterPropertyInteger('InputDelay', 10);

        // Polling Defaults
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('PollSlow', 15);

        // Dauerausfall: Poll-Takt gestaffelt bis auf diesen Wert strecken (Sek., 0 = aus)
        // Ohne das pollt das Modul einen abgehängten Projektor tagelang im PollFast-Takt.
        $this->RegisterPropertyInteger('PollOfflineMax', 300);

        // Nach Statusänderung noch X Sekunden schnell pollen
        $this->RegisterPropertyInteger('FastAfterChange', 30);

        // Fehlermeldungen drosseln (Sekunden, 0 = keine Drosselung)
        $this->RegisterPropertyInteger('ErrorLogCooldown', 60);

        // Vom Gerät gemeldete Eingangsnamen (PJLink Class 2, INNM) als JSON,
        // Schlüssel ist der PJLink-Code des Eingangs (z. B. {"31":"HDMI1"}).
        // Attribut statt Property, damit die Namen ein Modul-Update überleben.
        // Ältere Stände haben hier nach logischem Platz (1..4) abgelegt – das
        // wandelt MigrateInputNames() beim ersten ApplyChanges um.
        $this->RegisterAttributeString('InputNames', '');

        // Eingangsliste des Geräts (PJLink INST) als JSON. Damit lassen sich
        // Hersteller-Defaults prüfen, die es am konkreten Modell nicht gibt.
        $this->RegisterAttributeString('DeviceInputs', '');

        // Welche Werte zuletzt im instanzeigenen Input-Profil standen. Ohne das
        // bliebe ein Eingang in der Auswahl stehen, den das Gerät nicht mehr meldet.
        $this->RegisterAttributeString('ProfileValues', '');

        // PJLink-Klasse des Geräts (1 oder 2), 0 = noch nicht ermittelt.
        // Class-2-Befehle (INNM, FREZ, FILT, IRES, SNUM, SVER) dürfen an einem
        // Class-1-Gerät gar nicht erst gesendet werden.
        $this->RegisterAttributeInteger('DeviceClass', 0);

        // Welche AVMT-Variante das Gerät annimmt: 31 = Bild+Ton, 11 = nur Bild,
        // 21 = nur Ton, 0 = noch unbekannt. Der PT-VMZ72 weist z. B. 11 mit ERR2 ab.
        $this->RegisterAttributeInteger('AVMuteVariant', 0);

        // Statische Geräteinfo (INF1/INF2/NAME/SNUM/SVER) als JSON – ändert sich
        // im Betrieb nicht und wird deshalb nur einmal gelesen.
        $this->RegisterAttributeString('DeviceInfo', '');

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

        // Instanzeigenes Profil: die Beschriftung kommt vom Gerät (INNM) und darf
        // andere Instanzen/Anlagen nicht mitverändern.
        $this->MigrateInputNames();
        $this->EnsureInstanceInputProfile();
        $this->RegisterVariableInteger('Input', 'Projektor Quelle', $this->InputProfileName());
        $this->EnableAction('Input');

        $this->RegisterVariableBoolean('Busy', 'Projektor Busy', '~Switch');

        // Diagnose
        $this->RegisterVariableString('AvailableInputs', 'Verfügbare Inputs', '');

        // AV-Mute / Shutter (PJLink AVMT) - Class 1, praktisch überall vorhanden
        if ($this->ReadPropertyBoolean('EnableAVMute')) {
            $this->RegisterVariableBoolean('AVMute', 'Projektor Bild/Ton stumm', '~Switch');
            $this->EnableAction('AVMute');
            @IPS_SetIcon($this->GetIDForIdent('AVMute'), 'Lightbulb');
        } else {
            $this->MaybeUnregister('AVMute');
        }

        // Standbild (PJLink FREZ) - nur Class 2
        if ($this->ReadPropertyBoolean('EnableFreeze')) {
            $this->RegisterVariableBoolean('Freeze', 'Projektor Standbild', '~Switch');
            $this->EnableAction('Freeze');
            @IPS_SetIcon($this->GetIDForIdent('Freeze'), 'Snowflake');
        } else {
            $this->MaybeUnregister('Freeze');
        }

        // Diagnose: Betriebsstunden, Fehlerstatus, Geräteinfo, Auflösung
        if ($this->ReadPropertyBoolean('EnableDiagnostics')) {
            $this->RegisterVariableInteger('LampHours', 'Projektor Betriebsstunden', 'PJP.Hours');
            $this->RegisterVariableString('LampState', 'Projektor Lichtquelle', '');
            $this->RegisterVariableInteger('FilterHours', 'Projektor Filterstunden', 'PJP.Hours');
            $this->RegisterVariableString('ErrorStatus', 'Projektor Fehlerstatus', '');
            $this->RegisterVariableBoolean('HasFault', 'Projektor Störung', '~Alert');
            $this->RegisterVariableString('InputResolution', 'Projektor Eingangsauflösung', '');
            $this->RegisterVariableString('DeviceInfo', 'Projektor Geräteinfo', '');

            @IPS_SetIcon($this->GetIDForIdent('LampHours'), 'Clock');
            @IPS_SetIcon($this->GetIDForIdent('FilterHours'), 'Clock');
            @IPS_SetIcon($this->GetIDForIdent('ErrorStatus'), 'Warning');
            @IPS_SetIcon($this->GetIDForIdent('HasFault'), 'Alert');
            @IPS_SetIcon($this->GetIDForIdent('InputResolution'), 'Display');
            @IPS_SetIcon($this->GetIDForIdent('DeviceInfo'), 'Information');
        } else {
            foreach (['LampHours', 'LampState', 'FilterHours', 'ErrorStatus',
                      'HasFault', 'InputResolution', 'DeviceInfo'] as $ident) {
                $this->MaybeUnregister($ident);
            }
        }

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

        if ($Ident === 'AVMute') {
            $this->SetAVMute((bool)$Value);
            return;
        }

        if ($Ident === 'Freeze') {
            $this->SetFreeze((bool)$Value);
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

        $altCmd   = (bool)$this->GetValue('__CmdPower');
        $altPower = (bool)$this->GetValue('Power');

        $this->SetValue('__CmdPower', (bool)$wantOn);
        $this->SetValue('Power', (bool)$wantOn);

        $this->SetValue('__LastChangeTS', time());
        $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));

        // Befehl SOFORT senden (nicht erst beim nächsten Poll warten)
        if (!$this->SendPowerCommandNow($wantOn)) {
            // Projektor nicht erreichbar: Sollwert zurücknehmen. Sonst steht in der Visu
            // (und für gekoppelte Automationen) ein Zustand, den das Gerät nie erhalten hat.
            $this->SetValue('__CmdPower', $altCmd);
            $this->SetValue('Power', $altPower);
            $this->LogWarningThrottled('Power-Befehl nicht zugestellt (Projektor nicht erreichbar) – Sollwert zurückgenommen.');
            $this->SetPollInterval($this->OfflineBackoffInterval());
            return;
        }

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

        // Zustand für den Rückzieher, falls der Befehl nicht zugestellt werden kann
        $altCmdInput     = (int)$this->GetValue('__CmdInput');
        $altCmdLogical   = (int)$this->GetValue('__CmdInputLogical');
        $altCmdPrevDev   = (int)$this->GetValue('__CmdInputPrevDevice');
        $altInput        = (int)$this->GetValue('Input');
        $altCmdPower     = (bool)$this->GetValue('__CmdPower');
        $altPower        = (bool)$this->GetValue('Power');

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
        if (!$this->SendInputCommandNow($deviceCode)) {
            $this->SetValue('__CmdInput', $altCmdInput);
            $this->SetValue('__CmdInputLogical', $altCmdLogical);
            $this->SetValue('__CmdInputPrevDevice', $altCmdPrevDev);
            $this->SetValue('Input', $altInput);
            $this->SetValue('__CmdPower', $altCmdPower);
            $this->SetValue('Power', $altPower);
            $this->LogWarningThrottled('Quellen-Befehl nicht zugestellt (Projektor nicht erreichbar) – Sollwert zurückgenommen.');
            $this->SetPollInterval($this->OfflineBackoffInterval());
            return;
        }

        $this->Poll();
    }

    // ---------- Sofort-Befehle (ohne Poll-Verzögerung) ----------
    // Rückgabe: true = Befehl ist beim Projektor gelandet (oder wird bewusst später
    // gesendet), false = Gerät nicht erreichbar. Der Aufrufer nimmt dann den Sollwert
    // zurück, damit Visu und Kopplungen keinen Zustand anzeigen, den es nie gab.
    private function SendPowerCommandNow($wantOn)
    {
        $self = $this;
        $erfolg = false;
        $gelaufen = $this->WithLock('poll', function () use ($self, $wantOn, &$erfolg) {
            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') return;

                $port = (int)$self->ReadPropertyInteger('Port');
                $pw   = (string)$self->ReadPropertyString('Password');
                $timeout = 2;

                $self->PJLinkSetPower($host, $port, $pw, $wantOn ? 1 : 0, $timeout);
                $erfolg = true;
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('Sofort-Power-Befehl', $e);
                // Protokollfehler = das Gerät hat geantwortet, der Sollwert darf stehen
                // bleiben und der Poll gleicht ab. Nur ein Verbindungsfehler heißt
                // "nicht zugestellt".
                $erfolg = !$self->IsUndeliveredPJLinkError($e->getMessage());
            }
        });

        // Semaphore belegt: der Poll übernimmt den Sollwert gleich selbst -> kein Fehlschlag
        return $erfolg || ($gelaufen === false);
    }

    private function SendInputCommandNow($deviceCode)
    {
        $self = $this;
        $erfolg = false;
        $gelaufen = $this->WithLock('poll', function () use ($self, $deviceCode, &$erfolg) {
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
                    $erfolg = true;
                    return;
                }

                // Wenn Warmup: Input wird automatisch beim nächsten Poll gesetzt
                if ($pwrState === 3) {
                    $erfolg = true;
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
                $erfolg = true;
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('Sofort-Input-Befehl', $e);
                $erfolg = !$self->IsUndeliveredPJLinkError($e->getMessage());
            }
        });

        return $erfolg || ($gelaufen === false);
    }

    // ---------- AV-Mute (Shutter) und Standbild ----------

    /**
     * Bild und Ton stummschalten (PJLink AVMT), per Skript: PJP_SetAVMute($id, true).
     *
     * Welche AVMT-Variante ein Gerät annimmt, ist modellabhängig: der Panasonic
     * PT-VMZ72 kennt 31/30 (Bild+Ton) und 21/20 (nur Ton), weist 11/10 (nur Bild)
     * aber mit ERR2 ab. Deshalb wird die erste funktionierende Variante gemerkt.
     */
    public function SetAVMute($on)
    {
        if (!$this->ReadPropertyBoolean('EnableAVMute')) {
            $this->LogMessage('AV-Mute ist in der Konfiguration nicht aktiviert.', KL_WARNING);
            return;
        }
        if (!$this->HasVariable('AVMute')) {
            return;
        }

        $on  = (bool)$on;
        $alt = (bool)$this->GetValue('AVMute');
        $this->SetValue('AVMute', $on);

        if (!$this->IsOn()) {
            // Im Standby nimmt kein Projektor AVMT an – Sollwert zurücknehmen,
            // sonst zeigt die Visu einen Zustand, den es nie gab.
            $this->SetValue('AVMute', $alt);
            return;
        }

        $self = $this;
        $erfolg = false;
        $gelaufen = $this->WithLock('poll', function () use ($self, $on, &$erfolg) {
            try {
                $self->PJLinkSetAVMute(
                    trim($self->ReadPropertyString('Host')),
                    (int)$self->ReadPropertyInteger('Port'),
                    (string)$self->ReadPropertyString('Password'),
                    $on,
                    2
                );
                $erfolg = true;
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('AVMT set', $e);
            }
        });

        if (!$erfolg && $gelaufen !== false) {
            $this->SetValue('AVMute', $alt);
        }
    }

    /**
     * Standbild einfrieren (PJLink FREZ, nur Class 2), per Skript: PJP_SetFreeze($id, true).
     * Viele Geräte antworten mit ERR3, solange am gewählten Eingang kein Signal anliegt.
     */
    public function SetFreeze($on)
    {
        if (!$this->ReadPropertyBoolean('EnableFreeze')) {
            $this->LogMessage('Standbild ist in der Konfiguration nicht aktiviert.', KL_WARNING);
            return;
        }
        if (!$this->HasVariable('Freeze')) {
            return;
        }
        // Nur ablehnen, wenn die Klasse bekannt UND zu niedrig ist. Steht sie noch
        // auf 0 (kein Kontakt gehabt), ruhig senden - das Gerät antwortet dann selbst.
        if ($this->DeviceClass() === 1) {
            $this->LogWarningThrottled('Standbild (FREZ) benötigt ein PJLink-Class-2-Gerät.');
            return;
        }

        $on  = (bool)$on;
        $alt = (bool)$this->GetValue('Freeze');
        $this->SetValue('Freeze', $on);

        if (!$this->IsOn()) {
            $this->SetValue('Freeze', $alt);
            return;
        }

        $self = $this;
        $erfolg = false;
        $gelaufen = $this->WithLock('poll', function () use ($self, $on, &$erfolg) {
            try {
                $self->PJLinkSetFreeze(
                    trim($self->ReadPropertyString('Host')),
                    (int)$self->ReadPropertyInteger('Port'),
                    (string)$self->ReadPropertyString('Password'),
                    $on,
                    2
                );
                $erfolg = true;
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('FREZ set', $e);
            }
        });

        if (!$erfolg && $gelaufen !== false) {
            $this->SetValue('Freeze', $alt);
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

                // Vor SetOnlineOk() merken: hatten wir den Projektor überhaupt schon mal?
                $hatteKontakt = ((int)$self->GetValue('LastOKTimestamp') > 0);

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

                // Delay-Start bei echtem Power-On: Eintritt in An/Warmup aus Aus (0) oder Cool-down (2)
                //
                // Beim allerersten Kontakt steht __LastPwr noch auf 0 ("Aus"), obwohl der
                // Projektor womöglich längst läuft. Ohne $hatteKontakt startete das Modul
                // direkt nach dem Anlegen der Instanz einen Input-Delay und schluckte die
                // erste Quellenumschaltung - genau beim Einrichten am auffälligsten.
                $last = (int)$self->GetValue('__LastPwr');
                if ($hatteKontakt && ($last === 0 || $last === 2) && ($pwrState === 1 || $pwrState === 3)) {
                    $self->SetValue('__PowerOnTS', time());
                }

                // Externe Einschaltung erkennen (CEC/Fernbedienung) und Sollwert übernehmen,
                // sonst würde ApplyLogic den Projektor sofort wieder ausschalten.
                //   - Warm-up (3) ist IMMER ein Einschaltvorgang – Ausschalten läuft über Cool-down (2),
                //     nie über Warm-up. Daher unabhängig vom Vorzustand als externe Einschaltung werten.
                //     (Fix: früher nur $last===0, wodurch ein CEC-Einschalten aus dem Cool-down heraus
                //      verpasst und der Projektor sofort wieder ausgeschaltet wurde.)
                //   - An (1) nur übernehmen, wenn er aus Aus/Warm-up hochkam ($last 0 oder 3);
                //     ein lingerndes "An" direkt nach eigenem Aus-Befehl darf NICHT reaktivieren.
                if (!(bool)$self->GetValue('__CmdPower')) {
                    $externalOn = ($pwrState === 3)
                        || ($pwrState === 1 && ($last === 0 || $last === 3));
                    if ($externalOn) {
                        $self->SetValue('__CmdPower', true);
                        $self->SetValue('Power', true);
                        $self->LogMessage('Externe Einschaltung erkannt (CEC/Fernbedienung) – Sollwert auf EIN gesetzt.', KL_NOTIFY);
                    }
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

                // Einmalig nach Installation/Modul-Update: Eingänge und ihre echte
                // Beschriftung vom Gerät holen. Läuft schon in der 'poll'-Sperre,
                // deshalb direkt RefreshInputMetadata() statt der öffentlichen Variante.
                if (trim((string)$self->ReadAttributeString('DeviceInputs')) === ''
                    || trim((string)$self->ReadAttributeString('InputNames')) === '') {
                    try {
                        $self->RefreshInputMetadata($host, $port, $pw, $timeout);
                    } catch (Exception $eMeta) {
                        // Reine Beschriftung - darf den Poll nicht offline melden
                        $self->LogMessage('Eingangsnamen nicht lesbar: ' . $eMeta->getMessage(), KL_DEBUG);
                    }
                }

                // Zusatzfunktionen (AVMT/FREZ/Diagnose) laufen in derselben Sperre,
                // weil PJLink immer nur eine Sitzung gleichzeitig zulässt.
                $self->PollExtended($host, $port, $pw, $timeout, $pwrState);

            } catch (Exception $e) {

                if ($self->IsTransientPJLinkError($e->getMessage()) && $self->IsLikelyTransition()) {
                    $self->LogMessage('PJLink-Antwort während eines Übergangs ausgeblieben: '
                        . $e->getMessage(), KL_DEBUG);
                    $self->SetPollInterval($self->ReadPropertyInteger('PollFast'));
                    return;
                }

                $self->SetOnlineError($e->getMessage());
                $self->SetPollInterval($self->OfflineBackoffInterval());
            }
        });

        if ($ok === false) {
            $this->SetPollInterval($this->ReadPropertyInteger('PollFast'));
        }

        // Helligkeit/Lichtleistung getrennt aktualisieren (eigener Kanal, darf Poll nie stören)
        $this->PollLight();
        $this->AutoRegulate();
    }

    /**
     * Liest die optionalen Zusatzwerte: AV-Mute, Standbild und die Diagnosewerte.
     *
     * Läuft innerhalb der Poll-Sperre. Keiner dieser Werte darf den Projektor
     * offline melden oder den Poll abbrechen - es sind Komfort- und Diagnosedaten,
     * die je nach Modell und Betriebszustand schlicht fehlen dürfen.
     */
    private function PollExtended($host, $port, $pw, $timeout, $pwrState)
    {
        $an = ((int)$pwrState === 1);

        // --- AV-Mute (Class 1) ---
        if ($this->ReadPropertyBoolean('EnableAVMute') && $this->HasVariable('AVMute')) {
            try {
                if ($an) {
                    $mute = $this->PJLinkGetAVMuteOrNull($host, $port, $pw, $timeout);
                    if ($mute !== null) {
                        $this->SetValueIfChanged('AVMute', $mute);
                    }
                } elseif ((int)$pwrState === 0) {
                    // Im Standby gibt es keine Stummschaltung
                    $this->SetValueIfChanged('AVMute', false);
                }
            } catch (Exception $e) {
                $this->LogMessage('AVMT nicht lesbar: ' . $e->getMessage(), KL_DEBUG);
            }
        }

        // --- Standbild (Class 2) ---
        if ($this->ReadPropertyBoolean('EnableFreeze') && $this->HasVariable('Freeze')) {
            try {
                if ($an && $this->EnsureDeviceClass($host, $port, $pw, $timeout) >= 2) {
                    $frez = $this->PJLinkGetFreezeOrNull($host, $port, $pw, $timeout);
                    if ($frez !== null) {
                        $this->SetValueIfChanged('Freeze', $frez);
                    }
                } elseif ((int)$pwrState === 0) {
                    $this->SetValueIfChanged('Freeze', false);
                }
            } catch (Exception $e) {
                $this->LogMessage('FREZ nicht lesbar: ' . $e->getMessage(), KL_DEBUG);
            }
        }

        // --- Diagnose (gedrosselt) ---
        if (!$this->ReadPropertyBoolean('EnableDiagnostics') || !$this->HasVariable('ErrorStatus')) {
            return;
        }

        $takt = max(30, (int)$this->ReadPropertyInteger('DiagInterval'));
        $last = (int)$this->GetBuffer('DiagTS');
        if ($last > 0 && (time() - $last) < $takt) {
            return;
        }
        $this->SetBuffer('DiagTS', (string)time());

        try {
            $this->ReadDiagnosticsInto($host, $port, $pw, $timeout, $an);
        } catch (Exception $e) {
            $this->LogMessage('Diagnose-Abfrage fehlgeschlagen: ' . $e->getMessage(), KL_DEBUG);
        }
    }

    private function ReadDiagnosticsInto($host, $port, $pw, $timeout, $an)
    {
        $klasse = $this->EnsureDeviceClass($host, $port, $pw, $timeout);

        // Fehlerstatus - auch im Standby aussagekräftig
        $erst = $this->PJLinkGetErrorStatusOrNull($host, $port, $pw, $timeout);
        if ($erst !== null) {
            $this->SetValueIfChanged('ErrorStatus', $this->FormatErrorStatus($erst));
            $this->SetValueIfChanged('HasFault', (strpos($erst, '1') !== false || strpos($erst, '2') !== false));
        }

        // Betriebsstunden der Lichtquelle
        $lampen = $this->PJLinkGetLampsOrNull($host, $port, $pw, $timeout);
        if ($lampen !== null) {
            $stunden = [];
            $zustand = [];
            foreach ($lampen as $i => $lampe) {
                $stunden[] = (int)$lampe[0];
                $zustand[] = (count($lampen) > 1 ? ('Nr. ' . ($i + 1) . ': ') : '')
                    . ($lampe[1] ? 'an' : 'aus') . ' / ' . (int)$lampe[0] . ' h';
            }
            $this->SetValueIfChanged('LampHours', max($stunden));
            $this->SetValueIfChanged('LampState', implode(', ', $zustand));
        }

        if ($klasse >= 2) {
            $filt = $this->PJLinkGetFilterHoursOrNull($host, $port, $pw, $timeout);
            if ($filt !== null) {
                $this->SetValueIfChanged('FilterHours', $filt);
            }

            if ($an) {
                $ires = $this->PJLinkGetInputResolutionOrNull($host, $port, $pw, $timeout);
                if ($ires !== null) {
                    $this->SetValueIfChanged('InputResolution', $ires);
                }
            }
        }

        // Statische Geräteinfo nur einmal holen
        if (trim((string)$this->ReadAttributeString('DeviceInfo')) === '') {
            $this->RefreshDeviceInfo($host, $port, $pw, $timeout);
        } else {
            $this->SetValueIfChanged('DeviceInfo', $this->FormatDeviceInfo(
                json_decode((string)$this->ReadAttributeString('DeviceInfo'), true)
            ));
        }
    }

    /**
     * Liest Hersteller, Modell, Gerätename, Seriennummer und Firmware einmalig
     * aus und legt sie im Attribut ab. SNUM/SVER gibt es erst ab Class 2.
     */
    private function RefreshDeviceInfo($host, $port, $pw, $timeout)
    {
        $klasse = $this->EnsureDeviceClass($host, $port, $pw, $timeout);

        $info = [
            'class'        => $klasse,
            'manufacturer' => $this->PJLinkGetTextOrNull($host, $port, $pw, '%1INF1', $timeout),
            'model'        => $this->PJLinkGetTextOrNull($host, $port, $pw, '%1INF2', $timeout),
            'name'         => $this->PJLinkGetTextOrNull($host, $port, $pw, '%1NAME', $timeout),
            'other'        => $this->PJLinkGetTextOrNull($host, $port, $pw, '%1INFO', $timeout),
            'serial'       => ($klasse >= 2) ? $this->PJLinkGetTextOrNull($host, $port, $pw, '%2SNUM', $timeout) : null,
            'firmware'     => ($klasse >= 2) ? $this->PJLinkGetTextOrNull($host, $port, $pw, '%2SVER', $timeout) : null,
        ];

        $this->WriteAttributeString('DeviceInfo', json_encode($info));

        if ($this->HasVariable('DeviceInfo')) {
            $this->SetValueIfChanged('DeviceInfo', $this->FormatDeviceInfo($info));
        }

        return $info;
    }

    private function FormatDeviceInfo($info)
    {
        if (!is_array($info)) {
            return '';
        }

        $kopf = trim((string)($info['manufacturer'] ?? '') . ' ' . (string)($info['model'] ?? ''));
        if ($kopf === '') {
            $kopf = 'Unbekanntes Gerät';
        }

        $teile = [$kopf];
        if (!empty($info['name']))     $teile[] = 'Name: ' . $info['name'];
        if (!empty($info['serial']))   $teile[] = 'S/N: ' . $info['serial'];
        if (!empty($info['firmware'])) $teile[] = 'FW: ' . $info['firmware'];
        if (!empty($info['other']))    $teile[] = (string)$info['other'];
        if (!empty($info['class']))    $teile[] = 'PJLink Class ' . (int)$info['class'];

        return implode(' | ', $teile);
    }

    /** Macht aus den sechs ERST-Stellen einen lesbaren Satz. */
    private function FormatErrorStatus($erst)
    {
        $felder = ['Lüfter', 'Lampe', 'Temperatur', 'Abdeckung', 'Filter', 'Sonstiges'];
        $stufen = [1 => 'Warnung', 2 => 'Fehler'];

        $meldungen = [];
        for ($i = 0; $i < 6 && $i < strlen($erst); $i++) {
            $stufe = (int)$erst[$i];
            if ($stufe > 0) {
                $meldungen[] = $felder[$i] . ': ' . ($stufen[$stufe] ?? ('Code ' . $stufe));
            }
        }

        return ($meldungen === []) ? 'OK' : implode(', ', $meldungen);
    }

    /** PJLink-Klasse einmal ermitteln und merken (CLSS ändert sich nie). */
    private function EnsureDeviceClass($host, $port, $pw, $timeout)
    {
        $klasse = $this->DeviceClass();
        if ($klasse > 0) {
            return $klasse;
        }

        $klasse = $this->PJLinkGetClass($host, $port, $pw, $timeout);
        $this->WriteAttributeInteger('DeviceClass', $klasse);

        return $klasse;
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

                $result = $self->RefreshInputMetadata($host, $port, $pw, $timeout);
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('INST-Abfrage', $e);
            }
        });

        if ($ok === false) {
            return '';
        }

        return $result;
    }

    /**
     * Liest Hersteller, Modell, Seriennummer und Firmware neu vom Gerät und
     * liefert sie als lesbare Zeile zurück (PJP_ReadDeviceInfo($id)).
     */
    public function ReadDeviceInfo()
    {
        $text = '';
        $self = $this;

        $ok = $this->WithLock('poll', function () use ($self, &$text) {
            try {
                $host = trim($self->ReadPropertyString('Host'));
                if ($host === '') {
                    throw new Exception('Host ist leer.');
                }

                $self->WriteAttributeString('DeviceInfo', '');
                $info = $self->RefreshDeviceInfo(
                    $host,
                    (int)$self->ReadPropertyInteger('Port'),
                    (string)$self->ReadPropertyString('Password'),
                    2
                );
                $text = $self->FormatDeviceInfo($info);
            } catch (Exception $e) {
                $self->HandleImmediateCommandError('Geräteinfo-Abfrage', $e);
                $text = 'Geräteinfo nicht lesbar: ' . $e->getMessage();
            }
        });

        if ($ok === false) {
            return 'Gerät gerade belegt – bitte erneut versuchen.';
        }

        return $text;
    }

    /**
     * Liest die verfügbaren Eingänge (INST) und - bei Class-2-Geräten - deren
     * echte Beschriftung (INNM) und schreibt sie ins instanzeigene Profil.
     * Ohne Sperre, muss also innerhalb von WithLock('poll') aufgerufen werden.
     */
    private function RefreshInputMetadata($host, $port, $pw, $timeout)
    {
        $inputs = array_map('intval', $this->PJLinkGetAvailableInputs($host, $port, $pw, $timeout));
        $list = implode(' ', $inputs);
        $this->SetValue('AvailableInputs', $list);

        // Eingangsliste merken, bevor die Codes aufgelöst werden - erst damit
        // kann ResolveInputCodes() einen Default verwerfen, den es nicht gibt.
        $before = $this->ResolveInputCodes();
        $this->WriteAttributeString('DeviceInputs', json_encode($inputs));
        $after = $this->ResolveInputCodes();

        foreach ($after as $logical => $code) {
            if ((int)$code !== (int)$before[$logical]) {
                $this->LogMessage(
                    'Eingang ' . $logical . ': Code ' . $before[$logical] . ' kennt das Gerät nicht, '
                    . 'verwende ' . $code . ' (gemeldet: ' . $list . ').',
                    KL_MESSAGE
                );
            }
        }

        if ($this->EnsureDeviceClass($host, $port, $pw, $timeout) < 2) {
            // Class 1 kennt INNM nicht. Leeres Ergebnis festschreiben, sonst
            // fragt der Poll bei jedem Durchlauf erneut nach.
            $this->WriteAttributeString('InputNames', '{}');
            $this->EnsureInstanceInputProfile();
            return $list;
        }

        // PJLink erlaubt nur eine Sitzung: kollidiert die Abfrage mit dem
        // regulären Poll, kommt ein leerer Handshake zurück und PJLinkSend
        // wirft. Das Attribut bleibt dann ungeschrieben und der nächste Poll
        // wiederholt - besser als eine halb beschriftete Belegung einzubrennen.
        //
        // Gefragt wird nach JEDEM gemeldeten Eingang, nicht nur nach den vier
        // klassischen Plätzen - sonst blieben COMPUTER, MEMORY VIEWER & Co. namenlos.
        $names = [];
        foreach ($inputs as $code) {
            $code = (int)$code;
            if ($code <= 0) {
                continue;
            }

            $name = $this->PJLinkGetInputNameOrNull($host, $port, $pw, $code, $timeout);
            if ($name !== null) {
                $names[(string)$code] = $name;
            }
        }

        $this->WriteAttributeString('InputNames', json_encode($names));
        $this->EnsureInstanceInputProfile();

        return $list;
    }

    /**
     * Ältere Modulstände haben die Eingangsnamen nach logischem Platz (1..4)
     * abgelegt. Seit der Unterstützung beliebiger Geräteeingänge ist der
     * PJLink-Code der Schlüssel - das hier zieht den alten Stand einmalig nach.
     */
    private function MigrateInputNames()
    {
        $roh = trim((string)$this->ReadAttributeString('InputNames'));
        if ($roh === '' || $roh === '{}') {
            return;
        }

        $alt = json_decode($roh, true);
        if (!is_array($alt) || $alt === []) {
            return;
        }

        // Codes sind immer >= 11; ist kein Schlüssel kleiner, liegt schon das neue Format vor.
        $legacy = false;
        foreach (array_keys($alt) as $key) {
            if ((int)$key > 0 && (int)$key <= 10) {
                $legacy = true;
                break;
            }
        }
        if (!$legacy) {
            return;
        }

        $codes = $this->ResolveInputCodes();
        $neu = [];
        foreach ($alt as $key => $name) {
            $key = (int)$key;
            $code = ($key <= 10 && isset($codes[$key])) ? (int)$codes[$key] : $key;
            if ($code > 0 && trim((string)$name) !== '') {
                $neu[(string)$code] = trim((string)$name);
            }
        }

        $this->WriteAttributeString('InputNames', json_encode($neu));
        $this->LogMessage('Eingangsnamen auf das neue Format (PJLink-Code als Schlüssel) umgestellt.', KL_DEBUG);
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
            // Diagnose: macht ein Modul-seitiges Ausschalten im Meldungslog eindeutig sichtbar
            // (unterscheidbar von einem Aus über eine externe Automation/Kopplung).
            $this->LogMessage('ApplyLogic: Soll=Aus – sende POWR 0 (PowerState=' . (int)$pwrState . ').', KL_NOTIFY);
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
            $this->SetPollInterval($this->OfflineBackoffInterval());
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

    // Bei dauerhaftem Ausfall den Poll-Takt strecken: eine abgezogene Netzwerkleitung
    // wird durch Pollen im 2-Sekunden-Takt nicht besser, füllt aber Meldungslog und
    // ErrorCounter. Kurze Störungen sollen trotzdem schnell überbrückt werden.
    private function OfflineBackoffInterval()
    {
        $fast = max(1, (int)$this->ReadPropertyInteger('PollFast'));
        $max  = (int)$this->ReadPropertyInteger('PollOfflineMax');
        if ($max <= $fast) {
            return $fast; // Backoff abgeschaltet oder sinnlos konfiguriert
        }

        $seit = (int)$this->GetBuffer('OfflineSince');
        if ($seit <= 0) {
            $seit = (int)$this->GetValue('LastOKTimestamp');
        }
        if ($seit <= 0) {
            return $fast; // noch nie Kontakt gehabt -> normal weiterprobieren
        }

        $weg = time() - $seit;
        if ($weg < 60) {
            return $fast;              // kurze Störung / Neustart des Projektors
        }
        if ($weg < 300) {
            return min($max, 15);
        }
        if ($weg < 1800) {
            return min($max, 60);
        }
        return $max;                   // dauerhaft weg -> nur noch selten anklopfen
    }

    private function SetOnlineOk()
    {
        if (!(bool)$this->GetValue('Online')) {
            // Buffer ist nach einem Modul-Neuladen leer -> auf den letzten Kontakt zurückfallen,
            // sonst fehlt genau bei langen Ausfällen die Dauer in der Meldung.
            $seit = (int)$this->GetBuffer('OfflineSince');
            if ($seit <= 0) {
                $seit = (int)$this->GetValue('LastOKTimestamp');
            }
            $dauer = ($seit > 0) ? ' (war ' . $this->FormatDauer(time() - $seit) . ' weg)' : '';
            $this->LogMessage('Projektor wieder erreichbar' . $dauer . '.', KL_NOTIFY);

            $this->SetValue('Online', true);
            $this->SetBuffer('WarnMsg', '');
            $this->SetBuffer('WarnTs', '0');
            $this->SetBuffer('OfflineSince', '0');
            $this->SetBuffer('ErrCnt', '0');
            $this->SetBuffer('ErrCntTs', '0');
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
        $msg = (string)$message;
        $warOnline = (bool)$this->GetValue('Online');

        if ($warOnline) {
            // Genau ein Eintrag beim Wegbrechen – vorher stand im Meldungslog gar nichts,
            // dadurch fiel ein tagelanger Ausfall niemandem auf.
            $this->LogMessage('Projektor nicht mehr erreichbar: ' . $msg, KL_WARNING);
            $this->SetValue('Online', false);
            $this->SetBuffer('OfflineSince', (string)time());
            $this->SetBuffer('ErrCnt', (string)(int)$this->GetValue('ErrorCounter'));
            $this->SetBuffer('ErrCntTs', '0');
        }

        if ((bool)$this->GetValue('Busy')) {
            $this->SetValue('Busy', false);
        }

        // Zähler im Buffer mitführen und nur gedrosselt in die Variable schreiben.
        // Jeder Schreibvorgang landet sonst als eigene Zeile im Meldungslog.
        // Buffer leer = Modul wurde neu geladen -> beim Variablenwert weiterzählen.
        $roh = $this->GetBuffer('ErrCnt');
        $cnt = (($roh === '') ? (int)$this->GetValue('ErrorCounter') : (int)$roh) + 1;
        $this->SetBuffer('ErrCnt', (string)$cnt);

        $takt = max(60, (int)$this->ReadPropertyInteger('ErrorLogCooldown'));
        $letzte = (int)$this->GetBuffer('ErrCntTs');
        if ($letzte === 0 || (time() - $letzte) >= $takt) {
            $this->SetValue('ErrorCounter', $cnt);
            $this->SetBuffer('ErrCntTs', (string)time());
        }

        if ((string)$this->GetValue('LastError') !== $msg) {
            $this->SetValue('LastError', $msg);
        }
    }

    private function FormatDauer($sekunden)
    {
        $s = max(0, (int)$sekunden);
        if ($s < 60) {
            return $s . ' s';
        }
        if ($s < 3600) {
            return floor($s / 60) . ' min';
        }
        if ($s < 86400) {
            return floor($s / 3600) . ' h';
        }
        return floor($s / 86400) . ' Tage';
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
            // Vorübergehend – der nächste Poll gleicht ab. Die Ursache trotzdem
            // mitschreiben, sonst steht im Debug nur "übersprungen".
            $this->LogMessage($label . ' übersprungen: ' . $msg, KL_DEBUG);
            return;
        }

        $this->LogWarningThrottled($label . ' fehlgeschlagen: ' . $msg);
    }

    /**
     * Vorübergehende Störung: der Projektor ist da, konnte gerade nur nicht antworten.
     * Steuert Log-Stufe und die Entscheidung, ob ein Poll auf "offline" geht.
     */
    private function IsTransientPJLinkError($message)
    {
        if (strpos($message, 'Ungültiger PJLink Handshake') === 0) return true;
        if (strpos($message, 'PJLink Verbindung fehlgeschlagen') === 0) return true;
        if (strpos($message, 'PJLink Antwort ausgeblieben') === 0) return true;
        if (strpos($message, 'PJLink gerade nicht auskunftsfähig') === 0) return true;
        return false;
    }

    /**
     * Befehl sicher NICHT angekommen - nur dann darf ein Sofort-Befehl seinen
     * Sollwert zurücknehmen. Eine ausgebliebene Antwort gehört ausdrücklich nicht
     * dazu: der Befehl kann durchaus gesessen haben, der Poll gleicht das ab.
     */
    private function IsUndeliveredPJLinkError($message)
    {
        if (strpos($message, 'Ungültiger PJLink Handshake') === 0) return true;
        if (strpos($message, 'PJLink Verbindung fehlgeschlagen') === 0) return true;
        return false;
    }

    // Läuft gerade ein Übergang (Power ODER Quellenwechsel)? Während eines
    // Übergangs sind ausgebliebene Antworten normal und dürfen nicht als
    // Ausfall im Meldungslog landen.
    private function IsLikelyTransition()
    {
        $pwrState = (int)$this->GetValue('PowerState');
        $wantOn = (bool)$this->GetValue('__CmdPower');
        if ($pwrState === 2 || $pwrState === 3) return true;
        if ($wantOn && $pwrState === 0) return true;
        if (!$wantOn && $pwrState === 1) return true;

        // Quellenwechsel läuft noch
        if ((int)$this->GetValue('__CmdInput') !== 0) return true;

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

    // ---------- Kleine Helfer ----------

    // Projektor voll eingeschaltet (PowerState 1)? Im Gegensatz zu IsPoweredOn()
    // ohne Meldung im Log - für Abfragen, die im Standby einfach entfallen.
    private function IsOn()
    {
        return (int)$this->GetValue('PowerState') === 1;
    }

    // Existiert die Variable? Optionale Variablen sind je nach Konfiguration nicht angelegt.
    private function HasVariable($ident)
    {
        return ((int)@$this->GetIDForIdent($ident) > 0);
    }

    // Zuletzt ermittelte PJLink-Klasse (0 = noch unbekannt)
    private function DeviceClass()
    {
        return (int)$this->ReadAttributeInteger('DeviceClass');
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
            IPS_SetVariableProfileAssociation('PJP.Input.Logical', 4, 'SDI', '', 0);
        }

        // Betriebsstunden (LAMP/FILT)
        if (!IPS_VariableProfileExists('PJP.Hours')) {
            IPS_CreateVariableProfile('PJP.Hours', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('PJP.Hours', 'Clock');
            IPS_SetVariableProfileText('PJP.Hours', '', ' h');
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

    // Profilname je Instanz - die Beschriftung stammt vom konkreten Gerät.
    private function InputProfileName()
    {
        return 'PJP.Input.' . $this->InstanceID;
    }

    // Fallback-Beschriftung, solange das Gerät nichts Eigenes gemeldet hat
    private function DefaultInputNames()
    {
        return [1 => 'HDMI 1', 2 => 'HDMI 2', 3 => 'HDBaseT', 4 => 'SDI'];
    }

    /**
     * Legt das instanzeigene Input-Profil an bzw. schreibt die Beschriftung neu.
     * Muss aus ApplyChanges laufen: Destroy() räumt Instanzprofile beim
     * Modul-Update ab, die Namen kommen dann aus dem Attribut zurück.
     */
    private function EnsureInstanceInputProfile()
    {
        $profile = $this->InputProfileName();

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($profile, 'TV');
        }

        $auswahl = $this->AllInputChoices();

        // Was beim letzten Mal drinstand und jetzt wegfällt, muss raus - sonst
        // bliebe ein Eingang wählbar, den das Gerät gar nicht mehr meldet.
        $vorher = json_decode((string)$this->ReadAttributeString('ProfileValues'), true);
        if (is_array($vorher)) {
            foreach ($vorher as $wert) {
                if (!isset($auswahl[(int)$wert])) {
                    IPS_SetVariableProfileAssociation($profile, (int)$wert, '', '', 0);
                }
            }
        }

        foreach ($auswahl as $logical => $eintrag) {
            IPS_SetVariableProfileAssociation($profile, (int)$logical, $eintrag['name'], '', 0);
        }

        $this->WriteAttributeString('ProfileValues', json_encode(array_keys($auswahl)));
    }

    // ---------- Input Mapping ----------

    /**
     * Alle wählbaren Eingänge als logischer Wert => ['code' => PJLink-Code, 'name' => Beschriftung].
     *
     * Enthält die vier klassischen Plätze (logisch 1..4, für Rückwärtskompatibilität)
     * und zusätzlich jeden weiteren Eingang, den das Gerät über INST gemeldet hat.
     * Diese zusätzlichen Eingänge tragen ihren PJLink-Code als logischen Wert; Codes
     * beginnen bei 11 und kollidieren deshalb nie mit den Plätzen 1..4.
     */
    private function AllInputChoices()
    {
        $codes     = $this->ResolveInputCodes();
        $available = $this->KnownDeviceInputs();
        $namen     = $this->CachedInputNames();
        $defaults  = $this->DefaultInputNames();

        $auswahl = [];
        $belegt  = [];

        // 1..4: die klassischen Plätze
        foreach ($defaults as $logical => $fallback) {
            $code = isset($codes[$logical]) ? (int)$codes[$logical] : 0;
            if ($code <= 0) {
                continue; // kein Code aufgelöst (z. B. SDI ohne Hersteller-Default)
            }
            if ($available !== [] && !in_array($code, $available, true)) {
                continue; // Gerät kennt diesen Eingang nicht
            }

            $auswahl[$logical] = [
                'code' => $code,
                'name' => isset($namen[$code]) ? $namen[$code] : $fallback,
            ];
            $belegt[] = $code;
        }

        // Alles Weitere, was das Gerät meldet - mit dem Code als logischem Wert
        foreach ($available as $code) {
            $code = (int)$code;
            if ($code <= 0 || in_array($code, $belegt, true)) {
                continue;
            }

            $auswahl[$code] = [
                'code' => $code,
                'name' => isset($namen[$code]) ? $namen[$code] : ('Eingang ' . $code),
            ];
            $belegt[] = $code;
        }

        return $auswahl;
    }

    /** Vom Gerät gemeldete Eingangsnamen als Code => Name. */
    private function CachedInputNames()
    {
        $roh = json_decode((string)$this->ReadAttributeString('InputNames'), true);
        if (!is_array($roh)) {
            return [];
        }

        $namen = [];
        foreach ($roh as $code => $name) {
            $code = (int)$code;
            $name = trim((string)$name);
            if ($code > 0 && $name !== '') {
                $namen[$code] = $name;
            }
        }

        return $namen;
    }

    private function MapInputToDevice($logical)
    {
        $l = (int)$logical;

        $auswahl = $this->AllInputChoices();
        if (isset($auswahl[$l])) {
            return (int)$auswahl[$l]['code'];
        }

        // Noch nie Kontakt zum Gerät gehabt: auf die Hersteller-Defaults zurückfallen,
        // damit sich der Projektor auch beim allerersten Befehl schalten lässt.
        $codes = $this->ResolveInputCodes();
        if (isset($codes[$l])) {
            return (int)$codes[$l];
        }

        // Ein logischer Wert >= 11 ist ein direkt adressierter PJLink-Code.
        return ($l >= 11) ? $l : 0;
    }

    // Eingangsliste, die das Gerät zuletzt über INST gemeldet hat
    private function KnownDeviceInputs()
    {
        $raw = json_decode((string)$this->ReadAttributeString('DeviceInputs'), true);
        if (!is_array($raw)) {
            return [];
        }

        $codes = array_values(array_unique(array_map('intval', $raw)));
        sort($codes);

        return $codes;
    }

    /**
     * Liefert die PJLink-Codes der drei logischen Eingänge.
     *
     * Reihenfolge: manueller Override gewinnt immer, sonst der Hersteller-Default.
     * Kennt das Modul die Eingangsliste des Geräts und der Default steht nicht
     * darin, wird ein freier Eingang derselben PJLink-Gruppe genommen. Sony
     * gibt HDBaseT je nach Modell als 33 oder 36 aus - der Default 36 läuft am
     * VPL-FHZ80 sonst in ERR2, weil das Gerät nur 31/32/33 kennt.
     */
    private function ResolveInputCodes()
    {
        $vendor = (string)$this->ReadPropertyString('Vendor');

        // SDI kennt nur Epson mit einem festen Default (34). Für Sony ist der
        // Code modellabhängig und wird deshalb nicht geraten - dort bleibt der
        // Eingang aus, bis jemand den Code von Hand setzt. Panasonic führt
        // HDBaseT als "DIGITAL LINK" auf Code 33 (geprüft am PT-VMZ72) und hat
        // in dieser Geräteklasse kein SDI.
        if ($vendor === 'SONY') {
            $defaults = [1 => 31, 2 => 32, 3 => 36, 4 => 0];
        } elseif ($vendor === 'PANASONIC') {
            $defaults = [1 => 31, 2 => 32, 3 => 33, 4 => 0];
        } else {
            $defaults = [1 => 32, 2 => 33, 3 => 56, 4 => 34];
        }

        $overrides = [
            1 => (int)$this->ReadPropertyInteger('CodeHDMI1'),
            2 => (int)$this->ReadPropertyInteger('CodeHDMI2'),
            3 => (int)$this->ReadPropertyInteger('CodeHDBT'),
            4 => (int)$this->ReadPropertyInteger('CodeSDI'),
        ];

        $codes = [];
        foreach ($defaults as $logical => $default) {
            $codes[$logical] = ($overrides[$logical] > 0) ? $overrides[$logical] : $default;
        }

        $available = $this->KnownDeviceInputs();
        if ($available === []) {
            // Gerät noch nie erreicht - beim bisherigen Verhalten bleiben
            return $codes;
        }

        // Belegt ist, was der Anwender gesetzt hat oder was das Gerät bestätigt
        $taken = [];
        foreach ($codes as $logical => $code) {
            if ($overrides[$logical] > 0 || in_array($code, $available, true)) {
                $taken[] = $code;
            }
        }

        foreach ($codes as $logical => $code) {
            if ($overrides[$logical] > 0 || in_array($code, $available, true)) {
                continue;
            }
            if ($code <= 0) {
                // Kein Default bekannt - ohne Gruppe gibt es nichts zu raten.
                continue;
            }

            // Gleiche PJLink-Gruppe: 1x RGB, 2x Video, 3x Digital, 4x Storage, 5x Netzwerk
            $group = intdiv($code, 10);

            foreach ($available as $candidate) {
                if (intdiv($candidate, 10) !== $group) {
                    continue;
                }
                if (in_array($candidate, $taken, true)) {
                    continue;
                }

                $codes[$logical] = $candidate;
                $taken[] = $candidate;
                break;
            }
        }

        return $codes;
    }

    private function UnmapInputToLogical($deviceCode)
    {
        $deviceCode = (int)$deviceCode;
        if ($deviceCode <= 0) {
            return 0;
        }

        // Klassische Plätze haben Vorrang, damit bestehende Visus den gewohnten
        // Wert 1..4 sehen und nicht plötzlich den Gerätecode.
        foreach ($this->AllInputChoices() as $logical => $eintrag) {
            if ((int)$eintrag['code'] === $deviceCode) {
                return (int)$logical;
            }
        }

        foreach ($this->ResolveInputCodes() as $logical => $code) {
            if ((int)$code === $deviceCode) {
                return (int)$logical;
            }
        }

        return 0;
    }

    // ---------- PJLink ----------

    /**
     * Liest eine PJLink-Zeile.
     *
     * Wichtig: PJLink beendet Handshake und Antwort mit CR (0x0D), NICHT mit LF.
     * fgets() wartet deshalb bei jeder Abfrage bis zum Stream-Timeout, bevor es
     * die längst vollständige Antwort herausgibt - am Panasonic PT-VMZ72 gemessen
     * 2 s pro Lesevorgang, also 4 s pro Befehl. Zeichenweise bis zum CR zu lesen
     * bringt dieselben sechs Abfragen von 24,4 s auf 0,3 s.
     *
     * Führende CR/LF werden übersprungen, damit auch Geräte sauber laufen, die
     * CRLF senden (sonst läge das LF beim nächsten Lesevorgang im Puffer).
     */
    private function PJLinkReadLine($fp, $timeoutSec)
    {
        $deadline = microtime(true) + max(1, (int)$timeoutSec);
        $out = '';

        while (microtime(true) < $deadline) {
            $c = fread($fp, 1);

            if ($c === false) {
                break;
            }

            if ($c === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out']) || feof($fp)) {
                    break;
                }
                usleep(2000);
                continue;
            }

            if ($c === "\r" || $c === "\n") {
                if ($out === '') {
                    continue; // vorlaufendes Zeilenende überspringen
                }
                return $out;
            }

            $out .= $c;
            if (strlen($out) >= 512) {
                break;
            }
        }

        return $out;
    }

    private function PJLinkSend($ip, $port, $password, $cmd, $timeoutSec)
    {
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($ip, (int)$port, $errno, $errstr, (int)$timeoutSec);
        if (!$fp) {
            throw new Exception("PJLink Verbindung fehlgeschlagen zu $ip:$port - $errstr ($errno)");
        }

        stream_set_timeout($fp, (int)$timeoutSec);

        $handshake = trim($this->PJLinkReadLine($fp, $timeoutSec));
        if (strpos($handshake, 'PJLINK ') !== 0) {
            fclose($fp);
            throw new Exception("Ungültiger PJLink Handshake: '$handshake'");
        }

        $authPrefix = '';
        if (preg_match('/^PJLINK 1 ([0-9A-Fa-f]{8,})$/', $handshake, $m)) {
            $authPrefix = md5($m[1] . (string)$password);
        }

        fwrite($fp, $authPrefix . (string)$cmd . "\r");
        $resp = trim($this->PJLinkReadLine($fp, $timeoutSec));
        fclose($fp);

        if ($resp === 'PJLINK ERRA') {
            throw new Exception('PJLink Authentifizierung fehlgeschlagen (Passwort prüfen).');
        }

        // Der Panasonic PT-VMZ72 lässt eine Antwort aus, während er eine Quelle
        // umschaltet. Das ist kein Ausfall - ohne eigene Fehlerklasse landete das
        // früher als "INPT set unerwartet:" im Log und meldete den Projektor offline.
        if ($resp === '') {
            throw new Exception('PJLink Antwort ausgeblieben (Gerät gerade beschäftigt) auf: ' . (string)$cmd);
        }

        return $resp;
    }

    private function PJLinkGetPower($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1POWR ?", $timeout);
        if (preg_match('/%1POWR=([0-3])/', $r, $m)) {
            return (int)$m[1];
        }

        // Während eines Quellenwechsels antwortet der Panasonic PT-VMZ72 auf POWR?
        // mit ERR4. Laut Norm ist ERR4 ein Gerätefehler, in der Praxis heißt es hier
        // "gerade nicht auskunftsfähig". Als Ausfall gewertet erzeugte das bei jedem
        // Umschalten ein "nicht mehr erreichbar"/"wieder erreichbar"-Paar im Log.
        // Ein echter Dauerfehler fällt weiterhin auf: er verschwindet nicht wieder
        // und steht zusätzlich im Fehlerstatus (ERST).
        if (preg_match('/%1POWR=ERR([34])/', $r, $m)) {
            throw new Exception('PJLink gerade nicht auskunftsfähig (POWR=ERR' . $m[1] . ').');
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
        // ERR3/ERR4 = gerade keine Auskunft (z. B. mitten im Umschalten),
        // ERR1/ERR2 = Gerät kennt die Abfrage nicht. In allen Fällen gilt der
        // Ist-Input schlicht als unbekannt - das darf keinen Ausfall auslösen.
        if (preg_match('/%1INPT=ERR[1-4]/', $r)) {
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
        if (preg_match('/%1INST=ERR[34]/', $r)) {
            throw new Exception('PJLink gerade nicht auskunftsfähig (INST).');
        }
        throw new Exception("INST? unerwartet: $r");
    }

    // PJLink-Klasse des Geräts (1 oder 2). Nur Class 2 kennt INNM.
    private function PJLinkGetClass($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%1CLSS ?", $timeout);

        if (preg_match('/%1CLSS=([12])/', $r, $m)) {
            return (int)$m[1];
        }
        throw new Exception("CLSS? unerwartet: $r");
    }

    // Eingangsname, wie ihn das Gerät selbst führt (z. B. "InputC" beim Sony VPL-FHZ80)
    private function PJLinkGetInputNameOrNull($ip, $port, $pw, $deviceCode, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, "%2INNM ?" . (int)$deviceCode, $timeout);

        if (preg_match('/%2INNM=(.*)$/', $r, $m)) {
            $name = trim($m[1]);
            // ERR1/ERR2 = Eingang kennt das Gerät nicht, ERR3/ERR4 = gerade nicht abfragbar
            if ($name === '' || preg_match('/^ERR[1-4]$/', $name)) {
                return null;
            }
            return $name;
        }
        return null;
    }

    // ---- AVMT (Bild/Ton stumm, "Shutter") ----

    /**
     * Liefert true/false oder null, wenn das Gerät gerade keine Auskunft gibt.
     * AVMT-Antwort ist zweistellig: 1x = Bild, 2x = Ton, 3x = beides;
     * die zweite Stelle ist 1 (stumm) oder 0 (normal).
     */
    private function PJLinkGetAVMuteOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%1AVMT ?', $timeout);

        if (preg_match('/%1AVMT=([123])([01])/', $r, $m)) {
            return ((int)$m[2] === 1);
        }
        if (preg_match('/%1AVMT=ERR[1234]/', $r)) {
            return null;
        }
        throw new Exception("AVMT? unerwartet: $r");
    }

    private function PJLinkSetAVMute($ip, $port, $pw, $on, $timeout)
    {
        $suffix = $on ? '1' : '0';

        // Bereits bekannte Variante zuerst, sonst der Reihe nach probieren.
        $bekannt = (int)$this->ReadAttributeInteger('AVMuteVariant');
        $gruppen = ($bekannt > 0) ? [intdiv($bekannt, 10)] : [3, 1, 2];

        $letzte = '';
        foreach ($gruppen as $gruppe) {
            $r = $this->PJLinkSend($ip, $port, $pw, '%1AVMT ' . $gruppe . $suffix, $timeout);
            if (strpos($r, '%1AVMT=OK') === 0) {
                $this->WriteAttributeInteger('AVMuteVariant', $gruppe * 10 + 1);
                return;
            }
            $letzte = $r;
            if (strpos($r, '%1AVMT=ERR2') !== 0) {
                // ERR3/ERR4 heißt "gerade nicht" - andere Gruppen bringen nichts
                break;
            }
        }

        if (strpos($letzte, '%1AVMT=ERR2') === 0) {
            throw new Exception('AVMT set ungültig (ERR2) – Gerät kennt keine der Varianten 31/11/21.');
        }
        if (strpos($letzte, '%1AVMT=ERR3') === 0) {
            throw new Exception('AVMT set nicht verfügbar (ERR3) – Projektor noch nicht bereit.');
        }
        throw new Exception("AVMT set unerwartet: $letzte");
    }

    // ---- FREZ (Standbild, Class 2) ----
    private function PJLinkGetFreezeOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%2FREZ ?', $timeout);

        if (preg_match('/%2FREZ=([01])\s*$/', $r, $m)) {
            return ((int)$m[1] === 1);
        }
        if (preg_match('/%2FREZ=ERR[1234]/', $r)) {
            return null;
        }
        throw new Exception("FREZ? unerwartet: $r");
    }

    private function PJLinkSetFreeze($ip, $port, $pw, $on, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%2FREZ ' . ($on ? '1' : '0'), $timeout);
        if (strpos($r, '%2FREZ=OK') === 0) return;
        if (strpos($r, '%2FREZ=ERR3') === 0) {
            throw new Exception('FREZ set nicht verfügbar (ERR3) – meist liegt am Eingang kein Signal an.');
        }
        if (strpos($r, '%2FREZ=ERR1') === 0 || strpos($r, '%2FREZ=ERR2') === 0) {
            throw new Exception('FREZ set abgewiesen – Gerät unterstützt kein Standbild über PJLink.');
        }
        throw new Exception("FREZ set unerwartet: $r");
    }

    // ---- Diagnose ----

    /** ERST: sechs Stellen Lüfter/Lampe/Temperatur/Abdeckung/Filter/Sonstiges, 0=ok 1=Warnung 2=Fehler */
    private function PJLinkGetErrorStatusOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%1ERST ?', $timeout);

        if (preg_match('/%1ERST=([0-2]{6})/', $r, $m)) {
            return $m[1];
        }
        if (preg_match('/%1ERST=ERR[1234]/', $r)) {
            return null;
        }
        throw new Exception("ERST? unerwartet: $r");
    }

    /** LAMP: je Lampe "Stunden Status", z. B. "1234 1". Liefert [[stunden, an], ...] oder null. */
    private function PJLinkGetLampsOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%1LAMP ?', $timeout);

        if (preg_match('/%1LAMP=([0-9 ]+)/', $r, $m)) {
            $teile = preg_split('/\s+/', trim($m[1]));
            $lampen = [];
            for ($i = 0; $i + 1 < count($teile); $i += 2) {
                $lampen[] = [(int)$teile[$i], ((int)$teile[$i + 1] === 1)];
            }
            return ($lampen === []) ? null : $lampen;
        }
        if (preg_match('/%1LAMP=ERR[1234]/', $r)) {
            return null;
        }
        throw new Exception("LAMP? unerwartet: $r");
    }

    /** FILT: Filter-Betriebsstunden (Class 2). */
    private function PJLinkGetFilterHoursOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%2FILT ?', $timeout);

        if (preg_match('/%2FILT=([0-9]+)/', $r, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /** IRES: Auflösung des anliegenden Signals, "-" = kein Signal (Class 2). */
    private function PJLinkGetInputResolutionOrNull($ip, $port, $pw, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, '%2IRES ?', $timeout);

        if (preg_match('/%2IRES=(.*)$/', $r, $m)) {
            $wert = trim($m[1]);
            if ($wert === '' || preg_match('/^ERR[1-4]$/', $wert)) {
                return null;
            }
            return ($wert === '-') ? 'kein Signal' : $wert;
        }
        return null;
    }

    /** Einfache Textabfrage (NAME, INF1, INF2, INFO, SNUM, SVER). */
    private function PJLinkGetTextOrNull($ip, $port, $pw, $cmd, $timeout)
    {
        $r = $this->PJLinkSend($ip, $port, $pw, $cmd . ' ?', $timeout);

        if (preg_match('/=(.*)$/', $r, $m)) {
            $wert = trim($m[1]);
            if ($wert === '' || preg_match('/^ERR[1-4]$/', $wert)) {
                return null;
            }
            return $wert;
        }
        return null;
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
        if (!$this->IsPoweredOn()) return;   // bei Aus/Warm-/Cool-down nicht an den Projektor senden
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
        if (!$this->IsPoweredOn()) return;   // bei Aus/Warm-/Cool-down nicht an den Projektor senden
        $this->MarkManualOverride();
        $this->ApplyLightLevel((int)$level);
        $this->RefreshLightNow();
    }

    // True nur, wenn der Projektor voll eingeschaltet ist (PowerState 1 = An).
    // Bei Aus (0), Cool-down (2) oder Warm-up (3) werden manuelle Helligkeits-/Modus-
    // Befehle ignoriert (kein Epson-Web-Befehl im Standby).
    private function IsPoweredOn()
    {
        if ((int)$this->GetValue('PowerState') === 1) return true;
        $this->LogMessage('Helligkeit/Modus bei ausgeschaltetem Projektor ignoriert (PowerState='
            . (int)$this->GetValue('PowerState') . ').', KL_NOTIFY);
        return false;
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
