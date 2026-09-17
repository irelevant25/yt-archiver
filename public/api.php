<?php
/**
 * YouTube Archiver API
 * Handles all backend operations for downloading, managing, and serving videos
 */

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// No CORS headers on purpose: the API is only meant to be used by the same-origin frontend.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Every action needs a signed-in, approved account. nginx checks this before PHP runs as well;
// this check also covers the local dev server and any misconfigured proxy.
try {
    $currentUser = isInstalled() ? currentUser() : null;
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The user database is not available']);
    exit;
}
if (!isApproved($currentUser)) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in required', 'login_required' => true]);
    exit;
}

// Ensure directories exist
if (!file_exists(STAGING_DIR)) {
    mkdir(STAGING_DIR, 0755, true);
}

// Initialize database if not exists
if (!file_exists(DB_FILE)) {
    saveDatabase(['videos' => []]);
}

// Initialize queue if not exists
if (!file_exists(QUEUE_FILE)) {
    saveQueue(['queue' => [], 'current' => null, 'pid' => null]);
}

// Helper functions
function getYtDlpVersion(): string {
    $output = runCommand([...ytDlpCommand(), '--version']);
    return trim((string)$output) ?: 'unknown';
}

function getLatestYtDlpVersion(): array {
    $version = latestYtDlpVersion();
    return $version === null
        ? ['version' => 'unknown', 'url' => '']
        : ['version' => $version, 'url' => YTDLP_RELEASES_URL . '/tag/' . $version];
}

function updateYtDlp(): array {
    if (getenv('YTA_DEV') === '1') {
        // Local development: the standalone binary in .dev-tools updates itself (a pip install refuses and says so)
        $output = runCommand([...ytDlpCommand(), '-U'], $exitCode);
        return [
            'success' => $exitCode === 0,
            'version' => getYtDlpVersion(),
            'output' => (string)$output,
            'error' => $exitCode === 0 ? null : 'yt-dlp -U failed: ' . trim((string)$output)
        ];
    }
    // Must match the sudoers rule in the Dockerfile exactly
    exec('sudo -n /usr/bin/pip3 install --upgrade yt-dlp --break-system-packages 2>&1', $output, $exitCode);
    return [
        'success' => $exitCode === 0,
        'version' => getYtDlpVersion(),
        'output' => implode("\n", $output)
    ];
}

/**
 * Reject cross-site form posts: a JSON Content-Type cannot be sent cross-origin
 * without a CORS preflight, which this API never approves.
 */
function requireJsonRequest(): array {
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($contentType, 'application/json')) {
        http_response_code(415);
        echo json_encode(['error' => 'Content-Type must be application/json']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($input) ? $input : [];
}

/** An API error with its own HTTP status and extra response fields. */
class ApiError extends Exception {
    public function __construct(string $message, public int $status = 400, public array $data = []) {
        parent::__construct($message);
    }
}

// Size checks are reused by the download request that follows the confirmation
define('INSPECTION_TTL', 15 * 60);

/** @return array{0: string, 1: string} job type ('video'|'playlist') and the canonical URL passed to yt-dlp */
function resolveSource(string $url, bool $wantPlaylist): array {
    $parsed = parseYoutubeUrl($url);
    if ($parsed === null) {
        throw new ApiError('Invalid source');
    }

    // A watch URL with a list parameter downloads just the video unless the whole playlist was requested
    if ($parsed['list'] !== null && ($parsed['video'] === null || $wantPlaylist)) {
        // The watch?v=&list= form also works for auto-generated mixes (RD...), which /playlist does not
        return ['playlist', $parsed['video'] !== null
            ? 'https://www.youtube.com/watch?v=' . $parsed['video'] . '&list=' . $parsed['list']
            : 'https://www.youtube.com/playlist?list=' . $parsed['list']];
    }
    return ['video', 'https://www.youtube.com/watch?v=' . $parsed['video']];
}

/** The reason from yt-dlp's last "ERROR:" line, e.g. ": Private video". */
function ytDlpErrorSuffix(?string $errorOutput): string {
    preg_match_all('/^ERROR:\s*(?:\[[^\]]+\]\s*)?(?:[\w-]+:\s*)?(.+)$/m', (string)$errorOutput, $matches);
    $reason = trim((string)end($matches[1]));
    return $reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '';
}

/**
 * Title, duration and estimated final size of a video or playlist, without downloading it.
 * Results are cached for INSPECTION_TTL in DATA_DIR/inspections.
 */
function inspectSource(string $type, string $url, string $format): array {
    $cacheDir = DATA_DIR . '/inspections';
    $cacheFile = $cacheDir . '/' . sha1("$type|$url|$format") . '.json';
    $cached = readJson($cacheFile, []);
    if (($cached['checked_at'] ?? 0) > time() - INSPECTION_TTL) {
        return $cached;
    }

    if ($type === 'playlist') {
        $json = runCommand([...ytDlpCommand(), '--flat-playlist', '--dump-single-json', '--no-warnings', '--', $url], $exitCode, $errors);
        $data = json_decode((string)$json, true);
        $entries = playlistEntries(is_array($data) ? $data : null);
        if (!$entries) {
            throw new ApiError('Playlist is empty or unavailable' . ytDlpErrorSuffix($errors), 422);
        }

        // Entries without a duration are assumed to be as long as the average entry
        $durations = array_values(array_filter(array_map(fn(array $entry) => is_numeric($entry['duration'] ?? null) ? (float)$entry['duration'] : null, $entries), 'is_float'));
        $average = $durations ? array_sum($durations) / count($durations) : 0.0;
        $duration = array_sum($durations) + $average * (count($entries) - count($durations));

        $inspection = [
            'type'              => 'playlist',
            'title'             => sanitizeTitle($data['title'] ?? null) ?: 'Playlist',
            'items'             => count($entries),
            'duration'          => (int)round($duration),
            'unknown_durations' => count($entries) - count($durations),
            'estimated_size'    => $durations ? estimateBytesForDuration($duration, $format) : null,
            'live'              => false,
        ];
    } else {
        $json = runCommand([...ytDlpCommand(), '--dump-json', '--no-playlist', '--no-warnings', ...ytDlpFormatArgs($format), '--', $url], $exitCode, $errors);
        $info = json_decode((string)$json, true);
        if (!is_array($info)) {
            throw new ApiError('Video is unavailable' . ytDlpErrorSuffix($errors), 422);
        }

        $inspection = [
            'type'           => 'video',
            'title'          => sanitizeTitle($info['title'] ?? null),
            'items'          => 1,
            'duration'       => is_numeric($info['duration'] ?? null) ? (int)round($info['duration']) : null,
            'estimated_size' => estimateVideoBytes($info, $format),
            'live'           => ($info['live_status'] ?? null) === 'is_live' || !empty($info['is_live']),
        ];
    }

    $inspection += ['format' => $format, 'checked_at' => time()];

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
        if (filemtime($file) < time() - INSPECTION_TTL) {
            @unlink($file);
        }
    }
    writeJson($cacheFile, $inspection);

    return $inspection;
}

/** Everything the UI needs to decide about a download: size estimate, disk space, block/confirmation verdict. */
function checkDownload(string $url, string $format, bool $wantPlaylist): array {
    [$type, $canonicalUrl] = resolveSource($url, $wantPlaylist);
    $inspection = inspectSource($type, $canonicalUrl, $format);
    $storage = storageInfo();
    return ['url' => $canonicalUrl, 'inspection' => $inspection, 'storage' => $storage] + evaluateDownload($inspection, $storage);
}

function startDownload(string $url, string $format, bool $wantPlaylist, bool $confirmed): array {
    $check = checkDownload($url, $format, $wantPlaylist);
    $inspection = $check['inspection'];

    return withLock(function () use ($check, $inspection, $format, $confirmed) {
        // Decide again under the lock, so parallel requests see each other's reservations
        $storage = storageInfo();
        $decision = evaluateDownload($inspection, $storage);
        $details = ['inspection' => $inspection, 'storage' => $storage] + $decision;

        if ($decision['blocked'] !== null) {
            throw new ApiError($decision['blocked'], 507, $details);
        }
        if ($decision['confirmation_required'] && !$confirmed) {
            throw new ApiError('Confirmation required: ' . implode(' ', $decision['reasons']), 409, $details);
        }

        $isPlaylist = $inspection['type'] === 'playlist';
        $job = [
            'id'             => generateId($isPlaylist ? 'pl_' : 'vid_'),
            'type'           => $inspection['type'],
            'url'            => $check['url'],
            'format'         => $format,
            'status'         => 'queued',
            'estimated_size' => $inspection['estimated_size'],
            'duration'       => $inspection['duration'],
            'created_at'     => date('c'),
        ];
        if ($inspection['title'] !== '') {
            $job[$isPlaylist ? 'playlist_title' : 'title'] = $inspection['title'];
        }

        $queue = getQueue();
        $queue['queue'][] = $job;
        saveQueue($queue);
        recoverStaleJob();
        startNextJob();

        return [
            'success' => true,
            'id' => $job['id'],
            'type' => $job['type'],
            'message' => $isPlaylist ? 'Playlist added to queue' : 'Added to queue'
        ];
    });
}

/** Remove the files a job left behind. Must be called while holding the lock. */
function cleanupJobFiles(array $job): void {
    removeDir(jobWorkDir($job));
}

/** Cancel a playlist: its resolve job, all its items and its archive job. */
function cancelPlaylist(string $playlistId): array {
    return withLock(function () use ($playlistId) {
        $queue = getQueue();
        $belongs = fn(array $job): bool => $job['id'] === $playlistId || ($job['playlist_id'] ?? null) === $playlistId;

        $before = count($queue['queue']);
        $queue['queue'] = array_values(array_filter($queue['queue'], fn(array $job) => !$belongs($job)));
        $found = count($queue['queue']) !== $before;

        if ($queue['current'] !== null && $belongs($queue['current'])) {
            killWorker($queue['pid']);
            $queue['current'] = null;
            $queue['pid'] = null;
            clearProgress();
            $found = true;
        }
        if (!$found) {
            return ['success' => false, 'message' => 'Playlist not found'];
        }

        saveQueue($queue);
        removeDir(playlistDir($playlistId));
        // Partial archive and libzip's temporary files next to it
        foreach (glob(STAGING_DIR . '/' . $playlistId . '.zip*') ?: [] as $file) {
            @unlink($file);
        }
        startNextJob();

        return ['success' => true, 'message' => 'Playlist cancelled'];
    });
}

function cancelDownload(string $id): array {
    return withLock(function () use ($id) {
        $queue = getQueue();

        $job = null;
        if ($queue['current'] !== null && $queue['current']['id'] === $id) {
            $job = $queue['current'];
        } else {
            foreach ($queue['queue'] as $queued) {
                if ($queued['id'] === $id) {
                    $job = $queued;
                    break;
                }
            }
        }

        if ($job === null) {
            return ['success' => false, 'message' => 'Download not found'];
        }

        // An archive (or playlist resolve) cannot be cancelled on its own; it cancels the whole playlist
        if (in_array(jobType($job), ['archive', 'playlist'], true)) {
            return cancelPlaylist($job['playlist_id'] ?? $job['id']);
        }

        if ($queue['current'] !== null && $queue['current']['id'] === $id) {
            killWorker($queue['pid']);
            cleanupJobFiles($job);
            $queue['current'] = null;
            $queue['pid'] = null;
            saveQueue($queue);
            clearProgress();
            startNextJob();
            return ['success' => true, 'message' => 'Download cancelled'];
        }

        $queue['queue'] = array_values(array_filter($queue['queue'], fn(array $queued) => $queued['id'] !== $id));
        saveQueue($queue);
        return ['success' => true, 'message' => 'Removed from queue'];
    });
}

function getVideos(): array {
    return getDatabase()['videos'];
}

function deleteVideo(string $id): array {
    return withLock(function () use ($id) {
        $db = getDatabase();

        foreach ($db['videos'] as $index => $video) {
            if ($video['id'] !== $id) {
                continue;
            }
            $filepath = VIDEOS_DIR . '/' . basename($video['filename']);
            if (is_file($filepath)) {
                unlink($filepath);
            }
            array_splice($db['videos'], $index, 1);
            saveDatabase($db);
            return ['success' => true, 'message' => 'Video deleted'];
        }

        return ['success' => false, 'message' => 'Video not found'];
    });
}

function getDownloadStatus(): array {
    // Polled by the UI: also the place where a stuck queue heals itself
    withLock(function () {
        recoverStaleJob();
        startNextJob();
    });

    $queue = getQueue();
    $progress = getProgress();

    // Only return progress if it matches current download
    if ($queue['current'] === null || $progress['id'] !== $queue['current']['id']) {
        $progress = idleProgress();
    }

    return [
        'current' => $queue['current'],
        'queue' => $queue['queue'],
        'progress' => $progress,
        'storage' => storageInfo()
    ];
}

const LOG_HEADER = "\"timestamp\",\"action\",\"method\",\"ip\",\"body\",\"user\"\n";

function logRequest(string $action, string $method, string $body, string $user): void {
    // Always skip these (polled or read-only)
    if (in_array($action, ['version', 'status', 'logs', 'me', 'users'])) {
        return;
    }
    // Skip videos only for GET (list); log DELETE (delete a video)
    if ($action === 'videos' && $method === 'GET') {
        return;
    }

    $escape = fn(string $v): string => '"' . str_replace('"', '""', $v) . '"';

    if (!file_exists(LOG_FILE)) {
        file_put_contents(LOG_FILE, LOG_HEADER, LOCK_EX);
    }

    $line = implode(',', [
        $escape(date('c')),
        $escape($action),
        $escape($method),
        $escape(getClientIp()),
        $escape($body),
        $escape($user),
    ]) . "\n";

    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function getLogs(int $page, int $limit, string $filterAction = '', string $filterMethod = '', string $search = ''): array {
    if (!file_exists(LOG_FILE)) {
        return ['logs' => [], 'total' => 0, 'pages' => 0, 'page' => $page, 'limit' => $limit, 'size' => 0];
    }

    $size  = filesize(LOG_FILE);
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($lines); // remove header
    $lines = array_reverse($lines); // newest first

    $all = [];
    foreach ($lines as $line) {
        $parsed = str_getcsv($line, ',', '"', '');
        if (count($parsed) < 5) continue;

        $entry = [
            'timestamp' => $parsed[0],
            'action'    => $parsed[1],
            'method'    => $parsed[2],
            'ip'        => $parsed[3],
            'body'      => $parsed[4],
            'user'      => $parsed[5] ?? '', // entries from before sign-in existed have no user
        ];

        if ($filterAction && $entry['action'] !== $filterAction) continue;
        if ($filterMethod && $entry['method'] !== $filterMethod) continue;
        if ($search !== '') {
            $s = strtolower($search);
            if (strpos(strtolower($entry['action']), $s) === false
                && strpos(strtolower($entry['body']), $s) === false
                && strpos(strtolower($entry['ip']), $s) === false
                && strpos(strtolower($entry['user']), $s) === false) {
                continue;
            }
        }

        $all[] = $entry;
    }

    $total  = count($all);
    $pages  = $limit > 0 ? (int)ceil($total / $limit) : 1;
    $offset = ($page - 1) * $limit;

    return [
        'logs'  => array_slice($all, $offset, $limit),
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
        'pages' => $pages,
        'size'  => $size,
    ];
}

function clearLogs(): void {
    file_put_contents(LOG_FILE, LOG_HEADER, LOCK_EX);
}

function requireAdmin(array $user): void {
    if (!isAdmin($user)) {
        throw new ApiError('Administrators only', 403);
    }
}

function publicUser(array $user): array {
    return array_intersect_key($user, array_flip(['id', 'email', 'name', 'picture', 'role', 'status']));
}

function listUsers(): array {
    // ISO 8601 timestamps: PostgreSQL's default text form ("… 07:45:12.1+00") is not parsed by every browser
    $iso = fn(string $column) => "to_char($column AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
    return dbAll(
        "SELECT u.id, u.email, u.name, u.picture, u.role, u.status, {$iso('u.created_at')} AS created_at,
                {$iso('u.approved_at')} AS approved_at, {$iso('u.last_login_at')} AS last_login_at,
                a.email AS approved_by, c.email AS created_by, u.google_sub IS NULL AS invited
         FROM users u LEFT JOIN users a ON a.id = u.approved_by LEFT JOIN users c ON c.id = u.created_by
         ORDER BY (u.status = 'pending' AND u.google_sub IS NOT NULL) DESC, u.created_at DESC"
    );
}

/**
 * Create an account by email before the person signs in (see upsertGoogleUser: their first Google sign-in with this
 * verified email address claims it, keeping the role and status chosen here).
 */
function createUser(array $admin, array $input): array {
    $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
    $role = $input['role'] ?? 'user';
    $approved = ($input['approved'] ?? false) === true;

    if ($email === '' || mb_strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('Enter a valid email address');
    }
    if (!in_array($role, ['user', 'admin'], true)) {
        throw new ApiError('Unknown role');
    }
    if ($role === 'admin' && !$approved) {
        throw new ApiError('An administrator account must be approved');
    }
    if (dbValue('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$email])) {
        throw new ApiError("An account for $email already exists", 409);
    }

    try {
        $id = (int)dbValue(
            "INSERT INTO users (google_sub, email, role, status, approved_at, approved_by, created_by)
             VALUES (NULL, ?, ?, ?, CASE WHEN ? THEN now() END, ?, ?) RETURNING id",
            [$email, $role, $approved ? 'approved' : 'pending', $approved ? 'true' : 'false', $approved ? $admin['id'] : null, $admin['id']]
        );
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') { // unique violation: created concurrently
            throw new ApiError("An account for $email already exists", 409);
        }
        throw $e;
    }
    return ['success' => true, 'message' => "Account for $email created", 'user' => dbOne('SELECT id, email, name, picture, role, status FROM users WHERE id = ?', [$id])];
}

/**
 * Approve, disable, enable, promote or demote another account. Accounts are never deleted (a disabled account keeps
 * its history and cannot sign in). Administrators cannot change their own account.
 */
function manageUser(array $admin, array $input): array {
    $id = is_numeric($input['id'] ?? null) ? (int)$input['id'] : 0;
    $operation = (string)($input['operation'] ?? '');
    $target = dbOne('SELECT id, email, role, status FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        throw new ApiError('User not found', 404);
    }
    if ((int)$target['id'] === (int)$admin['id']) {
        throw new ApiError('You cannot change your own account', 400);
    }

    switch ($operation) {
        case 'approve':
        case 'enable':
            dbExec("UPDATE users SET status = 'approved', approved_at = coalesce(approved_at, now()), approved_by = coalesce(approved_by, ?) WHERE id = ?", [$admin['id'], $id]);
            break;
        case 'disable':
            dbExec("UPDATE users SET status = 'disabled' WHERE id = ?", [$id]);
            break;
        case 'make_admin':
            if ($target['status'] !== 'approved') {
                throw new ApiError('Approve the account before making it an administrator');
            }
            dbExec("UPDATE users SET role = 'admin' WHERE id = ?", [$id]);
            break;
        case 'make_user':
            dbExec("UPDATE users SET role = 'user' WHERE id = ?", [$id]);
            break;
        default:
            throw new ApiError('Unknown operation');
    }
    return ['success' => true, 'user' => dbOne('SELECT id, email, name, picture, role, status FROM users WHERE id = ?', [$id])];
}

// Router
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Log the incoming request (skip OPTIONS and excluded actions)
if ($method !== 'OPTIONS') {
    logRequest($action, $method, file_get_contents('php://input') ?: '', $currentUser['email']);
}

try {
    switch ($action) {
        case 'version':
            $current = getYtDlpVersion();
            $latest = getLatestYtDlpVersion();
            echo json_encode([
                'current' => $current,
                'latest' => $latest['version'],
                'latest_url' => $latest['url'],
                'needs_update' => version_compare($current, $latest['version'], '<')
            ]);
            break;

        case 'me':
            echo json_encode([
                'user'          => publicUser($currentUser),
                'csrf'          => sessionValue('csrf'),
                // Only people who signed in and are waiting; pending accounts created in advance are not waiting yet
                'pending_users' => isAdmin($currentUser) ? (int)dbValue("SELECT count(*) FROM users WHERE status = 'pending' AND google_sub IS NOT NULL") : null,
            ]);
            break;

        case 'users':
            requireAdmin($currentUser);
            echo json_encode(['users' => listUsers(), 'me' => (int)$currentUser['id']]);
            break;

        case 'user':
            requireAdmin($currentUser);
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            echo json_encode(manageUser($currentUser, requireJsonRequest()));
            break;

        case 'create_user':
            requireAdmin($currentUser);
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            echo json_encode(createUser($currentUser, requireJsonRequest()));
            break;

        case 'update':
            requireAdmin($currentUser);
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            requireJsonRequest();
            echo json_encode(updateYtDlp());
            break;

        case 'download':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            $input = requireJsonRequest();
            $url = is_string($input['url'] ?? null) ? $input['url'] : '';
            $format = $input['format'] ?? 'mp4';

            if ($url === '') {
                throw new Exception('URL is required');
            }
            if (!in_array($format, ALLOWED_FORMATS, true)) {
                throw new Exception('Invalid format');
            }

            echo json_encode(startDownload($url, $format, ($input['playlist'] ?? false) === true, ($input['confirmed'] ?? false) === true));
            break;

        case 'inspect':
            // Size estimate and disk check before downloading (POST: it runs yt-dlp)
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            $input = requireJsonRequest();
            $url = is_string($input['url'] ?? null) ? $input['url'] : '';
            $format = $input['format'] ?? 'mp4';
            if ($url === '') {
                throw new Exception('URL is required');
            }
            if (!in_array($format, ALLOWED_FORMATS, true)) {
                throw new Exception('Invalid format');
            }
            echo json_encode(['success' => true] + checkDownload($url, $format, ($input['playlist'] ?? false) === true));
            break;

        case 'cancel':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            $input = requireJsonRequest();
            $id = is_string($input['id'] ?? null) ? $input['id'] : '';
            $playlistId = is_string($input['playlist_id'] ?? null) ? $input['playlist_id'] : '';

            if ($playlistId !== '') {
                echo json_encode(cancelPlaylist($playlistId));
            } elseif ($id !== '') {
                echo json_encode(cancelDownload($id));
            } else {
                throw new Exception('Download ID is required');
            }
            break;

        case 'status':
            echo json_encode(getDownloadStatus());
            break;

        case 'process':
            withLock(function () {
                recoverStaleJob();
                startNextJob();
            });
            echo json_encode(['success' => true]);
            break;

        case 'videos':
            if ($method === 'GET') {
                echo json_encode(['videos' => getVideos()]);
            } elseif ($method === 'DELETE') {
                $id = $_GET['id'] ?? '';
                if (!is_string($id) || $id === '') {
                    throw new Exception('Video ID is required');
                }
                echo json_encode(deleteVideo($id));
            } else {
                throw new Exception('Method not allowed');
            }
            break;

        case 'serve':
            $filename = $_GET['file'] ?? '';
            if (!is_string($filename) || $filename === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Filename is required']);
                exit;
            }

            $filename = basename($filename);
            $filepath = VIDEOS_DIR . '/' . $filename;
            if ($filename === '' || $filename[0] === '.' || !is_file($filepath)) {
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                exit;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4' => 'video/mp4',
                'mp3' => 'audio/mpeg',
                'webm' => 'video/webm',
                'm4a' => 'audio/mp4',
                'zip' => 'application/zip'
            ];

            // Clear any previous output
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Send proper headers for file download
            header('Content-Description: File Transfer');
            header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filepath));

            // Flush headers
            flush();

            // Read file in chunks to handle large files
            $handle = fopen($filepath, 'rb');
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
            exit;

        case 'file_serve':
            // Logging only — the actual file is served by nginx via /download/
            // The frontend POSTs here before triggering the direct download link
            echo json_encode(['success' => true]);
            break;

        case 'logs':
            requireAdmin($currentUser);
            if ($method === 'DELETE') {
                clearLogs();
                echo json_encode(['success' => true]);
            } else {
                $page         = max(1, (int)($_GET['page']          ?? 1));
                $limit        = min(max(1, (int)($_GET['limit']      ?? 50)), 500);
                $filterAction = (string)($_GET['filter_action'] ?? '');
                $filterMethod = (string)($_GET['filter_method'] ?? '');
                $search       = (string)($_GET['search']        ?? '');
                echo json_encode(getLogs($page, $limit, $filterAction, $filterMethod, $search));
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (ApiError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()] + $e->data);
} catch (PDOException $e) {
    // Never leak SQL details to the browser
    error_log('[api] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
