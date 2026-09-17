<?php
/**
 * Download Worker
 * Runs in the background for exactly one job (the queue's "current" job), then starts the next one.
 *
 * Job types:
 *   video          single video/audio        → library
 *   playlist       expands a playlist        → one playlist_item job per entry + one archive job
 *   playlist_item  one entry of a playlist   → the playlist's staging directory (not the library)
 *   archive        zips a playlist's items   → library (single .zip entry)
 *
 * Usage: php download_worker.php <job-id>
 */

require_once __DIR__ . '/includes/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

class JobCancelled extends Exception {}

function isCurrentJob(string $id): bool {
    $queue = getQueue();
    return $queue['current'] !== null && $queue['current']['id'] === $id;
}

/** Throws JobCancelled when the job was cancelled. Rate-limited unless $force is set. */
function assertStillCurrent(string $id, bool $force = false): void {
    static $lastCheck = 0.0;
    $now = microtime(true);
    if (!$force && $now - $lastCheck < 1.0) {
        return;
    }
    $lastCheck = $now;
    if (!isCurrentJob($id)) {
        throw new JobCancelled();
    }
}

function setProgress(array $job, float $percent, string $status, string $title): void {
    saveProgress([
        'percent' => (int)round($percent),
        'status'  => $status,
        'title'   => $title,
        'id'      => $job['id'],
    ]);
}

function failJob(array $job, string $message, ?string $cleanupDir = null): array {
    if ($cleanupDir !== null) {
        removeDir($cleanupDir);
    }
    workerLog("{$job['id']} failed: $message");
    setProgress($job, 0, 'error', $message);
    return [];
}

function findDownloadedFile(string $dir): ?string {
    $best = null;
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (!is_file($file) || preg_match('/\.(part|ytdl|temp|tmp)$/i', $file)) {
            continue;
        }
        if ($best === null || filesize($file) > filesize($best)) {
            $best = $file;
        }
    }
    return $best;
}

/**
 * Download $job['url'] into $workDir with yt-dlp, mapping its progress to [$from, $to].
 * Returns the path of the downloaded file, or null on failure.
 */
function downloadMedia(array $job, string $workDir, string $title, float $from, float $to): ?string {
    $cmd = [
        ...ytDlpCommand(), '--no-playlist', '--newline', '--progress', '-o', $workDir . '/%(id)s.%(ext)s',
        ...ytDlpFormatArgs($job['format']), '--', $job['url'],
    ];

    $proc = proc_open($cmd, [0 => ['file', NULL_DEVICE, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    $lastPercent = $from;
    $outputTail = [];
    try {
        while (($line = fgets($pipes[1])) !== false) {
            assertStillCurrent($job['id']);
            if (!preg_match('/^\[download\]\s+[\d.]+%/', $line)) {
                $outputTail = array_slice([...$outputTail, rtrim($line)], -15);
            }
            if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $matches)) {
                $percent = min($to, $from + (float)$matches[1] * ($to - $from) / 100);
                if ($percent >= $lastPercent + 1) {
                    $lastPercent = $percent;
                    setProgress($job, $percent, 'downloading', $title);
                }
            }
        }
    } catch (JobCancelled $e) {
        proc_terminate($proc, 9);
        fclose($pipes[1]);
        proc_close($proc);
        throw $e;
    }
    fclose($pipes[1]);

    $exitCode = proc_close($proc);
    $file = $exitCode === 0 ? findDownloadedFile($workDir) : null;
    workerLog("{$job['id']} yt-dlp exit $exitCode, file: " . ($file ?? 'none')
        . ($file === null ? "\n    " . implode("\n    ", $outputTail) : ''));
    return $file;
}

function addToLibrary(array $entry): void {
    $db = getDatabase();
    foreach ($db['videos'] as $video) {
        if ($video['id'] === $entry['id']) {
            return;
        }
    }
    $db['videos'][] = $entry;
    saveDatabase($db);
}

// ── Job handlers (each returns follow-up jobs to put at the front of the queue) ──

function runVideo(array $job): array {
    $id = $job['id'];
    $workDir = jobWorkDir($job);
    removeDir($workDir);
    mkdir($workDir, 0755, true);

    // The title is known from the size check when the job was queued; older jobs fetch it here
    $title = $job['title'] ?? null;
    if ($title === null) {
        setProgress($job, 0, 'starting', 'Fetching video info...');
        $info = json_decode((string)runCommand([...ytDlpCommand(), '--dump-json', '--no-playlist', '--no-warnings', '--', $job['url']]), true);
        assertStillCurrent($id, true);
        $title = sanitizeTitle($info['title'] ?? null);
    }
    $title = $title ?: 'video_' . $id;
    setProgress($job, 5, 'downloading', $title);

    $file = downloadMedia($job, $workDir, $title, 5, 95);
    assertStillCurrent($id, true);
    if ($file === null) {
        return failJob($job, 'Download failed: ' . $title, $workDir);
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $filename = $id . '_' . sanitizeTitle($title, 80) . '.' . $ext;
    $target = VIDEOS_DIR . '/' . $filename;

    withLock(function () use ($id, $file, $target, $filename, $title, $ext) {
        if (!isCurrentJob($id)) {
            throw new JobCancelled();
        }
        if (!rename($file, $target)) {
            throw new RuntimeException('Could not move file into library');
        }
        addToLibrary([
            'id'         => $id,
            'title'      => $title,
            'filename'   => $filename,
            'type'       => $ext === 'mp3' ? 'audio' : 'video',
            'format'     => $ext,
            'size'       => filesize($target),
            'created_at' => date('c'),
        ]);
    });
    removeDir($workDir);

    setProgress($job, 100, 'complete', $title);
    return [];
}

function runPlaylist(array $job): array {
    $id = $job['id'];
    setProgress($job, 0, 'starting', 'Fetching playlist info...');

    $json = runCommand([...ytDlpCommand(), '--flat-playlist', '--dump-single-json', '--no-warnings', '--', $job['url']]);
    assertStillCurrent($id, true);
    $data = json_decode((string)$json, true);
    $entries = playlistEntries(is_array($data) ? $data : null);

    if (!$entries) {
        return failJob($job, 'Playlist is empty or unavailable');
    }

    $title = sanitizeTitle($data['title'] ?? null) ?: 'playlist_' . $id;
    $total = count($entries);
    $now = date('c');

    // Per-item size estimates keep the queue's disk reservation accurate (items + archive = 2x the playlist)
    $durations = array_filter(array_map(fn(array $entry) => is_numeric($entry['duration'] ?? null) ? (float)$entry['duration'] : null, $entries), 'is_float');
    $averageDuration = $durations ? array_sum($durations) / count($durations) : 0.0;
    $itemsEstimate = 0;

    $jobs = [];
    foreach ($entries as $i => $entry) {
        $estimate = estimateBytesForDuration($durations[$i] ?? $averageDuration, $job['format']);
        $itemsEstimate += $estimate;
        $jobs[] = [
            'id'             => generateId('itm_'),
            'type'           => 'playlist_item',
            'url'            => 'https://www.youtube.com/watch?v=' . $entry['id'],
            'format'         => $job['format'],
            'title'          => sanitizeTitle($entry['title'] ?? null) ?: $entry['id'],
            'playlist_id'    => $id,
            'playlist_title' => $title,
            'index'          => $i + 1,
            'total'          => $total,
            'estimated_size' => $estimate,
            'created_at'     => $now,
        ];
    }
    $jobs[] = [
        'id'             => generateId('zip_'),
        'type'           => 'archive',
        'format'         => $job['format'],
        'playlist_id'    => $id,
        'playlist_title' => $title,
        'total'          => $total,
        'estimated_size' => $itemsEstimate,
        'created_at'     => $now,
    ];

    removeDir(playlistDir($id));
    mkdir(playlistDir($id), 0755, true);

    setProgress($job, 100, 'complete', $title . " ($total items)");
    return $jobs;
}

function runPlaylistItem(array $job): array {
    $id = $job['id'];
    $title = $job['title'];
    $workDir = jobWorkDir($job);
    removeDir($workDir);
    mkdir($workDir, 0755, true);

    setProgress($job, 0, 'downloading', $title);
    $file = downloadMedia($job, $workDir, $title, 0, 100);
    assertStillCurrent($id, true);
    if ($file === null) {
        return failJob($job, 'Download failed: ' . $title, $workDir);
    }

    // "03 - Title.mp3" keeps playlist order inside the archive
    $index = str_pad((string)$job['index'], max(2, strlen((string)$job['total'])), '0', STR_PAD_LEFT);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $target = playlistDir($job['playlist_id']) . '/' . $index . ' - ' . (sanitizeTitle($title, 80) ?: 'item') . '.' . $ext;

    withLock(function () use ($id, $file, $target) {
        if (!isCurrentJob($id)) {
            throw new JobCancelled();
        }
        if (!rename($file, $target)) {
            throw new RuntimeException('Could not move playlist item');
        }
    });
    workerLog("$id stored as " . basename($target));
    removeDir($workDir);

    setProgress($job, 100, 'complete', $title);
    return [];
}

function runArchive(array $job): array {
    $id = $job['id'];
    $playlistId = $job['playlist_id'];
    $title = $job['playlist_title'];
    $dir = playlistDir($playlistId);

    // Dot files are short aliases from an earlier, interrupted attempt (see below)
    $files = array_values(array_filter(glob($dir . '/*') ?: [], fn(string $file) => is_file($file) && basename($file)[0] !== '.'));
    natsort($files);
    if (!$files) {
        return failJob($job, 'No playlist items were downloaded: ' . $title, $dir);
    }
    if (!class_exists('ZipArchive')) {
        return failJob($job, 'PHP zip extension is missing');
    }

    // The archive is a stored (uncompressed) copy of the items, so it needs about their size again
    $storage = storageInfo();
    $itemsSize = array_sum(array_map('filesize', $files));
    if ($storage['total'] > 0 && $storage['free'] - $itemsSize < $storage['min_free']) {
        return failJob($job, sprintf('Not enough disk space to archive %s (needs ≈ %s, at least %s %% must stay free)',
            $title, formatBytes($itemsSize), $storage['min_free_percent']), $dir);
    }

    setProgress($job, 0, 'archiving', $title);

    $tmpZip = STAGING_DIR . '/' . $playlistId . '.zip';
    @unlink($tmpZip);

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return failJob($job, 'Could not create archive: ' . $title);
    }
    foreach ($files as $index => $file) {
        $name = basename($file);
        if (!@$zip->addFile($file, $name)) {
            // On Windows libzip cannot open paths longer than 260 characters: add a short hard link to the same file
            $alias = $dir . '/.' . $index . '.' . pathinfo($name, PATHINFO_EXTENSION);
            @unlink($alias);
            if (!@link($file, $alias) || !$zip->addFile($alias, $name)) {
                $zip->unchangeAll();
                $zip->close();
                @unlink($tmpZip);
                return failJob($job, "Could not add \"$name\" to the archive: $title", $dir);
            }
        }
        // Media is already compressed; storing is much faster and barely larger
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    if (method_exists($zip, 'registerProgressCallback')) {
        $zip->registerProgressCallback(0.01, function (float $rate) use ($job, $title) {
            setProgress($job, $rate * 99, 'archiving', $title);
        });
    }
    if (method_exists($zip, 'registerCancelCallback')) {
        // libzip calls this very often; assertStillCurrent() rate-limits the queue file reads
        $zip->registerCancelCallback(function () use ($id): int {
            try {
                assertStillCurrent($id);
                return 0;
            } catch (JobCancelled $e) {
                return 1;
            }
        });
    }

    workerLog("$id archiving " . count($files) . " files: " . implode(' | ', array_map('basename', $files)));
    $closed = $zip->close();
    workerLog("$id zip close: " . ($closed ? 'ok' : 'failed') . ', status: ' . $zip->getStatusString());
    assertStillCurrent($id, true);

    // Never publish an archive that silently lost entries
    $check = new ZipArchive();
    $entries = $closed && $check->open($tmpZip, ZipArchive::RDONLY) === true ? $check->numFiles : -1;
    if ($entries !== -1) {
        $check->close();
    }
    if ($entries !== count($files)) {
        @unlink($tmpZip);
        return failJob($job, "Archiving failed ($entries of " . count($files) . " files written): $title", $dir);
    }

    $filename = $playlistId . '_' . (sanitizeTitle($title, 80) ?: 'playlist') . '.zip';
    $target = VIDEOS_DIR . '/' . $filename;

    withLock(function () use ($id, $job, $playlistId, $title, $tmpZip, $target, $filename, $files) {
        if (!isCurrentJob($id)) {
            throw new JobCancelled();
        }
        if (!rename($tmpZip, $target)) {
            throw new RuntimeException('Could not move archive into library');
        }
        addToLibrary([
            'id'             => $playlistId,
            'title'          => $title,
            'filename'       => $filename,
            'type'           => 'playlist',
            'format'         => 'zip',
            'content_format' => $job['format'],
            'items'          => count($files),
            'total'          => $job['total'] ?? count($files),
            'size'           => filesize($target),
            'created_at'     => date('c'),
        ]);
    });
    removeDir($dir);

    setProgress($job, 100, 'complete', $title);
    return [];
}

/** Clear the current job (if it is still ours), queue follow-ups and start the next job. */
function finishJob(string $id, array $followUps): void {
    withLock(function () use ($id, $followUps) {
        $queue = getQueue();
        if ($queue['current'] === null || $queue['current']['id'] !== $id) {
            return;
        }
        $queue['queue'] = array_merge($followUps, $queue['queue']);
        $queue['current'] = null;
        $queue['pid'] = null;
        saveQueue($queue);
        startNextJob();
    });
}

// ── Main ─────────────────────────────────────────────────

$id = $argv[1] ?? '';
if ($id === '') {
    exit(1);
}

// Become a process-group leader so a cancel can kill yt-dlp/ffmpeg along with this worker
if (function_exists('posix_setsid')) {
    @posix_setsid();
}

$job = getQueue()['current'];
if ($job === null || $job['id'] !== $id) {
    exit(0);
}

workerLog("$id start " . jobType($job) . ' ' . ($job['title'] ?? $job['playlist_title'] ?? $job['url'] ?? ''));
try {
    // The disk may have filled up since the job was queued
    $storage = storageInfo();
    if (jobType($job) !== 'archive' && $storage['total'] > 0 && $storage['free'] < $storage['min_free']) {
        $followUps = failJob($job, sprintf('Not enough disk space: only %s free, at least %s %% must stay free',
            formatBytes($storage['free']), $storage['min_free_percent']));
    } else {
        $followUps = match (jobType($job)) {
            'playlist'      => runPlaylist($job),
            'playlist_item' => runPlaylistItem($job),
            'archive'       => runArchive($job),
            default         => runVideo($job),
        };
    }
} catch (JobCancelled $e) {
    // The API already cleaned up and moved on
    exit(0);
} catch (Throwable $e) {
    $followUps = [];
    $cleanup = jobType($job) === 'archive' ? null : jobWorkDir($job);
    failJob($job, 'Error: ' . $e->getMessage(), $cleanup);
}

// Give the UI a moment to show the final status
usleep(500000);
finishJob($id, $followUps);
