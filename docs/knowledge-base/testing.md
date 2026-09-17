# Testing and verification

There is no framework test suite. Use every level available; levels 1–3 need only PHP 8 and Node.

**PHP binary:** use PHP ≥ 8.0 (the code uses `match`, `mixed` and `str_contains`). On the maintainer's Windows machine it is the `php8`
command; `php` on PATH is 7.4, which cannot parse the code (IDE linters using it report false errors like `unexpected '=>'`).
The examples below write `php8`.

## 1. Static checks

```bash
for f in public/api.php public/download_worker.php public/includes/common.php dev/serve.php dev/router.php; do php8 -l $f; done
node --check public/js/app.js && node --check public/js/logs.js
```

## 2. Unit tests

```bash
php8 tests/unit.php            # URL parsing, title sanitizing, ipInRange, lock re-entrancy, queue recovery, staging paths
node tests/frontend.test.js    # queue grouping, escaping/XSS, playlist detection, formatting
```

`tests/unit.php` defines `DATA_DIR` as a temp directory. The recovery tests start real worker processes
(with `YTA_DATA_DIR` pointing to an empty directory, so they exit immediately) to simulate crashed workers.

## 3. Integration test (end to end, offline)

```bash
php8 tests/integration.php     # ~45 s
```

It needs PostgreSQL **binaries** (`initdb`, `pg_ctl`; found on PATH, in `C:/Program Files/PostgreSQL/*/bin`, `/usr/lib/postgresql/*/bin`,
or via `YTA_TEST_PG_BIN`). `tests/support/postgres.php` creates a throwaway cluster in the temp directory (trust auth, a free port, fsync off),
and stops and deletes it at the end. It never uses a running PostgreSQL service or its credentials. Without binaries: `SKIPPED` (exit 0),
or a failure with `YTA_TEST_REQUIRE_PG=1`. initdb refuses to run as root (CI containers: use a normal user).

Auth part:
- **Setup over HTTP:** forged CSRF, validation, and "Test connection" (success, unreachable server, missing database, unknown role).
  Saving is refused while the database fails, then succeeds.
- **Database from the environment** (last phase): a second installation in its own data directory and a second database (`yta_env`) of the same
  throwaway cluster, with the dev server started with `YTA_DB_*`. Checks: no database fields, Test connection hits that database, `?db=custom` still
  offers the form, saving writes no `db` key but creates the tables, and `setup.php --status`/`--migrate` use it. Then an approved admin is inserted
  directly (a reopened setup over existing accounts): the setup sign-in is refused and creates no account, and `--make-admin` finishes the installation.
  `tests/unit.php` covers the resolution order (config over environment, defaults, `YTA_DB_PASSWORD_FILE`).
- **Fake Google:** the config file is edited so `google.certs_endpoint` points at `tests/fixtures/fake-google.php`, a second `php -S`
  that publishes `tests/fixtures/google-test-cert.pem`.
- **Acting like Google's button:** the test reads `data-client-id`, `data-nonce` and `csrf` from the page, signs an ID token with
  `tests/fixtures/google-test-key.pem` (a **throwaway test key**, committed on purpose) and posts it.
- **Covered:** a forged token during setup, the admin created by setup, setup 404 afterwards, and the gate before and after setup.
  Accounts: pending → approve → admin-only 403s → disable → re-sign-in refused → enable. Tokens: unverified email, tampered signature,
  wrong audience/issuer, expired, foreign nonce, missing CSRF, replay (single-use nonce). Also sign-out with and without CSRF.

The key pair was generated with `openssl req -x509 -newkey rsa:2048 -nodes` (PHP cannot generate keys on Windows without an openssl.cnf).
Cookie jars (`$jar = 'bob'`) simulate separate browsers.

Starts `dev/serve.php` on a free port with a temp data directory and `YTA_YTDLP_BIN` set to
`tests/fixtures/fake-yt-dlp.php`, then checks over HTTP:
- the router deny rules, SPA fallback, CSRF 415, input validation;
- single download → library → `/download/` file;
- playlist → items + archive visible in the queue → one ZIP (private/failed items skipped, numbered entries, staging cleaned);
- cancelling a running download and a whole playlist (fake yt-dlp process killed, staging removed, nothing in the library);
- delete, logs, clean dev-server shutdown.

The fake yt-dlp is driven by IDs: `FAIL…` fails ("Private video"), `SLOW…` takes ~60 s and writes its PID to `FAKE_YTDLP_STATE_DIR`,
`HUGE…` reports 4 MB of streams and 2 h, `LIVE…` is a live stream. `PLslow…` is a playlist of slow items, `PLhuge…` has long entries plus one without a duration;
any other playlist is mixed (ok, private, unicode, failing). Flat-playlist entries report durations.

The main run starts the server with `LARGE_DOWNLOAD_WARNING_GB=0.001` and `MIN_FREE_SPACE_PERCENT=0`, so the size tests don't depend on the real disk.
A second, short run uses `MIN_FREE_SPACE_PERCENT=100` to test the 507 block. `tests/unit.php` covers `evaluateDownload` with synthetic numbers
and the worker's own low-disk refusal.
When you add behaviour to the worker, extend the fake and add a check here.

## 4. Manual testing with the real yt-dlp

```bash
php8 dev/serve.php             # downloads yt-dlp + ffmpeg into .dev-tools on first start; open http://127.0.0.1:8080
YTA_YTDLP_BIN="php8 tests/fixtures/fake-yt-dlp.php" php8 dev/serve.php --no-tools   # UI work without network
```

Known-good real inputs (verified 2026-09-17 on Windows):
- `https://youtu.be/jNQXAC9IVRw` ("Me at the zoo", 19 s) as mp3 and mp4.
- Playlist `PLZPcXWT247fT2ZdRMiJ7mSdEWOZo-oovm` ("sound clips for senses", 9 short entries, 3 of them private): the ZIP gets 6 of 9 items.

When a real download fails, read `<data>/worker.log`: it holds yt-dlp's exit code and last output lines.

## 5. Container smoke test (needs Docker)

The production path differs from local dev in: nginx instead of the router, php-fpm spawning workers directly (no dispatcher),
the sudo pip update, `/proc` liveness checks, and process-group kills. Verify changes in those areas here.

```bash
docker build -t yt-archiver:dev .
docker run -d --name yta-dev -p 8080:80 -v "$PWD/.dev-data-docker:/data" yt-archiver:dev
curl -s localhost:8080/api.php?action=version
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"url":"https://youtu.be/jNQXAC9IVRw","format":"mp3"}' localhost:8080/api.php?action=download
curl -s localhost:8080/api.php?action=status
docker exec yta-dev ls -la /data/videos /data/videos/.staging
docker rm -f yta-dev
```

Check by hand:
- nginx denies `/includes/common.php`, `/download_worker.php` and `/download/.staging/` (403); a POST without JSON Content-Type returns 415.
- Cancelling leaves no `yt-dlp`/`ffmpeg` process behind (`docker exec yta-dev ps aux`).
- `docker restart yta-dev` during a download: the job is re-queued once and resumes after the UI polls `status`.
- A real playlist: grouped items in the queue, a single numbered ZIP in the library.
