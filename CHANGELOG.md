# Changelog — WM DSGVO Privacy Eraser

All notable changes to **WM DSGVO Privacy Eraser** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Switched GenerateBlocks 2.x Dynamic Tag filtering from `generateblocks_dynamic_tag_output` to `generateblocks_dynamic_tag_replacement` with `mixed` safety.
- Renamed `RestController::NAMESPACE` to `REST_NAMESPACE` for PHP 8.5/8.6 compatibility.

## [1.4.2] - 2026-11-21

### Fixed
- Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu, and its caption now says what the screen shows: the overview tab with the counts, the state of the record chain, libsodium and the two settings, not an erasure queue with link status and a certificate log. The German caption of screenshot 3 reads «zum Schutz» instead of «zur Schutz». The plugin's code is unchanged.

## [1.4.1] - 2026-11-21

### Changed
- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
- The translation catalogs are regenerated from the code: the `.pot` header names the current version, and the Author URI entry follows the plugin header (https://arnoldwender.com instead of the www address). The block editor's JavaScript catalogs now carry the block title, description and keywords under the `block title`, `block description` and `block keyword` contexts the editor looks them up with, and they no longer carry the confirmation mail text, which only PHP sends.

## [1.4.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.

### Fixed
- The placeholder of the e-mail field in the request form pointed at a real, registered domain; it now uses the reserved example.com (RFC 2606), and its translations in the 28 catalogs carry the same domain.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.3.4] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/`, `https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.3.3] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`.

### Changed
- Archived superseded early banners and screenshots non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata and SBOM (`bom.json`) to `1.3.3`.

---

## [1.3.2] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/` and align release metadata at `v1.3.2`.

---

## [1.3.1] - 2026-11-18

### Fixed
- **Multisite Network Activation & New-Site Initialization (`R2-B8`):** Supported `$network_wide` in `register_activation_hook()` across all sites via `get_sites( [ 'fields' => 'ids', 'number' => 0 ] )` and hooked `wp_initialize_site` so new sites created while the plugin is network-activated (`is_plugin_active_for_network()`) initialize their custom tables and schema version inside `switch_to_blog()` / `try ... finally { restore_current_blog(); }`.
- **Non-Autoloaded Schema Option (`R2-B8`):** Passed explicit `$autoload = false` when writing `wm_dsgvo_schema_version` on activation, site initialization, and `Plugin::maybe_upgrade_schema()`.

---

## [1.3.0] - 2026-11-16

### Added
- **Compiled Gutenberg Block (`wm/dsgvo-erasure-portal`):** Added `blocks/dsgvo-erasure-portal/block.json`, `blocks-src/index.js`, `build/index.js`, and `build/index.asset.php` with `InspectorControls` (`showDescription` toggle), live `ServerSideRender` preview, and `wp_set_script_translations()` registration on handle `wm-dsgvo-eraser-blocks-editor`.
- **Admin Settings Tab & Opt-in Uninstall (`D-UNINSTALL`):** Registered `wm_dsgvo_anonymize_wc_orders` and `wm_dsgvo_eraser_delete_data_on_uninstall` (both default `0`) via the WordPress Settings API on the new **Privacy Eraser › Einstellungen** tab. `uninstall.php` clears the scheduled expiry sweep and preserves plugin options and erasure records by default unless `wm_dsgvo_eraser_delete_data_on_uninstall` is enabled.
- **Ecosystem & Developer Extensibility:** Added `do_action( 'wm_eraser_ready', $this )`, `do_action( 'wm_eraser_completed', $outcome )`, `apply_filters( 'wm_eraser_anonymize_personal_data', [], $clean_email )`, `apply_filters( 'wm_eraser_rest_rate_limit', 30, $request )`, `apply_filters( 'wm_eraser_status_text', $summary, $chain, $key )`, WordPress 6.5+ Block Bindings source `wm-dsgvo-eraser/status`, and GenerateBlocks 2.x Dynamic Tag `{{wm_eraser_status}}`.
- **28-Locale Translations & Packaging:** Regenerated `languages/wm-dsgvo-privacy-eraser.pot` and compiled complete `.po`, `.mo`, and block-editor JED `.json` catalogs across all 28 supported locales, alongside CycloneDX 1.6 `bom.json` and `.wordpress-org/blueprints/blueprint.json`.

### Security
- **Public REST Rate-Limiting Guard (`SEC-02`):** Replaced unconditional REST permission callbacks on `/wm-dsgvo-eraser/v1/request` and `/wm-dsgvo-eraser/v1/confirm` with `RestController::check_public_erasure_access()`, throttling bursts per HMAC-SHA256 blinded client IP (`wm_dsgvo_rl_*`, default 30 requests/minute, HTTP 429 when exceeded, never storing raw IPs, bypassed for `manage_options`).
- **POST-Only Confirmation & Atomic Claim (`D4`, `D5`):** Opening the mailed confirmation link (`GET`) erases nothing and renders a confirmation page whose `POST` button claims the request atomically (`ErasureRequestManager::claim()`). Legacy REST links (`GET /confirm`) redirect (`302`) to the confirmation page.

### Fixed
- **WooCommerce HPOS Billing Anonymisation (`D1`):** Overwrites billing fields through `wc_get_orders()` and order setters across both HPOS and legacy post storage instead of issuing raw SQL against missing columns.
- **Measured Area & Chain Reporting (`D2`–`D18`):** Every erasure area records database-affected rows or `failed` (`removed_from_this_site` on multisite, `skipped_administrator` for administrators); `verify_chain_integrity()` detects missing tables, emptied tables with a recorded tip, truncated tails, and edited `certificate_id` values; appends require MySQL `GET_LOCK( 'wm_dsgvo_cert_chain', 5 )`.
- **Design Family & Documentation:** Admin interface aligned with the shared WM Suite stylesheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`), German gettext source strings with real umlauts, and documentation synchronised with the codebase.

---

## [1.2.0] - 2026-08-27

### Security
- **Keyed HMAC Certificate Chain** (`ea2519c`): Replaced unkeyed row digests with HMAC-SHA256 seals keyed from `wp_salt( 'secure_auth' )` binding each predecessor seal and row payload, mirrored in `wm_dsgvo_cert_chain_tip` under a MySQL lock, with verification via `wp dsgvo verify` and `GET /verify`.
- **Encrypted Pending Addresses** (`3fe0fca`): `RequestCipher` encrypts pending request addresses at rest with libsodium `secretbox`; `wm_dsgvo_expire_requests` clears addresses and tokens of expired requests hourly.

---

## [1.1.0] - 2026-08-27

### Security
- **Single-Use Confirmation Token Flow** (`a2d634d`): `/request` stores a pending request with a SHA-256 hashed 24-hour token and mails a confirmation link instead of erasing immediately from an unauthenticated request; added per-address and per-IP rate limits, enumeration-safe responses, opt-in `wm_dsgvo_anonymize_wc_orders`, and `maybe_upgrade_schema()`.

---

## [1.0.0] - 2026-08-27

### Initial Release
- Initial plugin structure for Art. 17 DSGVO erasure handling, database schema, WP-CLI `wp dsgvo` command, and admin overview.
