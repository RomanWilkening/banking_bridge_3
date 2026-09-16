# Umsetzung und Agentenübergabe

## Verbindliche Anforderungen

- Hintergrundläufe dürfen keine TAN oder Push-Freigabe anfordern. Eine Prüfung
  erst nach der Bankanfrage reicht nicht aus.
- Der bestehende TAN-Schalter wird nicht in einen Kontoausschluss umgedeutet.
  Ein eigener Schalter steuert die Teilnahme am Hintergrundabruf.
- Ein Institut ist **eine konfigurierte Bankverbindung**, identifiziert durch
  `bank_id`; mehrere Logins derselben BLZ bleiben getrennt.
- Nur „Bankzugang freigeben“ darf einen neuen interaktiven Vorgang starten.
- MQTT meldet den Freigabestatus unabhängig vom Kontosaldoexport, ohne TAN,
  Challenge, Zugangsdaten oder serialisierte FinTS-Sitzungen.
- Agentenaufträge, Entscheidungen und verbleibende Prüfungen werden hier
  laufend fortgeschrieben. Keine echten Bankanfragen während der Entwicklung.

## Arbeitspakete und Zuständigkeiten

| Paket | Agent | Dateien/Zuständigkeit | Status |
| --- | --- | --- | --- |
| AP-1/2 | `fints-backend` | FinTS-Service, API-Controller, Routen, Auto-Sync, neue Freigabeservices und Tests | Schutz/Operationen implementiert; abschließende API- und lokale Ablaufprüfungen laufen |
| AP-3 | `mqtt-status` | MQTT-Service, MQTT-Cron, MQTT-Regressionstests | Status/Retry/ACK/Datenschutz getestet; Abgleich mit lokaler Ablaufrichtlinie läuft |
| AP-4 | `authorization-ui` | Templates, Bank-/Konto-/Dashboard-Controller | Oberfläche, CSRF und Währungstrennung getestet; Beschriftung lokaler Ablaufgrenze in Arbeit |
| AP-5 | `financial-integrity` | Datenbankservice, PayPal-Service und Regressionstests | In Arbeit: konservative Buchungsidentität, atomare Speicherung, Migration |
| AP-6 | Hauptagent / `security-audit` | Konfiguration, Dokumentation, Sicherheitsreview, Regressionsergebnisse | CSRF-Befund behoben; Abschlussreview ausstehend |

Dateien haben jeweils genau einen schreibenden Verantwortlichen. Änderungen an
gemeinsamen Schnittstellen werden vor der Umsetzung abgestimmt.

## Schnittstellenvertrag

- `getBankAuthorizationState(bankId)` liefert `bank_id`, `status`, `reason`,
  `authenticated_at`, `required_at`, `updated_at`, `expires_at`.
- Zustände: `unknown`, `authorized`, `required`, `pending`, `error`.
- Zeitpunkte sind UTC; ein technischer Session-Datensatz beweist keine SCA.
- `setBankAuthorizationState(bankId, status, reason)` verändert den
  Statuszeitpunkt nur bei einer tatsächlichen Änderung.
- `background_sync_enabled` ist ein neuer Kontoschalter mit Standard `1`.
  Vorhandene `tan_manual_approval`-Werte bleiben erhalten.
- `POST /api/banks/{id}/authorize` startet ausschließlich auf ausdrückliche
  Benutzeraktion einen manuellen Vorgang.
- `POST /api/accounts/{id}/background-sync` steuert den neuen Kontoschalter.
- MQTT-Zustände verwenden stabile Bankverbindungs-IDs, keine BLZ-Aggregation.

## Technische Ausgangslage

- Abhängigkeiten lokal installiert: phpFinTS 3.7.0, MQTT-Client 1.8.1.
- Bisher keine automatisierte Testsuite vorhanden; fokussierte Regressionstests
  werden ohne neues Testframework ergänzt.
- Lokale FinTS-Patches werden im Docker-Build über die Vendor-Dateien kopiert.
- Ein fehlender oder abgelaufener technischer Zustand darf keinen automatischen
  Login-Fallback mehr auslösen.
- Falls die Bibliothek eine Challenge nicht vor deren Auslösung sicher
  unterdrücken kann, bleibt FinTS im Hintergrund konservativ pausiert.

## Laufende Entscheidungen und Verifikation

- AP-1: phpFinTS 3.7.0 `FinTs::login()` ruft `execute()` auf. Dort wird
  HKTAN-Prozessvariante 2 Schritt 1 vor `sendMessage()` angefügt; `needsTan`
  wird erst anhand der Bankantwort verfügbar. Deshalb kann die bisherige
  nachträgliche Prüfung keine Push-Anforderung verhindern. Hintergrund-FinTS
  wird konservativ vor dem Netzwerkzugriff gesperrt, auch nach manueller
  Freigabe. Gespeicherte Daten bleiben per MQTT/API verfügbar; PayPal läuft
  unabhängig weiter. Ein sicherer TAN-freier Transport ist ein gesonderter
  zukünftiger Erweiterungsauftrag, keine implizite Garantie dieses Umbaus.
- Datenbankvertrag implementiert: persistenter Freigabestatus, unabhängiger
  Hintergrundschalter, UTC-Zeitpunkte, aktivierte Fremdschlüssel und Wartezeit
  bei SQLite-Sperren. Bestehende technische Sitzungen bleiben zunächst
  fachlich `unknown`.
- Lokaler Freigabe-Maximalzeitraum wird von der tatsächlichen Bankfreigabe
  getrennt: konservatives Ablaufdatum ist eine Sicherheitsrichtlinie, keine
  Zusage einer 90 Tage TAN-freien Bankverbindung.
- Sicherheitsreview fand einen CSRF-Pfad über MQTT-Einstellungen. Die
  Hauptanwendung prüft jetzt für schreibende Methoden ein Session-Token;
  Formulare und AJAX werden vom UI-Agenten angepasst. Fehlende/falsche Tokens
  erreichen die Controller nicht. Bankzugänge bleiben ohne eigene
  Benutzerauthentifizierung: ein authentifizierender Reverse Proxy bleibt
  Voraussetzung für einen geschützten Betrieb.
- Konfiguration: `APP_DEBUG` steuert Fehlerdetails; Produktionslogs beginnen
  bei Warnungen. MQTT-Umgebungswerte dienen als Vorgaben, explizite
  Datenbankeinstellungen haben Vorrang. Gespeicherte MQTT-Passwörter werden
  nicht mehr ins Einstellungsformular ausgegeben.
- Verifikation bisher: 43 CSRF-/Konfigurationsprüfungen bestanden; PHP-Syntax
  der Hauptagent-Dateien geprüft; `composer audit` ohne bekannte Advisories.
- Bestehender Docker-Build erfolgreich. Dies war ein Zwischenstand während
  laufender Agentenarbeit, nicht der abschließende Funktionstest aller Änderungen.
- AP-3 hat echte lokale MQTT-Protokolltests mit synthetischem Broker durchgeführt:
  PUBACK-Abwarten, Timeout/Retry, Status-Deduplizierung, unabhängige Verbindungen
  gleicher BLZ, Bereinigung alter Topics und unbekannte/veraltete Salden.
- AP-4 hat PHP-/Twig-Rendering und JavaScript-Verhalten offline geprüft:
  kein TAN-/Polling-Start aus gewöhnlichem Sync, expliziter Start mit Request-ID,
  Challenge-Operations-ID, Abbruch, Wiederaufnahme und CSRF-Felder.
- Das Sicherheitsreview hat nach dem CSRF-Fix keine weiteren Sicherheitslücken
  in den geprüften neuen Freigabeänderungen gemeldet. Dies ist kein Nachweis,
  dass bestehende Zugangsdatenhaltung oder fehlende Benutzeranmeldung sicher sind.

## Abnahmekriterien

- [ ] Hintergrund ohne/mit abgelaufener Sitzung: keine TAN-Anforderung.
- [ ] Kein automatischer Neuversuch bei bekanntem Freigabebedarf.
- [ ] Kontoausschluss unabhängig von TAN-Regeln.
- [ ] Explizite Freigabe, Doppelklick, Abbruch und Ablauf konsistent.
- [ ] Gleichzeitiger Cron-/Webzugriff überschreibt keine aktive Freigabe.
- [ ] TAN bei Saldo/Umsatz/Depot stoppt weitere Fachaufträge im Dialog.
- [ ] Erfolgreiche Teilergebnisse und leere Depotbestände korrekt gespeichert.
- [ ] MQTT-Status unabhängig von Saldoexport, Retry ohne Bankzugriff.
- [ ] Statuswechsel und Löschung bereinigen retained Nachrichten.
- [ ] Gleiche echte Buchungen mit verschiedenen Referenzen bleiben erhalten.
- [ ] Wiederholungsimporte erzeugen keine zusätzlichen Dubletten.
- [ ] Fehlgeschlagener Depotimport erhält den bisherigen Bestand.
- [ ] PayPal-only funktioniert ohne FinTS-Produkt-ID.
- [ ] Dokumentation, Tests und unabhängige Validierung abgeschlossen.
