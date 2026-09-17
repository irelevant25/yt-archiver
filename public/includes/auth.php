<?php
/**
 * Configuration, PostgreSQL and Google sign-in.
 *
 * - The configuration (Google OAuth client ID, secret, and a database entered by hand) is written by setup.php to
 *   DATA_DIR/config.php. /data is never served by nginx. By default the database is the PostgreSQL service from
 *   docker-compose.yml, passed in through YTA_DB_* environment variables (see databaseSettings()).
 * - Only "Sign in with Google" exists. New accounts are pending until an administrator approves them.
 * - Every request except /login.php (and /setup.php before the installation is finished) needs an approved user.
 *   nginx asks includes/auth_check.php (auth_request); dev/router.php calls authGate() directly;
 *   api.php checks again itself.
 */

require_once __DIR__ . '/common.php';

define('CONFIG_FILE', DATA_DIR . '/config.php');
define('SESSIONS_DIR', DATA_DIR . '/sessions');
define('SESSION_NAME', 'yta_session');
define('SESSION_LIFETIME', 30 * 24 * 3600);
define('MIGRATIONS_DIR', __DIR__ . '/migrations');

const GOOGLE_CERTS_ENDPOINT = 'https://www.googleapis.com/oauth2/v1/certs';
const GOOGLE_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

// Pages only administrators may open (their API actions check the role as well)
const ADMIN_PATHS = ['/logs.html', '/js/logs.js', '/css/logs.css', '/users.html', '/js/users.js', '/css/users.css'];
// Reachable without a signed-in user
const PUBLIC_PATHS = ['/login.php', '/setup.php'];

// ── Configuration ────────────────────────────────────────

function appConfig(bool $reload = false): array {
    static $config = null;
    if ($config === null || $reload) {
        $loaded = is_file(CONFIG_FILE) ? (include CONFIG_FILE) : [];
        $config = is_array($loaded) ? $loaded : [];
    }
    return $config;
}

function saveConfig(array $config): void {
    $php = "<?php\n// YT Archiver settings, written by setup.php. Contains secrets: never commit or publish.\n\nreturn "
        . var_export($config, true) . ";\n";
    $tmp = CONFIG_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, CONFIG_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write ' . CONFIG_FILE . ' (is the data directory writable?)');
    }
    @chmod(CONFIG_FILE, 0600);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONFIG_FILE, true);
    }
    appConfig(true);
}

/** Setup finished: database configured and an administrator exists. */
function isInstalled(): bool {
    return !empty(appConfig()['installed']);
}

function baseUrl(): string {
    return rtrim((string)(appConfig()['base_url'] ?? ''), '/');
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Database ─────────────────────────────────────────────

function pgsqlDsn(string $host, int $port, string $dbname): string {
    return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
}

function connectDatabase(string $dsn, string $user, string $password): PDO {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec("SET TIME ZONE 'UTC'");
    return $pdo;
}

/**
 * The database docker-compose.yml deploys next to the app (YTA_DB_HOST, YTA_DB_PORT, YTA_DB_NAME, YTA_DB_USER,
 * YTA_DB_PASSWORD or YTA_DB_PASSWORD_FILE for a Docker secret). Null when YTA_DB_HOST is not set.
 * @return array{dsn: string, user: string, password: string, source: 'environment'}|null
 */
function envDatabaseSettings(): ?array {
    $host = trim((string)getenv('YTA_DB_HOST'));
    if ($host === '') {
        return null;
    }
    $passwordFile = trim((string)getenv('YTA_DB_PASSWORD_FILE'));
    $password = $passwordFile !== ''
        ? rtrim((string)@file_get_contents($passwordFile), "\r\n")
        : (string)getenv('YTA_DB_PASSWORD');
    return [
        'dsn'      => pgsqlDsn($host, (int)numericEnv('YTA_DB_PORT', 5432), trim((string)getenv('YTA_DB_NAME')) ?: 'yt_archiver'),
        'user'     => trim((string)getenv('YTA_DB_USER')) ?: 'yt_archiver',
        'password' => $password,
        'source'   => 'environment',
    ];
}

/**
 * The database to use. A server entered by hand in setup.php (saved in config.php) wins, so an existing installation keeps
 * its accounts when the environment later gains YTA_DB_*; otherwise the environment. Null when neither is configured.
 * @return array{dsn: string, user: string, password: string, source: 'config'|'environment'}|null
 */
function databaseSettings(): ?array {
    $saved = appConfig()['db'] ?? null;
    if (is_array($saved) && (string)($saved['dsn'] ?? '') !== '') {
        return ['dsn' => (string)$saved['dsn'], 'user' => (string)($saved['user'] ?? ''), 'password' => (string)($saved['password'] ?? ''), 'source' => 'config'];
    }
    return envDatabaseSettings();
}

/** @return array{host?: string, port?: string, dbname?: string} */
function parseDsn(string $dsn): array {
    preg_match_all('/(host|port|dbname)=([^;]*)/', $dsn, $matches, PREG_SET_ORDER);
    return array_column($matches, 2, 1);
}

/** "name @ host:port" of a pgsql DSN, for pages and status output (never the credentials). */
function describeDsn(string $dsn): string {
    $parts = parseDsn($dsn);
    return ($parts['dbname'] ?? '?') . ' @ ' . ($parts['host'] ?? '?') . ':' . ($parts['port'] ?? '?');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $settings = databaseSettings();
        if ($settings === null) {
            throw new RuntimeException('The database is not configured yet. Open /setup.php.');
        }
        $pdo = connectDatabase($settings['dsn'], $settings['user'], $settings['password']);
    }
    return $pdo;
}

const EXISTING_ADMIN_MESSAGE = 'This database already has an administrator from an earlier installation, so the setup sign-in cannot create one. '
    . 'Finish the installation on the server: php /var/www/html/setup.php --make-admin=you@example.com';

/** The accounts database already belongs to an installation (checked before setup creates the first administrator; needs the migrations). */
function hasApprovedAdmin(): bool {
    return (bool)dbValue("SELECT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'approved')");
}

function dbAll(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dbOne(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function dbValue(string $sql, array $params = []): mixed {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function dbExec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Apply migrations from includes/migrations that have not run yet. Returns the applied file names. */
function migrateDatabase(?PDO $pdo = null): array {
    $pdo ??= db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
        name       varchar(200) PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
    )');
    $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $done, true)) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec((string)file_get_contents($file));
            $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
        }
        $applied[] = $name;
    }
    return $applied;
}

// ── Session ──────────────────────────────────────────────

function isHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * Start the session. Read-only by default (read_and_close), so long requests such as the size check
 * never block other requests of the same user on the session lock.
 */
function startSession(bool $write = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!is_dir(SESSIONS_DIR)) {
        @mkdir(SESSIONS_DIR, 0700, true);
    }
    session_save_path(SESSIONS_DIR);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        // Lax: the cookie must come along when Google redirects back to /login.php
        'samesite' => 'Lax',
        'httponly' => true,
        'secure'   => str_starts_with(baseUrl(), 'https://') || isHttpsRequest(),
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_start($write ? [] : ['read_and_close' => true]);
}

function sessionValue(string $key): mixed {
    if (empty($_COOKIE[SESSION_NAME])) {
        return null;
    }
    startSession();
    return $_SESSION[$key] ?? null;
}

/** The signed-in user (any status) or null. Read from the database on every request, so blocking works immediately. */
function currentUser(): ?array {
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!isInstalled()) {
        return null;
    }
    $id = (int)sessionValue('user_id');
    if ($id <= 0) {
        return null;
    }
    return $user = dbOne('SELECT id, email, name, picture, role, status FROM users WHERE id = ?', [$id]);
}

function isApproved(?array $user): bool {
    return $user !== null && $user['status'] === 'approved';
}

function isAdmin(?array $user): bool {
    return isApproved($user) && $user['role'] === 'admin';
}

function csrfToken(): string {
    startSession(true);
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['csrf'];
    session_write_close();
    return $token;
}

function csrfValid(?string $sent): bool {
    $token = sessionValue('csrf');
    return is_string($token) && $token !== '' && is_string($sent) && hash_equals($token, $sent);
}

function logout(): void {
    startSession(true);
    $_SESSION = [];
    session_destroy();
    setcookie(SESSION_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
}

/** Local path to continue to after signing in; anything else (other hosts, protocol-relative URLs) becomes "/". */
function safeNextPath(mixed $next): string {
    return is_string($next) && preg_match('#^/(?![/\\\\])[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*$#', $next) && !str_starts_with($next, '/login.php')
        ? $next : '/';
}

// ── Access gate ──────────────────────────────────────────

/**
 * Decide whether a request may continue:
 *   200 allowed, 401 sign-in required (or setup not finished), 403 signed in but not allowed (admin pages).
 */
function authGate(string $requestUri): int {
    $path = rawurldecode((string)parse_url($requestUri, PHP_URL_PATH));
    if (in_array($path, PUBLIC_PATHS, true)) {
        return 200;
    }
    if (!isInstalled()) {
        return 401;
    }
    try {
        $user = currentUser();
    } catch (Throwable $e) {
        error_log('[auth] ' . $e->getMessage());
        return 401; // fail closed when the database is unavailable
    }
    if (!isApproved($user)) {
        return 401;
    }
    if (in_array($path, ADMIN_PATHS, true) && !isAdmin($user)) {
        return 403;
    }
    return 200;
}

// ── Google sign-in (Google Identity Services: ID token verified on the server, no client secret) ──
//
// The login page loads Google's GIS library with the client ID and a per-session nonce. Google returns a signed
// ID token (JWT) to the page, which posts it to /login.php?action=google. The server verifies the RS256 signature
// against Google's published certificates and checks the claims, including the nonce stored in the session.

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string|false {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
}

function googleConfig(): array {
    $google = appConfig()['google'] ?? [];
    return [
        'client_id'      => (string)($google['client_id'] ?? ''),
        // Overridable only in the config file (tests use a fake certificate endpoint)
        'certs_endpoint' => (string)($google['certs_endpoint'] ?? GOOGLE_CERTS_ENDPOINT),
    ];
}

/**
 * Google's current signing certificates (key ID => PEM), cached in DATA_DIR/google-certs.json
 * for as long as Google's Cache-Control allows. A stale cache is used if Google is unreachable.
 */
function googleCertificates(bool $refresh = false): array {
    $cacheFile = DATA_DIR . '/google-certs.json';
    $cache = readJson($cacheFile, []);
    if (!$refresh && ($cache['expires'] ?? 0) > time() && !empty($cache['certs'])) {
        return $cache['certs'];
    }

    $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'user_agent' => 'YT-Archiver']]);
    $body = @file_get_contents(googleConfig()['certs_endpoint'], false, $context);
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $certs = is_string($body) ? json_decode($body, true) : null;
    $certs = is_array($certs) ? array_filter($certs, fn($pem) => is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')) : [];

    if (!$certs) {
        if (!empty($cache['certs'])) {
            return $cache['certs'];
        }
        throw new RuntimeException('Google sign-in cannot be verified right now (Google certificates unavailable). Please try again later.');
    }
    $maxAge = preg_match('/max-age=(\d+)/i', implode("\n", $headers), $m) ? (int)$m[1] : 3600;
    writeJson($cacheFile, ['expires' => time() + max(60, min($maxAge, 86400)), 'certs' => $certs]);
    return $certs;
}

/**
 * Verify a Google ID token received from the browser: RS256 signature with Google's certificate for its key ID,
 * then the claims. Returns the claims or throws with a user-readable reason.
 *
 * @param array|null $certificates key ID => PEM; null fetches Google's (tests pass their own)
 */
function verifyGoogleIdToken(string $idToken, string $clientId, string $nonce, ?int $now = null, ?array $certificates = null): array {
    $parts = explode('.', $idToken);
    $header = count($parts) === 3 ? json_decode((string)base64UrlDecode($parts[0]), true) : null;
    $signature = count($parts) === 3 ? base64UrlDecode($parts[2]) : false;
    if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || !$signature) {
        throw new RuntimeException('Google returned an invalid sign-in token.');
    }

    $certificate = ($certificates ?? googleCertificates())[$header['kid']] ?? null;
    if ($certificate === null && $certificates === null) {
        // Google rotates its keys: the token may use one newer than the cached certificates
        $certificate = googleCertificates(true)[$header['kid']] ?? null;
    }
    if ($certificate === null || openssl_verify($parts[0] . '.' . $parts[1], $signature, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('The Google sign-in could not be verified (signature).');
    }
    return validateIdTokenClaims($idToken, $clientId, $nonce, $now);
}

/** Check the claims of a Google ID token whose signature was already verified. */
function validateIdTokenClaims(string $idToken, string $clientId, string $nonce, ?int $now = null): array {
    $now ??= time();
    $parts = explode('.', $idToken);
    $claims = count($parts) === 3 ? json_decode((string)base64UrlDecode($parts[1]), true) : null;
    if (!is_array($claims)) {
        throw new RuntimeException('Google returned an invalid sign-in token.');
    }

    $audience = (array)($claims['aud'] ?? []);
    $checks = [
        'issuer'   => in_array($claims['iss'] ?? null, GOOGLE_ISSUERS, true),
        'audience' => $clientId !== '' && in_array($clientId, $audience, true) && (count($audience) === 1 || ($claims['azp'] ?? null) === $clientId),
        'expiry'   => is_numeric($claims['exp'] ?? null) && (int)$claims['exp'] >= $now - 60
            && (!isset($claims['nbf']) || (is_numeric($claims['nbf']) && (int)$claims['nbf'] <= $now + 60)),
        'nonce'    => is_string($claims['nonce'] ?? null) && $nonce !== '' && hash_equals($nonce, $claims['nonce']),
        'subject'  => is_string($claims['sub'] ?? null) && $claims['sub'] !== '',
        'email'    => is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL),
        'verified' => ($claims['email_verified'] ?? null) === true || ($claims['email_verified'] ?? null) === 'true',
    ];
    foreach ($checks as $check => $ok) {
        if (!$ok) {
            throw new RuntimeException(match ($check) {
                'verified' => 'Your Google account email address is not verified.',
                'nonce'    => 'The sign-in expired or was started in another tab. Please try again.',
                default    => "The Google sign-in could not be verified ($check).",
            });
        }
    }
    return $claims;
}

/**
 * The "Sign in with Google" button. Stores a fresh nonce (single use) in the session, plus whether this sign-in
 * finishes the setup and where to continue afterwards.
 */
function googleSignInWidget(bool $forSetup, string $next = '/'): string {
    startSession(true);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    // Reuse a pending nonce: rendering the page again (another tab, the favicon request redirected here) must not
    // invalidate a sign-in that is already in progress. It is still single use and expires after 30 minutes.
    $pending = $_SESSION['google'] ?? null;
    $reuse = is_array($pending) && is_string($pending['nonce'] ?? null) && time() - (int)($pending['created'] ?? 0) < 1500;
    $nonce = $reuse ? $pending['nonce'] : bin2hex(random_bytes(24));
    $_SESSION['google'] = ['nonce' => $nonce, 'setup' => $forSetup, 'next' => safeNextPath($next), 'created' => $reuse ? (int)$pending['created'] : time()];
    $csrf = $_SESSION['csrf'];
    session_write_close();

    $script = <<<'JS'
function ytaGoogleReady() {
    var slot = document.getElementById('googleButton');
    google.accounts.id.initialize({
        client_id: slot.dataset.clientId,
        nonce: slot.dataset.nonce,
        ux_mode: 'popup',
        auto_select: false,
        context: 'signin',
        callback: function (response) {
            document.getElementById('googleCredential').value = response.credential;
            document.getElementById('googleForm').submit();
        }
    });
    google.accounts.id.renderButton(slot, { type: 'standard', theme: 'filled_black', size: 'large', shape: 'pill', text: 'signin_with', width: 300 });
}
function ytaGoogleFailed() {
    document.getElementById('googleError').hidden = false;
}
JS;

    return '<form id="googleForm" method="post" action="/login.php?action=google">'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="credential" id="googleCredential"></form>'
        . '<div id="googleButton" class="google-slot" data-client-id="' . e(googleConfig()['client_id']) . '" data-nonce="' . e($nonce) . '"></div>'
        . '<p class="notice error" id="googleError" hidden>Google sign-in could not be loaded. Check the connection or content blockers and reload the page.</p>'
        . '<script>' . $script . '</script>'
        . '<script src="https://accounts.google.com/gsi/client" async onload="ytaGoogleReady()" onerror="ytaGoogleFailed()"></script>';
}

/**
 * Handle the ID token posted by the sign-in button: CSRF token, single-use nonce from the session, signature and claims.
 * Returns ['claims' => ..., 'setup' => bool, 'next' => string]. Throws with a user-readable message.
 */
function completeGoogleSignIn(?string $credential, ?string $csrf): array {
    if (!csrfValid($csrf)) {
        throw new RuntimeException('The sign-in form expired. Please try again.');
    }
    startSession(true);
    $pending = $_SESSION['google'] ?? null;
    unset($_SESSION['google']); // single use
    session_write_close();

    if (!is_array($pending) || time() - (int)$pending['created'] > 1800) {
        throw new RuntimeException('The sign-in expired. Please try again.');
    }
    if (!is_string($credential) || $credential === '') {
        throw new RuntimeException('Google did not return a sign-in.');
    }

    return [
        'claims' => verifyGoogleIdToken($credential, googleConfig()['client_id'], (string)$pending['nonce']),
        'setup'  => !empty($pending['setup']),
        'next'   => safeNextPath($pending['next'] ?? '/'),
    ];
}

/** Create or update the account for verified Google claims. New accounts are pending, unless $asAdmin (setup). */
function upsertGoogleUser(array $claims, bool $asAdmin = false): array {
    $picture = is_string($claims['picture'] ?? null) && str_starts_with($claims['picture'], 'https://') ? $claims['picture'] : '';
    $name = mb_substr(trim((string)($claims['name'] ?? '')), 0, 255);
    $email = mb_substr((string)$claims['email'], 0, 320);

    $existing = dbOne('SELECT id FROM users WHERE google_sub = ?', [$claims['sub']]);
    // First sign-in of an account an administrator created by email: claim it (it keeps the role and status set by
    // the administrator). Safe because Google verified the email address (validateIdTokenClaims requires email_verified).
    // "google_sub IS NULL" in the UPDATE makes a concurrent claim of the same account fail instead of taking it over.
    if (!$existing) {
        $invited = dbOne('SELECT id FROM users WHERE google_sub IS NULL AND lower(email) = lower(?)', [$email]);
        if ($invited && dbExec('UPDATE users SET google_sub = ? WHERE id = ? AND google_sub IS NULL', [$claims['sub'], $invited['id']]) === 1) {
            $existing = $invited;
        }
    }
    if ($existing) {
        dbExec('UPDATE users SET email = ?, name = ?, picture = ?, last_login_at = now() WHERE id = ?', [$email, $name, $picture, $existing['id']]);
        if ($asAdmin) {
            dbExec("UPDATE users SET role = 'admin', status = 'approved', approved_at = coalesce(approved_at, now()) WHERE id = ?", [$existing['id']]);
        }
        $id = (int)$existing['id'];
    } else {
        $id = (int)dbValue(
            'INSERT INTO users (google_sub, email, name, picture, role, status, approved_at, last_login_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, now()) RETURNING id',
            [$claims['sub'], $email, $name, $picture, $asAdmin ? 'admin' : 'user', $asAdmin ? 'approved' : 'pending', $asAdmin ? gmdate('c') : null]
        );
    }
    return dbOne('SELECT id, email, name, picture, role, status FROM users WHERE id = ?', [$id]);
}

function signIn(array $user): void {
    startSession(true);
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$user['id'], 'csrf' => bin2hex(random_bytes(32)), 'signed_in_at' => time()];
    session_write_close();
}

/** Minimal page frame for login.php and setup.php (styles inline: nothing else is reachable before signing in). */
function renderAuthPage(string $title, string $body, bool $wide = false): void {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    // Google's sign-in button checks the page origin through the Referer header
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $css = (string)@file_get_contents(__DIR__ . '/auth-page.css');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        // Inline icon: otherwise the browser requests /favicon.ico, which is redirected to this page again
        . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E%3Cpath fill=%27%23ff3366%27 d=%27M19.615 3.184c-3.604-.246-11.631-.245-15.23 0C.488 3.45.029 5.804 0 12c.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0C23.512 20.55 23.971 18.196 24 12c-.029-6.185-.484-8.549-4.385-8.816zM9 16V8l8 3.993L9 16z%27/%3E%3C/svg%3E"><title>' . e($title) . ' · YT Archiver</title><style>' . $css . '</style></head>'
        . '<body><main class="auth' . ($wide ? ' wide' : '') . '"><div class="auth-logo"><svg viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0C.488 3.45.029 5.804 0 12c.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0C23.512 20.55 23.971 18.196 24 12c-.029-6.185-.484-8.549-4.385-8.816zM9 16V8l8 3.993L9 16z"/></svg></div>'
        . '<h1>' . e($title) . '</h1><p class="auth-brand">YT Archiver</p>' . $body . '</main></body></html>';
}
