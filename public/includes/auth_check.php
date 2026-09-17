<?php
/**
 * nginx auth_request endpoint (location /_auth, internal only).
 * Responds 204 when the original request may proceed, 401 when the visitor must sign in, 403 for admin-only pages.
 */

require_once __DIR__ . '/auth.php';

$status = authGate((string)($_SERVER['AUTH_ORIGINAL_URI'] ?? $_SERVER['REQUEST_URI'] ?? '/'));
http_response_code($status === 200 ? 204 : $status);
