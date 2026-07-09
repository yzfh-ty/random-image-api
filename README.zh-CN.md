# random-image-api

[English](README.md) | 简体中文

一个基于 PHP 8.2 + SQLite 的随机图片 API。它会从配置好的分类目录里随机返回本地图片，也可以读取分类目录下 `links.txt` 中的远程图片链接，并生成类似 `/category-a/1.png` 的短链接。

## 功能

- `GET /`：从所有已配置分类中随机。
- `GET /:folder`：从指定分类中随机。
- `GET /:folder/:id.ext`：访问索引生成的短图片链接。
- `GET /?json=1`：返回 JSON，不直接跳转。
- `GET /_health`：返回简洁健康检查 JSON。
- `?type=pc` 和 `?type=mobile`：筛选横图或竖图。

只有写入 `RI_FOLDERS` 的顶层目录可以被访问。

## 运行要求

- PHP 8.2 或更高版本。
- PDO SQLite 扩展。
- `getimagesize()` 支持。
- 如需检查远程链接，建议启用 cURL 扩展。

## 快速开始

复制本地配置模板：

```powershell
Copy-Item .env.example .env
```

至少在 `.env` 中设置：

```dotenv
RI_FOLDERS=category-a,category-b
RI_ALLOWED_HOSTS=localhost,localhost:3000,example.com
```

创建和 `RI_FOLDERS` 对应的图片目录：

```text
images/
  category-a/
    001.jpg
    links.txt
  category-b/
    002.webp
```

构建 SQLite 索引：

```powershell
php bin\console.php index
```

使用 `public/` 作为 Web 根目录本地运行：

```powershell
php -S localhost:3000 -t public public/index.php
```

访问 `http://localhost:3000/` 或 `http://localhost:3000/category-a`。

## 图片目录

新图片直接放进已配置的分类目录。执行索引时，横图会移动到 `pc/`，竖图会移动到 `mobile/`，方图或无法识别尺寸的图片会留在分类根目录。

```text
images/
  category-a/
    pc/
      001.jpg
    mobile/
      002.jpg
    links.txt
```

远程图片链接可以写在分类目录下的 `links.txt` 中，每行一个 URL。远程链接默认关闭，需要先配置 `RI_LINKCHECK_ALLOWED_HOSTS`，并执行 `check-links` 成功后才会参与随机。

## 常用命令

```powershell
php bin\console.php index
php bin\console.php index --folder=category-a
php bin\console.php status
php bin\console.php config --json
php bin\console.php doctor
php bin\console.php check-links
php bin\console.php generate-token
```

新增、删除、移动图片，或修改 `links.txt` 后，需要重新执行 `index`。

## 配置

程序会先读取真实环境变量，再读取 `.env`，最后使用内置默认值。完整变量说明见 `.env.example`，生产环境模板见 `.env.production.example`。

常用配置：

- `RI_FOLDERS`：允许访问的分类，多个分类用英文逗号分隔。
- `RI_IMAGE_ROOT`：本地图片根目录，默认是 `images`。
- `RI_ALLOWED_HOSTS`：允许的请求 Host；生产环境上线前必须设置。
- `RI_TRUST_PROXY`：只有位于可信反向代理后面才开启。
- `RI_DEFAULT_MODE`：`redirect` 或 `json`。
- `RI_LINKCHECK_ALLOWED_HOSTS`：远程图片链接可信域名白名单。
- `RI_HTTP_PROXY`：远程链接检查使用的可选代理。
- `RI_ADMIN_ENABLED` 和 `RI_ADMIN_TOKEN`：可选的只读管理状态接口。

## Docker

Docker 镜像由 GitHub Actions 发布到 GitHub Container Registry，并且只在推送匹配 `v*` 的 Git 标签时触发。普通分支 push 不会发布镜像。

发布 workflow 完成后可拉取并运行：

```powershell
docker pull ghcr.io/OWNER/REPOSITORY:v1.0.0
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime ghcr.io/OWNER/REPOSITORY:v1.0.0
```

本地测试镜像：

```powershell
docker build -t random-image-api:local .
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime random-image-api:local
```

容器使用 Apache 监听 `3000` 端口，默认启动时会自动索引。若想手动索引，可设置 `RI_AUTO_INDEX_ON_START=false`。

## 管理接口

`/_api` 管理接口默认关闭。如需启用，先生成 token，再使用 Bearer Token 请求：

```powershell
php bin\console.php generate-token
```

```dotenv
RI_ADMIN_ENABLED=true
RI_ADMIN_TOKEN=paste-generated-token-here
```

```powershell
curl.exe -H "Authorization: Bearer <generated-token>" http://localhost:3000/_api/index
```

## 部署提示

- Apache 或 Nginx 的 Web 根目录应指向 `public/`。
- 不要把 `.env`、`.runtime/`、`images/` 提交到 Git。
- 生产环境必须把 `RI_ALLOWED_HOSTS` 设置为真实域名。
- 除非反向代理可信，否则保持 `RI_TRUST_PROXY=false`。
- 除非确实需要，否则保持 SVG 禁用。
- 只为可信域名启用远程链接，并执行 `check-links`。
- 管理接口优先使用 Bearer Token；查询参数 token 默认关闭。
