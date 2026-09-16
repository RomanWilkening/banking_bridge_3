# Banking Bridge - FinTS zu Home Assistant

Eine schlanke Web-App zur Verbindung von deutschen Bankkonten (via FinTS/HBCI) mit Home Assistant über MQTT.

## Features

- **FinTS-Integration**: Zugriff auf Bankdaten über das FinTS-Protokoll (phpFinTS)
- **Multi-Bank-Support**: Verwalten Sie mehrere Bankverbindungen
- **TAN-Unterstützung**: Vollständige TAN-Verfahren-Unterstützung (pushTAN, chipTAN, etc.)
- **Moderne WebUI**: Benutzerfreundliche Oberfläche mit Tailwind CSS
- **Docker-Ready**: Einfache Bereitstellung mit Docker Compose
- **SQLite-Datenbank**: Alle Daten lokal und persistent gespeichert
- **MQTT-Export**: Kontodaten und Freigabestatus je Bankverbindung für Home Assistant

## TAN, Hintergrundabruf und manuelle Freigabe

**Automatische TAN- und Push-Anforderungen sind gesperrt.** In der geprüften
phpFinTS-Version 3.7.0 sendet bereits `login()`/`execute()` gegebenenfalls einen
TAN-Auftrag, bevor `needsTan()` ausgewertet werden kann. Deshalb ist der
FinTS-Hintergrundabruf konservativ vollständig pausiert — auch mit einer zuvor
erfolgreich freigegebenen Verbindung. Dies ist kein TAN-freier Abrufmodus.
PayPal-Synchronisation und MQTT-Veröffentlichung gespeicherter Daten laufen
unabhängig davon weiter.

Für einen neuen Bankabruf auf der Bankseite ausdrücklich **Bankzugang freigeben**
wählen. Dieser Vorgang kann TAN-Eingabe oder Push-Bestätigung erfordern und ruft
Konten, Salden, Umsätze und Depotbestände ab. Die Sammelsynchronisation und
gewöhnliche Datenabruf-Endpunkte starten keine neue Freigabe. Ein Institut
entspricht genau einer konfigurierten Bankverbindung, auch bei identischer BLZ.

Konten besitzen einen separaten Schalter für die Teilnahme am Hintergrundabruf.
Er ist unabhängig vom bisherigen Merkmal `tan_manual_approval`; vorhandene Werte
werden nicht in einen Kontoausschluss umgedeutet. Das globale Verbot automatischer
TAN-Anforderungen gilt für beide Schalterstellungen. Der neue Schalter ermöglicht
die Auswahl für einen später nachweislich sicheren Hintergrundabruf; er umgeht
die aktuelle konservative Sperre nicht.

Technische FinTS-Sitzungen sind kein Nachweis einer gültigen Bankfreigabe. Die
Anwendung unterscheidet unbekannten Status, bestätigte Freigabe, erforderliche
Freigabe, laufende manuelle Freigabe und technische Fehler. Eine lokale
Ablauffrist ist nur eine konservative Sicherheitsrichtlinie, keine Zusage der
Bank über TAN-freien Zugriff. Ohne Bankzugriff kann ein früherer bankseitiger
Widerruf nicht erkannt werden.

## MQTT-Freigabestatus

Nach Aktivierung von MQTT in den Einstellungen wird unabhängig vom Saldoexport
ein retained Status unter `<MQTT_TOPIC_PREFIX>/banks/<bank_id>/authorization`
veröffentlicht. Er enthält ausschließlich Verbindungs-ID, Status, maschinenlesbaren
Grund und Zeitpunkte; keine Zugangsdaten, TAN oder Challenge. Home Assistant erhält
passende Discovery-Einträge. Zustandswechsel werden lokal gespeichert und nach
Broker-Ausfällen erneut veröffentlicht, ohne dafür eine Bankverbindung zu öffnen.

Banklöschung und Präfixwechsel werden beim nächsten erfolgreichen MQTT-Lauf auf
dem aktuellen Broker bereinigt. Bei einem Brokerwechsel können retained Daten
auf dem vorherigen, nicht mehr erreichbaren Broker nicht automatisch gelöscht
werden; dort manuell entfernen oder zur Bereinigung zurückverbinden. MQTT muss
für eine solche Bereinigung aktiviert bleiben. Bestehende Saldo-Topics können
weiterhin Finanzdaten enthalten und gehören in einen zugriffsgeschützten Broker.

## Schnellstart

### Mit Docker Compose

1. Repository klonen:
```bash
git clone <repository-url>
cd banking-bridge
```

2. Umgebungsvariablen konfigurieren:
```bash
cp .env.example .env
# Bearbeiten Sie .env nach Bedarf
```

3. Container starten:
```bash
docker-compose up -d
```

4. Web-Oberfläche öffnen:
```
http://localhost:8080
```

## Konfiguration

### Umgebungsvariablen

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `WEB_PORT` | Port für die Web-Oberfläche | `8080` |
| `APP_ENV` | Umgebung (development/production) | `production` |
| `APP_DEBUG` | Debug-Modus | `false` |
| `DATA_PATH` | Datenverzeichnis für PHP-Webapp und CLI | `/data` |
| `MQTT_HOST` | MQTT-Broker-Hostname | `homeassistant.local` |
| `MQTT_PORT` | MQTT-Broker-Port | `1883` |
| `MQTT_USER` | MQTT-Benutzername | - |
| `MQTT_PASSWORD` | MQTT-Passwort | - |
| `MQTT_TOPIC_PREFIX` | Präfix für MQTT-Topics | `banking` |
| `TZ` | Zeitzone | `Europe/Berlin` |

MQTT-Umgebungsvariablen sind Vorgaben; gespeicherte Werte aus den Einstellungen
haben Vorrang, auch explizit leere Werte. MQTT muss zusätzlich in der Oberfläche
aktiviert werden. Ein leeres Passwortfeld erhält das gespeicherte Passwort;
„Passwort löschen“ entfernt es ausdrücklich.

### Daten-Persistenz

Alle Daten werden im Docker-Volume `banking_data` gespeichert:
- SQLite-Datenbank: `/data/banking.db`
- Log-Dateien: `/data/app.log`

## Bank hinzufügen

1. Öffnen Sie die Web-Oberfläche
2. Klicken Sie auf "Bank hinzufügen"
3. Geben Sie die erforderlichen Daten ein:
   - **Bezeichnung**: Ein Name für die Bankverbindung
   - **Bankleitzahl (BLZ)**: 8-stellige Bankleitzahl
   - **FinTS-URL**: Die FinTS-URL Ihrer Bank
   - **Benutzerkennung**: Ihre Online-Banking Benutzerkennung
   - **PIN**: Ihre Online-Banking PIN

### FinTS-URLs finden

Die FinTS-URL Ihrer Bank finden Sie unter:
- [hbci-zka.de](https://www.hbci-zka.de/institute/institut_auswahl.htm)
- Auf der Website Ihrer Bank (Online-Banking Hilfe)

### Bekannte FinTS-URLs

| Bank | BLZ | FinTS-URL |
|------|-----|-----------|
| Sparkasse | variiert | `https://banking-<region>.s-fints-pt-<region>.de/fints30` |
| Volksbank | variiert | `https://fints.gad.de/fints` |
| ING | 50010517 | `https://fints.ing.de/fints` |
| DKB | 12030000 | `https://banking-dkb.s-fints-pt-dkb.de/fints30` |
| Commerzbank | 50040000 | `https://fints.commerzbank.com/` |
| Postbank | 10010010 | `https://banking.postbank.de/rai/login` |

## Technologie-Stack

- **Backend**: PHP 8.2 mit Slim 4 Framework
- **FinTS**: [phpFinTS](https://github.com/nemiah/phpFinTS) Bibliothek
- **Datenbank**: SQLite
- **Frontend**: Twig Templates, Tailwind CSS, Alpine.js
- **Container**: Docker mit Apache

## Projektstruktur

```
/
├── app/
│   ├── composer.json
│   ├── config/
│   │   ├── container.php    # DI Container
│   │   └── routes.php       # Routen-Definition
│   ├── public/
│   │   └── index.php        # Entry Point
│   ├── src/
│   │   ├── Controllers/     # HTTP Controller
│   │   ├── Models/          # Datenmodelle
│   │   └── Services/        # Business Logic
│   └── templates/           # Twig Templates
├── data/                    # Persistente Daten (DB, Logs)
├── docker-compose.yml
├── Dockerfile
├── .env.example
└── README.md
```

## Sicherheitshinweise

- **Lokale Speicherung**: Alle Zugangsdaten werden nur lokal gespeichert
- **Keine Cloud**: Keine Daten werden an externe Server übertragen
- **Verschlüsselung**: Verwenden Sie HTTPS für den Produktionsbetrieb
- **Netzwerk**: Betreiben Sie die Anwendung in einem sicheren Netzwerk
- **Separate PIN**: Erwägen Sie eine separate Banking-PIN für diese Anwendung

Die Anwendung besitzt weiterhin keine eigene Benutzeranmeldung. Ein
authentifizierender Reverse Proxy und ein geschütztes Netzwerk sind notwendig;
CSRF-Schutz ersetzt keine Authentifizierung. Schreibende HTTP-Aufrufe benötigen
das Session-gebundene CSRF-Token als `X-CSRF-Token` oder Formularfeld `csrf_token`.
Die Weboberfläche sendet dieses automatisch. Lesende `/api/v1/`-Endpunkte bleiben
unverändert. Hinter einem TLS-Proxy die HTTPS-Erkennung des Webservers korrekt
konfigurieren, damit Session-Cookies das Secure-Attribut erhalten.

Bankzugänge, technische Sitzungen und laufende TAN-Vorgänge liegen lokal im
Datenvolume; dieses enthält sensible Daten und muss entsprechend geschützt und
gesichert werden. `APP_DEBUG=false` vermeidet ausführliche Produktionsfehler und
Debugprotokolle. Debugbetrieb nicht mit echten Bankdaten verwenden.

## Entwicklung

### Lokal entwickeln

```bash
cd app
composer install
php -S localhost:8080 -t public
```

### Mit Docker (Development)

```bash
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up
```

## Roadmap

- [x] WebUI für Bankverwaltung
- [x] Kontoabruf via FinTS
- [x] TAN-Unterstützung
- [x] MQTT-Integration für Home Assistant
- [x] Scheduler für PayPal und MQTT; sichere Sperre für FinTS
- [x] Manueller Umsatz- und Depotabruf
- [x] Home Assistant Entitäten
- [x] Freigabestatus je Institut
- [ ] Nachweislich TAN-freier FinTS-Hintergrundtransport

## Lizenz

MIT License

## Credits

- [phpFinTS](https://github.com/nemiah/phpFinTS) - PHP FinTS/HBCI Bibliothek
- [firefly-iii-fints-importer](https://github.com/bnw/firefly-iii-fints-importer) - Inspiration für die FinTS-Integration
