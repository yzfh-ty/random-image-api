# random-image-api

[English](README.md) | 简体中文

基于 PHP 8.2 + SQLite 的随机图片 API。项目用于从配置好的本地分类目录和目录内 `links.txt` 远程直链中随机返回图片，并生成当前域名下的短链接，例如 `/erciyuan/1.png`。

## 功能

- `GET /`：从所有已配置分类中随机。
- `GET /:folder`：从指定分类中随机。
- `GET /:folder/:id.ext`：访问索引生成的短图片链接；本地图片直接输出，TXT 远程链接 302 跳转到原始 URL。
- `GET /?json=1`：返回当前域名下的短图片链接 JSON。
- 浏览器直接打开 `/`、`/erciyuan` 时返回一个展示图片的 HTML 页面，刷新会重新随机。
- 作为 `<img>` 或 CSS 背景请求时，接口返回 302 到短图片链接。
- 本地图片索引时会按宽高识别类型：横图为 `pc`，竖图为 `mobile`，并在索引时移动到受管理的 `pc/` 或 `mobile/` 目录。
- 请求可用 `?type=pc` 或 `?type=mobile` 指定类型；不传时会根据 Client Hints 或 User-Agent 自动判断。
- HTTP 请求只查 SQLite，不实时扫描目录，避免图片过多时卡住。

只有 `RI_FOLDERS` 中配置过的顶层目录可以访问。本地存在但未配置的目录会返回 `404`。

## 源码与运行数据

仓库只提交源码、配置模板和文档，不提交运行数据和本地配置：

- `.env`：本地运行配置，从 `.env.example` 复制生成。
- `.runtime/`：SQLite、索引锁、索引日志和本地测试临时文件。
- `images/`：本地图片目录。

部署后先复制 `.env.example` 为 `.env`，然后在服务器本地创建图片目录，例如 `images/erciyuan`，最后执行索引命令。

## 配置

项目使用真实环境变量或本地 `.env` 配置。运行前先复制一份示例配置：

```powershell
Copy-Item .env.example .env
```

配置优先级为：系统环境变量、`.env`、程序默认值。

唯一必填变量是 `RI_FOLDERS`：

```dotenv
RI_FOLDERS=erciyuan,fengjing
RI_ALLOWED_HOSTS=example.com,www.example.com
RI_TRUST_PROXY=false
RI_ADMIN_ENABLED=false
RI_ADMIN_TOKEN=
RI_DEFAULT_MODE=redirect
RI_HTTP_PROXY=
```

完整变量列表见 `.env.example`。默认只接受 `localhost`、`127.0.0.1` 和 `[::1]` 作为请求 Host；部署到生产域名之前必须把域名写入 `RI_ALLOWED_HOSTS`。只有服务位于可信反向代理后面，才设置 `RI_TRUST_PROXY=true`。

## 目录示例

```text
images/
  erciyuan/
    001.jpg            # 新上传图片；索引时会按尺寸移动
    links.txt
    pc/
      002.jpg
    mobile/
      003.jpg
```

- `/` 包含所有配置分类和分类根目录的 TXT 链接。
- `/erciyuan` 只包含 `erciyuan` 这个分类。
- `/erciyuan/pc` 和 `/erciyuan/mobile` 不是公开路由；需要横图或竖图时使用 `/erciyuan?type=pc` 或 `/erciyuan?type=mobile`。
- 其它子目录会被索引忽略，不再作为子分类。

## PC 和 Mobile 图片

索引时，本地图片会按尺寸分类：

- `pc`：宽度大于高度，适合 PC 横屏背景。
- `mobile`：高度大于宽度，适合手机竖屏背景。
- `square`：宽高相同。
- `unknown`：无法读取尺寸，或来自 TXT 远程链接。

新上传的本地图片直接放在分类目录，例如 `images/erciyuan/001.jpg`。执行索引命令后，横图会移动到 `images/erciyuan/pc/`，竖图会移动到 `images/erciyuan/mobile/`。方图或无法识别尺寸的图片会留在分类目录。

随机接口支持显式指定类型：

```text
/erciyuan?type=pc
/erciyuan?type=mobile
```

也支持别名：`desktop`、`landscape`、`horizontal` 等同于 `pc`；`phone`、`portrait`、`vertical` 等同于 `mobile`。

不传 `type` 时，浏览器请求会根据 Client Hints 或 User-Agent 自动判断。作为 CSS 背景调用时，浏览器通常也会带 User-Agent，所以多数情况下可以自动适配；如果你想强制背景方向，建议显式使用 `?type=pc` 或 `?type=mobile`。

`links.txt` 里的远程链接默认记为 `unknown`，因为服务不会下载远程图片来读取尺寸。不带类型过滤时会参与随机；请求 `pc` 或 `mobile` 时会被排除。

## 索引

HTTP 请求不会扫描目录。新增、删除、移动图片，或修改分类根目录的 `links.txt` 后，需要重新索引。

索引命令也会整理本地文件：必要时在每个已配置分类里创建 `pc/` 和 `mobile/`，然后把识别出的横图和竖图移动进去。识别不出来的文件保持原位置。

索引日志写入 `RI_INDEX_LOG`，并按大小自动轮转。默认活动日志不超过 `1048576` 字节，保留 `3` 个历史备份；需要时可在 `.env` 中调整 `RI_INDEX_LOG_MAX_BYTES` 和 `RI_INDEX_LOG_BACKUPS`。

你本机 PHP 路径：

```text
D:\phpstudy_pro\Extensions\php\php8.2.9nts
```

当前 PHP 的默认 `php.ini` 包含 PHP 8.2 不再支持的 `track_errors`，命令行建议加 `-n` 并手动启用 SQLite：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php index
```

查看索引状态：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php status
```

查看脱敏后的实际生效配置：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php config --json
```

检查本地运行环境、必要 PHP 扩展、路径和已配置分类：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php doctor
```

只重建单个分类：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php index --folder=erciyuan
```

检查远程链接：

```powershell
$env:RI_HTTP_PROXY="http://127.0.0.1:10808"
$env:RI_LINKCHECK_VERIFY_TLS="0"
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=curl -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php check-links
```

`RI_HTTP_PROXY` 只在当前网络需要代理时设置。`RI_LINKCHECK_VERIFY_TLS=0` 只建议本地测试使用，生产环境保持 TLS 校验开启。
`RI_LINKCHECK_CONCURRENCY` 默认是 `4`，启用 cURL 扩展时使用 cURL multi 并发检查；未启用 cURL 时会自动退回串行 stream 请求。
`RI_LINKCHECK_BIND_RESOLVED_IP=true` 会让 cURL 检查绑定到校验阶段解析出的公网 IP，在不走代理时降低 DNS rebinding 风险。生产环境建议把可信图片域名写入 `RI_LINKCHECK_ALLOWED_HOSTS`。

## 本地运行

推荐使用 `public/` 作为 Web 根目录：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

测试或访问完成后停止服务进程。

## Docker

构建镜像：

```powershell
docker build -t random-image-api .
```

运行时挂载当前项目里的图片目录和运行目录：

```powershell
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime random-image-api
```

容器默认启动时会自动索引。若想手动控制索引，可设置 `RI_AUTO_INDEX_ON_START=false`，再用 `docker run --rm --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime random-image-api php bin/console.php index` 单独执行。

## 管理接口

`/_api` 管理接口默认关闭。需要启用时：

```dotenv
RI_ADMIN_ENABLED=true
RI_ADMIN_TOKEN=replace-with-a-long-random-token
```

请求时使用 Bearer Token：

```powershell
curl.exe -H "Authorization: Bearer replace-with-a-long-random-token" http://127.0.0.1:3000/_api/index
```

默认不接受 `?token=`，避免 token 出现在浏览器历史或访问日志中。确实需要时可设置 `RI_ADMIN_ALLOW_QUERY_TOKEN=true`。

## 安全默认值

- 推荐 Web 根目录指向 `public/`。
- `public/.htaccess` 负责 Apache 路由重写并阻止访问点文件；应用入口只保留在 `public/`。
- 只允许 `GET` 和 `HEAD` 请求。
- Host 头受 `RI_ALLOWED_HOSTS` 限制；生产域名必须显式列出。
- 顶层分类必须配置在 `RI_FOLDERS` 中。
- 路径会拒绝 `../`、反斜杠和空字节。
- 短链接不暴露原始文件名。
- SVG 默认禁用，避免脚本型 SVG 风险。
- 本地输出前会再次校验真实路径仍在分类目录内，并拒绝符号链接。
- TXT 远程链接会拒绝 `localhost`、内网 IP、保留地址和云 metadata 主机；跳转检测也会校验重定向目标。
- 远程链接可用 `RI_LINKCHECK_ALLOWED_HOSTS` 进一步限制允许的域名。

## 定时索引

Windows 任务计划程序每小时索引一次：

```powershell
schtasks /Create /SC HOURLY /TN "RandomPicApiIndex" /TR "\"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe\" -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 \"D:\project\api-image\bin\console.php\" index" /F
```

Linux cron 示例：

```cron
0 * * * * cd /path/to/api-image && php bin/console.php index >/tmp/random-pic-index.log 2>&1
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

默认由 PHP 输出本地图片。高并发或大图场景可以设置 `RI_SENDFILE_MODE=x-sendfile` 或 `RI_SENDFILE_MODE=x-accel`，交给 Apache/Nginx 输出文件。
