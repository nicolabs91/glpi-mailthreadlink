<?php

include('../../../inc/includes.php');

Session::checkRight('config', READ);

$rows = PluginMailthreadlinkThreadmatcher::getLogRows(200);
Html::header(__('Mail Thread Link log', 'mailthreadlink'), $_SERVER['PHP_SELF'], 'config', 'plugin');

echo '<div class="center">';
echo '<h2>' . htmlescape(__('Mail Thread Link log', 'mailthreadlink')) . '</h2>';
echo '<p>' . htmlescape(__('This log records decisions made by the plugin. GLPI refusals that happen before or outside this plugin remain visible in GLPI’s own mail collector logs.', 'mailthreadlink')) . '</p>';
echo '<table class="tab_cadre_fixehov">';
echo '<tr><th>' . __('Date') . '</th><th>' . __('Result') . '</th><th>' . __('Reason') . '</th><th>Message-ID</th><th>' . __('Sender') . '</th><th>' . __('Ticket') . '</th></tr>';
foreach ($rows as $row) {
    echo '<tr>';
    foreach (['date_creation', 'result', 'reason', 'message_id', 'sender'] as $field) {
        echo '<td>' . htmlescape((string) ($row[$field] ?? '')) . '</td>';
    }
    echo '<td>' . ((int) ($row['tickets_id'] ?? 0) ?: '') . '</td></tr>';
}
if ($rows === []) {
    echo '<tr><td colspan="6">' . htmlescape(__('No plugin events have been recorded yet.', 'mailthreadlink')) . '</td></tr>';
}
echo '</table></div>';
Html::footer();
