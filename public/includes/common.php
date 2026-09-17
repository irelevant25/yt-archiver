<?php
/**
 * Shared code for api.php and download_worker.php.
 *
 * This file must not have side effects when included (no output, no file writes).
 * It is not web-accessible (nginx denies /includes/).
 */

// The container uses /data. Local development (dev/serve.php) and tests point YTA_DATA_DIR elsewhere;
// tests may also define DATA_DIR before including this file.
if (!defined('DATA_DIR')) {
    define('DATA_DIR', rtrim(str_replace('\\', '/', getenv('YTA_DATA_DIR') ?: '/data'), '/'));
}
define('VIDEOS_DIR', DATA_DIR . '/videos');
// Work-in-progress downloads live on the same filesystem as the library so the
// final move is an atomic rename. nginx denies access to dot-directories.
define('STAGING_DIR', VIDEOS_DIR . '/.staging');
define('DB_FILE', DATA_DIR . '/database.json');
define('QUEUE_FILE', DATA_DIR . '/queue.json');
define('PROGRESS_FILE', DATA_DIR . '/progress.json');
define('LOCK_FILE', DATA_DIR . '/queue.lock');
define('LOG_FILE', DATA_DIR . '/logs.csv');
define('WORKER_SCRIPT', dirname(__DIR__) . '/download_worker.php');

define('IS_WINDOWS', PHP_OS_FAMILY === 'Windows');
define('NULL_DEVICE', IS_WINDOWS ? 'NUL' : '/dev/null');

define('YTDLP_RELEASES_URL', 'https://github.com/yt-dlp/yt-dlp/releases');

define('ALLOWED_FORMATS', ['mp4', 'mp3']);

function numericEnv(string $name, float $default): float {
    $value = getenv($name);
    return $value !== false && is_numeric(trim($value)) ? (float)trim($value) : $default;
}

// Downloads are refused while less than this share of the disk is (or would be) free
define('MIN_FREE_SPACE_PERCENT', max(0.0, min(100.0, numericEnv('MIN_FREE_SPACE_PERCENT', 10))));
// Downloads estimated at or above this size need an explicit confirmation
define('LARGE_DOWNLOAD_BYTES', (int)round(max(0.0, numericEnv('LARGE_DOWNLOAD_WARNING_GB', 1)) * 1024 ** 3));
// Size estimates when yt-dlp reports no file size: mp3 --audio-quality 0 (VBR ~245 kbps), mp4 ~1080p (~3.2 Mbps)
define('ESTIMATED_BYTES_PER_SECOND', ['mp3' => 30625, 'mp4' => 400000]);
// How many times a job whose worker died unexpectedly is re-queued before it is dropped.
define('MAX_JOB_ATTEMPTS', 2);

// ── Storage ──────────────────────────────────────────────

/**
 * Run $fn while holding the exclusive queue/database lock.
 * Re-entrant within one process (flock on a second descriptor would deadlock).
 */
function withLock(callable $fn): mixed {
    static $depth = 0;

    if ($depth > 0) {
        $depth++;
        try {
            return $fn();
        } finally {
            $depth--;
        }
    }

    // 'e' = close-on-exec, so spawned workers do not inherit the lock descriptor
    $handle = fopen(LOCK_FILE, 'ce');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Could not acquire queue lock');
    }
    $depth = 1;
    try {
        return $fn();
    } finally {
        $depth = 0;
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function readJson(string $file, array $default): array {
    // On Windows a read can fail for a moment while another process replaces the file; retry briefly
    // (otherwise e.g. a worker could mistake a failed read for a cancelled job)
    for ($attempt = 0; $attempt < 5; $attempt++) {
        if (!file_exists($file)) {
            return $default;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
        usleep(20000);
    }
    return $default;
}

/** Atomic write: readers never observe a half-written file. */
function writeJson(string $file, array $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE): void {
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
        throw new RuntimeException('Could not write ' . basename($file));
    }
    // Windows refuses to replace a file another process has open at that moment; retry briefly
    for ($attempt = 0; !@rename($tmp, $file); $attempt++) {
        if ($attempt >= 50) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace ' . basename($file));
        }
        usleep(20000);
    }
}

function getDatabase(): array {
    $db = readJson(DB_FILE, ['videos' => []]);
    $db['videos'] = array_values($db['videos'] ?? []);
    return $db;
}

function saveDatabase(array $db): void {
    writeJson(DB_FILE, $db);
}

function getQueue(): array {
    $queue = readJson(QUEUE_FILE, []);
    return [
        'queue'   => array_values($queue['queue'] ?? []),
        'current' => $queue['current'] ?? null,
        'pid'     => isset($queue['pid']) ? (int)$queue['pid'] : null,
    ];
}

function saveQueue(array $queue): void {
    writeJson(QUEUE_FILE, $queue);
}

function idleProgress(): array {
    return ['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null];
}

function getProgress(): array {
    return readJson(PROGRESS_FILE, idleProgress());
}

function saveProgress(array $progress): void {
    writeJson(PROGRESS_FILE, $progress, JSON_UNESCAPED_UNICODE);
}

function clearProgress(): void {
    saveProgress(idleProgress());
}

// ── Helpers ──────────────────────────────────────────────

function generateId(string $prefix = 'vid_'): string {
    return uniqid($prefix, true);
}

function jobType(array $job): string {
    return $job['type'] ?? 'video';
}

/**
 * Make a title safe for filenames and display: keeps letters (any script),
 * digits and a small set of punctuation.
 */
function sanitizeTitle(?string $title, int $maxLength = 100): string {
    $title = preg_replace('/[^\p{L}\p{M}\p{N}\s\-_.,()\[\]!&+]/u', '', (string)$title) ?? '';
    $title = preg_replace('/\s+/u', ' ', $title) ?? '';
    $title = mb_substr($title, 0, $maxLength);
    // No leading dots (hidden files) or trailing dots/spaces
    return trim($title, " .");
}

/**
 * Validate a YouTube URL and extract the video and playlist IDs from it.
 * Only the extracted IDs are ever passed to yt-dlp, never the raw URL.
 *
 * @return array{video: ?string, list: ?string}|null
 */
function parseYoutubeUrl(string $url): ?array {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
        return null;
    }

    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    $path = rtrim($parts['path'] ?? '', '/');
    parse_str($parts['query'] ?? '', $query);

    $video = null;
    if ($host === 'youtu.be') {
        $video = explode('/', ltrim($path, '/'))[0];
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
        if ($path === '/watch') {
            $video = $query['v'] ?? null;
        } elseif (preg_match('#^/(?:shorts|live|embed)/([^/]+)$#', $path, $m)) {
            $video = $m[1];
        } elseif ($path !== '/playlist') {
            return null;
        }
    } else {
        return null;
    }

    $list = $query['list'] ?? null;
    $video = is_string($video) && preg_match('/^[A-Za-z0-9_-]{11}$/', $video) ? $video : null;
    $list = is_string($list) && preg_match('/^[A-Za-z0-9_-]{2,64}$/', $list) ? $list : null;

    if ($video === null && $list === null) {
        return null;
    }
    return ['video' => $video, 'list' => $list];
}

/** Whether $ip is inside $range: a single IP or a CIDR block (IPv4 or IPv6). */
function ipInRange(string $ip, string $range): bool {
    [$subnet, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton((string)$subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    if ($bits === null) {
        $bits = $maxBits;
    } elseif (!ctype_digit($bits) || (int)$bits > $maxBits) {
        return false;
    }
    $bits = (int)$bits;

    $fullBytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }
    $remainingBits = $bits % 8;
    if ($remainingBits === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);
    return ($ipBin[$fullBytes] & $mask) === ($subnetBin[$fullBytes] & $mask);
}

function isTrustedProxy(string $ip): bool {
    // Proxy headers are only trusted from private/loopback addresses (reverse proxy,
    // cloudflared, Docker network) or from TRUSTED_PROXIES. A direct client could otherwise spoof its IP.
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    foreach (array_filter(explode(',', (string)getenv('TRUSTED_PROXIES'))) as $range) {
        if (ipInRange($ip, $range)) {
            return true;
        }
    }
    return false;
}

function getClientIp(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '') {
        return 'unknown';
    }
    if (!isTrustedProxy($remote)) {
        return $remote;
    }

    // Cloudflare sets CF-Connecting-IP itself; X-Real-IP is typically set by the reverse proxy
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
        $ip = trim($_SERVER[$header] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    // The last X-Forwarded-For entry was added by the nearest proxy; earlier entries are client-controlled
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $ip = end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $remote;
}

/**
 * Append a line to DATA_DIR/worker.log. Only active in local development (YTA_DEV=1) or with YTA_WORKER_LOG=1,
 * because the container discards worker output and the file would grow without limit.
 */
function workerLog(string $message): void {
    if (getenv('YTA_DEV') !== '1' && getenv('YTA_WORKER_LOG') !== '1') {
        return;
    }
    @file_put_contents(DATA_DIR . '/worker.log', sprintf("[%s] [%d] %s\n", date('Y-m-d H:i:s'), getmypid(), $message), FILE_APPEND | LOCK_EX);
}

/** Recursively delete a directory. Only ever called with paths built from trusted job IDs. */
function removeDir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/** Directory where a job downloads before its result is moved into place. */
function jobWorkDir(array $job): string {
    if (jobType($job) === 'playlist_item') {
        return playlistDir($job['playlist_id']) . '/' . $job['id'];
    }
    return STAGING_DIR . '/' . $job['id'];
}

/** Directory collecting the finished items of a playlist until they are archived. */
function playlistDir(string $playlistId): string {
    return STAGING_DIR . '/' . $playlistId;
}

// ── Worker processes ─────────────────────────────────────

/** PHP CLI binary for workers. php-fpm's PHP_BINARY is php-fpm itself, so the container falls back to `php` on PATH. */
function phpBinary(): string {
    return getenv('YTA_PHP_BINARY') ?: (in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : 'php');
}

/**
 * yt-dlp command prefix. YTA_YTDLP_BIN may hold a path or a whole command
 * (quoted parts allowed), e.g. `php tests/fixtures/fake-yt-dlp.php`.
 */
function ytDlpCommand(): array {
    $command = trim((string)getenv('YTA_YTDLP_BIN'));
    return $command === '' ? ['yt-dlp'] : array_values(array_filter(str_getcsv($command, ' ', '"', ''), 'strlen'));
}

/**
 * Latest yt-dlp release tag, read from the redirect of /releases/latest.
 * Unlike the GitHub API this has no rate limit, and it needs no curl (only openssl).
 */
function latestYtDlpVersion(int $timeout = 10): ?string {
    $context = stream_context_create(['http' => [
        'method' => 'HEAD', 'follow_location' => 0, 'timeout' => $timeout, 'user_agent' => 'YT-Archiver',
    ]]);
    $headers = @get_headers(YTDLP_RELEASES_URL . '/latest', true, $context);
    $location = $headers['Location'] ?? $headers['location'] ?? null;
    $location = is_array($location) ? end($location) : $location;
    return is_string($location) && preg_match('#/releases/tag/([\w.\-]+)$#', $location, $m) ? $m[1] : null;
}

/** Run a command without a shell and return its stdout (null if it could not be started). */
function runCommand(array $cmd, ?int &$exitCode = null, ?string &$errorOutput = null): ?string {
    // stderr goes to a temp file: two pipes could deadlock when both fill up
    $errorFile = tempnam(sys_get_temp_dir(), 'yta');
    $proc = @proc_open($cmd, [0 => ['file', NULL_DEVICE, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile ?: NULL_DEVICE, 'w']], $pipes);
    if (!is_resource($proc)) {
        $exitCode = -1;
        $errorOutput = 'Could not start ' . ($cmd[0] ?? 'command');
        @unlink($errorFile);
        return null;
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($proc);
    $errorOutput = $errorFile ? (string)@file_get_contents($errorFile) : '';
    @unlink($errorFile);
    return $output === false ? null : $output;
}

/** yt-dlp format selection and post-processing for a download format (shared by the worker and the size check). */
function ytDlpFormatArgs(string $format): array {
    return $format === 'mp3'
        ? ['-f', 'bestaudio/best', '--extract-audio', '--audio-format', 'mp3', '--audio-quality', '0']
        : ['-f', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best', '--merge-output-format', 'mp4'];
}

/** Entries of a `yt-dlp --flat-playlist --dump-single-json` result that can be downloaded. */
function playlistEntries(?array $data): array {
    $entries = [];
    foreach ($data['entries'] ?? [] as $entry) {
        $videoId = is_array($entry) ? ($entry['id'] ?? null) : null;
        if (!is_string($videoId) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            continue;
        }
        if (in_array($entry['title'] ?? '', ['[Private video]', '[Deleted video]'], true)) {
            continue;
        }
        $entries[] = $entry;
    }
    return $entries;
}

function estimateBytesForDuration(float $seconds, string $format): int {
    return (int)round($seconds * (ESTIMATED_BYTES_PER_SECOND[$format] ?? ESTIMATED_BYTES_PER_SECOND['mp4']));
}

/**
 * Estimated final size of a single video from its `yt-dlp --dump-json` info (with the format selection applied).
 * mp4 uses the sizes YouTube reports for the selected streams; mp3 is re-encoded, so it is estimated from the duration.
 */
function estimateVideoBytes(array $info, string $format): ?int {
    $duration = is_numeric($info['duration'] ?? null) ? (float)$info['duration'] : null;
    if ($format !== 'mp3') {
        $streams = $info['requested_formats'] ?? [$info];
        $total = 0;
        foreach ($streams as $stream) {
            $size = $stream['filesize'] ?? $stream['filesize_approx'] ?? null;
            if (!is_numeric($size)) {
                $total = null;
                break;
            }
            $total += (int)$size;
        }
        if ($total) {
            return $total;
        }
    }
    return $duration !== null ? estimateBytesForDuration($duration, $format) : null;
}

/** Disk space the job will still need: a playlist needs its items plus the ZIP copy until staging is removed. */
function jobReservedBytes(array $job): int {
    $size = (int)($job['estimated_size'] ?? 0);
    return jobType($job) === 'playlist' ? 2 * $size : $size;
}

/** Disk usage of the library volume. `total` is 0 when the platform cannot report it (then nothing is blocked). */
function storageInfo(): array {
    $total = (int)(@disk_total_space(VIDEOS_DIR) ?: 0);
    $free = (int)(@disk_free_space(VIDEOS_DIR) ?: 0);
    $queue = getQueue();

    return [
        'total'            => $total,
        'free'             => $free,
        'library'          => array_sum(array_map(fn(array $video) => (int)($video['size'] ?? 0), getDatabase()['videos'])),
        'reserved'         => array_sum(array_map('jobReservedBytes', array_filter([$queue['current'], ...$queue['queue']]))),
        'min_free'         => (int)ceil($total * MIN_FREE_SPACE_PERCENT / 100),
        'min_free_percent' => MIN_FREE_SPACE_PERCENT,
        'large_download'   => LARGE_DOWNLOAD_BYTES,
    ];
}

function formatBytes(int|float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    for ($value = (float)$bytes; abs($value) >= 1024 && $i < count($units) - 1; $i++) {
        $value /= 1024;
    }
    return round($value, $i ? 1 : 0) . ' ' . $units[$i];
}

/**
 * Decide whether a download may be queued.
 *  - blocked: less than MIN_FREE_SPACE_PERCENT is free now, or would be after the queue and this download (peak usage)
 *  - confirmation required: estimated size >= LARGE_DOWNLOAD_BYTES, or less than twice the minimum would remain free, or a live stream
 */
function evaluateDownload(array $inspection, array $storage): array {
    $estimate = $inspection['estimated_size'];
    $peak = jobReservedBytes(['type' => $inspection['type'], 'estimated_size' => $estimate]);
    $known = $storage['total'] > 0;
    $projectedFree = $known ? $storage['free'] - $storage['reserved'] - $peak : null;
    $percent = fn(int $bytes): string => round(max(0, $bytes) * 100 / $storage['total'], 1) . ' %';

    $blocked = null;
    if ($known && $storage['free'] < $storage['min_free']) {
        $blocked = sprintf('Not enough disk space: only %s (%s) is free, downloads need at least %s %% free.',
            formatBytes($storage['free']), $percent($storage['free']), $storage['min_free_percent']);
    } elseif ($known && $projectedFree < $storage['min_free']) {
        $blocked = sprintf('Not enough disk space: this download (≈ %s%s) and the queue (≈ %s) would leave %s (%s) free, the minimum is %s %%.',
            formatBytes($peak), $peak !== $estimate ? ' while archiving' : '', formatBytes($storage['reserved']),
            formatBytes(max(0, $projectedFree)), $percent($projectedFree), $storage['min_free_percent']);
    }

    $reasons = [];
    if ($estimate !== null && $estimate >= LARGE_DOWNLOAD_BYTES) {
        $reasons[] = 'The estimated size is ≈ ' . formatBytes($estimate) . '.';
    }
    if ($blocked === null && $known && $projectedFree < 2 * $storage['min_free']) {
        $reasons[] = 'Disk space is running low: about ' . formatBytes($projectedFree) . ' (' . $percent($projectedFree) . ') would remain free.';
    }
    if (!empty($inspection['live'])) {
        $reasons[] = 'This is a live stream: its size cannot be estimated and it downloads until the stream ends.';
    }

    return [
        'blocked'               => $blocked,
        'confirmation_required' => $blocked === null && $reasons !== [],
        'reasons'               => $reasons,
        'projected_free'        => $projectedFree,
    ];
}

/**
 * Whether this process may start workers. PHP's built-in web server (local development) must not:
 * the worker would inherit the client socket and keep the HTTP response open until it exits.
 * There, dev/serve.php starts jobs instead.
 */
function canSpawnWorkers(): bool {
    return PHP_SAPI !== 'cli-server';
}

/** Start a background worker for the given job. Returns its PID (on Linux also its process-group ID). */
function spawnWorker(string $jobId): ?int {
    if (IS_WINDOWS) {
        // The process handle is not waited for when the resource is freed, so the worker keeps running
        $proc = @proc_open(
            [phpBinary(), WORKER_SCRIPT, $jobId],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes, null, null, ['bypass_shell' => true]
        );
        return is_resource($proc) ? (proc_get_status($proc)['pid'] ?: null) : null;
    }

    // Double fork through sh: the worker is re-parented to init, so nobody has to reap it
    $cmd = sprintf(
        '%s %s %s > /dev/null 2>&1 & echo $!',
        escapeshellarg(phpBinary()),
        escapeshellarg(WORKER_SCRIPT),
        escapeshellarg($jobId)
    );
    $pid = (int)trim((string)shell_exec($cmd));
    return $pid > 0 ? $pid : null;
}

function isWorkerAlive(?int $pid): bool {
    if (!$pid) {
        return false;
    }
    if (IS_WINDOWS) {
        exec(sprintf('tasklist /FI "PID eq %d" /FO CSV /NH', $pid), $lines);
        return str_contains(strtolower(implode("\n", $lines)), '"php');
    }
    // Checking the command line guards against PID reuse (e.g. after a container restart).
    // Zombies have an empty cmdline and are correctly treated as dead.
    if (is_dir('/proc/self')) {
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        return $cmdline !== false && str_contains($cmdline, basename(WORKER_SCRIPT));
    }
    // macOS and other systems without /proc
    exec(sprintf('ps -p %d -o command= 2>/dev/null', $pid), $lines);
    return str_contains(implode("\n", $lines), basename(WORKER_SCRIPT));
}

/** Terminate a worker and everything it started (yt-dlp, ffmpeg). */
function killWorker(?int $pid): void {
    if (!$pid || !isWorkerAlive($pid)) {
        return;
    }

    if (IS_WINDOWS) {
        // /T kills the whole process tree, /F forces it
        exec(sprintf('taskkill /PID %d /T /F 2>NUL', $pid));
        for ($i = 0; $i < 30 && isWorkerAlive($pid); $i++) {
            usleep(100000);
        }
        return;
    }

    // The worker is a process-group leader (posix_setsid), so signal the whole group
    $signal = static function (int $sig) use ($pid): void {
        if (function_exists('posix_kill')) {
            @posix_kill(-$pid, $sig);
            @posix_kill($pid, $sig);
        } else {
            exec(sprintf('kill -%d -- -%d %d 2>/dev/null', $sig, $pid, $pid));
        }
    };

    $signal(15); // SIGTERM
    for ($i = 0; $i < 30 && isWorkerAlive($pid); $i++) {
        usleep(100000);
    }
    $signal(9); // SIGKILL anything left in the group
}

// ── Queue ────────────────────────────────────────────────

/**
 * If nothing is running, take the next job from the queue and start a worker for it.
 * Must be called while holding the lock.
 */
function startNextJob(): void {
    if (!canSpawnWorkers()) {
        return;
    }
    $queue = getQueue();
    if ($queue['current'] !== null || empty($queue['queue'])) {
        return;
    }

    $job = array_shift($queue['queue']);
    $queue['current'] = $job;
    $queue['pid'] = null;
    saveQueue($queue);

    saveProgress([
        'percent' => 0,
        'status'  => 'starting',
        'title'   => jobType($job) === 'playlist' ? 'Fetching playlist info...' : ($job['title'] ?? 'Fetching video info...'),
        'id'      => $job['id'],
    ]);

    $queue['pid'] = spawnWorker($job['id']);
    saveQueue($queue);
}

/**
 * Detect a job whose worker process is gone (crash, container restart) and re-queue it
 * a limited number of times so the queue never gets stuck. Must be called while holding the lock.
 */
function recoverStaleJob(): void {
    $queue = getQueue();
    if ($queue['current'] === null || isWorkerAlive($queue['pid'])) {
        return;
    }

    $job = $queue['current'];
    $job['attempts'] = ($job['attempts'] ?? 0) + 1;
    $queue['current'] = null;
    $queue['pid'] = null;
    if ($job['attempts'] < MAX_JOB_ATTEMPTS) {
        array_unshift($queue['queue'], $job);
    } else {
        removeDir(jobType($job) === 'archive' ? playlistDir($job['playlist_id']) : jobWorkDir($job));
    }
    saveQueue($queue);
    clearProgress();
}
