<?php

class PluginMailthreadlinkThreadmatcher
{
    public const TABLE = 'glpi_plugin_mailthreadlink_messages';
    public const LOG_TABLE = 'glpi_plugin_mailthreadlink_log';
    public const ACTION_FIELD = '_mailthreadlink';
    public const ACTION_TYPE = 'mailthreadlink';
    public const FALLBACK_RULE_NAME = 'Mail Thread Link fallback';

    private static array $messageLocks = [];

    public static function ensureSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $DB->doQuery(
                'CREATE TABLE `' . self::TABLE . '` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `message_hash` char(64) NOT NULL,
                `message_id` varchar(998) NOT NULL,
                `tickets_id` int unsigned NOT NULL,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `message_hash` (`message_hash`),
                KEY `tickets_id` (`tickets_id`),
                KEY `item` (`itemtype`, `items_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (!$DB->tableExists(self::LOG_TABLE)) {
            $DB->doQuery(
                'CREATE TABLE `' . self::LOG_TABLE . '` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `result` varchar(32) NOT NULL,
                    `reason` varchar(64) NOT NULL,
                    `message_hash` char(64) NULL,
                    `message_id` varchar(998) NULL,
                    `sender` varchar(255) NULL,
                    `tickets_id` int unsigned NULL,
                    `details` varchar(255) NULL,
                    PRIMARY KEY (`id`),
                    KEY `date_creation` (`date_creation`),
                    KEY `reason` (`reason`),
                    KEY `message_hash` (`message_hash`),
                    KEY `tickets_id` (`tickets_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    public static function match(array $params): array
    {
        $ticket_input = $params['params']['ticket'] ?? [];
        $headers = $params['params']['headers'] ?? [];

        if (!is_array($ticket_input) || !is_array($headers)) {
            return [];
        }

        self::removeExpiredClaims();

        $message_id = self::normalizeMessageId((string) ($headers['message_id'] ?? ''));
        if ($message_id !== '') {
            if (self::messageExists($message_id)) {
                self::logEvent($headers, 'refused', 'duplicate_message_id');
                return ['_refuse_email_no_response' => 1];
            }
        }

        foreach (self::referenceMessageIds($headers) as $reference) {
            $ticket_id = self::findTicketId($reference);
            if ($ticket_id === null) {
                continue;
            }

            $ticket = new Ticket();
            if (
                !$ticket->getFromDB($ticket_id)
                || (int) $ticket->fields['status'] === CommonITILObject::CLOSED
            ) {
                continue;
            }

            $requester_id = (int) (
                $params['params']['_users_id_requester']
                ?? $ticket_input['_users_id_requester']
                ?? 0
            );
            $sender_email = self::normalizeEmail((string) ($headers['from'] ?? ''));
            if (!self::isAuthorizedSender($ticket_id, $requester_id, $sender_email)) {
                self::logEvent($headers, 'fallback', 'sender_not_authorized', $ticket_id);
                // Do not reject an otherwise valid message just because the
                // thread sender cannot be mapped to this ticket. Returning no
                // rule action lets GLPI apply its normal new-ticket rules.
                continue;
            }

            if ($message_id !== '' && !self::claimMessage($message_id, $ticket_id)) {
                self::logEvent($headers, 'fallback', 'temporary_claim_conflict', $ticket_id);
                // A concurrent collector must not send a message to Refused
                // merely because this plugin could not claim it. Let GLPI's
                // normal collector processing decide how to handle it.
                continue;
            }

            $output = [
                'tickets_id' => $ticket_id,
                'requesttypes_id' => RequestType::getDefault('mailfollowup'),
                'add_reopen' => 1,
            ];

            if (self::shouldSuppressReplyAllEcho($headers, $params)) {
                $output['_disablenotif'] = 1;
            }

            return $output;
        }

        return [];
    }

    public static function rememberItem(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket && !$item instanceof ITILFollowup) {
            return;
        }

        $headers = $item->input['_head'] ?? [];
        if (!is_array($headers)) {
            return;
        }

        $message_id = self::normalizeMessageId((string) ($headers['message_id'] ?? ''));
        if ($message_id === '') {
            return;
        }

        if ($item instanceof Ticket) {
            $ticket_id = (int) $item->getID();
        } else {
            if (($item->fields['itemtype'] ?? '') !== Ticket::class) {
                return;
            }
            $ticket_id = (int) ($item->fields['items_id'] ?? 0);
        }

        if ($ticket_id <= 0) {
            return;
        }

        self::storeMessage(
            $message_id,
            $ticket_id,
            $item::class,
            (int) $item->getID()
        );
    }

    public static function suppressReplyAllEchoNotification(ITILFollowup $followup): void
    {
        if (!is_array($followup->input)) {
            return;
        }

        if (self::shouldDisableFollowupNotification($followup->input)) {
            $followup->input['_disablenotif'] = 1;
        }
    }

    public static function shouldDisableFollowupNotification(array $input): bool
    {
        $headers = $input['_head'] ?? [];
        if (!is_array($headers) || $headers === []) {
            return false;
        }

        if (($input['itemtype'] ?? '') !== Ticket::class) {
            return false;
        }

        if ((int) ($input['items_id'] ?? 0) <= 0) {
            return false;
        }

        return self::hasDirectHumanRecipients($headers);
    }

    public static function attachActionToAllRules(): void
    {
        global $DB;

        $rules = $DB->request([
            'SELECT' => ['id'],
            'FROM' => Rule::getTable(),
            'WHERE' => ['sub_type' => RuleMailCollector::class],
        ]);

        foreach ($rules as $rule) {
            self::ensureRuleAction((int) $rule['id']);
        }
    }

    public static function forgetTicket(int $ticket_id): void
    {
        global $DB;

        if ($ticket_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return;
        }

        $DB->delete(self::TABLE, ['tickets_id' => $ticket_id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function getLogRows(int $limit = 100): array
    {
        global $DB;

        if (!$DB->tableExists(self::LOG_TABLE)) {
            return [];
        }

        $rows = [];
        foreach ($DB->request([
            'FROM' => self::LOG_TABLE,
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => max(1, min($limit, 500)),
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function ensureFallbackRule(): void
    {
        global $DB;

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM' => Rule::getTable(),
            'WHERE' => [
                'sub_type' => RuleMailCollector::class,
                'name' => self::FALLBACK_RULE_NAME,
            ],
            'LIMIT' => 1,
        ])->current();

        if ($existing) {
            self::ensureRuleAction((int) $existing['id']);
            return;
        }

        $max_ranking = 0;
        $ranking_result = $DB->request([
            'SELECT' => ['MAX' => 'ranking AS max_ranking'],
            'FROM' => Rule::getTable(),
            'WHERE' => ['sub_type' => RuleMailCollector::class],
        ])->current();
        if ($ranking_result) {
            $max_ranking = (int) ($ranking_result['max_ranking'] ?? 0);
        }

        $rule = new RuleMailCollector();
        $rule_id = $rule->add([
            'name' => self::FALLBACK_RULE_NAME,
            'description' => 'Internal fallback rule used by the Mail Thread Link plugin.',
            'match' => 'AND',
            'is_active' => 1,
            'sub_type' => RuleMailCollector::class,
            'ranking' => $max_ranking + 1,
        ]);

        if ($rule_id) {
            self::ensureRuleAction((int) $rule_id);
        }
    }

    public static function ensureRuleAction(int $rule_id): void
    {
        global $DB;

        if ($rule_id <= 0) {
            return;
        }

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM' => RuleAction::getTable(),
            'WHERE' => [
                'rules_id' => $rule_id,
                'field' => self::ACTION_FIELD,
            ],
        ])->current();

        if ((int) ($exists['cpt'] ?? 0) > 0) {
            return;
        }

        $action = new RuleAction(RuleMailCollector::class);
        $action->add([
            'rules_id' => $rule_id,
            'action_type' => self::ACTION_TYPE,
            'field' => self::ACTION_FIELD,
            'value' => 1,
        ]);
    }

    public static function normalizeMessageId(string $message_id): string
    {
        $message_id = trim($message_id);
        if (str_starts_with($message_id, '<') && str_ends_with($message_id, '>')) {
            $message_id = substr($message_id, 1, -1);
        }

        return strtolower(trim($message_id));
    }

    public static function referenceMessageIds(array $headers): array
    {
        $references = [];

        foreach (['in_reply_to', 'references'] as $header) {
            $value = (string) ($headers[$header] ?? '');
            if ($value === '') {
                continue;
            }

            preg_match_all('/<([^>]+)>|([^\s<>]+)/', $value, $matches, PREG_SET_ORDER);
            $ids = [];
            foreach ($matches as $match) {
                $ids[] = ($match[1] ?? '') !== ''
                    ? $match[1]
                    : ($match[2] ?? '');
            }

            foreach (array_reverse($ids) as $id) {
                $normalized = self::normalizeMessageId((string) $id);
                if ($normalized !== '' && !in_array($normalized, $references, true)) {
                    $references[] = $normalized;
                }
            }
        }

        return $references;
    }

    public static function hasDirectHumanRecipients(array $headers, array $collector_emails = []): bool
    {
        $ignored = array_filter(array_map(
            static fn ($email) => self::normalizeEmail((string) $email),
            array_merge($collector_emails, [(string) ($headers['from'] ?? ''), (string) ($headers['to'] ?? '')])
        ));

        foreach (['tos', 'ccs'] as $header) {
            $values = $headers[$header] ?? [];
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $email) {
                $normalized = self::normalizeEmail((string) $email);
                if ($normalized !== '' && !in_array($normalized, $ignored, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function shouldSuppressReplyAllEcho(array $headers, array $params): bool
    {
        $collector_emails = [];
        $mailcollector_id = (int) ($params['params']['mailcollector'] ?? 0);
        if ($mailcollector_id > 0 && class_exists(MailCollector::class)) {
            $collector = new MailCollector();
            if ($collector->getFromDB($mailcollector_id)) {
                $collector_emails[] = (string) ($collector->fields['name'] ?? '');
            }
        }

        return self::hasDirectHumanRecipients($headers, $collector_emails);
    }

    private static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        if (preg_match('/<([^>]+)>/', $email, $matches) === 1) {
            $email = $matches[1];
        }

        return strtolower(trim($email));
    }

    private static function findTicketId(string $message_id): ?int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => self::TABLE,
            'WHERE' => ['message_hash' => hash('sha256', $message_id)],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        $ticket_id = (int) $row['tickets_id'];
        return $ticket_id > 0 ? $ticket_id : null;
    }

    private static function messageExists(string $message_id): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return false;
        }

        $row = $DB->request([
            'SELECT' => ['itemtype'],
            'FROM' => self::TABLE,
            'WHERE' => ['message_hash' => hash('sha256', $message_id)],
            'LIMIT' => 1,
        ])->current();

        // A Pending row belongs to an in-flight collector. It is not yet a
        // completed duplicate and must not send the mail to Refused.
        return is_array($row) && ($row['itemtype'] ?? '') !== 'Pending';
    }

    private static function claimMessage(string $message_id, int $ticket_id): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return true;
        }

        $hash = hash('sha256', $message_id);
        $lock_name = 'mailthreadlink.' . substr($hash, 0, 40);
        if (isset(self::$messageLocks[$hash]) || !$DB->getLock($lock_name)) {
            return false;
        }
        self::$messageLocks[$hash] = $lock_name;

        $claimed = false;
        try {
            $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
            $DB->doQuery(
                'INSERT IGNORE INTO `' . self::TABLE . '`'
                . ' (`message_hash`, `message_id`, `tickets_id`, `itemtype`, `items_id`, `date_creation`)'
                . ' VALUES ('
                . DBmysql::quoteValue($hash) . ', '
                . DBmysql::quoteValue(mb_substr($message_id, 0, 998)) . ', '
                . $ticket_id . ', '
                . DBmysql::quoteValue('Pending') . ', 0, '
                . DBmysql::quoteValue($now)
                . ')'
            );

            $claimed = $DB->affectedRows() === 1;
            return $claimed;
        } finally {
            // A successful claim is released by storeMessage() after the
            // Ticket/Followup hook. On an insert failure, release it here so
            // one broken message cannot poison later collector work.
            if (!$claimed) {
                self::releaseMessageLock($hash);
            }
        }
    }

    private static function removeExpiredClaims(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $expired_before = date('Y-m-d H:i:s', time() - 600);
        $DB->doQuery(
            'DELETE FROM `' . self::TABLE . '`'
            . ' WHERE `itemtype` = ' . DBmysql::quoteValue('Pending')
            . ' AND `date_creation` < ' . DBmysql::quoteValue($expired_before)
        );
    }

    private static function releaseMessageLock(string $hash): void
    {
        global $DB;

        if (!isset(self::$messageLocks[$hash])) {
            return;
        }

        $DB->releaseLock(self::$messageLocks[$hash]);
        unset(self::$messageLocks[$hash]);
    }

    private static function isAuthorizedSender(
        int $ticket_id,
        int $users_id,
        string $sender_email
    ): bool {
        global $DB;

        if ($users_id > 0) {
            $actor = $DB->request([
                'COUNT' => 'cpt',
                'FROM' => Ticket_User::getTable(),
                'WHERE' => [
                    'tickets_id' => $ticket_id,
                    'users_id' => $users_id,
                ],
            ])->current();
            if ((int) ($actor['cpt'] ?? 0) > 0) {
                return true;
            }
        }

        $sender_email = trim($sender_email);
        if ($sender_email === '') {
            return false;
        }

        $ticket_user = new Ticket_User();
        if ($ticket_user->isAlternateEmailForITILObject($ticket_id, $sender_email)) {
            return true;
        }

        $supplier_ticket = new Supplier_Ticket();
        return $supplier_ticket->isSupplierEmail($ticket_id, $sender_email);
    }

    private static function storeMessage(
        string $message_id,
        int $ticket_id,
        string $itemtype,
        int $items_id
    ): void {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $hash = hash('sha256', $message_id);
        try {
            $DB->update(self::TABLE, [
                'tickets_id' => $ticket_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ], [
                'message_hash' => $hash,
                'itemtype' => 'Pending',
            ]);
            if ($DB->affectedRows() > 0) {
                return;
            }

            if (self::messageExists($message_id)) {
                return;
            }

            $DB->insert(self::TABLE, [
                'message_hash' => $hash,
                'message_id' => mb_substr($message_id, 0, 998),
                'tickets_id' => $ticket_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } finally {
            self::releaseMessageLock($hash);
        }
    }

    private static function logEvent(
        array $headers,
        string $result,
        string $reason,
        ?int $ticket_id = null,
        ?string $details = null
    ): void {
        global $DB;

        if (!$DB->tableExists(self::LOG_TABLE)) {
            return;
        }

        $message_id = self::normalizeMessageId((string) ($headers['message_id'] ?? ''));
        try {
            $DB->insert(self::LOG_TABLE, [
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'result' => $result,
                'reason' => $reason,
                'message_hash' => $message_id !== '' ? hash('sha256', $message_id) : null,
                'message_id' => $message_id !== '' ? mb_substr($message_id, 0, 998) : null,
                'sender' => mb_substr(self::normalizeEmail((string) ($headers['from'] ?? '')), 0, 255) ?: null,
                'tickets_id' => $ticket_id,
                'details' => $details !== null ? mb_substr($details, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // Diagnostics must never turn a mail decision into a failed import.
            Toolbox::logInFile('mailthreadlink', 'Unable to write diagnostic log: ' . $e->getMessage() . "\n");
        }
    }
}
