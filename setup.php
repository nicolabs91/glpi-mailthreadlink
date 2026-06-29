<?php

if (!defined('GLPI_ROOT')) {
    die('Sorry. You cannot access this file directly');
}

define('PLUGIN_MAILTHREADLINK_VERSION', '0.1.1');

function plugin_init_mailthreadlink(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass('PluginMailthreadlinkThreadmatcher');

    $PLUGIN_HOOKS['csrf_compliant']['mailthreadlink'] = true;
    $PLUGIN_HOOKS['use_rules']['mailthreadlink'] = [RuleMailCollector::class];
    $PLUGIN_HOOKS['pre_item_add']['mailthreadlink'] = [
        ITILFollowup::class => 'plugin_mailthreadlink_pre_item_add',
    ];
    $PLUGIN_HOOKS['item_add']['mailthreadlink'] = [
        Ticket::class => 'plugin_mailthreadlink_item_add',
        ITILFollowup::class => 'plugin_mailthreadlink_item_add',
        RuleMailCollector::class => 'plugin_mailthreadlink_rule_add',
    ];
    $PLUGIN_HOOKS['item_purge']['mailthreadlink'] = [
        Ticket::class => 'plugin_mailthreadlink_item_purge',
    ];
}

function plugin_version_mailthreadlink(): array
{
    return [
        'name' => 'Mail Thread Link',
        'version' => PLUGIN_MAILTHREADLINK_VERSION,
        'author' => 'Nicolabs91',
        'license' => 'GPLv3+',
        'homepage' => 'https://github.com/nicolabs91/glpi-mailthreadlink',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_mailthreadlink_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.0', '>=');
}

function plugin_mailthreadlink_check_config(bool $verbose = false): bool
{
    return true;
}
