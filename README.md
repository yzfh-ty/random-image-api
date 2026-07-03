# random-image-api

English | [简体中文](README.zh-CN.md)

A PHP 8.2 + SQLite random image API. It randomly serves images from configured local category folders and remote image links listed in `links.txt`, then exposes short URLs under the current domain, such as `/erciyuan/1.png`.

## Features

- `GET /`: randomly selects from all configured categories.
- `GET /:folder`: randomly selects from one configured category.
- `GET /:folder/:id.ext`: serves an indexed short image URL. Local images are streamed by the server, while remote TXT links return a 302 redirect to the original URL.
- `GET /?json=1`: returns JSON with a short image URL under the current domain.
- Opening `/` or `/erciyuan` directly in a browser returns an HTML image viewer page. Refreshing the page picks a new image.
- Requests from `<img>` tags or CSS backgrounds receive a 302 redirect to the short image URL.
- Local images are indexed as `pc` for landscape images and `mobile` for portrait images, then moved into managed `pc/` or `mobile/` folders during indexing.
- Requests can filter image type with `?type=pc` or `?type=mobile`; without this parameter, browser requests are auto-detected from Client Hints or User-Agent.
- HTTP requests read from SQLite only. Directory scanning is never done during normal requests.

Only top-level folders listed in `RI_FOLDERS` are accessible. Local folders that exist but are not configured return `404`.

## Source And Runtime Data

The repository only tracks source code, configuration templates, and documentation. Runtime data and local configuration are intentionally ignored:

- `.env`: local runtime configuration. Copy it from `.env.example`.
- `.runtime/`: SQLite database, index lock, index logs, and local-only test scratch files.
- `images/`: local image storage.

After deployment, copy `.env.example` to `.env`, create local image folders on the server, for example `images/erciyuan`, then run the index command.

## Configuration

The project is configured with real environment variables or a local `.env` file. Copy the example before running the app:

```powershell
Copy-Item .env.example .env
```

Configuration priority is: system environment variables, then `.env`, then built-in defaults.

The only required variable is `RI_FOLDERS`:

```dotenv
RI_FOLDERS=erciyuan,fengjing
RI_ALLOWED_HOSTS=example.com,www.example.com
RI_TRUST_PROXY=false
RI_ADMIN_ENABLED=false
RI_ADMIN_TOKEN=
RI_DEFAULT_MODE=redirect
RI_HTTP_PROXY=
```

Supported variables are listed in `.env.example`. By default, only `localhost`, `127.0.0.1`, and `[::1]` are accepted as request hosts; set `RI_ALLOWED_HOSTS` to your production domain names before deploying. Set `RI_TRUST_PROXY=true` only when the app is behind a trusted reverse proxy.

### Configuration Reference

| Variable | Meaning | Default / Example |
| --- | --- | --- |
| `RI_FOLDERS` | Comma-separated top-level image categories that can be requested. | `erciyuan,fengjing` |
| `RI_IMAGE_ROOT` | Local image root directory. Relative paths are resolved from the project root. | `images` |
| `RI_LINK_FILES` | TXT file names read from each category for remote image links. | `links.txt` |
| `RI_DEFAULT_MODE` | Default random response mode: `redirect` or `json`. | `redirect` |
| `RI_SERVER_HOST` | Bind host used by Docker/local PHP server startup. | `0.0.0.0` |
| `RI_SERVER_PORT` | Port used by Docker/local PHP server startup. | `3000` |
| `RI_ALLOWED_HOSTS` | Allowed request Host headers. Set production domains here. | `example.com,www.example.com` |
| `RI_TRUST_PROXY` | Trust `X-Forwarded-Proto` and `X-Forwarded-Host` from a reverse proxy. | `false` |
| `RI_ADMIN_ENABLED` | Enable read-only admin status endpoints. | `false` |
| `RI_ADMIN_PREFIX` | Admin API route prefix. This prefix is reserved. | `/_api` |
| `RI_ADMIN_TOKEN` | Bearer token required when admin API is enabled. | empty |
| `RI_ADMIN_ALLOW_QUERY_TOKEN` | Allow admin token in `?token=`. Prefer Bearer tokens. | `false` |
| `RI_INDEX_DATABASE` | SQLite index database path. | `.runtime/image-index.sqlite` |
| `RI_INDEX_LOCK` | File lock path used to prevent concurrent index rebuilds. | `.runtime/index.lock` |
| `RI_INDEX_LOG` | JSONL index log path. | `.runtime/index.log` |
| `RI_INDEX_LOG_MAX_BYTES` | Rotate the index log after this size; `0` disables size rotation. | `1048576` |
| `RI_INDEX_LOG_BACKUPS` | Number of rotated index log backups to keep. | `3` |
| `RI_IMAGE_EXTENSIONS` | Allowed local image extensions. SVG is ignored unless explicitly enabled. | `.jpg,.jpeg,.png,.gif,.webp,.avif,.bmp` |
| `RI_ALLOW_SVG` | Allow SVG indexing/output. Keep disabled unless needed. | `false` |
| `RI_HTTP_PROXY` | Optional HTTP proxy used only for remote link checks. | `http://127.0.0.1:10808` |
| `RI_LINKCHECK_TIMEOUT` | Timeout in seconds for each remote link check. | `5` |
| `RI_LINKCHECK_CONCURRENCY` | Maximum concurrent remote link checks when cURL is available. | `4` |
| `RI_LINKCHECK_USER_AGENT` | User-Agent sent during remote link checks. | `random-image-api/1.0` |
| `RI_LINKCHECK_VERIFY_TLS` | Verify TLS certificates for HTTPS remote links. | `true` |
| `RI_LINKCHECK_BIND_RESOLVED_IP` | Bind cURL checks to the public IP resolved during validation when no proxy is used. | `true` |
| `RI_LINKCHECK_ALLOWED_HOSTS` | Optional allowlist for remote image hosts. Wildcards like `*.example.org` are supported. | empty |
| `RI_SENDFILE_MODE` | Local image output mode: `php`, `x-sendfile`, or `x-accel`. | `php` |
| `RI_X_ACCEL_PREFIX` | Internal Nginx location prefix required for `x-accel`. | empty |

## Directory Example

```text
images/
  erciyuan/
    001.jpg            # newly uploaded image; indexing will move it if it is pc/mobile
    links.txt
    pc/
      002.jpg
    mobile/
      003.jpg
```

- `/` includes all configured categories and root-level TXT links.
- `/erciyuan` includes only the `erciyuan` category.
- `/erciyuan/pc` and `/erciyuan/mobile` are not public routes; use `/erciyuan?type=pc` or `/erciyuan?type=mobile`.
- Other subdirectories are ignored by indexing and are not treated as subcategories.

## PC And Mobile Images

During indexing, local images are classified by dimensions:

- `pc`: width is greater than height.
- `mobile`: height is greater than width.
- `square`: width equals height.
- `unknown`: dimensions cannot be detected, or the image comes from a TXT remote link.

Put newly uploaded local images directly in the category folder, for example `images/erciyuan/001.jpg`. The index command moves landscape images into `images/erciyuan/pc/` and portrait images into `images/erciyuan/mobile/`. Square or unreadable images stay in the category folder.

Random endpoints support explicit type filtering:

```text
/erciyuan?type=pc
/erciyuan?type=mobile
```

Aliases are also accepted: `desktop`, `landscape`, `horizontal` map to `pc`; `phone`, `portrait`, `vertical` map to `mobile`.

When `type` is not provided, browser requests are auto-detected from Client Hints or User-Agent. This usually works for CSS background calls too, because browsers still send a User-Agent. Use `?type=pc` or `?type=mobile` when you need an exact background orientation regardless of the device detection result.

Remote links from `links.txt` are indexed as `unknown`, because the service does not download remote images to inspect their dimensions. They are included when no type filter is active, and excluded when requesting `pc` or `mobile`.

## Indexing

HTTP requests never scan directories. Rebuild the index after adding, deleting, moving images, or editing the category-level `links.txt`.

The index command also organizes local files: it creates `pc/` and `mobile/` inside each configured category when needed, then moves detected landscape and portrait images into those folders. Files that cannot be classified are left where they are.

Index logs are written to `RI_INDEX_LOG` and rotated by size. The defaults keep the active log under `1048576` bytes and retain `3` backups; adjust `RI_INDEX_LOG_MAX_BYTES` and `RI_INDEX_LOG_BACKUPS` in `.env` when needed.

Local PHP path used during development:

```text
D:\phpstudy_pro\Extensions\php\php8.2.9nts
```

The default `php.ini` on this machine contains the removed PHP 8.2 option `track_errors`, so CLI commands should use `-n` and enable SQLite extensions explicitly:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php index
```

Show index status:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php status
```

Show the effective configuration without exposing secrets:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php config --json
```

Check the local runtime, required PHP extensions, paths, and configured folders:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php doctor
```

Rebuild a single category:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php index --folder=erciyuan
```

Check remote links:

```powershell
$env:RI_HTTP_PROXY="http://127.0.0.1:10808"
$env:RI_LINKCHECK_VERIFY_TLS="0"
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=curl -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php check-links
```

Set `RI_HTTP_PROXY` only when the current network needs a proxy. `RI_LINKCHECK_VERIFY_TLS=0` is only recommended for local testing; keep TLS verification enabled in production.
`RI_LINKCHECK_CONCURRENCY` defaults to `4` and uses cURL multi when the cURL extension is enabled; otherwise link checks fall back to sequential stream requests.
`RI_LINKCHECK_BIND_RESOLVED_IP=true` makes cURL checks bind requests to the public IP resolved during validation, reducing DNS rebinding risk when no proxy is used. For production, set `RI_LINKCHECK_ALLOWED_HOSTS` to the image domains you trust.

## Local Run

Use `public/` as the web root:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

Stop the local server process after testing.

## Docker

Build the image:

```powershell
docker build -t random-image-api .
```

Run it with local images and runtime data mounted from the project directory:

```powershell
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime random-image-api
```

The container indexes images on startup by default. Set `RI_AUTO_INDEX_ON_START=false` when you want to run indexing separately with `docker run --rm --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime random-image-api php bin/console.php index`.
The container drops PHP to the non-root `app` user by default. On Linux bind mounts, make sure the mounted `images` and `.runtime` directories are writable by UID `10001`, or set `RI_RUN_USER` to a suitable user inside a derived image.

## Admin API

The `/_api` admin endpoints are disabled by default. To enable them:

```dotenv
RI_ADMIN_ENABLED=true
RI_ADMIN_TOKEN=replace-with-a-long-random-token
```

Use a Bearer token:

```powershell
curl.exe -H "Authorization: Bearer replace-with-a-long-random-token" http://127.0.0.1:3000/_api/index
```

`?token=` is not accepted by default, so tokens do not appear in browser history or access logs. Set `RI_ADMIN_ALLOW_QUERY_TOKEN=true` only if you explicitly need query tokens.

## Security Defaults

- Use `public/` as the web root.
- `public/.htaccess` handles Apache rewrites and blocks dotfiles. The application entrypoint lives in `public/`.
- Only `GET` and `HEAD` are allowed.
- Host headers are restricted by `RI_ALLOWED_HOSTS`; production domains must be listed explicitly.
- Top-level categories must be present in `RI_FOLDERS`.
- Paths reject `../`, backslashes, null bytes, and ASCII control characters.
- Short URLs do not expose original file names.
- SVG is disabled by default to avoid scriptable SVG risks; if explicitly enabled, local SVG files are sent as attachments with a restrictive CSP.
- Local image output verifies the resolved real path remains inside the category directory and rejects symlinks.
- TXT remote links reject localhost, private IPs, reserved addresses, and cloud metadata hosts. Redirect targets are checked too.
- `RI_LINKCHECK_ALLOWED_HOSTS` can further restrict allowed remote image domains.

## Scheduled Indexing

Windows Task Scheduler example, hourly:

```powershell
schtasks /Create /SC HOURLY /TN "RandomPicApiIndex" /TR "\"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe\" -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 \"D:\project\api-image\bin\console.php\" index" /F
```

Linux cron example:

```cron
0 * * * * cd /path/to/api-image && php bin/console.php index >/tmp/random-pic-index.log 2>&1
```

The index command uses a file lock, so only one indexing task can run at a time. SQLite WAL mode is enabled for more stable concurrent reads and index writes.

## Deployment

Apache or Nginx should point the site root to `public/`.

Nginx example:

```nginx
root /path/to/api-image/public;

location / {
    try_files $uri /index.php$is_args$args;
}
```

By default, PHP streams local images. For high concurrency or large files, set `RI_SENDFILE_MODE=x-sendfile` or `RI_SENDFILE_MODE=x-accel` and let Apache or Nginx serve image files.
