<?php
/**
 * YouTube Archiver API
 * Handles all backend operations for downloading, managing, and serving videos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration
define('DATA_DIR', '/data');
define('VIDEOS_DIR', DATA_DIR . '/videos');
define('DB_FILE', DATA_DIR . '/database.json');
define('QUEUE_FILE', DATA_DIR . '/queue.json');
define('PROGRESS_FILE', DATA_DIR . '/progress.json');
define('PID_FILE', DATA_DIR . '/download.pid');

// Ensure directories exist
if (!file_exists(VIDEOS_DIR)) {
    mkdir(VIDEOS_DIR, 0755, true);
}

// Initialize database if not exists
if (!file_exists(DB_FILE)) {
    file_put_contents(DB_FILE, json_encode(['videos' => []]));
}

// Initialize queue if not exists
if (!file_exists(QUEUE_FILE)) {
    file_put_contents(QUEUE_FILE, json_encode(['queue' => [], 'current' => null]));
}

// Helper functions
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

function getProgress(): array {
    if (!file_exists(PROGRESS_FILE)) {
        return ['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null];
    }
    $content = file_get_contents(PROGRESS_FILE);
    return json_decode($content, true) ?: ['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null];
}

function saveProgress(array $progress): void {
    file_put_contents(PROGRESS_FILE, json_encode($progress));
}

function clearProgress(): void {
    saveProgress(['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null]);
}

function generateId(): string {
    return uniqid('vid_', true);
}

function getYtDlpVersion(): string {
    $output = shell_exec('yt-dlp --version 2>/dev/null');
    return trim($output) ?: 'unknown';
}

function getLatestYtDlpVersion(): array {
    $ch = curl_init('https://api.github.com/repos/yt-dlp/yt-dlp/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'YT-Archiver/1.0',
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        return [
            'version' => $data['tag_name'] ?? 'unknown',
            'url' => $data['html_url'] ?? ''
        ];
    }
    return ['version' => 'unknown', 'url' => ''];
}

function updateYtDlp(): array {
    $output = shell_exec('pip install --upgrade yt-dlp 2>&1');
    $newVersion = getYtDlpVersion();
    return [
        'success' => true,
        'version' => $newVersion,
        'output' => $output
    ];
}

function startDownload(string $url, string $format): array {
    $queue = getQueue();
    $id = generateId();
    
    $item = [
        'id' => $id,
        'url' => $url,
        'format' => $format,
        'status' => 'queued',
        'created_at' => date('c')
    ];
    
    $queue['queue'][] = $item;
    saveQueue($queue);
    
    // Trigger download process if nothing is currently downloading
    if ($queue['current'] === null) {
        processQueue();
    }
    
    return ['success' => true, 'id' => $id, 'message' => 'Added to queue'];
}

function processQueue(): void {
    $queue = getQueue();
    
    if ($queue['current'] !== null || empty($queue['queue'])) {
        return;
    }
    
    // Get next item from queue
    $item = array_shift($queue['queue']);
    $queue['current'] = $item;
    saveQueue($queue);
    
    // Reset progress for new download
    saveProgress([
        'percent' => 0,
        'status' => 'starting',
        'title' => 'Fetching video info...',
        'id' => $item['id']
    ]);
    
    // Start download in background
    $cmd = sprintf(
        'php %s/download_worker.php %s %s %s > /dev/null 2>&1 & echo $!',
        __DIR__,
        escapeshellarg($item['id']),
        escapeshellarg($item['url']),
        escapeshellarg($item['format'])
    );
    $pid = trim(shell_exec($cmd));
    
    // Save PID for potential cancellation
    if ($pid) {
        file_put_contents(PID_FILE, $pid);
    }
}

function cancelDownload(string $id): array {
    $queue = getQueue();
    
    // Check if it's the current download
    if ($queue['current'] !== null && $queue['current']['id'] === $id) {
        // Kill the download process
        if (file_exists(PID_FILE)) {
            $pid = trim(file_get_contents(PID_FILE));
            if ($pid) {
                // Kill the worker and any child processes (yt-dlp)
                shell_exec("pkill -P $pid 2>/dev/null");
                shell_exec("kill $pid 2>/dev/null");
            }
            unlink(PID_FILE);
        }
        
        // Clean up any partial files
        $files = glob(VIDEOS_DIR . '/' . $id . '_*');
        foreach ($files as $file) {
            unlink($file);
        }
        
        // Also clean up .part files
        $partFiles = glob(VIDEOS_DIR . '/*.part');
        foreach ($partFiles as $file) {
            unlink($file);
        }
        
        // Clear current and progress
        $queue['current'] = null;
        saveQueue($queue);
        clearProgress();
        
        // Process next in queue
        processQueue();
        
        return ['success' => true, 'message' => 'Download cancelled'];
    }
    
    // Check if it's in the queue
    $found = false;
    $queue['queue'] = array_filter($queue['queue'], function($item) use ($id, &$found) {
        if ($item['id'] === $id) {
            $found = true;
            return false;
        }
        return true;
    });
    $queue['queue'] = array_values($queue['queue']); // Re-index array
    
    if ($found) {
        saveQueue($queue);
        return ['success' => true, 'message' => 'Removed from queue'];
    }
    
    return ['success' => false, 'message' => 'Download not found'];
}

function getVideos(): array {
    $db = getDatabase();
    return $db['videos'];
}

function deleteVideo(string $id): array {
    $db = getDatabase();
    $index = null;
    
    // Find the video by id
    foreach ($db['videos'] as $i => $video) {
        if ($video['id'] === $id) {
            $index = $i;
            break;
        }
    }
    
    if ($index === null) {
        return ['success' => false, 'message' => 'Video not found'];
    }
    
    $video = $db['videos'][$index];
    $filepath = VIDEOS_DIR . '/' . $video['filename'];
    
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    array_splice($db['videos'], $index, 1);
    saveDatabase($db);
    
    return ['success' => true, 'message' => 'Video deleted'];
}

function getDownloadStatus(): array {
    $queue = getQueue();
    $progress = getProgress();
    
    // Only return progress if it matches current download
    if ($queue['current'] === null || $progress['id'] !== $queue['current']['id']) {
        $progress = ['percent' => 0, 'status' => 'idle', 'title' => '', 'id' => null];
    }
    
    return [
        'current' => $queue['current'],
        'queue' => $queue['queue'],
        'progress' => $progress
    ];
}

// Router
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

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
            
        case 'update':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            echo json_encode(updateYtDlp());
            break;
            
        case 'download':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $url = $input['url'] ?? '';
            $format = $input['format'] ?? 'mp4';
            
            if (empty($url)) {
                throw new Exception('URL is required');
            }
            
            echo json_encode(startDownload($url, $format));
            break;
            
        case 'cancel':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? '';
            
            if (empty($id)) {
                throw new Exception('Download ID is required');
            }
            
            echo json_encode(cancelDownload($id));
            break;
            
        case 'status':
            echo json_encode(getDownloadStatus());
            break;
            
        case 'process':
            processQueue();
            echo json_encode(['success' => true]);
            break;
            
        case 'videos':
            if ($method === 'GET') {
                echo json_encode(['videos' => getVideos()]);
            } elseif ($method === 'DELETE') {
                $id = $_GET['id'] ?? '';
                if (empty($id)) {
                    throw new Exception('Video ID is required');
                }
                echo json_encode(deleteVideo($id));
            }
            break;
            
        case 'serve':
            $filename = $_GET['file'] ?? '';
            if (empty($filename)) {
                http_response_code(400);
                echo json_encode(['error' => 'Filename is required']);
                exit;
            }
            
            $filepath = VIDEOS_DIR . '/' . basename($filename);
            if (!file_exists($filepath)) {
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                exit;
            }
            
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4' => 'video/mp4',
                'mp3' => 'audio/mpeg',
                'webm' => 'video/webm',
                'm4a' => 'audio/mp4'
            ];
            
            // Clear any previous output
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Send proper headers for file download
            header('Content-Description: File Transfer');
            header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
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
            
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
