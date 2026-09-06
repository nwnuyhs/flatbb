<?php
/**
 * Email verification codes: a six-digit code mailed to an address, checked before an account is created or an address
 * is changed (setting register_verify). Codes are stored hashed with the site secret, expire after ten minutes, allow
 * five attempts, and are throttled per address (one per minute) and per visitor address (ten per hour).
 */
if (!defined('FLATBB')) exit;

const EMAIL_CODE_TTL = 600;

function register_verify_on(): bool
{
    return setting('register_verify', '0') === '1';
}

function email_code_hash(string $email, string $code): string
{
    return hash_hmac('sha256', strtolower($email) . ':' . $code, secret());
}

/**
 * Create a code for an address (replacing an older one) and return it in clear, or '' with $error set when the address
 * is throttled. mail_send() is left to email_code_send() so tests can check codes without a mail server.
 */
function email_code_issue(string $email, string $ip, ?string &$error = null): string
{
    $error = null;
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = t('Please enter a valid email address.'); return ''; }
    $last = one('SELECT * FROM fb_email_codes WHERE email=?', [$email]);
    if ($last !== null && (int)$last['sent_at'] > now() - 60) { $error = t('A code was sent a moment ago. Wait a minute before asking for another one.'); return ''; }
    if ((int)val('SELECT COUNT(*) FROM fb_email_codes WHERE ip=? AND sent_at>?', [$ip, now() - 3600]) >= 10) { $error = t('Too many codes requested from your network. Please try later.'); return ''; }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_upsert('fb_email_codes', ['email' => $email, 'code_hash' => email_code_hash($email, $code), 'ip' => cut($ip, 45, ''), 'attempts' => 0, 'sent_at' => now(), 'expires_at' => now() + EMAIL_CODE_TTL], ['email']);
    return $code;
}

/** Issue and mail a code. Returns '' on success or the message to show. */
function email_code_send(string $email, string $ip): string
{
    $code = email_code_issue($email, $ip, $error);
    if ($code === '') return (string)$error;
    $text = t("Your verification code for %s is:\n\n%s\n\nIt is valid for 10 minutes. If you did not ask for it, ignore this email.", setting('site_name'), $code);
    if (!mail_send(strtolower(trim($email)), t('[%s] Your verification code', setting('site_name')), $text)) {
        db_delete('fb_email_codes', 'email=?', [strtolower(trim($email))]);
        return t('The email could not be sent. The site has no working mail setup; please tell the administrator.');
    }
    return '';
}

/** Check a code for an address; a correct code is consumed. Five wrong attempts void the code. */
function email_code_check(string $email, string $code): bool
{
    $email = strtolower(trim($email));
    $code = preg_replace('/\D+/', '', $code) ?? '';
    $row = one('SELECT * FROM fb_email_codes WHERE email=?', [$email]);
    if ($row === null || (int)$row['expires_at'] < now() || (int)$row['attempts'] >= 5 || strlen($code) !== 6) return false;
    if (!hash_equals((string)$row['code_hash'], email_code_hash($email, $code))) {
        db_increment('fb_email_codes', 'attempts', 1, 'email=?', [$email]);
        return false;
    }
    db_delete('fb_email_codes', 'email=?', [$email]);
    return true;
}

/** Which plugin delivers mail (mail.send hook), or PHP's mail() when none does. Shown next to the setting. */
function mail_transport_label(): string
{
    foreach (plugin_manifests() as $m) if (isset($m['hooks']['mail.send'])) return (string)$m['name'];
    return 'PHP mail()';
}

/** Cron: codes older than a day. */
function email_code_cleanup(): int
{
    return db_delete('fb_email_codes', 'expires_at<?', [now() - 86400]);
}
