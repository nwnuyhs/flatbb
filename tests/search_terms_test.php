<?php
/** Search finds short words, Chinese text and stopword-heavy questions (forum topic 178). Run with: php flatbb test */

function test_search_terms_split_for_mysql(): void
{
    test_same(['index' => ['install'], 'like' => []], search_terms('how to install', 'mysql'), 'stopwords never sink the search');
    test_same(['index' => ['plugin'], 'like' => ['AI']], search_terms('AI plugin', 'mysql'), 'a two-letter word is matched as text');
    test_same(['index' => [], 'like' => ['语言包', '翻译']], search_terms('语言包 翻译', 'mysql'), 'Chinese is matched as text');
    test_same(['index' => [], 'like' => ['the']], search_terms('the', 'mysql'), 'only stopwords: still searched');
    test_same(['index' => ['mail', 'setup'], 'like' => []], search_terms('e-mail setup', 'mysql'), 'operators separate words, single letters drop');
    test_same(['index' => ['Tiếng', 'Việt'], 'like' => []], search_terms('Tiếng Việt', 'mysql'), 'accented words stay in the index');
}

function test_search_terms_split_for_fts5_and_like(): void
{
    test_same(['index' => ['install'], 'like' => []], search_terms('how to install', 'fts5'), 'stopwords drop on SQLite too');
    test_same(['index' => ['AI', 'plugin'], 'like' => []], search_terms('AI plugin', 'fts5'), 'FTS5 indexes two-letter words');
    test_same(['index' => ['plugin'], 'like' => ['中文']], search_terms('plugin 中文', 'fts5'), 'CJK goes to LIKE on SQLite too');
    test_same(['index' => [], 'like' => ['e-mail', 'setup']], search_terms('e-mail setup', 'like'), 'LIKE keeps words as typed');
    test_same(8, count(search_terms('a1 b2 c3 d4 e5 f6 g7 h8 i9 j10', 'fts5')['index']), 'at most eight words');
}

function test_search_finds_chinese_and_mixed_queries(): void
{
    save_settings(['review_first_posts' => '0']);
    $cat = (int)val('SELECT id FROM fb_categories ORDER BY id LIMIT 1');
    $u = user_create('st_user', 'st_user@example.invalid', 'password-123');
    topic_create($cat, $u, '如何安装语言包', '下载语言包后放到 lang 目录 zorbleflux。');
    topic_create($cat, $u, 'Another topic about zorbleflux', 'Nothing in Chinese here.');
    test_same(1, (int)search_query('语言包')['total'], 'a Chinese word inside a sentence is found');
    test_same(1, (int)search_query('语言包 zorbleflux')['total'], 'Chinese and an English word together: both required');
    test_same(2, (int)search_query('zorbleflux')['total'], 'an English word alone');
    test_same(0, (int)search_query('语言包 nothingmatcheshere')['total'], 'every word is required');
}
