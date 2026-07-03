# random-image-api

基于 PHP 8.2 + SQLite 的随机图片 API，适合部署到 PHPStudy、Nginx/PHP-FPM、Apache 或 Docker。

## 功能

- `GET /`：从所有已配置分类及其子目录中随机返回图片。
- `GET /:folder`：从已配置分类及其子目录中随机返回图片。
- `GET /:folder/:subPath`：从对应子目录分类中随机返回图片。
- `GET /:folder/:id.ext`：访问索引生成的短图片链接，本地图片直接输出，TXT 远程链接会 302 跳转。
- `GET /?json=1`：返回当前域名下的短图片链接 JSON。
- `GET /_api/index`：查看索引状态。
- `GET /_api/folders`：查看已启用分类统计。

浏览器直接打开 `/`、`/erciyuan` 这类随机路径时，会返回一个显示图片的 HTML 页面，地址栏保持随机路径；刷新页面会重新随机。作为 `<img>` 或 CSS 背景请求时，接口会返回 302 到短图片链接。

只有 `config.json` 的 `folders` 中配置过的顶层目录可以访问。本地存在但未配置的目录会被忽略。

## 索引触发方式

HTTP 请求不扫描目录，只读取 SQLite 索引，避免图片很多时访问卡死。

索引触发只有两种方式：

- 手动索引：命令行执行 `php cli.php index`。
- 定时索引：用 Windows 任务计划程序或 Linux cron 定时执行同一个命令。

新增、删除、移动图片，或修改 `links.txt` 后，需要重新索引才会在接口中生效。

## 配置

默认配置读取 `images/erciyuan`：

```json
{
  "imageRoot": "images",
  "folders": ["erciyuan"],
  "linkFiles": ["links.txt"],
  "indexDatabase": ".runtime/image-index.sqlite"
}
```

子文件夹会自动成为分类路径。例如：

```text
images/
  erciyuan/
    001.jpg
    links.txt
    wallpaper/
      002.jpg
      links.txt
```

- 访问 `/`：从所有已配置目录、子目录和 TXT 链接中随机。
- 访问 `/erciyuan`：从 `erciyuan` 及其全部子目录中随机。
- 访问 `/erciyuan/wallpaper`：只从 `erciyuan/wallpaper` 及其子目录中随机。

## 本地运行

你本机 PHP 目录：

```text
D:\phpstudy_pro\Extensions\php\php8.2.9nts
```

当前这个 PHP 的 `php.ini` 包含 PHP 8.2 不再支持的 `track_errors`，所以命令行建议加 `-n` 忽略默认 ini，并手动启用 SQLite 扩展：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 index.php
```

查看索引状态：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php status
```

测试脚本：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 tests\smoke.php
```

测试或访问完成后记得停止服务进程。

## 定时索引

Windows 任务计划程序可以让它每小时执行一次：

```powershell
schtasks /Create /SC HOURLY /TN "RandomPicApiIndex" /TR "\"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe\" -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 \"D:\project\api-image\cli.php\" index" /F
```

Linux cron 示例：

```cron
0 * * * * cd /path/to/api-image && php cli.php index >/tmp/random-pic-index.log 2>&1
```

## 部署提示

Apache 可直接使用项目内的 `.htaccess` 将路径转发到 `index.php`。

Nginx 可使用类似规则：

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}
```
