# random-image-api

[English](README.md) | 简体中文

基于 PHP 8.2 + SQLite 的随机图片 API。项目用于从配置好的本地分类目录和目录内 `links.txt` 远程直链中随机返回图片，并生成当前域名下的短链接，例如 `/category-a/1.png`。

## 功能

- `GET /`：从所有已配置分类中随机。
- `GET /:folder`：从指定分类中随机。
- `GET /:folder/:id.ext`：访问索引生成的短图片链接；本地图片直接输出，TXT 远程链接 302 跳转到原始 URL。
- `GET /_health`：返回适合监控使用的简洁健康检查 JSON。
- `GET /?json=1`：返回当前域名下的短图片链接 JSON。
- 浏览器直接打开 `/`、`/category-a` 时返回一个展示图片的 HTML 页面，刷新会重新随机。
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

部署后先复制 `.env.example` 为 `.env`，然后在服务器本地创建图片目录，例如 `images/category-a`，最后执行索引命令。

## 配置

项目使用真实环境变量或本地 `.env` 配置。运行前先复制一份示例配置：

```powershell
Copy-Item .env.example .env
```

配置优先级为：系统环境变量、`.env`、程序默认值。

唯一必填变量是 `RI_FOLDERS`：

```dotenv
RI_FOLDERS=category-a,category-b
RI_ALLOWED_HOSTS=example.com,www.example.com
RI_TRUST_PROXY=false
RI_ADMIN_ENABLED=false
RI_ADMIN_TOKEN=
RI_DEFAULT_MODE=redirect
RI_HTTP_PROXY=
```

完整变量列表见 `.env.example`；`.env.production.example` 列出了偏生产环境的配置模板。默认只接受本地主机和对应的 `:3000` 变体作为请求 Host；部署到生产域名之前必须把域名写入 `RI_ALLOWED_HOSTS`。Host 端口会精确匹配，如果客户端会发送端口，也要把端口写进去。只有服务位于可信反向代理后面，才设置 `RI_TRUST_PROXY=true`。

### 配置变量说明

| 变量 | 含义 | 默认值 / 示例 |
| --- | --- | --- |
| `RI_FOLDERS` | 允许访问的顶层图片分类，多个分类用英文逗号分隔。 | `category-a,category-b` |
| `RI_IMAGE_ROOT` | 本地图片根目录；相对路径基于项目根目录解析。 | `images` |
| `RI_LINK_FILES` | 每个分类目录内用于读取远程图片直链的 TXT 文件名。 | `links.txt` |
| `RI_DEFAULT_MODE` | 默认随机响应模式：`redirect` 或 `json`。 | `redirect` |
| `RI_SERVER_HOST` | 本地 PHP 内置 server 命令监听的主机；Docker 使用 Apache。 | `0.0.0.0` |
| `RI_SERVER_PORT` | Docker Apache 和本地 PHP 服务启动时监听的端口。 | `3000` |
| `RI_HEALTHCHECK_HOST` | 可选 Docker 健康检查 Host 头；默认使用 `RI_ALLOWED_HOSTS` 的第一个值。 | 空 |
| `RI_ALLOWED_HOSTS` | 允许的请求 Host 头；端口会精确匹配，生产域名必须写在这里。 | `example.com,www.example.com` |
| `RI_TRUST_PROXY` | 是否信任反向代理传入的 `X-Forwarded-Proto` 和 `X-Forwarded-Host`。 | `false` |
| `RI_ADMIN_ENABLED` | 是否启用只读管理状态接口。 | `false` |
| `RI_ADMIN_PREFIX` | 管理接口路由前缀，此前缀也会成为保留分类名。 | `/_api` |
| `RI_ADMIN_TOKEN` | 管理接口启用后要求的 Bearer Token，至少 32 个字符。 | 空 |
| `RI_ADMIN_ALLOW_QUERY_TOKEN` | 是否允许用 `?token=` 传管理 token，优先使用 Bearer Token。 | `false` |
| `RI_INDEX_DATABASE` | SQLite 索引数据库路径。 | `.runtime/image-index.sqlite` |
| `RI_INDEX_LOCK` | 索引文件锁路径，用于避免并发重建索引。 | `.runtime/index.lock` |
| `RI_INDEX_LOG` | JSONL 索引日志路径。 | `.runtime/index.log` |
| `RI_INDEX_LOG_MAX_BYTES` | 索引日志达到多少字节后轮转；`0` 表示关闭大小轮转。 | `1048576` |
| `RI_INDEX_LOG_BACKUPS` | 保留的索引日志轮转备份数量。 | `3` |
| `RI_IMAGE_EXTENSIONS` | 允许索引的本地图片扩展名；SVG 需要额外开启。 | `.jpg,.jpeg,.png,.gif,.webp,.avif,.bmp` |
| `RI_ALLOW_SVG` | 是否允许索引/输出 SVG；除非确实需要，否则保持关闭。 | `false` |
| `RI_MAX_IMAGE_BYTES` | 本地单个图片文件最大字节数；`0` 表示不限制。 | `52428800` |
| `RI_REMOTE_REQUIRE_CHECKED` | 远程短链接跳转前是否要求最近一次 `check-links` 成功。 | `true` |
| `RI_REMOTE_CHECK_MAX_AGE` | 远程链接成功检查结果的最大有效期，单位秒。 | `86400` |
| `RI_HTTP_PROXY` | 远程链接检查时使用的可选 HTTP 代理。 | `http://127.0.0.1:10808` |
| `RI_LINKCHECK_TIMEOUT` | 单个远程链接检查超时时间，单位秒。 | `5` |
| `RI_LINKCHECK_CONCURRENCY` | cURL 可用时远程链接检查的最大并发数。 | `4` |
| `RI_LINKCHECK_USER_AGENT` | 远程链接检查时发送的 User-Agent。 | `random-image-api/1.0` |
| `RI_LINKCHECK_VERIFY_TLS` | 检查 HTTPS 远程链接时是否校验证书。 | `true` |
| `RI_LINKCHECK_BIND_RESOLVED_IP` | 不使用代理时，cURL 检查是否绑定到校验阶段解析出的公网 IP。 | `true` |
| `RI_LINKCHECK_ALLOWED_HOSTS` | 远程图片域名白名单；为空时远程链接禁用，可使用 `*.example.org` 这类通配符。 | 空 |
| `RI_SENDFILE_MODE` | 本地图片输出模式：`php`、`x-sendfile` 或 `x-accel`。 | `php` |
| `RI_X_ACCEL_PREFIX` | 使用 `x-accel` 时需要配置的安全绝对 Nginx 内部 location 前缀。 | 空 |

## 目录示例

```text
images/
  category-a/
    001.jpg            # 新上传图片；索引时会按尺寸移动
    links.txt
    pc/
      002.jpg
    mobile/
      003.jpg
```

- `/` 包含所有配置分类和分类根目录的 TXT 链接。
- `/category-a` 只包含 `category-a` 这个分类。
- `/category-a/pc` 和 `/category-a/mobile` 不是公开路由；需要横图或竖图时使用 `/category-a?type=pc` 或 `/category-a?type=mobile`。
- 其它子目录会被索引忽略，不再作为子分类。

## PC 和 Mobile 图片

索引时，本地图片会按尺寸分类：

- `pc`：宽度大于高度，适合 PC 横屏背景。
- `mobile`：高度大于宽度，适合手机竖屏背景。
- `square`：宽高相同。
- `unknown`：无法读取尺寸，或来自 TXT 远程链接。

新上传的本地图片直接放在分类目录，例如 `images/category-a/001.jpg`。执行索引命令后，横图会移动到 `images/category-a/pc/`，竖图会移动到 `images/category-a/mobile/`。方图或无法识别尺寸的图片会留在分类目录。

随机接口支持显式指定类型：

```text
/category-a?type=pc
/category-a?type=mobile
```

也支持别名：`desktop`、`landscape`、`horizontal` 等同于 `pc`；`phone`、`portrait`、`vertical` 等同于 `mobile`。

不传 `type` 时，浏览器请求会根据 Client Hints 或 User-Agent 自动判断。作为 CSS 背景调用时，浏览器通常也会带 User-Agent，所以多数情况下可以自动适配；如果你想强制背景方向，建议显式使用 `?type=pc` 或 `?type=mobile`。

`links.txt` 里的远程链接默认记为 `unknown`，因为服务不会下载远程图片来读取尺寸。远程链接必须先把域名写入 `RI_LINKCHECK_ALLOWED_HOSTS`，且短链接只有在 `check-links` 记录最近一次成功检查后才会跳转。不带类型过滤时会参与随机；请求 `pc` 或 `mobile` 时会被排除。

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
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php index --folder=category-a
```

检查远程链接：

```powershell
$env:RI_HTTP_PROXY="http://127.0.0.1:10808"
$env:RI_LINKCHECK_VERIFY_TLS="0"
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=curl -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php check-links
```

`RI_HTTP_PROXY` 只在当前网络需要代理时设置。`RI_LINKCHECK_VERIFY_TLS=0` 只建议本地测试使用，生产环境保持 TLS 校验开启。
`RI_LINKCHECK_CONCURRENCY` 默认是 `4`，启用 cURL 扩展时使用 cURL multi 并发检查。
当 `RI_LINKCHECK_BIND_RESOLVED_IP=true` 且未配置代理时，远程检查要求启用 cURL，以便把请求绑定到前一步校验过的公网 IP。
`RI_LINKCHECK_BIND_RESOLVED_IP=true` 会让 cURL 检查绑定到校验阶段解析出的公网 IP，在不走代理时降低 DNS rebinding 风险。索引远程链接前，先把可信图片域名写入 `RI_LINKCHECK_ALLOWED_HOSTS`，然后执行 `check-links`。
`status --json` 和管理状态接口会展示远程链接状态字段，包括 indexed、checked、unchecked、stale 和 serviceable 等数量。

生成强管理 token：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n bin\console.php generate-token
```

## 本地运行

推荐使用 `public/` 作为 Web 根目录：

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

测试或访问完成后停止服务进程。

## Docker

Docker 镜像基于固定 digest 的 `php:8.2-apache`，并使用 Apache 服务 `public/`，不再使用 PHP 内置 server 对外服务。容器使用固定的 Apache 站点配置，禁用 `.htaccess` 覆盖，隐藏版本细节，并关闭 HTTP TRACE。

Docker 镜像由 GitHub Actions 发布到 GitHub Container Registry，并且只在推送匹配 `v*` 的 Git 标签时触发。普通分支 push 不会构建或发布镜像。

创建并推送发布标签：

```powershell
git tag v1.0.0
git push origin v1.0.0
```

等待 workflow 完成后，拉取并运行镜像：

```powershell
docker pull ghcr.io/yzfh-ty/random-image-api:v1.0.0
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime ghcr.io/yzfh-ty/random-image-api:v1.0.0
```

例如推送 `v1.0.0` 这类 semver 标签时，workflow 会发布 `v1.0.0`、`1.0.0` 和 `1.0` 三个镜像标签。

打标签前如需本地冒烟测试，可以本地构建：

```powershell
docker build -t random-image-api:local .
```

容器默认启动时会自动索引。若想手动控制索引，可设置 `RI_AUTO_INDEX_ON_START=false`，再用 `docker run --rm --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime ghcr.io/yzfh-ty/random-image-api:v1.0.0 php bin/console.php index` 单独执行。
容器默认会把 PHP 降权到非 root 的 `app` 用户运行。Linux bind mount 场景下，请确保挂载的 `images` 和 `.runtime` 目录允许 UID `10001` 写入，或在派生镜像中设置合适的 `RI_RUN_USER`。入口脚本默认不会递归修改 bind mount 属主；只有显式设置 `RI_CHOWN_MOUNTS=true` 时才会执行。
Docker 健康检查会使用 `RI_ALLOWED_HOSTS` 的第一个值作为 Host 请求头；如果需要使用其它已允许的主机名，可设置 `RI_HEALTHCHECK_HOST`。
如果希望 GHCR 镜像可以公开拉取，需要在 GitHub Packages 中把该 package 的可见性设为 public。

安装 Trivy 后，可以扫描镜像里的高危和严重 CVE：

```powershell
wsl.exe -e sh docker/scan-image.sh ghcr.io/yzfh-ty/random-image-api:v1.0.0
```

## 管理接口

`/_api` 管理接口默认关闭。需要启用时：

```dotenv
RI_ADMIN_ENABLED=true
RI_ADMIN_TOKEN=paste-generated-token-here
```

请求时使用 Bearer Token：

```powershell
curl.exe -H "Authorization: Bearer <generated-token>" http://127.0.0.1:3000/_api/index
```

默认不接受 `?token=`，避免 token 出现在浏览器历史或访问日志中。确实需要时可设置 `RI_ADMIN_ALLOW_QUERY_TOKEN=true`。

## 安全默认值

- 推荐 Web 根目录指向 `public/`。
- `public/.htaccess` 负责 Apache 路由重写并阻止访问点文件；应用入口只保留在 `public/`。
- 只允许 `GET` 和 `HEAD` 请求。
- Host 头受 `RI_ALLOWED_HOSTS` 限制；生产域名必须显式列出。
- 顶层分类必须配置在 `RI_FOLDERS` 中。
- 路径会拒绝 `../`、反斜杠、空字节和 ASCII 控制字符。
- 短链接不暴露原始文件名。
- SVG 默认禁用，避免脚本型 SVG 风险；如果显式启用，本地 SVG 会按附件输出并附加严格 CSP。
- 本地输出前会再次校验真实路径仍在分类目录内，拒绝符号链接，执行 `RI_MAX_IMAGE_BYTES` 限制，并重新确认非 SVG 文件是可读取图片。
- TXT 远程链接必须配置 `RI_LINKCHECK_ALLOWED_HOSTS`，并会拒绝 `localhost`、内网 IP、保留地址、无法解析的域名、云 metadata 主机和非图片 `Content-Type` 响应；跳转检测也会校验重定向目标。
- 远程短链接默认要求最近一次 `check-links` 成功。

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

Apache 或 Nginx 推荐将站点根目录指向 `public/`。仓库根目录提供了一个 `.htaccess` 作为 Apache 误暴露项目根目录时的兜底保护，但不能替代正确设置 Web 根目录。

Nginx 示例：

```nginx
root /path/to/api-image/public;

location / {
    try_files $uri /index.php$is_args$args;
}
```

默认由 PHP 输出本地图片。高并发或大图场景可以设置 `RI_SENDFILE_MODE=x-sendfile` 或 `RI_SENDFILE_MODE=x-accel`，交给 Apache/Nginx 输出文件。
