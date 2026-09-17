<?php
/**
 * Offline stand-in for yt-dlp, used by tests/integration.php (and handy for UI work without network):
 *   YTA_YTDLP_BIN="php tests/fixtures/fake-yt-dlp.php" php dev/serve.php
 *
 * Understands only the invocations the worker and the size check make. Behaviour is driven by the video ID:
 *   FAIL…      info and download fail ("Private video")
 *   SLOW…      download takes ~60 s (for cancel tests); writes <id>.pid into FAKE_YTDLP_STATE_DIR
 *   HUGE…      reports 4 MB of streams and a 2 h duration (tests use a 1 MB "large download" threshold)
 *   LIVE…      reports a live stream
 *   anything else: 19 s, ~250 KB, downloads instantly with progress output
 * Playlist IDs: PLslow… → slow entries, PLhuge… → long entries, anything else → a mixed playlist
 * (ok, private, unicode title, failing). Entries report durations like YouTube's flat playlists.
 */

$args = array_slice($argv, 1);
$has = fn(string $flag): bool => in_array($flag, $args, true);
$url = $args[array_search('--', $args, true) + 1] ?? '';
parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
$videoId = $query['v'] ?? '';
$listId = $query['list'] ?? '';

if ($has('--version')) {
    echo "2099.01.01-fake\n";
    exit(0);
}

if ($has('-U')) {
    echo "yt-dlp is up to date (fake@2099.01.01)\n";
    exit(0);
}

if ($has('--flat-playlist')) {
    $entries = match (true) {
        str_starts_with($listId, 'PLslow') => [
            ['id' => 'SLOWSLOWSL1', 'title' => 'Slow one', 'duration' => 5],
            ['id' => 'SLOWSLOWSL2', 'title' => 'Slow two', 'duration' => 5],
        ],
        str_starts_with($listId, 'PLhuge') => [
            ['id' => 'HUGEHUGEHU1', 'title' => 'Long one', 'duration' => 3600],
            ['id' => 'HUGEHUGEHU2', 'title' => 'Unknown length', 'duration' => null],
        ],
        default => [
            ['id' => 'AAAAAAAAAAA', 'title' => 'First "song" <b>', 'duration' => 8],
            ['id' => 'PRIVATEPRIV', 'title' => '[Private video]', 'duration' => null],
            ['id' => 'BBBBBBBBBBB', 'title' => 'Druhá pieseň', 'duration' => 8],
            ['id' => 'FAILFAILFAI', 'title' => 'Broken one', 'duration' => 8],
        ],
    };
    echo json_encode(['id' => $listId, 'title' => 'Test Playlist ' . $listId, 'entries' => $entries]);
    exit(0);
}

if ($has('--dump-json')) {
    if (str_starts_with($videoId, 'FAIL')) {
        fwrite(STDERR, "ERROR: [youtube] $videoId: Private video\n");
        exit(1);
    }
    $huge = str_starts_with($videoId, 'HUGE');
    $info = [
        'id'          => $videoId,
        'title'       => "Video $videoId: \"quoted\" & <tagged>",
        'duration'    => $huge ? 7200 : 19,
        'live_status' => str_starts_with($videoId, 'LIVE') ? 'is_live' : 'not_live',
    ];
    if (!$has('--extract-audio')) {
        $info['requested_formats'] = [
            ['format_id' => '137', 'filesize' => $huge ? 3_000_000 : 200_000],
            ['format_id' => '140', 'filesize_approx' => $huge ? 1_000_000 : 50_000],
        ];
    }
    echo json_encode($info);
    exit(0);
}

// Download
$template = $args[array_search('-o', $args, true) + 1] ?? '';
if ($template === '' || $videoId === '') {
    fwrite(STDERR, "fake-yt-dlp: unsupported invocation\n");
    exit(2);
}
if (str_starts_with($videoId, 'FAIL')) {
    echo "ERROR: [youtube] $videoId: fake failure\n";
    exit(1);
}

$slow = str_starts_with($videoId, 'SLOW');
if ($slow && ($stateDir = getenv('FAKE_YTDLP_STATE_DIR'))) {
    file_put_contents("$stateDir/$videoId.pid", (string)getmypid());
}

$ext = $has('--extract-audio') ? 'mp3' : 'mp4';
$output = str_replace(['%(id)s', '%(ext)s'], [$videoId, $ext], $template);
file_put_contents("$output.part", '');

for ($percent = 0; $percent <= 100; $percent += 10) {
    echo "[download]  $percent.0% of 1.00MiB at 1.00MiB/s ETA 00:01\n";
    usleep($slow ? 6000000 : 20000);
}

unlink("$output.part");
file_put_contents($output, "fake $ext content for $videoId");
echo "[download] Destination: $output\n";
exit(0);
