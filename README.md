# random-image-api

English | [简体中文](README.zh-CN.md)

A PHP 8.2 + SQLite random image API. It randomly serves images from configured local category folders and remote image links listed in `links.txt`, then exposes short URLs under the current domain, such as `/erciyuan/1.png`.

## Features

- `GET /`: randomly selects from all configured categories and their subdirectories.
- `GET /:folder`: randomly selects from a configured category and its subdirectories.
- `GET /:folder/:subPath`: randomly selects from a specific subcategory path.
- `GET /:folder/:id.ext`: serves an indexed short image URL. Local images are streamed by the server, while remote TXT links return a 302 redirect to the original URL.
- `GET /?json=1`: returns JSON with a short image URL under the current domain.
- Opening `/` or `/erciyuan` directly in a browser returns an HTML image viewer page. Refreshing the page picks a new image.
- Requests from `<img>` tags or CSS backgrounds receive a 302 redirect to the short image URL.
- HTTP requests read from SQLite only. Directory scanning is never done during normal requests.

Only top-level folders listed in the local `config.json` under `folders` are accessible. Local folders that exist but are not configured return `404`.

## Source And Runtime Data

The repository only tracks source code, configuration templates, and documentation. Runtime data and local configuration are intentionally ignored:

- `config.json`: local runtime configuration. Copy it from `config.example.json`.
- `.runtime/`: SQLite database, index lock, and index logs.
- `images/`: local image storage.
- `test-image/`: temporary test images.
- `tests/`: local-only test scripts.

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
    001.jpg
    links.txt
    wallpaper/
      002.jpg
      links.txt
```

- `/` includes all configured categories, subdirectories, and TXT links.
- `/erciyuan` includes `erciyuan` and all of its subdirectories.
- `/erciyuan/wallpaper` only selects from that subdirectory path and its children.

## Indexing

HTTP requests never scan directories. Rebuild the index after adding, deleting, moving images, or editing `links.txt`.

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

List indexed paths:

```powershell
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe -n -d extension_dir=D:\phpstudy_pro\Extensions\php\php8.2.9nts\ext -d extension=pdo_sqlite -d extension=sqlite3 bin\console.php paths
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
