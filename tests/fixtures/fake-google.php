<?php
/**
 * Fake Google certificate endpoint for tests/integration.php, served with
 *   php -S 127.0.0.1:PORT tests/fixtures/fake-google.php
 *
 * GET /certs   {"test-key-1": "<PEM certificate>"}, like https://www.googleapis.com/oauth2/v1/certs
 *
 * The matching private key (tests/fixtures/google-test-key.pem) is a throwaway test key: the tests use it to sign the
 * ID tokens that Google's sign-in button would normally hand to the browser.
 */

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/certs') {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=300');
    echo json_encode(['test-key-1' => file_get_contents(__DIR__ . '/google-test-cert.pem')]);
    exit;
}

http_response_code(404);
