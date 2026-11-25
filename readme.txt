=== WM DSGVO Art. 17 Cryptographic Privacy Eraser ===
Contributors: arnoldwender
Tags: dsgvo, gdpr, erasure, privacy, art-17
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Erasure requests confirmed through a single-use mailed link and a button on the site, with HMAC-chained erasure records.

== Description ==

Data subjects request erasure through a public shortcode or Gutenberg block. The request remains
pending until someone with access to the mailbox opens the single-use link and confirms with the
button on the page it leads to; opening the link alone erases nothing. Whether a controller may
require this step for a given request is not decided by the plugin (Art. 12 Abs. 6 DSGVO speaks of
reasonable doubts about the requester's identity).

* Gutenberg block `wm/dsgvo-erasure-portal` with InspectorControls and live ServerSideRender preview, plus shortcode `[wm_dsgvo_erasure_portal]`.
* Confirmation link valid 24 hours, single use, stored as a SHA-256 hash and compared in constant time.
* Rate limited per address, per client IP in the request table, and at the REST permission layer using short-lived blinded-IP HMAC-SHA256 transients (no raw IPs stored).
* Identical response for known and unknown addresses — no account enumeration.
* WooCommerce billing anonymisation is OFF by default: order billing data can be subject to
  retention periods under § 147 AO and § 257 HGB; which one applies is not decided by the plugin.
* Uninstallation preserves erasure records and plugin options by default unless the administrator explicitly opts in via `wm_dsgvo_eraser_delete_data_on_uninstall`.
* Every erasure, confirmed or run with `wp dsgvo erase`, adds a record with a keyed hash of the
  address; an area that failed is recorded as failed, and a record that could not be written is
  reported.
* Certificates form a tamper-evident chain: each seal is an HMAC over the previous seal plus the
  row's own contents, keyed from wp-config.php rather than from the database. `wp dsgvo verify`
  walks the chain and names the first row that does not check out. Tamper-evident means edits,
  insertions and removals are detectable while the recorded chain tip and wp-config.php salts
  remain intact.
* Developer extensibility via `wm_eraser_ready`, `wm_eraser_completed`, `wm_eraser_anonymize_personal_data`, `wm_eraser_rest_rate_limit`, `wm_eraser_status_text`, WordPress 6.5+ Block Bindings (`wm-dsgvo-eraser/status`) and GenerateBlocks 2.x Dynamic Tags (`{{wm_eraser_status}}`).
* Complete translations across 28 locales (`.po`, `.mo` and block-editor JED `.json`).

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload the `wm-dsgvo-privacy-eraser` folder to `/wp-content/plugins/` and activate the plugin.
2. Insert the Gutenberg block `wm/dsgvo-erasure-portal` or `[wm_dsgvo_erasure_portal]` on your privacy page.
3. Configure WooCommerce anonymisation and opt-in uninstall settings under **Privacy Eraser › Einstellungen**.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Overview tab: erasure records, erased or anonymised entries, open requests, the state of the record chain, libsodium and the two settings
2. Gutenberg editor showing the wm/dsgvo-erasure-portal block
3. Public data erasure request portal with constant anti-enumeration confirmation

== Changelog ==


= 1.4.2 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu, and its caption now says what the screen shows: the overview tab with the counts, the state of the record chain, libsodium and the two settings, not an erasure queue with link status and a certificate log. The German caption of screenshot 3 reads «zum Schutz» instead of «zur Schutz». The plugin's code is unchanged.

= 1.4.1 =
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

= 1.4.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Fixed: The placeholder of the e-mail field in the request form pointed at a real, registered domain; it now uses the reserved example.com (RFC 2606), and its translations in the 28 catalogs carry the same domain.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.3.4 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-dsgvo-privacy-eraser` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.3.3 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/`).

= 1.3.2 =
* Point Plugin URI to the official plugin page.

= 1.3.1 = (2026-11-18)
* Multisite: added network-wide activation support (`$network_wide`) and `wp_initialize_site` handler so newly added network sites create their per-site tables and schema version safely inside `try`/`finally` `restore_current_blog()`.
* Passed explicit `$autoload = false` when saving `wm_dsgvo_schema_version` during activation and schema upgrades.

= 1.3.0 = (2026-11-16)
* Compiled Gutenberg block `wm/dsgvo-erasure-portal` (`block.json`, `build/index.js`, `build/index.asset.php`, `InspectorControls`, `ServerSideRender` and `wp_set_script_translations`).
* Replaced open REST permission callbacks on `/request` and `/confirm` with `check_public_erasure_access()` implementing blinded-IP HMAC-SHA256 transient rate limiting (`wm_dsgvo_rl_*`, filterable via `wm_eraser_rest_rate_limit`, HTTP 429 when exceeded, bypassed for `manage_options`).
* Opt-in uninstallation (`wm_dsgvo_eraser_delete_data_on_uninstall`, default off) preserving erasure certificates and options unless explicitly enabled on the new Settings tab (`Einstellungen`).
* Added WordPress 6.5+ Block Bindings source `wm-dsgvo-eraser/status`, GenerateBlocks 2.x Dynamic Tag `{{wm_eraser_status}}`, actions `wm_eraser_ready` and `wm_eraser_completed`, and filter `wm_eraser_anonymize_personal_data`.
* Added WordPress Playground blueprint, CycloneDX 1.6 `bom.json`, and complete translations across all 28 locales (`.po`, `.mo`, and JED `.json`).
* Opening the mailed link shows a confirmation page with a POST button rather than erasing on GET; concurrent confirmations are serialised via `ErasureRequestManager::claim()`; WooCommerce billing anonymisation goes through `wc_get_orders()` for HPOS compatibility.

= 1.2.0 = (2026-08-27)
* Certificates are chained with a keyed HMAC-SHA256 seal mirrored in `wm_dsgvo_cert_chain_tip` and verifiable via `wp dsgvo verify` or `GET /wm-dsgvo-eraser/v1/verify` (`manage_options`).
* Pending request addresses are encrypted at rest with libsodium `secretbox` when available and cleared on completion or hourly expiry sweep.
* The admin dashboard reports the live chain verification state rather than a fixed readiness figure.

= 1.1.0 = (2026-08-27)
* Security: the erasure endpoint no longer deletes straight from an unauthenticated request.
* Added identity confirmation, rate limiting and enumeration-safe responses.
* WooCommerce order anonymisation gated behind an explicit opt-in.

== Upgrade Notice ==

= 1.4.2 =
Screenshot 1 retaken with the current WM Suite menu and its caption corrected. No code, data or settings change.

= 1.4.1 =
Stylesheet update only (the family sheet of WM Suite Hub 1.2.5). No data or settings change.

= 1.4.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
