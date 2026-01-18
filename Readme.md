# PJLink Projector

Dieses Modul ermöglicht die Steuerung von Projektoren über **PJLink (Class 1)** in **IP-Symcon**.  
Unterstützt werden aktuell **Sony**- und **Epson**-Projektoren mit HDMI- und HDBaseT-Eingängen.

Das Modul ist für den stabilen Betrieb auf der **SymBox** ausgelegt.

---

## Voraussetzungen

- IP-Symcon ab Version **6.x**
- Projektor mit **PJLink Class 1** Unterstützung
- Netzwerkverbindung zum Projektor

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
