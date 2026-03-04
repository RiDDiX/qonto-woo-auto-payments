# Auto Payment Matcher for Qonto

[![Version](https://img.shields.io/badge/version-1.5.0-blue.svg)](https://github.com/RiDDiX/qonto-woo-auto-payments)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-green.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-4.0%2B-purple.svg)](https://woocommerce.com/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-orange.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**English** | [Deutsch](#deutsch)

---

## English

### Overview

A WordPress/WooCommerce plugin that automatically matches incoming bank transfers from your Qonto account (including external accounts like N26, Revolut) with WooCommerce orders. When a payment is detected, the corresponding order status is automatically changed from "On Hold" to "Processing".

### Features

#### Banking Integration
- **Qonto API v2** integration for transaction monitoring
- Support for **internal Qonto accounts** and **external bank accounts** (e.g., N26, Revolut)
- Automatic bank account discovery via API
- Filter by account name or external account flag

#### Order Matching
- **Smart order number detection** in transaction references using configurable regex patterns
- Recognizes order numbers in formats like `Best.Nr.123`, `Rechnung 123`, `Order #123`, `Invoice 123`, `#123` and more
- Extracts order IDs from transaction labels, payment references, notes, and transfer details
- **Name-Matching Fallback**: If no order number found, matches by customer name (billing, shipping, or company name)
  - Supports name in normal and reversed order
  - Requires exact amount match for safety
  - Only auto-matches if exactly one order matches (prevents false positives)
- **Amount verification** with configurable tolerance
- **Currency validation** — transaction currency must match order currency
- **Duplicate payment protection** — orders cannot be matched twice
- **Order date validation** — order must be created before the transaction
- Only processes orders in "On Hold" status

#### Automation
- **Cron-based scheduling** — runs automatically every 6 hours
- Manual test button with **live console output**
- Tracks processed transactions to avoid duplicates

#### Manual Transaction Search
- Search by **order number**, **sender name**, or **amount**
- Configurable time range (7, 30, 90, or 180 days)
- Tabular results with date, sender, amount, reference, and status

#### Order Notes
When a payment is matched, a detailed note is added to the order including transaction amount, dates, sender name, masked IBAN, reference, and transaction ID.

---

### Security

| Feature | Description |
|---------|-------------|
| **AES-256-GCM Encryption** | API secrets encrypted with WordPress salts |
| **HMAC Integrity** | Fallback CBC mode includes SHA-256 HMAC for tamper detection |
| **Rate Limiting** | All endpoints protected (10s for test, 5s for search, 30s for manual run) |
| **Capability Checks** | Requires `manage_woocommerce` + active login |
| **Nonce Validation** | All admin actions protected |
| **Data Masking** | IBANs and transaction IDs masked in responses and order notes |
| **XSS Protection** | All output escaped |
| **SSL Verification** | API calls enforce certificate verification |
| **ReDoS Protection** | User-supplied regex patterns validated against dangerous patterns |
| **Input Sanitization** | All inputs sanitized, `$_SERVER` access secured |

---

### Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the 'Plugins' menu in WordPress
3. Navigate to **WooCommerce → Qonto Zahlungen**
4. Enter your Qonto API credentials (Login + Secret Key)
5. Configure the target bank account name
6. Optional: Enable "Only external accounts"

### Configuration

| Option | Description | Default |
|--------|-------------|---------|
| **API Login** | Qonto organization identifier | — |
| **Secret Key** | Qonto API secret (stored encrypted) | — |
| **Account Name** | Bank account name to monitor | `MEL` |
| **Only External** | Only check external accounts | `false` |
| **Only Completed** | Only match completed transactions | `true` |
| **Order Regex** | Pattern to extract order numbers | Auto-detect |
| **Min Amount** | Minimum transaction amount | `0` (disabled) |
| **Amount Tolerance** | Allowed difference between transaction and order | `0.01 EUR` |
| **Require Amount Match** | Transaction must match order total | Recommended |
| **Name Matching** | Fallback to customer name if no order number | `false` |

### Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+ with OpenSSL
- Qonto Business Account with API access
- HTTPS recommended

---

## Deutsch

### Überblick

Ein WordPress/WooCommerce Plugin, das automatisch eingehende Banküberweisungen von deinem Qonto-Konto (inkl. externer Konten wie N26, Revolut) mit WooCommerce-Bestellungen abgleicht. Bei erkannter Zahlung wird der Bestellstatus automatisch von "Wartestellung" auf "In Bearbeitung" geändert.

### Funktionen

#### Banking-Integration
- **Qonto API v2** Integration für Transaktionsüberwachung
- Unterstützung für **interne Qonto-Konten** und **externe Bankkonten** (z.B. N26, Revolut)
- Automatische Kontoerkennung via API
- Filterung nach Kontoname oder Extern-Flag

#### Bestellzuordnung
- **Intelligente Bestellnummern-Erkennung** im Verwendungszweck mittels konfigurierbarer Regex-Muster
- Erkennt Bestellnummern in Formaten wie `Best.Nr.123`, `Rechnung 123`, `Order #123`, `Invoice 123`, `#123` u.v.m.
- Extrahiert Bestellnummern aus Transaktionsbeschreibungen, Zahlungsreferenzen, Notizen und Überweisungsdetails
- **Name-Matching Fallback**: Wenn keine Bestellnummer gefunden, Zuordnung über Kundenname (Rechnungs-, Liefer- oder Firmenname)
  - Unterstützt Namen in normaler und umgekehrter Reihenfolge
  - Erfordert immer exakten Betragsabgleich
  - Nur automatische Zuordnung wenn genau eine Bestellung passt
- **Betragsabgleich** mit konfigurierbarer Toleranz
- **Währungsvalidierung** — Transaktionswährung muss mit Bestellwährung übereinstimmen
- **Duplikat-Schutz** — Bestellungen können nicht doppelt zugeordnet werden
- **Bestelldatum-Prüfung** — Bestellung muss vor der Transaktion erstellt worden sein
- Verarbeitet nur Bestellungen im Status "Wartestellung"

#### Automatisierung
- **Cron-basierte Planung** — läuft automatisch alle 6 Stunden
- Manueller Test-Button mit **Live-Konsolen-Ausgabe**
- Speichert verarbeitete Transaktionen um Duplikate zu vermeiden

#### Manuelle Transaktionssuche
- Suche nach **Bestellnummer**, **Absendername** oder **Betrag**
- Konfigurierbarer Zeitraum (7, 30, 90 oder 180 Tage)
- Tabellarische Ergebnisse mit Datum, Absender, Betrag, Referenz und Status

#### Bestellnotizen
Bei zugeordneter Zahlung wird eine detaillierte Notiz zur Bestellung hinzugefügt mit Transaktionsbetrag, Daten, Absendername, maskierter IBAN, Referenz und Transaktions-ID.

---

### Sicherheit

| Funktion | Beschreibung |
|----------|-------------|
| **AES-256-GCM Verschlüsselung** | API-Secrets verschlüsselt mit WordPress-Salts |
| **HMAC Integrität** | Fallback CBC-Modus mit SHA-256 HMAC zur Manipulationserkennung |
| **Rate-Limiting** | Alle Endpoints geschützt (10s Test, 5s Suche, 30s manueller Lauf) |
| **Berechtigungsprüfung** | Erfordert `manage_woocommerce` + aktiven Login |
| **Nonce-Validierung** | Alle Admin-Aktionen geschützt |
| **Daten-Maskierung** | IBANs und Transaktions-IDs maskiert in Antworten und Bestellnotizen |
| **XSS-Schutz** | Alle Ausgaben escaped |
| **SSL-Verifikation** | API-Aufrufe erzwingen Zertifikatsprüfung |
| **ReDoS-Schutz** | Benutzerdefinierte Regex werden auf gefährliche Muster validiert |
| **Input-Sanitierung** | Alle Eingaben sanitiert, `$_SERVER`-Zugriffe gesichert |

---

### Installation

1. Plugin-Ordner nach `/wp-content/plugins/` hochladen
2. Plugin über das 'Plugins'-Menü aktivieren
3. Navigiere zu **WooCommerce → Qonto Zahlungen**
4. Qonto API-Zugangsdaten eingeben (Login + Secret Key)
5. Ziel-Kontoname konfigurieren
6. Optional: "Nur externes Konto" aktivieren

### Konfiguration

| Option | Beschreibung | Standard |
|--------|-------------|----------|
| **API Login** | Qonto-Organisations-ID | — |
| **Secret Key** | Qonto API-Secret (verschlüsselt gespeichert) | — |
| **Kontoname** | Name des zu überwachenden Bankkontos | `MEL` |
| **Nur Extern** | Nur externe Konten prüfen | `false` |
| **Nur Completed** | Nur abgeschlossene Transaktionen matchen | `true` |
| **Bestellnummer Regex** | Muster zur Extraktion von Bestellnummern | Auto-Erkennung |
| **Mindestbetrag** | Minimaler Transaktionsbetrag | `0` (deaktiviert) |
| **Betragstoleranz** | Erlaubte Differenz zwischen Transaktion und Bestellung | `0.01 EUR` |
| **Betragsabgleich** | Transaktion muss mit Bestellsumme übereinstimmen | Empfohlen |
| **Name-Matching** | Fallback auf Kundenname wenn keine Bestellnummer | `false` |

### Voraussetzungen

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+ mit OpenSSL
- Qonto Business-Konto mit API-Zugang
- HTTPS empfohlen

---

## Third-Party Service: Qonto API

This plugin connects to the **Qonto Banking API** (`https://thirdparty.qonto.com/v2/`) to retrieve transaction data. This connection is established only when the user explicitly configures their API credentials and either the cron job runs or a manual action is triggered from the admin panel.

**Data transmitted:** API credentials, query parameters (bank account ID, date filters, pagination).
**Data received:** Bank account information, transaction details (amounts, dates, references, sender names).

No user data from your WordPress site is sent to Qonto.

- [Qonto Website](https://qonto.com)
- [Qonto API Documentation](https://api-doc.qonto.com)
- [Qonto Terms of Service](https://qonto.com/en/legal/general-terms-of-service)
- [Qonto Privacy Policy](https://qonto.com/en/legal/privacy-policy)

> "Qonto" is a trademark of Qonto S.A. This plugin is not affiliated with, endorsed by, or sponsored by Qonto S.A.

---

## License

GPL-2.0-or-later

## Author

**RiDDiX** — [riddix.de](https://riddix.de)

---

## Support the Project

If you find this plugin useful, consider supporting its development:

[![GitHub Sponsors](https://img.shields.io/badge/GitHub-Sponsor-ea4aaa?logo=github&style=for-the-badge)](https://github.com/sponsors/RiDDiX)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal&style=for-the-badge)](https://www.paypal.me/RiDDiX93)

---

<p align="center">
  <i>Made with ❤️ for WooCommerce shops using Qonto</i>
</p>
