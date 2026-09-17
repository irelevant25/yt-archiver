# Queue, jobs and the worker

Exactly one job runs at a time. `queue.json` holds `current` (the running job), `pid` (its worker)
and `queue` (pending jobs, FIFO). Code lives in `public/includes/common.php` (shared) and
`public/download_worker.php` (job handlers).

## Job types

| `type` | Created by | Does | Output |
|---|---|---|---|
| `video` (or missing, for legacy jobs) | API `download` | `yt-dlp --dump-json` for the title, then download | file in `videos/` + library entry |
| `playlist` | API `download` with a playlist | `yt-dlp --flat-playlist --dump-single-json`, skips private/deleted entries | N `playlist_item` jobs + 1 `archive` job **inserted at the front** of the queue |
| `playlist_item` | `playlist` job | downloads one entry | `videos/.staging/<pl_id>/<NN> - <title>.<ext>` (not in the library) |
| `archive` | `playlist` job | `ZipArchive`, `CM_STORE`, progress and cancel callbacks | `videos/<pl_id>_<title>.zip` + library entry (`type: playlist`) |

Playlist-related jobs carry `playlist_id` (= the `pl_…` id of the playlist job), `playlist_title`,
`index` and `total`. Because children are inserted at the front, they stay contiguous in the queue,
which the UI relies on for grouping.

IDs: `vid_` (video), `pl_` (playlist), `itm_` (item), `zip_` (archive), all generated with `uniqid(prefix, true)`.

## Lifecycle

```
API download ──► queue[] ──startNextJob()──► current + spawnWorker() ──► worker runs handler
                                                                       │
             finishJob(id, followUps): if still current → prepend followUps, current = null, startNextJob()
```

- `startNextJob()` (under the lock): if idle, shift a job into `current`, reset progress, spawn
  `php download_worker.php <id>` and store its PID. Under PHP's built-in server (local dev) it does nothing;
  the dispatcher loop in `dev/serve.php` calls it instead (see architecture.md, "Local development mode").
- The worker reads `current`; if it is not its job, it exits. It calls `posix_setsid()` so that
  its PID is also the process-group ID.
- Handlers return follow-up jobs (only `playlist` returns any). Failures set `progress.status = error`
  and clean up their work directory. The worker sleeps 0.5 s so the UI can show the final state, then calls `finishJob`.
- The final move into the library (`rename` + `addToLibrary`) happens under the lock after re-checking
  that the job is still current, so a cancel cannot leave an orphan library file.

## Locking rules

- `withLock(fn)` = `flock` on `/data/queue.lock`; **re-entrant within one process** (depth counter).
  Never open a second lock handle manually.
- Every read-modify-write of `queue.json` or `database.json` must be inside `withLock`.
  Lock-free reads are fine because writes are atomic.
- The lock file is opened with the `ce` mode (close-on-exec), so spawned workers do not inherit it.
- Keep critical sections short. The only long one is `killWorker` (≤ 3 s) during a cancel.

## Size fields on jobs

- `video`/`playlist` jobs created by the API carry `estimated_size`, `duration` and `title`/`playlist_title` from the size check.
  `runVideo` uses the stored title and skips its own `--dump-json` call; jobs from older versions without a title still fetch it.
- `runPlaylist` gives each `playlist_item` an `estimated_size` (duration-based) and the `archive` job the sum, so
  `storageInfo()['reserved']` stays about the same when a playlist expands (2 × playlist size).
- Jobs from older versions have no `estimated_size` and reserve 0.
- Disk checks at job start and before archiving are described in architecture.md ("Size check and disk space").

## Cancel

- `cancel {id}` for a single job: if running, `killWorker(pid)` (SIGTERM to the process group, wait ≤ 3 s, SIGKILL),
  remove its work directory, clear `current`, start the next job. If queued, just remove it.
  Cancelling a `playlist` or `archive` job cancels the whole playlist.
- `cancel {playlist_id}`: removes all queued jobs of the playlist, kills the current one if it belongs to it, and deletes
  `.staging/<pl_id>/` and `.staging/<pl_id>.zip*`.
- Skipping a single `playlist_item` is allowed; the archive then contains the remaining items
  (the library shows "`items` of `total`").
- Workers also detect cancellation themselves (`assertStillCurrent`, rate-limited to 1 check per second) and throw `JobCancelled`.

## Self-healing

`recoverStaleJob()` runs on every `status` poll, `download` and `process` call. If `current` is set but
`isWorkerAlive(pid)` is false (on Linux it checks `/proc/<pid>/cmdline` for `download_worker.php`, which also covers PID reuse after a
container restart; see architecture.md for macOS/Windows), the job is re-queued at the front with `attempts + 1`. After
`MAX_JOB_ATTEMPTS` (2) attempts, it is dropped and its staging data is removed. Afterwards, `startNextJob()` restarts processing.

## Staging layout

```
videos/.staging/
  vid_…/                  single video work dir (removed after the move or on failure)
  pl_…/                   playlist collection dir
    itm_…/                item work dir (yt-dlp output: <youtube-id>.<ext>)
    01 - First title.mp3  finished items, renamed with a zero-padded index
  pl_….zip                archive being written (libzip also creates pl_….zip.XXXXXX temp files)
```

## yt-dlp invocation rules

- Always `proc_open([...ytDlpCommand(), ...])` (no shell; `ytDlpCommand()` honours `YTA_YTDLP_BIN`), use `NULL_DEVICE`
  instead of `/dev/null`, and put `--` before the URL.
- Only canonical URLs built from validated IDs reach yt-dlp (`parseYoutubeUrl` in common.php).
- Downloads use `--no-playlist` (a watch URL with `&list=` would otherwise download the whole playlist).
- Progress is parsed from `[download]  12.3%` lines (`--newline --progress`). mp4 downloads report
  two streams, and progress only ever increases.
