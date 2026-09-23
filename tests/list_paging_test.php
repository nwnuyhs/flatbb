<?php
/** Topic lists that load while scrolling: the Load more button follows the page numbers. Run with: php flatbb test */

function test_list_more_follows_the_setting_and_the_next_page(): void
{
    $pages = pagination(['page' => 1, 'pages' => 3], static fn(int $n): string => '/?page=' . $n);
    $last = pagination(['page' => 3, 'pages' => 3], static fn(int $n): string => '/?page=' . $n);
    try {
        save_settings(['list_paging' => 'pages']);
        test_same('', list_more_html($pages), 'page numbers only: no button');
        save_settings(['list_paging' => 'scroll']);
        test_same(true, str_contains(list_more_html($pages), 'data-list-more'), 'load while scrolling: the button, while a next page exists');
        test_same('', list_more_html($last), 'the last page: nothing more to load');
        test_same('', list_more_html(''), 'a list of one page: nothing more to load');
    } finally {
        save_settings(['list_paging' => 'pages']);
    }
}
