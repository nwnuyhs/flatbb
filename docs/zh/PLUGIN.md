# flatbb 插件开发规范（中文版）

英文原版 `docs/PLUGIN.md` 是唯一权威规范，AI 工具默认读英文版；本文为中文对照，内容同步。开发前先完整读完，再看 `plugins/hello/plugin.php` 和功能最接近的插件；实现以 `core/` 里的真实函数为准（见 `docs/API.md`），不要臆造接口。

## 1. 工作顺序

1. 确定插件 **id**（小写字母、数字、下划线，2–40 字符，如 `word_filter`）、功能边界、设置项、数据、页面、权限、外部请求和计划任务。
2. 优先用核心函数、Hook、Region 和声明式设置；插件机制做不到的，往核心加 Hook，而不是在插件里改核心行为。
3. 所有逻辑放在 `plugins/<id>/plugin.php`。允许附加文件（`assets/`、`lang/`、`README.md`、被 plugin.php `require` 的 PHP 文件），但 manifest 必须由 `plugin.php` 返回。
4. 新插件：验证默认值、启用、停用、卸载。改旧插件：兼容旧设置和旧数据，至少递增补丁版本，行为变化时更新描述。
5. 最后执行 `php -l plugins/<id>/plugin.php` 和 `php flatbb plugin:check <id>`，并过一遍文末清单。

## 2. 最小插件

```php
<?php
if (!defined('FLATBB')) exit;

function hello_footer(string $html, array $ctx): string
{
    return $html . '<span class="hello-badge">' . h((string)plugin_setting('hello', 'text', 'Hello')) . '</span>';
}

function hello_css(): string
{
    return '.hello-badge{color:var(--brand);font-size:var(--font-size-xs)}';
}

return [
    'id'          => 'hello',
    'name'        => 'Hello Badge',
    'version'     => '1.0.0',
    'description' => 'Shows a small greeting badge in the footer.',
    'author'      => 'your-name',
    'requires'    => ['flatbb' => '0.1.0'],
    'hooks'       => ['region.footer.right' => 'hello_footer'],
    'settings'    => ['text' => ['type' => 'text', 'label' => 'Badge text', 'default' => 'Hello', 'max' => 40]],
    'assets'      => ['css' => ['hello_css']],
];
```

放好后执行 `php flatbb plugin:sync` 或在后台 插件 → "Scan plugins folder" 注册，再启用。

## 3. manifest 字段

| 键 | 必需 | 含义 |
| --- | --- | --- |
| `id` | 是 | 与目录名相同 |
| `name`、`version`、`description`、`author` | 是 | `version` 为语义化 `x.y.z`；`description` 面向普通用户，只说功能，不写技术词 |
| `url` | 否 | 主页或仓库 |
| `requires` | 否 | `['flatbb' => '0.1.0']` 最低核心版本 |
| `hooks` | 否 | `['hook.name' => '回调' \| ['cb1', 'cb2']]`，见 `docs/HOOKS.md` |
| `routes` | 否 | `['/path' => '回调', '/path/{id}' => '回调']`，写法同 `core/router.php` |
| `admin_pages` | 否 | `['key' => ['label' => '菜单名', 'callback' => 'fn']]` → `/admin/ext/<id>/<key>`，回调内调 `need_admin()` |
| `settings` | 否 | 声明式设置，后台表单自动生成（见 §5） |
| `assets` | 否 | `['css' => [...], 'js' => [...]]`，每项是返回源码的函数名或相对插件目录的文件路径；所有插件合并成一个文件 |
| `cron` | 否 | `['job' => ['callback' => 'fn', 'interval' => 3600 \| '返回秒数的函数名']]` |
| `install` | 否 | 首次启用或版本变化时执行，必须可重复执行 |
| `uninstall` | 否 | 删除插件自己的表和文件；不得删除不属于自己的用户内容 |
| `price` | 否 | 官网市场预留（0 = 免费） |

## 4. 命名与隔离（`plugin:check` 会检查）

- PHP 函数 `myid_*`、常量 `MYID_*`、类 `MyId*`。
- 数据表 `plugin_myid_*`；绝不改 `fb_*` 核心表。每主题/帖子/用户的扩展数据放自己的表（以 id 为键）或通过 Hook 写 `meta` JSON，不给核心表加列。
- CSS 类/ID/变量 `myid-*` / `--myid-*`；`data-myid-*` 属性；JS 函数和全局 `myid_*`；浏览器存储键 `myid_*`。
- 插件自定义 Hook 命名 `myid.event_name`；核心 Hook 用原名。
- 插件之间不能互相调用函数，共享能力进核心。
- `plugin.php` 开头必须是 `if (!defined('FLATBB')) exit;`。
- 文件读写只能在 `plugin_path($id, ...)`、`DATA_DIR . '/myid_*'` 或 `UPLOAD_DIR` 下，不硬编码路径。

## 5. 设置

声明 schema，后台自动生成并校验表单：

```php
'settings' => [
    'enabled'   => ['type' => 'checkbox', 'label' => 'Enable', 'default' => 1],
    'limit'     => ['type' => 'number', 'label' => 'Items', 'default' => 5, 'min' => 1, 'max' => 50],
    'mode'      => ['type' => 'select', 'label' => 'Mode', 'options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'],
    'text'      => ['type' => 'text', 'label' => 'Title', 'default' => '', 'max' => 80, 'help' => 'Shown above the list'],
    'html'      => ['type' => 'html', 'label' => 'Custom HTML', 'rows' => 6],
    'color'     => ['type' => 'color', 'label' => 'Accent', 'default' => '#e7672e'],
],
```

读取：`plugin_setting('myid', 'limit', 5)` 或 `plugin_settings('myid')`。默认值来自 schema，旧站点缺键也不报错。程序写入：`plugin_save_settings('myid', $array)`。需要自定义后台页面时用 `admin_pages`。

## 6. Hook 与 Region

所有 Hook 签名：`function myid_x($value, array $ctx)`，返回新值，返回 `null` 表示不改。事件（`fire()`）忽略返回值。

- **HTML Region**（`region.header.left`、`region.sidebar.right.top`、`region.footer.right` …）：`$value` 是 HTML，追加即可。
- **列表 Region**（`region.sidebar.left.nav`、`region.header.user_menu`、`region.post.actions`、`region.sidebar.right.cards` …）：`$value` 是以项目 id 为键的数组，加入 `['label'=>…, 'url'=>…, 'icon'=>…]` 或 `['html'=>…]`，键用插件 id。
- **循环内 Region**（`region.topic_list.item.*`、`region.post.*`）：每行/每帖调用一次，**禁止查库**。只能用 `$ctx['topic']`/`$ctx['post']` 已有数据，或者先用批量 Hook `topic_list.rows` / `topic.posts`（每页调一次，拿到全部行）把数据挂上去。
- **后台控制**：每个 Region 都出现在 后台 → Layout，管理员可以按位置关闭你的插件或插入 HTML 块，不要用 CSS 对抗。
- **前端**：每个 Region 元素带 `data-slot="<region>"`，用 `[data-slot~="post.actions"]` 选择；循环内的插槽会重复，用 `[data-post-id]` / `[data-topic-id]` 缩小范围。

### 批量模式（给每行加数据的唯一正确方式）

```php
function myid_rows(array $rows, array $ctx): array
{
    $ids = array_column($rows, 'id');
    $extra = $ids ? rows_by_ids('plugin_myid_stats', $ids, 'topic_id,score', 'topic_id') : [];
    foreach ($rows as &$r) $r['myid_score'] = (int)($extra[(int)$r['id']]['score'] ?? 0);
    return $rows;
}
function myid_title_suffix(string $html, array $ctx): string   // 循环 Hook：只用内存
{
    $s = (int)($ctx['topic']['myid_score'] ?? 0);
    return $s > 0 ? $html . '<span class="myid-score">' . $s . '</span>' : $html;
}
'hooks' => ['topic_list.rows' => 'myid_rows', 'region.topic_list.item.title_suffix' => 'myid_title_suffix'],
```

循环 Hook 拿不到批量数据时，输出唯一占位符 `<!--myid-<token>-<id>-->`，在 `page.before_output` 里用一条 `IN (...)` 查询一次性全部替换，最终 HTML 不能残留占位符。

## 7. 路由与页面

```php
function myid_page(string $id = ''): never
{
    $me = need_login();                  // 或 need_admin()；公开页面不需要
    $row = one('SELECT * FROM plugin_myid_items WHERE id=?', [(int)$id]);
    if ($row === null) not_found();
    page('Title', '<div class="card"><div class="card-body">' . h($row['title']) . '</div></div>', ['class' => 'page-myid']);
}
function myid_save(): never
{
    need_login();
    require_post();                      // POST + CSRF
    $title = post_str('title', 120);
    if ($title === '') fail(t('Title is required.'));
    db_insert('plugin_myid_items', ['user_id' => uid(), 'title' => $title, 'created_at' => now()]);
    flash(t('Saved.'));
    redirect(url('/myid'));
}
'routes' => ['/myid' => 'myid_page', '/myid/{id}' => 'myid_page', '/myid/save' => 'myid_save'],
```

- 链接用 `url('/myid/' . $id)`；表单带 `csrf_field()`；加 `data-ajax="1"` 走 fetch 提交（处理器用 `redirect()`/`json_ok()` 响应）。
- 纯 AJAX 接口：`json_ok([...])` / `json_error('msg', 400)`；`is_ajax()` 判断请求方式。
- 页面选项：`['left' => false]` 隐藏左栏，`['right' => $html]` 替换右栏，`['breadcrumbs' => [[label, url]]]`。
- 界面用 `core/render.php` 的助手：`card()`、`tabs()`、`pagination()`、`form_row()`、`input()`、`select()`、`checkbox()`、`action_form()`、`editor()`、`avatar()`、`user_link()`、`icon()`。

## 8. 数据库

```php
function myid_install(array $manifest): void
{
    db_create_table('plugin_myid_items', [
        'id' => 'id', 'user_id' => 'uint', 'title' => 'string', 'body' => 'text', 'score' => 'int', 'created_at' => 'uint',
    ]);
    db_create_index('plugin_myid_items', 'ix_myid_items_user', ['user_id', 'created_at']);
}
function myid_uninstall(array $manifest): void
{
    db_drop_table('plugin_myid_items');
}
```

- 类型：`id`、`uint`、`int`、`bigint`、`bool`、`float`、`string`(255)、`key`(191，可索引/唯一)、`text`、`mediumtext`。原生类型只在 SQLite 与 MySQL 5.7 都合法时才可用。
- 写入：`db_insert()`（返回 id）、`db_update()`、`db_delete()`、`db_upsert($table, $data, $keys)`（键需主键或唯一索引）、`db_insert_ignore()`、`db_increment()`。
- 多步写入放 `tx(function () { ... })`。
- 可移植助手：`db_greatest()`、`db_random()`、`db_like()`（配合 `ESCAPE '\\'`）、`sql_marks()`。
- 表结构只在 `install` 里改（版本变化时会再次执行，用 `db_ensure_columns()` 保证幂等）。
- 跨请求缓存：`save_settings(['myid_cache' => json_encode_value($v)])` + `setting('myid_cache')`；请求内：`request_cache('myid_x', fn() => ...)`。

## 9. 资源与前端

- CSS/JS 在 manifest 声明；不要在 Hook 里输出 `<style>`/`<script>`，除非内容必须动态且放在 `region.head`/`region.body.end`。
- 资源函数无参数、返回不带标签的源码，在启停时打包，不能依赖当前用户或页面；动态值通过 `data-myid-*` 属性传递。
- JS 用 IIFE 包裹。`window.FB` 提供 `base`、`csrf`、`uid`、`request(url, opts)`（自带 CSRF 头的 fetch）、`toast(msg, type)`、`initEditor(el)`；可监听 `fb:ready`、`fb:ajax`、`fb:editor` 事件。
- 只用 CSS 变量：`--bg --panel --panel-2 --line --line-soft --text --text-muted --text-subtle --brand --brand-hover --brand-soft --success --danger --warning --info`（及 `*-soft`）、`--radius --radius-sm --shadow`、字号 `--font-size-xs|sm|md|lg|xl|2xl`。不写死颜色和像素字号；不用 `!important`；选择器限制在自己的类下。
- 复用核心类：`.card`、`.card-head`、`.card-body`、`.btn`、`.btn-primary`、`.btn-sm`、`.tag-badge`、`.flag`、`.muted`、`.form-row`、`.table-wrap table.admin`。
- 静态文件（图片）放 `plugins/<id>/assets/`，可直接访问：`plugin_url($id, 'assets/logo.png')`。

## 10. 安全

- 一切输出 `h()`；所有输入校验并限长（`post_str()`、`post_int()`、`get_str()`、`get_int()`）。
- 权限：`uid()`、`me()`、`need_login()`、`need_mod()`、`need_admin()`、`can('post'|'reply'|'upload'|'edit_own'|'delete_own')`、`is_admin()`、`is_mod()`、`can_edit_post()`、`can_manage_topic()`、`category_can_view()`。
- 文件：扩展名白名单，不信任客户端 mime，复用 `upload_store()`；运行时不往 `plugins/` 写文件。
- 外部请求：curl 带连接/总超时和大小限制，拒绝内网地址，URL 不带凭据；不要在页面渲染时同步等远端，放到计划任务。
- 不记录、不展示 token、密码和完整 SQL。

## 11. 计划任务

```php
function myid_cleanup(array $job): string
{
    $n = db_delete('plugin_myid_items', 'created_at<?', [now() - 86400 * 30]);
    return $n . ' removed';         // 显示在 后台 → Scheduled jobs
}
'cron' => ['cleanup' => ['callback' => 'myid_cleanup', 'interval' => 86400]],
```

任务由 `/cron?key=…` 或 `php flatbb cron` 触发，带锁，只有启用的插件参与。任务要幂等、快速；长任务用队列表。

## 12. 翻译

用户可见文案包在 `t('English text')` 里。提供 `plugins/<id>/lang/<code>.php` 返回 `['English text' => '译文']`，会按当前语言自动加载。

## 13. 交付清单

- [ ] `php -l` 与 `php flatbb plugin:check <id>` 通过。
- [ ] 所有符号带插件 id 前缀（函数、表、CSS、JS、存储、Hook）。
- [ ] 循环里没有查询；循环 Hook 只用内存；没有残留占位符。
- [ ] 所有状态变更走 `require_post()`；权限已检查；输出已转义。
- [ ] SQLite 与 MySQL 5.7 都能跑（无方言 SQL，表结构走 `db_*` 助手）。
- [ ] 旧设置/旧数据仍可用；`install` 与 `uninstall` 可重复执行。
- [ ] `version` 已递增；`description` 准确；`README.md` 写明设置与用法。
- [ ] 窄屏、长文本、空状态、错误状态均已处理。

## 14. 给 AI 的提示词

```
Read CLAUDE.md, docs/PLUGIN.md and docs/HOOKS.md in this repository, then look at plugins/hello/plugin.php.
Create the plugin plugins/<id>/plugin.php that: <功能、显示位置、设置项、谁可以用>.
Follow every rule in docs/PLUGIN.md. When done run `php -l` and `php flatbb plugin:check <id>` and fix any findings.
```
