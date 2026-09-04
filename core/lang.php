<?php
/**
 * Translation. t('New Topic') returns the string itself in English, or the translation
 * from lang/<code>.php when one exists. Plugins may ship plugins/<id>/lang/<code>.php.
 * Keys are the English source strings; sprintf placeholders are supported: t('%d replies', $n).
 * The active language is the admin setting site_lang (Settings → General), falling back to
 * 'lang' in data/config.php. An empty translation means "not translated yet": English is shown.
 * Language files may carry a '__name' key with the language's native name.
 */

function lang_code(): string
{
    $code = setting('site_lang') ?: (string)config('lang', 'en');
    return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) ? $code : 'en';
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
