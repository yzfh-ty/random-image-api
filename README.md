# random-image-api

English | [简体中文](README.zh-CN.md)

A small PHP 8.2 + SQLite service for returning random images from configured categories. It supports local image folders and optional remote image URLs listed in `links.txt`, then exposes short URLs such as `/category-a/1.png`.

## What It Provides

- `GET /` randomly selects from all configured categories.
- `GET /:folder` randomly selects from one configured category.
- `GET /:folder/:id.ext` serves an indexed short image URL.
- `GET /?json=1` returns JSON instead of redirecting.
- `GET /_health` returns a compact health check JSON.
- `?type=pc` and `?type=mobile` filter landscape or portrait local images.

Only top-level folders listed in `RI_FOLDERS` can be requested.

## Requirements

- PHP 8.2 or newer.
- PDO SQLite extension.
- `getimagesize()` support.
- cURL extension is recommended for remote link checks.

## Quick Start

Copy the local configuration template:

```powershell
Copy-Item .env.example .env
```

Set at least these values in `.env`:

```dotenv
RI_FOLDERS=category-a,category-b
RI_ALLOWED_HOSTS=localhost,localhost:3000,example.com
```

Create image folders that match `RI_FOLDERS`:

```text
images/
  category-a/
    001.jpg
    links.txt
  category-b/
    002.webp
```

Build the SQLite index:

```powershell
php bin\console.php index
```

Run locally with `public/` as the web root:

```powershell
php -S localhost:3000 -t public public/index.php
```

Open `http://localhost:3000/` or `http://localhost:3000/category-a`.

## Image Folders

Put new local images directly inside a configured category folder. During indexing, landscape images are moved into `pc/`, portrait images are moved into `mobile/`, and square or unreadable images stay in the category root.

```text
images/
  category-a/
    pc/
      001.jpg
    mobile/
      002.jpg
    links.txt
```

Remote image URLs can be placed in a category-level `links.txt`, one URL per line. Remote links are disabled until `RI_LINKCHECK_ALLOWED_HOSTS` is configured and `check-links` succeeds.

## Useful Commands

```powershell
php bin\console.php index
php bin\console.php index --folder=category-a
php bin\console.php status
php bin\console.php config --json
php bin\console.php doctor
php bin\console.php check-links
php bin\console.php generate-token
```

Run `index` after adding, deleting, moving images, or editing `links.txt`.

## Configuration

The app reads real environment variables first, then `.env`, then built-in defaults. `.env.example` documents all supported variables, and `.env.production.example` contains a production-oriented template.

Common settings:

- `RI_FOLDERS`: comma-separated public categories.
- `RI_IMAGE_ROOT`: local image root, default `images`.
- `RI_ALLOWED_HOSTS`: allowed request Host headers; set this before production use.
- `RI_TRUST_PROXY`: enable only behind a trusted reverse proxy.
- `RI_DEFAULT_MODE`: `redirect` or `json`.
- `RI_LINKCHECK_ALLOWED_HOSTS`: trusted hosts for remote image URLs.
- `RI_HTTP_PROXY`: optional proxy for remote link checks.
- `RI_ADMIN_ENABLED` and `RI_ADMIN_TOKEN`: optional read-only admin status API.

## Docker

Images are published to GitHub Container Registry by GitHub Actions only when a Git tag matching `v*` is pushed. Normal branch pushes do not publish an image.

After a release workflow finishes:

```powershell
docker pull ghcr.io/yzfh-ty/random-image-api:latest
docker run --rm -p 3000:3000 --env-file .env -v ${PWD}/images:/app/images -v ${PWD}/.runtime:/app/.runtime ghcr.io/yzfh-ty/random-image-api:latest
```

Or use Docker Compose:

```powershell
docker compose pull
docker compose up -d
```

Edit `docker-compose.yml` before deployment, especially `RI_FOLDERS`, `RI_ALLOWED_HOSTS`, and any remote-link or admin settings. Use a version tag instead of `latest` when you want a pinned deployment.

The container runs Apache on port `3000` and indexes images on startup by default. Set `RI_AUTO_INDEX_ON_START=false` if you prefer to run indexing separately.

## Admin API

Admin endpoints under `/_api` are disabled by default. To enable them, generate a token and use it as a Bearer token:

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

## Deployment Notes

- Point Apache or Nginx to `public/` as the web root.
- Keep `.env`, `.runtime/`, and `images/` out of Git.
- Set `RI_ALLOWED_HOSTS` to your real production domains.
- Keep `RI_TRUST_PROXY=false` unless the reverse proxy is trusted.
- Keep SVG disabled unless you explicitly need it.
- Enable remote links only for trusted hosts and run `check-links`.
- Prefer Bearer tokens for the admin API; query-string tokens are disabled by default.
