# Changelog

## 0.1.0 - 2026-06-09

- Preserve and match original RFC `Message-ID` values.
- Link replies through `In-Reply-To` and `References`.
- Prevent duplicate imports by current `Message-ID`.
- Keep GLPI's normal requester and observer permission checks.
- Ignore closed tickets and reopen solved tickets through GLPI behavior.
- Add idempotent installation and missing-table protection.
- Add header parser and SMTP/IMAP regression tests.
