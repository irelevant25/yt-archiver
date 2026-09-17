<?php
/**
 * Throwaway PostgreSQL cluster for tests: initdb into a temp directory, start on a free port with trust auth,
 * stop and delete afterwards. Never touches an existing PostgreSQL installation or its data.
 *
 * The binaries are searched in YTA_TEST_PG_BIN, PATH, and the usual install locations.
 */

function findPostgresBinDir(): ?string {
    $exe = IS_WINDOWS ? '.exe' : '';
    $candidates = array_merge(
        array_filter([getenv('YTA_TEST_PG_BIN') ?: null]),
        explode(PATH_SEPARATOR, (string)getenv('PATH')),
        glob('C:/Program Files/PostgreSQL/*/bin') ?: [],
        glob('/usr/lib/postgresql/*/bin') ?: [],
        glob('/opt/homebrew/opt/postgresql*/bin') ?: [],
        ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']
    );
    rsort($candidates); // newest version directories first
    foreach ($candidates as $dir) {
        if ($dir !== '' && is_file("$dir/initdb$exe") && is_file("$dir/pg_ctl$exe")) {
            return $dir;
        }
    }
    return null;
}

function freeTcpPort(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

/** Run a command with its output going to a file (the server keeps running after pg_ctl exits, so no pipes). */
function runLogged(array $cmd, string $logFile): int {
    $proc = proc_open($cmd, [0 => ['file', NULL_DEVICE, 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, null, null, ['bypass_shell' => true]);
    return is_resource($proc) ? proc_close($proc) : -1;
}

/**
 * @return array{host: string, port: int, user: string, password: string, database: string, stop: callable}|string
 *         connection details, or the reason why no cluster could be started
 */
function startTemporaryPostgres(string $baseDir): array|string {
    if (!extension_loaded('pdo_pgsql')) {
        return 'the pdo_pgsql PHP extension is not loaded';
    }
    if (!IS_WINDOWS && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return 'initdb refuses to run as root';
    }
    $bin = findPostgresBinDir();
    if ($bin === null) {
        return 'PostgreSQL binaries (initdb, pg_ctl) not found; set YTA_TEST_PG_BIN';
    }

    $exe = IS_WINDOWS ? '.exe' : '';
    $dataDir = "$baseDir/pgdata";
    $log = "$baseDir/postgres.log";
    $port = freeTcpPort();

    if (runLogged(["$bin/initdb$exe", '-D', $dataDir, '-U', 'postgres', '-A', 'trust', '-E', 'UTF8', '--no-sync'], $log) !== 0) {
        return "initdb failed, see $log";
    }
    $options = "-p $port -h 127.0.0.1 -c fsync=off -c synchronous_commit=off -c full_page_writes=off";
    if (runLogged(["$bin/pg_ctl$exe", '-D', $dataDir, '-l', "$baseDir/server.log", '-o', $options, '-w', '-t', '60', 'start'], $log) !== 0) {
        return "pg_ctl start failed, see $log and $baseDir/server.log";
    }

    $stop = function () use ($bin, $exe, $dataDir, $log): void {
        runLogged(["$bin/pg_ctl$exe", '-D', $dataDir, '-m', 'immediate', '-w', 'stop'], $log);
    };
    try {
        $pdo = new PDO("pgsql:host=127.0.0.1;port=$port;dbname=postgres", 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE yta_test');
    } catch (Throwable $e) {
        $stop();
        return 'could not create the test database: ' . $e->getMessage();
    }

    return ['host' => '127.0.0.1', 'port' => $port, 'user' => 'postgres', 'password' => '', 'database' => 'yta_test', 'stop' => $stop];
}
