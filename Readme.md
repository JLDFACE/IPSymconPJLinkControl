# PJLink Projector

Dieses Modul ermöglicht die Steuerung von Projektoren über **PJLink (Class 1 und Class 2)** in **IP-Symcon**.  
Hersteller-Voreinstellungen gibt es für **Sony**, **Epson** und **Panasonic**; grundsätzlich läuft jeder
PJLink-fähige Projektor, weil das Modul die **Eingangsliste und deren Beschriftung vom Gerät selbst liest**.

Neben Power und Quellenwahl beherrscht das Modul optional **Bild/Ton stumm (Shutter)**, **Standbild**
sowie eine **Diagnose** mit Betriebsstunden, Fehlerstatus, Eingangsauflösung und Geräteinfo.

Zusätzlich kann für **Epson-Modelle mit Epson Web Control** die **Laser-Lichtleistung / Helligkeit**
gesteuert werden (getestet am **Epson QS100**) – inklusive einer **automatischen Anpassung an die
Raumhelligkeit** über einen verknüpften Helligkeitssensor (z. B. KNX-Lux-Wert). Da PJLink selbst
keinen Helligkeitsbefehl kennt, läuft dieser Teil über die HTTP-API der Epson Web Control.

Das Modul ist für den stabilen Betrieb auf der **SymBox** ausgelegt.

---

## Voraussetzungen

- IP-Symcon ab Version **6.x**
- Projektor mit **PJLink Class 1** Unterstützung
- Netzwerkverbindung zum Projektor
- **Optional (nur Helligkeit):** Epson-Projektor mit aktivierter **Epson Web Control** (HTTP/HTTPS)

---

## Installation

### Modul hinzufügen

Über das **Module Control** in IP-Symcon:

https://github.com/JLDFACE/IPSymconPJLinkControl


Nach dem Hinzufügen das Modul aktualisieren.

---

## Instanz anlegen

1. Objektbaum öffnen
2. **Instanz hinzufügen**
3. Kategorie **Geräte**
4. **PJLink Projector (Sony/Epson)** auswählen

---

## Konfiguration

| Eigenschaft | Beschreibung |
|------------|--------------|
| Hersteller | Sony, Epson oder Panasonic (nur für die Default-Inputcodes) |
| IP / Hostname | IP-Adresse oder Hostname des Projektors |
| Port | PJLink-Port (Standard: 4352) |
| Passwort | PJLink-Passwort (leer, wenn keine Authentifizierung aktiv ist) |
| Code HDMI 1 | Optionaler PJLink-Code für HDMI 1 |
| Code HDMI 2 | Optionaler PJLink-Code für HDMI 2 |
| Code HDBaseT | Optionaler PJLink-Code für HDBaseT / DIGITAL LINK |
| Code SDI | Optionaler PJLink-Code für SDI (Epson-Standard 34, bei Sony/Panasonic modellabhängig und daher Pflicht) |
| Input-Delay | Verzögerung nach echtem Power-On vor Quellenumschaltung |
| PollFast | Polling-Intervall bei Übergängen |
| PollSlow | Polling-Intervall im stabilen Zustand |
| FastAfterChange | Zeitspanne für schnelles Polling nach Statusänderungen |
| Fehler-Log Drosselung | Mindestabstand zwischen identischen Warnungen (Sek., 0 = aus) |

### Eingänge

Das Modul fragt beim Projektor die Eingangsliste (`INST`) ab und holt sich bei Class-2-Geräten
zusätzlich die **echte Beschriftung** (`INNM`). Die Quellen-Auswahl in der Visualisierung zeigt
deshalb genau das, was am Gerät auch im OSD steht.

Die Variable **Input** nutzt zwei Wertebereiche:

| Wert | Bedeutung |
|------|-----------|
| 1–4 | Die klassischen Plätze HDMI 1, HDMI 2, HDBaseT, SDI. Bestehende Visualisierungen und Skripte laufen unverändert weiter. |
| ≥ 11 | Jeder weitere Eingang, den das Gerät meldet – der Wert ist der PJLink-Code selbst (z. B. 11 = COMPUTER, 41 = MEMORY VIEWER, 51 = NETWORK). |

Beispiel Panasonic PT-VMZ72: HDMI1 (31) liegt auf Platz 1, HDMI2 (32) auf Platz 2,
DIGITAL LINK (33) auf Platz 3; COMPUTER, MEMORY VIEWER und NETWORK stehen unter 11, 41 und 51.

Meldet das Gerät einen Eingang nicht mehr, verschwindet er aus der Auswahl.

### Default-Inputcodes

Die Codes unten gelten nur, solange das Gerät noch nichts Eigenes gemeldet hat. Steht der Default
nicht in der Geräteliste, sucht das Modul einen Ersatz – aber vorsichtig:

- **Nur in der Digitalgruppe 3x.** In den anderen PJLink-Gruppen stehen ganz verschiedene Quellen
  nebeneinander. Epson legt HDBaseT auf 56 in die Netzwerkgruppe; der EB-L265F hat kein HDBaseT,
  dafür 52 = LAN. Ein Gruppen-Ersatz hätte „LAN" auf den HDBaseT-Platz gelegt.
- **Nicht gegen den Gerätenamen.** Nennt das Gerät einen Eingang eindeutig als andere Schnittstelle
  (HDMI, HDBaseT/DIGITAL LINK, SDI, DVI), kommt er auf keinen fremden Platz.
- **SDI nur bei ausdrücklichem SDI-Namen.** Ein beliebiger Digitaleingang ist kein SDI.

Findet sich kein passender Ersatz, entfällt der Platz. Der Eingang selbst bleibt trotzdem wählbar,
dann unter seinem PJLink-Code.

**Sony**
- HDMI 1: 31
- HDMI 2: 32
- HDBaseT: 36
- SDI: kein Standard (Code von Hand setzen, sonst wird der Eingang nicht angeboten)

**Epson**
- HDMI 1: 32
- HDMI 2: 33
- HDBaseT: 56
- SDI: 34

**Panasonic**
- HDMI 1: 31
- HDMI 2: 32
- DIGITAL LINK (HDBaseT): 33
- SDI: kein Standard (in dieser Geräteklasse nicht vorhanden)

### Zusatzfunktionen des PJLink-Standards

Alle drei sind ab Werk **aus**, damit bestehende Installationen nach einem Update nicht ungefragt
neue Variablen bekommen.

| Eigenschaft | Beschreibung |
|------------|--------------|
| Bild/Ton stumm schalten | Legt die Variable `AVMute` an (PJLink `AVMT`, Class 1) |
| Standbild | Legt die Variable `Freeze` an (PJLink `FREZ`, nur Class 2) |
| Diagnose | Legt Betriebsstunden, Fehlerstatus, Auflösung und Geräteinfo an |
| Abstand der Diagnose-Abfragen | Wie oft die Diagnosewerte geholt werden (Sek., Vorgabe 300) |

### Helligkeit / Lichtleistung (Epson Web Control)

| Eigenschaft | Beschreibung |
|------------|--------------|
| Lichtleistungs-Steuerung aktivieren | Schaltet die Helligkeits-Funktion frei (legt die Variablen an) |
| Web-Control Benutzer | Benutzername der Epson Web Control (Standard: `EPSONWEB`) |
| Web-Control Passwort | Passwort der Epson Web Control |
| HTTPS statt HTTP verwenden | Zugriff über Port 443 statt 80 (selbstsigniertes Zertifikat wird akzeptiert) |

### Automatische Anpassung an Raumhelligkeit

| Eigenschaft | Beschreibung |
|------------|--------------|
| Auto-Helligkeit aktivieren | Aktiviert die automatische Regelung (legt den Laufzeit-Schalter an) |
| Helligkeitssensor | Verknüpfte Variable mit dem Raumhelligkeitswert (z. B. KNX-Lux) |
| Kennlinie (Lux → Pegel) | Tabelle von Stützpunkten; dazwischen wird linear interpoliert, außerhalb geklemmt |
| Glättung | Trägheit des Sensorwerts (100 = keine Glättung, kleiner = träger) |
| Totband | Minimale Pegeländerung, ab der überhaupt gestellt wird |
| Mindestabstand | Kürzeste Zeit (Sek.) zwischen zwei Stellbefehlen |
| Pause nach manueller Änderung | Minuten, für die die Automatik nach einer Handbedienung pausiert (0 = aus) |

---

## Variablen

### Sichtbare Variablen

| Variable | Typ | Beschreibung |
|--------|-----|--------------|
| Power | Boolean | Projektor Ein / Aus |
| PowerState | Integer | 0=Aus, 1=An, 2=Cool-down, 3=Warm-up |
| Input | Integer | Quelle (Plätze 1–4 oder PJLink-Code ab 11) |
| Busy | Boolean | Projektor befindet sich im Übergang |
| AvailableInputs | String | Eingangsliste, wie das Gerät sie meldet |
| Online | Boolean | Projektor erreichbar |
| LastError | String | Letzter Fehler |
| LastOKTimestamp | Integer | Zeitstempel des letzten erfolgreichen Zugriffs |
| ErrorCounter | Integer | Anzahl aufeinanderfolgender Fehler |
| AVMute¹ | Boolean | Bild und Ton stumm (Shutter) |
| Freeze² | Boolean | Standbild eingefroren |
| LampHours³ | Integer | Betriebsstunden der Lichtquelle |
| LampState³ | String | Zustand und Stunden je Lampe |
| FilterHours³ | Integer | Filter-Betriebsstunden (nur Class 2) |
| ErrorStatus³ | String | Fehlerstatus im Klartext, sonst „OK" |
| HasFault³ | Boolean | Mindestens eine Warnung oder ein Fehler liegt an |
| InputResolution³ | String | Auflösung des anliegenden Signals (nur Class 2) |
| DeviceInfo³ | String | Hersteller, Modell, Name, Seriennummer, Firmware, Klasse |
| LightMode⁴ | Integer | Lichtleistungs-Modus: Hoch / Eco / Mittel / Custom |
| LightLevel⁴ | Integer | Lichtleistungs-Pegel 0–250 (nur im Custom-Modus wirksam) |
| AutoBrightness⁵ | Boolean | Laufzeit-Schalter der automatischen Raumhelligkeits-Regelung |

¹ nur wenn *Bild/Ton stumm schalten* aktiviert ist  
² nur wenn *Standbild* aktiviert ist  
³ nur wenn *Diagnose* aktiviert ist  
⁴ nur wenn *Lichtleistungs-Steuerung* aktiviert ist  
⁵ nur wenn zusätzlich *Auto-Helligkeit* aktiviert ist

### Interne Variablen (versteckt)

- Soll-Power
- Soll-Input (Device- und Logical-Code)
- Zeitstempel Power-On
- Zeitstempel letzter Statusänderung

---

## Funktionsweise

- Die Kommunikation erfolgt **ohne IO-Instanz** direkt per TCP (Request/Response).
- Befehle werden **sofort** beim Bedienen gesendet (keine Poll-Verzögerung).
- Beim Setzen einer Quelle wird der Projektor automatisch eingeschaltet.
- Während einer laufenden Umschaltung bleiben Power und Input **stabil auf dem Sollwert** (kein Flipping).
- Die Quelle wird intern als „pending" behandelt, bis der Projektor den Sollzustand erreicht.
- Polling passt sich automatisch dem Betriebszustand an:
  - Schnell bei Übergängen
  - Langsam im stabilen Zustand

---

## Helligkeit / Lichtleistung (Epson Web Control)

PJLink kennt keinen Helligkeitsbefehl. Für Epson-Modelle mit **Epson Web Control** steuert das Modul
die Laser-Lichtleistung deshalb über deren HTTP-API:

- **Lesen:** `GET /cgi-bin/json_query?jsoncallback=<CMD>?`
- **Schreiben:** `GET /cgi-bin/json_query?jsoncallback=<CMD> <wert>` – der native ESC/VP21-Befehl,
  den der Projektor mit `SUCCESS` oder `ERR` quittiert. Ältere Web-Control-Stände ohne diese
  Durchreiche bekommen den alten Weg `GET /cgi-bin/directsend?_OSD_<CMD>=<wert>`. Welcher Weg
  greift, ermittelt das Modul einmal selbst, indem es den Lichtmodus auf seinen aktuellen Wert setzt.
- **Auth:** HTTP-Digest (`Web-Control Benutzer`/`Passwort`) mit Pflicht-`Referer` (CSRF-Schutz)

Verwendete Kommandos: `LUMINANCE` (Modus `00`=Hoch, `01`=Eco, `02`=Mittel, `04`=Erweitert,
`05`=Custom) und `LUMLEVEL` (numerischer Pegel `0–250`, nur im Custom-Modus wirksam). Beim Setzen
eines Pegels wird automatisch in den Custom-Modus gewechselt.

Welche Modi es gibt, hängt vom Modell ab. Der EB-L265F kennt `00`, `01`, `04` und `05`, lehnt `02`
ab. Einen abgelehnten Modus meldet das Modul als Warnung, der angezeigte Wert bleibt der echte.
Den Pegel rundet der EB-L265F intern: 200 wird zu 198, 250 zu 247.

Der alte OSD-Weg taugt nicht für alle Geräte: am EB-L265F ergab `directsend?_OSD_LUMLEVEL=150`
Pegel 0 und `=100` Pegel 247 – ohne Fehlermeldung. Deshalb der native Befehl.

Der Status wird gedrosselt (höchstens alle 12 s, nur wenn der Projektor an ist) im normalen Poll
mitgelesen und stört den PJLink-Betrieb nicht.

### Steuerung per Skript

| Funktion | Beschreibung |
|----------|--------------|
| `PJP_SetLightMode($id, $mode)` | Lichtleistungs-Modus setzen (`0`, `1`, `2`, `5`) |
| `PJP_SetLightLevel($id, $level)` | Lichtleistungs-Pegel `0–250` setzen (aktiviert Custom-Modus) |

> Aufrufe dieser Funktionen bzw. eine manuelle Änderung der Variablen gelten als **Handbedienung**
> und pausieren die automatische Regelung für die konfigurierte Dauer.

---

## Automatische Raumhelligkeits-Regelung

Ist ein Helligkeitssensor verknüpft und *Auto-Helligkeit* aktiv, passt das Modul den
Lichtleistungs-Pegel automatisch an die gemessene Raumhelligkeit an:

1. Der Sensorwert löst über **`VM_UPDATE`** direkt eine Neuberechnung aus (zusätzlich als Fallback im Poll).
2. Der Wert wird per **gleitendem Mittelwert (EMA)** geglättet.
3. Über die **Kennlinie** (Lux → Pegel, stückweise linear) wird der Ziel-Pegel bestimmt.
4. **Totband** und **Rate-Limit** verhindern Zappeln und unnötige Stellbefehle.

Geregelt wird nur, wenn der Projektor **an** ist und der Laufzeit-Schalter **AutoBrightness** aktiv ist.
Eine **manuelle Änderung** (Slider oder Skript) pausiert die Automatik für die eingestellten Minuten;
das erneute Einschalten von *AutoBrightness* hebt eine laufende Pause sofort auf.

---

## Polling-Strategie

- **PollFast**:
  - Warm-up
  - Cool-down
  - Pending Input
  - Direkt nach Statusänderungen
- **PollSlow**:
  - Stabiler Ein-/Aus-Zustand
- **FastAfterChange**:
  - Nach erkannter Statusänderung bleibt das Modul für eine definierte Zeit im Fast-Polling

---

## Fehlerbehandlung

- Verbindungs- oder Protokollfehler setzen `Online = false`
- Fehler werden in `LastError` gespeichert
- Warnungen für fehlgeschlagene Sofort-Befehle werden gedrosselt (konfigurierbar)
- Keine Fatal Errors bei parallelen Timer- und Bedienaktionen
- Sperrmechanismen (Semaphore) sind non-fatal ausgeführt

---

## Bild/Ton stumm, Standbild und Diagnose

### Steuerung per Skript

| Funktion | Beschreibung |
|----------|--------------|
| `PJP_SetAVMute($id, true/false)` | Bild und Ton stumm schalten (PJLink `AVMT`) |
| `PJP_SetFreeze($id, true/false)` | Standbild einfrieren (PJLink `FREZ`, Class 2) |
| `PJP_ReadDeviceInfo($id)` | Geräteinfo neu einlesen, liefert sie als Text zurück |
| `PJP_RefreshAvailableInputs($id)` | Eingangsliste und Beschriftung neu vom Gerät holen |
| `PJP_Poll($id)` | Status sofort aktualisieren |

### Modellabhängigkeiten

Welche `AVMT`-Variante ein Projektor annimmt, ist Herstellersache. Das Modul probiert der Reihe nach
Bild+Ton (`31`), nur Bild (`11`) und nur Ton (`21`) und merkt sich die erste, die durchgeht.
Der Panasonic PT-VMZ72 weist zum Beispiel „nur Bild" mit `ERR2` ab.

`FREZ` beantworten viele Geräte mit `ERR3`, solange am gewählten Eingang **kein Signal anliegt**.
Der Sollwert wird dann zurückgenommen, der Projektor bleibt online.

Ein Class-1-Gerät kennt weder `FREZ` noch `INNM`, `FILT`, `IRES`, `SNUM` und `SVER`. Das Modul
ermittelt die Klasse einmal über `CLSS` und lässt diese Befehle dann ganz weg.

### Was passiert, wenn der Projektor das alles nicht kann?

**Nichts Lautes.** Lehnt ein Gerät die Zusatzbefehle ab, schreibt das Modul **keine Warnung und
keinen Fehler** ins Meldungslog, meldet den Projektor **nicht offline** und lässt Power und
Quellenwahl unberührt. Die betroffenen Variablen bleiben schlicht auf ihrem Wert stehen.

Der Grund: jede Zusatzabfrage läuft in einer eigenen Absicherung und landet im Fehlerfall
höchstens auf der Stufe **Debug**. Der Poll selbst bekommt davon nichts mit.

Eine Ausnahme gibt es bewusst: **bedient jemand eine Funktion, die das Gerät nicht kann**,
erscheint **eine** gedrosselte Warnung und der Sollwert wird zurückgenommen. Sonst stünde in
der Visualisierung ein Zustand, den es nie gab, und niemand wüsste warum.

Geprüft ist das in `tests/run.php` mit einem Gerät, das `AVMT`, `FREZ`, `ERST`, `LAMP`, `FILT`,
`IRES`, `SNUM`, `SVER` und `INNM` allesamt mit `ERR1` beantwortet.

---

## Tests

Im Ordner `tests/` liegen zwei Wege, das Modul ohne SymBox zu prüfen:

```bash
php tests/run.php                                       # Testsuite gegen den Simulator
php tests/live_test.php --host=192.168.2.118 --extras   # gegen einen echten Projektor
```

`tests/fake_projector.php` ist ein kleiner PJLink-Server. Damit lassen sich Fälle prüfen, die sich an
echter Hardware kaum herstellen lassen: Class-1-Geräte, aktivierte Authentifizierung, Geräte mit
CRLF-Zeilenenden, abgelehnte `AVMT`-Varianten und ausbleibende Antworten.

`tests/live_test.php` fährt das Modul gegen ein echtes Gerät und kann auf Wunsch schalten
(`--power=on|off`, `--switch=<logischer Wert>`, `--avmute=on|off`, `--freeze=on|off`, `--powercycle`).

---

## Hinweise

- Änderungen am Projektor (z. B. Fernbedienung) werden über Polling erkannt.
- Für reine Request/Response-Protokolle wie PJLink ist **keine IO-Instanz erforderlich**.
- Das Modul verwendet ausschließlich PHP-7-kompatible Syntax (SymBox-tauglich).
- PJLink beendet seine Antworten mit **CR**, nicht mit LF. Das Modul liest deshalb zeichenweise bis
  zum CR. Mit `fgets()` lief jede einzelne Abfrage stattdessen in den Stream-Timeout – am PT-VMZ72
  gemessen 4 s pro Befehl statt 50 ms.
- Während eines Quellenwechsels lassen manche Projektoren eine Antwort aus oder melden `POWR=ERR4`.
  Beides gilt als vorübergehend und erzeugt keine Ausfallmeldung.

---

## Getestet mit

| Gerät | PJLink | Bemerkung |
|-------|--------|-----------|
| Panasonic PT-VMZ72 | Class 2 | Power, alle sechs Eingänge, AV-Mute, Diagnose live geprüft. `FREZ` nur mit anliegendem Signal, `AVMT 11` wird abgewiesen. |
| Sony VPL-FHZ80 | Class 2 | HDBaseT liegt auf 33 statt 36 |
| Epson QS100 | Class 2 | zusätzlich Lichtleistung über Epson Web Control (alter OSD-Weg, mit dem nativen Befehl noch nicht erneut geprüft) |
| Epson EB-L265F | Class 2 | PJLink mit Passwort, elf Eingänge, kein HDBaseT/SDI, AV-Mute, Diagnose, Lichtleistung über den nativen Befehl live geprüft. Meldet beim Umschalten `POWR=ERR3`. |

---

## Lizenz

MIT License  
© FACE GmbH / Jean-Luc Doijen
