# Architecture

YT Archiver is a self-hosted YouTube downloader: an app container with a static frontend, a small PHP JSON API,
and a background PHP worker that drives `yt-dlp`, deployed by `docker-compose.yml` together with a PostgreSQL container
that holds only the accounts. There is no framework, no build step, and no Composer or npm dependencies.

## Deployment (docker-compose.yml)

| Service | Image | Role |
|---|---|---|
| `yt-archiver` | `ghcr.io/irelevant25/yt-archiver` | the app (below); `depends_on: postgres` (`service_healthy`) |
| `postgres` | `postgres:17-alpine` | accounts database; `pg_isready` healthcheck, data in `/opt/yt-archiver/postgres`, **no published port** |

The app gets the connection as `YTA_DB_HOST=postgres`, `YTA_DB_PORT`, `YTA_DB_NAME`, `YTA_DB_USER`, `YTA_DB_PASSWORD`. The password is
`${YTA_DB_PASSWORD}` from `.env` (see `.env.example`) and is shared with `POSTGRES_PASSWORD`. A server entered by hand in setup still works
and takes precedence (see authentication.md, "Which database").

## Container (Dockerfile, supervisord.conf, entrypoint.sh)

| Process | Role |
|---|---|
| `supervisord` (PID 1, root) | Runs nginx and php-fpm, reaps orphaned worker processes |
| `nginx` | Serves `public/` static files, proxies `*.php` to php-fpm, serves media from `/data/videos` |
| `php-fpm` (www-data) | Runs `api.php` |
| `php download_worker.php <job-id>` (www-data) | Background process started by the API or by the previous worker, one job at a time |
| `yt-dlp` + `ffmpeg` | Children of the worker, in the worker's process group |

- Base image: `php:8.3-fpm-alpine`. The code targets **PHP 8.3** (`match`, `str_starts_with`, `mixed`, etc.).
- Extensions used: `mbstring`, `posix`, `curl`, `json` (bundled) and `zip` (installed in the Dockerfile for `ZipArchive`).
- `entrypoint.sh` fixes `/data` ownership, creates `database.json` and `queue.json`, applies migrations once installed (retried while the database starts), then execs supervisord.
- `www-data` may run exactly `sudo -n /usr/bin/pip3 install --upgrade yt-dlp --break-system-packages`
  (the sudoers rule and `updateYtDlp()` in `api.php` must stay identical).
- CI: `.github/workflows/docker-publish.yml` builds amd64 and arm64 images and pushes them to `ghcr.io/irelevant25/yt-archiver` on pushes to `main` and `v*` tags.

## Source layout

```
public/                     → copied to /var/www/html
  index.html, js/app.js     main UI (download form, queue, library); polls the API every 2 s
  logs.html, js/logs.js     request-log viewer (administrators only)
  css/styles.css, logs.css  dark theme, CSS variables in :root
  api.php                   JSON API router (?action=...); approved users only, some actions admin only
  login.php, setup.php      Google sign-in / one-time installation (the only pages reachable without an account)
  users.html, js/users.js   account management (admin)
  includes/auth.php         config, PostgreSQL, sessions, access gate, Google ID token verification (see authentication.md)
  includes/auth_check.php   nginx auth_request endpoint
  includes/migrations/      SQL migrations for the accounts database
  download_worker.php       CLI-only background worker (nginx denies web access)
  includes/common.php       shared constants, storage, locking, queue and worker helpers (nginx denies)
  debug.php                 prints request IP headers (debug leftover, any approved user)
nginx.conf                  site config (/download/ and /videos/ alias /data/videos)
dev/serve.php               local dev without Docker: built-in server + job dispatcher + preflight checks
dev/tools.php               downloads/updates yt-dlp and ffmpeg into .dev-tools/bin (checksum-verified)
dev/router.php              built-in server router mirroring nginx.conf (keep both in sync!)
tests/                      unit.php, integration.php (PHP ≥ 8), frontend.test.js (node), fixtures/fake-yt-dlp.php
docs/knowledge-base/        this documentation
```

## Local development mode (no Docker)

`php8 dev/serve.php [--port --data --host --verbose --update-tools --no-tools]` works on Windows, Linux and macOS:

| Docker | Local dev |
|---|---|
| nginx | PHP built-in server + `dev/router.php` (same deny rules, `/download/`, `/videos/`, SPA fallback) |
| php-fpm `startNextJob()` spawns workers | `canSpawnWorkers()` is false under `cli-server` (a worker would inherit the client socket and hold the HTTP response open); the **dispatcher loop in serve.php** runs `recoverStaleJob()` and `startNextJob()` every 0.5 s. Workers still start their successors themselves. |
| `/data` | `.dev-data/` (gitignored) or `--data`, passed on as `YTA_DATA_DIR` |
| yt-dlp/ffmpeg from apk/pip | `dev/tools.php` manages them in `.dev-tools/bin` (see below), prepends that directory to `PATH` and sets `YTA_YTDLP_BIN` |
| sudo pip update | `update` runs `yt-dlp -U` when `YTA_DEV=1` (works for the standalone binary) |
| zip ext installed in the image | serve.php enables missing `zip`/`curl` for its children through `PHP_INI_SCAN_DIR` (`<data>/php-conf.d`) if the extension file exists |
| worker output discarded | `workerLog()` writes `<data>/worker.log` (job start, yt-dlp exit code + last output lines on failure, archive file list) |

Shutdown (Ctrl+C or `<data>/.dev-stop`) stops the server and puts the running job back at the queue front without counting it as an attempt.

### Tool provisioning (`dev/tools.php`, skipped with `--no-tools`)

- Assets only for Windows x64 (`yt-dlp.exe`, `ffmpeg-master-latest-win64-gpl.zip`) and Linux x86_64 (`yt-dlp` Python zipapp, `ffmpeg-master-latest-linux64-gpl.tar.xz`).
  Other platforms get warnings and must use `PATH`.
- **yt-dlp:** the latest tag comes from the `Location` redirect of `github.com/yt-dlp/yt-dlp/releases/latest` (`latestYtDlpVersion()` in common.php, also used by
  the API's `version` action: no API rate limit, no curl). It is compared with `yt-dlp --version` using `version_compare`; the check runs every 12 h
  (`.dev-tools/state.json` stores `checked_at`), and immediately with `--update-tools` or when yt-dlp is missing. If `YTA_YTDLP_BIN` is set, yt-dlp is not managed.
- **ffmpeg/ffprobe:** a system ffmpeg on `PATH` is used if there is no managed copy. Otherwise the archive is downloaded once and extracted (Windows: `System32\tar.exe`, then
  PowerShell `Expand-Archive`; Linux: `tar -xJf`), and only `bin/ffmpeg` and `bin/ffprobe` are kept. `--update-tools` compares the published checksum with the one in `state.json`.
- **Integrity:** downloads go to `*.download`, are verified against `SHA2-256SUMS` (yt-dlp) or `checksums.sha256` (BtbN), then renamed into place.
  Nothing unverified is installed.
- **Known gap:** yt-dlp warns that no JavaScript runtime (deno) is installed. YouTube extraction without one is deprecated upstream, and some formats may be missing.
  This affects the Docker image too.

### Windows specifics worth knowing

- **Paths over 260 characters:** PHP and yt-dlp handle them, but libzip does not. `ZipArchive::addFile()` returns false, and ignoring that silently dropped the entry.
  `runArchive` falls back to a short hard link (`.<n>.<ext>` in the playlist dir) and verifies the entry count after `close()`.

### Environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `YTA_DATA_DIR` | common.php | data directory (default `/data`) |
| `YTA_YTDLP_BIN` | common.php `ytDlpCommand()` | yt-dlp command; may contain quoted arguments (e.g. the fake yt-dlp) |
| `YTA_PHP_BINARY` | common.php `phpBinary()` | PHP CLI used for workers (default: `PHP_BINARY` in CLI, `php` under fpm) |
| `YTA_DEV` | api.php | `1` in local dev |
| `TRUSTED_PROXIES` | common.php | proxies whose client-IP / `X-Forwarded-Proto` headers are trusted (besides private IPs) |
| `MIN_FREE_SPACE_PERCENT` (10), `LARGE_DOWNLOAD_WARNING_GB` (1) | common.php | disk-space block and large-download confirmation thresholds |
| `YTA_WORKER_LOG` | common.php | `1` enables `worker.log` outside local dev |
| `YTA_DB_HOST`, `YTA_DB_PORT`, `YTA_DB_NAME`, `YTA_DB_USER`, `YTA_DB_PASSWORD`, `YTA_DB_PASSWORD_FILE` | auth.php `envDatabaseSettings()` | accounts database (the compose `postgres` service); used when setup saved no server of its own |

### Cross-platform process handling (common.php)

| | Linux (container) | macOS | Windows |
|---|---|---|---|
| spawn | `sh … & echo $!` (double fork) | same | `proc_open` with `bypass_shell` (the handle is not waited for) |
| alive | `/proc/<pid>/cmdline` contains `download_worker.php` | `ps -p … -o command=` | `tasklist` image name `php*` |
| kill | SIGTERM/SIGKILL to the process group (worker calls `posix_setsid`) | same | `taskkill /PID … /T /F` |

On Windows, `writeJson` retries `rename` and `readJson` retries reads, because replacing a file that another process has open fails briefly.

## Data (`/data` volume)

| Path | Content | Written by |
|---|---|---|
| `config.php` | settings from setup.php: Google OAuth client, base URL, secret, `installed`, and PostgreSQL only if entered by hand (0600) | setup.php, login.php (install finish) |
| `sessions/` | PHP session files (0700) | PHP |
| `inspections/` | cached size checks (15 min) | API |
| `database.json` | `{"videos": [...]}`: library entries | worker (add), API (delete) |
| `queue.json` | `{"queue": [...], "current": job\|null, "pid": int\|null}` | API and worker, always under the lock |
| `progress.json` | `{"percent", "status", "title", "id"}` for the current job | worker |
| `queue.lock` | `flock` target serializing queue and database changes | |
| `logs.csv` | request log (`timestamp, action, method, ip, body`) | API |
| `videos/` | finished library files `<id>_<title>.<ext>` | worker |
| `videos/.staging/` | in-progress work (never served: nginx denies dot paths) | worker |

All JSON writes are atomic (`writeJson`: write a temp file, then `rename`), so lock-free readers never see partial files.

### Library entry

```json
{ "id": "vid_…", "title": "…", "filename": "vid_…_Title.mp3", "type": "audio|video", "format": "mp3|mp4",
  "size": 123, "created_at": "ISO-8601" }
```
Playlist archives add `"type": "playlist", "format": "zip", "content_format": "mp3|mp4", "items": 12, "total": 13`.
The entry `id` of an archive is the playlist job id (`pl_…`).

## Accounts (PostgreSQL)

Only the `users` table (and `migrations`) lives in PostgreSQL; the queue and library stay in JSON files.
By default it is the `postgres` service deployed with the app (`YTA_DB_*`); `databaseSettings()` in auth.php decides which server is used.
Access rules, the Google flow and setup are described in [authentication.md](authentication.md).

## API (`public/api.php`)

All actions need an approved account; `update`, `logs`, `users`, `user` need an administrator. Account actions: `me`, `users`, `user` (see authentication.md).

| Action | Method | Notes |
|---|---|---|
| `version` | GET | local vs latest yt-dlp (GitHub API) |
| `update` | POST | pip upgrade via sudo |
| `inspect` | POST | `{url, format, playlist}` → `checkDownload()`: `inspection` (title, duration, items, estimated_size, live), `storage`, `blocked`, `confirmation_required`, `reasons`, `projected_free`. Runs yt-dlp synchronously; results are cached for 15 min in `DATA_DIR/inspections/` |
| `download` | POST | `{url, format, playlist, confirmed}`: validates, canonicalizes, inspects (cache), **re-evaluates under the lock**, then queues a `video`/`playlist` job with `title`/`playlist_title`, `duration`, `estimated_size`. Errors: 409 confirmation required, 507 disk space, 422 source unavailable (`ApiError`) |
| `cancel` | POST | `{id}` or `{playlist_id}` |
| `status` | GET | queue + progress + `storage` (`storageInfo()`); **also heals the queue** (recover dead worker, start next job) |
| `process` | GET/POST | recover + start next job |
| `videos` | GET / DELETE `&id=` | list / delete library entry and file |
| `serve` | GET `&file=` | PHP streaming fallback (the UI uses nginx `/download/` instead) |
| `file_serve` | POST | logging only, called before the nginx download |
| `logs` | GET / DELETE | request log (admin only; paging, filters, search incl. user) / clear it |

Every POST must carry `Content-Type: application/json` (CSRF defence; see security.md).
Requests are logged to `logs.csv` except `version`, `status`, `logs` and `GET videos`.

## Size check and disk space

All rules live in `common.php`, so the API, the worker and the tests share them:

| Function | Purpose |
|---|---|
| `estimateVideoBytes($info, $format)` | mp4: sum of `requested_formats[].filesize ?? filesize_approx`; otherwise, and always for mp3, `duration × ESTIMATED_BYTES_PER_SECOND` (mp3 30 625 B/s ≈ 245 kbps, mp4 400 000 B/s ≈ 3.2 Mbps) |
| `estimateBytesForDuration()` | playlists: entry durations × rate; entries without a duration count as the average entry |
| `jobReservedBytes($job)` | queued space claim: `estimated_size`, **×2 for `playlist` jobs** (items + stored ZIP copy); expanded playlists carry per-item estimates + the archive estimate |
| `storageInfo()` | `total`/`free` of `VIDEOS_DIR`, `library` (sum of DB sizes), `reserved` (current + queued jobs), `min_free`, thresholds. `total = 0` means unknown and never blocks |
| `evaluateDownload($inspection, $storage)` | **blocked** when `free < min_free`, or `free − reserved − peak < min_free`. **Confirmation** when estimate ≥ `LARGE_DOWNLOAD_BYTES`, when `projected_free < 2 × min_free`, or for a live stream |

Enforcement points:
1. UI: `inspect` before `download`; a confirmation dialog; the Download button is disabled while `free − reserved < min_free`.
2. API `download`: re-runs `evaluateDownload` under the queue lock (parallel requests see each other's reservations). A confirmation cannot override a block.
3. Worker: every job except `archive` fails when `free < min_free` at start. `archive` fails when `free − items size < min_free`.

## Frontend (`public/js/app.js`)

- Vanilla JS; the whole queue and library are re-rendered with `innerHTML` every 2 s.
- **All interpolated values go through `escapeHtml()`** (escapes `& < > " '`). Buttons use `data-*`
  attributes plus delegated listeners, never inline `onclick` with data.
- The queue groups consecutive jobs sharing a `playlist_id` into one card (`groupQueueItems`, `renderPlaylistGroup`).
- The playlist checkbox appears when the URL has `list=`; it is forced on for `/playlist` URLs.
