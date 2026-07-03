# 随机图片 API 开发文档

## 技术栈

当前项目使用 PHP 实现：

| 类型 | 选择 |
| --- | --- |
| 语言 | PHP 8.2+ |
| 索引 | SQLite / PDO SQLite |
| 配置 | `config.json` |
| 部署 | PHPStudy、Nginx/PHP-FPM、Apache、Docker |
| 测试 | 本地 smoke / HTTP 回归测试，不随源码提交 |

选择 PHP + SQLite 的原因：

- 随机图片 API 主要是文件输出、302 跳转和轻量查询，PHP 部署成本低。
- SQLite 可以把大量图片和 TXT 远程链接提前索引，避免每次请求递归扫描目录。
- HTTP 随机使用数据库计数和随机 offset，只取一条记录，避免把大分类整池读入 PHP。
- 短链接使用稳定数字 ID，例如 `/erciyuan/1.png`，不暴露原始长文件名。
- 当前需求支持配置白名单、递归子目录分类、TXT 远程链接、当前域名短链接、刷新避免连续重复。

## 目录约定

```text
api-image/
  public/
    index.php
  index.php
  cli.php
  config.json
  app/
    random_image.php
  images/        本地图片目录，不提交
  tests/         本地测试脚本，不提交
  .runtime/      SQLite、锁、日志，不提交
```

生产部署推荐将 Web 根目录指向 `public/`。根目录入口 `index.php` 保留给必须直接部署到项目根目录的 Apache/PHPStudy 场景，根目录 `.htaccess` 会阻止敏感路径被直接访问。

## 配置

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

关键配置：

- `folders`：顶层分类白名单，只有这里配置过的目录能访问。
- `server.allowedHosts`：生产环境建议填写真实域名，防止 Host 头污染生成的短链接。
- `server.trustProxy`：只有在可信反向代理后面才启用。
- `adminEnabled`：管理接口默认关闭。
- `adminToken` / `RI_ADMIN_TOKEN`：管理接口启用时必须提供。
- `adminAllowQueryToken`：默认关闭，建议只使用 `Authorization: Bearer ...`。
- `allowSvg`：默认关闭；除非确认 SVG 来源可信，否则不要启用。
- `linkCheck.allowedHosts`：可限制 TXT 远程链接只能来自指定域名或通配域名，例如 `*.example.com`。

## 索引流程

索引由 CLI 触发，HTTP 请求不触发扫描。

手动索引：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
```

只索引单个分类：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index --folder=erciyuan
```

查看状态：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php status
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

定时索引本质上也是定时执行 `cli.php index`，可以用 Windows 任务计划程序、Linux cron 或面板计划任务。

索引命令使用 `.runtime/index.lock` 文件锁，同一时间只允许一个索引进程运行，避免手动索引和定时索引同时写 SQLite。

索引过程：

1. 读取 `config.json`。
2. 只扫描 `folders` 白名单内的顶层目录。
3. 跳过符号链接目录和符号链接文件。
4. 递归读取允许扩展名的本地图片。
5. 读取每个目录下配置好的 `links.txt`。
6. 写入 SQLite。
7. 删除已经不存在于本次扫描结果里的旧索引。
8. 写入 `.runtime/index.log` JSON Lines 日志。

SQLite 打开时启用：

```sql
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA busy_timeout = 5000;
```

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

`remote_link_checks` 保存远程链接最近一次检测结果：

```text
folder       顶层分类
image_id     image_index.id
url          原始远程URL
ok           1 / 0
status_code  HTTP 状态码
error        错误信息
duration_ms  检测耗时
checked_at   检测时间
```

## 路由

- `GET /`：从全部已配置分类及子目录随机。
- `GET /:folder`：从指定配置分类及子目录随机。
- `GET /:folder/:subPath`：从指定子目录分类随机。
- `GET /:folder/:id.ext`：访问短图片链接。
- `GET /?json=1`：返回 JSON。
- `GET /_api/index`：管理接口启用并通过 Bearer Token 后查看索引状态。
- `GET /_api/folders`：管理接口启用并通过 Bearer Token 后查看分类统计。

浏览器请求规则：

- 当 `Accept` 包含 `text/html` 且未传 `?json=1` 时，随机接口返回 HTML 页面展示图片。
- HTML 模式下地址栏保持 `/`、`/erciyuan` 或 `/erciyuan/subPath`，刷新页面会重新随机。
- 非 HTML 请求返回 302 到短图片链接，方便作为图片 API、`<img>` 或 CSS 背景使用。
- 仅允许 `GET` 和 `HEAD`。

## 随机算法

HTTP 请求不会加载整个随机池。

流程：

1. 根据 `/`、`/:folder` 或 `/:folder/:subPath` 生成 SQL 条件。
2. 先 `COUNT(*)` 得到候选数量。
3. 如果当前 Session 里有上一次返回的图片，并且候选数量大于 1，则在 SQL 里排除上一张。
4. 使用 `random_int()` 生成 offset。
5. `LIMIT 1 OFFSET :offset` 只读取一条图片记录。

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
- 拒绝控制字符、反斜杠、localhost、内网 IP、保留地址和云 metadata 主机。
- 同一个 `links.txt` 内重复链接会去重。
- 返回给用户时转换为当前域名下的短链接，例如 `/erciyuan/3.jpg`。
- 访问远程短链接时由服务端 302 跳转到原始远程 URL。
- `check-links` 命令会对已索引远程链接做 HEAD/GET 检测，并校验重定向目标。
- 检测优先使用 PHP cURL 扩展；没有 cURL 时退回 PHP stream。
- 当前网络需要代理时，可以设置 `linkCheck.proxy` 或临时环境变量 `RI_HTTP_PROXY`。

## 本地图片输出

默认模式：

```json
{
  "sendfile": {
    "mode": "php"
  }
}
```

生产环境可选：

- `"x-sendfile"`：输出 `X-Sendfile` 头，由 Apache/lighttpd 等支持模块发送文件。
- `"x-accel"`：输出 `X-Accel-Redirect` 头，由 Nginx 内部路径发送文件。

Nginx `x-accel` 示例：

```json
{
  "sendfile": {
    "mode": "x-accel",
    "xAccelPrefix": "/_protected_images"
  }
}
```

```nginx
location /_protected_images/ {
    internal;
    alias /path/to/api-image/images/;
}
```

## 刷新换图

服务使用 PHP Session 记住当前访问者在每个随机路径上一次拿到的图片。

- 浏览器直接打开随机路径时不会跳到固定随机路径，因此刷新浏览器会重新随机。
- 同一路径随机池有 2 张以上图片时，下次刷新会避开上一张。
- `/`、`/erciyuan`、`/erciyuan/wallpaper` 分别独立记忆。
- `source=local` 和 `source=remote` 分别独立记忆。
- 随机池只有 1 张图片时允许重复。

## 安全要求

- Web 根目录推荐使用 `public/`。
- 根目录 `.htaccess` 阻止直接访问配置、源码、运行时目录、图片目录和测试目录。
- 禁止 `../` 路径穿越。
- 顶层路径必须命中 `config.json` 的 `folders`。
- 未配置目录即使真实存在也返回 `404`。
- 不返回服务器真实文件路径。
- 不暴露原始长文件名。
- 本地图片输出前用 `realpath` 重新确认路径仍在分类目录内。
- 拒绝扫描和输出符号链接文件。
- 管理接口默认关闭；启用后默认只接受 Bearer Token。
- 默认禁用 SVG。
- `_api`、`_assets`、`_remote` 是保留路径，不能作为顶层分类名。

## 本地验证

PHPStudy 的 PHP 8.2.9 NTS 路径：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
```

由于当前 `php.ini` 存在 PHP 8.2 不支持的 `track_errors`，命令行运行建议加 `-n`，并启用 SQLite 扩展：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -l app\random_image.php
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 cli.php index
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

每次 HTTP 测试完成后，停止本地服务进程。
