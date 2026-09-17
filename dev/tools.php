<?php
/**
 * yt-dlp and ffmpeg for local development, managed in .dev-tools/bin by dev/serve.php.
 *
 * - yt-dlp: downloaded when missing; checked against the latest release every YTDLP_CHECK_INTERVAL
 *   (or on --update-tools) and replaced when outdated. YouTube changes break old versions quickly.
 * - ffmpeg/ffprobe: taken from PATH when available, otherwise downloaded once. The "latest" build changes
 *   daily and is large, so it is only refreshed on --update-tools (when its checksum changed).
 * - Every download is verified against the SHA-256 checksum file published with the release.
 *
 * Prebuilt binaries are used on Windows (x64) and Linux (x86_64). Other platforms must provide the tools on PATH.
 */

const FFMPEG_RELEASE = 'https://github.com/BtbN/FFmpeg-Builds/releases/download/latest';
const YTDLP_CHECK_INTERVAL = 12 * 3600;

/** Release asset names for this platform, or null when no prebuilt binaries are used. */
function toolAssets(): ?array {
    if (IS_WINDOWS) {
        return ['yt-dlp' => 'yt-dlp.exe', 'ffmpeg' => 'ffmpeg-master-latest-win64-gpl.zip', 'exe' => '.exe'];
    }
    if (PHP_OS_FAMILY === 'Linux' && in_array(strtolower(php_uname('m')), ['x86_64', 'amd64'], true)) {
        // The plain "yt-dlp" asset is a Python zipapp and needs python3 >= 3.9
        return ['yt-dlp' => 'yt-dlp', 'ffmpeg' => 'ffmpeg-master-latest-linux64-gpl.tar.xz', 'exe' => ''];
    }
    return null;
}

function httpContext(array $options = [], array $params = []) {
    return stream_context_create(['http' => $options + [
        'user_agent' => 'YT-Archiver-dev',
        'timeout'    => 60,
    ]], $params);
}

/** SHA-256 for $asset from a "hash  filename" checksum file. */
function publishedChecksum(string $sumsUrl, string $asset): ?string {
    $sums = @file_get_contents($sumsUrl, false, httpContext());
    if ($sums === false) {
        return null;
    }
    foreach (preg_split('/\R/', $sums) as $line) {
        if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $m) && $m[2] === $asset) {
            return strtolower($m[1]);
        }
    }
    return null;
}

/** Stream a download to $target, printing coarse progress. */
function downloadFile(string $url, string $target, string $label): bool {
    $size = 0;
    $shown = 0;
    $context = httpContext([], ['notification' => function (int $code, int $severity, ?string $message, int $messageCode, int $transferred, int $max) use (&$size, &$shown, $label) {
        if ($code === STREAM_NOTIFY_FILE_SIZE_IS) {
            $size = $max;
        } elseif ($code === STREAM_NOTIFY_PROGRESS && $size > 0 && $transferred * 100 / $size >= $shown + 25) {
            $shown = (int)floor($transferred * 100 / $size / 25) * 25;
            printf("[dev]   %s: %d%% of %.1f MB\n", $label, $shown, $size / 1048576);
        }
    }]);

    $in = @fopen($url, 'rb', false, $context);
    $out = @fopen($target, 'wb');
    if (!$in || !$out) {
        return false;
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $copied !== false && $copied > 0;
}

/** Download $url to $target (atomically) after verifying its checksum. */
function installVerified(string $url, string $expectedSha256, string $target, string $label): bool {
    $tmp = $target . '.download';
    if (!downloadFile($url, $tmp, $label)) {
        @unlink($tmp);
        warn("Downloading $label failed ($url)");
        return false;
    }
    if (hash_file('sha256', $tmp) !== $expectedSha256) {
        @unlink($tmp);
        warn("Checksum mismatch for $label, the download was discarded");
        return false;
    }
    if (!IS_WINDOWS) {
        chmod($tmp, 0755);
    }
    for ($attempt = 0; !@rename($tmp, $target); $attempt++) {
        if ($attempt >= 20) {
            @unlink($tmp);
            warn("Could not replace $target (is it still running?)");
            return false;
        }
        usleep(250000);
    }
    return true;
}

function extractArchive(string $archive, string $destination): bool {
    if (IS_WINDOWS) {
        // bsdtar ships with Windows 10+ and extracts zip files; PowerShell is the fallback
        $tar = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
        if (is_file($tar)) {
            runCommand([$tar, '-xf', $archive, '-C', $destination], $exitCode);
            if ($exitCode === 0) {
                return true;
            }
        }
        $quote = fn(string $path): string => "'" . str_replace("'", "''", $path) . "'";
        runCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command',
            'Expand-Archive -LiteralPath ' . $quote($archive) . ' -DestinationPath ' . $quote($destination) . ' -Force'], $exitCode);
        return $exitCode === 0;
    }
    runCommand(['tar', '-xJf', $archive, '-C', $destination], $exitCode);
    return $exitCode === 0;
}

function firstLine(?string $output): ?string {
    $line = trim(strtok((string)$output, "\r\n") ?: '');
    return $line === '' ? null : $line;
}

function commandWorks(array $cmd): bool {
    runCommand($cmd, $exitCode);
    return $exitCode === 0;
}

function provisionYtDlp(string $binDir, array &$state, array $assets, bool $forceCheck): void {
    $local = $binDir . '/yt-dlp' . $assets['exe'];
    $installed = is_file($local) ? firstLine(runCommand([$local, '--version'])) : null;

    if ($forceCheck || $installed === null || time() - ($state['yt-dlp']['checked_at'] ?? 0) > YTDLP_CHECK_INTERVAL) {
        $latest = latestYtDlpVersion();
        if ($latest === null) {
            warn('Could not determine the latest yt-dlp version (offline?)');
        } else {
            $state['yt-dlp']['checked_at'] = time();
            if ($installed === null || version_compare($installed, $latest, '<')) {
                info($installed === null ? "Downloading yt-dlp $latest" : "Updating yt-dlp $installed -> $latest");
                $checksum = publishedChecksum(YTDLP_RELEASES_URL . "/download/$latest/SHA2-256SUMS", $assets['yt-dlp']);
                if ($checksum === null) {
                    warn('yt-dlp checksum file unavailable, not installing an unverified binary');
                } elseif (installVerified(YTDLP_RELEASES_URL . "/download/$latest/" . $assets['yt-dlp'], $checksum, $local, 'yt-dlp')) {
                    $installed = firstLine(runCommand([$local, '--version']));
                    if ($installed === null && !IS_WINDOWS) {
                        warn('The downloaded yt-dlp does not run. The Linux build needs python3 >= 3.9 on PATH.');
                    }
                }
            }
        }
    }

    if ($installed !== null) {
        putenv('YTA_YTDLP_BIN="' . $local . '"');
        info("yt-dlp $installed (.dev-tools)");
    } elseif (commandWorks(['yt-dlp', '--version'])) {
        info('yt-dlp ' . firstLine(runCommand(['yt-dlp', '--version'])) . ' (PATH, may be outdated)');
    } else {
        warn('yt-dlp is not available: downloads will fail.');
    }
}

function provisionFfmpeg(string $toolsDir, string $binDir, array &$state, array $assets, bool $forceUpdate): void {
    $ffmpeg = $binDir . '/ffmpeg' . $assets['exe'];
    $ffprobe = $binDir . '/ffprobe' . $assets['exe'];
    $managed = is_file($ffmpeg) && is_file($ffprobe);

    // A system ffmpeg is good enough; only manage our own copy when there is none
    if (!$managed && commandWorks(['ffmpeg', '-version']) && commandWorks(['ffprobe', '-version'])) {
        info('ffmpeg from PATH: ' . firstLine(runCommand(['ffmpeg', '-version'])));
        return;
    }

    if (!$managed || $forceUpdate) {
        $checksum = publishedChecksum(FFMPEG_RELEASE . '/checksums.sha256', $assets['ffmpeg']);
        if ($checksum === null) {
            warn($managed ? 'Could not check for a newer ffmpeg (offline?)' : 'ffmpeg checksum file unavailable (offline?), not downloading ffmpeg');
        } elseif ($managed && $checksum === ($state['ffmpeg']['sha256'] ?? null)) {
            info('ffmpeg is up to date');
        } else {
            info(($managed ? 'Updating' : 'Downloading') . ' ffmpeg (' . $assets['ffmpeg'] . ', this can take a while)');
            $archive = $toolsDir . '/' . $assets['ffmpeg'];
            $extractDir = $toolsDir . '/ffmpeg-extract';
            removeDir($extractDir);
            mkdir($extractDir, 0755, true);

            if (installVerified(FFMPEG_RELEASE . '/' . $assets['ffmpeg'], $checksum, $archive, 'ffmpeg')) {
                if (!extractArchive($archive, $extractDir) || !($sourceBin = glob($extractDir . '/*/bin', GLOB_ONLYDIR)[0] ?? null)) {
                    warn('Could not extract ' . $assets['ffmpeg']);
                } else {
                    foreach (['ffmpeg', 'ffprobe'] as $tool) {
                        @unlink("$binDir/$tool" . $assets['exe']);
                        rename("$sourceBin/$tool" . $assets['exe'], "$binDir/$tool" . $assets['exe']);
                        if (!IS_WINDOWS) {
                            chmod("$binDir/$tool", 0755);
                        }
                    }
                    $state['ffmpeg'] = ['sha256' => $checksum, 'installed_at' => date('c')];
                }
            }
            @unlink($archive);
            removeDir($extractDir);
        }
    }

    if (is_file($ffmpeg) && commandWorks([$ffmpeg, '-version'])) {
        info('ffmpeg (.dev-tools): ' . firstLine(runCommand([$ffmpeg, '-version'])));
    } else {
        warn('ffmpeg is not available: MP3 extraction and MP4 merging will fail.');
    }
}

/**
 * Make yt-dlp and ffmpeg available to the dev server and its workers.
 * Puts .dev-tools/bin first on PATH and sets YTA_YTDLP_BIN (unless the developer set it).
 */
function provisionTools(string $toolsDir, bool $forceUpdate): void {
    $binDir = $toolsDir . '/bin';
    if (!is_dir($binDir)) {
        mkdir($binDir, 0755, true);
    }
    // Children (web server, workers, yt-dlp → ffmpeg) inherit this PATH
    putenv('PATH=' . str_replace('/', DIRECTORY_SEPARATOR, $binDir) . PATH_SEPARATOR . getenv('PATH'));

    $assets = toolAssets();
    $customYtDlp = getenv('YTA_YTDLP_BIN') !== false;
    if ($assets === null || !extension_loaded('openssl')) {
        warn($assets === null
            ? 'No prebuilt yt-dlp/ffmpeg for ' . PHP_OS_FAMILY . ' ' . php_uname('m') . ': install them yourself (e.g. brew install yt-dlp ffmpeg).'
            : 'The openssl extension is needed to download yt-dlp/ffmpeg. Install them yourself or enable openssl.');
        if (!$customYtDlp && !commandWorks(['yt-dlp', '--version'])) {
            warn('yt-dlp not found on PATH.');
        }
        if (!commandWorks(['ffmpeg', '-version'])) {
            warn('ffmpeg not found on PATH.');
        }
        return;
    }

    $stateFile = $toolsDir . '/state.json';
    $state = readJson($stateFile, []);

    if ($customYtDlp) {
        info('yt-dlp from YTA_YTDLP_BIN: ' . (firstLine(runCommand([...ytDlpCommand(), '--version'])) ?? 'NOT WORKING'));
    } else {
        provisionYtDlp($binDir, $state, $assets, $forceUpdate);
    }
    provisionFfmpeg($toolsDir, $binDir, $state, $assets, $forceUpdate);

    writeJson($stateFile, $state);
}
