# Banking Bridge - FinTS zu Home Assistant

Eine schlanke Web-App zur Verbindung von deutschen Bankkonten (via FinTS/HBCI) mit Home Assistant über MQTT.

## Features

- **FinTS-Integration**: Zugriff auf Bankdaten über das FinTS-Protokoll (phpFinTS)
- **Multi-Bank-Support**: Verwalten Sie mehrere Bankverbindungen
- **TAN-Unterstützung**: Vollständige TAN-Verfahren-Unterstützung (pushTAN, chipTAN, etc.)
- **Moderne WebUI**: Benutzerfreundliche Oberfläche mit Tailwind CSS
- **Docker-Ready**: Einfache Bereitstellung mit Docker Compose
- **SQLite-Datenbank**: Alle Daten lokal und persistent gespeichert
- **MQTT-Export** (geplant): Automatische Übertragung an Home Assistant

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
| `MQTT_HOST` | MQTT-Broker-Hostname | `homeassistant.local` |
| `MQTT_PORT` | MQTT-Broker-Port | `1883` |
| `MQTT_USER` | MQTT-Benutzername | - |
| `MQTT_PASSWORD` | MQTT-Passwort | - |
| `MQTT_TOPIC_PREFIX` | Präfix für MQTT-Topics | `banking` |
| `TZ` | Zeitzone | `Europe/Berlin` |

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
- [ ] MQTT-Integration für Home Assistant
- [ ] Automatischer Kontostand-Abruf (Scheduler)
- [ ] Umsatzabruf
- [ ] Home Assistant Entitäten
- [ ] Benachrichtigungen

## Lizenz

MIT License

## Credits

- [phpFinTS](https://github.com/nemiah/phpFinTS) - PHP FinTS/HBCI Bibliothek
- [firefly-iii-fints-importer](https://github.com/bnw/firefly-iii-fints-importer) - Inspiration für die FinTS-Integration
