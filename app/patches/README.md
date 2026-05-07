# `app/patches/` — Lokale Patches für `nemiah/php-fints`

Dieses Verzeichnis enthält **lokale Kopien einzelner Dateien aus `vendor/nemiah/php-fints/lib/`**, die im Docker-Build (siehe `Dockerfile`) **nach** dem `composer install` über die Originale **drüberkopiert** werden. Anschließend wird `composer dump-autoload` neu aufgerufen.

Layout (1:1 Spiegelung des Vendor-Pfads):

```
app/patches/Fhp/<...>/Foo.php
   → kopiert nach
vendor/nemiah/php-fints/lib/Fhp/<...>/Foo.php
```

> ⚠️  Diese Dateien werden nicht von Composer verwaltet. Bei jedem `composer update nemiah/php-fints` muss geprüft werden, ob die Upstream-Datei sich verändert hat — falls ja, Patch neu auf die neue Version anwenden oder ggf. ganz entfernen, wenn das Problem upstream gelöst ist.

---

## Patch-Inventar

### 1. `Fhp/Segment/WPD/HIWPDSv6.php`, `HIWPDv6.php`, `HKWPDv6.php` &nbsp;·&nbsp; `Fhp/Action/GetDepotAufstellung.php` &nbsp;·&nbsp; `Fhp/MT535/MT535.php`

- **Zweck:** Depot-Abruf (HKWPD/HIWPD v6) und erweiterte MT535-Parsing.
- **Begründung:** phpFinTS hat (zumindest in v3.7.0) keine eigene Implementierung dafür.
- **Entfernen, wenn:** Upstream eine eigene HIWPDSv6/HKWPDv6-Implementierung mitbringt.

### 2. `Fhp/Model/StatementOfAccount/StatementOfAccount.php`

- **Zweck:** MT940 Balance-Sign-Fix.
- **Entfernen, wenn:** Upstream den Sign-Bug behoben hat.

### 3. `Fhp/Protocol/BPD.php` &nbsp;·&nbsp; **NEU 2026-05**

- **Zweck:** Fix für [`nemiah/phpFinTS#554`](https://github.com/nemiah/phpFinTS/issues/554) — *"The bank does not support PSD2."* gegenüber Consorsbank seit 25.04.2026.
- **Was wurde geändert:**
  - `supportsPsd2()` akzeptiert jetzt **HITANSv6 ODER HITANSv7** (Original: nur v6).
  - `supportsParameters()` ist gegen den `Undefined array key`-Warning gehärtet, der bei fehlendem Segment-Typ auftritt.
  - **Sonst nichts** — alle anderen Methoden sind byte-identisch zum Upstream.
- **Warum geht das problemlos:** `nemiah/php-fints` v3.7.0 enthält bereits die kompletten v7-Parser (`HITANSv7`, `HKTANv7`, `ParameterZweiSchrittTanEinreichungV7`, `VerfahrensparameterZweiSchrittVerfahrenV7`); der nachgelagerte Code in `BPD::extractFromResponse()` und `FinTs::getTanModes()` arbeitet bereits über das `HITANS`-Interface, das beide Versionen implementieren. Es fehlt einzig der hartkodierte `v6`-Check.
- **Diff zum Upstream-Original:**
  ```bash
  diff -u app/vendor/nemiah/php-fints/lib/Fhp/Protocol/BPD.php app/patches/Fhp/Protocol/BPD.php
  ```
  Erwartete Hunks: Header-Kommentar, `supportsParameters` (Null-Coalescing), `supportsPsd2` (v6 || v7).
- **Entfernen, wenn:**
  1. Ein Release von `nemiah/php-fints` erscheint, der #554 fixt (Stand der Diskussion: Philipp91 hat im Issue angekündigt, einen Fix zu schicken).
  2. Dann:
     ```bash
     # 1. Vendor aktualisieren
     cd app && composer update nemiah/php-fints

     # 2. Sicherstellen, dass die Upstream-Version supportsPsd2() für v6+v7 (oder allgemeiner) abdeckt
     grep -n "supportsPsd2\|supportsParameters('HITANS'" vendor/nemiah/php-fints/lib/Fhp/Protocol/BPD.php

     # 3. Patch entfernen
     rm app/patches/Fhp/Protocol/BPD.php

     # 4. Dockerfile-Zeile entfernen
     #    (COPY app/patches/Fhp/Protocol/BPD.php ... )

     # 5. Defensive User-Facing-Meldungen in app/src/Services/FinTSService.php aufräumen
     #    selectTanMode() / getBankCapabilities() — die Sonderbehandlung
     #    "does not support PSD2" wird dann nicht mehr ausgelöst und kann weg.
     ```

---

## Verifikation, dass alle Patches angewendet wurden

Im laufenden Container:

```bash
docker compose exec banking-bridge \
  grep -c "BANKING-BRIDGE LOCAL PATCH" \
  /var/www/html/vendor/nemiah/php-fints/lib/Fhp/Protocol/BPD.php
# → 1
```

Im Build-Log nach `RUN composer dump-autoload`:

```
Generating optimized autoload files
Generated optimized autoload files containing N classes
```

Wenn ein Patch **nicht** kopiert wurde (z. B. weil eine Datei umbenannt wurde, oder der Vendor-Pfad sich geändert hat), würde `docker build` an der betreffenden `COPY`-Zeile nicht fehlschlagen — aber der Patch wäre wirkungslos. Daher bei jedem Vendor-Update verifizieren!
