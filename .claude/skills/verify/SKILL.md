---
name: verify
description: Run YT Archiver's verification steps (PHP 8 lint, JS syntax check, PHP and frontend unit tests, the offline end-to-end integration test, and a Docker build/smoke test when Docker exists) and report the results. Use after any code change, before telling the user a change is done, or when asked to test/check the project.
---

# Verify YT Archiver

Run from the repository root. Report each step as passed, failed (with output) or skipped (with the reason). Never claim a skipped step passed.

## 1. Find a PHP ≥ 8 binary

Try `php8` first (the maintainer's PHP 8 command), then `php`:

```bash
for p in php8 php php8.3 php83; do
  command -v "$p" >/dev/null 2>&1 && "$p" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' 2>/dev/null && { PHP=$p; echo "PHP=$p"; break; }
done
```
If none is found, skip the PHP steps and say so. PHP 7.4 is **not** acceptable: it cannot parse `match`.

## 2. Lint

```bash
for f in public/api.php public/download_worker.php public/includes/common.php dev/serve.php dev/router.php tests/*.php tests/fixtures/*.php; do $PHP -l "$f" || break; done
node --check public/js/app.js && node --check public/js/logs.js
```

## 3. Unit tests

```bash
$PHP tests/unit.php
node tests/frontend.test.js
```

## 4. Integration test (offline, ~45 s)

Needs PostgreSQL binaries (`initdb`, `pg_ctl`) for a throwaway cluster; if it prints `SKIPPED`, report that the auth and end-to-end checks did **not** run.
Never point it at an existing PostgreSQL server.

```bash
$PHP tests/integration.php
```
It starts `dev/serve.php` on a free port with the fake yt-dlp. On failure it prints the dev-server output and the tail of the web-server log; use those for diagnosis.
If a step hangs, check for leftover processes (`tasklist | findstr php` on Windows, `ps aux | grep -E "serve.php|download_worker"` elsewhere).

## 5. Container (only if `docker` is available)

Follow section 5 of `docs/knowledge-base/testing.md`. This matters for changes to nginx.conf, the Dockerfile, entrypoint.sh, the sudo update,
or the Linux process handling (spawn, `/proc`, process-group kill), which local runs on Windows don't exercise.

## 6. Consistency checks

- `git status --short`: are new files intended? Is nothing that should be ignored (`.dev-data/`) included?
- `nginx.conf` changed → is `dev/router.php` updated too, and vice versa?
- Behaviour changed → are `docs/knowledge-base/*.md` and `README.md` updated?
- For changes to input handling, processes or rendering, suggest the `security-reviewer` agent; for queue or worker changes, suggest `queue-reviewer`.
