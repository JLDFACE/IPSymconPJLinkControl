<?php

class PJLinkProjector extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Vendor', 'SONY');
        $this->RegisterPropertyString('Host', '192.168.1.50');
        $this->RegisterPropertyInteger('Port', 4352);
        $this->RegisterPropertyString('Password', '');

        $this->RegisterTimer('PollTimer', 0, 'PJP_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Power', 'Projektor Power', '~Switch');
        $this->EnableAction('Power');

        $this->SetTimerInterval('PollTimer', 60000);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Power') {
            $this->SetValue('Power', (bool)$Value);
            return;
        }
        throw new Exception('Unknown Ident: ' . $Ident);
    }

    // Wird per Button/Timer via Prefix aufgerufen
    public function Poll()
    {
        $this->LogMessage('Poll() OK - Modul lädt', KL_MESSAGE);
    }
}
