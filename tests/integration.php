<?php
/**
 * End-to-end test over HTTP: dev/serve.php (router + API + dispatcher) → worker → fake yt-dlp,
 * including setup.php, Google sign-in (fake provider), account approval and blocking.
 *
 * Needs no Docker, network, real yt-dlp or Google account. It starts a throwaway PostgreSQL cluster
 * (initdb; binaries found automatically or via YTA_TEST_PG_BIN) and deletes it afterwards.
 * Without PostgreSQL the test is skipped (set YTA_TEST_REQUIRE_PG=1 to fail instead). Takes about a minute.
 *
 * Run with PHP >= 8.0 and pdo_pgsql:  php tests/integration.php
 */

error_reporting(E_ALL);

$root = dirname(__DIR__);
$dataDir = str_replace('\\', '/', sys_get_temp_dir()) . '/yt-archiver-it-' . getmypid();
$stateDir = "$dataDir/fake-state";
$pgDir = str_replace('\\', '/', sys_get_temp_dir()) . '/yt-archiver-pg-' . getmypid();
$envDataDir = "$dataDir-env"; // second installation, with the database from YTA_DB_* (docker-compose.yml)
mkdir($stateDir, 0755, true);
mkdir($pgDir, 0755, true);
define('DATA_DIR', $dataDir);
require __DIR__ . '/../public/includes/common.php';
require __DIR__ . '/support/postgres.php';

// ── Helpers ──────────────────────────────────────────────

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? "\n  $detail" : '') . "\n";
    } else {
        echo "ok   $name\n";
    }
}

// Cookie jars simulate separate browsers ("admin", "bob", ...); $jar selects the active one
$jars = [];
$jar = 'admin';

/**
 * One HTTP request without following redirects. $path is relative to the app, or an absolute URL (cookies are
 * only sent to the app). $body: array → JSON, ['form' => [...]] → form-encoded.
 */
function http(string $method, string $path, ?array $body = null, bool $jsonContentType = true): array {
    global $baseUrl, $jars, $jar;
    $absolute = (bool)preg_match('#^https?://#', $path);
    $isApp = !$absolute || str_starts_with($path, $baseUrl);
    $url = $absolute ? $path : $baseUrl . $path;

    $headers = [];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0];
    if ($body !== null) {
        if (isset($body['form'])) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($body['form']);
        } else {
            $headers[] = 'Content-Type: ' . ($jsonContentType ? 'application/json' : 'text/plain');
            $options['content'] = json_encode($body);
        }
    }
    if ($isApp && !empty($jars[$jar])) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jars[$jar]), $jars[$jar]));
    }
    $options['header'] = implode("\r\n", $headers);

    $responseBody = @file_get_contents($url, false, stream_context_create(['http' => $options]));
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
    preg_match('#^HTTP/\S+ (\d+)#', $responseHeaders[0] ?? '', $m);

    $location = null;
    foreach ($responseHeaders ?? [] as $header) {
        if (preg_match('/^Location:\s*(.+)$/i', $header, $lm)) {
            $location = trim($lm[1]);
        }
        if ($isApp && preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)(.*)$/i', $header, $cm)) {
            $expired = $cm[2] === '' || $cm[2] === 'deleted' || preg_match('/expires=[^;]*19\d\d|max-age=0/i', $cm[3]);
            if ($expired) {
                unset($jars[$jar][$cm[1]]);
            } else {
                $jars[$jar][$cm[1]] = $cm[2];
            }
        }
    }
    return ['status' => (int)($m[1] ?? 0), 'body' => (string)$responseBody, 'json' => json_decode((string)$responseBody, true), 'location' => $location];
}

function waitFor(callable $condition, float $timeout): bool {
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($condition()) {
            return true;
        }
        usleep(200000);
    }
    return false;
}

function status(): array {
    return http('GET', '/api.php?action=status')['json'] ?? [];
}

function isIdle(): bool {
    $status = status();
    return array_key_exists('current', $status) && $status['current'] === null && $status['queue'] === [];
}

function library(): array {
    return http('GET', '/api.php?action=videos')['json']['videos'] ?? [];
}

function processExists(int $pid): bool {
    if (IS_WINDOWS) {
        exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH", $lines);
        return str_contains(implode('', $lines), "\"$pid\"");
    }
    exec("ps -p $pid -o pid= 2>/dev/null", $lines);
    return trim(implode('', $lines)) !== '';
}

function fakePid(string $videoId): ?int {
    global $stateDir;
    $file = "$stateDir/$videoId.pid";
    return is_file($file) ? (int)file_get_contents($file) : null;
}

/** GET and follow redirects like a browser does for background requests (e.g. /favicon.ico). Returns the final response. */
function followRedirects(string $path, int $limit = 5): array {
    $response = http('GET', $path);
    while ($limit-- > 0 && $response['location'] !== null) {
        $response = http('GET', $response['location']);
    }
    return $response;
}

/** Hidden input value from an HTML form. */
function formValue(string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

function dataAttribute(string $html, string $name): string {
    return preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

function b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * An ID token as Google would issue it, signed with the throwaway fixture key that tests/fixtures/fake-google.php
 * publishes. $tamper changes the payload after signing (invalid signature).
 */
function googleIdToken(array $claims, bool $tamper = false): string {
    $header = b64u(json_encode(['alg' => 'RS256', 'kid' => 'test-key-1', 'typ' => 'JWT']));
    $payload = b64u(json_encode($claims));
    openssl_sign("$header.$payload", $signature, file_get_contents(__DIR__ . '/fixtures/google-test-key.pem'), OPENSSL_ALGO_SHA256);
    if ($tamper) {
        $payload = b64u(json_encode(['email' => 'attacker@example.com'] + $claims));
    }
    return "$header.$payload." . b64u($signature);
}

/**
 * Do what the "Sign in with Google" button does: read the client ID, nonce and CSRF token from $page, obtain an ID
 * token for $email (claims overridable) and post it to /login.php?action=google. Returns where login.php redirects.
 */
function signInWithGoogle(string $email, string $page = '/login.php', array $claims = [], bool $tamper = false): ?string {
    $html = http('GET', $page)['body'];
    $nonce = dataAttribute($html, 'data-nonce');
    if ($nonce === '') {
        return "no sign-in button on $page";
    }
    $token = googleIdToken(array_merge([
        'iss'            => 'https://accounts.google.com',
        'aud'            => dataAttribute($html, 'data-client-id'),
        'azp'            => dataAttribute($html, 'data-client-id'),
        'sub'            => 'sub-' . sha1(strtolower($email)),
        'email'          => $email,
        'email_verified' => true,
        'name'           => ucfirst(strtok($email, '@')) . ' Tester',
        'picture'        => 'https://lh3.googleusercontent.com/a/fake',
        'nonce'          => $nonce,
        'iat'            => time(),
        'exp'            => time() + 3600,
    ], $claims), $tamper);
    return http('POST', '/login.php?action=google', ['form' => ['csrf' => formValue($html, 'csrf'), 'credential' => $token]])['location'];
}

function startProcess(array $cmd, string $log, array $env = []) {
    global $root;
    return proc_open($cmd, [0 => ['file', NULL_DEVICE, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes, $root, array_merge(getenv(), $env), ['bypass_shell' => true]);
}

function stopProcess($process): void {
    if (!is_resource($process) || !proc_get_status($process)['running']) {
        return;
    }
    $pid = proc_get_status($process)['pid'];
    IS_WINDOWS ? exec("taskkill /PID $pid /T /F 2>NUL") : proc_terminate($process, 9);
}

// ── Dev server ───────────────────────────────────────────

$serverLog = "$dataDir/serve-output.log";

/** Start dev/serve.php on a free port; sets $baseUrl. */
function startDevServer(array $extraEnv) {
    global $root, $dataDir, $stateDir, $serverLog, $baseUrl;
    $baseUrl = 'http://127.0.0.1:' . freeTcpPort();
    $serve = startProcess(
        [PHP_BINARY, "$root/dev/serve.php", '--port=' . parse_url($baseUrl, PHP_URL_PORT), "--data=$dataDir", '--no-tools'],
        $serverLog,
        ['YTA_YTDLP_BIN' => '"' . PHP_BINARY . '" "' . $root . '/tests/fixtures/fake-yt-dlp.php"', 'FAKE_YTDLP_STATE_DIR' => $stateDir] + $extraEnv
    );
    check('dev server starts', waitFor(fn() => in_array(http('GET', '/login.php')['status'], [200, 302, 303], true), 15), (string)@file_get_contents($serverLog));
    return $serve;
}

function stopDevServer($serve): void {
    global $dataDir;
    if (!is_resource($serve)) {
        return;
    }
    touch("$dataDir/.dev-stop");
    $stopped = waitFor(fn() => !proc_get_status($serve)['running'], 15);
    if (!$stopped) {
        stopProcess($serve);
    }
    check('dev server stops cleanly', $stopped);
}

// ── PostgreSQL and fake Google ───────────────────────────

$postgres = startTemporaryPostgres($pgDir);
if (is_string($postgres)) {
    echo "SKIPPED: no PostgreSQL for the integration test ($postgres)\n";
    removeDir($dataDir);
    removeDir($pgDir);
    exit(getenv('YTA_TEST_REQUIRE_PG') === '1' ? 1 : 0);
}

$googleUrl = 'http://127.0.0.1:' . freeTcpPort();
$google = startProcess([PHP_BINARY, '-S', substr($googleUrl, 7), "$root/tests/fixtures/fake-google.php"], "$dataDir/fake-google.log");

// Sizes in the fake yt-dlp are tiny: a 1 MB "large download" threshold and no free-space minimum
// keep the results independent of the disk the tests run on
$serve = null;
try {
    $serve = startDevServer(['LARGE_DOWNLOAD_WARNING_GB' => '0.001', 'MIN_FREE_SPACE_PERCENT' => '0']);
    waitFor(fn() => @fsockopen('127.0.0.1', (int)parse_url($googleUrl, PHP_URL_PORT)) !== false, 10);

    // ── Before the installation: only setup ──────────────
    $jar = 'admin';
    check('home redirects to sign-in before setup', http('GET', '/')['location'] === '/login.php');
    check('sign-in redirects to setup', http('GET', '/login.php')['location'] === '/setup.php');
    $api = http('GET', '/api.php?action=status');
    check('API refuses anonymous requests', $api['status'] === 401 && ($api['json']['login_required'] ?? false) === true, $api['body']);
    check('static files need sign-in', http('GET', '/js/app.js')['status'] === 302 && http('GET', '/download/x.mp3')['status'] === 302);

    $page = http('GET', '/setup.php');
    check('setup form is shown', $page['status'] === 200 && str_contains($page['body'], 'Step 1 of 2'), $page['body']);
    check('setup asks for no client secret', !str_contains($page['body'], 'client_secret') && str_contains($page['body'], 'Test connection'));
    $setupForm = [
        'token' => '',
        'db_host' => $postgres['host'], 'db_port' => (string)$postgres['port'], 'db_name' => $postgres['database'],
        'db_user' => $postgres['user'], 'db_password' => $postgres['password'],
        'client_id' => '1234567890-test.apps.googleusercontent.com', 'base_url' => $baseUrl,
    ];
    $postSetup = function (array $fields) use (&$setupForm) {
        $page = http('GET', '/setup.php');
        return http('POST', '/setup.php', ['form' => array_merge($setupForm, ['csrf' => formValue($page['body'], 'csrf')], $fields)]);
    };

    $response = http('POST', '/setup.php', ['form' => ['csrf' => 'forged'] + $setupForm]);
    check('setup rejects a forged form', $response['status'] === 200 && str_contains($response['body'], 'form expired') && !is_file("$dataDir/config.php"));
    $response = $postSetup(['db_port' => '99999']);
    check('setup validates input', str_contains($response['body'], 'Database port must be') && !is_file("$dataDir/config.php"));

    // Browsers request /favicon.ico in the background; its redirect chain ends on /setup.php and must not expire the open form
    $page = http('GET', '/setup.php');
    check('favicon request ends on the setup page', str_contains(followRedirects('/favicon.ico')['body'], 'Step 1 of 2'));
    http('GET', '/setup.php'); // a second tab
    $response = http('POST', '/setup.php', ['form' => array_merge($setupForm, ['csrf' => formValue($page['body'], 'csrf'), 'intent' => 'test'])]);
    check('background requests do not expire the setup form', str_contains($response['body'], 'is reachable') && !str_contains($response['body'], 'form expired'), $response['body']);
    check('pages declare an inline icon', str_contains($page['body'], 'rel="icon" href="data:'));

    // "Test connection" checks network, sign-in and permissions without saving anything
    $response = $postSetup(['intent' => 'test']);
    check('test connection succeeds', str_contains($response['body'], 'is reachable') && str_contains($response['body'], 'Signed in as &quot;postgres&quot;')
        && str_contains($response['body'], 'may create the tables') && !str_contains($response['body'], 'check error'), $response['body']);
    check('test connection saves nothing', $response['status'] === 200 && !is_file("$dataDir/config.php"));
    check('test connection keeps the typed values', formValue($response['body'], 'db_name') === $postgres['database']);
    $response = $postSetup(['intent' => 'test', 'db_port' => (string)freeTcpPort()]);
    check('test connection reports an unreachable server', str_contains($response['body'], 'Cannot reach'));
    $response = $postSetup(['intent' => 'test', 'db_name' => 'no_such_database']);
    check('test connection reports a missing database', str_contains($response['body'], 'Sign-in to the database failed') && str_contains($response['body'], 'no_such_database'));
    $response = $postSetup(['intent' => 'test', 'db_user' => 'no_such_role']);
    check('test connection reports failed authentication', str_contains($response['body'], 'Sign-in to the database failed') && str_contains($response['body'], 'no_such_role'));
    $response = $postSetup(['db_user' => 'no_such_role']);
    check('saving refuses a failing database', str_contains($response['body'], 'database check failed') && !is_file("$dataDir/config.php"));

    $response = $postSetup([]);
    $setupToken = preg_match('/token=([a-f0-9]+)/', (string)$response['location'], $m) ? $m[1] : '';
    check('setup saves the settings', $response['status'] === 303 && $setupToken !== '' && is_file("$dataDir/config.php"), $response['body']);

    // Point Google's certificate endpoint at the fake one (only possible by editing the config file)
    $config = include "$dataDir/config.php";
    check('config holds the client ID only', ($config['google'] ?? null) === ['client_id' => '1234567890-test.apps.googleusercontent.com'] && $config['installed'] === false, json_encode($config['google'] ?? null));
    $config['google']['certs_endpoint'] = "$googleUrl/certs";
    file_put_contents("$dataDir/config.php", '<?php return ' . var_export($config, true) . ';');

    check('setup needs the token once started', http('GET', '/setup.php')['status'] === 403);
    check('setup step 2 is shown with the token', str_contains(http('GET', "/setup.php?token=$setupToken")['body'], 'Step 2 of 2'));

    $jar = 'intruder';
    $target = signInWithGoogle('intruder@example.com', "/setup.php?token=wrong");
    check('setup sign-in needs the setup page', str_starts_with((string)$target, 'no sign-in button') && ($config = include "$dataDir/config.php")['installed'] === false, (string)$target);
    $jar = 'admin';

    $target = signInWithGoogle('owner@example.com', "/setup.php?token=$setupToken", [], true);
    check('forged token during setup returns to setup', $target === "/setup.php?token=$setupToken", (string)$target);
    check('setup shows the verification error', str_contains(http('GET', "/setup.php?token=$setupToken")['body'], 'could not be verified (signature)'));

    $target = signInWithGoogle('owner@example.com', "/setup.php?token=$setupToken");
    check('setup sign-in finishes the installation', $target === '/', (string)$target);
    $config = include "$dataDir/config.php";
    check('installation is recorded', ($config['installed'] ?? false) === true && !isset($config['setup_token']));
    check('setup is gone after the installation', http('GET', '/setup.php')['status'] === 404 && http('GET', "/setup.php?token=$setupToken")['status'] === 404);
    $me = http('GET', '/api.php?action=me')['json'] ?? [];
    check('setup account is an approved admin', ($me['user']['email'] ?? '') === 'owner@example.com'
        && ($me['user']['role'] ?? '') === 'admin' && ($me['user']['status'] ?? '') === 'approved' && strlen($me['csrf'] ?? '') === 64, json_encode($me));
    check('admin reaches the app', str_contains(http('GET', '/')['body'], 'id="downloadForm"'));
    check('admin reaches the users page', http('GET', '/users.html')['status'] === 200);

    // ── Router mirrors nginx rules ───────────────────────
    check('router denies includes', http('GET', '/includes/common.php')['status'] === 403);
    check('router denies worker script', http('GET', '/download_worker.php')['status'] === 403);
    check('router denies staging', http('GET', '/download/.staging/anything')['status'] === 403);
    check('SPA fallback', str_contains(http('GET', '/some/route')['body'], 'YT Archiver'));

    // ── API validation ───────────────────────────────────
    check('CSRF guard rejects non-JSON POST',
        http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/VIDEOVIDEO1'], false)['status'] === 415);
    check('invalid source rejected', http('POST', '/api.php?action=download', ['url' => 'https://example.com/x'])['status'] === 400);
    check('invalid format rejected',
        http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/VIDEOVIDEO1', 'format' => 'exe'])['status'] === 400);

    // ── Size check before downloading ────────────────────
    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://youtu.be/VIDEOVIDEO1', 'format' => 'mp3'])['json'] ?? [];
    check('inspect small video', ($check['success'] ?? false) === true && ($check['confirmation_required'] ?? null) === false
        && $check['blocked'] === null && ($check['inspection']['estimated_size'] ?? 0) === 19 * 30625, json_encode($check));
    check('inspect reports storage', ($check['storage']['total'] ?? 0) > 0 && array_key_exists('reserved', $check['storage'] ?? []));

    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://youtu.be/FAILFAILFAI', 'format' => 'mp3']);
    check('inspect unavailable video', $check['status'] === 422 && str_contains($check['json']['error'] ?? '', 'Private video'), $check['body']);

    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://youtu.be/HUGEHUGEHUG', 'format' => 'mp4'])['json'] ?? [];
    check('inspect large video uses stream sizes', ($check['inspection']['estimated_size'] ?? 0) === 4_000_000
        && ($check['confirmation_required'] ?? false) === true && count($check['reasons'] ?? []) === 1, json_encode($check));

    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://youtu.be/LIVELIVELIV', 'format' => 'mp3'])['json'] ?? [];
    check('inspect live stream needs confirmation', ($check['confirmation_required'] ?? false) === true
        && str_contains(implode(' ', $check['reasons'] ?? []), 'live stream'), json_encode($check));

    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://www.youtube.com/playlist?list=PLhuge1', 'format' => 'mp4'])['json'] ?? [];
    check('inspect playlist estimates from durations', ($check['inspection']['items'] ?? 0) === 2 && ($check['inspection']['unknown_durations'] ?? 0) === 1
        && ($check['inspection']['duration'] ?? 0) === 7200 && ($check['inspection']['estimated_size'] ?? 0) === 7200 * 400000
        && ($check['confirmation_required'] ?? false) === true, json_encode($check));

    $response = http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/HUGEHUGEHUG', 'format' => 'mp4']);
    check('server refuses unconfirmed large download', $response['status'] === 409 && ($response['json']['confirmation_required'] ?? false) === true, $response['body']);
    check('refused download is not queued', isIdle());

    // ── Single video ─────────────────────────────────────
    $response = http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/VIDEOVIDEO1', 'format' => 'mp3']);
    $videoJobId = $response['json']['id'] ?? '';
    check('video queued', ($response['json']['success'] ?? false) === true, $response['body']);
    check('video finishes', waitFor(fn() => isIdle() && array_filter(library(), fn($v) => $v['id'] === $videoJobId), 20));

    $video = array_values(array_filter(library(), fn($v) => $v['id'] === $videoJobId))[0] ?? [];
    check('video library entry', ($video['type'] ?? '') === 'audio' && str_ends_with($video['filename'] ?? '', '.mp3'), json_encode($video));
    check('video title sanitized', !preg_match('/["<>:]/', $video['title'] ?? '"'), $video['title'] ?? '');
    $file = http('GET', '/download/' . rawurlencode($video['filename'] ?? 'x'));
    check('video downloadable', $file['status'] === 200 && $file['body'] === 'fake mp3 content for VIDEOVIDEO1', $file['body']);
    check('video staging cleaned', !is_dir(STAGING_DIR . '/' . $videoJobId));

    // ── Playlist → items in queue → one ZIP in library ───
    $response = http('POST', '/api.php?action=download', ['url' => 'https://www.youtube.com/playlist?list=PLtest123', 'format' => 'mp3']);
    $playlistId = $response['json']['id'] ?? '';
    check('playlist queued', ($response['json']['type'] ?? '') === 'playlist', $response['body']);

    $seenTypes = [];
    $done = waitFor(function () use (&$seenTypes, $playlistId) {
        $status = status();
        foreach (array_merge([$status['current'] ?? null], $status['queue'] ?? []) as $job) {
            if ($job && ($job['playlist_id'] ?? null) === $playlistId) {
                $seenTypes[$job['type']] = true;
            }
        }
        return isIdle() && array_filter(library(), fn($v) => $v['id'] === $playlistId);
    }, 30);
    check('playlist finishes', $done);
    check('playlist items shown in queue', isset($seenTypes['playlist_item'], $seenTypes['archive']), json_encode(array_keys($seenTypes)));

    $archive = array_values(array_filter(library(), fn($v) => $v['id'] === $playlistId))[0] ?? [];
    check('only the ZIP is in the library', count(library()) === 2, json_encode(library()));
    check('archive entry', ($archive['type'] ?? '') === 'playlist' && ($archive['format'] ?? '') === 'zip'
        && ($archive['content_format'] ?? '') === 'mp3', json_encode($archive));
    check('archive counts skip private and failed items', ($archive['items'] ?? 0) === 2 && ($archive['total'] ?? 0) === 3, json_encode($archive));

    $zip = http('GET', '/download/' . rawurlencode($archive['filename'] ?? 'x'))['body'];
    check('archive is a zip', str_starts_with($zip, "PK\x03\x04"));
    check('archive entries numbered in playlist order',
        str_contains($zip, '01 - First song b.mp3') && str_contains($zip, '02 - Druhá pieseň.mp3') && !str_contains($zip, 'Broken'));
    check('playlist staging cleaned', !is_dir(playlistDir($playlistId)));

    // ── Cancel a running download ────────────────────────
    $response = http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/SLOWSLOWSL0', 'format' => 'mp4']);
    $slowJobId = $response['json']['id'] ?? '';
    check('slow download started', waitFor(fn() => fakePid('SLOWSLOWSL0') !== null, 15));
    check('running download reserves its estimated size', (status()['storage']['reserved'] ?? 0) === 250_000, json_encode(status()['storage'] ?? null));
    $cancel = http('POST', '/api.php?action=cancel', ['id' => $slowJobId]);
    check('cancel succeeds', ($cancel['json']['success'] ?? false) === true, $cancel['body']);
    check('cancel stops yt-dlp', waitFor(fn() => !processExists((int)fakePid('SLOWSLOWSL0')), 5));
    check('cancel clears queue', isIdle());
    check('cancel removes work dir', waitFor(fn() => !is_dir(STAGING_DIR . '/' . $slowJobId), 5));

    // ── Cancel a whole playlist ──────────────────────────
    $response = http('POST', '/api.php?action=download', ['url' => 'https://www.youtube.com/playlist?list=PLslow1', 'format' => 'mp3']);
    $slowPlaylistId = $response['json']['id'] ?? '';
    check('slow playlist item started', waitFor(fn() => fakePid('SLOWSLOWSL1') !== null, 15));
    $cancel = http('POST', '/api.php?action=cancel', ['playlist_id' => $slowPlaylistId]);
    check('playlist cancel succeeds', ($cancel['json']['message'] ?? '') === 'Playlist cancelled', $cancel['body']);
    check('playlist cancel stops yt-dlp', waitFor(fn() => !processExists((int)fakePid('SLOWSLOWSL1')), 5));
    check('playlist cancel clears queue', isIdle(), json_encode(status()));
    check('playlist cancel removes staging', waitFor(fn() => !is_dir(playlistDir($slowPlaylistId)), 5));
    check('cancelled items never reach the library', count(library()) === 2);

    // ── Confirmed large download ─────────────────────────
    $response = http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/HUGEHUGEHUG', 'format' => 'mp4', 'confirmed' => true]);
    check('confirmed large download is queued', ($response['json']['success'] ?? false) === true, $response['body']);
    check('confirmed large download finishes', waitFor(fn() => isIdle() && count(library()) === 3, 20));
    $hugeDelete = http('DELETE', '/api.php?action=videos&id=' . rawurlencode($response['json']['id'] ?? ''));
    check('large download removed again', ($hugeDelete['json']['success'] ?? false) === true && count(library()) === 2);

    // ── Delete and logs ──────────────────────────────────
    $delete = http('DELETE', '/api.php?action=videos&id=' . rawurlencode($videoJobId));
    check('delete succeeds', ($delete['json']['success'] ?? false) === true, $delete['body']);
    check('delete removes file', !is_file(VIDEOS_DIR . '/' . ($video['filename'] ?? 'x')) && count(library()) === 1);
    check('logs record requests', (http('GET', '/api.php?action=logs')['json']['total'] ?? 0) > 0);
    $logs = http('GET', '/api.php?action=logs&search=owner%40example.com')['json'] ?? [];
    check('logs record the user', ($logs['total'] ?? 0) > 0 && ($logs['logs'][0]['user'] ?? '') === 'owner@example.com', json_encode($logs['logs'][0] ?? null));

    // ── Other accounts need approval ─────────────────────
    $jar = 'bob';
    $target = signInWithGoogle('bob@example.com');
    check('new account lands on the pending page', $target === '/login.php', (string)$target);
    check('pending page explains the approval', str_contains(http('GET', '/login.php')['body'], 'waiting for approval'));
    check('pending account cannot use the API', http('GET', '/api.php?action=status')['status'] === 401);
    check('pending account cannot open the app', http('GET', '/')['location'] === '/login.php');
    check('pending account cannot download', http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/VIDEOVIDEO1', 'format' => 'mp3'])['status'] === 401);

    $jar = 'admin';
    check('admin sees the pending count', (http('GET', '/api.php?action=me')['json']['pending_users'] ?? 0) === 1);
    $users = http('GET', '/api.php?action=users')['json']['users'] ?? [];
    $bob = array_values(array_filter($users, fn($u) => $u['email'] === 'bob@example.com'))[0] ?? [];
    $owner = array_values(array_filter($users, fn($u) => $u['email'] === 'owner@example.com'))[0] ?? [];
    check('admin lists the pending account first', ($users[0]['email'] ?? '') === 'bob@example.com' && ($bob['status'] ?? '') === 'pending', json_encode($users));
    check('admin cannot change their own account', http('POST', '/api.php?action=user', ['id' => $owner['id'] ?? 0, 'operation' => 'disable'])['status'] === 400);
    check('accounts cannot be deleted', http('POST', '/api.php?action=user', ['id' => $bob['id'] ?? 0, 'operation' => 'delete'])['status'] === 400);
    check('pending account cannot become admin', http('POST', '/api.php?action=user', ['id' => $bob['id'] ?? 0, 'operation' => 'make_admin'])['status'] === 400);
    check('admin approves the account', (http('POST', '/api.php?action=user', ['id' => $bob['id'] ?? 0, 'operation' => 'approve'])['json']['user']['status'] ?? '') === 'approved');

    $jar = 'bob';
    check('approved account can use the app', http('GET', '/api.php?action=status')['status'] === 200 && http('GET', '/')['status'] === 200);
    check('approved account may delete from the library', http('DELETE', '/api.php?action=videos&id=nonexistent')['status'] === 200);
    check('regular account cannot open admin pages', http('GET', '/users.html')['status'] === 403 && http('GET', '/logs.html')['status'] === 403);
    check('regular account cannot use admin actions', http('GET', '/api.php?action=users')['status'] === 403
        && http('POST', '/api.php?action=update', [])['status'] === 403 && http('GET', '/api.php?action=logs')['status'] === 403);
    $response = http('POST', '/login.php?action=logout', ['form' => ['csrf' => 'forged']]);
    check('sign-out needs the CSRF token', $response['status'] === 303 && http('GET', '/api.php?action=status')['status'] === 200);

    $jar = 'admin';
    check('admin disables the account', (http('POST', '/api.php?action=user', ['id' => $bob['id'] ?? 0, 'operation' => 'disable'])['json']['user']['status'] ?? '') === 'disabled');
    $jar = 'bob';
    check('disabled account loses access immediately', http('GET', '/api.php?action=status')['status'] === 401);
    $jars['bob'] = [];
    $target = signInWithGoogle('bob@example.com');
    $page = http('GET', (string)$target);
    check('disabled account cannot sign in again', $target === '/login.php' && str_contains($page['body'], 'has been disabled')
        && http('GET', '/api.php?action=status')['status'] === 401, $page['body']);
    $jar = 'admin';
    check('admin enables the account again', (http('POST', '/api.php?action=user', ['id' => $bob['id'] ?? 0, 'operation' => 'enable'])['json']['user']['status'] ?? '') === 'approved');
    $jar = 'bob';
    check('enabled account can sign in again', signInWithGoogle('bob@example.com') === '/' && http('GET', '/api.php?action=status')['status'] === 200);

    // ── Forged or invalid ID tokens ──────────────────────
    $jar = 'eve';
    $refused = function (string $name, ?string $target, string $message) {
        $body = http('GET', '/login.php')['body'];
        check($name, $target === '/login.php' && str_contains($body, $message) && http('GET', '/api.php?action=status')['status'] === 401, (string)$target . ' ' . $body);
    };
    $refused('unverified Google email is refused', signInWithGoogle('eve@example.com', '/login.php', ['email_verified' => false]), 'not verified');
    $refused('tampered token is refused', signInWithGoogle('eve@example.com', '/login.php', [], true), '(signature)');
    $refused('token for another client is refused', signInWithGoogle('eve@example.com', '/login.php', ['aud' => 'other.apps.googleusercontent.com', 'azp' => 'other']), '(audience)');
    $refused('expired token is refused', signInWithGoogle('eve@example.com', '/login.php', ['exp' => time() - 600]), '(expiry)');
    $refused('token from another issuer is refused', signInWithGoogle('eve@example.com', '/login.php', ['iss' => 'https://evil.example']), '(issuer)');
    $refused('token with a foreign nonce is refused', signInWithGoogle('eve@example.com', '/login.php', ['nonce' => 'replayed-from-elsewhere']), 'another tab');

    $validForm = function (): array {
        $html = http('GET', '/login.php')['body'];
        return ['csrf' => formValue($html, 'csrf'), 'credential' => googleIdToken([
            'iss' => 'https://accounts.google.com', 'aud' => dataAttribute($html, 'data-client-id'), 'sub' => 'sub-eve',
            'email' => 'eve@example.com', 'email_verified' => true, 'nonce' => dataAttribute($html, 'data-nonce'), 'exp' => time() + 600,
        ])];
    };
    $form = $validForm();
    $refused('token without the CSRF token is refused', http('POST', '/login.php?action=google', ['form' => ['credential' => $form['credential']]])['location'], 'form expired');
    $form = $validForm();
    // The sign-in page rendered again meanwhile (favicon request redirected to it, a second tab) must not invalidate the nonce
    followRedirects('/favicon.ico');
    http('GET', '/login.php');
    check('valid token signs in even after the page was rendered again', http('POST', '/login.php?action=google', ['form' => $form])['location'] === '/login.php');
    check('the new account is pending', str_contains(http('GET', '/login.php')['body'], 'waiting for approval'));
    // Same token again, with the session's current CSRF token, so only the used-up nonce can stop it
    $form['csrf'] = formValue(http('GET', '/login.php')['body'], 'csrf');
    $replay = http('POST', '/login.php?action=google', ['form' => $form])['location'];
    check('replayed token is refused (single-use nonce)', $replay === '/login.php' && str_contains(http('GET', '/login.php')['body'], 'The sign-in expired. Please try again.'));

    $jar = 'admin';
    check('only valid sign-ins create accounts', count(http('GET', '/api.php?action=users')['json']['users'] ?? []) === 3);

    // ── Accounts added in advance ────────────────────────
    $create = fn(array $body) => http('POST', '/api.php?action=create_user', $body);
    $userByEmail = fn(string $email) => array_values(array_filter(http('GET', '/api.php?action=users')['json']['users'] ?? [], fn($u) => $u['email'] === $email))[0] ?? [];

    $response = $create(['email' => ' Dave@Example.com ', 'role' => 'user', 'approved' => true]);
    check('admin adds an approved account', ($response['json']['user']['status'] ?? '') === 'approved' && ($response['json']['user']['email'] ?? '') === 'dave@example.com', $response['body']);
    $dave = $userByEmail('dave@example.com');
    check('added account is marked as not signed in yet', ($dave['invited'] ?? false) === true && ($dave['created_by'] ?? '') === 'owner@example.com' && ($dave['approved_by'] ?? '') === 'owner@example.com', json_encode($dave));
    check('adding an existing email is refused', $create(['email' => 'bob@example.com', 'role' => 'user', 'approved' => true])['status'] === 409
        && $create(['email' => 'DAVE@example.com', 'role' => 'user', 'approved' => false])['status'] === 409);
    check('adding validates the input', $create(['email' => 'not-an-email', 'role' => 'user', 'approved' => true])['status'] === 400
        && $create(['email' => 'x@example.com', 'role' => 'owner', 'approved' => true])['status'] === 400
        && $create(['email' => 'x@example.com', 'role' => 'admin', 'approved' => false])['status'] === 400);
    check('admin adds an administrator and a pending account', ($create(['email' => 'frank@example.com', 'role' => 'admin', 'approved' => true])['json']['user']['role'] ?? '') === 'admin'
        && ($create(['email' => 'gina@example.com', 'role' => 'user', 'approved' => false])['json']['user']['status'] ?? '') === 'pending');
    check('added pending accounts are not counted as waiting', (http('GET', '/api.php?action=me')['json']['pending_users'] ?? -1) === 1);
    $create(['email' => 'ivan@example.com', 'role' => 'user', 'approved' => true]);
    $jar = 'bob';
    check('regular account cannot add accounts', $create(['email' => 'y@example.com', 'role' => 'user', 'approved' => true])['status'] === 403);

    $jar = 'dave';
    check('added account signs in without waiting for approval', signInWithGoogle('dave@example.com') === '/' && http('GET', '/api.php?action=status')['status'] === 200);
    $jar = 'frank';
    check('added administrator signs in as admin (email case ignored)', signInWithGoogle('Frank@Example.COM') === '/' && http('GET', '/api.php?action=users')['status'] === 200);
    $jar = 'gina';
    check('added pending account waits for approval', signInWithGoogle('gina@example.com') === '/login.php' && http('GET', '/api.php?action=status')['status'] === 401);
    $jar = 'ivan';
    $target = signInWithGoogle('ivan@example.com', '/login.php', ['email_verified' => false]);
    $jar = 'admin';
    check('unverified email cannot claim an added account', $target === '/login.php' && ($userByEmail('ivan@example.com')['invited'] ?? false) === true);

    $dave = $userByEmail('dave@example.com');
    check('first sign-in links the added account', ($dave['invited'] ?? true) === false && ($dave['name'] ?? '') === 'Dave Tester' && ($dave['status'] ?? '') === 'approved', json_encode($dave));
    check('no duplicate accounts after linking', count(http('GET', '/api.php?action=users')['json']['users'] ?? []) === 7);
    check('pending count includes the signed-in pending account', (http('GET', '/api.php?action=me')['json']['pending_users'] ?? -1) === 2);

    // Recovery on the command line creates an administrator account for an email that has none
    putenv("YTA_DATA_DIR=$dataDir");
    $output = runCommand([PHP_BINARY, "$root/public/setup.php", '--make-admin=judy@example.com'], $exitCode);
    check('setup.php --make-admin creates a missing account', $exitCode === 0 && str_contains((string)$output, 'Created an administrator account'), (string)$output);
    $jar = 'judy';
    check('account from --make-admin signs in as admin', signInWithGoogle('judy@example.com') === '/' && http('GET', '/api.php?action=users')['status'] === 200);
    $jar = 'admin';

    stopDevServer($serve);

    // ── Low disk space blocks downloads ──────────────────
    // With a 100 % minimum, any real disk is "too full". Sessions survive the restart.
    $serve = startDevServer(['MIN_FREE_SPACE_PERCENT' => '100']);
    $storage = status()['storage'] ?? [];
    check('status reports the minimum', ($storage['min_free_percent'] ?? null) == 100 && ($storage['min_free'] ?? 0) === ($storage['total'] ?? -1), json_encode($storage));
    $check = http('POST', '/api.php?action=inspect', ['url' => 'https://youtu.be/VIDEOVIDEO2', 'format' => 'mp3'])['json'] ?? [];
    check('inspect reports blocked download', is_string($check['blocked'] ?? null) && ($check['confirmation_required'] ?? true) === false, json_encode($check));
    $response = http('POST', '/api.php?action=download', ['url' => 'https://youtu.be/VIDEOVIDEO2', 'format' => 'mp3', 'confirmed' => true]);
    check('server refuses download on low disk space even when confirmed', $response['status'] === 507, $response['body']);
    check('blocked download is not queued', isIdle());

    // ── Sign out ─────────────────────────────────────────
    $csrf = http('GET', '/api.php?action=me')['json']['csrf'] ?? '';
    $response = http('POST', '/login.php?action=logout', ['form' => ['csrf' => $csrf]]);
    check('sign-out works', $response['location'] === '/login.php' && http('GET', '/api.php?action=status')['status'] === 401
        && str_contains(http('GET', '/login.php')['body'], 'id="googleButton"'));

    // ── Database deployed with the app (YTA_DB_* environment, as docker-compose.yml sets it) ──
    // A fresh installation in its own data directory and database: step 1 asks only for Google, nothing about the database is saved
    stopDevServer($serve);
    $serve = null;
    (new PDO("pgsql:host={$postgres['host']};port={$postgres['port']};dbname=postgres", $postgres['user'], $postgres['password']))->exec('CREATE DATABASE yta_env');
    $dbEnv = ['YTA_DB_HOST' => $postgres['host'], 'YTA_DB_PORT' => (string)$postgres['port'], 'YTA_DB_NAME' => 'yta_env',
        'YTA_DB_USER' => $postgres['user'], 'YTA_DB_PASSWORD' => $postgres['password']];
    $mainDataDir = $dataDir;
    $dataDir = $envDataDir;
    mkdir($dataDir, 0755, true);
    $jar = 'env';
    try {
        $serve = startDevServer($dbEnv);
        $page = http('GET', '/setup.php');
        check('setup uses the database deployed with the app', str_contains($page['body'], 'database deployed with the app is used') && str_contains($page['body'], 'yta_env @ ')
            && !str_contains($page['body'], 'name="db_host"') && !str_contains($page['body'], 'name="db_password"'), $page['body']);
        $envForm = ['token' => '', 'csrf' => formValue($page['body'], 'csrf'), 'db_mode' => 'environment',
            'client_id' => '1234567890-test.apps.googleusercontent.com', 'base_url' => $baseUrl];
        $response = http('POST', '/setup.php', ['form' => $envForm + ['intent' => 'test']]);
        check('test connection checks the environment database', str_contains($response['body'], 'to database &quot;yta_env&quot;')
            && !str_contains($response['body'], 'check error') && !is_file("$dataDir/config.php"), $response['body']);

        $custom = http('GET', '/setup.php?db=custom');
        check('another server can still be entered by hand', str_contains($custom['body'], 'name="db_host"') && str_contains($custom['body'], 'Use the database deployed with the app'));
        $response = http('POST', '/setup.php', ['form' => array_merge($envForm, ['intent' => 'test', 'db_mode' => 'custom', 'db_host' => 'bad host!'])]);
        check('the hand-entered form is validated', str_contains($response['body'], 'Database host:'));

        $response = http('POST', '/setup.php', ['form' => $envForm + ['intent' => 'save']]);
        $config = is_file("$dataDir/config.php") ? include "$dataDir/config.php" : [];
        check('setup saves no database settings', $response['status'] === 303 && !array_key_exists('db', $config)
            && ($config['google']['client_id'] ?? '') === '1234567890-test.apps.googleusercontent.com', $response['body'] . json_encode(array_keys($config)));
        $tables = (new PDO("pgsql:host={$postgres['host']};port={$postgres['port']};dbname=yta_env", $postgres['user'], $postgres['password']))
            ->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn();
        check('setup creates the tables in the environment database', (bool)$tables);
        $envToken = (string)($config['setup_token'] ?? '');
        $html = http('GET', "/setup.php?token=$envToken")['body'];
        check('step 2 names the environment database', str_contains($html, 'deployed with the app, YTA_DB_* variables') && dataAttribute($html, 'data-nonce') !== '');

        // Setup reopened against a database that already has accounts (config.php lost, database volume kept): the environment
        // supplies the password, so the browser must not be able to create an administrator; only the server-side CLI can
        $config['google']['certs_endpoint'] = "$googleUrl/certs";
        file_put_contents("$dataDir/config.php", '<?php return ' . var_export($config, true) . ';');
        $envPdo = new PDO("pgsql:host={$postgres['host']};port={$postgres['port']};dbname=yta_env", $postgres['user'], $postgres['password']);
        $envPdo->exec("INSERT INTO users (google_sub, email, role, status, approved_at) VALUES ('sub-earlier', 'earlier@example.com', 'admin', 'approved', now())");
        $credential = googleIdToken(['iss' => 'https://accounts.google.com', 'aud' => dataAttribute($html, 'data-client-id'), 'sub' => 'sub-intruder',
            'email' => 'intruder@example.com', 'email_verified' => true, 'nonce' => dataAttribute($html, 'data-nonce'), 'iat' => time(), 'exp' => time() + 3600]);
        $response = http('POST', '/login.php?action=google', ['form' => ['csrf' => formValue($html, 'csrf'), 'credential' => $credential]]);
        $intruders = (int)$envPdo->query("SELECT count(*) FROM users WHERE email = 'intruder@example.com'")->fetchColumn();
        check('reopened setup cannot take over existing accounts', $response['location'] === "/setup.php?token=$envToken" && $intruders === 0
            && (include "$dataDir/config.php")['installed'] === false, (string)$response['location']);
        $html = http('GET', "/setup.php?token=$envToken")['body'];
        check('reopened setup points to the server-side recovery', str_contains($html, 'already has an administrator') && dataAttribute($html, 'data-nonce') === '');

        foreach ($dbEnv + ['YTA_DATA_DIR' => $dataDir] as $name => $value) {
            putenv("$name=$value");
        }
        $output = runCommand([PHP_BINARY, "$root/public/setup.php", '--status'], $exitCode);
        check('setup.php --status shows the environment database', $exitCode === 0 && str_contains((string)$output, 'Database: yta_env @ '), (string)$output);
        $output = runCommand([PHP_BINARY, "$root/public/setup.php", '--migrate'], $exitCode);
        check('setup.php --migrate uses the environment database', $exitCode === 0 && str_contains((string)$output, 'up to date'), (string)$output);
        $output = runCommand([PHP_BINARY, "$root/public/setup.php", '--make-admin=earlier@example.com'], $exitCode);
        check('setup.php --make-admin finishes the reopened installation', $exitCode === 0 && (include "$dataDir/config.php")['installed'] === true, (string)$output);
    } finally {
        foreach (array_keys($dbEnv) as $name) {
            putenv($name);
        }
        stopDevServer($serve);
        $serve = null;
        $dataDir = $mainDataDir;
        $jar = 'admin';
    }
} finally {
    stopDevServer($serve);
    stopProcess($google);
    ($postgres['stop'])();
    if ($failures) {
        echo "\n--- dev/serve.php output ---\n" . @file_get_contents($serverLog) . "\n--- web server log (tail) ---\n"
            . implode("\n", array_slice(file("$dataDir/dev-server.log", FILE_IGNORE_NEW_LINES) ?: [], -40)) . "\n";
    }
    usleep(500000);
    removeDir($dataDir);
    removeDir($envDataDir);
    removeDir($pgDir);
}

echo $failures ? "\n$failures FAILURE(S)\n" : "\nAll integration tests passed\n";
exit($failures ? 1 : 0);
