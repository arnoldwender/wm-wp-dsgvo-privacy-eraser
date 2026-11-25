# WM DSGVO Privacy Eraser

<p align="center">
  <a href="README.md"><img src="https://img.shields.io/badge/Language-English%20(Default)-blue.svg" alt="English Documentation" /></a>
  <a href="README.de.md"><img src="https://img.shields.io/badge/Sprache-Deutsch-lightgrey.svg" alt="Deutsche Dokumentation" /></a>
  <a href="README.es.md"><img src="https://img.shields.io/badge/Idioma-Espa%C3%B1ol-lightgrey.svg" alt="Documentación en Español" /></a>
  <a href="#translated-documentation"><img src="https://img.shields.io/badge/UI%20Translations-28%20Locales-brightgreen.svg" alt="28 UI translation locales" /></a>
</p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.4%20--%207.1-21759b.svg?logo=wordpress&logoColor=white" alt="WordPress Version" /></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4.svg?logo=php&logoColor=white" alt="PHP Version" /></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2%20or%20later-brightgreen.svg" alt="License" /></a>
</p>

> **Takes erasure requests through a public form, acts only after someone with access to the mailbox opens a single-use link and confirms on the page it leads to, then anonymises that address's comments, deletes its non-administrator account (and optionally anonymises WooCommerce billing fields) and records the erasure in an HMAC-chained table — covering WordPress core, WooCommerce and any custom areas registered through its filter, without making a legal assessment of a request**  
> **Plugin page:** [https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/](https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/)  
> **Built with an agentic workflow:** developed with AI coding agents. Review and test it like any third-party code before you use it in production.  
> **Support & Liability:** Zero support — reproducible bugs on [GitHub Issues](https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser/issues) only · no private support · AS IS / no liability ([SUPPORT.md](SUPPORT.md))

> Every route, command, table, option, block and hook documented here is measured directly against the code in this repository: shortcode `[wm_dsgvo_erasure_portal]`, Gutenberg block `wm/dsgvo-erasure-portal`, WP-CLI `wp dsgvo erase` / `verify`, REST `/request`, `/confirm` and `/verify` under `wm-dsgvo-eraser/v1`, tables `wp_wm_dsgvo_requests` and `wp_wm_dsgvo_erasure_certificates`, Block Bindings source `wm-dsgvo-eraser/status`, and GenerateBlocks Dynamic Tag `{{wm_eraser_status}}`.

---

## Showcase

!WM DSGVO Privacy Eraser banner

No marketing mockup from `assets/images/` is embedded (`assets/images/` and `versionen/` remain excluded via `.distignore`); the banner above and the live WordPress screenshots below live in `.wordpress-org/`.

| Overview | Gutenberg Block | Public Portal |
|---|---|---|
| ![Overview tab: erasure records, erased or anonymised entries, open requests, the state of the record chain, libsodium and the two settings](.wordpress-org/screenshot-1.png) | ![Gutenberg editor showing the wm/dsgvo-erasure-portal block](.wordpress-org/screenshot-2.png) | ![Public data erasure request portal with constant anti-enumeration confirmation](.wordpress-org/screenshot-3.png) |

---

## What the plugin does

1. **Request form & Gutenberg block.** `[wm_dsgvo_erasure_portal]` and the compiled Gutenberg block `wm/dsgvo-erasure-portal` (`InspectorControls` + `ServerSideRender`, editor script `wm-dsgvo-eraser-blocks-editor`) render an accessible request box with an optional introductory notice, a labelled email field and a button that posts to `POST /wp-json/wm-dsgvo-eraser/v1/request`. The answer on the page is identical for every syntactically valid address.
2. **Request.** `RestController::check_public_erasure_access()` guards `/request` and `/confirm` with transient-based HMAC-SHA256 blinded-IP rate limiting (`wm_dsgvo_rl_*`, default 30 requests per minute, filterable via `wm_eraser_rest_rate_limit`, returning HTTP 429 when exceeded and bypassed for `manage_options`). Inside `ErasureRequestManager::create()`, requests are also capped silently at 3 per address in 24 hours and 10 per client IP in one hour. Accepted requests store a UUID, keyed HMACs of address and IP (`wp_salt( 'auth' )`), the address encrypted with libsodium (`secretbox`, key derived from `wp_salt( 'secure_auth' )`; cleartext fallback when libsodium is absent), the SHA-256 of a random 64-character token, status `pending` and a 24-hour expiry, and mail `/?wm_dsgvo_erasure=confirm&wm_erasure_request=…&wm_erasure_token=…`. The response is always 202 with one message.
3. **Confirmation.** The mailed link opens a page on the site. Opening it (GET) erases nothing: it resolves only a pending, unexpired request whose token hash matches in constant time and shows what will happen with a POST button; anything else shows that the link is invalid, expired or already used. Only the POST of that button erases: `ErasureRequestManager::claim()` runs a conditional `UPDATE … WHERE status = 'pending'`, decrypts the address, runs the erasure, marks the request `processed` (or `failed` when an area failed, the record was not written or an exception occurred) and clears both the encrypted address and token hash. `GET /wp-json/wm-dsgvo-eraser/v1/confirm` redirects (302) to that page for legacy links.
4. **Erasure & developer hooks.** For that address: comments are rewritten (`Anonymisiert`, `anonymized@gdpr.local`, empty URL, IP `0.0.0.0`, empty user agent, user id 0, replacement text); a non-administrator user account is deleted with `wp_delete_user()` (administrators record `skipped_administrator`; multisite records `removed_from_this_site`); when `wm_dsgvo_anonymize_wc_orders` is enabled, WooCommerce order billing fields are overwritten via `wc_get_orders()` (HPOS and post storage alike); companion plugins can anonymise custom tables via `apply_filters( 'wm_eraser_anonymize_personal_data', [], $clean_email )`, whose counts or `failed` markers fold into the sealed summary; and `do_action( 'wm_eraser_completed', $outcome )` fires with the non-PII outcome.
5. **Erasure records.** Each erasure appends a row to `wp_wm_dsgvo_erasure_certificates` under MySQL lock `wm_dsgvo_cert_chain`: an `ERASE-…` identifier derived from the row digest, a keyed HMAC of the address, the total changed entries, the per-area summary, `prev_hash` and `sha256_audit_seal` (`hmac_sha256` keyed with `wp_salt( 'secure_auth' )`), mirroring the newest seal in `wm_dsgvo_cert_chain_tip`. When WM Sovereign Consent Vault is active, the seal is also anchored there. Verification walks every row and reports `INTACT`, `EMPTY_CHAIN` or `BROKEN` with the first failing row.
6. **Expiry sweep.** An hourly cron event `wm_dsgvo_expire_requests` clears address and token of expired pending requests and marks expired `processing` rows as `failed`; deactivation and uninstallation unschedule it.
7. **Admin & settings.** Menu *Privacy Eraser* (position 86), page `wm-dsgvo-eraser`: *Übersicht*, *Antrag stellen*, *Löschnachweise*, *Was gelöscht wird* and *Einstellungen* (checkboxes registered via the Settings API for `wm_dsgvo_anonymize_wc_orders` and `wm_dsgvo_eraser_delete_data_on_uninstall`), plus an admin dashboard widget.
8. **WP-CLI & REST monitoring.** `wp dsgvo erase <email>` erases immediately after operator identity verification and exits non-zero on failure; `wp dsgvo verify` and `GET /wp-json/wm-dsgvo-eraser/v1/verify` (`manage_options`, HTTP 409 when broken) verify the chain.
9. **Block Bindings, GenerateBlocks & Suite Hub.** `Plugin::boot()` fires `do_action( 'wm_eraser_ready', $this )`. `EcosystemIntegration` registers WP 6.5+ Block Bindings source `wm-dsgvo-eraser/status` and GenerateBlocks 2.x Dynamic Tag `{{wm_eraser_status}}` (filterable via `wm_eraser_status_text`). `Privacy_Eraser_Spoke_Adapter` registers with WM Suite Hub (`wm_register_suite_module`).

### Not shipped

No personal-data export or access report (Art. 15 or 20 DSGVO); no automatic call to WordPress core's privacy erasers; nothing in other plugins' tables unless integrated via `wm_eraser_anonymize_personal_data`; nothing in user meta of kept accounts, shipping fields, files, logs, caches or backups; no downloadable certificate copy for the data subject (the confirmation page displays its record ID); no legal assessment of whether a request is justified, whether Art. 17(3) DSGVO exceptions apply or which statutory retention period governs.

---

## Quick start

1. Install and activate the plugin (WordPress 6.4+, PHP 8.2+, libsodium recommended). Tables are created on activation and the hourly expiry sweep is scheduled on boot.
2. Insert the **DSGVO Löschantrag (Art. 17)** Gutenberg block (`wm/dsgvo-erasure-portal`) or `[wm_dsgvo_erasure_portal]` on your privacy page.
3. Submit a test request with an address you control, open the mailed link, confirm on the page and inspect **Privacy Eraser › Löschnachweise**.
4. Review **Privacy Eraser › Einstellungen**: keep `wm_dsgvo_anonymize_wc_orders` off until you have checked commercial and tax retention duties (§ 147 AO, § 257 HGB), and leave `wm_dsgvo_eraser_delete_data_on_uninstall` off unless you want uninstalling the plugin to drop all erasure records and options.
5. Monitor the chain with `wp dsgvo verify` or `GET /wp-json/wm-dsgvo-eraser/v1/verify`.

---

## Stored state

Four options in `wp_options`:

| Option | Written by | Meaning |
|---|---|---|
| `wm_dsgvo_anonymize_wc_orders` | **Privacy Eraser › Einstellungen** or `wp option update` | include WooCommerce billing fields in an erasure (default `0`; off records `skipped_switch_off`) |
| `wm_dsgvo_eraser_delete_data_on_uninstall` | **Privacy Eraser › Einstellungen** or `wp option update` | opt-in gate for `uninstall.php` (default `0`; when off, uninstall preserves options and both custom tables) |
| `wm_dsgvo_cert_chain_tip` | `ErasureCertificateGenerator::issue_certificate()` | mirrors the newest seal so tail truncation is detectable; never edit manually |
| `wm_dsgvo_schema_version` | activation and `Plugin::maybe_upgrade_schema()` | tracks the installed table schema against `Plugin::SCHEMA_VERSION` |

---

## Developers

Classes, routes, tables, options, hooks, blocks and the hub adapter: **[DEVELOPERS.md](DEVELOPERS.md)**.

```bash
php tests/test-suite.php         # cipher, request, rate limit, chain, erasure, confirmation, block, bridges, admin, CLI, uninstall
php tests/test-pot-freshness.php # every translatable string in PHP, JS and block.json is in the .pot (needs wp-cli)
bash tests/run-mutations.sh      # applies every literal mutation in tests/mutations.php; the suite must fail each time
wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/wp-runtime-eraser.php --user=1
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-erasure-authorization.php --allow-root
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-request-address-at-rest.php --allow-root
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-certificate-chain.php --allow-root
WM_ERASER_TEST=test-erasure-authorization.php wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/wp-run-rolled-back.php --user=1
```

### Known limitations

- Deleting an account with `wp_delete_user()` without reassignment also deletes posts authored by that account.
- Seals, address hashes and pending-address encryption derive keys from `wp-config.php` salts: rotating salts or copying tables to another install invalidates verification and prevents decrypting pending requests (`tests/wp-chain-provenance.php` distinguishes salt changes from row edits).
- Tamper-evidence is bounded by database and filesystem access: someone with database write access can alter `wm_dsgvo_cert_chain_tip` or empty both the table and the option, and someone holding `wp-config.php` can re-seal rows.
- Request rows retain keyed hashes of address and IP (`email_hmac`, `ip_hmac`) after completion or expiry.
- By default (`wm_dsgvo_eraser_delete_data_on_uninstall = 0`), uninstalling the plugin clears the scheduled cron hook and preserves options and both tables so erasure certificates are not destroyed accidentally. Enabling the setting drops both tables and deletes all plugin options on uninstall.
- Translation catalogs cover all 28 supported locales (`.po`, `.mo` and JED `.json` bundles for the block editor).
- On multisite, tables and options are per site, and opt-in uninstallation removes only the current site's tables.

---

## Translated documentation

Translated READMEs live in `docs/translations/README.<lang>.md`; their headings are translated, and the sync tool copies four sections of this file into them in English (the tagline, the quick start, what the plugin does and does not do, compliance). Meanwhile, the plugin UI itself ships complete `.po`, `.mo` and block-editor JED `.json` translations across 28 locales in `languages/`.

| Language (Native) | Locale Code | Language (Native) | Locale Code |
|---|---|---|---|
| [Български](docs/translations/README.bg.md) | `bg_BG` | [Magyar](docs/translations/README.hu.md) | `hu_HU` |
| [Čeština](docs/translations/README.cs.md) | `cs_CZ` | [Italiano](docs/translations/README.it.md) | `it_IT` |
| [Dansk](docs/translations/README.da.md) | `da_DK` | [Lietuvių](docs/translations/README.lt.md) | `lt_LT` |
| [Deutsch](README.de.md) | `de_DE` | [Latviešu](docs/translations/README.lv.md) | `lv_LV` |
| [Ελληνικά](docs/translations/README.el.md) | `el_GR` | [Malti](docs/translations/README.mt.md) | `mt_MT` |
| [English](README.md) | `en_GB` | [Nederlands](docs/translations/README.nl.md) | `nl_NL` |
| [Español](README.es.md) | `es_ES` | [Polski](docs/translations/README.pl.md) | `pl_PL` |
| [Eesti](docs/translations/README.et.md) | `et_EE` | [Português](docs/translations/README.pt.md) | `pt_PT` |
| [Suomi](docs/translations/README.fi.md) | `fi_FI` | [Română](docs/translations/README.ro.md) | `ro_RO` |
| [Français](docs/translations/README.fr.md) | `fr_FR` | [Slovenčina](docs/translations/README.sk.md) | `sk_SK` |
| [Gaeilge](docs/translations/README.ga.md) | `ga_IE` | [Slovenščina](docs/translations/README.sl.md) | `sl_SI` |
| [Hrvatski](docs/translations/README.hr.md) | `hr_HR` | [Svenska](docs/translations/README.sv.md) | `sv_SE` |

---

## Compliance

- **What the plugin establishes:** that someone with access to a mailbox opened a single-use link and confirmed on the site, that the covered WordPress, WooCommerce and filter-registered data for that address were erased or anonymised (or which area failed), and an HMAC-chained record in which edits, insertions and removals are detectable while the recorded chain tip and site salts remain intact. It does not decide whether a request is legally justified, whether Art. 17(3) DSGVO exceptions or statutory retention duties apply, or erase data stored outside its covered scope.
- **Outbound requests:** one confirmation email per accepted request through `wp_mail()`. Nothing else leaves the site.
- **Personal data:** while a request is pending, the email address (encrypted when libsodium is available), keyed hashes of address and IP, and the SHA-256 token hash; after confirmation or expiry, only the keyed hashes remain. Erasure records store a keyed hash of the address, affected counts and timestamps. Uninstalling preserves options and tables by default unless `wm_dsgvo_eraser_delete_data_on_uninstall` is explicitly enabled.

---

## Contribution & Security

- [CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md) · [CHANGELOG.md](CHANGELOG.md)

---

## License

Distributed under the **GNU General Public License v2 or later** (`GPL-2.0-or-later`).  
Copyright (C) 2026 Arnold Wender.  
