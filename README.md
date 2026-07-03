# random-image-api

基于 PHP 8.2 + SQLite 的随机图片 API。项目用于从配置好的本地分类目录和目录内 `links.txt` 远程直链中随机返回图片，并生成当前域名下的短链接，例如 `/erciyuan/1.png`。

## 功能

- `GET /`：从所有已配置分类及其子目录中随机。
- `GET /:folder`：从指定分类及其子目录中随机。
- `GET /:folder/:subPath`：从指定子目录分类中随机。
- `GET /:folder/:id.ext`：访问索引生成的短图片链接；本地图片直接输出，TXT 远程链接 302 跳转到原始 URL。
- `GET /?json=1`：返回当前域名下的短图片链接 JSON。
- 浏览器直接打开 `/`、`/erciyuan` 时返回一个展示图片的 HTML 页面，刷新会重新随机。
- 作为 `<img>` 或 CSS 背景请求时，接口返回 302 到短图片链接。
- HTTP 请求只查 SQLite，不实时扫描目录，避免图片过多时卡住。

只有 `config.json` 的 `folders` 中配置过的顶层目录可以访问。本地存在但未配置的目录会返回 `404`。

## 源码与运行数据

仓库只提交源码、配置模板和文档，不提交运行数据：

- `.runtime/`：SQLite、索引锁、索引日志。
- `images/`：本地图片目录。
- `test-image/`：临时测试图片。
- `tests/`：本地测试脚本。

部署后在服务器本地创建图片目录，例如 `images/erciyuan`，再执行索引命令。

## 配置

默认配置读取 `images/erciyuan`：

```json
{
  "server": {
    "host": "0.0.0.0",
    "port": 3000,
    "trustProxy": false,
    "allowedHosts": []
  },
  "imageRoot": "images",
  "folders": ["erciyuan"],
  "linkFiles": ["links.txt"],
  "adminPrefix": "/_api",
  "adminEnabled": false,
  "adminToken": "",
  "adminAllowQueryToken": false,
  "indexDatabase": ".runtime/image-index.sqlite",
  "indexLock": ".runtime/index.lock",
  "indexLog": ".runtime/index.log",
  "imageExtensions": [".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif", ".bmp"],
  "allowSvg": false,
  "defaultMode": "redirect",
  "linkCheck": {
    "timeoutSeconds": 5,
    "userAgent": "random-image-api/1.0",
    "proxy": "",
    "verifyTls": true,
    "allowedHosts": []
  },
  "sendfile": {
    "mode": "php",
    "xAccelPrefix": ""
  }
}
```

生产环境建议设置 `server.allowedHosts`，例如：

```json
{
  "server": {
    "allowedHosts": ["example.com", "www.example.com"]
  }
}
```

如果服务位于可信反向代理后面，才将 `server.trustProxy` 改为 `true`。

## 目录示例

```text
images/
  erciyuan/
    001.jpg
    links.txt
    wallpaper/
      002.jpg
      links.txt
```

- `/` 包含所有配置分类、子目录和 TXT 链接。
- `/erciyuan` 包含 `erciyuan` 及其全部子目录。
- `/erciyuan/wallpaper` 只从对应子目录及其子目录随机。

## 索引

HTTP 请求不会扫描目录。新增、删除、移动图片，或修改 `links.txt` 后，需要重新索引。

你本机 PHP 路径：

```text
D:\phpstudy_pro\Extensions\php\php8.2.9nts
```

当前 PHP 的默认 `php.ini` 包含 PHP 8.2 不再支持的 `track_errors`，命令行建议加 `-n` 并手动启用 SQLite：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
```

查看索引状态：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php status
```

只重建单个分类：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index --folder=erciyuan
```

查看已索引路径：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php paths
```

检查远程链接：

```powershell
$env:RI_HTTP_PROXY="http://127.0.0.1:10808"
$env:RI_LINKCHECK_VERIFY_TLS="0"
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=curl -d extension=pdo_sqlite -d extension=sqlite3 cli.php check-links
```

`RI_HTTP_PROXY` 只在当前网络需要代理时设置。`RI_LINKCHECK_VERIFY_TLS=0` 只建议本地测试使用，生产环境保持 TLS 校验开启。

## 本地运行

推荐使用 `public/` 作为 Web 根目录：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

测试或访问完成后停止服务进程。

## 管理接口

`/_api` 管理接口默认关闭。需要启用时：

```json
{
  "adminEnabled": true,
  "adminToken": "replace-with-a-long-random-token"
}
```

请求时使用 Bearer Token：

```powershell
curl.exe -H "Authorization: Bearer replace-with-a-long-random-token" http://127.0.0.1:3000/_api/index
```

默认不接受 `?token=`，避免 token 出现在浏览器历史或访问日志中。确实需要时可将 `adminAllowQueryToken` 改为 `true`。

## 安全默认值

- 推荐 Web 根目录指向 `public/`。
- 根目录 `.htaccess` 会阻止访问 `config.json`、`app/`、`.runtime/`、`images/`、`tests/` 等敏感路径。
- 只允许 `GET` 和 `HEAD` 请求。
- 顶层分类必须在 `folders` 白名单中。
- 路径会拒绝 `../`、反斜杠和空字节。
- 短链接不暴露原始文件名。
- SVG 默认禁用，避免脚本型 SVG 风险。
- 本地输出前会再次校验真实路径仍在分类目录内，并拒绝符号链接。
- TXT 远程链接会拒绝 `localhost`、内网 IP、保留地址和云 metadata 主机；跳转检测也会校验重定向目标。
- 远程链接可用 `linkCheck.allowedHosts` 进一步限制允许的域名。

## 定时索引

Windows 任务计划程序每小时索引一次：

```powershell
schtasks /Create /SC HOURLY /TN "RandomPicApiIndex" /TR "\"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe\" -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 \"D:\project\api-image\cli.php\" index" /F
```

Linux cron 示例：

```cron
0 * * * * cd /path/to/api-image && php cli.php index >/tmp/random-pic-index.log 2>&1
```

索引命令带文件锁，同一时间只允许一个索引任务运行。SQLite 默认启用 WAL，接口读取和索引写入可以更稳定地并发。

## 部署提示

Apache 或 Nginx 推荐将站点根目录指向 `public/`。

Nginx 示例：

```nginx
root /path/to/api-image/public;

location / {
    try_files $uri /index.php$is_args$args;
}
```

默认由 PHP 输出本地图片。高并发或大图场景可以在 `config.json` 中将 `sendfile.mode` 设置为 `x-sendfile` 或 `x-accel`，交给 Apache/Nginx 输出文件。
