<?php
/**
 * Register, login, logout. Password reset by email is left to a mail plugin (hook account.forgot).
 */

function account_login(): never
{
    if (uid() > 0) redirect(url('/'));
    $back = get_str('back', 300);
    if (!str_starts_with($back, '/') || str_starts_with($back, '//')) $back = '';
    if (is_post()) {
        check_csrf();
        $name = post_str('username', 100);
        $pass = post_secret('password');
        $user = str_contains($name, '@') ? one('SELECT * FROM fb_users WHERE email=?', [mb_strtolower($name)]) : user_by_name($name);
        if (!login_throttle_ok()) fail(t('Too many attempts. Please wait a minute.'), url('/login'));
        $errors = (array)hook('account.login_validate', [], ['username' => $name]); // plugins: challenges, blocks
        if ($errors !== []) fail(implode(' ', $errors), url('/login', $back !== '' ? ['back' => $back] : []));
        if ($user === null || !password_verify($pass, (string)$user['password'])) {
            login_throttle_hit();
            fail(t('Incorrect username or password.'), url('/login', $back !== '' ? ['back' => $back] : []));
        }
        if ((int)$user['status'] !== 1) fail(t('This account is suspended.'), url('/login'));
        if (password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT)) {
            $user['password'] = password_hash($pass, PASSWORD_DEFAULT);
            db_update('fb_users', ['password' => $user['password']], 'id=?', [(int)$user['id']]);
        }
        login_user($user, post_int('remember', 1) === 1);
        fire('account.after_login', ['user' => $user]);
        redirect($back !== '' ? url($back) : url('/'));
    }
    page(t('Sign In'), view('login', ['back' => $back]), ['class' => 'page-auth', 'left' => false, 'right' => false]);
}

function account_register(): never
{
    if (uid() > 0) redirect(url('/'));
    if (setting('allow_register', '1') !== '1') error_page(t('Registration is currently closed.'), 403, t('Registration closed'));
    $errors = [];
    if (is_post()) {
        check_csrf();
        $name = post_str('username', 30);
        $email = mb_strtolower(post_str('email', 120));
        $pass = post_secret('password');
        $invite = post_str('invite', 60);
        if (!username_valid($name)) $errors[] = t('Username must be 2-30 characters: letters, numbers, dot, dash or underscore.');
        elseif (user_by_name($name) !== null) $errors[] = t('That username is already taken.');
        $verify = register_verify_on();
        if ($verify && $email === '') $errors[] = t('An email address is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Please enter a valid email address.');
        elseif ($email !== '' && val('SELECT 1 FROM fb_users WHERE email=?', [$email])) $errors[] = t('That email is already registered.');
        elseif ($verify && !email_code_check($email, post_str('code', 12))) $errors[] = t('The verification code is wrong or expired. Ask for a new one.');
        if (strlen($pass) < 8) $errors[] = t('Password must be at least 8 characters.');
        if (setting('invite_code', '') !== '' && !hash_equals(setting('invite_code'), $invite)) $errors[] = t('Invalid invite code.');
        if (post_str('website', 200) !== '') $errors[] = 'Spam detected.'; // honeypot
        if ((int)val('SELECT COUNT(*) FROM fb_users WHERE created_ip=? AND created_at>?', [client_ip(), now() - 3600]) >= 3) $errors[] = t('Too many registrations from your network. Please try later.');
        $errors = hook('account.register_validate', $errors, ['username' => $name, 'email' => $email]);
        if ($errors === []) {
            $uid = user_create($name, $email, $pass);
            if ($verify) db_update('fb_users', ['email_verified' => 1], 'id=?', [$uid]);
            $user = user_by_id($uid);
            login_user($user);
            fire('account.after_register', ['user' => $user]);
            flash(t('Welcome, %s!', $name));
            redirect(url('/'));
        }
        if (is_ajax()) json_error(implode(' ', $errors));
    }
    page(t('Create Account'), view('register', ['errors' => $errors, 'values' => ['username' => post_str('username', 30), 'email' => post_str('email', 120)]]), ['class' => 'page-auth', 'left' => false, 'right' => false]);
}

/** GET|POST /forgot — email a password reset link (never reveals whether the address exists). */
function account_forgot(): never
{
    if (uid() > 0) redirect(url('/settings/password'));
    $sent = false;
    if (is_post()) {
        check_csrf();
        if (!login_throttle_ok()) fail(t('Too many attempts. Please wait a minute.'), url('/forgot'));
        login_throttle_hit();
        $email = mb_strtolower(post_str('email', 120));
        $user = $email !== '' ? one('SELECT * FROM fb_users WHERE email=? AND status=1', [$email]) : null;
        if ($user !== null) {
            $token = random_token(24);
            db_update('fb_users', ['reset_token' => hash('sha256', $token), 'reset_expires' => now() + 3600], 'id=?', [(int)$user['id']]);
            $link = absolute_url('/reset/' . $token);
            $body = t("Hi %s,\n\nSomeone asked to reset the password of your account on %s. Open this link within one hour to choose a new password:\n\n%s\n\nIf you did not request this, ignore this email.", $user['username'], setting('site_name'), $link);
            if (!mail_send($email, t('[%s] Reset your password', setting('site_name')), $body)) {
                @error_log('[flatbb] password reset mail failed for user ' . $user['id']);
            }
        }
        $sent = true;
    }
    page(t('Forgot password'), view('forgot', ['sent' => $sent]), ['class' => 'page-auth', 'left' => false, 'right' => false, 'robots' => 'noindex']);
}

/** GET|POST /reset/{token} */
function account_reset(string $token): never
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) not_found();
    $user = one('SELECT * FROM fb_users WHERE reset_token=? AND reset_expires>?', [hash('sha256', $token), now()]);
    if ($user === null) error_page(t('This reset link is invalid or has expired. Request a new one.'), 410, t('Link expired'));
    if (is_post()) {
        check_csrf();
        $pass = post_secret('password');
        if (strlen($pass) < 8) fail(t('Password must be at least 8 characters.'), url('/reset/' . $token));
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db_update('fb_users', ['password' => $hash, 'reset_token' => '', 'reset_expires' => 0], 'id=?', [(int)$user['id']]);
        $user['password'] = $hash;
        login_user($user);
        flash(t('Password updated. You are signed in.'));
        redirect(url('/'));
    }
    page(t('Choose a new password'), view('reset', ['token' => $token, 'user' => $user]), ['class' => 'page-auth', 'left' => false, 'right' => false, 'robots' => 'noindex']);
}

function account_logout(): never
{
    require_post();
    logout_user();
    redirect(url('/'));
}

/** Simple per-IP throttle stored in a cache file (no DB writes on failed logins). */
function login_throttle_file(): string
{
    return CACHE_DIR . '/login_' . md5(client_ip()) . '.json';
}

function login_throttle_ok(): bool
{
    $f = login_throttle_file();
    if (!is_file($f)) return true;
    $d = json_decode_array((string)file_get_contents($f));
    if (now() - (int)($d['at'] ?? 0) > 60) return true;
    return (int)($d['n'] ?? 0) < 8;
}

function login_throttle_hit(): void
{
    $f = login_throttle_file();
    $d = is_file($f) ? json_decode_array((string)file_get_contents($f)) : [];
    if (now() - (int)($d['at'] ?? 0) > 60) $d = ['n' => 0, 'at' => now()];
    $d['n'] = (int)($d['n'] ?? 0) + 1;
    @file_put_contents($f, json_encode_value($d));
}
