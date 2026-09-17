# Authentication, setup and accounts

Only **Sign in with Google** exists, and it needs only a **client ID** (no client secret, no redirect URI).
Accounts live in PostgreSQL; everything else (queue, library, logs) stays in JSON/CSV files.

## Files

| File | Role |
|---|---|
| `public/includes/auth.php` | config file, database settings (`databaseSettings()`), PDO + migrations, sessions, `currentUser()`, `authGate()`, Google ID token verification, `googleSignInWidget()`, `renderAuthPage()` |
| `public/includes/auth_check.php` | nginx `auth_request` endpoint: 204 allowed / 401 sign in / 403 admin-only |
| `public/includes/migrations/NNN_*.sql` | applied in name order, recorded in the `migrations` table (`002` renamed `blocked` → `disabled`) |
| `public/includes/auth-page.css` | inlined into login/setup pages (no static file is reachable before sign-in) |
| `public/login.php` | sign-in page, `POST ?action=google` (ID token), pending/disabled status page, sign-out |
| `public/setup.php` | one-time installation wizard (with "Test connection") + CLI (`--status`, `--migrate`, `--make-admin=EMAIL`) |
| `public/users.html`, `js/users.js`, `css/users.css` | admin account management (API `users`, `user`) |
| `DATA_DIR/config.php` | written by setup: `db` {dsn,user,password} **only for a server entered by hand**, `google` {client_id[, certs_endpoint]}, `base_url`, `secret`, `setup_token` (until installed), `installed`, `installed_at`. Mode 0600 |
| `DATA_DIR/google-certs.json` | cached Google signing certificates (for as long as Google's `Cache-Control` allows, max 24 h) |
| `DATA_DIR/sessions/` | PHP session files (0700); persist across container restarts |
| `docker-compose.yml`, `.env.example` | deploy the app together with a `postgres:17-alpine` service and pass its connection in as `YTA_DB_*` |

## Which database (`databaseSettings()`)

1. `config.php` `db`: a server entered by hand in setup (the "Use another PostgreSQL server" form). It **wins**, so an installation made before the
   compose file shipped a database keeps its accounts when `YTA_DB_*` appear later.
2. `envDatabaseSettings()`: `YTA_DB_HOST` (required to enable it), `YTA_DB_PORT` (5432), `YTA_DB_NAME` (`yt_archiver`), `YTA_DB_USER` (`yt_archiver`),
   `YTA_DB_PASSWORD`, or `YTA_DB_PASSWORD_FILE` (Docker secret; wins over `YTA_DB_PASSWORD`, trailing newline stripped). This is the default deployment.
3. Neither: `db()` throws, `authGate()` fails closed, setup shows the hand-entry form.

The environment's credentials are **never written** to `config.php`. The php-fpm image sets `clear_env = no`, so the container environment
reaches php-fpm and the workers. `describeDsn()` (name @ host:port, no credentials) is what pages and `--status` show.

## The gate: every request except `/login.php` and `/setup.php`

`authGate($requestUri)`:
1. `PUBLIC_PATHS` (`/login.php`, `/setup.php`) → 200. setup.php protects itself (404 once installed, token while in progress).
2. Not installed → 401, which leads to `/login.php` and then `/setup.php`.
3. `currentUser()` reads `user_id` from the session and loads the row from PostgreSQL **on every request**, so disabling works
   immediately. A database error means 401 (fail closed).
4. Status other than `approved` → 401. Pending and disabled users are sent to `/login.php`, which shows their status and a sign-out button.
5. `ADMIN_PATHS` (users/logs pages and their JS/CSS) for a non-admin → 403.

Where it runs:
- **Docker:** nginx `auth_request /_auth` at server level; `error_page 401 = @signin` returns a JSON 401 for `/api.php`, otherwise a 302 to `/login.php`.
  Only the `/login.php` and `/setup.php` locations have `auth_request off`. This covers static files, `/download/` and `/videos/` too.
- **Local dev:** `dev/router.php` calls `authGate()` for every request.
- **API:** `api.php` checks again (`isApproved($currentUser)`); admin actions call `requireAdmin()`. Adding a new PHP entry point? Gate it the same way.

Sessions: cookie `yta_session`, HttpOnly, SameSite=Lax, `Secure` when `base_url` is https, 30-day lifetime, `use_strict_mode`,
and the ID is regenerated on sign-in. Read access uses `read_and_close`, so long requests (yt-dlp size checks) never hold the session lock.

## Google sign-in (Google Identity Services, ID token verified on the server)

1. `googleSignInWidget($forSetup, $next)` (login page, setup step 2) stores a **nonce** (a pending one younger than 25 min is reused, see below),
   the `setup` flag and `next` in the session, ensures a CSRF token, and renders:
   - `<div id="googleButton" data-client-id data-nonce>`, filled by `https://accounts.google.com/gsi/client` (`google.accounts.id.initialize` with the
     nonce, popup UX, `renderButton`). This inline script is the only JavaScript before sign-in.
   - a hidden form that the GIS callback submits: `POST /login.php?action=google` with `credential` (the ID token) and `csrf`.
2. `completeGoogleSignIn($credential, $csrf)`: CSRF token check, then the session's pending sign-in is **consumed** (single use,
   30 min), then `verifyGoogleIdToken()`.
3. `verifyGoogleIdToken()`: header `alg` must be `RS256` with a `kid`; the certificate for the `kid` comes from `googleCertificates()`
   (`https://www.googleapis.com/oauth2/v1/certs`, cached; re-fetched once for an unknown `kid` because Google rotates keys; a stale cache
   is used if Google is unreachable); `openssl_verify` must return 1. Then `validateIdTokenClaims()`: `iss` Google,
   `aud` = client ID (`azp` when there are several audiences), `exp`/`nbf`, `nonce` = session nonce, `sub`, valid `email`, `email_verified`.
4. `upsertGoogleUser()`: matched by `google_sub` (email, name and picture are updated on each sign-in). Otherwise an **account added in advance**
   (`google_sub IS NULL`, same email case-insensitively) is claimed: `google_sub` is set with `WHERE google_sub IS NULL` (a concurrent claim fails), and the
   account keeps the role and status the admin chose. This is safe only because `email_verified` is required. Otherwise a new account is `role=user, status=pending`.
   `picture` is kept only if it is https.
5. Disabled → no session, and a flash message. Pending → session, redirected to the pending page. Approved → `next`
   (`safeNextPath`: local paths only, never back to login).

Google Cloud console: OAuth client of type **Web application** with the **Authorized JavaScript origin** = `base_url`
(for localhost: both `http://localhost` and `http://localhost:PORT`; Google does not accept `127.0.0.1`). Pages set
`Referrer-Policy: strict-origin-when-cross-origin`, because the GIS button checks the origin through the Referer.
`googleConfig()` allows overriding `certs_endpoint` from the config file only (the tests use `tests/fixtures/fake-google.php`).

## Setup lifecycle

| State | `/setup.php` | Everything else |
|---|---|---|
| no `config.php` | step 1 form (open to anyone: finish setup right after deploying) | → `/login.php` → `/setup.php` |
| `config.php`, `installed=false` | needs `?token=`; step 2 "Sign in with Google" button; `&edit=1` shows step 1 again (an empty password keeps the saved one) | same |
| `installed=true` | **404** | gate as above |

- **Step 1:** a double-submit CSRF cookie (`yta_setup_csrf`, path `/setup.php`, SameSite Strict; an existing cookie is reused, see below) protects the form, which has two submit buttons.
  **Database mode** (hidden `db_mode`, switched with the `?db=environment|custom` links; default `environment` unless `config.php` already has `db`):
  - `environment` (only when `YTA_DB_*` is set): no database fields; the page shows `describeDsn()` and the user. Test and save use `envDatabaseSettings()`,
    and the saved config has **no `db` key** (an old one is dropped).
  - `custom`: the host/port/name/user/password fields below, validated and saved in `config.php`.
  - `intent=test` ("Test connection") validates only the database fields and runs `testDatabase()`: TCP reachability (`fsockopen`), then sign-in
    (PDO; wrong password, unknown role and missing database are reported with PostgreSQL's message, without the SQLSTATE prefix), then permissions
    (`has_schema_privilege('public', 'CREATE')`, or existing tables from an earlier installation). Nothing is saved; the typed values
    (including the password) are shown again.
  - `intent=save` validates everything, runs the same database test (saving is refused if any check fails), runs the migrations, and
    writes the config with a new `setup_token`.
- **Step 2:** a Google sign-in whose session carries `setup=true` (only granted by the token-protected step 2 page). The account becomes
  an approved admin, `installed=true` is set, `setup_token` is removed, and the admin is signed in. Errors return to `setup.php?token=…` with a flash message.
  **Refused when the database already has an approved admin** (`hasApprovedAdmin()`, checked in login.php and hiding the button on the page).
  Otherwise losing `config.php` while the database volume survives would reopen setup, and with `YTA_DB_*` the visitor needs no database password to
  attach the existing accounts. Such an installation is finished on the server with `setup.php --make-admin=EMAIL`.
- **Recovery:** `php setup.php --status` prints the database in use (`databaseDescription()`: name @ host:port and its source) and the setup link.
  `php setup.php --make-admin=EMAIL` approves and promotes the account with that email,
  or adds an approved admin account in advance if none exists (and marks the installation finished).
- Migrations run on start once `config.php` exists: `entrypoint.sh` (Docker) and `dev/serve.php` (local) both call `setup.php --migrate`.
  `entrypoint.sh` retries up to 24 times, 5 s apart (2 min), because the database container may still be starting: compose only waits for it to be
  started (`service_started`), and `depends_on` is not applied at all when Docker restarts containers after a reboot.

## Accounts API (admin)

- `GET me` → `user`, `csrf` (for the sign-out form), `pending_users` (admins).
- `GET users` → all accounts, pending first, with ISO 8601 timestamps and `approved_by`.
- `POST create_user {email, role: user|admin, approved: bool}` adds an account in advance (email stored lowercase; 409 if any account has that email;
  an admin must be approved). It has no `google_sub` until the first sign-in (`invited: true` in `users`; `created_by` records the admin).
  Pending accounts added in advance are not counted in `pending_users` until the person signs in.
  Migration `003` made `google_sub` nullable and added a unique index on `lower(email)` for unclaimed accounts.
- `POST user {id, operation}` with `approve | disable | enable | make_admin | make_user`. **Accounts are never deleted**, only disabled.
  Admins cannot change their own account (this also prevents removing the last admin). `make_admin` requires an approved account.
- Every approved user may use the downloader, including deleting library entries. Admin-only: accounts, logs, yt-dlp update.
- Every logged request records the user's email (6th column in `logs.csv`).

## Pitfall: pages rendered again in the background

Browsers request `/favicon.ico` automatically. Before sign-in, that request is redirected to `/login.php` and then `/setup.php`, so
the page is rendered a second time behind the user's back (a second tab has the same effect). Anything the page stores per render would be
replaced, and the form that is already open would fail ("The form expired", or a nonce mismatch after Google sign-in). Therefore:
- the setup CSRF cookie and the pending Google nonce are **reused**, not regenerated, on every render;
- every page declares an inline `data:` icon, so browsers do not request `/favicon.ico` at all.

`tests/integration.php` covers both cases (`followRedirects('/favicon.ico')` between loading and submitting).

## Adding a migration

Create `public/includes/migrations/00N_description.sql` (plain SQL; it runs inside a transaction). Never edit an applied file.
It runs on the next container start, or with `php setup.php --migrate`.
