<?php
/**
 * Translation. t('New Topic') returns the string itself in English, or the translation
 * from lang/<code>.php when one exists. Plugins may ship plugins/<id>/lang/<code>.php.
 * Keys are the English source strings; sprintf placeholders are supported: t('%d replies', $n).
 * The active language is per visitor: the member's preference (Settings → Preferences), else the
 * fb_lang cookie set by the header switcher, else the admin setting site_lang (Settings → General),
 * else 'lang' in data/config.php. An empty translation means "not translated yet": English is shown.
 * Language files may carry a '__name' key with the language's native name and '__dir' => 'rtl' for right-to-left scripts.
 */

function lang_code(): string
{
    return request_cache('lang_code', static function (): string {
        if (!IS_CLI && is_installed()) {
            $me = me();
            $mine = $me !== null ? (string)(json_decode_array((string)$me['prefs'])['lang'] ?? '') : '';
            foreach ([$mine, cookie_str('fb_lang', 10)] as $c) if ($c !== '' && lang_installed($c)) return $c;
        }
        return lang_site_code();
    }) ?? 'en';
}

/** The administrator's default language (Settings → General), before any per-visitor choice. */
function lang_site_code(): string
{
    $code = setting('site_lang') ?: (string)config('lang', 'en');
    return lang_installed($code) ? $code : 'en';
}

function lang_installed(string $code): bool
{
    return $code === 'en' || (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) === 1 && is_file(LANG_DIR . '/' . $code . '.php'));
}

/** Remember a visitor's language: cookie for a year, and the preference of a signed-in member. '' returns to the site default. */
function lang_set(string $code): void
{
    if ($code !== '' && !lang_installed($code)) return;
    app_cookie('fb_lang', $code, $code === '' ? now() - 3600 : now() + 86400 * 365);
    $me = me();
    if ($me !== null) {
        $prefs = json_decode_array((string)$me['prefs']);
        $prefs['lang'] = $code;
        db_update('fb_users', ['prefs' => json_encode_value($prefs)], 'id=?', [(int)$me['id']]);
    }
    request_cache('lang_code', null, true);
    request_cache('lang', null, true);
}

/** Installed language packs: code => native name (English always first). */
function lang_available(): array
{
    $list = ['en' => 'English'];
    foreach (glob(LANG_DIR . '/*.php') ?: [] as $file) {
        $code = basename($file, '.php');
        if ($code === 'en') continue;
        $table = (array)include $file;
        $list[$code] = (string)($table['__name'] ?? $code);
    }
    return $list;
}

/** 'rtl' when the active pack declares '__dir' => 'rtl' (Persian, Arabic, Hebrew…), else 'ltr'. */
function lang_direction(): string
{
    return (string)(lang_table()['__dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
}

function lang_table(): array
{
    return request_cache('lang', static function (): array {
        $code = lang_code();
        $table = [];
        $file = LANG_DIR . '/' . $code . '.php';
        if ($code !== 'en' && is_file($file)) $table = (array)include $file;
        return $table;
    }) ?? [];
}

/** Merge a plugin's translations at runtime. */
function lang_add(array $strings): void
{
    $table = lang_table();
    request_cache('lang', null, true);
    request_cache('lang', static fn(): array => $strings + $table);
}

function t(string $key, mixed ...$args): string
{
    $s = lang_table()[$key] ?? '';
    if ($s === '') $s = $key;
    return $args === [] ? $s : vsprintf($s, $args);
}
