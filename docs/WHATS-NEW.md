# What's new for plugin authors

The changes that matter when you write a plugin or a theme, newest release first. Everything else a release brings is in the release notes on GitHub: https://github.com/nwnuyhs/flatbb/releases

## 0.1.94

- **Shared member regions**: `member.labels` (HTML), `member.stats` and `member.actions` (lists) run wherever the core shows a member: the sidebar member card (`place` `card`), the author card on topic pages (`author`), the account menu (`menu`: labels, stats and actions) and the profile card (`profile`). ctx: `user`, `self`, `place`. Hook once and check `place` instead of adding to `user.profile.labels`, `user.profile.stats`, `header.user_menu.labels` and `header.user_menu.stats`, which keep working.
  - `member.stats` items: `label`, `value`, `url`, `sub`, `weight`; one with `progress` (0 to 1) is drawn as a bar across the card.
  - `member.actions` items: `label`, `url`, `icon`, `primary`, `count`, `title`, `done`, `weight`, and `post`: the button POSTs to `url` with the CSRF token and `back` (the current path). On the sidebar card New Topic (`new`) comes first and the others share a row under it; the account menu (`place` `menu`) shows them full width under its numbers. New helper `member_action_html($id, $item, $class)` draws one.
  - Admin → Widgets can order and hide the items of both lists.
- The sidebar member card and the topic author card share the new view `app/views/member_card.php`, built on these regions; the sidebar card shows Points next to Topics, Replies and Likes. A theme that overrides `card_user.php` or `card_author.php` should compare it with the new files.
- **Profile page, new layout**: a head card across the page (avatar, name, labels, bio, details, buttons, with the number tiles in a row under them), then the tabs and lists beside a column of sections. New list region `member.sections` (`title`, `html`, `url`, `link`, `weight`; ctx `user`, `self`, `place` `profile`): each item is a card in that column. Numbers with `progress` move there as bars; `user.profile.after` and `user.profile.cards` still show, in the same column. `app/views/profile.php` changed: a theme that overrides it should compare it with the new file. The Badges plugin (1.1.0) moved its strip to a `member.sections` card.
- The New Topic button beside the list tabs (`main.toolbar`) carries the class `list-new-topic`: hidden on wide screens while the member card beside the list offers New Topic, and on phones a round button floating in the lower corner; the header's small + (class `header-new-topic`) steps aside on those list pages. New helper `new_topic_url()`: inside a category list it opens the composer on that category.
- The tag cloud left the left column: it is a core card of the right column (`sidebar.right.cards`, id `tags`, view `card_tags.php`) that Admin → Widgets orders or hides. A theme that overrides `sidebar_left.php` should compare it with the new file.
- **Four hooks for bigger plugins** (see "Permissions, uploads, hidden content and pages" in the plugin guide):
  - `user.can` (filter, ctx `permission`, `user`, `group`): change one member's permission after their group decided; guests and admins never reach it.
  - `upload.before_save` (return a message to refuse a file, or change it in place), `upload.after_save` (event for attachments, avatars and site images, with path and file), `upload.url` (serve files from a CDN or an object store).
  - `markdown.excerpt` (filter in `md_excerpt()`): strip hidden content before it becomes the search index, a page description, a notification or a feed item.
  - `router.routes` (filter on the route table): serve a core page such as `/`; admin, sign-in, settings, setup and API addresses stay the core's, and Admin → Plugins lists what plugins took over. `routes_final()` and `routes_taken_over()` read the result.
- Form helpers `input()`, `select()` and `textarea()` leave out an attribute whose value is `false` or `null` (`'required' => false` used to print `required=""`).
- **One icon field for the whole site**: `icon_picker($name, $value, $opts)` (search, the built-in icons, Your icons, an emoji, an upload) and `icon_from_post($name)`; plugin settings take `'type' => 'icon'`. Icon values are a built-in name, `emoji:…`, an uploaded file under `site/`, or `none`; `icon_any()` draws all of them, and the menus now draw their items with it. About 100 more built-in icons (from Lucide, ISC License) join the set: `icon_set()` lists them with what plugins add through `icon.paths`. Uploaded icons form one library (`icon_library()`) that every icon field offers. Uploaded SVG files are rebuilt from an allowlist by the new `svg_sanitize()`: scripts, styles, event attributes and outside links never survive.
- **Admin → Appearance → Menus**: the top navigation, the left menu, the category bar above the lists (the phone's first row), the account menu and the footer links, edited in one place. Any item, the core's or a plugin's, can be hidden, moved, renamed, relinked, given an icon or a new tab, and the admin adds links of their own. The edits apply inside `region_list()` (setting `menu_items`), so a plugin's link needs nothing to be editable. A plugin with a menu of its own adds it through the new filter `menus.known` (region => name). The Nav Menu plugin is no longer needed: its links can be added here.
- **Where an entry goes** (new section in the plugin guide): one place per kind of entry, so a busy forum stays tidy.
  - `sidebar.left.nav` items take `group`: `community`, `tools`, or an id of your own with `group_label`; the left menu shows the main links, then each group under a small heading, and past the setting `nav_visible` (8) folds the rest under More. One link per plugin: `plugin:check` warns when a plugin adds more.
  - The sidebar member card shows the account menu's `you` items as shortcuts (setting `card_shortcuts`, 6). New helper `user_menu_items($me)` returns that list; an item with `card` => false stays out of the card.
- **New helper `user_level($user)`** and filter `user.level` (ctx: `user`): a member's level as a number, 0 when no plugin keeps levels. The plugin that keeps levels answers the filter; every other plugin (badges, gated topics) reads the level here instead of calling that plugin. It runs inside lists, so the answer must not query.
- The Levels plugin (1.2.0) is the example: one `member.labels` and one `member.stats` callback replace its three place-specific hooks.

## 0.1.93

- `lang_set()` takes a second, optional argument: the preferences the caller has just written. Settings → Preferences passes them, so remembering the language no longer saves the row read earlier in the request over the answers just given. Without them the row is read again from the database, never from the request's cached copy.
- New `notify_wanted($user_id, $kind)`, used by `notify()`: `reply` and `mention` notifications now follow the member's own preferences. Kinds a plugin invents are unaffected.
- The profile page is one centred card. `app/views/profile.php` moved the details block inside the header, and the number tiles fill the card width. A theme that overrides `profile.php` should be compared with the new file.
- The Newest members card reads `group_id`, `status` and `last_seen` as well, so avatars there can show the online dot and a plugin's corner mark.
- Russian is available as a language pack (`lang/ru.php`).

## 0.1.92

- **New hook `user.avatar_after`**: HTML placed inside every avatar, over its lower corner (ctx: `user`, `size` in px). The avatar wrapper is positioned, so use `position: absolute`. Runs inside lists: no database access. The Verified Badges plugin puts its badge there.
- **New regions**
  - `post.name`: on the name row of a post, after the username, OP and group labels (loop, no DB).
  - `user.profile.labels`: on the profile card, next to the group label.
  - `header.user_menu.labels`: under the name in the account menu.
  - `header.user_menu.stats`: the numbers strip of the account menu (list: `label`, `value`, `url`).
  - `points.summary`: short facts next to the balance on `/points` (list: `label`, `sub`, `progress`, `url`).
- **New filter `points.icon`**: the icon name of a points history line (ctx: `reason`, `delta`).
- **New keys on list items**
  - `user.profile.stats` items may carry `progress` (0 to 1), drawn as a bar across the profile card.
  - `header.user_menu` items take `group` (`you` or `site`) and `count`.
  - `points.actions` items take `title`, `sub` and `done`; they show as tiles.
- **Templates**: `post.php`, `topic.php`, `profile.php` and `topic_rows.php` changed; `user_menu.php` and `points_wallet.php` are new. A theme that overrides one of them should compare it with the new file (`php flatbb theme:check <id>` lists them).
- `points_log_html()` takes a third argument, `$by_day`, that groups the lines under Today, Yesterday and dates.

## 0.1.91

- **New route `/t/{id}/unread`** and helper `topic_unread_url($topic)`: link a partly read topic to its first unread reply.
- On the page that holds the opening post, `post.php` receives a `head` variable with the topic title and the topic's buttons, so the title sits inside the first post.

## 0.1.89

- **Themes**: a theme is a plugin whose manifest says `'type' => 'theme'`. One theme is on at a time. Tokens, a stylesheet and template overrides are described in [Themes and layout](THEME.md).
