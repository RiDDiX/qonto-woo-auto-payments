=== Auto Payment Matcher for Qonto ===
Contributors: riddix
Tags: woocommerce, payments, bank-transfer, automation, order-management
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically matches incoming Qonto bank transfers (including external accounts like N26) with WooCommerce orders and updates their status.

== Description ==

This WordPress/WooCommerce plugin automatically matches incoming bank transfers from your Qonto account (including external accounts like N26, Revolut) with WooCommerce orders. When a payment is detected, the corresponding order status is automatically changed from "On Hold" to "Processing".

= Key Features =

* **Qonto API v2** integration for transaction monitoring
* Support for **internal Qonto accounts** and **external bank accounts** (e.g., N26, Revolut)
* **Smart order number detection** in transaction references using configurable regex patterns
* Recognizes order numbers in formats like: `Best.Nr.123`, `Rechnung 123`, `Order #123`, `Invoice 123`, `#123` and more
* **Name-Matching Fallback**: If no order number found, matches by customer name (requires exact amount match)
* **Company name matching** support
* **Amount verification** with configurable tolerance (prevents false matches)
* **Currency validation** — transaction currency must match order currency
* **Duplicate payment protection** — orders cannot be matched twice
* **Order date validation** — order must be created before transaction
* **Manual transaction search** — search by order number, sender name, or amount
* **Live console** for manual testing with real-time output
* **Cron-based scheduling** — runs automatically every 6 hours
* Only processes orders in "On Hold" status

= Security =

* AES-256-GCM encrypted API secrets (with WordPress salts)
* HMAC integrity verification (fallback CBC mode)
* Rate limiting on all AJAX endpoints
* Strict capability checks (`manage_woocommerce` + active login)
* Nonce validation on all admin actions
* Sensitive data masking (IBANs, transaction IDs)
* Generic error messages to clients (details logged internally)
* XSS protection — all output escaped
* SSL verification enforced on API calls
* ReDoS protection for user-supplied regex patterns

= Third-Party Service: Qonto API =

This plugin connects to the **Qonto Banking API** (`https://thirdparty.qonto.com/v2/`) to retrieve transaction data. This connection is established only when the user explicitly configures their API credentials and either the cron job runs or a manual test/search is triggered from the admin panel.

**Data transmitted to Qonto:**

* API authentication credentials (login + secret key)
* Query parameters (bank account ID, date filters, pagination)

**Data received from Qonto:**

* Bank account information (names, IBANs, status)
* Transaction details (amounts, dates, references, sender names)

No user data from your WordPress site is sent to Qonto. The plugin only reads transaction data to match against existing WooCommerce orders.

* [Qonto Website](https://qonto.com)
* [Qonto API Documentation](https://api-doc.qonto.com)
* [Qonto Terms of Service](https://qonto.com/en/legal/general-terms-of-service)
* [Qonto Privacy Policy](https://qonto.com/en/legal/privacy-policy)

"Qonto" is a trademark of Qonto S.A. This plugin is not affiliated with, endorsed by, or sponsored by Qonto S.A.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **WooCommerce → Qonto Zahlungen**
4. Enter your Qonto API credentials (Login + Secret Key)
5. Configure the target bank account name (e.g., "MEL" for external N26)
6. Optional: Enable "Only external accounts" checkbox

== Frequently Asked Questions ==

= What bank accounts are supported? =

The plugin supports all bank accounts connected to your Qonto organization, including internal Qonto accounts and external accounts (N26, Revolut, etc.) linked via Qonto's external account feature.

= How does order matching work? =

The plugin extracts order numbers from transaction references (label, note, reference fields) using configurable regex patterns. If no order number is found and name-matching is enabled, it falls back to matching by customer name combined with exact amount verification.

= Is my API key stored securely? =

Yes. API secrets are encrypted using AES-256-GCM with WordPress salts as the encryption key. The plaintext secret is never stored in the database.

= How often does the plugin check for new payments? =

By default, the cron job runs every 6 hours. You can also trigger a manual check from the settings page at any time.

= What happens if multiple orders match? =

For order number matching: each matched order is updated individually. For name matching: if multiple orders match the same name and amount, no automatic assignment is made to prevent false positives. A warning is logged instead.

= Does this plugin work with WooCommerce HPOS? =

Yes, the plugin uses WooCommerce's standard order API (`wc_get_order`, `wc_get_orders`) which is compatible with both the legacy post-based storage and High-Performance Order Storage (HPOS).

== Screenshots ==

1. Settings page with API configuration
2. Live console showing transaction matching
3. Manual transaction search with results

== Changelog ==

= 1.5.0 =
* Security: ReDoS protection for user-supplied regex patterns
* Security: Duplicate payment protection via order meta tracking
* Security: Currency validation (transaction currency must match order currency)
* Security: Order date validation (order must be created before transaction)
* Security: IBAN masking in WooCommerce order notes
* Security: Rate limiting for manual run endpoint
* Security: Removed insecure legacy Base64 decryption support
* Security: Whitelist validation for search type parameter
* Security: Proper regex error handling (removed error suppressor)
* Security: Sanitized $_SERVER superglobal access
* Feature: Company name matching in name-matching fallback
* Feature: Stricter partial match criteria (minimum 7 characters)
* Fix: DoS protection — database queries capped at 200 results
* Fix: Text domain corrected to match plugin slug
* Fix: Complete plugin header for WordPress.org compliance

= 1.4.0 =
* Feature: Extended order number regex patterns
* Feature: Name-Matching fallback (customer name + exact amount)
* Improvement: Only auto-assigns when exactly one order matches
* Improvement: Enhanced order notes showing match type

= 1.3.0 =
* Feature: Manual transaction search (by order number, sender name, or amount)
* Feature: Search filters and configurable time range (7-180 days)
* Feature: Tabular results display
* Security: Rate limiting for search endpoint

= 1.2.0 =
* Security: AES-256-GCM encryption for API secrets
* Security: HMAC integrity verification
* Security: Rate limiting for AJAX endpoints
* Security: Masked sensitive data in responses
* Security: Obfuscated API error messages
* Security: Admin panel security warnings

= 1.1.0 =
* Feature: Support for external bank accounts (N26, etc.)
* Feature: "Only external accounts" option
* Improvement: Switched to /v2/bank_accounts API

= 1.0.0 =
* Initial release
* Qonto API v2 integration
* Automatic order matching
* Live console for testing

== Upgrade Notice ==

= 1.5.0 =
Major security update with ReDoS protection, duplicate payment prevention, currency validation, and WordPress.org guidelines compliance. Recommended for all users.
