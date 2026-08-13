# Mail Thread Link

[![Tests](https://github.com/nicolabs91/glpi-mailthreadlink/actions/workflows/tests.yml/badge.svg)](https://github.com/nicolabs91/glpi-mailthreadlink/actions/workflows/tests.yml)

GLPI 11 plugin that links email replies to the ticket created from the original
message, even when the reply targets the original sender rather than a GLPI
notification.

## How it works

1. The plugin stores the RFC `Message-ID` when a mail collector creates a ticket.
2. Incoming mail rules inspect `In-Reply-To` and `References`.
3. A matching open ticket is selected before GLPI decides between ticket creation
   and follow-up creation.
4. GLPI's normal follow-up permission checks remain in force.
5. Already processed `Message-ID` values are rejected to prevent duplicates.
6. If the sender cannot be authorized for the referenced ticket, the plugin
   leaves the message to GLPI's normal new-ticket rules instead of sending it
   to `Refused`.
7. A temporary concurrent-collector claim conflict also falls back to normal
   GLPI processing; only an exact duplicate is deliberately rejected.
8. Reply-all follow-ups that already went directly to other recipients are
   imported without sending a second GLPI follow-up notification to the same
   conversation, including replies matched by GLPI's native ticket headers.

The plugin never links messages by subject alone.

## Diagnostics

The plugin records its own decisions in the **Mail Thread Link log** under
GLPI's plugin configuration page. A deliberate duplicate refusal is recorded
with its reason, Message-ID, sender and referenced ticket. This log does not
replace GLPI's own mail collector diagnostics: blacklist, permission and
parsing failures handled by GLPI outside this plugin remain in GLPI's
**Not imported emails** view or mailgate logs.

## Requirements

- GLPI 11
- PHP 8.2 or newer
- The mail receiver must add CC recipients as observers when they need to reply.
- The observer profile needs the **Add as observer** follow-up right.

Replies to solved tickets reopen the ticket through GLPI's standard mail
collector behavior. Replies referencing a closed ticket are not linked and
continue through the normal receiver rules.

## Installation

1. Copy the `mailthreadlink` directory to GLPI's `plugins` directory.
2. Install and activate **Mail Thread Link** in GLPI.
3. Confirm that every mail collector rule has the action
   **Link replies using email thread headers**.
4. Run a controlled original-message and reply-all test before production use.

Installing a newer version is idempotent: existing message mappings and rule
actions are preserved. Uninstalling the plugin removes its message mapping
table. Back up that table first when the mappings need to be retained.

## Production checklist

- Back up the GLPI database before installation.
- Add intended `To` and `Cc` recipients as observers in the receiver settings.
- Grant observers only the follow-up permissions they need.
- Keep GLPI's normal requester and observer permission checks enabled.
- Do not add subject-only mail rules as a fallback.
- Keep SPF, DKIM, and DMARC checks enabled on the receiving mail server. Email
  addresses are the identity boundary used by GLPI's mail collector.
- Monitor rejected and unimported emails after rollout.

For reliable duplicate detection, incoming messages need a valid RFC
`Message-ID`. GLPI can process a message without one, but repeated copies
cannot be identified reliably.

Do not run two collectors against the same IMAP mailbox simultaneously. The
plugin prevents duplicate follow-ups, but GLPI itself may fail when concurrent
processes try to remove the same IMAP message.

## Tests

Run the header parser regression test from the plugin directory:

```sh
php tests/thread_headers.php
```

## License

GPLv3 or later.
