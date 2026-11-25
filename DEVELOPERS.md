# WM DSGVO Privacy Eraser — Developer Manual

> Everything below is measured against the code in this repository (`v1.3.3`).

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-dsgvo-privacy-eraser.php` | Header (`v1.3.3`), constants (`WM_DSGVO_ERASER_VERSION` / `_FILE` / `_PATH` / `_URL`), PSR-4 autoloader for `WMCyber\DSGVO\Eraser\` → `includes/`, activation (single-site and `$network_wide` multisite tables + `wm_dsgvo_schema_version` with `autoload = false`), `wp_initialize_site` for newly added network sites (`try`/`finally` `restore_current_blog()`), deactivation (unschedule the sweep), hub registration on `wm_register_suite_module`, boot on `plugins_loaded`. |
| `includes/Core/Plugin.php` | `CRON_HOOK`, `SCHEMA_VERSION` (`3`), text domain on `init`, `maybe_upgrade_schema()`, form & block, confirmation page, ecosystem bridges, admin, REST, hourly sweep, WP-CLI command `dsgvo`, action `wm_eraser_ready`. |
| `includes/Database/Schema.php` | Table names and `create_tables()` (`dbDelta`). |
| `includes/Request/ErasureRequestManager.php` | `blind()`, rate limits, `create()` with confirmation mail, `confirmation_url()`, `resolve()`, `claim()`, `mark()`, `address_of()`, `purge_expired()`. |
| `includes/Request/ErasureConfirmation.php` | `run( uuid, token )` (resolve, claim, decrypt, erase, close) and `pending( uuid, token )`. |
| `includes/Crypto/RequestCipher.php` | libsodium `secretbox` for pending addresses (`wmv1:`), cleartext fallback without libsodium, legacy rows readable. |
| `includes/Eraser/TransversalDataEraser.php` | `execute_erasure()`: comments, account, optional WooCommerce billing through `wc_get_orders()`, filter `wm_eraser_anonymize_personal_data`, certificate record, action `wm_eraser_completed`; `ANON_EMAIL`. |
| `includes/Crypto/ErasureCertificateGenerator.php` | `issue_certificate()`, `record_hash()`, `seal()`, `verify_chain_integrity()`, `GENESIS_HASH`, `TIP_OPTION`. |
| `includes/API/RestController.php` | `/request`, `/confirm` (302 to confirmation page), `/verify` under `wm-dsgvo-eraser/v1`, plus `check_public_erasure_access()` blinded-IP transient rate limiter (`wm_eraser_rest_rate_limit`). |
| `includes/Frontend/ErasureRequestForm.php` | `[wm_dsgvo_erasure_portal]`, block `wm/dsgvo-erasure-portal` (`blocks/dsgvo-erasure-portal/block.json`, `build/index.js`, `build/index.asset.php`, handle `wm-dsgvo-eraser-blocks-editor`), footer script. |
| `includes/Frontend/ErasureConfirmationPage.php` | `template_redirect` (priority 0) for `?wm_dsgvo_erasure=confirm`: GET shows the button, POST runs `ErasureConfirmation::run()`; `markup( view, … )`. |
| `includes/Frontend/EcosystemIntegration.php` | WP 6.5+ Block Bindings source `wm-dsgvo-eraser/status` and GenerateBlocks 2.x Dynamic Tag `{{wm_eraser_status}}` (`wm_eraser_status_text`). |
| `includes/CLI/PrivacyEraserCommand.php` | `wp dsgvo erase`, `wp dsgvo verify`. |
| `includes/Admin/AdminDashboard.php` | Menu, Settings API registration (`wm_dsgvo_anonymize_wc_orders`, `wm_dsgvo_eraser_delete_data_on_uninstall`), family sheet enqueue, `stats()`, five tabs (`overview`, `request`, `certificates`, `limits`, `settings`), dashboard widget. |
| `includes/Adapters/Privacy_Eraser_Spoke_Adapter.php` | WM Suite Hub contract (`WM_Plugin_Module_Interface`). |
| `blocks/dsgvo-erasure-portal/block.json` | Block metadata (`wm/dsgvo-erasure-portal`, `editorScript`: `wm-dsgvo-eraser-blocks-editor`). |
| `blocks-src/index.js` & `build/` | Block editor source and compiled bundle (`InspectorControls` + `ServerSideRender`). |
| `assets/css/` | `wm-admin-tokens.css`, `wm-admin-ui.css` (shared WM Suite admin stylesheets). |
| `uninstall.php` | Clears `wm_dsgvo_expire_requests`; preserves options and tables unless `wm_dsgvo_eraser_delete_data_on_uninstall` is enabled. |

---

## 2. Storage

`wp_wm_dsgvo_requests`: `id`, `request_uuid` (UNIQUE), `email_hmac`, `email_enc` (envelope, NULL after the request ends), `token_hash` (SHA-256, NULL after use), `status` (`pending`, `processing`, `processed`, `failed`, `expired`), `ip_hmac`, `requested_at`, `expires_at`, `confirmed_at`, `processed_at`.

`wp_wm_dsgvo_erasure_certificates`: `id`, `certificate_id` (UNIQUE, `ERASE-` + 16 hex), `blinded_email_hmac`, `purged_records_count`, `purged_tables_summary` (`area: count`, `area: skipped_…`, `area: removed_from_this_site`, `area: failed` or `area: failed N of M`), `prev_hash` (empty for pre-chain rows), `sha256_audit_seal`, `erased_at`.

| Option | Written by | Read by |
| --- | --- | --- |
| `wm_dsgvo_schema_version` | activation, `maybe_upgrade_schema()` | `maybe_upgrade_schema()` |
| `wm_dsgvo_cert_chain_tip` | `issue_certificate()` | `issue_certificate()`, `verify_chain_integrity()` |
| `wm_dsgvo_anonymize_wc_orders` | Settings API (`AdminDashboard`) or `wp option update` | `execute_erasure()`, `AdminDashboard`, `Privacy_Eraser_Spoke_Adapter` |
| `wm_dsgvo_eraser_delete_data_on_uninstall` | Settings API (`AdminDashboard`) or `wp option update` | `AdminDashboard`, `uninstall.php` |

---

## 3. Hooks & Ecosystem Bridges

| Type | Name | Arguments | Purpose |
| --- | --- | --- | --- |
| Action | `wm_eraser_ready` | `Plugin $plugin` | Fires after plugin services, routes, cron and CLI commands are wired. |
| Action | `wm_eraser_completed` | `array $outcome` | Fires after `TransversalDataEraser::execute_erasure()` completes (carries `email_hmac`, counts, areas and certificate, never raw email). |
| Filter | `wm_eraser_anonymize_personal_data` | `array $extra_areas, string $clean_email` | Allows companion plugins to anonymise or erase custom tables and report integer counts or `'failed'` into the sealed certificate summary. |
| Filter | `wm_eraser_rest_rate_limit` | `int $limit, WP_REST_Request $request` | Per-minute blinded-IP transient rate limit on public REST routes (default `30`). |
| Filter | `wm_eraser_status_text` | `string $summary, array $chain, string $key` | Customises the public portal status string exposed to Block Bindings and GenerateBlocks. |
| Block Binding | `wm-dsgvo-eraser/status` | `key` (`status` \| `checked`) | WordPress 6.5+ Block Bindings source returning portal/chain status. |
| Dynamic Tag | `{{wm_eraser_status}}` | `key` (`status` \| `checked`) | GenerateBlocks 2.x Dynamic Tag (`GenerateBlocks_Register_Dynamic_Tag` + `generateblocks_dynamic_tag_output`). |

---

## 4. Request & Erasure Flow

1. **Public REST guard:** `RestController::check_public_erasure_access()` bypasses rate limiting for `manage_options`, blinds `REMOTE_ADDR` with `ErasureRequestManager::blind()`, increments transient `wm_dsgvo_rl_<32hex>` (`MINUTE_IN_SECONDS`) and returns `WP_Error( 'wm_eraser_rate_limited', …, [ 'status' => 429 ] )` above `$limit`.
2. **Request (`POST /request`):** Returns `400` on invalid email; otherwise calls `ErasureRequestManager::create()` (silently capped at 3 per address / 24 h and 10 per IP / 1 h) and always answers `202` with a uniform message.
3. **Link open (`GET /?wm_dsgvo_erasure=confirm`):** Resolves pending, unexpired request with `hash_equals()` on SHA-256 token hash and renders a POST confirmation button without deleting anything. `GET /confirm` redirects (`302`) here.
4. **Confirmation (`POST /?wm_dsgvo_erasure=confirm`):** `claim()` runs conditional `UPDATE … SET status = 'processing', token_hash = NULL WHERE request_uuid = %s AND status = 'pending'`, decrypts `email_enc`, runs `TransversalDataEraser::execute_erasure()`, and closes the request via `mark()` (`processed` or `failed`, clearing `email_enc` and `token_hash`).

---

## 5. Tests

```bash
php tests/test-suite.php
php tests/test-pot-freshness.php
bash tests/run-mutations.sh
wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/wp-runtime-eraser.php --user=1
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-erasure-authorization.php --allow-root
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-request-address-at-rest.php --allow-root
WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-certificate-chain.php --allow-root [scratch]
WM_ERASER_TEST=test-erasure-authorization.php wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/wp-run-rolled-back.php --user=1
```

---

## 6. Security Disclosure

See [SECURITY.md](SECURITY.md).
