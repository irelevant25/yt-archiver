<?php
/**
 * Unit tests for public/includes/common.php (no Docker, yt-dlp or Linux needed).
 * Run with PHP >= 8.0:  php tests/unit.php
 */

error_reporting(E_ALL);

$dataDir = str_replace('\\', '/', sys_get_temp_dir()) . '/yt-archiver-test-' . getmypid();
mkdir($dataDir . '/videos', 0755, true);
define('DATA_DIR', $dataDir);
// Workers spawned by startNextJob() look at an empty data dir, find no job and exit at once,
// which simulates a crashed worker for the recovery tests
putenv('YTA_DATA_DIR=' . $dataDir . '/no-such-dir');

require __DIR__ . '/../public/includes/common.php';

$failures = 0;
function check(string $name, mixed $expected, mixed $actual): void {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL $name\n  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// ── parseYoutubeUrl ──────────────────────────────────────
$urls = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ'                              => ['dQw4w9WgXcQ', null],
    'http://youtube.com/watch?v=dQw4w9WgXcQ&t=10'                              => ['dQw4w9WgXcQ', null],
    'https://youtu.be/dQw4w9WgXcQ?si=abc'                                      => ['dQw4w9WgXcQ', null],
    'https://music.youtube.com/watch?v=dQw4w9WgXcQ&list=OLAK5uy_abc'           => ['dQw4w9WgXcQ', 'OLAK5uy_abc'],
    'https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG' => [null, 'PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG'],
    'https://youtube.com/playlist/?list=PLabc'                                 => [null, 'PLabc'],
    'https://m.youtube.com/shorts/dQw4w9WgXcQ'                                 => ['dQw4w9WgXcQ', null],
    'https://www.youtube.com/watch?v=bad'                                      => null,
    'https://www.youtube.com/channel/UCabc'                                    => null,
    'https://evil.com/watch?v=dQw4w9WgXcQ'                                     => null,
    'https://www.youtube.com.evil.com/watch?v=dQw4w9WgXcQ'                     => null,
    'https://user@www.youtube.com/watch?v=dQw4w9WgXcQ'                         => null,
    'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'                         => null,
    'https://www.youtube.com/playlist?list=PL;rm%20-rf'                        => null,
    'javascript:alert(1)'                                                      => null,
    'file:///etc/passwd'                                                       => null,
    ''                                                                         => null,
];
foreach ($urls as $url => $expected) {
    $parsed = parseYoutubeUrl($url);
    check("parseYoutubeUrl($url)", $expected, $parsed === null ? null : [$parsed['video'], $parsed['list']]);
}

// ── sanitizeTitle ────────────────────────────────────────
check('sanitize keeps diacritics', 'Príliš žluťoučký kůň úpěl', sanitizeTitle('Príliš žluťoučký kůň "úpěl"'));
check('sanitize strips markup and paths', 'script ..etcpasswd', sanitizeTitle('<script> ../etc/passwd'));
check('sanitize no leading dots', 'hidden', sanitizeTitle('...hidden'));
check('sanitize control chars', 'abcd', sanitizeTitle("a\x00b/c\\d"));
check('sanitize keeps punctuation', 'Song (Official) [4K] - A & B!', sanitizeTitle('Song (Official) [4K] - A & B!'));
check('sanitize CJK', '日本語 タイトル', sanitizeTitle('日本語   タイトル'));
check('sanitize length is in characters', 80, mb_strlen(sanitizeTitle(str_repeat('ž', 300), 80)));

// ── ipInRange ────────────────────────────────────────────
check('ip exact', true, ipInRange('203.0.113.7', '203.0.113.7'));
check('ip exact mismatch', false, ipInRange('203.0.113.8', '203.0.113.7'));
check('ipv4 cidr in', true, ipInRange('173.245.48.10', '173.245.48.0/20'));
check('ipv4 cidr out', false, ipInRange('173.245.64.1', '173.245.48.0/20'));
check('ipv4 /0', true, ipInRange('8.8.8.8', '0.0.0.0/0'));
check('ipv6 cidr in', true, ipInRange('2400:cb00:1::1', '2400:cb00::/32'));
check('ipv6 vs ipv4 range', false, ipInRange('2400:cb00::1', '173.245.48.0/20'));
check('invalid bits', false, ipInRange('1.2.3.4', '1.2.3.0/abc'));
check('too many bits', false, ipInRange('1.2.3.4', '1.2.3.0/33'));
check('garbage ip', false, ipInRange('not-an-ip', '0.0.0.0/0'));

// ── Storage + queue state machine ────────────────────────
saveQueue(['queue' => [['id' => 'a', 'url' => 'u', 'format' => 'mp4'], ['id' => 'b', 'url' => 'u', 'format' => 'mp3']], 'current' => null]);

$ids = fn() => array_column(getQueue()['queue'], 'id');

$waitForWorkerExit = function (): void {
    for ($i = 0; $i < 100 && isWorkerAlive(getQueue()['pid']); $i++) {
        usleep(100000);
    }
};

// Re-entrant lock must not deadlock
withLock(fn() => withLock(fn() => startNextJob()));
check('start takes first job', 'a', getQueue()['current']['id']);
check('start leaves rest queued', ['b'], $ids());
check('start records worker pid', true, getQueue()['pid'] > 0);

$waitForWorkerExit();
withLock(fn() => recoverStaleJob());
check('first crash re-queues', ['a', 'b'], $ids());
check('first crash clears current', null, getQueue()['current']);

withLock(fn() => startNextJob());
$waitForWorkerExit();
withLock(fn() => recoverStaleJob());
check('second crash drops job', ['b'], $ids());

withLock(fn() => startNextJob());
check('next job starts', 'b', getQueue()['current']['id']);
check('progress tracks current job', 'b', getProgress()['id']);

// ── Staging paths ────────────────────────────────────────
$item = ['id' => 'itm_1', 'type' => 'playlist_item', 'playlist_id' => 'pl_1'];
check('playlist item work dir', STAGING_DIR . '/pl_1/itm_1', jobWorkDir($item));
check('video work dir', STAGING_DIR . '/vid_1', jobWorkDir(['id' => 'vid_1']));

mkdir(jobWorkDir($item), 0755, true);
file_put_contents(jobWorkDir($item) . '/x.part', 'x');
removeDir(playlistDir('pl_1'));
check('removeDir removes tree', false, is_dir(playlistDir('pl_1')));

// ── Size estimates ───────────────────────────────────────
check('mp4 estimate sums stream sizes', 250, estimateVideoBytes(['duration' => 60, 'requested_formats' => [['filesize' => 200], ['filesize_approx' => 50]]], 'mp4'));
check('mp4 estimate falls back to duration', 60 * 400000, estimateVideoBytes(['duration' => 60, 'requested_formats' => [['filesize' => 200], []]], 'mp4'));
check('mp4 single format size', 1234, estimateVideoBytes(['filesize' => 1234], 'mp4'));
check('mp3 estimate uses duration', 60 * 30625, estimateVideoBytes(['duration' => 60, 'filesize' => 999], 'mp3'));
check('estimate unknown without size and duration', null, estimateVideoBytes([], 'mp3'));
check('playlist reserves items plus archive', 200, jobReservedBytes(['type' => 'playlist', 'estimated_size' => 100]));
check('video reserves its size', 100, jobReservedBytes(['type' => 'video', 'estimated_size' => 100]));
check('legacy job reserves nothing', 0, jobReservedBytes(['url' => 'u']));
check('formatBytes', '1.5 GB', formatBytes(1.5 * 1024 ** 3));
check('playlistEntries skips private and invalid', ['AAAAAAAAAAA'], array_column(playlistEntries(['entries' => [
    ['id' => 'AAAAAAAAAAA', 'title' => 'ok'], ['id' => 'BBBBBBBBBBB', 'title' => '[Private video]'], ['id' => 'bad'], 'garbage',
]]), 'id'));

// ── Download decision (MIN 10 %, large threshold 1 GiB) ──
$storage = fn(int $free, int $reserved = 0, int $total = 1000) => [
    'total' => $total, 'free' => $free, 'library' => 0, 'reserved' => $reserved,
    'min_free' => (int)ceil($total * 0.1), 'min_free_percent' => 10.0, 'large_download' => LARGE_DOWNLOAD_BYTES,
];
$video = fn(?int $size, bool $live = false) => ['type' => 'video', 'estimated_size' => $size, 'live' => $live];
$decide = fn(array $inspection, array $storage) => (function (array $d) {
    return [$d['blocked'] !== null, $d['confirmation_required'], count($d['reasons'])];
})(evaluateDownload($inspection, $storage));

check('small download passes', [false, false, 0], $decide($video(100), $storage(500)));
check('low remaining space needs confirmation', [false, true, 1], $decide($video(350), $storage(500)));
check('download below the minimum is blocked', [true, false, 0], $decide($video(450), $storage(500)));
check('already below the minimum is blocked', [true, false, 0], $decide($video(1), $storage(90)));
check('queue reservations count', [true, false, 0], $decide($video(150), $storage(500, 300)));
check('playlist peak (x2) counts', [false, true, 1], $decide(['type' => 'playlist', 'estimated_size' => 200, 'live' => false], $storage(500)));
check('unknown disk never blocks', [false, false, 0], $decide($video(100), $storage(0, 0, 0)));
check('large download needs confirmation', [false, true, 1], $decide($video(LARGE_DOWNLOAD_BYTES), $storage(5 * 10 ** 12, 0, 10 ** 13)));
check('live stream needs confirmation', [false, true, 1], $decide($video(null, true), $storage(5 * 10 ** 12, 0, 10 ** 13)));
check('blocked message mentions the queue', true, str_contains((string)evaluateDownload($video(150), $storage(500, 300))['blocked'], 'queue'));

// ── Sign-in helpers (includes/auth.php) ──────────────────
require_once __DIR__ . '/../public/includes/auth.php';

check('next: local path', '/users.html?x=1', safeNextPath('/users.html?x=1'));
check('next: other host', '/', safeNextPath('https://evil.example/'));
check('next: protocol-relative', '/', safeNextPath('//evil.example/'));
check('next: backslash trick', '/', safeNextPath('/\\evil.example'));
check('next: no login loop', '/', safeNextPath('/login.php?action=start'));
check('next: not a string', '/', safeNextPath(['/x']));
check('base64url round trip', "\xff\xfe binary?", base64UrlDecode(base64UrlEncode("\xff\xfe binary?")));

$token = fn(array $claims) => base64UrlEncode('{"alg":"RS256"}') . '.' . base64UrlEncode(json_encode($claims)) . '.sig';
$claims = ['iss' => 'https://accounts.google.com', 'aud' => 'client-1', 'sub' => '123', 'email' => 'a@example.com',
    'email_verified' => true, 'nonce' => 'n1', 'exp' => 2000];
$claimError = function (array $override, string $nonce = 'n1') use ($token, $claims): ?string {
    try {
        validateIdTokenClaims($token(array_merge($claims, $override)), 'client-1', $nonce, 1000);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
check('id token: valid', null, $claimError([]));
check('id token: legacy issuer', null, $claimError(['iss' => 'accounts.google.com']));
check('id token: string email_verified', null, $claimError(['email_verified' => 'true']));
check('id token: wrong issuer', true, str_contains((string)$claimError(['iss' => 'https://evil.example']), 'issuer'));
check('id token: wrong audience', true, str_contains((string)$claimError(['aud' => 'client-2']), 'audience'));
check('id token: multiple audiences need azp', true, str_contains((string)$claimError(['aud' => ['client-1', 'other']]), 'audience'));
check('id token: multiple audiences with azp', null, $claimError(['aud' => ['client-1', 'other'], 'azp' => 'client-1']));
check('id token: expired', true, str_contains((string)$claimError(['exp' => 900]), 'expiry'));
check('id token: nonce mismatch', true, str_contains((string)$claimError([], 'n2'), 'another tab'));
check('id token: not yet valid', true, str_contains((string)$claimError(['nbf' => 1200]), 'expiry'));
check('id token: unverified email', true, str_contains((string)$claimError(['email_verified' => false]), 'not verified'));
check('id token: missing subject', true, str_contains((string)$claimError(['sub' => '']), 'subject'));
check('id token: garbage', true, (function () {
    try { validateIdTokenClaims('not-a-token', 'client-1', 'n1'); return false; } catch (RuntimeException) { return true; }
})());

// Signature verification with the throwaway fixture key (what tests/fixtures/fake-google.php publishes)
$certificates = ['test-key-1' => file_get_contents(__DIR__ . '/fixtures/google-test-cert.pem')];
$signed = function (array $claims, array $header = ['alg' => 'RS256', 'kid' => 'test-key-1']): string {
    $unsigned = base64UrlEncode(json_encode($header)) . '.' . base64UrlEncode(json_encode($claims));
    openssl_sign($unsigned, $signature, file_get_contents(__DIR__ . '/fixtures/google-test-key.pem'), OPENSSL_ALGO_SHA256);
    return $unsigned . '.' . base64UrlEncode($signature);
};
$verifyError = function (string $jwt) use ($certificates): ?string {
    try {
        verifyGoogleIdToken($jwt, 'client-1', 'n1', 1000, $certificates);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
check('signature: valid token', null, $verifyError($signed($claims)));
[$h, $p, $s] = explode('.', $signed($claims));
check('signature: tampered payload', true, str_contains((string)$verifyError($h . '.' . base64UrlEncode(json_encode(['email' => 'x@evil.example'] + $claims)) . '.' . $s), '(signature)'));
check('signature: unknown key id', true, str_contains((string)$verifyError($signed($claims, ['alg' => 'RS256', 'kid' => 'other'])), '(signature)'));
check('signature: alg none refused', true, str_contains((string)$verifyError(base64UrlEncode('{"alg":"none","kid":"test-key-1"}') . '.' . $p . '.x'), 'invalid'));
check('signature: HS256 refused', true, str_contains((string)$verifyError($signed($claims, ['alg' => 'HS256', 'kid' => 'test-key-1'])), 'invalid'));
check('signature: unsigned token refused', true, str_contains((string)$verifyError("$h.$p."), 'invalid'));
check('signature: claims still checked', true, str_contains((string)$verifyError($signed(['aud' => 'client-2'] + $claims)), 'audience'));

check('gate: login page is public', 200, authGate('/login.php?state=x'));
check('gate: setup page is public', 200, authGate('/setup.php'));
check('gate: app needs sign-in before setup', 401, authGate('/'));
check('gate: API needs sign-in', 401, authGate('/api.php?action=status'));
check('gate: encoded path still gated', 401, authGate('/%6Cogin.php.html'));

// ── Database settings: config.php (entered in setup) wins over the environment (docker-compose service) ──
$dbEnv = ['YTA_DB_HOST', 'YTA_DB_PORT', 'YTA_DB_NAME', 'YTA_DB_USER', 'YTA_DB_PASSWORD', 'YTA_DB_PASSWORD_FILE'];
foreach ($dbEnv as $name) {
    putenv($name);
}
check('db: nothing configured', null, databaseSettings());
putenv('YTA_DB_HOST=postgres');
check('db: environment defaults', ['dsn' => 'pgsql:host=postgres;port=5432;dbname=yt_archiver', 'user' => 'yt_archiver', 'password' => '', 'source' => 'environment'], databaseSettings());
putenv('YTA_DB_PORT=6543');
putenv('YTA_DB_NAME=archive');
putenv('YTA_DB_USER=app');
putenv('YTA_DB_PASSWORD=from-env');
check('db: environment values', ['dsn' => 'pgsql:host=postgres;port=6543;dbname=archive', 'user' => 'app', 'password' => 'from-env', 'source' => 'environment'], databaseSettings());
file_put_contents("$dataDir/db-secret", "from-file\n");
putenv("YTA_DB_PASSWORD_FILE=$dataDir/db-secret");
check('db: password file wins, trailing newline removed', 'from-file', databaseSettings()['password']);
check('db: description has no credentials', 'archive @ postgres:6543', describeDsn(databaseSettings()['dsn']));
saveConfig(['db' => ['dsn' => 'pgsql:host=db.example;port=5432;dbname=own', 'user' => 'own', 'password' => 'secret']]);
check('db: server entered in setup wins', ['dsn' => 'pgsql:host=db.example;port=5432;dbname=own', 'user' => 'own', 'password' => 'secret', 'source' => 'config'], databaseSettings());
saveConfig(['google' => ['client_id' => 'x']]);
check('db: without a saved server the environment is used', 'environment', databaseSettings()['source'] ?? null);
unlink(CONFIG_FILE);
appConfig(true);
foreach ($dbEnv as $name) {
    putenv($name);
}

// ── Worker refuses to start on low disk space ────────────
saveQueue(['queue' => [], 'current' => ['id' => 'vid_disk', 'type' => 'video', 'url' => 'u', 'format' => 'mp3', 'title' => 'Disk'], 'pid' => null]);
$worker = proc_open(
    [PHP_BINARY, __DIR__ . '/../public/download_worker.php', 'vid_disk'],
    [0 => ['file', NULL_DEVICE, 'r'], 1 => ['file', NULL_DEVICE, 'w'], 2 => ['file', NULL_DEVICE, 'w']],
    $pipes, null, array_merge(getenv(), ['YTA_DATA_DIR' => $dataDir, 'MIN_FREE_SPACE_PERCENT' => '100', 'YTA_YTDLP_BIN' => 'no-such-yt-dlp']),
    ['bypass_shell' => true]
);
proc_close($worker);
check('worker fails job on low disk space', true, str_contains(getProgress()['title'] ?? '', 'Not enough disk space'));
check('worker releases the queue', null, getQueue()['current']);

removeDir($dataDir);

echo $failures ? "\n$failures FAILURE(S)\n" : "All PHP unit tests passed\n";
exit($failures ? 1 : 0);
