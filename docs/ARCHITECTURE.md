# Architecture — WM DSGVO Art. 17 Cryptographic Privacy Eraser
`wm-dsgvo-privacy-eraser` · version `1.3.1` from the plugin header · WordPress 6.4+ · PHP 8.2+ · text domain `wm-dsgvo-privacy-eraser`

Every figure below comes directly from the codebase.

---

## How an erasure flows

```
[wm_dsgvo_erasure_portal]  or  the block wm/dsgvo-erasure-portal
    └─ POST /wp-json/wm-dsgvo-eraser/v1/request          PUBLIC
         rate limits: 3 per address / 24 h, 10 per client IP / 1 h — dropped silently above
         stores: request UUID, HMAC of address and IP, address encrypted with libsodium
                 secretbox, SHA-256 of a 64-char token, status pending, expiry +24 h
         mails the confirmation link
         ALWAYS answers 202 with the same message, whether or not the address exists

visitor opens the link  →  a PAGE.  Nothing is erased by this GET.
    └─ POST of that page's button                        ErasureRequestManager::claim()
         comments anonymised · non-admin user deleted · optional WooCommerce billing
         each area records affected rows or `failed`

    └─ erasure record appended to wp_wm_dsgvo_erasure_certificates
         id ERASE-… derived from the row · HMAC of the address · seal over predecessor+contents
         needs the MySQL lock wm_dsgvo_cert_chain — without it NOTHING is appended and the
         erasure is reported as not recorded
         tip mirrored in wm_dsgvo_cert_chain_tip; a new row chains onto THAT option

hourly cron wm_dsgvo_expire_requests   clears address and token of expired pending requests
wp dsgvo erase                          the only path that erases without confirmation
wp dsgvo verify · GET /verify           INTACT · EMPTY_CHAIN · BROKEN with the first failing row
```

---

## Wiring — what to touch for a given change

| Change | Files, in order | Trap |
|---|---|---|
| Add an erasure area | `includes/Eraser/` → record its affected rows or `failed` → assertion + mutation | an area that fails makes the whole erasure **not** a success. Never report a count you did not get from the database |
| Change the chain input | the sealing code in `includes/Crypto/` | it is a contract: every stored row stops verifying, and **there is no re-signing** |
| Add a REST route | `includes/API/` | `/request` is public — it needs its rate limits and the constant-time comparisons. `/verify` needs a capability |
| Touch the address at rest | `RequestCipher`, read only through `ErasureRequestManager::address_of()` | pending addresses stay encrypted; on a PHP without libsodium they are stored in clear, and that is documented |

---

## Fragile spots

1. **Nothing is erased by a GET.** `/request` only records and mails; the link shows a page; only that page's POST erases, after `claim()` won the request. Only WP-CLI erases without confirmation. Test group 13 and `tests/test-erasure-authorization.php` pin this — a crawler or a mail scanner following links must not delete anybody's account.
2. **The public answer never depends on whether the address exists.** Always 202, always the same message. Anything else is an enumeration oracle.
3. **Never rewrite, delete or re-seal certificate rows to make the chain verify.** A broken chain is reported as broken, including rows a database copy brought from another install.
4. **Report what the database did.** Every area records affected rows or `failed`; a failed area or an unwritten record is never a success. `wp_delete_user()` also deletes that user's posts — and on multisite it only removes the account from the current site, recorded as `removed_from_this_site`.
5. **An administrator account is never deleted** — recorded as `skipped_administrator`.
6. **`wm_dsgvo_cert_chain_tip` is written by the append path only.** Writing it by hand makes the chain report `BROKEN`.
7. **Three of the four integration tests write** (rows, a deleted test account, Consent Vault anchors nothing removes) and refuse to start without `WM_ERASER_INTEGRATION_DISPOSABLE=1`. Only `wp-runtime-eraser.php` is safe on a shared install.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-dsgvo-privacy-eraser --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**17 PHP files, 2737 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 198 |
| `build` | 1 | 18 |
| `includes/API` | 1 | 169 |
| `includes/Adapters` | 1 | 193 |
| `includes/Admin` | 1 | 443 |
| `includes/CLI` | 1 | 102 |
| `includes/Core` | 1 | 99 |
| `includes/Crypto` | 2 | 451 |
| `includes/Database` | 1 | 74 |
| `includes/Eraser` | 1 | 186 |
| `includes/Frontend` | 3 | 406 |
| `includes/Request` | 2 | 398 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `EcosystemIntegration` | [`includes/Frontend/EcosystemIntegration.php`](../includes/Frontend/EcosystemIntegration.php) |
| `ErasureCertificateGenerator` | [`includes/Crypto/ErasureCertificateGenerator.php`](../includes/Crypto/ErasureCertificateGenerator.php) |
| `ErasureConfirmation` | [`includes/Request/ErasureConfirmation.php`](../includes/Request/ErasureConfirmation.php) |
| `ErasureConfirmationPage` | [`includes/Frontend/ErasureConfirmationPage.php`](../includes/Frontend/ErasureConfirmationPage.php) |
| `ErasureRequestForm` | [`includes/Frontend/ErasureRequestForm.php`](../includes/Frontend/ErasureRequestForm.php) |
| `ErasureRequestManager` | [`includes/Request/ErasureRequestManager.php`](../includes/Request/ErasureRequestManager.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `PrivacyEraserCommand` | [`includes/CLI/PrivacyEraserCommand.php`](../includes/CLI/PrivacyEraserCommand.php) |
| `Privacy_Eraser_Spoke_Adapter` | [`includes/Adapters/Privacy_Eraser_Spoke_Adapter.php`](../includes/Adapters/Privacy_Eraser_Spoke_Adapter.php) |
| `RequestCipher` | [`includes/Crypto/RequestCipher.php`](../includes/Crypto/RequestCipher.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `TransversalDataEraser` | [`includes/Eraser/TransversalDataEraser.php`](../includes/Eraser/TransversalDataEraser.php) |

---

## Entry points

**REST routes:** `/confirm`, `/request`, `/verify`

**WP-CLI:** `dsgvo`

**Shortcodes:** `wm_dsgvo_erasure_portal`

**Gutenberg blocks:** `wm/dsgvo-erasure-portal`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_dsgvo_anonymize_wc_orders` | `includes/Admin/AdminDashboard.php` | `includes/Adapters/Privacy_Eraser_Spoke_Adapter.php`, `includes/Admin/AdminDashboard.php`, `includes/Eraser/TransversalDataEraser.php` |
| `wm_dsgvo_eraser_delete_data_on_uninstall` | `includes/Admin/AdminDashboard.php` | `includes/Admin/AdminDashboard.php`, `uninstall.php` |
| `wm_dsgvo_schema_version` | `includes/Core/Plugin.php`, `wm-dsgvo-privacy-eraser.php` | `includes/Core/Plugin.php` |

**More than one writer:** `wm_dsgvo_schema_version`. Two layers writing one key is the shape that goes out of sync; check they agree on the default as well as on the value.


**Tables** (prefixed with `$wpdb->prefix`): `wm_dsgvo_erasure_certificates`, `wm_dsgvo_requests`

---

## Gates

- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-certificate-chain.php`
- `tests/test-erasure-authorization.php`
- `tests/test-pot-freshness.php`
- `tests/test-request-address-at-rest.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/wp-chain-provenance.php`
- `tests/wp-run-rolled-back.php`
- `tests/wp-runtime-eraser.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

