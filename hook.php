<?php

function plugin_mailthreadlink_install(): bool
{
    PluginMailthreadlinkThreadmatcher::ensureSchema();
    PluginMailthreadlinkThreadmatcher::attachActionToAllRules();
    PluginMailthreadlinkThreadmatcher::ensureFallbackRule();

    return true;
}

function plugin_mailthreadlink_uninstall(): bool
{
    global $DB;

    $DB->delete(RuleAction::getTable(), [
        'field' => PluginMailthreadlinkThreadmatcher::ACTION_FIELD,
    ]);

    $fallback = new RuleMailCollector();
    $fallback->deleteByCriteria([
        'sub_type' => RuleMailCollector::class,
        'name' => PluginMailthreadlinkThreadmatcher::FALLBACK_RULE_NAME,
    ], true);

    if ($DB->tableExists(PluginMailthreadlinkThreadmatcher::TABLE)) {
        $DB->doQuery('DROP TABLE `' . PluginMailthreadlinkThreadmatcher::TABLE . '`');
    }
    if ($DB->tableExists(PluginMailthreadlinkThreadmatcher::LOG_TABLE)) {
        $DB->doQuery('DROP TABLE `' . PluginMailthreadlinkThreadmatcher::LOG_TABLE . '`');
    }

    return true;
}

function plugin_mailthreadlink_getRuleActions(array $params): array
{
    if (($params['rule_itemtype'] ?? '') !== RuleMailCollector::class) {
        return [];
    }

    return [
        PluginMailthreadlinkThreadmatcher::ACTION_FIELD => [
            'name' => __('Link replies using email thread headers', 'mailthreadlink'),
            'type' => 'yesonly',
            'force_actions' => [PluginMailthreadlinkThreadmatcher::ACTION_TYPE],
        ],
    ];
}

function plugin_mailthreadlink_executeActions(array $params): array
{
    $action = $params['action'] ?? null;
    if (
        !$action instanceof RuleAction
        || ($action->fields['field'] ?? '') !== PluginMailthreadlinkThreadmatcher::ACTION_FIELD
        || ($action->fields['action_type'] ?? '') !== PluginMailthreadlinkThreadmatcher::ACTION_TYPE
    ) {
        return [];
    }

    return PluginMailthreadlinkThreadmatcher::match($params);
}

function plugin_mailthreadlink_item_add(CommonDBTM $item): void
{
    PluginMailthreadlinkThreadmatcher::rememberItem($item);
}

function plugin_mailthreadlink_pre_item_add(ITILFollowup $followup): void
{
    PluginMailthreadlinkThreadmatcher::suppressReplyAllEchoNotification($followup);
}

function plugin_mailthreadlink_rule_add(RuleMailCollector $rule): void
{
    PluginMailthreadlinkThreadmatcher::ensureRuleAction((int) $rule->getID());
}

function plugin_mailthreadlink_item_purge(Ticket $ticket): void
{
    PluginMailthreadlinkThreadmatcher::forgetTicket((int) $ticket->getID());
}
