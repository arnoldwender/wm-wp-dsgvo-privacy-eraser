# Contributing to WM DSGVO Privacy Eraser

Thank you for your interest in contributing to **WM DSGVO Privacy Eraser**.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ strict typing:** every PHP file under `includes/` starts with `declare(strict_types=1);` and a direct-access guard (the standalone suite checks the guard). The WordPress tests run through `wp eval-file`, where neither applies.
2. **Self-hosted assets:** no CDNs, Google Fonts or external telemetry loaded by the plugin.
3. **Confirmation before erasure:** no GET may erase; the POST of the confirmation page with a valid token is the only public path, WP-CLI the only other one.
4. **No enumeration:** public answers are identical for known and unknown addresses.
5. **Records are append-only:** never edit or re-seal certificate rows; a broken chain is reported, not repaired.
6. **No legal verdicts** in the admin screen, CLI output, mails, REST answers or docs.

---

## Development Workflow

```bash
# 1. Clone the repository
git clone https://github.com/arnoldwender/wm-wp-dsgvo-privacy-eraser.git
cd wm-dsgvo-privacy-eraser

# 2. Run the standalone suites
php tests/test-suite.php
bash tests/run-mutations.sh
```

The WordPress integration tests are listed in [DEVELOPERS.md](DEVELOPERS.md).

---

## Commit Conventions

- Format: `[Feature] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.
- A pull request that changes the request flow, the erasure or the chain adds an assertion to `tests/test-suite.php` that fails without it, an entry in `tests/mutations.php`, and updates README.md, DEVELOPERS.md and CHANGELOG.md.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
