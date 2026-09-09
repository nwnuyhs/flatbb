<?php
/**
 * Public profiles (/u/name) and account settings (/settings).
 */

/** Whether members may change their own username (Admin -> Settings -> Registration). */
function user_rename_allowed(): bool
{
    return setting('allow_rename', '0') === '1';
}

/** Unix time from which this member may rename again (0 when never renamed). */
function user_rename_next(array $user): int
{
    $former = user_former_names($user);
    $last = $former === [] ? 0 : (int)end($former)['at'];
    $days = max(0, (int)setting('rename_days', '30'));
    return $last > 0 ? $last + $days * 86400 : 0;
}

/** GET /u/{name}[/{tab}] tabs: topics, replies, bookmarks (own only) */
function user_profile(string $name, string $tab = 'topics'): never
{
    $user = user_by_name(rawurldecode($name));
    if ($user === null && ($renamed = user_by_former_name(rawurldecode($name))) !== null) redirect(user_url($renamed) . ($tab !== 'topics' ? '/' . $tab : ''), 301);
    if ($user === null) not_found();
    $self = uid() === (int)$user['id'];
    $tabs = [
        'topics' => ['label' => t('Topics'), 'url' => user_url($user), 'badge' => (int)$user['topic_count'] ?: ''],
        'replies' => ['label' => t('Replies'), 'url' => user_url($user) . '/replies', 'badge' => (int)$user['post_count'] ?: ''],
    ];
    if ($self) $tabs['bookmarks'] = ['label' => t('Bookmarks'), 'url' => user_url($user) . '/bookmarks'];
    $tabs = region_list('user.profile.tabs', $tabs, ['user' => $user, 'self' => $self]);
    if (!isset($tabs[$tab])) not_found();
    foreach ($tabs as $k => &$t) $t['active'] = $k === $tab;
    unset($t);
    $p = topic_list_page();
    $body = '';
    $pg = ['pages' => 1];
    $url_fn = static fn(int $n): string => user_url($user) . ($tab === 'topics' ? '' : '/' . $tab) . ($n > 1 ? '?page=' . $n : '');
    if ($tab === 'topics') {
        $list = topic_list_fetch('user_id=?', [(int)$user['id']], 'created_at DESC', $p);
        $body = view('topic_rows', ['topics' => $list['topics'], 'empty' => t('No topics yet.')]);
        $pg = $list['pagination'];
    } elseif ($tab === 'replies') {
        $pg = paginate_calc((int)val('SELECT COUNT(*) FROM fb_posts WHERE user_id=? AND floor>0 AND is_deleted=0', [(int)$user['id']]), $p['page'], $p['per_page']);
        $posts = all('SELECT * FROM fb_posts WHERE user_id=? AND floor>0 AND is_deleted=0 ORDER BY id DESC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [(int)$user['id']]);
        $topics = rows_by_ids('fb_topics', array_column($posts, 'topic_id'), 'id,title,slug,category_id,is_deleted');
        $body = view('post_rows', ['posts' => $posts, 'topics' => $topics, 'user' => $user, 'empty' => t('No replies yet.')]);
    } elseif ($tab === 'bookmarks') {
        $list = topic_list_fetch('t.id IN (SELECT topic_id FROM fb_bookmarks WHERE user_id=?)', [(int)$user['id']], 'last_post_at DESC', $p);
        $body = view('topic_rows', ['topics' => $list['topics'], 'empty' => t('No bookmarks yet.')]);
        $pg = $list['pagination'];
    } else {
        $body = (string)hook('user.profile_tab', '', ['user' => $user, 'tab' => $tab, 'self' => $self]);
    }
    $base_stats = [
        'topics' => ['label' => t('Topics'), 'value' => human_number((int)$user['topic_count']), 'url' => user_url($user)],
        'replies' => ['label' => t('Replies'), 'value' => human_number((int)$user['post_count']), 'url' => user_url($user) . '/replies'],
        'likes' => ['label' => t('Likes'), 'value' => human_number((int)$user['like_count'])],
    ];
    if (points_public($user) && ((int)$user['points'] !== 0 || $self)) $base_stats['points'] = ['label' => t('Points'), 'value' => human_number((int)$user['points']), 'url' => $self ? url('/settings/points') : ''];
    $stats = region_list('user.profile.stats', $base_stats, ['user' => $user]);
    $main = view('profile', ['user' => $user, 'group' => group_by_id((int)$user['group_id']), 'self' => $self, 'tabs' => tabs($tabs), 'body' => $body, 'pagination' => pagination($pg, $url_fn), 'stats' => $stats]);
    $cards = region_list('user.profile.cards', [], ['user' => $user]);
    page($user['username'], $main, ['class' => 'page-profile', 'right' => $cards === [] ? false : view('sidebar_right', ['cards' => $cards])]);
}

/** GET|POST /settings[/{tab}] tabs: profile, avatar, password, preferences */
function user_settings(string $tab = 'profile'): never
{
    $me = need_login();
    $tabs = [
        'profile' => ['label' => t('Profile'), 'group' => 'account', 'weight' => 10],
        'avatar' => ['label' => t('Avatar'), 'group' => 'account', 'weight' => 20],
        'password' => ['label' => t('Password'), 'group' => 'account', 'weight' => 30],
        'preferences' => ['label' => t('Preferences'), 'group' => 'preferences', 'weight' => 40],
        'points' => ['label' => t('Points'), 'group' => 'community', 'weight' => 60],
    ];
    $tabs = region_list('user.settings.tabs', $tabs, ['user' => $me]);
    foreach ($tabs as $k => $item) if (!is_array($item)) $tabs[$k] = ['label' => (string)$item, 'group' => 'more', 'weight' => 100]; // a plugin that only gave a label
    if (!isset($tabs[$tab])) not_found();
    if (is_post()) {
        check_csrf();
        $back = url('/settings/' . $tab);
        if ($tab === 'profile') {
            $website = post_str('website', 200);
            if ($website !== '' && !preg_match('#^https?://#i', $website)) $website = 'https://' . $website;
            if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) fail(t('Please enter a valid website URL.'), $back);
            $email = mb_strtolower(post_str('email', 120));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(t('Please enter a valid email address.'), $back);
            if ($email !== '' && val('SELECT 1 FROM fb_users WHERE email=? AND id<>?', [$email, (int)$me['id']])) fail(t('That email is already registered.'), $back);
            $email_changed = $email !== (string)$me['email'];
            if ($email_changed && $email !== '' && register_verify_on() && !email_code_check($email, post_str('code', 12))) fail(t('The verification code is wrong or expired. Ask for a new one.'), $back);
            if ($email_changed) db_update('fb_users', ['email_verified' => $email !== '' && register_verify_on() ? 1 : 0], 'id=?', [(int)$me['id']]);
            $data = hook('user.before_save', ['email' => $email, 'bio' => post_str('bio', 1000), 'website' => $website, 'location' => post_str('location', 80), 'signature' => post_str('signature', 300)], ['user' => $me]);
            db_update('fb_users', $data, 'id=?', [(int)$me['id']]);
            $renamed = false;
            $new_name = post_str('username', 30);
            if (user_rename_allowed() && $new_name !== '' && $new_name !== (string)$me['username']) {
                $next = user_rename_next((array)$me);
                if ($next > now()) fail(t('You can change your username again on %s.', date('Y-m-d', $next)), $back);
                $err = user_rename((array)$me, $new_name, (int)$me['id']);
                if ($err !== '') fail($err, $back);
                $renamed = true;
            }
            fire('user.after_save', ['user_id' => (int)$me['id']]);
            flash($renamed ? t('Profile saved. Your username is now %s.', $new_name) : t('Profile saved.'));
        } elseif ($tab === 'avatar') {
            $f = $_FILES['avatar'] ?? null;
            if (post_int('remove') === 1) {
                db_update('fb_users', ['avatar' => ''], 'id=?', [(int)$me['id']]);
                flash(t('Avatar removed.'));
            } elseif (is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK) {
                if ((int)$f['size'] > 4 * 1048576) fail(t('Avatar must be smaller than 4 MB.'), $back);
                try {
                    $path = avatar_store((int)$me['id'], (string)$f['tmp_name']);
                } catch (RuntimeException $e) {
                    fail($e->getMessage(), $back);
                }
                db_update('fb_users', ['avatar' => $path], 'id=?', [(int)$me['id']]);
                flash(t('Avatar updated.'));
            } else {
                fail(t('Please choose an image.'), $back);
            }
        } elseif ($tab === 'password') {
            $old = post_secret('old_password');
            $new = post_secret('password');
            if ((string)$me['password'] !== '' && !password_verify($old, (string)$me['password'])) fail(t('Current password is incorrect.'), $back); // '' = no password yet (social sign-up)
            if (strlen($new) < 8) fail(t('Password must be at least 8 characters.'), $back);
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db_update('fb_users', ['password' => $hash], 'id=?', [(int)$me['id']]);
            $me['password'] = $hash;
            login_user($me);
            flash(t('Password changed.'));
        } elseif ($tab === 'preferences') {
            $prefs = json_decode_array((string)$me['prefs']);
            $prefs['theme'] = in_array(post_str('theme', 10), ['auto', 'light', 'dark'], true) ? post_str('theme', 10) : 'auto';
            $prefs['notify_reply'] = post_int('notify_reply') ? 1 : 0;
            $prefs['notify_mention'] = post_int('notify_mention') ? 1 : 0;
            $prefs['show_points'] = post_int('show_points') ? 1 : 0;
            $prefs['lang'] = isset(lang_available()[post_str('lang', 10)]) ? post_str('lang', 10) : '';
            $prefs = hook('user.prefs_save', $prefs, ['user' => $me]);
            db_update('fb_users', ['prefs' => json_encode_value($prefs)], 'id=?', [(int)$me['id']]);
            $me['prefs'] = json_encode_value($prefs);
            lang_set((string)$prefs['lang']); // the header switcher and this select stay in step
            flash(t('Preferences saved.'));
        } else {
            fire('user.settings_post', ['user' => $me, 'tab' => $tab]);
        }
        redirect($back);
    }
    $me = user_by_id((int)$me['id']);
    $extra = (string)hook('user.settings_tab', '', ['user' => $me, 'tab' => $tab]);
    if ($tab === 'points') {
        $log = points_log((int)$me['id'], get_int('page', 1, 1, 10000));
        $extra = '<p class="points-balance"><b>' . human_number((int)$me['points']) . '</b> ' . t('points') . ' <a class="btn btn-sm" href="' . h(url('/points')) . '">' . icon('star') . t('My points page') . '</a> <span class="muted small">' . t('Only you and the staff can see this history.') . '</span></p>'
            . points_log_html($log['rows']) . pagination($log['pagination'], static fn(int $n): string => url('/settings/points', $n > 1 ? ['page' => $n] : []));
    }
    $index = current_path() === '/settings'; // phones show the grouped list here and the form one level down
    page(t('Settings'), view('settings', ['user' => $me, 'tab' => $tab, 'tabs' => $tabs, 'prefs' => json_decode_array((string)$me['prefs']), 'extra' => $extra, 'index' => $index]), ['class' => 'page-settings', 'left' => false, 'right' => false]);
}

function user_pref(string $key, mixed $default = null): mixed
{
    $me = me();
    if ($me === null) return $default;
    $prefs = request_cache('my_prefs', static fn(): array => json_decode_array((string)$me['prefs'])) ?? [];
    return $prefs[$key] ?? $default;
}
