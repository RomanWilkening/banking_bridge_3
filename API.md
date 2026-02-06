# Banking Bridge API Dokumentation

Diese Dokumentation beschreibt alle verfügbaren API-Endpunkte der Banking Bridge Anwendung.

## Inhaltsverzeichnis

- [Übersicht](#übersicht)
- [Authentifizierung](#authentifizierung)
- [Depot API (v1)](#depot-api-v1)
  - [Alle Depots auflisten](#alle-depots-auflisten)
  - [Einzelnes Depot abrufen](#einzelnes-depot-abrufen)
  - [Depot-Bestände abrufen](#depot-bestände-abrufen)
  - [Alle Bestände abrufen](#alle-bestände-abrufen)
- [Bank API](#bank-api)
  - [Bankverbindung testen](#bankverbindung-testen)
  - [Konten abrufen](#konten-abrufen)
  - [Salden synchronisieren](#salden-synchronisieren)
  - [Alles synchronisieren](#alles-synchronisieren)
  - [Bank-Fähigkeiten abrufen](#bank-fähigkeiten-abrufen)
  - [Aktivitätsprotokoll](#aktivitätsprotokoll)
- [Konto API](#konto-api)
  - [Transaktionen abrufen](#transaktionen-abrufen)
  - [Transaktionen synchronisieren](#transaktionen-synchronisieren)
  - [Depot-Bestände synchronisieren](#depot-bestände-synchronisieren)
- [MQTT API](#mqtt-api)
  - [Verbindung testen](#mqtt-verbindung-testen)
  - [Daten veröffentlichen](#mqtt-daten-veröffentlichen)
  - [MQTT-Konten anzeigen](#mqtt-konten-anzeigen)
  - [MQTT-Export aktivieren](#mqtt-export-aktivieren)
- [Auto-Sync API](#auto-sync-api)
- [TAN-Verfahren](#tan-verfahren)

---

## Übersicht

**Basis-URL:** `http://[host]:[port]/api`

**Antwortformat:** Alle Endpunkte liefern JSON zurück.

**Standard-Antwortstruktur:**
```json
{
  "success": true,
  "message": "Beschreibung des Ergebnisses",
  "data": { ... }
}
```

**Fehler-Antwort:**
```json
{
  "success": false,
  "message": "Fehlerbeschreibung"
}
```

---

## Authentifizierung

Die API verwendet derzeit keine Authentifizierung. Für den Produktiveinsatz sollte die Anwendung in einem geschützten Netzwerk betrieben oder ein Reverse Proxy mit Authentifizierung vorgeschaltet werden.

---

## Depot API (v1)

Die Depot API ermöglicht externen Diensten den Zugriff auf Wertpapierbestände.

### Alle Depots auflisten

Listet alle verfügbaren Depots mit Übersichtsinformationen auf.

**Endpunkt:** `GET /api/v1/depots`

**Antwort:**
```json
{
  "success": true,
  "count": 3,
  "depots": [
    {
      "id": 5,
      "name": "Depot",
      "account_number": "1234567",
      "sub_account": "01",
      "bank": "Consorsbank",
      "bank_code": "76030080",
      "total_value": 45678.90,
      "currency": "EUR",
      "last_update": "2024-01-15 10:30:00"
    },
    {
      "id": 6,
      "name": "Zweitdepot",
      "account_number": "1234567",
      "sub_account": "02",
      "bank": "Consorsbank",
      "bank_code": "76030080",
      "total_value": 12345.67,
      "currency": "EUR",
      "last_update": "2024-01-15 10:30:00"
    }
  ]
}
```

**Beispiel:**
```bash
curl http://localhost:8080/api/v1/depots
```

---

### Einzelnes Depot abrufen

Ruft Details zu einem spezifischen Depot ab.

**Endpunkt:** `GET /api/v1/depots/{id}`

**Parameter:**
| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `id` | integer | Depot-ID |

**Antwort:**
```json
{
  "success": true,
  "depot": {
    "id": 5,
    "name": "Depot",
    "account_number": "1234567",
    "sub_account": "01",
    "bank": "Consorsbank",
    "bank_code": "76030080",
    "total_value": 45678.90,
    "currency": "EUR",
    "last_update": "2024-01-15 10:30:00",
    "holdings_count": 15
  }
}
```

**Fehler (404):**
```json
{
  "success": false,
  "error": "Depot nicht gefunden"
}
```

**Beispiel:**
```bash
curl http://localhost:8080/api/v1/depots/5
```

---

### Depot-Bestände abrufen

Listet alle Wertpapierbestände eines spezifischen Depots auf.

**Endpunkt:** `GET /api/v1/depots/{id}/holdings`

**Parameter:**
| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `id` | integer | Depot-ID |

**Antwort:**
```json
{
  "success": true,
  "depot": {
    "id": 5,
    "name": "Depot",
    "bank": "Consorsbank"
  },
  "count": 3,
  "holdings": [
    {
      "isin": "DE0007664039",
      "wkn": "766403",
      "name": "Volkswagen AG Vorzugsaktien",
      "quantity": 50.0,
      "currency": "EUR",
      "current_price": 108.5000,
      "purchase_price": 95.2000,
      "total_value": 5425.00,
      "profit_loss": 665.00,
      "profit_loss_percent": 13.97,
      "price_date": "2024-01-15",
      "updated_at": "2024-01-15 10:30:00"
    },
    {
      "isin": "IE00B4L5Y983",
      "wkn": "A0RPWH",
      "name": "iShares Core MSCI World UCITS ETF",
      "quantity": 100.0,
      "currency": "EUR",
      "current_price": 85.2400,
      "purchase_price": 72.5000,
      "total_value": 8524.00,
      "profit_loss": 1274.00,
      "profit_loss_percent": 17.57,
      "price_date": "2024-01-15",
      "updated_at": "2024-01-15 10:30:00"
    }
  ]
}
```

**Felder der Holdings:**

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `isin` | string | International Securities Identification Number |
| `wkn` | string | Wertpapierkennnummer |
| `name` | string | Name des Wertpapiers |
| `quantity` | float | Anzahl der Stücke |
| `currency` | string | Währung (z.B. "EUR") |
| `current_price` | float | Aktueller Kurs (4 Dezimalstellen) |
| `purchase_price` | float | Einstandskurs (4 Dezimalstellen) |
| `total_value` | float | Gesamtwert (Menge × Kurs) |
| `profit_loss` | float | Gewinn/Verlust absolut |
| `profit_loss_percent` | float | Gewinn/Verlust in Prozent |
| `price_date` | string | Datum des Kurses |
| `updated_at` | string | Letzte Aktualisierung |

**Beispiel:**
```bash
curl http://localhost:8080/api/v1/depots/5/holdings
```

---

### Alle Bestände abrufen

Listet alle Wertpapierbestände über alle Depots hinweg auf. Unterstützt Filterung.

**Endpunkt:** `GET /api/v1/holdings`

**Query-Parameter (optional):**

| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `isin` | string | Filtert nach ISIN (Teilübereinstimmung, case-insensitive) |
| `wkn` | string | Filtert nach WKN (Teilübereinstimmung, case-insensitive) |
| `name` | string | Filtert nach Wertpapiername (Teilübereinstimmung, case-insensitive) |

**Antwort:**
```json
{
  "success": true,
  "count": 25,
  "holdings": [
    {
      "isin": "DE0007664039",
      "wkn": "766403",
      "name": "Volkswagen AG Vorzugsaktien",
      "quantity": 50.0,
      "currency": "EUR",
      "current_price": 108.5000,
      "purchase_price": 95.2000,
      "total_value": 5425.00,
      "profit_loss": 665.00,
      "profit_loss_percent": 13.97,
      "price_date": "2024-01-15",
      "depot": {
        "id": 5,
        "name": "Depot",
        "number": "1234567",
        "bank": "Consorsbank"
      },
      "updated_at": "2024-01-15 10:30:00"
    }
  ]
}
```

**Beispiele:**
```bash
# Alle Bestände
curl http://localhost:8080/api/v1/holdings

# Nach ISIN filtern
curl "http://localhost:8080/api/v1/holdings?isin=DE0007664039"

# Nach WKN filtern
curl "http://localhost:8080/api/v1/holdings?wkn=766403"

# Nach Name filtern
curl "http://localhost:8080/api/v1/holdings?name=Volkswagen"

# Kombinierte Filter
curl "http://localhost:8080/api/v1/holdings?name=ETF&isin=IE00"
```

---

## Bank API

### Bankverbindung testen

Testet die FinTS-Verbindung zu einer Bank.

**Endpunkt:** `POST /api/banks/test`

**Request Body:**
```json
{
  "bank_code": "76030080",
  "fints_url": "https://fints.consorsbank.de/fints30",
  "username": "kontonummer",
  "password": "pin"
}
```

**Antwort:**
```json
{
  "success": true,
  "message": "Verbindung erfolgreich",
  "tan_modes": [
    {
      "id": 920,
      "name": "pushTAN",
      "is_decoupled": true
    }
  ]
}
```

---

### Konten abrufen

Ruft alle Konten einer Bank ab.

**Endpunkt:** `GET /api/banks/{id}/accounts`

**Hinweis:** Kann eine TAN-Anforderung zurückgeben (siehe [TAN-Verfahren](#tan-verfahren)).

**Antwort:**
```json
{
  "success": true,
  "accounts": [
    {
      "account_number": "1234567",
      "iban": "DE89370400440532013000",
      "bic": "COBADEFFXXX",
      "account_name": "Girokonto",
      "owner_name": "Max Mustermann",
      "account_type": "checking",
      "balance": 1234.56,
      "currency": "EUR"
    }
  ]
}
```

---

### Salden synchronisieren

Aktualisiert die Kontosalden einer Bank.

**Endpunkt:** `POST /api/banks/{id}/balances`

**Antwort:**
```json
{
  "success": true,
  "message": "Kontosalden aktualisiert",
  "updated_count": 3,
  "balances": [
    {
      "iban": "DE89370400440532013000",
      "balance": 1234.56,
      "balance_date": "2024-01-15"
    }
  ]
}
```

---

### Alles synchronisieren

Führt eine vollständige Synchronisierung durch: Salden, Transaktionen und Depot-Bestände.

**Endpunkt:** `POST /api/banks/{id}/sync-all`

**Antwort:**
```json
{
  "success": true,
  "message": "Synchronisierung abgeschlossen",
  "stats": {
    "balances_updated": 3,
    "transactions_new": 15,
    "transactions_updated": 5,
    "holdings_updated": 10,
    "errors": []
  }
}
```

---

### Bank-Fähigkeiten abrufen

Zeigt die FinTS-Fähigkeiten einer Bank an.

**Endpunkt:** `GET /api/banks/{id}/capabilities`

**Query-Parameter:**
| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `refresh` | string | `1` um Cache zu ignorieren |

**Antwort:**
```json
{
  "success": true,
  "capabilities": {
    "bank_name_from_bpd": "Consorsbank",
    "bpd_version": 220,
    "supports_psd2": true,
    "mt940_supported": true,
    "camt_supported": true,
    "transactions_supported": true,
    "supports_balance": true,
    "read": {
      "transactions_mt940": {
        "supported": true,
        "available": true,
        "bank_versions": [4, 5, 6, 7],
        "library_versions": [4, 5, 6, 7]
      },
      "depot": {
        "supported": true,
        "available": true,
        "bank_versions": [1, 2, 3, 4, 5, 6, 7],
        "library_versions": [5, 6, 7]
      }
    },
    "tan_modes": [
      { "id": 920, "name": "pushTAN", "is_decoupled": true }
    ]
  },
  "cached": true,
  "cache_age": 3600
}
```

---

### Aktivitätsprotokoll

Ruft das Aktivitätsprotokoll einer Bank ab.

**Endpunkt:** `GET /api/banks/{id}/activity-log`

**Query-Parameter:**
| Parameter | Typ | Standard | Beschreibung |
|-----------|-----|----------|--------------|
| `limit` | integer | 100 | Maximale Anzahl Einträge (10-100) |

**Antwort:**
```json
{
  "success": true,
  "activities": [
    {
      "id": 123,
      "action": "sync_all",
      "status": "success",
      "message": "Sync abgeschlossen: 3 Salden, 15 neue TX, 10 Wertpapiere",
      "account_name": null,
      "created_at": "2024-01-15 10:30:00",
      "details": {
        "balances_updated": 3,
        "transactions_new": 15
      }
    }
  ],
  "count": 50
}
```

---

## Konto API

### Transaktionen abrufen

Ruft gespeicherte Transaktionen eines Kontos ab.

**Endpunkt:** `GET /api/accounts/{id}/transactions`

**Query-Parameter:**
| Parameter | Typ | Standard | Beschreibung |
|-----------|-----|----------|--------------|
| `limit` | integer | 30 | Maximale Anzahl (1-100) |
| `offset` | integer | 0 | Überspringen der ersten N Einträge |

**Antwort:**
```json
{
  "success": true,
  "transactions": [
    {
      "id": 456,
      "transaction_id": "abc123",
      "booking_date": "2024-01-15",
      "valuta_date": "2024-01-15",
      "amount": -49.99,
      "currency": "EUR",
      "name": "Amazon EU S.a.r.l.",
      "description": "Bestellung 123-456-789",
      "iban": "LU123456789",
      "booking_text": "SEPA-Lastschrift"
    }
  ],
  "total": 150,
  "limit": 30,
  "offset": 0
}
```

---

### Transaktionen synchronisieren

Synchronisiert Transaktionen von der Bank.

**Endpunkt:** `POST /api/accounts/{id}/sync`

**Request Body (optional):**
```json
{
  "from": "2024-01-01",
  "to": "2024-01-31"
}
```

**Standard:** Letzte 30 Tage

**Antwort:**
```json
{
  "success": true,
  "message": "Transaktionen synchronisiert",
  "new_count": 15,
  "updated_count": 5,
  "count": 20,
  "balance": 1234.56
}
```

---

### Depot-Bestände synchronisieren

Synchronisiert Wertpapierbestände eines Depots.

**Endpunkt:** `POST /api/accounts/{id}/depot`

**Antwort:**
```json
{
  "success": true,
  "message": "Depotbestand synchronisiert",
  "count": 15,
  "total_value": 45678.90
}
```

---

## MQTT API

### MQTT-Verbindung testen

Testet die Verbindung zum MQTT-Broker.

**Endpunkt:** `POST /api/mqtt/test`

**Antwort:**
```json
{
  "success": true,
  "message": "Verbindung zu homeassistant.local:1883 erfolgreich"
}
```

---

### MQTT-Daten veröffentlichen

Veröffentlicht alle aktivierten Kontosalden an MQTT.

**Endpunkt:** `POST /api/mqtt/publish`

**Antwort:**
```json
{
  "success": true,
  "message": "9 Konto(en) veröffentlicht",
  "published": 9,
  "errors": [],
  "details": [
    {
      "account_id": 1,
      "name": "Girokonto",
      "bank": "Sparkasse",
      "topic": "banking/sparkasse/girokonto_1",
      "balance": 1234.56,
      "status": "ok"
    }
  ]
}
```

---

### MQTT-Konten anzeigen

Zeigt alle für MQTT aktivierten Konten (für Debugging).

**Endpunkt:** `GET /api/mqtt/accounts`

**Antwort:**
```json
{
  "success": true,
  "count": 9,
  "accounts": [
    {
      "id": 1,
      "bank_id": 1,
      "bank_name": "Sparkasse",
      "account_name": "Girokonto",
      "account_type": "checking",
      "iban": "DE89370400440532013000",
      "balance": 1234.56,
      "balance_is_null": false,
      "balance_type": "double",
      "currency": "EUR",
      "mqtt_export": 1
    }
  ]
}
```

---

### MQTT-Export aktivieren

Aktiviert oder deaktiviert den MQTT-Export für ein Konto.

**Endpunkt:** `POST /api/accounts/{id}/mqtt-export`

**Request Body:**
```json
{
  "enabled": true
}
```

**Antwort:**
```json
{
  "success": true,
  "message": "MQTT-Export aktiviert",
  "mqtt_export": true
}
```

---

## Auto-Sync API

### Auto-Sync ausführen

Führt eine automatische Synchronisierung aller Banken durch.

**Endpunkt:** `POST /api/auto-sync/run`

**Hinweis:** Banken, die eine TAN erfordern, werden übersprungen.

**Antwort:**
```json
{
  "success": true,
  "message": "2 Bank(en) synchronisiert, 1 übersprungen. 6 Salden, 30 neue Transaktionen, 15 Wertpapiere.",
  "stats": {
    "banks_synced": 2,
    "banks_skipped": 1,
    "balances_updated": 6,
    "transactions_new": 30,
    "holdings_updated": 15,
    "errors": []
  },
  "results": {
    "Consorsbank": {
      "status": "success",
      "stats": { ... }
    },
    "Sparkasse": {
      "status": "skipped",
      "reason": "TAN erforderlich"
    }
  }
}
```

---

### Auto-Sync Status

Ruft den Status der automatischen Synchronisierung ab.

**Endpunkt:** `GET /api/auto-sync/status`

**Antwort:**
```json
{
  "success": true,
  "enabled": true,
  "interval": 30,
  "last_run": "15.01.2024 10:30"
}
```

---

## TAN-Verfahren

Viele FinTS-Operationen können eine TAN erfordern. In diesem Fall wird folgende Antwort zurückgegeben:

```json
{
  "success": false,
  "needs_tan": true,
  "tan_request": {
    "challenge": "Bitte bestätigen Sie die Anfrage in Ihrer Banking-App",
    "tan_medium": "iPhone von Max",
    "is_decoupled": true
  }
}
```

### Decoupled TAN (z.B. pushTAN)

Bei decoupled TAN-Verfahren muss die Bestätigung in der Banking-App erfolgen. Der Status kann über folgenden Endpunkt abgefragt werden:

**Endpunkt:** `POST /api/banks/{id}/decoupled`

**Antworten:**

*Noch ausstehend:*
```json
{
  "status": "pending"
}
```

*Bestätigt:*
```json
{
  "success": true,
  "message": "...",
  ...
}
```

### Klassische TAN-Eingabe

Bei klassischen TAN-Verfahren (iTAN, mTAN, chipTAN) muss die TAN übermittelt werden:

**Endpunkt:** `POST /api/banks/{id}/tan`

**Request Body:**
```json
{
  "tan": "123456"
}
```

---

## Fehlerbehandlung

### HTTP Status Codes

| Code | Bedeutung |
|------|-----------|
| 200 | Erfolg |
| 400 | Ungültige Anfrage |
| 404 | Ressource nicht gefunden |
| 500 | Server-Fehler |

### Typische Fehler

**Bank nicht gefunden:**
```json
{
  "success": false,
  "message": "Bank nicht gefunden"
}
```

**Fehlende Parameter:**
```json
{
  "success": false,
  "message": "Alle Felder müssen ausgefüllt sein"
}
```

**FinTS-Fehler:**
```json
{
  "success": false,
  "message": "FinTS-Fehler: Die Nachricht enthält Fehler"
}
```

---

## Beispiele

### Python

```python
import requests

BASE_URL = "http://localhost:8080/api/v1"

# Alle Depots abrufen
response = requests.get(f"{BASE_URL}/depots")
depots = response.json()["depots"]

for depot in depots:
    print(f"{depot['name']} ({depot['bank']}): {depot['total_value']} EUR")

# Holdings eines Depots
depot_id = depots[0]["id"]
response = requests.get(f"{BASE_URL}/depots/{depot_id}/holdings")
holdings = response.json()["holdings"]

for h in holdings:
    print(f"{h['name']} ({h['isin']}): {h['quantity']} Stück @ {h['current_price']} EUR")
```

### JavaScript / Node.js

```javascript
const BASE_URL = 'http://localhost:8080/api/v1';

// Alle Holdings abrufen
fetch(`${BASE_URL}/holdings`)
  .then(res => res.json())
  .then(data => {
    data.holdings.forEach(h => {
      console.log(`${h.name} (${h.isin}): ${h.quantity} @ ${h.current_price} EUR`);
    });
  });
```

### cURL

```bash
# Alle Depots
curl -s http://localhost:8080/api/v1/depots | jq

# Holdings nach ISIN filtern
curl -s "http://localhost:8080/api/v1/holdings?isin=IE00B4L5Y983" | jq

# MQTT veröffentlichen
curl -X POST http://localhost:8080/api/mqtt/publish | jq
```

---

## Home Assistant Integration

Die Banking Bridge unterstützt Home Assistant über MQTT Auto-Discovery.

### Konfiguration

1. In den Einstellungen MQTT aktivieren und konfigurieren
2. Bei jeder Bank die gewünschten Konten für MQTT aktivieren (Toggle)
3. Die Sensoren erscheinen automatisch in Home Assistant

### MQTT Topics

**Discovery:** `homeassistant/sensor/banking_{account_id}/config`

**State:** `{prefix}/{bankname}/{kontoname}_{account_id}`

### Sensor-Attribute

Jeder Sensor hat folgende Attribute:
- `balance` - Aktueller Kontostand
- `currency` - Währung
- `account_name` - Kontoname
- `account_type` - `checking` oder `depot`
- `bank` - Bankname
- `iban` - IBAN (falls vorhanden)
- `last_update` - Letzte Aktualisierung

### Beispiel-Automatisierung

```yaml
automation:
  - alias: "Warnung bei niedrigem Kontostand"
    trigger:
      - platform: numeric_state
        entity_id: sensor.girokonto
        below: 500
    action:
      - service: notify.mobile_app
        data:
          message: "Kontostand unter 500 EUR!"
```
