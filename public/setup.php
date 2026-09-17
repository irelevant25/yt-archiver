<?php
/**
 * One-time installation: PostgreSQL, Google sign-in, first administrator.
 *
 * Browser (only until the installation is finished, afterwards 404):
 *   1. /setup.php                  database (with a "Test connection" button) + Google OAuth client ID + public URL
 *                                  → connection check, migrations,
 *                                  DATA_DIR/config.php is written together with a setup token
 *   2. /setup.php?token=…          "Sign in with Google": proves the Google configuration works; that account
 *                                  becomes the approved administrator and the installation is finished
 *      /setup.php?token=…&edit=1   change the settings of step 1
 *
 * Command line (always available, e.g. `docker exec yt-archiver php /var/www/html/setup.php --status`):
 *   php setup.php --status               installation state (prints the setup link while unfinished)
 *   php setup.php --migrate              apply new database migrations (run by entrypoint.sh on start)
 *   php setup.php --make-admin=EMAIL     approve an account and make it an administrator; creates it if needed (recovery)
 */

require_once __DIR__ . '/includes/auth.php';

const SETUP_CSRF_COOKIE = 'yta_setup_csrf';

if (PHP_SAPI === 'cli') {
    exit(setupCli());
}

// ── Browser ──────────────────────────────────────────────

if (isInstalled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "404 Not Found\n";
    exit;
}

$hasConfig = is_file(CONFIG_FILE);
$config = appConfig();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$tokenValid = $hasConfig && is_string($config['setup_token'] ?? null) && $token !== '' && hash_equals($config['setup_token'], $token);

if ($hasConfig && !$tokenValid) {
    http_response_code(403);
    renderAuthPage('Setup in progress', '<p class="notice warning">The installation has already been started. Continue with the link setup showed after saving the settings.</p>'
        . '<p>Lost it? Print it on the server:</p><p><code>php /var/www/html/setup.php --status</code></p>');
    exit;
}

$flashMessage = null;
if (is_string(sessionValue('flash'))) {
    startSession(true);
    $flashMessage = $_SESSION['flash'];
    unset($_SESSION['flash']);
    session_write_close();
}

$editing = !$hasConfig || isset($_GET['edit']) || $_SERVER['REQUEST_METHOD'] === 'POST';

if ($editing) {
    settingsStep($config, $hasConfig, $token);
} else {
    signInStep($config, $token, $flashMessage);
}

function settingsStep(array $config, bool $hasConfig, string $token): void {
    $db = parseDsn((string)($config['db']['dsn'] ?? ''));
    $form = [
        'db_host'     => trim((string)($_POST['db_host'] ?? $db['host'] ?? '127.0.0.1')),
        'db_port'     => trim((string)($_POST['db_port'] ?? $db['port'] ?? '5432')),
        'db_name'     => trim((string)($_POST['db_name'] ?? $db['dbname'] ?? 'yt_archiver')),
        'db_user'     => trim((string)($_POST['db_user'] ?? $config['db']['user'] ?? '')),
        'db_password' => (string)($_POST['db_password'] ?? ''),
        'client_id'   => trim((string)($_POST['client_id'] ?? $config['google']['client_id'] ?? '')),
        'base_url'    => rtrim(trim((string)($_POST['base_url'] ?? $config['base_url'] ?? detectedBaseUrl())), '/'),
    ];
    $intent = ($_POST['intent'] ?? '') === 'test' ? 'test' : 'save';
    // An empty password while editing keeps the saved one
    $password = $form['db_password'] === '' && $hasConfig ? (string)($config['db']['password'] ?? '') : $form['db_password'];
    $errors = [];
    $testResults = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string)($_COOKIE[SETUP_CSRF_COOKIE] ?? '');
        if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $errors[] = 'The form expired. Please submit it again.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $form['db_host'])) {
            $errors[] = 'Database host: letters, digits, dots, dashes and underscores only.';
        }
        if (!ctype_digit($form['db_port']) || (int)$form['db_port'] < 1 || (int)$form['db_port'] > 65535) {
            $errors[] = 'Database port must be a number between 1 and 65535.';
        }
        foreach (['db_name' => 'Database name', 'db_user' => 'Database user'] as $field => $label) {
            if (!preg_match('/^[A-Za-z0-9._-]{1,63}$/', $form[$field])) {
                $errors[] = "$label: 1–63 letters, digits, dots, dashes or underscores.";
            }
        }
        if ($intent === 'save') {
            if (!preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $form['client_id'])) {
                $errors[] = 'Google client ID must look like 1234567890-abc123.apps.googleusercontent.com.';
            }
            if (!preg_match('~^https?://(?:[A-Za-z0-9-]+\.)*[A-Za-z0-9-]+(?::\d{1,5})?$~', $form['base_url'])) {
                $errors[] = 'Public URL must be like https://yt.example.com (no path, no trailing slash).';
            }
        }

        if (!$errors) {
            $testResults = testDatabase($form['db_host'], (int)$form['db_port'], $form['db_name'], $form['db_user'], $password);
        }
        $databaseOk = $testResults && !in_array('error', array_column($testResults, 0), true);

        if (!$errors && $intent === 'save' && $databaseOk) {
            $dsn = pgsqlDsn($form['db_host'], (int)$form['db_port'], $form['db_name']);
            try {
                $applied = migrateDatabase(connectDatabase($dsn, $form['db_user'], $password));
                error_log('[setup] migrations applied: ' . ($applied ? implode(', ', $applied) : 'none'));

                $google = ['client_id' => $form['client_id']];
                // Keep an endpoint override set in the file by hand (tests)
                if (isset($config['google']['certs_endpoint'])) {
                    $google['certs_endpoint'] = $config['google']['certs_endpoint'];
                }
                $newToken = (string)($config['setup_token'] ?? bin2hex(random_bytes(24)));
                saveConfig([
                    'db'          => ['dsn' => $dsn, 'user' => $form['db_user'], 'password' => $password],
                    'google'      => $google,
                    'base_url'    => $form['base_url'],
                    'secret'      => (string)($config['secret'] ?? bin2hex(random_bytes(32))),
                    'setup_token' => $newToken,
                    'installed'   => false,
                ]);
                header('Location: /setup.php?token=' . rawurlencode($newToken), true, 303);
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Cannot set up the database: ' . cleanDatabaseError($e);
            }
        } elseif (!$errors && $intent === 'save') {
            $errors[] = 'The database check failed (details below). Fix the connection and try again.';
        }
    }

    // Keep the existing token: other requests that end on this page (the browser's automatic /favicon.ico request is
    // redirected here, a second tab) must not invalidate the form that is already open
    $csrf = preg_match('/^[a-f0-9]{48}$/', (string)($_COOKIE[SETUP_CSRF_COOKIE] ?? '')) ? $_COOKIE[SETUP_CSRF_COOKIE] : bin2hex(random_bytes(24));
    setcookie(SETUP_CSRF_COOKIE, $csrf, ['path' => '/setup.php', 'httponly' => true, 'samesite' => 'Strict', 'secure' => isHttpsRequest()]);

    $body = '';
    foreach ($errors as $error) {
        $body .= '<p class="notice error" role="alert">' . e($error) . '</p>';
    }
    $results = '';
    foreach ($testResults as [$level, $message]) {
        $results .= '<li class="check ' . e($level) . '">' . e($message) . '</li>';
    }
    $origin = preg_match('~^https?://[^/]+$~', $form['base_url']) ? $form['base_url'] : 'https://yt.example.com';

    $body .= '<p>Step 1 of 2: connect the PostgreSQL database and the Google sign-in. Everything is stored in <code>' . e(CONFIG_FILE) . '</code>.</p>'
        . '<form method="post" action="/setup.php' . ($token !== '' ? '?token=' . e(rawurlencode($token)) : '') . '">'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="token" value="' . e($token) . '">'
        . '<h2>PostgreSQL</h2>'
        . '<div class="row"><label>Host<input name="db_host" value="' . e($form['db_host']) . '" required></label>'
        . '<label class="small">Port<input name="db_port" value="' . e($form['db_port']) . '" inputmode="numeric" required></label></div>'
        . '<label>Database name<input name="db_name" value="' . e($form['db_name']) . '" required><span class="hint">The database must exist; tables are created automatically.</span></label>'
        . '<div class="row"><label>User<input name="db_user" value="' . e($form['db_user']) . '" required autocomplete="off"></label>'
        . '<label>Password<input type="password" name="db_password" value="' . e($form['db_password']) . '"'
        . ($hasConfig ? ' placeholder="unchanged"' : '') . ' autocomplete="new-password"></label></div>'
        . ($results !== '' ? '<ul class="checks">' . $results . '</ul>' : '')
        . '<div class="actions"><button type="submit" name="intent" value="test" class="secondary" formnovalidate>Test connection</button></div>'
        . '<h2>Google sign-in</h2>'
        . '<p class="hint">Only the client ID is needed: the browser receives a signed ID token from Google, and the server verifies it with Google\'s public keys.</p>'
        . '<ol><li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud console → APIs &amp; Services → Credentials</a>.</li>'
        . '<li>Configure the OAuth consent screen. In testing mode, add the Google accounts that may sign in as test users.</li>'
        . '<li>Create credentials → OAuth client ID → <strong>Web application</strong>, and add the <strong>authorized JavaScript origin</strong> <code>' . e($origin) . '</code>. '
        . 'For local testing on localhost, add both <code>http://localhost</code> and <code>http://localhost:PORT</code>. No redirect URI and no client secret are used.</li></ol>'
        . '<label>Public URL of this site<input name="base_url" value="' . e($form['base_url']) . '" required><span class="hint">How users open YT Archiver, e.g. https://yt.example.com. It must be the JavaScript origin registered above; Google allows plain http only for localhost.</span></label>'
        . '<label>Client ID<input name="client_id" value="' . e($form['client_id']) . '" required autocomplete="off"></label>'
        . '<div class="actions">' . ($token !== '' ? '<a class="button secondary" href="/setup.php?token=' . e(rawurlencode($token)) . '">Back</a>' : '')
        . '<button type="submit" name="intent" value="save">Check and save</button></div></form>';

    renderAuthPage('Setup', $body, true);
}

/**
 * Check the database in the order problems usually appear: network, sign-in (credentials, database name), permissions.
 * @return list<array{0: 'success'|'error', 1: string}>
 */
function testDatabase(string $host, int $port, string $dbname, string $user, string $password): array {
    $socket = @fsockopen($host, $port, $errno, $errorMessage, 5);
    if ($socket === false) {
        return [['error', "Cannot reach $host:$port" . ($errorMessage ? " ($errorMessage)" : '') . '. Check the host, the port and that PostgreSQL accepts TCP connections.']];
    }
    fclose($socket);
    $results = [['success', "The server $host:$port is reachable."]];

    try {
        $pdo = connectDatabase(pgsqlDsn($host, $port, $dbname), $user, $password);
    } catch (Throwable $e) {
        $results[] = ['error', 'Sign-in to the database failed: ' . cleanDatabaseError($e)];
        return $results;
    }

    try {
        $info = $pdo->query("SELECT current_user AS db_user, current_setting('server_version') AS version,
            has_schema_privilege('public', 'CREATE') AS can_create, to_regclass('public.users') IS NOT NULL AS has_users")->fetch();
        $results[] = ['success', "Signed in as \"{$info['db_user']}\" to database \"$dbname\" (PostgreSQL {$info['version']})."];
        if ($info['has_users']) {
            $results[] = ['success', 'The tables already exist from an earlier installation and will be reused.'];
        } elseif ($info['can_create']) {
            $results[] = ['success', 'The user may create the tables.'];
        } else {
            $results[] = ['error', "The user may not create tables in schema public. Run as the database owner: GRANT CREATE ON SCHEMA public TO \"$user\";"];
        }
    } catch (Throwable $e) {
        $results[] = ['error', 'The permission check failed: ' . cleanDatabaseError($e)];
    }
    return $results;
}

/** PDO messages without the SQLSTATE prefix, e.g. 'FATAL: password authentication failed for user "x"'. */
function cleanDatabaseError(Throwable $e): string {
    $message = preg_replace('/^SQLSTATE\[\w+\]\s*(?:\[\d+\]\s*)?/', '', $e->getMessage()) ?? $e->getMessage();
    return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 300);
}

function signInStep(array $config, string $token, ?string $flashMessage): void {
    $db = parseDsn((string)($config['db']['dsn'] ?? ''));
    $body = $flashMessage !== null ? '<p class="notice error" role="alert">' . e($flashMessage) . '</p>' : '';
    $body .= '<p class="notice success">The database is connected and its tables are ready.</p>'
        . '<p>Step 2 of 2: sign in with Google. This tests the Google configuration, and <strong>the account you sign in with becomes the administrator</strong>. '
        . 'Everyone who signs in later has to be approved by an administrator.</p>'
        . googleSignInWidget(true)
        . '<p class="footer">Database <code>' . e(($db['dbname'] ?? '?') . ' @ ' . ($db['host'] ?? '?') . ':' . ($db['port'] ?? '?')) . '</code><br>'
        . 'JavaScript origin <code>' . e(baseUrl()) . '</code> · Client ID <code>' . e($config['google']['client_id'] ?? '') . '</code><br>'
        . 'The button does not appear or Google reports an error? Check that the origin above is registered for this client ID.<br>'
        . '<a href="/setup.php?token=' . e(rawurlencode($token)) . '&amp;edit=1">Change settings</a></p>';
    renderAuthPage('Setup', $body, true);
}

function parseDsn(string $dsn): array {
    preg_match_all('/(host|port|dbname)=([^;]*)/', $dsn, $matches, PREG_SET_ORDER);
    return array_column($matches, 2, 1);
}

function detectedBaseUrl(): string {
    $host = preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $_SERVER['HTTP_HOST'] ?? '') ? $_SERVER['HTTP_HOST'] : 'localhost';
    return (isHttpsRequest() ? 'https://' : 'http://') . $host;
}

// ── Command line ─────────────────────────────────────────

function setupCli(): int {
    $options = getopt('', ['status', 'migrate', 'make-admin:', 'help']);
    $say = fn(string $line) => fwrite(STDOUT, $line . PHP_EOL);

    try {
        if (isset($options['help']) || !$options) {
            $say('Usage: php setup.php --status | --migrate | --make-admin=EMAIL');
            return isset($options['help']) ? 0 : 1;
        }

        if (isset($options['status'])) {
            if (!is_file(CONFIG_FILE)) {
                $say('Not configured: open /setup.php in the browser.');
                return 0;
            }
            $config = appConfig();
            if (!isInstalled()) {
                $say('Setup started but not finished. Continue at:');
                $say(baseUrl() . '/setup.php?token=' . ($config['setup_token'] ?? ''));
                return 0;
            }
            $counts = dbAll('SELECT status, count(*) AS n FROM users GROUP BY status ORDER BY status');
            $say('Installed ' . ($config['installed_at'] ?? '') . ', public URL ' . baseUrl());
            $say('Users: ' . (implode(', ', array_map(fn($row) => $row['status'] . ' ' . $row['n'], $counts)) ?: 'none'));
            return 0;
        }

        if (!is_file(CONFIG_FILE)) {
            $say('Not configured yet: nothing to do.');
            return isset($options['migrate']) ? 0 : 1;
        }

        if (isset($options['migrate'])) {
            $applied = migrateDatabase();
            $say($applied ? 'Applied migrations: ' . implode(', ', $applied) : 'Database is up to date.');
        }

        if (isset($options['make-admin'])) {
            $email = mb_strtolower(trim((string)$options['make-admin']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $say("Not a valid email address: $email");
                return 1;
            }
            $count = dbExec("UPDATE users SET role = 'admin', status = 'approved', approved_at = coalesce(approved_at, now()) WHERE lower(email) = lower(?)", [$email]);
            if ($count === 0) {
                // No account yet: create one that the first Google sign-in with this verified email claims
                dbExec("INSERT INTO users (google_sub, email, role, status, approved_at) VALUES (NULL, ?, 'admin', 'approved', now())", [$email]);
                $say("Created an administrator account for $email; it is linked on the first Google sign-in with that address.");
            }
            if (!isInstalled()) {
                $config = appConfig();
                $config['installed'] = true;
                $config['installed_at'] = gmdate('c');
                unset($config['setup_token']);
                saveConfig($config);
            }
            $say("$email is now an approved administrator.");
        }
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }
}
