<?php
/**
 * Router for PHP's built-in web server: mirrors nginx.conf for local development.
 * Started by dev/serve.php; not used in the Docker image.
 */

require_once __DIR__ . '/../public/includes/auth.php';

$publicDir = realpath(__DIR__ . '/../public');
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

function respond(int $status, string $message): bool {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    return true;
}

// Same deny rules as nginx.conf
if (str_contains($path, "\0")
    || str_starts_with($path, '/includes/')
    || $path === '/download_worker.php'
    || preg_match('#^/(download|videos)/\.#', $path)) {
    return respond(403, '403 Forbidden');
}

// Same sign-in requirement as nginx's auth_request: only /login.php and /setup.php are public
$gate = authGate($_SERVER['REQUEST_URI'] ?? '/');
if ($gate === 401) {
    if ($path === '/api.php') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sign in required', 'login_required' => true]);
        return true;
    }
    header('Location: /login.php', true, 302);
    return true;
}
if ($gate === 403) {
    return respond(403, '403 Forbidden');
}

// /download/<file> (forced download) and /videos/<file> (inline), both served from VIDEOS_DIR
if (preg_match('#^/(download|videos)/([^/\\\\]+)$#', $path, $m)) {
    $file = VIDEOS_DIR . '/' . $m[2];
    if (!is_file($file)) {
        return respond(404, '404 Not Found');
    }

    $mimeTypes = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'webm' => 'video/webm', 'm4a' => 'audio/mp4', 'zip' => 'application/zip'];
    if ($m[1] === 'download') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment');
    } else {
        header('Content-Type: ' . ($mimeTypes[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    }
    header('Content-Length: ' . filesize($file));

    set_time_limit(0);
    $handle = fopen($file, 'rb');
    while (!feof($handle)) {
        echo fread($handle, 1024 * 1024);
        flush();
    }
    fclose($handle);
    return true;
}

// PHP scripts, CSS, JS, other static files: let the built-in server handle them
if (str_ends_with($path, '.php')) {
    return false;
}
$static = realpath($publicDir . $path);
if ($path !== '/' && $static !== false && is_file($static)) {
    return str_starts_with($static, $publicDir . DIRECTORY_SEPARATOR) ? false : respond(404, '404 Not Found');
}

// SPA fallback (nginx: try_files $uri $uri/ /index.html)
header('Content-Type: text/html; charset=utf-8');
readfile($publicDir . '/index.html');
return true;
