<?php
/**
 * Markdown renderer with built-in sanitizing. Raw HTML in the source is always escaped,
 * so the output only ever contains tags this file emits.
 *
 * Supported: headings, paragraphs, line breaks, bold, italic, strike, inline code, fenced code,
 * links, images, autolinks, blockquotes, ordered/unordered lists (2 levels), tables, hr, @mentions.
 *
 * Hooks: markdown.before (source text), markdown.after (html).
 */

function md(string $text): string
{
    $text = (string)hook('markdown.before', str_replace(["\r\n", "\r"], "\n", $text));
    $ph = [];
    $text = md_fences($text, $ph, false);
    $text = h($text);
    $html = md_blocks(explode("\n", $text), $ph);
    $html = strtr($html, $ph);
    return (string)hook('markdown.after', $html);
}

/** Replace fenced and inline code with placeholders. $escaped: whether $text already went through h(). */
function md_fences(string $text, array &$ph, bool $escaped): string
{
    $esc = static fn(string $s): string => $escaped ? $s : h($s);
    $text = preg_replace_callback('/^```([\w+-]*)[ \t]*\n(.*?)\n```[ \t]*$/ms', static function (array $m) use (&$ph, $esc): string {
        $lang = $m[1] !== '' ? ' class="language-' . h($m[1]) . '"' : '';
        $key = "\x1A" . count($ph) . "\x1A";
        $ph[$key] = '<pre><code' . $lang . '>' . $esc($m[2]) . '</code></pre>';
        return "\n" . $key . "\n";
    }, $text) ?? $text;
    return preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$ph, $esc): string {
        $key = "\x1A" . count($ph) . "\x1A";
        $ph[$key] = '<code>' . $esc($m[1]) . '</code>';
        return $key;
    }, $text) ?? $text;
}

function md_blocks(array $lines, array &$ph = []): string
{
    $out = '';
    $para = [];
    $flush = static function () use (&$para, &$out): void {
        if ($para !== []) {
            $out .= '<p>' . implode('<br>', array_map('md_inline', $para)) . '</p>';
            $para = [];
        }
    };
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $trim = trim($line);
        if ($trim === '') { $flush(); continue; }
        if (preg_match('/^\x1A\d+\x1A$/', $trim)) { $flush(); $out .= $trim; continue; }
        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m)) {
            $flush();
            $lvl = strlen($m[1]) + 1; // h2..h6 inside posts
            $out .= '<h' . min(6, $lvl) . '>' . md_inline($m[2]) . '</h' . min(6, $lvl) . '>';
            continue;
        }
        if (preg_match('/^([-*_])(\s*\1){2,}$/', $trim)) { $flush(); $out .= '<hr>'; continue; }
        if (str_starts_with($trim, '&gt;')) {
            $flush();
            $quote = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '&gt;')) {
                $quote[] = preg_replace('/^\s*&gt;\s?/', '', $lines[$i]);
                $i++;
            }
            $i--;
            $inner = md_fences(implode("\n", $quote), $ph, true); // quoted text may contain its own code fences
            $out .= '<blockquote>' . md_blocks(explode("\n", $inner), $ph) . '</blockquote>';
            continue;
        }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line, $m)) {
            $flush();
            $out .= md_list($lines, $i);
            continue;
        }
        if (str_contains($trim, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim($lines[$i + 1]))) {
            $flush();
            $out .= md_table($lines, $i);
            continue;
        }
        $para[] = $line;
    }
    $flush();
    return $out;
}

/** Parse a list starting at $i (advances $i to the last list line). Two nesting levels. */
function md_list(array $lines, int &$i): string
{
    $n = count($lines);
    $items = [];
    $ordered = null;
    $baseIndent = null;
    while ($i < $n) {
        $line = $lines[$i];
        if (trim($line) === '') { $i++; break; } // a blank line ends the list (tight lists only)
        if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
            if ($items !== [] && preg_match('/^\s{2,}(.*)$/', $line, $c)) { $items[count($items) - 1]['text'] .= '<br>' . md_inline($c[1]); $i++; continue; }
            break;
        }
        $indent = strlen($m[1]);
        $baseIndent ??= $indent;
        $ordered ??= ctype_digit($m[2][0]);
        if ($indent > $baseIndent && $items !== []) {
            $sub = md_list($lines, $i);
            $items[count($items) - 1]['sub'] .= $sub;
            continue;
        }
        if ($indent < $baseIndent) break;
        $items[] = ['text' => md_inline($m[3]), 'sub' => ''];
        $i++;
    }
    $i--;
    $tag = $ordered ? 'ol' : 'ul';
    $html = "<{$tag}>";
    foreach ($items as $it) $html .= '<li>' . $it['text'] . $it['sub'] . '</li>';
    return $html . "</{$tag}>";
}

function md_table(array $lines, int &$i): string
{
    $n = count($lines);
    $cells = static fn(string $l): array => array_map('trim', explode('|', trim(trim($l), '|')));
    $head = $cells($lines[$i]);
    $aligns = array_map(static function (string $c): string {
        $l = str_starts_with($c, ':'); $r = str_ends_with($c, ':');
        return $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
    }, $cells($lines[$i + 1]));
    $i += 2;
    $rows = [];
    while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) { $rows[] = $cells($lines[$i]); $i++; }
    $i--;
    $td = static function (array $row, string $tag) use ($aligns, $head): string {
        $h = '';
        foreach ($head as $k => $_) {
            $a = ($aligns[$k] ?? '') !== '' ? ' style="text-align:' . $aligns[$k] . '"' : '';
            $h .= "<{$tag}{$a}>" . md_inline($row[$k] ?? '') . "</{$tag}>";
        }
        return '<tr>' . $h . '</tr>';
    };
    $html = '<div class="table-wrap"><table><thead>' . $td($head, 'th') . '</thead><tbody>';
    foreach ($rows as $r) $html .= $td($r, 'td');
    return $html . '</tbody></table></div>';
}

/** Inline formatting on already-escaped text. */
function md_inline(string $s): string
{
    // images ![alt](url)
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
        $u = md_safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), true);
        return $u === '' ? $m[0] : '<img src="' . h($u) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $s) ?? $s;
    // links [text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
        $u = md_safe_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        return $u === '' ? $m[0] : md_link($u, $m[1]);
    }, $s) ?? $s;
    // autolinks
    $s = preg_replace_callback('/(?<![="\'>\w])(https?:\/\/[^\s<]+[^\s<.,;:!?)\'"])/i', static function (array $m): string {
        $raw = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        return md_link($raw, h(cut($raw, 60)));
    }, $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/(?<![\w_])__(.+?)__(?![\w_])/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $s) ?? $s;
    // @mentions
    $s = preg_replace_callback('/(?<![\w\/])@([A-Za-z0-9][A-Za-z0-9_.-]{1,29})\b/', static fn(array $m): string => '<a class="mention" href="' . h(user_url($m[1])) . '">@' . $m[1] . '</a>', $s) ?? $s;
    return $s;
}

function md_link(string $url, string $label_html): string
{
    $external = !str_starts_with($url, base_url()) && !str_starts_with($url, '/');
    $attr = $external ? ' rel="nofollow ugc noopener" target="_blank"' : '';
    return '<a href="' . h($url) . '"' . $attr . '>' . $label_html . '</a>';
}

/** Allow http(s), mailto and site-relative URLs only. */
function md_safe_url(string $url, bool $image = false): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) return '';
    if (preg_match('/^(https?:)?\/\//i', $url)) return $url;
    if (!$image && stripos($url, 'mailto:') === 0) return $url;
    if ($url[0] === '/' || $url[0] === '#') return $url;
    return '';
}

/** Extract usernames mentioned in a markdown source. */
function md_mentions(string $text): array
{
    preg_match_all('/(?<![\w\/])@([A-Za-z0-9][A-Za-z0-9_.-]{1,29})\b/', $text, $m);
    return array_values(array_unique($m[1] ?? []));
}

/** Plain-text excerpt of a markdown source. */
function md_excerpt(string $text, int $max = 160): string
{
    $t = preg_replace('/```.*?```/s', '', $text) ?? $text;
    $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $t) ?? $t;
    $t = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $t) ?? $t;
    $t = preg_replace('/[#>*_`~|-]+/', ' ', $t) ?? $t;
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;
    return cut(trim($t), $max);
}
