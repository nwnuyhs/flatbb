<?php
/**
 * Static manifest reader: the array a plugin.php returns, read with PHP's tokenizer instead of executing the file.
 * Used for disabled plugins, for uploads the marketplace validates, and by plugin:check. A manifest is readable when it
 * is data: strings, numbers, true/false/null, nested arrays ([] or array()), constants, and '.' between those. A call,
 * a variable or any other expression makes it unreadable (null, with the reason in $error).
 */
if (!defined('FLATBB')) exit;

function plugin_manifest_parse(string $src, ?string &$error = null): ?array
{
    $error = null;
    if (!function_exists('token_get_all')) { $error = 'the tokenizer extension is missing'; return null; }
    $toks = [];
    foreach (@token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) continue;
        $toks[] = $t;
    }
    // the last `return` at brace depth zero is the manifest
    $depth = 0;
    $start = null;
    foreach ($toks as $i => $t) {
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
        elseif ($t === '}') $depth--;
        elseif ($depth === 0 && is_array($t) && $t[0] === T_RETURN) $start = $i;
    }
    if ($start === null) { $error = 'no top-level return statement'; return null; }
    $p = $start + 1;
    try {
        $value = plugin_manifest_value($toks, $p);
        if (($toks[$p] ?? null) !== ';') throw new RuntimeException('expected ";" after the manifest' . plugin_manifest_at($toks, $p));
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        return null;
    }
    if (!is_array($value)) { $error = 'the return value is not an array'; return null; }
    return $value;
}

/** One value at $p (advances $p past it). */
function plugin_manifest_value(array $toks, int &$p): mixed
{
    $v = plugin_manifest_atom($toks, $p);
    while (($toks[$p] ?? null) === '.') {
        $p++;
        $r = plugin_manifest_atom($toks, $p);
        if (!is_scalar($v) || !is_scalar($r)) throw new RuntimeException('"." joins strings only' . plugin_manifest_at($toks, $p));
        $v = (string)$v . (string)$r;
    }
    return $v;
}

function plugin_manifest_atom(array $toks, int &$p): mixed
{
    $t = $toks[$p] ?? null;
    if ($t === null) throw new RuntimeException('unexpected end of file');
    if ($t === '[') { $p++; return plugin_manifest_array($toks, $p, ']'); }
    if ($t === '-' || $t === '+') {
        $sign = $t === '-' ? -1 : 1;
        $p++;
        $n = plugin_manifest_atom($toks, $p);
        if (!is_int($n) && !is_float($n)) throw new RuntimeException('a sign needs a number' . plugin_manifest_at($toks, $p));
        return $n * $sign;
    }
    if (!is_array($t)) throw new RuntimeException('unexpected "' . $t . '"' . plugin_manifest_at($toks, $p));
    [$id, $text] = $t;
    $p++;
    switch ($id) {
        case T_ARRAY:
            if (($toks[$p] ?? null) !== '(') throw new RuntimeException('expected "(" after array' . plugin_manifest_at($toks, $p));
            $p++;
            return plugin_manifest_array($toks, $p, ')');
        case T_CONSTANT_ENCAPSED_STRING:
            return plugin_manifest_string($text);
        case T_LNUMBER:
            return (int)intval(str_replace('_', '', $text), 0);
        case T_DNUMBER:
            return (float)str_replace('_', '', $text);
        case T_STRING:
            $lower = strtolower($text);
            if ($lower === 'true') return true;
            if ($lower === 'false') return false;
            if ($lower === 'null') return null;
            if (defined($text)) return constant($text);
            throw new RuntimeException('unknown constant ' . $text . plugin_manifest_at($toks, $p - 1));
        default:
            throw new RuntimeException('unexpected ' . token_name($id) . ' (' . cut($text, 30, '…') . ')' . plugin_manifest_at($toks, $p - 1));
    }
}

/** Array items up to the closing bracket (advances $p past it). */
function plugin_manifest_array(array $toks, int &$p, string $close): array
{
    $out = [];
    while (true) {
        $t = $toks[$p] ?? null;
        if ($t === null) throw new RuntimeException('unterminated array');
        if ($t === $close) { $p++; return $out; }
        $v = plugin_manifest_value($toks, $p);
        if (is_array($toks[$p] ?? null) && $toks[$p][0] === T_DOUBLE_ARROW) {
            $p++;
            if (!is_int($v) && !is_string($v)) throw new RuntimeException('array keys must be strings or integers' . plugin_manifest_at($toks, $p));
            $out[$v] = plugin_manifest_value($toks, $p);
        } else {
            $out[] = $v;
        }
        $t = $toks[$p] ?? null;
        if ($t === ',') { $p++; continue; }
        if ($t !== $close) throw new RuntimeException('expected "," or "' . $close . '"' . plugin_manifest_at($toks, $p));
    }
}

/** The value of a single- or double-quoted string literal (double quotes with variables are not constant strings and never reach here). */
function plugin_manifest_string(string $literal): string
{
    $q = $literal[0];
    $body = substr($literal, 1, -1);
    if ($q === "'") return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    return preg_replace_callback('/\\\\(n|t|r|v|f|e|\\\\|\$|"|0|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/', static function (array $m): string {
        $c = $m[1];
        return match ($c[0]) {
            'n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'f' => "\f", 'e' => "\e", '\\' => '\\', '$' => '$', '"' => '"', '0' => "\0",
            'x' => chr((int)hexdec(substr($c, 1))),
            'u' => mb_chr((int)hexdec(substr($c, 2, -1)), 'UTF-8') ?: '',
            default => $m[0],
        };
    }, $body) ?? $body;
}

function plugin_manifest_at(array $toks, int $p): string
{
    for ($i = $p; $i >= 0; $i--) if (is_array($toks[$i] ?? null)) return ' near line ' . (int)$toks[$i][2];
    return '';
}
