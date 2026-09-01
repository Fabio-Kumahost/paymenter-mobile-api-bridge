# Mobile API Bridge für Paymenter

Separat installierbare Paymenter-Erweiterung, die die kundenseitige
REST-API (`/api/mobile/v1/*`) bereitstellt, die Paymenters offizielle
Kern-API nicht hat: eigenes Profil, Katalog, Warenkorb, gehosteter
Checkout, eigene Bestellungen/Services/Rechnungen/Guthaben/Tickets sowie
optionalen delegierten Admin-Zugriff.

Wird von der nativen iOS-App **PaymenterMobile** benötigt. Ohne diese
Erweiterung läuft die App nur im eingeschränkten Admin-API-Modus (kein
Kunden-Login, kein Shop).

Version: 0.1.1 — siehe [CHANGELOG.md](CHANGELOG.md).

## Voraussetzungen

- Paymenter ≥ 1.5.8 (getestet), PHP 8.5, Laravel Passport (bereits Teil
  von Paymenter)
- Admin-Zugriff auf den Server (SSH/SFTP) und auf das Paymenter-Adminpanel

## Installation

Vom Wurzelverzeichnis deiner Paymenter-Installation aus:

```bash
git clone https://github.com/Fabio-Kumahost/paymenter-mobile-api-bridge.git /tmp/mobile-api-bridge \
  && mkdir -p extensions/Others \
  && cp -R /tmp/mobile-api-bridge/extensions/Others/MobileAPIBridge extensions/Others/MobileAPIBridge \
  && rm -rf /tmp/mobile-api-bridge
```

Danach im Paymenter-Adminpanel unter **Extensions** die **"Mobile API
Bridge"** suchen und installieren/aktivieren. Das löst automatisch aus:

- Bereitstellung eines dedizierten öffentlichen (nicht-confidential)
  OAuth-PKCE-Clients über Passports eigene `ClientRepository` — kein
  Client-Secret, keine eigene Auth-Infrastruktur
- Registrierung der `/api/mobile/v1/*`-Routen

## Konfiguration

In den Erweiterungseinstellungen:

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Kunden-API aktivieren | an | Schaltet `/api/mobile/v1/*` für Kunden komplett frei/dicht (fail-closed 404) |
| Delegierte Admin-API aktivieren | aus | Erlaubt Kunden mit echten Paymenter-Admin-Rechten, denselben OAuth-Token für eingeschränkte Admin-Funktionen zu nutzen — serverseitig gegen die echte Rolle geprüft, nie aus einem Client-Flag abgeleitet |

## Update

Denselben Installationsbefehl erneut ausführen (überschreibt die
Extension-Dateien), danach im Adminpanel die Erweiterung einmal aus- und
wieder einschalten, damit ggf. neue Migrationen laufen.

**Wichtig:** Danach den PHP-FPM-Dienst neu starten, damit der PHP-OPcache
den geänderten Code tatsächlich neu lädt (sonst führt der Server auf
manchen Setups stillschweigend weiter die alte, im Speicher gecachte
Version aus, obwohl die Dateien auf der Festplatte bereits aktuell sind
— live so beobachtet). Der Dienstname hängt von der PHP-Version ab, z. B.:

```bash
sudo systemctl restart php8.4-fpm
```

Den tatsächlichen Namen bei Unsicherheit ermitteln mit:

```bash
systemctl list-units --type=service | grep -i php
```

## Sicherheit

- Kein Client-Secret in der mobilen App — der provisionierte OAuth-Client
  ist ein öffentlicher PKCE-Client (RFC 7636 S256)
- Jede Kundenabfrage ist über `Auth::user()`-Relationen gescoped, nie über
  eine vom Client übergebene ID
- Preise/Steuern/Rabatte werden ausschließlich serverseitig berechnet
- `include=`-Query-Parameter werden auf allen Bridge-Routen aktiv entfernt
  (`StripUnsafeIncludes`-Middleware), um versehentliches Auslecken interner
  Relationen (z. B. versteckte Produkte, Support-Mitarbeiter-Daten) über
  Paymenters JSON:API-Ressourcen zu verhindern
- Checkout läuft ausschließlich über eine kurzlebige (5 Minuten),
  einmalig verwendbare signierte URL im Systembrowser des Nutzers — nie
  eingebettet, nie mit gespeicherten Zahlungsdaten in der App

Details: siehe `tests/Unit/BridgeSecurityInvariantTest.php` für die
verifizierten Sicherheits-Invarianten.

## Lizenz

MIT
