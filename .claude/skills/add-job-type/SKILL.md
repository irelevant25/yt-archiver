---
name: add-job-type
description: Guide for adding or changing a background job type in YT Archiver (download_worker.php handlers such as video, playlist, playlist_item or archive), including queue integration, cancel/cleanup, staging, library entries and UI rendering. Use when adding a new download or processing kind (e.g. channel downloads, new formats, post-processing) or changing an existing handler.
---

# Add or change a job type

Read `docs/knowledge-base/queue-and-jobs.md` first.

1. **Job shape**: `['id' => generateId('<prefix>_'), 'type' => '<type>', 'format' => ..., 'created_at' => date('c'), ...]`.
   Pick a unique ID prefix. Jobs that belong to a playlist-like group need `playlist_id`, `playlist_title`, `index` and `total` so the UI groups them.
2. **Creation**: in `api.php` (`startDownload`, under `withLock`, followed by `recoverStaleJob(); startNextJob();`), or returned as
   follow-up jobs from another handler (they get prepended to the queue by `finishJob`).
3. **Handler** in `public/download_worker.php`: `function runX(array $job): array` returning follow-up jobs, registered in the `match` at the bottom.
   - Work in `jobWorkDir($job)` (extend that function in `common.php` if the job needs a different location). Always under `STAGING_DIR`.
   - External tools: `proc_open([...], ...)` with an argument array and `--` before URLs. Call `assertStillCurrent($job['id'])` inside loops
     and `assertStillCurrent($id, true)` after long blocking calls.
   - Progress: `setProgress($job, $percent, $status, $title)`. The UI shows the percent for the `downloading` and `archiving` statuses.
   - Failure: `return failJob($job, 'message', $dirToClean);`.
   - Publishing results: inside `withLock`, re-check `isCurrentJob($id)`, then `rename` into `VIDEOS_DIR` plus `addToLibrary([...])`.
4. **Cancel and recovery** (`api.php` `cancelDownload`/`cancelPlaylist`, `common.php` `recoverStaleJob`): make sure the new type's staging data is removed
   when it is cancelled or finally dropped.
5. **Library entry**: new `type` values need `renderTypeCell()` in `app.js`, an `<option>` in `#typeFilter` in `index.html`, and a MIME type in the `serve` action.
6. **Queue UI**: `renderSingleJob` or `renderPlaylistGroup` in `app.js`; add a badge style in `css/styles.css` if needed. Escape every value.
7. **Tests and docs**: teach `tests/fixtures/fake-yt-dlp.php` any new yt-dlp invocation and add an end-to-end check to `tests/integration.php`;
   extend `tests/unit.php`/`tests/frontend.test.js`; update the job table in `queue-and-jobs.md` and the README.
   Keep it cross-platform: `NULL_DEVICE`, `ytDlpCommand()`, and the process helpers from `common.php` (it must work in `php8 dev/serve.php` on Windows).
8. Run the `verify` skill, then the `queue-reviewer` agent.
