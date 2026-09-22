# 用 AI 开发 FlatBB 插件

FlatBB 的插件就是一个带 `plugin.php` 的文件夹，按一份简短而严格的规范来写。Claude Code、Codex、Cursor、ChatGPT 这类 AI 编程助手，一条提示词就能写出来：你描述插件要做什么，AI 读规范，你上传压缩包。全程不需要命令行。

英文原版 `docs/AI.md` 为准，本文为中文对照。

## 1. 把这段提示词交给 AI

```text
Read https://www.flatbb.com/dev/plugins.md first.
Then create a FlatBB plugin plugins/<id>/plugin.php that:
<插件做什么、显示在哪里、有哪些设置、谁可以使用>.
Follow every rule in the document.
Give me the finished folder as a zip I can upload under Admin -> Plugins.
```

`https://www.flatbb.com/dev/plugins.md` 是一个纯文本文件，包含 AI 需要的全部内容：本页、插件规范、所有 Hook 和 Region、核心 API、主题规则，并始终跟随 FlatBB 最新版本。

尖括号里写清楚这几点，AI 第一次就能写对：

- **显示在哪里**：侧栏卡片、每个帖子下方的一行、新页面 `/something`、后台页面、帖子页的按钮。
- **有哪些设置**，以及默认值：比如"数量上限，默认 20"。
- **谁可以使用**：所有人、会员、版主、管理员。
- **要保存什么数据**（如果有）：比如"记住哪些会员投过票"。

## 2. 安装到你的论坛

打开 **后台 → 插件 → 上传插件**，选择压缩包。新插件上传后立即启用，设置在列表里它那一行打开。如果有问题，把报错或看到的现象发回给 AI，再上传修好的压缩包：更新会保留插件的数据和设置。

## 3. 分享给所有人（可选）

在 https://www.flatbb.com/market/publish 发布压缩包，或者把论坛连接到插件市场后在后台发布，其他论坛就能一键安装。更新版本、发布前的检查等细节见 [发布插件](../PUBLISH.md)。

## 做主题而不是插件

同样的提示词，改成要一个主题："…create a FlatBB theme plugins/<id>/ with `'type' => 'theme'` that looks like <配色、风格、疏密、圆角、字体>. Support light and dark mode." 在 **后台 → 外观 → 主题** 上传。

## 在 FlatBB 源码里开发

电脑上有 FlatBB 源码时，AI 还能自己检查写得对不对：

```text
Read CLAUDE.md, docs/PLUGIN.md and docs/HOOKS.md in this repository,
then look at plugins/hello/plugin.php.
Create the plugin plugins/<id>/plugin.php that:
<插件做什么、显示在哪里、有哪些设置、谁可以使用>.
Follow every rule in docs/PLUGIN.md. When done run php -l and
php flatbb plugin:check <id> and fix any findings.
```

## 手动开发

AI 读的参考文档同样适合人读：[插件开发指南](PLUGIN.md)，以及英文的 Hooks、核心 API、主题、数据库和架构文档。
