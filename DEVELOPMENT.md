# 随机图片 API 开发文档

## 技术栈

当前项目使用 PHP 实现：

| 类型 | 选择 |
| --- | --- |
| 语言 | PHP 8.2+ |
| 索引 | SQLite / PDO SQLite |
| 配置 | `config.json` |
| 部署 | PHPStudy、Nginx/PHP-FPM、Apache、Docker |
| 测试 | 原生 PHP smoke test |

选择 PHP + SQLite 的原因：

- 随机图片 API 主要是文件输出、302 跳转和轻量查询，PHP 部署成本低。
- SQLite 可以把大量图片和 TXT 远程链接提前索引，避免每次请求递归扫描目录。
- 短链接可以使用稳定数字 ID，例如 `/erciyuan/1.png`，不暴露原始长文件名。
- 当前需求支持配置白名单、递归子目录分类、TXT 远程链接、当前域名短链接、刷新避免连续重复。

## 目录约定

```text
api-image/
  index.php
  cli.php
  config.json
  app/
    random_image.php
  images/
    erciyuan/
      001.jpg
      links.txt
      wallpaper/
        002.jpg
        links.txt
  .runtime/
    image-index.sqlite
```

`.runtime/` 是运行时目录，不提交到 git。

## 配置

```json
{
  "server": {
    "host": "0.0.0.0",
    "port": 3000,
    "trustProxy": true
  },
  "imageRoot": "images",
  "folders": ["erciyuan"],
  "linkFiles": ["links.txt"],
  "adminPrefix": "/_api",
  "indexDatabase": ".runtime/image-index.sqlite",
  "imageExtensions": [".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif", ".bmp", ".svg"],
  "defaultMode": "redirect"
}
```

只有 `folders` 中配置过的顶层目录可以通过 URL 访问。

## 索引流程

索引由 CLI 触发，HTTP 请求不触发扫描。

手动索引：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
```

查看状态：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php status
```

定时索引本质上也是定时执行 `cli.php index`。可以用 Windows 任务计划程序、Linux cron 或面板自带计划任务。

索引过程：

1. 读取 `config.json`。
2. 只扫描 `folders` 白名单内的顶层目录。
3. 递归读取本地图片。
4. 读取每个目录下配置好的 `links.txt`。
5. 写入 SQLite。
6. 删除已经不存在于本次扫描结果里的旧索引。

## SQLite 表

`image_index` 保存图片本体：

```text
folder      顶层分类
index_key   稳定键，local:相对路径 或 remote:原始URL
source_type local / remote
target      本地相对路径或远程原始URL
extension   短链接后缀，不带点
id          分类内稳定数字ID
```

`image_paths` 保存分类路径关系：

```text
path      可访问分类路径，例如 erciyuan 或 erciyuan/wallpaper
folder    顶层分类
image_id  image_index.id
```

这样 `/erciyuan` 和 `/erciyuan/wallpaper` 都只需要查表，不需要扫描文件系统。

## 路由

- `GET /`：从全部已配置分类及子目录随机。
- `GET /:folder`：从指定配置分类及子目录随机。
- `GET /:folder/:subPath`：从指定子目录分类随机。
- `GET /:folder/:id.ext`：访问短图片链接。
- `GET /?json=1`：返回 JSON。
- `GET /_api/index`：查看索引状态。
- `GET /_api/folders`：查看分类统计。
- `GET /_api/folders/:folder`：查看单个分类统计。

浏览器请求规则：

- 当 `Accept` 包含 `text/html` 且未传 `?json=1` 时，随机接口返回 HTML 页面展示图片。
- HTML 模式下地址栏保持 `/`、`/erciyuan` 或 `/erciyuan/subPath`，刷新页面会重新随机。
- 非 HTML 请求返回 302 到短图片链接，方便作为图片 API、`<img>` 或 CSS 背景使用。

## 子目录分类

子文件夹自动成为路径分类：

```text
images/erciyuan/wallpaper/002.jpg
```

对应：

```text
/erciyuan/wallpaper
```

父路径会包含子路径内容：

- `/` 包含所有配置分类和全部子目录。
- `/erciyuan` 包含 `images/erciyuan` 和它的全部子目录。
- `/erciyuan/wallpaper` 只包含 `images/erciyuan/wallpaper` 及其子目录。

## TXT 链接

每个目录下的 `links.txt` 每行一个远程图片链接。

规则：

- 空行忽略。
- `#` 开头的注释忽略。
- 只接受 `http://` 和 `https://`。
- 同一个 `links.txt` 内重复链接会去重。
- 返回给用户时转换为当前域名下的短链接，例如 `/erciyuan/3.jpg`。
- 访问远程短链接时由服务端 302 跳转到原始远程 URL。

## 刷新换图

服务使用 PHP Session 记住当前访问者在每个随机路径上一次拿到的图片。

- 浏览器直接打开随机路径时不会跳到固定随机路径，因此刷新浏览器会重新随机。
- 同一路径随机池有 2 张以上图片时，下次刷新会避开上一张。
- `/`、`/erciyuan`、`/erciyuan/wallpaper` 分别独立记忆。
- `source=local` 和 `source=remote` 分别独立记忆。
- 随机池只有 1 张图片时允许重复。

## 安全要求

- 禁止 `../` 路径穿越。
- 顶层路径必须命中 `config.json` 的 `folders`。
- 未配置目录即使真实存在也返回 `404`。
- 不返回服务器真实文件路径。
- 不暴露原始长文件名。
- `_api`、`_assets`、`_remote` 是保留路径，不能作为顶层分类名。

## 本地验证

PHPStudy 的 PHP 8.2.9 NTS 路径：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
```

由于当前 `php.ini` 存在 PHP 8.2 不支持的 `track_errors`，命令行运行建议加 `-n`，并启用 SQLite 扩展：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 tests\smoke.php
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 index.php
```
