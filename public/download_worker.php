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
    file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT));
}

function getQueue(): array {
    $content = file_get_contents(QUEUE_FILE);
    return json_decode($content, true) ?: ['queue' => [], 'current' => null];
}

function saveQueue(array $queue): void {
    file_put_contents(QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT));
}

function saveProgress(array $progress): void {
    file_put_contents(PROGRESS_FILE, json_encode($progress));
}

function processQueue(): void {
    $queue = getQueue();
    
    if (empty($queue['queue'])) {
        return;
    }
    
    // Get next item from queue
    $item = array_shift($queue['queue']);
    $queue['current'] = $item;
    saveQueue($queue);
    
    // Start download
    $cmd = sprintf(
        'php %s %s %s %s',
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

// Update progress
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
$info = json_decode($infoJson, true);

$title = $info['title'] ?? 'Unknown';
$title = preg_replace('/[^\w\s\-\.]/', '', $title);
$title = substr($title, 0, 100);

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

// Find the downloaded file
$files = glob(VIDEOS_DIR . '/' . $id . '_*');
$downloadedFile = !empty($files) ? basename($files[0]) : null;

if ($downloadedFile && $exitCode === 0) {
    saveProgress([
        'percent' => 100,
        'status' => 'complete',
        'title' => $title,
        'id' => $id
    ]);
    
    // Add to database
    $db = getDatabase();
    $ext = pathinfo($downloadedFile, PATHINFO_EXTENSION);
    
    $db['videos'][] = [
        'id' => $id,
        'title' => $title,
        'filename' => $downloadedFile,
        'type' => $ext === 'mp3' ? 'audio' : 'video',
        'format' => $ext,
        'size' => filesize(VIDEOS_DIR . '/' . $downloadedFile),
        'created_at' => date('c')
    ];
    
    saveDatabase($db);
} else {
    saveProgress([
        'percent' => 0,
        'status' => 'error',
        'title' => 'Download failed: ' . $title,
        'id' => $id
    ]);
}

// Clear current and process next in queue
$queue = getQueue();
$queue['current'] = null;
saveQueue($queue);

// Check if there are more items in queue
if (!empty($queue['queue'])) {
    processQueue();
}
