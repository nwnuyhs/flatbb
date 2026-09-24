# What's new for plugin authors

The changes that matter when you write a plugin or a theme, newest release first. Everything else a release brings is in the release notes on GitHub: https://github.com/nwnuyhs/flatbb/releases

## 0.1.99

- **Display names** (Admin → Settings → Registration → Display names, off by default): a member may set a name in any language, up to 30 characters, shown instead of the username across the forum; the username stays the profile address, the sign-in name and what @mentions use, and a profile shows both. New column `fb_users.display_name` (in `user_public_columns()`), setting `display_name_days` (days between a member's changes). **Show a member's name with `user_name($user)`**, not `$user['username']`: it returns the display name while display names are on and one is set, else the username (and "deleted" for null). `user_link()` and `avatar()` already use it; keep `$user['username']` for addresses (`user_url()`), form values and @mentions. A plugin that selects user columns by hand should add `display_name`. Helpers `display_names_on()`, `display_name_clean()`, `display_name_check()`, `display_name_set()`. The member search behind @mention autocomplete (`/api/users`) also matches display names and returns `name` next to `username`. Template changed: `profile.php` (the heading shows the display name, and while display names are on an @username line sits under it on every profile).
- Link cards: a picture that fails to load removes its box, and the card shows as a text card (assets/app.js).

## 0.1.98

- **Link previews** (core/links.php): a link on a line of its own in a post shows as a card (the page's picture, site, title and description). Pages are fetched once on the server, after the request that found the link or by the scheduled job `core.link_previews`, and kept a week in the new table `fb_link_previews`; showing a card never fetches. The fetch takes public http(s) addresses on ports 80 and 443 only, checks every redirect the same way, and stops after 5 seconds or 512 KB. Settings `link_preview`, `link_preview_posts` and `link_preview_block` (Admin → Settings → Content).
- **Logo** (Settings → General → Logo): three styles (`logo_style` icon | image | name), an uploaded square icon (`site_icon`) in place of the FlatBB mark, a dark version of the full logo (`site_logo_dark`), and the site name kept on phones on request (`logo_phone_name`). A site that had uploaded a logo keeps showing it. Helpers `site_logo_html()`, `site_icon_html($size)`, `logo_style()`. The favicon is unchanged: the FlatBB mark unless a favicon is uploaded. Settings sections take two more field kinds: `'hide' => true` (saved, drawn by a block of the section) and `raw` (a block drawn by a function). Template changed: `layout.php` (the logo and the drawer icon): a theme that overrides it should compare it with the new file.
- For plugins: `link_previews($urls)` returns the ready cards of a page's links in one query (and queues the new ones), `link_card_html($preview, $url)` draws one, `link_standalone($html)` finds the links on a line of their own; filter `link.previewable` keeps a link a link. X Style 1.2.0 uses them for the first link of an opening post.
- **Picture and key fields that save at once**: `image_field($name, $value, $opts)` draws a picture with Upload/Replace and Remove; with an `action` URL a picked file is saved at once and Remove acts at once, with an Undo in the notice. `secret_field($name, $saved, $opts)` shows a saved key as "Saved · ends in …XXXX" with Change and Remove (the key never goes back to the browser). The handler behind the `action` URL calls `field_action($scope, $current, $store, $upload, $label)`, which answers `do=upload|remove|restore`; `undo_keep()`/`undo_take()` keep the removed value on the server for 60 seconds, for the same user. `avatar_field()`/`avatar_field_action()` do the same for avatars. Settings sections: the `image` and `secret` kinds now use them, so no plugin change is needed. Without JavaScript the fields fall back to a file input and a Remove checkbox saved with the form. Donate 1.1.1 and Badges 1.2.1 use them.
- **Notifications for many members**: `notify_many($ids, $from, $kind, $content, $topic_id, $post_id)` stores the same notification for any number of members in a few queries (100 per INSERT), skipping the sender, suspended accounts and members who switched the kind off; event `notification.after_create_many` fires once. Use it instead of calling `notify()` in a loop. Every kind now has its own switch: the member preference `notify_<kind>` set to 0 turns it off (`notify_reply` and `notify_mention` already worked this way), so a plugin adds a checkbox (`user.settings_tab`) and saves the key (`user.prefs_save`). The Follow plugin uses both.

## 0.1.97

- **Fix: installing a fresh copy failed** (a 500 error instead of the installer) since 0.1.89: the theme lookup read the plugin table before it existed. `plugins()` is empty until the site is installed.
- **Docker** (optional): `Dockerfile` and `docker-compose.yml` run FlatBB on Apache + PHP 8.3 with a scheduler container and an optional MySQL service; see [Running FlatBB with Docker](DOCKER.md). The installer now also recognises clean URLs behind a port mapping.
- `ai_chat()` on a Claude connection no longer sends `temperature` (current Claude models reject it), leaves room for thinking in `max_tokens`, and treats a refused request as a failure the next connection may answer.

## 0.1.96

- **One AI connection for the site, with backups**: Admin → Settings → AI holds a main connection and up to two backups, each with its provider (`openai`-compatible or `anthropic`), address, model and key and its own test. When the main one fails (unreachable, key refused, out of credit, rate limited, server error) the backups are asked in order at once; a connection that failed rests for 5 minutes, and a writer never waits more than twice the timeout. The state lives in `data/cache/ai.json`. Plugins call `ai_chat($system, $user, $opts)` and `ai_json($text)` instead of their own client and key (`ai_ready()` says whether it is set up). New filter `ai.request` (change or refuse a request, ctx `purpose`) and event `ai.response` (once per connection tried: `connection`, model, token usage); `ai_chat()` answers with the `connection` that replied and takes `connection` to ask only one. See "AI" in the plugin guide. Reasoning models (deepseek-v4-pro and the like) get room to think before they answer, and on a Windows PHP without a CA bundle the certificate is checked against the system store.
- **A topic without a category**: setting `category_required` (on by default) and `default_category`. With it off, the composer's category may stay empty and the topic goes to the default category. New filter `topic.category_missing` (ctx `title`, `body`, `user`) lets a plugin pick one first, whatever the setting; `topic.category_auto` (true while the plugin can answer) makes the category optional in the composer. Helpers `topic_category_fallback()` and `topic_category_optional()`.
- Settings sections take a field type `secret`: a password field that is never shown again, keeps its value when left empty and has a box to remove it.
- Admin → Widgets: a plugin's switch on a list position only shows while it is switched off there (its items are switched one by one), so a stale switch can no longer hide a card without a way back.
- **Topic lists that load while scrolling**: setting `list_paging` (`pages`, the default, or `scroll`). With `scroll`, `list_more_html()` puts a Load more button above the page numbers; `assets/app.js` presses it near the end of the list, appends the rows of the next page to `.topic-rows` (by `data-topic-id`, skipping ones already there), swaps in that page's numbers and moves the address to it. Five pages load by themselves, then the button waits for a press. New document event `fb:content` (detail `root`, `nodes`): set up rows that arrive later there, or better, use event delegation.
- Templates changed: `topic_form.php`, `topic_list.php`.

## 0.1.95

- **Editor mode tabs**: new list region `composer.modes` draws tabs above the toolbar. The core gives `write` (the Markdown textarea) and `preview`; a plugin adds a mode with `label`, `icon`, `cmd` (the editor command that switches it on and off) and `class` (set on `.editor` while it is on), and may relabel `write`. The core keeps the tabs in step, also when your mode switches itself on at load. Preview is a mode of its own now (the old side-by-side split is gone), fullscreen moved to the tab row, and the toolbar wraps instead of scrolling. JS: `api.mode()` reads the mode, `api.mode('preview')` switches. The Visual Editor (1.1.0) is the example.
- **`--page-x`**: the page's side margin as a token (20px, 16px on tablets, 12px on phones). Line things up on it; a strip that runs edge to edge pulls out with `margin-inline: calc(-1 * var(--page-x))`. The phone gutter was 4px before.
- **Phones**: the list's floating New Topic button is gone; New Topic is the header's `.header-new-topic`, first of the right-hand buttons. Theme and language left the phone header for two round buttons in the account menu (members) or at the top of the drawer (visitors): `quick_prefs_html()`. The top bar sticks again (`overflow-x: clip` instead of `hidden`) and slides away while scrolling down (`body.topbar-away`).
- **Right column**: on wide screens it follows the page the way X does (setting `right_follow`, body class `right-follow`, CSS variable `--right-top`).
- **Settings → Email**: the address has a tab of its own (`email`, account group). A change asks for the password, a code sent to the new address when verification is on, and mails the old address a link that undoes it for 7 days (`/email/restore`). New event `user.email_changed` (ctx `user_id`, `old`, `new`, `restored`); `user.before_save` no longer carries `email`. Helpers `email_mask()`, `email_restore_link()`.
- Templates changed: `editor.php`, `settings.php`, `user_menu.php`, `layout.php`. A theme that overrides one of them should compare it with the new file (`php flatbb theme:check <id>`).

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
