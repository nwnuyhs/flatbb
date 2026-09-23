<?php
/**
 * Right column user card: sign-in box for guests; for members the member card (view member_card, place "card") with Topics,
 * Replies, Likes and Points, shortcuts from the account menu, and New Topic first among the buttons. Variable: me
 */
?>
<?php if ($me === null): ?>
<section class="card card-login">
  <div class="card-body">
    <p><?= t('Join the conversation. Sign in to post and reply.') ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="<?= h(url('/login')) ?>"><?= t('Sign in') ?></a>
      <?php if (setting('allow_register', '1') === '1'): ?><a class="btn" href="<?= h(url('/register')) ?>"><?= t('Sign up') ?></a><?php endif; ?>
    </div>
  </div>
</section>
<?php else:
// the "you" items of the account menu as shortcuts, the first few (setting card_shortcuts, 0 = none; six fill three rows of two)
$max = max(0, (int)setting('card_shortcuts', '6'));
$links = $max > 0 ? array_slice(array_filter(user_menu_items($me), static fn($it): bool => is_array($it) && (string)($it['group'] ?? 'you') === 'you' && ($it['card'] ?? true) !== false), 0, $max, true) : [];
?>
<?= view('member_card', ['user' => $me, 'place' => 'card', 'self' => true, 'foot' => '', 'links' => $links, 'stats' => [
    'topics' => ['label' => t('Topics'), 'value' => human_number((int)$me['topic_count']), 'url' => user_url($me), 'weight' => 10],
    'replies' => ['label' => t('Replies'), 'value' => human_number((int)$me['post_count']), 'url' => user_url($me) . '/replies', 'weight' => 20],
    'likes' => ['label' => t('Likes'), 'value' => human_number((int)$me['like_count']), 'url' => url('/notifications'), 'weight' => 30],
    'points' => ['label' => t('Points'), 'value' => human_number((int)$me['points']), 'url' => url('/points'), 'weight' => 40],
], 'actions' => can('post') ? ['new' => ['label' => t('New Topic'), 'url' => new_topic_url(), 'icon' => 'plus', 'primary' => true, 'weight' => -10]] : []]) ?>
<?php endif; ?>
