# PJLink Projector

Dieses Modul ermöglicht die Steuerung von Projektoren über **PJLink (Class 1)** in **IP-Symcon**.  
Unterstützt werden aktuell **Sony**- und **Epson**-Projektoren mit HDMI- und HDBaseT-Eingängen.

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
| Hersteller | Sony oder Epson (für Default-Inputcodes) |
| IP / Hostname | IP-Adresse oder Hostname des Projektors |
| Port | PJLink-Port (Standard: 4352) |
| Passwort | PJLink-Passwort (leer, wenn keine Authentifizierung aktiv ist) |
| Code HDMI 1 | Optionaler PJLink-Code für HDMI 1 |
| Code HDMI 2 | Optionaler PJLink-Code für HDMI 2 |
| Code HDBaseT | Optionaler PJLink-Code für HDBaseT |
| Input-Delay | Verzögerung nach echtem Power-On vor Quellenumschaltung |
| PollFast | Polling-Intervall bei Übergängen |
| PollSlow | Polling-Intervall im stabilen Zustand |
| FastAfterChange | Zeitspanne für schnelles Polling nach Statusänderungen |
| Fehler-Log Drosselung | Mindestabstand zwischen identischen Warnungen (Sek., 0 = aus) |

### Default-Inputcodes

**Sony**
- HDMI 1: 31
- HDMI 2: 32
- HDBaseT: 36

**Epson**
- HDMI 1: 32
- HDMI 2: 33
- HDBaseT: 56

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
| Input | Integer | HDMI 1 / HDMI 2 / HDBaseT |
| Busy | Boolean | Projektor befindet sich im Übergang |
| Online | Boolean | Projektor erreichbar |
| LastError | String | Letzter Fehler |
| LastOKTimestamp | Integer | Zeitstempel des letzten erfolgreichen Zugriffs |
| ErrorCounter | Integer | Anzahl aufeinanderfolgender Fehler |
| LightMode¹ | Integer | Lichtleistungs-Modus: Hoch / Eco / Mittel / Custom |
| LightLevel¹ | Integer | Lichtleistungs-Pegel 0–250 (nur im Custom-Modus wirksam) |
| AutoBrightness² | Boolean | Laufzeit-Schalter der automatischen Raumhelligkeits-Regelung |

¹ nur wenn *Lichtleistungs-Steuerung* aktiviert ist  
² nur wenn zusätzlich *Auto-Helligkeit* aktiviert ist

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

- **Lesen:** `GET /cgi-bin/json_query?jsoncallback=<CMD>`
- **Schreiben:** `GET /cgi-bin/directsend?_OSD_<PARAM>=<wert>`
- **Auth:** HTTP-Digest (`Web-Control Benutzer`/`Passwort`) mit Pflicht-`Referer` (CSRF-Schutz)

Verwendete Kommandos: `LUMINANCE` (Modus `00`=Hoch, `01`=Eco, `02`=Mittel, `05`=Custom) und
`LUMLEVEL` (numerischer Pegel `0–250`, nur im Custom-Modus wirksam). Beim Setzen eines Pegels wird
automatisch in den Custom-Modus gewechselt.

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

## Hinweise

- Änderungen am Projektor (z. B. Fernbedienung) werden über Polling erkannt.
- Für reine Request/Response-Protokolle wie PJLink ist **keine IO-Instanz erforderlich**.
- Das Modul verwendet ausschließlich PHP-7-kompatible Syntax (SymBox-tauglich).

---

## Lizenz

MIT License  
© FACE GmbH / Jean-Luc Doijen
