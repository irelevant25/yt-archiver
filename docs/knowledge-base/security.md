# Security model

The app is typically deployed on a home server and may be exposed through Cloudflare or a reverse proxy.
**Everything requires a signed-in, approved account** (Google sign-in; see authentication.md) except `/login.php` and,
until the installation is finished, `/setup.php`. Approved users are still only semi-trusted (anyone the admin approved):
they can queue downloads and delete library entries. Administrators can additionally manage accounts, read logs and upgrade yt-dlp.
Never add an entry point that bypasses the gate (nginx `auth_request`, `dev/router.php` and the check in the PHP file itself).

## Controls in place (keep them)

| Threat | Control | Where |
|---|---|---|
| Anonymous access | `authGate()` on every request: nginx `auth_request` (Docker), `dev/router.php` (dev), `api.php` check; fails closed if the DB is down | auth.php, nginx.conf |
| Unapproved or disabled accounts | status read from the DB on every request; pending → pending page, disabled → no session; accounts are never deleted | `currentUser()`, login.php |
| Forged Google sign-in / login CSRF | the ID token comes from the browser, so its **RS256 signature is verified** against Google's certificates (`kid`, no `alg` confusion); session nonce (single use, 30 min) + CSRF token; `iss`/`aud`/`azp`/`exp`/`nbf`/`nonce`/`email_verified` validated. No client secret exists to leak | `verifyGoogleIdToken`, `validateIdTokenClaims` |
| Session theft/fixation | HttpOnly, SameSite=Lax, Secure on https, strict mode, ID regenerated at sign-in; sign-out needs a CSRF token | auth.php |
| Open redirect after sign-in | `safeNextPath` allows local paths only | auth.php |
| Setup hijacking after install | `setup.php` 404 once `installed`; while in progress it requires the 48-hex `setup_token`; step 1 form has a double-submit CSRF cookie. If `config.php` is lost but the database (with `YTA_DB_*`, no password needed) keeps an approved admin, the setup sign-in refuses to create another one (`hasApprovedAdmin()`); finish with `--make-admin` on the server | setup.php, login.php |
| Privilege escalation | admin-only actions `requireAdmin()`; admin pages in `ADMIN_PATHS`; admins cannot change their own account | api.php, auth.php |
| Secrets on disk | `DATA_DIR/config.php` 0600, sessions 0700, `/data` never served; SQL errors are not sent to the browser. The bundled database's password stays in the environment (`YTA_DB_PASSWORD` or `YTA_DB_PASSWORD_FILE`) and is never written to `config.php` or shown by setup/`--status` (`describeDsn`). The postgres service publishes no port | entrypoint.sh, api.php, setup.php, docker-compose.yml |
| Command/argument injection into yt-dlp | URLs parsed into strict video (`[A-Za-z0-9_-]{11}`) and list IDs, canonical URL rebuilt; `proc_open` with an argument array; `--` before the URL | `parseYoutubeUrl`, `download_worker.php` |
| SSRF / non-YouTube sources | host allowlist (`youtube.com`, `www.`, `m.`, `music.`, `youtu.be`); no userinfo or port | `parseYoutubeUrl` |
| Path traversal | file paths are built only from server-generated job IDs and sanitized titles; `basename()` on `serve`/delete; dotfiles refused | `sanitizeTitle`, `api.php` |
| Stored XSS via video titles or filenames | `escapeHtml` escapes quotes; `data-*` attributes plus delegated handlers; format whitelisted | `app.js` |
| CSRF from other websites | no CORS headers; POST requires `Content-Type: application/json` (forces a preflight that is never approved); DELETE is always preflighted | `requireJsonRequest` |
| Spoofed client IP in the request log / https detection | proxy headers trusted only when `REMOTE_ADDR` is private/loopback or in the `TRUSTED_PROXIES` env (IPs/CIDRs, `ipInRange`); prefers `CF-Connecting-IP`, then `X-Real-IP`, then the **last** `X-Forwarded-For` entry | `getClientIp` |
| Privilege escalation via sudo | sudoers allows only the exact pip upgrade command | Dockerfile |
| Serving partial or internal files | nginx denies `/includes/`, `/download_worker.php` and `/(download\|videos)/.…`; `dev/router.php` mirrors these rules for local dev (covered by tests/integration.php) | nginx.conf, dev/router.php |
| Concurrent queue corruption / lost jobs | `flock` + atomic JSON writes | common.php |
| Runaway processes after a cancel | worker is a process-group leader; the whole group is killed | `killWorker` |
| Filling the disk | size check + 10 % free-space minimum enforced in the API (under the lock) and in the worker; confirmation is only a UI courtesy, never a bypass | `evaluateDownload`, download_worker.php |

## Known open points (not fixed; decide with the owner)

- **Before the installation is finished,** `/setup.php` step 1 is open to anyone who can reach the site (like any web installer).
  Finish setup immediately after deploying, or deploy on a private network first.
- `inspect` (and the first `download` of a URL) runs yt-dlp synchronously inside the web request. Approved users can tie up php-fpm workers
  (the pool has a few children) by sending many different URLs. The 15-minute cache only helps with repeated URLs.
- No rate limiting on sign-in attempts (Google handles account protection; the callback is cheap). Pending accounts can pile up if the site is public.
- Size estimates can be wrong (bitrate assumptions, formats hidden without a JS runtime). The 10 % minimum is the real safety net.

- `public/debug.php` shows request IP headers to any approved user. Remove it once IP debugging is done.
- `logs.csv` grows without limit and is read fully into memory.
- A LAN client (private `REMOTE_ADDR`) can still spoof `CF-Connecting-IP`, which only affects the IP shown in the request log.
- `entrypoint.sh` runs `chown -R` / `chmod -R 755` over the whole `/data` volume on every start (slow for big libraries, marks files executable).

The local dev server binds to `127.0.0.1` by default. `--host=0.0.0.0` exposes an unauthenticated app to the network.

## Review checklist for changes

1. Does any request value reach a shell, a filesystem path, a yt-dlp argument or `innerHTML` without validation or escaping?
2. Is every `queue.json`/`database.json` read-modify-write inside `withLock`, and every write done with `writeJson`?
3. New POST endpoint → uses `requireJsonRequest()`. New internal PHP file → not web-reachable (under `includes/`, or a deny rule in **both** nginx.conf and dev/router.php).
4. New files under `/data/videos` that must not be public → under `.staging/` or another dot directory.
5. Frontend: new rendered fields go through `escapeHtml`; no inline handlers with interpolated data.
