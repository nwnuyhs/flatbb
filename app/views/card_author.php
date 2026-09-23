<?php
/**
 * Author card on topic pages: the member card (view member_card, place "author") with Topics, Replies and Likes, when they joined
 * and were last seen, and the buttons plugins add (Message, Follow). Variable: user (may be null)
 */
?>
<?php if ($user):
$self = (int)$user['id'] === uid();
$stats = [
    'topics' => ['label' => t('Topics'), 'value' => human_number((int)$user['topic_count']), 'url' => user_url($user), 'weight' => 10],
    'replies' => ['label' => t('Replies'), 'value' => human_number((int)$user['post_count']), 'url' => user_url($user) . '/replies', 'weight' => 20],
    'likes' => ['label' => t('Likes'), 'value' => human_number((int)$user['like_count']), 'weight' => 30],
];
$foot = icon('clock') . t('Joined %s', time_tag((int)$user['created_at'], 'month')) . '<span class="me-dot">·</span>' . t('Seen %s', time_tag((int)$user['last_seen']));
?>
<?= view('member_card', ['user' => $user, 'place' => 'author', 'self' => $self, 'stats' => $stats, 'actions' => [], 'foot' => $foot, 'links' => []]) ?>
<?php endif; ?>
