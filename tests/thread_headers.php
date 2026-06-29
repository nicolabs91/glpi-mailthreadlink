<?php

require_once dirname(__DIR__) . '/inc/threadmatcher.class.php';

function assertSameValue(mixed $expected, mixed $actual, string $description): void
{
    if ($expected === $actual) {
        return;
    }

    fwrite(
        STDERR,
        sprintf(
            "%s\nExpected: %s\nActual:   %s\n",
            $description,
            var_export($expected, true),
            var_export($actual, true)
        )
    );
    exit(1);
}

assertSameValue(
    'original@example.test',
    PluginMailthreadlinkThreadmatcher::normalizeMessageId(
        ' <Original@Example.Test> '
    ),
    'Message IDs are normalized case-insensitively.'
);

assertSameValue(
    [
        'reply@example.test',
        'previous@example.test',
        'original@example.test',
    ],
    PluginMailthreadlinkThreadmatcher::referenceMessageIds([
        'in_reply_to' => '<reply@example.test>',
        'references' => '<original@example.test> <previous@example.test>',
    ]),
    'The nearest parent is checked first and duplicate references are removed.'
);

assertSameValue(
    ['latest@example.test', 'original@example.test'],
    PluginMailthreadlinkThreadmatcher::referenceMessageIds([
        'references' => 'original@example.test latest@example.test',
    ]),
    'Malformed but common whitespace-separated references are supported.'
);

assertSameValue(
    ['bare-parent@example.test', 'bracketed-parent@example.test'],
    PluginMailthreadlinkThreadmatcher::referenceMessageIds([
        'references' => '<bracketed-parent@example.test> bare-parent@example.test',
    ]),
    'Mixed bracketed and bare references are both retained.'
);

assertSameValue(
    [],
    PluginMailthreadlinkThreadmatcher::referenceMessageIds([]),
    'Missing thread headers do not produce a match.'
);

assertSameValue(
    false,
    PluginMailthreadlinkThreadmatcher::hasDirectHumanRecipients([
        'from' => 'or.shwartz@plusgrade.com',
        'to' => 'support@lbghotels.com',
        'tos' => ['support@lbghotels.com'],
        'ccs' => [],
    ], ['support@lbghotels.com']),
    'Support-only replies keep regular GLPI notifications enabled.'
);

assertSameValue(
    true,
    PluginMailthreadlinkThreadmatcher::hasDirectHumanRecipients([
        'from' => 'or.shwartz@plusgrade.com',
        'to' => 'support@lbghotels.com',
        'tos' => ['edith.vonken@lbghotels.com', 'support@lbghotels.com'],
        'ccs' => ['armando.vermeulen@lbghotels.com'],
    ], ['support@lbghotels.com']),
    'Reply-all messages suppress the GLPI echo notification.'
);

assertSameValue(
    false,
    PluginMailthreadlinkThreadmatcher::hasDirectHumanRecipients([
        'from' => 'Or Shwartz <or.shwartz@plusgrade.com>',
        'to' => 'IT Support <support@lbghotels.com>',
        'tos' => ['IT Support <support@lbghotels.com>'],
        'ccs' => ['Or Shwartz <or.shwartz@plusgrade.com>'],
    ], ['support@lbghotels.com']),
    'Display names are normalized before comparing email recipients.'
);

assertSameValue(
    true,
    PluginMailthreadlinkThreadmatcher::shouldDisableFollowupNotification([
        'itemtype' => 'Ticket',
        'items_id' => 3759,
        '_head' => [
            'from' => 'n.janssen91@gmail.com',
            'to' => null,
            'tos' => ['n.janssen@lbghotels.com'],
            'ccs' => ['support@lbghotels.com'],
            'references' => '<GLPI_z0IFN2x4AfAv2gbqRtmNpsUwzi69DZsPKjvwGz4Q-Ticket-3759/new@cbd52f2be740>',
        ],
    ]),
    'GLPI-native threaded reply-all follow-ups suppress the support echo notification.'
);

assertSameValue(
    false,
    PluginMailthreadlinkThreadmatcher::shouldDisableFollowupNotification([
        'itemtype' => 'Ticket',
        'items_id' => 3759,
        '_head' => [
            'from' => 'n.janssen91@gmail.com',
            'to' => 'support@lbghotels.com',
            'tos' => ['support@lbghotels.com'],
            'ccs' => [],
        ],
    ]),
    'Support-only mailcollector follow-ups keep regular GLPI notifications enabled.'
);

fwrite(STDOUT, "Mail Thread Link header tests passed.\n");
