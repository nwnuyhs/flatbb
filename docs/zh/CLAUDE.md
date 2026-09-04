# flatbb — AI 助手开发指南（中文版）

英文原版在仓库根目录 `CLAUDE.md`（AI 工具会自动读取），本文是同步的中文说明。

flatbb 是纯 PHP 8.1+ 的轻量论坛：无框架、无 Composer、无构建；SQLite 或 MySQL 5.7+。

## 你可能要做的事

| 任务 | 先读 | 只改 |
| --- | --- | --- |
| 写或改**插件** | `docs/PLUGIN.md`（规则）、`docs/HOOKS.md`（位置）、`docs/API.md`（函数） | `plugins/<id>/` |
| 改**外观** | `docs/THEME.md` | `assets/app.css`、`app/views/*.php`，更好的做法是主题插件 |
| 改**核心行为** | `docs/ARCHITECTURE.md` | `core/*.php`、`app/*.php` |
| 改**数据库** | `docs/DATABASE.md` | `core/schema.php`（核心）或插件的 `install` |
| 发布插件到官网 | `docs/PUBLISH.md` | `php flatbb plugin:publish <id>` |

## 目录地图

```
index.php          Web 入口：require core/boot.php; app_boot(); dispatch();
flatbb             命令行入口（php flatbb <命令>）
core/              内核，每个文件只管一件事，全部是普通函数
  boot.php         常量、config()、错误处理、加载顺序
  helpers.php      h()、t()、now()、URL 助手、站点设置、request_cache()、json/redirect/fail
  db.php           q()/one()/val()/col()/tx()、db_insert/update/upsert、db_create_table()、db_types()
  schema.php       核心表和索引、schema_install()、schema_seed()
  auth.php         me()/uid()、Cookie、CSRF、用户组、can()、users_by_ids()
  hook.php         hook()/fire()、region()/region_list()/slot()、regions_known()、后台自定义 HTML 块
  plugin.php       插件注册表、manifest、设置表单、启用/停用、资源合并
  render.php       view()、page()、icon()、avatar()、pagination()、表单助手、editor()
  markdown.php     md() 安全 Markdown 渲染、md_excerpt()、md_mentions()
  upload.php       附件、头像、图片缩放
  search.php       FTS5 / MySQL FULLTEXT / LIKE 搜索索引
  cron.php         计划任务（cron_run）与核心任务
  router.php       路由表、dispatch()、url()、topic_url()、current_path()
  devtools.php     plugin_check()、plugin_package()、plugin_publish()、文档生成器（仅 CLI）
app/               请求处理器：一个区域一个文件，函数命名 <区域>_<动作>()
app/views/         PHP 模板，由 view('name', $vars) 渲染；layout.php 是页面骨架
assets/            app.css（CSS 变量、三栏网格）、app.js（原生 JS，data-* 驱动）
plugins/<id>/      插件；plugin.php 返回 manifest；hello/ 是参考示例
lang/en.php        翻译（英文文案本身是键：t('text')）
data/              运行数据：config.php、flatbb.sqlite、cache/（禁止 Web 访问，不提交）
uploads/           用户文件（可访问，禁止执行 PHP）
docs/              文档（HOOKS.md 与 API.md 由命令生成）
```

## 硬性规则（核心与插件通用）

1. **所有输出都要转义**：进 HTML 的值一律 `h()`；Markdown 用 `md()`，它自带净化。
2. **绝不用用户输入拼 SQL**：用 `q()`/`one()`/`val()` 加 `?` 占位符；标识符用反引号。
3. **只写可移植 SQL**（SQLite 与 MySQL 5.7 都能跑）：不用 JSON 列、CTE、窗口函数、方言函数。建表只用 `db_create_table()`/`db_ensure_columns()`/`db_create_index()` 和 `db_types()` 里的类型名。
4. **禁止 N+1**：循环里不查库。先收集 ID，用 `rows_by_ids()`/`users_by_ids()`/`IN (...)` 一次查出，再在内存映射。标记为"loop, no DB"的 Hook（`topic_list.item.*`、`post.*`）完全不能碰数据库。
5. **改状态必须 POST + CSRF**：处理器先调 `require_post()`；表单含 `csrf_field()`；权限用 `need_login()`、`need_admin()`、`can()` 判断。
6. **文件保持小**：单文件不超过 30 KB，超了就按职责拆分。
7. **只用普通函数，不用类和全局变量**：请求内缓存走 `request_cache()`。
8. **插件不改核心文件**。缺 Hook 就在核心加一行 Hook 并写进文档。
9. **插件所有符号加前缀**：函数 `myplugin_*`、表 `plugin_myplugin_*`、CSS 类 `.myplugin-*`、JS 全局 `myplugin_*`。
10. 插件每次改动都要**递增 manifest 的 `version`**。

## 约定

- 处理器函数返回类型为 `never`，以 `page()`、`redirect()` 或 `json_ok()` 结束。
- 视图是普通 PHP 短标签模板，逻辑放在 `app/*.php`。
- 用户可见文案走 `t('English text')` 以便翻译。
- 时间是 Unix 秒（`now()`），布尔是 0/1，JSON 存 TEXT 列。
- URL 用 `url('/path')`、`topic_url($topic)`、`category_url($c)`、`user_url($u)`、`admin_url('page')` 生成，不硬编码。
- 图标用 `icon('name')`；颜色和尺寸来自 `assets/app.css` 的 CSS 变量。

## 常用命令

```bash
php flatbb plugin:check <id>      # 语法 + 命名规则检查（官网审核同样的检查）
php flatbb plugin:sync            # 注册 plugins/ 里的插件
php flatbb plugin:enable <id>
php flatbb plugin:publish <id>    # 打包并上传到 www.flatbb.com（需要 FLATBB_TOKEN）
php flatbb hooks:list > docs/HOOKS.md
php flatbb api:list   > docs/API.md
php flatbb cron
php -l <file>                     # 改过的 PHP 文件都要过一遍语法检查
```

## 完成标准

- 改过的文件 `php -l` 通过；插件 `php flatbb plugin:check <id>` 通过。
- 循环里没有新增查询；列表页查询数控制在 10 条以内。
- SQLite 和 MySQL 5.7 都能运行。
- 用户可见文案已转义、可翻译、为英文。
- 插件：版本已递增、描述准确、install/uninstall 可重复执行。
