<?php

class PluginMailthreadlinkThreadmatcher
{
    public const TABLE = 'glpi_plugin_mailthreadlink_messages';
    public const ACTION_FIELD = '_mailthreadlink';
    public const ACTION_TYPE = 'mailthreadlink';
    public const FALLBACK_RULE_NAME = 'Mail Thread Link fallback';

    public static function ensureSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

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

    public static function match(array $params): array
    {
        $ticket_input = $params['params']['ticket'] ?? [];
        $headers = $params['params']['headers'] ?? [];

        if (!is_array($ticket_input) || !is_array($headers)) {
            return [];
        }

        $message_id = self::normalizeMessageId((string) ($headers['message_id'] ?? ''));
        if ($message_id !== '' && self::messageExists($message_id)) {
            return ['_refuse_email_no_response' => 1];
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

            return [
                'tickets_id' => $ticket_id,
                'requesttypes_id' => RequestType::getDefault('mailfollowup'),
                'add_reopen' => 1,
            ];
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

            preg_match_all('/<([^>]+)>/', $value, $matches);
            $ids = $matches[1] ?? [];
            if ($ids === []) {
                $ids = preg_split('/\s+/', trim($value)) ?: [];
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
        return self::findTicketId($message_id) !== null;
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
    }
}
