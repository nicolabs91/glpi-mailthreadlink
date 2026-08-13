# Changelog

## 0.1.3 - 2026-08-13

- Add a database-backed diagnostic log for plugin decisions.
- Show duplicate Refused decisions with Message-ID, sender and ticket context.
- Show fallback decisions for unauthorized senders and temporary claim conflicts.
- Add a GLPI configuration page for reviewing the recent plugin log.

## 0.1.2 - 2026-08-13

- Avoid refusing mail when the thread sender cannot be matched to the ticket;
  GLPI now applies its normal new-ticket rules instead.
- Avoid refusing mail when a concurrent collector temporarily cannot claim the
  message; only an exact already-processed `Message-ID` remains a deliberate
  duplicate rejection.
- Treat in-flight `Pending` claims as temporary rather than completed
  duplicates, preventing a second collector from unnecessarily refusing mail.

## 0.1.1 - 2026-06-29

- Suppress GLPI's extra follow-up notification for reply-all mail collector
  imports when the incoming message already went directly to other human
  recipients.
- Suppress reply-all notification echoes for GLPI-native threaded follow-ups,
  not only follow-ups matched by the plugin rule action.
- Add a pre-add follow-up hook so mailcollector replies can disable the GLPI
  notification before `ITILFollowup` raises the `add_followup` event.
- Keep regular GLPI follow-up notifications enabled for replies sent only to
  the support mailbox.
- Add header tests for reply-all notification echo detection.
- Add regression coverage for replies where support is in `Cc` and the direct
  recipient is in `To`.

## 0.1.0 - 2026-06-09

- Preserve and match original RFC `Message-ID` values.
- Link replies through `In-Reply-To` and `References`.
- Prevent duplicate imports by current `Message-ID`.
- Keep GLPI's normal requester and observer permission checks.
- Require the sender to be a ticket actor, alternate address, or supplier.
- Ignore closed tickets and reopen solved tickets through GLPI behavior.
- Prevent duplicate follow-ups from concurrent collectors.
- Expire abandoned message claims after ten minutes.
- Remove stored message mappings when a ticket is permanently deleted.
- Add idempotent installation and missing-table protection.
- Add header parser and SMTP/IMAP regression tests.
