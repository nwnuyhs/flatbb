<?php
/**
 * Authentication and permissions. Cookie based, no PHP sessions.
 *
 * Login cookie: fb_auth = "<uid>.<expires>.<hmac>" signed with the site secret and the
 * user's password hash, so changing the password invalidates every session.
 * CSRF: every state-changing POST must carry the token from csrf_token() (see check_csrf()).
 */

function secret(): string
{
    return (string)config('secret', '');
}

function uid(): int
{
    return (int)(me()['id'] ?? 0);
}

/** Current user row or null. Cached per request. */
function me(): ?array
{
    return request_cache('me', static function (): ?array {
        $raw = (string)($_COOKIE['fb_auth'] ?? '');
        if ($raw === '' || substr_count($raw, '.') !== 2) return null;
        [$id, $exp, $sig] = explode('.', $raw);
        if (!ctype_digit($id) || !ctype_digit($exp) || (int)$exp < now()) return null;
        $user = user_by_id((int)$id);
        if ($user === null || (int)$user['status'] !== 1) return null;
        if (!hash_equals(auth_signature((int)$id, (int)$exp, $user['password']), $sig)) return null;
        if (now() - (int)$user['last_seen'] > 300) {
            db_update('fb_users', ['last_seen' => now()], 'id=?', [(int)$id]);
        }
        return $user;
    });
}

function auth_signature(int $uid, int $exp, string $password_hash): string
{
    return hash_hmac('sha256', $uid . '.' . $exp . '.' . $password_hash, secret());
}

function login_user(array $user, bool $remember = true): void
{
    $exp = now() + ($remember ? 86400 * 30 : 86400);
    app_cookie('fb_auth', $user['id'] . '.' . $exp . '.' . auth_signature((int)$user['id'], $exp, $user['password']), $exp);
    request_cache('me', null, true);
    request_cache('me', static fn(): array => $user);
}

function logout_user(): void
{
    app_cookie('fb_auth', '', now() - 3600);
    request_cache('me', null, true);
}

/** Visitor token cookie used as CSRF seed for guests and members alike. */
function visitor_token(): string
{
    $v = (string)($_COOKIE['fb_vt'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $v)) {
        $v = random_token(16);
        app_cookie('fb_vt', $v, now() + 86400 * 365);
    }
    return $v;
}

function csrf_token(): string
{
    return hash_hmac('sha256', 'csrf.' . visitor_token() . '.' . uid(), secret());
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

/** Verified once per request by dispatch() for every POST; handlers may still call it (no-op the second time). */
function check_csrf(): void
{
    static $ok = false;
    if ($ok) return;
    $token = (string)($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        error_page(t('Your session expired. Please go back and try again.'), 419, t('Invalid token'));
    }
    $ok = true;
}

/** POST only + CSRF. */
function require_post(): void
{
    if (!is_post()) error_page(t('Method not allowed.'), 405);
    check_csrf();
}

function need_login(): array
{
    $me = me();
    if ($me === null) {
        if (is_ajax()) json_error(t('Please sign in first.'), 401);
        flash(t('Please sign in first.'), 'info');
        redirect(url('/login', ['back' => current_path()]));
    }
    return $me;
}

function need_admin(): array
{
    $me = need_login();
    if (!is_admin()) forbidden();
    return $me;
}

function need_mod(): array
{
    $me = need_login();
    if (!is_mod()) forbidden();
    return $me;
}

/* ---------------------------------------------------------------- groups */

function groups(): array
{
    return request_cache('groups', static function (): array {
        $out = [];
        foreach (all('SELECT * FROM fb_groups ORDER BY sort,id') as $g) {
            $g['permissions'] = json_decode_array($g['permissions'] ?? '');
            $out[(int)$g['id']] = $g;
        }
        return $out;
    }) ?? [];
}

function group_by_id(int $id): ?array
{
    return groups()[$id] ?? null;
}

function my_group(): ?array
{
    $me = me();
    return $me === null ? null : group_by_id((int)$me['group_id']);
}

function is_admin(): bool
{
    return (int)(my_group()['is_admin'] ?? 0) === 1;
}

function is_mod(): bool
{
    $g = my_group();
    return $g !== null && ((int)$g['is_admin'] === 1 || (int)$g['is_mod'] === 1);
}

/** can('post'), can('reply'), can('upload'), can('edit_own'), can('delete_own') */
function can(string $permission): bool
{
    $g = my_group();
    if ($g === null) return false;
    if ((int)$g['is_admin'] === 1) return true;
    return in_array($permission, $g['permissions'], true);
}

/** Group id list from a comma separated setting; empty means "everyone". */
function group_allowed(string $csv, bool $guest_ok = true): bool
{
    $csv = trim($csv);
    if ($csv === '') return $guest_ok || uid() > 0;
    if (is_admin()) return true;
    $me = me();
    if ($me === null) return false;
    return in_array((string)(int)$me['group_id'], array_map('trim', explode(',', $csv)), true);
}

/* ---------------------------------------------------------------- users */

/** Full user row (all columns). Cached per request under 'users_full'. */
function user_by_id(int $id): ?array
{
    if ($id <= 0) return null;
    $cache = request_cache('users_full') ?? [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    $u = one('SELECT * FROM fb_users WHERE id=?', [$id]);
    users_cache_put([$id => $u], 'users_full');
    if ($u !== null) users_cache_put([$id => $u], 'users');
    return $u;
}

function user_by_name(string $name): ?array
{
    return one('SELECT * FROM fb_users WHERE username_lower=?', [mb_strtolower($name)]);
}

function users_cache_put(array $users, string $bucket = 'users'): void
{
    $cache = request_cache($bucket) ?? [];
    request_cache($bucket, null, true);
    $merged = $users + $cache;
    request_cache($bucket, static fn(): array => $merged);
}

/** Public user columns safe to render anywhere (no password/email). */
function user_public_columns(): string
{
    return 'id,username,avatar,group_id,post_count,topic_count,like_count,status,last_seen,created_at,points';
}

/** Batch-load users (public columns) for a list of ids, keyed by id. Never call in a loop. */
function users_by_ids(array $ids): array
{
    $cache = request_cache('users') ?? [];
    $missing = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0 && !array_key_exists($id, $cache)));
    if ($missing !== []) {
        $rows = rows_by_ids('fb_users', $missing, user_public_columns());
        foreach ($missing as $id) $rows[$id] ??= null;
        users_cache_put($rows);
        $cache = request_cache('users') ?? [];
    }
    $out = [];
    foreach ($ids as $id) if (!empty($cache[(int)$id])) $out[(int)$id] = $cache[(int)$id];
    return $out;
}

function username_valid(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{1,29}$/', $name);
}

function user_create(string $username, string $email, string $password, int $group_id = 0): int
{
    if ($group_id <= 0) $group_id = (int)val("SELECT id FROM fb_groups WHERE slug='member'");
    return db_insert('fb_users', [
        'username' => $username,
        'username_lower' => mb_strtolower($username),
        'email' => mb_strtolower($email),
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'group_id' => $group_id,
        'status' => 1,
        'created_at' => now(),
        'created_ip' => client_ip(),
        'last_seen' => now(),
        'prefs' => '{}',
    ]);
}

function user_url(array|string $user): string
{
    return url('/u/' . rawurlencode(is_array($user) ? (string)$user['username'] : $user));
}

/** Rate limit helper: returns seconds the user must still wait before posting. */
function post_wait_seconds(array $user): int
{
    if (is_mod()) return 0;
    $interval = (int)setting('post_interval', '15');
    $wait = (int)$user['last_post_at'] + $interval - now();
    return max(0, $wait);
}

function new_user_limited(array $user): bool
{
    if (is_mod()) return false;
    $hours = (int)setting('new_user_limit_hours', '24');
    $max = (int)setting('new_user_max_posts', '5');
    if ($hours <= 0 || $max <= 0) return false;
    return now() - (int)$user['created_at'] < $hours * 3600 && ((int)$user['post_count'] + (int)$user['topic_count']) >= $max;
}
