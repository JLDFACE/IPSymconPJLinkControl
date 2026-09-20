<?php

/**
 * Minimale Symcon-Attrappe fuer das PJLink-Modul.
 *
 * Bildet nur nach, was PJLinkProjector wirklich benutzt: Properties,
 * Attribute, Variablen (per Ident), Buffer, Timer, Profile und Semaphoren.
 * Damit laesst sich das Modul ohne SymBox gegen einen echten Projektor
 * oder gegen synthetische Antworten fahren.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;

const KL_MESSAGE = 1;
const KL_SUCCESS = 2;
const KL_NOTIFY  = 3;
const KL_WARNING = 20;
const KL_ERROR   = 30;
const KL_DEBUG   = 40;

const VM_UPDATE = 10603;

final class IPSKernel
{
    /** @var array<int,mixed> */
    public static $values = [];
    /** @var array<int,array> */
    public static $objects = [];
    /** @var string[] */
    public static $log = [];
    /** @var array<string,array> Profilname => [assoc => [wert => name], icon, text, values] */
    public static $profiles = [];
    /** @var string[] Alle belegten Semaphoren */
    public static $semaphores = [];
    public static $nextID = 1000;
    /** @var bool true = jedes LogMessage sofort ausgeben */
    public static $echoLog = false;

    public static function reset()
    {
        self::$values = [];
        self::$objects = [];
        self::$log = [];
        self::$profiles = [];
        self::$semaphores = [];
        self::$nextID = 1000;
    }

    /** Legt eine freistehende Variable an (z. B. als Helligkeitssensor). */
    public static function makeVariable($value)
    {
        $id = self::$nextID++;
        self::$objects[$id] = ['type' => 'var'];
        self::$values[$id] = $value;
        return $id;
    }
}

function IPS_VariableExists($id) { return isset(IPSKernel::$objects[$id]); }
function IPS_ObjectExists($id)   { return isset(IPSKernel::$objects[$id]); }
function IPS_SetHidden($id, $h)  { IPSKernel::$objects[$id]['hidden'] = $h; }
function IPS_SetIcon($id, $i)    { IPSKernel::$objects[$id]['icon'] = $i; }
function IPS_SetName($id, $n)    { IPSKernel::$objects[$id]['name'] = $n; }
function IPS_GetName($id)        { return IPSKernel::$objects[$id]['name'] ?? ''; }

function GetValue($id) { return IPSKernel::$values[$id] ?? null; }
function SetValue($id, $v) { IPSKernel::$values[$id] = $v; }

function IPS_VariableProfileExists($n) { return isset(IPSKernel::$profiles[$n]); }
function IPS_CreateVariableProfile($n, $t) { IPSKernel::$profiles[$n] = ['type' => $t, 'assoc' => []]; }
function IPS_DeleteVariableProfile($n) { unset(IPSKernel::$profiles[$n]); }
function IPS_SetVariableProfileIcon($n, $i) { IPSKernel::$profiles[$n]['icon'] = $i; }
function IPS_SetVariableProfileText($n, $pre, $suf) { IPSKernel::$profiles[$n]['text'] = [$pre, $suf]; }
function IPS_SetVariableProfileValues($n, $min, $max, $step) { IPSKernel::$profiles[$n]['values'] = [$min, $max, $step]; }
function IPS_SetVariableProfileAssociation($n, $value, $name, $icon, $color)
{
    if ($name === '') {
        unset(IPSKernel::$profiles[$n]['assoc'][$value]);
        return;
    }
    IPSKernel::$profiles[$n]['assoc'][$value] = $name;
}

function IPS_SemaphoreEnter($key, $ms)
{
    if (in_array($key, IPSKernel::$semaphores, true)) {
        return false;
    }
    IPSKernel::$semaphores[] = $key;
    return true;
}

function IPS_SemaphoreLeave($key)
{
    $i = array_search($key, IPSKernel::$semaphores, true);
    if ($i !== false) {
        unset(IPSKernel::$semaphores[$i]);
        IPSKernel::$semaphores = array_values(IPSKernel::$semaphores);
    }
    return true;
}

class IPSModule
{
    public $InstanceID = 4711;

    protected $properties = [];
    protected $attributes = [];
    protected $buffers    = [];
    protected $idents     = [];
    protected $timers     = [];
    protected $messages   = [];
    protected $references = [];

    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}

    // ---- Properties ----
    public function RegisterPropertyString($n, $v)  { if (!array_key_exists($n, $this->properties)) $this->properties[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { if (!array_key_exists($n, $this->properties)) $this->properties[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { if (!array_key_exists($n, $this->properties)) $this->properties[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($this->properties[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($this->properties[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($this->properties[$n] ?? false); }
    /** Nur fuer den Test: Property vor Create()/ApplyChanges() vorbelegen. */
    public function TestSetProperty($n, $v) { $this->properties[$n] = $v; }

    // ---- Attribute ----
    public function RegisterAttributeString($n, $v)  { if (!array_key_exists($n, $this->attributes)) $this->attributes[$n] = $v; }
    public function RegisterAttributeInteger($n, $v) { if (!array_key_exists($n, $this->attributes)) $this->attributes[$n] = $v; }
    public function RegisterAttributeBoolean($n, $v) { if (!array_key_exists($n, $this->attributes)) $this->attributes[$n] = $v; }
    public function ReadAttributeString($n)  { return (string)($this->attributes[$n] ?? ''); }
    public function ReadAttributeInteger($n) { return (int)($this->attributes[$n] ?? 0); }
    public function ReadAttributeBoolean($n) { return (bool)($this->attributes[$n] ?? false); }
    public function WriteAttributeString($n, $v)  { $this->attributes[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attributes[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attributes[$n] = $v; }

    // ---- Buffer ----
    public function SetBuffer($n, $v) { $this->buffers[$n] = (string)$v; }
    public function GetBuffer($n)     { return $this->buffers[$n] ?? ''; }

    // ---- Variablen ----
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { return $this->mkVar($i, $n, $p, VARIABLETYPE_BOOLEAN, false); }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { return $this->mkVar($i, $n, $p, VARIABLETYPE_INTEGER, 0); }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0)   { return $this->mkVar($i, $n, $p, VARIABLETYPE_FLOAT, 0.0); }
    public function RegisterVariableString($i, $n, $p = '', $pos = 0)  { return $this->mkVar($i, $n, $p, VARIABLETYPE_STRING, ''); }

    private function mkVar($ident, $name, $profile, $type, $init)
    {
        if (isset($this->idents[$ident])) {
            IPSKernel::$objects[$this->idents[$ident]]['profile'] = $profile;
            return $this->idents[$ident];
        }
        $id = IPSKernel::$nextID++;
        IPSKernel::$objects[$id] = ['type' => 'var', 'ident' => $ident, 'name' => $name,
                                    'profile' => $profile, 'varType' => $type];
        IPSKernel::$values[$id] = $init;
        $this->idents[$ident] = $id;
        return $id;
    }

    public function UnregisterVariable($ident)
    {
        if (isset($this->idents[$ident])) {
            unset(IPSKernel::$values[$this->idents[$ident]]);
            unset(IPSKernel::$objects[$this->idents[$ident]]);
            unset($this->idents[$ident]);
        }
    }

    public function EnableAction($ident) { IPSKernel::$objects[$this->idents[$ident]]['action'] = true; }

    /**
     * Symcon wirft hier nicht, sondern meldet eine PHP-Warnung und liefert 0.
     * Nur deshalb funktioniert das verbreitete @$this->GetIDForIdent(...).
     */
    public function GetIDForIdent($ident)
    {
        if (!isset($this->idents[$ident])) {
            trigger_error('Ident ' . $ident . ' unbekannt', E_USER_WARNING);
            return 0;
        }
        return $this->idents[$ident];
    }

    public function SetValue($ident, $v) { IPSKernel::$values[$this->GetIDForIdent($ident)] = $v; }
    public function GetValue($ident)     { return IPSKernel::$values[$this->GetIDForIdent($ident)]; }
    public function HasIdent($ident)     { return isset($this->idents[$ident]); }

    /** Alle angelegten Variablen als ident => wert (fuer Testausgaben). */
    public function TestDumpVariables()
    {
        $out = [];
        foreach ($this->idents as $ident => $id) {
            $out[$ident] = IPSKernel::$values[$id];
        }
        return $out;
    }

    public function TestProfileOf($ident)
    {
        return IPSKernel::$objects[$this->GetIDForIdent($ident)]['profile'] ?? '';
    }

    // ---- Timer ----
    public function RegisterTimer($n, $ms, $script) { $this->timers[$n] = $ms; }
    public function SetTimerInterval($n, $ms)       { $this->timers[$n] = $ms; }
    public function GetTimerInterval($n)            { return $this->timers[$n] ?? 0; }

    // ---- Nachrichten / Referenzen ----
    public function RegisterMessage($sender, $msg)   { $this->messages[$sender][] = $msg; }
    public function UnregisterMessage($sender, $msg)
    {
        if (!isset($this->messages[$sender])) {
            throw new Exception('Keine Registrierung fuer ' . $sender);
        }
        $this->messages[$sender] = array_values(array_diff($this->messages[$sender], [$msg]));
        if ($this->messages[$sender] === []) {
            unset($this->messages[$sender]);
        }
    }
    public function GetMessageList()         { return $this->messages; }
    public function RegisterReference($id)   { $this->references[$id] = true; }
    public function UnregisterReference($id) { unset($this->references[$id]); }
    public function GetReferenceList()       { return array_keys($this->references); }

    // ---- Sonstiges ----
    public function SetStatus($s) {}
    public function SendDebug($t, $m, $f) {}
    public function LogMessage($m, $l)
    {
        $label = [KL_MESSAGE => 'MSG', KL_SUCCESS => 'OK', KL_NOTIFY => 'NOTIFY',
                  KL_WARNING => 'WARN', KL_ERROR => 'ERROR', KL_DEBUG => 'DEBUG'];
        $line = ($label[$l] ?? $l) . ': ' . $m;
        IPSKernel::$log[] = $line;
        if (IPSKernel::$echoLog) {
            echo '    [log] ' . $line . PHP_EOL;
        }
    }
}
