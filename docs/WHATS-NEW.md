# What's new for plugin authors

The changes that matter when you write a plugin or a theme, newest release first. Everything else a release brings is in the release notes on GitHub: https://github.com/nwnuyhs/flatbb/releases

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
