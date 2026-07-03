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

Only top-level folders listed in the local `config.json` under `folders` are accessible. Local folders that exist but are not configured return `404`.

## Source And Runtime Data

The repository only tracks source code, configuration templates, and documentation. Runtime data and local configuration are intentionally ignored:

- `config.json`: local runtime configuration. Copy it from `config.example.json`.
- `.runtime/`: SQLite database, index lock, index logs, and local-only test scratch files.
- `images/`: local image storage.

After deployment, copy `config.example.json` to `config.json`, create local image folders on the server, for example `images/erciyuan`, then run the index command.

## Configuration

The repository provides `config.example.json`. Copy it before running the app:

```powershell
Copy-Item config.example.json config.json
```

The default example reads from `images/erciyuan`:

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

For production, set `server.allowedHosts`, for example:

```json
{
  "server": {
    "allowedHosts": ["example.com", "www.example.com"]
  }
}
```

Set `server.trustProxy` to `true` only when the app is behind a trusted reverse proxy.

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

## Local Run

Use `public/` as the web root:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:3000 -t public public/index.php
```

Stop the local server process after testing.

## Admin API

The `/_api` admin endpoints are disabled by default. To enable them:

```json
{
  "adminEnabled": true,
  "adminToken": "replace-with-a-long-random-token"
}
```

Use a Bearer token:

```powershell
curl.exe -H "Authorization: Bearer replace-with-a-long-random-token" http://127.0.0.1:3000/_api/index
```

`?token=` is not accepted by default, so tokens do not appear in browser history or access logs. Set `adminAllowQueryToken` to `true` only if you explicitly need query tokens.

## Security Defaults

- Use `public/` as the web root.
- `public/.htaccess` handles Apache rewrites and blocks dotfiles. The application entrypoint lives in `public/`.
- Only `GET` and `HEAD` are allowed.
- Top-level categories must be present in the `folders` allowlist.
- Paths reject `../`, backslashes, and null bytes.
- Short URLs do not expose original file names.
- SVG is disabled by default to avoid scriptable SVG risks.
- Local image output verifies the resolved real path remains inside the category directory and rejects symlinks.
- TXT remote links reject localhost, private IPs, reserved addresses, and cloud metadata hosts. Redirect targets are checked too.
- `linkCheck.allowedHosts` can further restrict allowed remote image domains.

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

By default, PHP streams local images. For high concurrency or large files, set `sendfile.mode` to `x-sendfile` or `x-accel` in your local `config.json` and let Apache or Nginx serve image files.
