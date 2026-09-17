<?php
/**
 * Local development server: runs YT Archiver without Docker (Windows, Linux, macOS).
 *
 *   php dev/serve.php [--host=127.0.0.1] [--port=8080] [--data=<dir>] [--verbose] [--update-tools] [--no-tools]
 *
 * - Provides yt-dlp and ffmpeg in .dev-tools/bin, downloading/updating them when needed (dev/tools.php).
 * - Starts PHP's built-in web server with dev/router.php (mirrors nginx.conf).
 * - Runs a dispatcher loop that starts queued jobs. The built-in server must not spawn
 *   workers itself (see canSpawnWorkers() in public/includes/common.php).
 * - Data goes to .dev-data/ in the repository unless --data is given.
 *
 * Needs PHP >= 8.0 with mbstring and openssl (zip for playlists, curl for the version check).
 * Stop with Ctrl+C, or by creating the file <data>/.dev-stop.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, 'PHP >= 8.0 is required, this is ' . PHP_VERSION . ". Run it with a PHP 8 binary (e.g. php8).\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', ['host:', 'port:', 'data:', 'verbose', 'help', 'no-tools', 'update-tools']);
if (isset($options['help'])) {
    echo <<<TXT
    Usage: php dev/serve.php [options]
      --host=127.0.0.1   interface to listen on
      --port=8080        port
      --data=.dev-data   data directory (library, queue, logs)
      --verbose          print the web server request log to this console
      --update-tools     check for new yt-dlp and ffmpeg now (ffmpeg is otherwise never updated)
      --no-tools         do not check or download yt-dlp/ffmpeg (offline use, tests)

    TXT;
    exit(0);
}
$host = $options['host'] ?? '127.0.0.1';
$port = (int)($options['port'] ?? 8080);
$verbose = isset($options['verbose']);

$dataDir = $options['data'] ?? $root . '/.dev-data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
    fwrite(STDERR, "Cannot create data directory $dataDir\n");
    exit(1);
}
$dataDir = str_replace('\\', '/', realpath($dataDir));

// Everything below (this process, the web server, workers) shares this environment
putenv("YTA_DATA_DIR=$dataDir");
putenv('YTA_DEV=1');
putenv('YTA_PHP_BINARY=' . PHP_BINARY);

require_once $root . '/public/includes/common.php';

function info(string $message): void { echo "[dev] $message\n"; }
function warn(string $message): void { echo "[dev] WARNING: $message\n"; }

// ── Preflight ────────────────────────────────────────────

if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "The mbstring extension is required. Enable it in " . (php_ini_loaded_file() ?: 'php.ini') . "\n");
    exit(1);
}

/** Enable a missing extension for child processes through an extra ini scan directory, if the extension file exists. */
function enableExtensionForChildren(string $name, string $dataDir): bool {
    $extDir = ini_get('extension_dir');
    if (!is_dir($extDir)) {
        $extDir = dirname(PHP_BINARY) . '/' . $extDir;
    }
    $file = IS_WINDOWS ? "$extDir/php_$name.dll" : "$extDir/$name.so";
    if (!is_file($file)) {
        return false;
    }

    $iniDir = "$dataDir/php-conf.d";
    @mkdir($iniDir, 0755, true);
    file_put_contents("$iniDir/dev-$name.ini", "extension=$name\n");
    // A leading empty entry keeps PHP's compiled-in scan directory
    $current = getenv('PHP_INI_SCAN_DIR');
    if (!str_contains((string)$current, $iniDir)) {
        putenv('PHP_INI_SCAN_DIR=' . ($current !== false && $current !== '' ? $current : '') . PATH_SEPARATOR . $iniDir);
    }

    $check = runCommand([PHP_BINARY, '-r', "echo extension_loaded('$name') ? 'yes' : 'no';"]);
    return trim((string)$check) === 'yes';
}

foreach ([
    'pdo_pgsql' => 'sign-in and setup will fail (PostgreSQL)',
    'openssl'   => 'Google sign-in and tool downloads will fail (HTTPS)',
    'zip'       => 'playlist archives will fail',
] as $extension => $impact) {
    if (extension_loaded($extension)) {
        continue;
    }
    if (enableExtensionForChildren($extension, $dataDir)) {
        info("Enabled the $extension extension for the dev server and workers");
    } else {
        warn("PHP extension '$extension' is not available: $impact. Enable it in " . (php_ini_loaded_file() ?: 'php.ini'));
    }
}

if (isset($options['no-tools'])) {
    info('Skipping yt-dlp/ffmpeg checks (--no-tools)');
} else {
    require_once __DIR__ . '/tools.php';
    provisionTools($root . '/.dev-tools', isset($options['update-tools']));
}

// New database migrations, like entrypoint.sh does in Docker (in a child process, which has the extensions enabled above)
if (is_file("$dataDir/config.php")) {
    $migration = trim((string)runCommand([PHP_BINARY, "$root/public/setup.php", '--migrate'], $exitCode, $migrationErrors));
    $exitCode === 0 ? info($migration) : warn('Database migrations failed: ' . trim($migration . ' ' . $migrationErrors));
}

// ── Web server ───────────────────────────────────────────

$stopFile = "$dataDir/.dev-stop";
@unlink($stopFile);

$logFile = "$dataDir/dev-server.log";
$output = $verbose ? STDOUT : ['file', $logFile, 'a'];
$server = proc_open(
    [PHP_BINARY, '-S', "$host:$port", '-t', "$root/public", "$root/dev/router.php"],
    [0 => ['file', NULL_DEVICE, 'r'], 1 => $output, 2 => $output],
    $pipes, $root, null, ['bypass_shell' => true]
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the PHP built-in server\n");
    exit(1);
}

usleep(700000);
if (!proc_get_status($server)['running']) {
    fwrite(STDERR, "The web server exited immediately. Is port $port already in use?" . ($verbose ? '' : " See $logFile") . "\n");
    exit(1);
}

info("YT Archiver running at http://$host:$port");
info("Data directory: $dataDir");
info($verbose ? 'Request log: this console' : "Request log: $logFile (use --verbose to print it here)");
info('Press Ctrl+C to stop');

// ── Dispatcher loop ──────────────────────────────────────

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
} elseif (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(function () use (&$stop) { $stop = true; });
}

$exitCode = 0;
while (!$stop) {
    try {
        withLock(function () {
            recoverStaleJob();
            startNextJob();
        });
    } catch (Throwable $e) {
        warn('Dispatcher: ' . $e->getMessage());
    }

    if (!proc_get_status($server)['running']) {
        warn('The web server stopped unexpectedly' . ($verbose ? '' : ", see $logFile"));
        $exitCode = 1;
        break;
    }
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        break;
    }
    usleep(500000);
}

// ── Shutdown ─────────────────────────────────────────────

info('Stopping...');
proc_terminate($server);
proc_close($server);

// Put an interrupted job back at the front of the queue (without counting it as a failed attempt)
withLock(function () {
    $queue = getQueue();
    if ($queue['current'] === null) {
        return;
    }
    killWorker($queue['pid']);
    $job = $queue['current'];
    if (jobType($job) === 'archive') {
        foreach (glob(STAGING_DIR . '/' . $job['playlist_id'] . '.zip*') ?: [] as $file) {
            @unlink($file);
        }
    } elseif (jobType($job) !== 'playlist') {
        removeDir(jobWorkDir($job));
    }
    array_unshift($queue['queue'], $job);
    $queue['current'] = null;
    $queue['pid'] = null;
    saveQueue($queue);
    clearProgress();
    info('Interrupted job ' . $job['id'] . ' was put back into the queue');
});

exit($exitCode);
