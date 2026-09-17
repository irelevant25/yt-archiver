# YT Archiver: instructions for Claude

Self-hosted YouTube downloader in one Docker container: nginx + php-fpm (PHP 8.3, Alpine) + yt-dlp/ffmpeg.
Vanilla JS frontend, JSON-file storage in `/data`, a background PHP worker, and a single-job FIFO queue.
Playlists are expanded into per-item jobs and end up in the library as one ZIP.
Access requires Google sign-in and an administrator's approval; accounts are stored in PostgreSQL, which `docker-compose.yml` deploys
alongside the app (connection via `YTA_DB_*`; a server entered by hand in `setup.php` still works and wins). Google is configured once through `setup.php`.

## Knowledge base: read before changing code

| Before you touch… | Read |
|---|---|
| anything (first time in a session) | @docs/knowledge-base/architecture.md |
| queue, worker, playlists, cancel, `includes/common.php` | [docs/knowledge-base/queue-and-jobs.md](docs/knowledge-base/queue-and-jobs.md) |
| sign-in, setup.php, login.php, accounts, sessions, nginx `auth_request`, any new page/endpoint | [docs/knowledge-base/authentication.md](docs/knowledge-base/authentication.md) |
| API input, file paths, shell/yt-dlp calls, rendering, nginx, Dockerfile | [docs/knowledge-base/security.md](docs/knowledge-base/security.md) |
| verification | [docs/knowledge-base/testing.md](docs/knowledge-base/testing.md) |

When a change alters behaviour described there, **update the knowledge base in the same change**
(and README.md for user-facing features or API changes).

## Project skills and agents

- Skills in `.claude/skills/`: `verify` (run all checks), `add-api-action`, `add-job-type`.
- Agents in `.claude/agents/`: `security-reviewer` (run on any change touching input handling, paths, processes or rendering),
  `queue-reviewer` (run on changes to the queue, worker or locking).

## Hard rules

- **Git:** commits are authored as `irelevant25 <frantisekpastorek@gmail.com>` (set in the repo-local git config; check with
  `git config user.email` before committing). **Never commit or push until the user has reviewed the changes and asked for it.**
- Target **PHP 8.3** in the container, and keep the code working on **PHP ≥ 8.0 on Windows, Linux and macOS** for local dev.
  **Pick the PHP binary by checking both `php8` and `php`** (either may be missing, and either may be PHP 7.4, which cannot parse this code;
  it depends on the shell). Use the first one that exists and reports PHP ≥ 8.0, and don't assume one of them without checking:
  `for p in php8 php; do command -v $p >/dev/null && $p -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' && { PHP=$p; break; }; done`.
  The integration test also needs `pdo_pgsql` loaded in that binary (`$PHP -m`).
- Platform-specific code lives only in `includes/common.php` (`IS_WINDOWS`, `NULL_DEVICE`, spawn/alive/kill helpers). Never hardcode `/dev/null`,
  `/proc` or `yt-dlp` elsewhere; use `NULL_DEVICE`, `isWorkerAlive()` and `ytDlpCommand()`.
- `dev/router.php` mirrors `nginx.conf`: change both together.
- To debug local jobs, read `<data>/worker.log` (e.g. `.dev-data/worker.log`). Use `.dev-tools/bin/yt-dlp.exe` and `ffprobe.exe` for manual checks on Windows.
- Never install unverified binaries: any new download in `dev/tools.php` must be checked against a published checksum.
- No new dependencies (Composer, npm, frameworks) without asking. Keep the no-build-step setup.
- yt-dlp/zip/other processes: `proc_open` with an argument array, never string commands built from input. Put `--` before URLs.
- Every `queue.json`/`database.json` read-modify-write goes inside `withLock()`; write with `writeJson()` (atomic).
- Frontend: every interpolated value goes through `escapeHtml()`; use `data-*` attributes plus delegated listeners, never inline `onclick` with data.
- Disk-space and size rules live only in `evaluateDownload()`/`storageInfo()` (common.php). Anything that starts writing to the library must respect them
  (API under the lock + worker check). A user confirmation must never bypass the free-space block.
- New POST actions call `requireJsonRequest()`. New internal PHP goes to `public/includes/` (nginx denies it).
- **Auth gate:** nothing except `/login.php` and `/setup.php` may be reachable without an approved account. A new PHP entry point must check
  `currentUser()`/`isApproved()` itself (it is also behind nginx `auth_request` and `dev/router.php`). Admin-only pages go into `ADMIN_PATHS`,
  admin-only API actions call `requireAdmin()`. Never weaken `verifyGoogleIdToken()` (RS256 signature against Google's certificates), `validateIdTokenClaims()`, the single-use nonce, or the setup 404 after installation. Google sign-in needs only the client ID: never add a client secret.
- DB schema changes: add a new file in `public/includes/migrations/` and never edit an applied one. Never write DB credentials or secrets anywhere
  except `DATA_DIR/config.php` (a server entered by hand) or the deployment environment (`YTA_DB_*`, `.env`; never commit `.env`).
  Resolve the database only through `databaseSettings()`.
- Never use the maintainer's own PostgreSQL service (port 5433) or its credentials: tests create a throwaway cluster themselves.
- Match the existing style: 4-space indentation, PHP helper functions (no classes except small exceptions), sparse comments explaining *why*.
- The working tree uses CRLF on Windows (`core.autocrlf=true`) while the repo stores LF; `*.sh` is forced to LF.

## Commands

`$PHP` is `php8` or `php`, whichever is PHP ≥ 8 (see Hard rules).

```bash
$PHP dev/serve.php                                         # run locally without Docker → http://127.0.0.1:8080 (auto-provisions yt-dlp + ffmpeg in .dev-tools/)
$PHP dev/serve.php --update-tools                          # force the yt-dlp/ffmpeg update check
YTA_YTDLP_BIN="$PHP tests/fixtures/fake-yt-dlp.php" $PHP dev/serve.php --no-tools   # run offline with the fake yt-dlp
$PHP tests/unit.php && node tests/frontend.test.js         # unit tests
$PHP tests/integration.php                                 # end-to-end over HTTP: setup, fake Google sign-in, accounts, downloads (~45 s, throwaway PostgreSQL)
$PHP public/setup.php --status                             # with YTA_DATA_DIR=.dev-data for the local instance
$PHP -l public/api.php                                     # lint (one file per call)
docker build -t yt-archiver:dev .                          # full image (if Docker is available)
```
