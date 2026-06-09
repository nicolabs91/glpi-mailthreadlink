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

fwrite(STDOUT, "Mail Thread Link header tests passed.\n");
