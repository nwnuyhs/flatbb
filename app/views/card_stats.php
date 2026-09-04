<?php /** Site statistics card. Variable: stats */ ?>
<?= card(t('Statistics'), '<ul class="stat-list">'
    . '<li><span>' . t('Topics') . '</span><b>' . human_number((int)($stats['topics'] ?? 0)) . '</b></li>'
    . '<li><span>' . t('Replies') . '</span><b>' . human_number((int)($stats['posts'] ?? 0)) . '</b></li>'
    . '<li><span>' . t('Members') . '</span><b>' . human_number((int)($stats['users'] ?? 0)) . '</b></li>'
    . '<li><span>' . t('Online') . '</span><b>' . (int)($stats['online'] ?? 0) . '</b></li>'
    . (!empty($stats['newest']) ? '<li><span>' . t('Newest') . '</span><b>' . user_link(['username' => $stats['newest'], 'group_id' => 0]) . '</b></li>' : '')
    . '</ul>', 'card-stats') ?>
