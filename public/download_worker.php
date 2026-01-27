<?php
/**
 * Download Worker
 * Runs in background to process video downloads
 */

define('DATA_DIR', '/data');
define('VIDEOS_DIR', DATA_DIR . '/videos');
define('DB_FILE', DATA_DIR . '/database.json');
define('QUEUE_FILE', DATA_DIR . '/queue.json');
define('PROGRESS_FILE', DATA_DIR . '/progress.json');

function getDatabase(): array {
    $content = file_get_contents(DB_FILE);
    return json_decode($content, true) ?: ['videos' => []];
}

function saveDatabase(array $db): void {
    file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getQueue(): array {
    $content = file_get_contents(QUEUE_FILE);
    return json_decode($content, true) ?: ['queue' => [], 'current' => null];
}

function saveQueue(array $queue): void {
    file_put_contents(QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function saveProgress(array $progress): void {
    file_put_contents(PROGRESS_FILE, json_encode($progress));
}

function clearProgress(): void {
    saveProgress(['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null]);
}

function isStillCurrentDownload(string $id): bool {
    $queue = getQueue();
    return $queue['current'] !== null && $queue['current']['id'] === $id;
}

function processNextInQueue(): void {
    $queue = getQueue();
    
    if (empty($queue['queue'])) {
        return;
    }
    
    // Get next item from queue
    $item = array_shift($queue['queue']);
    $queue['current'] = $item;
    saveQueue($queue);
    
    // Reset progress
    saveProgress([
        'percent' => 0,
        'status' => 'starting',
        'title' => 'Fetching video info...',
        'id' => $item['id']
    ]);
    
    // Start download
    $cmd = sprintf(
        'php %s %s %s %s > /dev/null 2>&1 &',
        __FILE__,
        escapeshellarg($item['id']),
        escapeshellarg($item['url']),
        escapeshellarg($item['format'])
    );
    exec($cmd);
}

// Main execution
if ($argc < 4) {
    exit(1);
}

$id = $argv[1];
$url = $argv[2];
$format = $argv[3];

// Check if we're still the current download (might have been cancelled)
if (!isStillCurrentDownload($id)) {
    exit(0);
}

// Update progress - starting
saveProgress([
    'percent' => 0,
    'status' => 'starting',
    'title' => 'Fetching video info...',
    'id' => $id
]);

// Get video info first
$infoCmd = sprintf(
    'yt-dlp --dump-json --no-warnings %s 2>/dev/null',
    escapeshellarg($url)
);
$infoJson = shell_exec($infoCmd);

// Check if cancelled
if (!isStillCurrentDownload($id)) {
    exit(0);
}

$info = json_decode($infoJson, true);

$title = $info['title'] ?? 'Unknown';
$title = preg_replace('/[^\w\s\-\.\(\)\[\]]/', '', $title);
$title = trim(substr($title, 0, 100));

if (empty($title)) {
    $title = 'video_' . $id;
}

saveProgress([
    'percent' => 5,
    'status' => 'downloading',
    'title' => $title,
    'id' => $id
]);

// Build download command based on format
$outputTemplate = VIDEOS_DIR . '/' . $id . '_%(title).50s.%(ext)s';

if ($format === 'mp3') {
    $downloadCmd = sprintf(
        'yt-dlp -f "bestaudio" --extract-audio --audio-format mp3 --audio-quality 0 ' .
        '--newline --progress -o %s %s 2>&1',
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
} else {
    $downloadCmd = sprintf(
        'yt-dlp -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best" ' .
        '--merge-output-format mp4 --newline --progress -o %s %s 2>&1',
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
}

// Execute download and capture progress
$handle = popen($downloadCmd, 'r');
$lastPercent = 5;

while (!feof($handle)) {
    $line = fgets($handle);
    
    // Check if cancelled
    if (!isStillCurrentDownload($id)) {
        pclose($handle);
        // Clean up partial files
        $files = glob(VIDEOS_DIR . '/' . $id . '_*');
        foreach ($files as $file) {
            @unlink($file);
        }
        exit(0);
    }
    
    // Parse progress from yt-dlp output
    if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $matches)) {
        $percent = min(95, 5 + (floatval($matches[1]) * 0.9));
        if ($percent > $lastPercent) {
            $lastPercent = $percent;
            saveProgress([
                'percent' => round($percent),
                'status' => 'downloading',
                'title' => $title,
                'id' => $id
            ]);
        }
    }
}

$exitCode = pclose($handle);

// Check if cancelled before finalizing
if (!isStillCurrentDownload($id)) {
    $files = glob(VIDEOS_DIR . '/' . $id . '_*');
    foreach ($files as $file) {
        @unlink($file);
    }
    exit(0);
}

// Find the downloaded file
$files = glob(VIDEOS_DIR . '/' . $id . '_*');

// Filter out .part files
$files = array_filter($files, function($f) {
    return !preg_match('/\.(part|ytdl)$/', $f);
});

$downloadedFile = !empty($files) ? basename(reset($files)) : null;

if ($downloadedFile && $exitCode === 0) {
    saveProgress([
        'percent' => 100,
        'status' => 'complete',
        'title' => $title,
        'id' => $id
    ]);
    
    // Add to database ONLY after successful completion
    $db = getDatabase();
    $ext = pathinfo($downloadedFile, PATHINFO_EXTENSION);
    $filepath = VIDEOS_DIR . '/' . $downloadedFile;
    
    // Check if this ID already exists in database (prevent duplicates)
    $exists = false;
    foreach ($db['videos'] as $video) {
        if ($video['id'] === $id) {
            $exists = true;
            break;
        }
    }
    
    if (!$exists) {
        $db['videos'][] = [
            'id' => $id,
            'title' => $title,
            'filename' => $downloadedFile,
            'type' => $ext === 'mp3' ? 'audio' : 'video',
            'format' => $ext,
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
            'created_at' => date('c')
        ];
        saveDatabase($db);
    }
} else {
    saveProgress([
        'percent' => 0,
        'status' => 'error',
        'title' => 'Download failed: ' . $title,
        'id' => $id
    ]);
    
    // Clean up any partial files on error
    $files = glob(VIDEOS_DIR . '/' . $id . '_*');
    foreach ($files as $file) {
        @unlink($file);
    }
}

// Clear current and process next in queue
$queue = getQueue();
$queue['current'] = null;
saveQueue($queue);

// Small delay to let the UI see the completion status
usleep(500000); // 0.5 seconds

// Check if there are more items in queue
processNextInQueue();
