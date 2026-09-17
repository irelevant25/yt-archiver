# YT Archiver

A simple, self-hosted YouTube video/audio downloader with a beautiful web interface.

![YT Archiver](https://img.shields.io/badge/yt--dlp-powered-red)
![Docker](https://img.shields.io/badge/docker-ready-blue)

## Features

- 📥 Download YouTube videos in MP4 (best quality video + audio)
- 🎵 Extract audio as MP3 (best quality)
- 🗂️ Playlist downloads - every item is queued separately, the library gets a single ZIP
- 💾 Size check before downloading - confirmation above 1 GB or when disk space runs low, downloads refused below 10 % free space
- 📈 Storage overview - free space, library size and space reserved by the queue
- 📋 Queue system - download multiple items one by one
- 📊 Real-time progress tracking
- 📚 Library management with search and filters
- 🔄 Built-in yt-dlp version checker and updater
- 🎨 Beautiful, responsive dark theme UI
- 🐳 Easy Docker deployment
- 🔐 Sign in with Google only; new accounts wait for an administrator's approval

## Quick Start

You need a **PostgreSQL** database (for accounts) and a **Google OAuth client** (for sign-in).
Everything else lives in the `/data` volume.

### Using Docker Compose (Recommended)

1. Create a `docker-compose.yml` file:

```yaml
version: "3.8"

services:
  yt-archiver:
    image: ghcr.io/irelevant25/yt-archiver:latest
    container_name: yt-archiver
    restart: unless-stopped
    ports:
      - "8080:80"
    volumes:
      - /opt/yt-archiver/videos:/data/videos
      - /opt/yt-archiver/data:/data
    environment:
      - TZ=UTC
```

2. Start the container:

```bash
docker-compose up -d
```

3. Open the web interface at `http://localhost:8080` and **finish the setup right away** (see below). Until then, anyone who
   can reach the site could run it.

### Setup (first start)

Every page redirects to `/setup.php` until the installation is finished:

1. **Step 1: database and Google.**
   - **Database:** enter the PostgreSQL host, port, database, user and password. **Test connection** checks that the server is reachable,
     that the credentials and database name are accepted, and that the user may create the tables, without saving anything.
   - **Google:** enter the public URL of the site and the Google OAuth **client ID** (no client secret is needed). Create the
     client in the [Google Cloud console](https://console.cloud.google.com/apis/credentials): configure the OAuth consent screen, then
     *Create credentials → OAuth client ID → Web application*, with the **authorized JavaScript origin** `https://<your host>`.
   - **Check and save** tests the database again, creates the tables and saves everything to `/data/config.php` (readable only by the container).
2. **Step 2: sign in with Google.** This tests the Google configuration. The account you sign in with becomes the
   **administrator**, and the installation is finished. `/setup.php` returns 404 afterwards.

After that:
- **Only Google sign-in exists.** Everything except `/login.php` (pages, JS/CSS, API, downloads) requires a signed-in, approved account.
- **New accounts start as pending.** They see a "waiting for approval" page and cannot download.
- **Administrators** manage accounts on **Users** (approve, disable, enable, make or remove admin; accounts are never deleted).
  They can also **add an account in advance** by email with a role and approved/pending status: the first Google sign-in with that
  (verified) address gets exactly that access, without waiting for approval. Administrators can also open **Logs** and update yt-dlp.
- **Every approved user** can download, cancel, and delete files from the library.

Command-line helpers inside the container:

```bash
docker exec yt-archiver php /var/www/html/setup.php --status                 # state, or the setup link if unfinished
docker exec yt-archiver php /var/www/html/setup.php --make-admin=you@gmail.com   # recovery: make that email an admin (creates the account if needed)
```

Database migrations run automatically when the container starts.

### Using Portainer

1. Go to **Stacks** → **Add stack**
2. Name your stack: `yt-archiver`
3. Paste the docker-compose.yml content
4. Deploy the stack

## Configuration

### Volume Paths

Customize where your videos are stored by modifying the volume mapping:

```yaml
volumes:
  # Store videos in your media library
  - /mnt/media/youtube:/data/videos
  
  # Or in your home directory
  - ~/Videos/youtube:/data/videos
  
  # Database and settings
  - /opt/yt-archiver/data:/data
```

### Port Configuration

Change the exposed port if 8080 is already in use:

```yaml
ports:
  - "3000:80"  # Access at http://localhost:3000
```

### Timezone

Set your timezone for correct timestamps:

```yaml
environment:
  - TZ=America/New_York
```

## Usage

### Downloading Videos

1. Paste a YouTube URL into the input field
2. Select format:
   - **MP4**: Best quality video with audio
   - **MP3**: Audio only extraction
3. Click **Download**
4. Watch the progress in the queue section

### Size Check and Disk Space

Before anything is queued, the app asks yt-dlp for the title, duration and size, without downloading:

- **Videos:** MP4 uses the stream sizes YouTube reports. MP3 is estimated from the duration (≈ 1.8 MB per minute).
- **Playlists:** estimated from the entry durations (MP4 ≈ 1.4 GB per hour, MP3 ≈ 110 MB per hour). While the ZIP is built, a playlist briefly needs twice its size.

A confirmation dialog appears when:
- the estimate is **1 GB or more**,
- less than **twice the minimum free space** would remain after this download and the queue, or
- the source is a **live stream**.

Downloads are **refused** (on the server too, even with confirmation) when less than **10 %** of the disk is free, or would be after this download and everything already queued.
The worker checks free space again when a job starts.

The download section shows free space, the library size, space reserved by queued downloads, and the 10 % limit.

```yaml
environment:
  - MIN_FREE_SPACE_PERCENT=10     # refuse downloads below this share of free disk space
  - LARGE_DOWNLOAD_WARNING_GB=1   # ask for confirmation from this estimated size
```

### Downloading Playlists

1. Paste a playlist URL (`https://www.youtube.com/playlist?list=...`), or a video URL that contains `&list=...`
2. For a video URL with a playlist, tick **Download the whole playlist** (otherwise only the video is downloaded)
3. Click **Download**

The playlist first resolves its items, then each item appears in the queue under the playlist and downloads one by one.
Single items can be skipped, or the whole playlist cancelled. When all items are done, they are packed
(uncompressed, numbered in playlist order) into one ZIP, which is the only entry added to the library.
Private and deleted videos are skipped; the library shows how many items the ZIP contains.

### Managing Library

- **Search**: Filter videos by name
- **Type Filter**: Show only videos or audio files
- **Sort**: Click column headers to sort
- **Download**: Download files to your computer
- **Delete**: Remove files from the library

### Updating yt-dlp

The header shows the current and latest yt-dlp versions. Click **Update yt-dlp** when an update is available.

## Architecture

```
┌─────────────────────────────────────────┐
│            Docker Container             │
├─────────────────────────────────────────┤
│  Nginx (port 80)                        │
│    ├── Static files (HTML/CSS/JS)       │
│    └── PHP FastCGI proxy                │
├─────────────────────────────────────────┤
│  PHP-FPM                                │
│    ├── api.php (main API)               │
│    └── download_worker.php (background) │
├─────────────────────────────────────────┤
│  yt-dlp + ffmpeg                        │
└─────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────┐
│            /data (volume)               │
├─────────────────────────────────────────┤
│  videos/    - Downloaded media files    │
│  database.json - Video metadata         │
│  queue.json    - Download queue         │
│  progress.json - Current progress       │
│  videos/.staging/ - In-progress work    │
└─────────────────────────────────────────┘
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api.php?action=version` | GET | Get yt-dlp version info |
| `/api.php?action=update` | POST | Update yt-dlp |
| `/api.php?action=download` | POST | Add URL to download queue |
| `/api.php?action=status` | GET | Get queue status and storage (`total`, `free`, `library`, `reserved`, `min_free`) |
| `/api.php?action=videos` | GET | List all downloaded videos |
| `/api.php?action=videos&id=X` | DELETE | Delete a video |
| `/api.php?action=inspect` body `{"url", "format", "playlist"}` | POST | Size estimate and disk check: `inspection`, `storage`, `blocked`, `confirmation_required`, `reasons` |
| `/api.php?action=download` body `{"url", "format": "mp4"\|"mp3", "playlist": bool, "confirmed": bool}` | POST | Queue a video or playlist. `409` if confirmation is required and `confirmed` is not `true`; `507` if disk space is too low; `422` if the source is unavailable |
| `/api.php?action=cancel` body `{"id"}` or `{"playlist_id"}` | POST | Cancel a job or a whole playlist |
| `/api.php?action=serve&file=X` | GET | Download a file |
| `/api.php?action=me` | GET | Signed-in account, CSRF token for sign-out, pending account count (admins) |
| `/api.php?action=users` | GET | All accounts (admin) |
| `/api.php?action=create_user` body `{"email", "role": "user"\|"admin", "approved": bool}` | POST | Add an account in advance; linked on its first Google sign-in (admin) |
| `/api.php?action=user` body `{"id", "operation": "approve"\|"disable"\|"enable"\|"make_admin"\|"make_user"}` | POST | Manage another account (admin) |

Every endpoint needs the session cookie of a signed-in, **approved** account (otherwise `401` with `"login_required": true`).
`update`, `logs`, `users` and `user` are for administrators only (`403` otherwise).
All `POST` requests must send `Content-Type: application/json` (cross-site request protection).

## Building from Source

```bash
# Clone the repository
git clone https://github.com/irelevant25/yt-archiver.git
cd yt-archiver

# Build the Docker image
docker build -t yt-archiver .

# Run locally
docker run -d -p 8080:80 -v $(pwd)/data:/data yt-archiver
```

## Local Development (without Docker)

Works on Windows, Linux and macOS. Requirements:

- PHP 8.0+ CLI with `mbstring`, `openssl` and `pdo_pgsql` (`zip` for playlists; missing extensions whose files exist are enabled automatically)
- A PostgreSQL database and a Google OAuth client ID, entered in `/setup.php` on the first visit like in Docker.
  For local testing, open **`http://localhost:8080`** (not 127.0.0.1: Google rejects IP origins), use it as the public URL, and add both
  `http://localhost` and `http://localhost:8080` as authorized JavaScript origins of the client.
- yt-dlp and ffmpeg are **downloaded automatically** on Windows x64 and Linux x86_64 (see below).
  On other platforms, put them on `PATH` yourself (e.g. `brew install yt-dlp ffmpeg`).

```bash
php dev/serve.php                 # http://127.0.0.1:8080, data in .dev-data/
php dev/serve.php --port=9000 --data=/tmp/yta --verbose
php dev/serve.php --update-tools  # check for new yt-dlp and ffmpeg right now
php dev/serve.php --no-tools      # offline: no checks or downloads
```

Use your PHP 8 binary in place of `php` if the default one is older (e.g. `php8 dev/serve.php`).

`dev/serve.php` runs PHP's built-in web server with `dev/router.php` (mirrors `nginx.conf`) plus a small dispatcher
that starts queued jobs. Stop it with Ctrl+C; an interrupted download goes back to the front of the queue.
Worker activity (yt-dlp exit codes and error output, archive contents) is logged to `<data>/worker.log`.

### yt-dlp and ffmpeg in `.dev-tools/`

On start, `dev/serve.php` manages the tools in `.dev-tools/bin` (gitignored) and puts that directory first on `PATH`:

| Tool | Source | When it is downloaded |
|---|---|---|
| yt-dlp | [yt-dlp releases](https://github.com/yt-dlp/yt-dlp/releases/latest) (`yt-dlp.exe` / `yt-dlp`) | when missing, or when the latest release is newer (checked at most every 12 h, or with `--update-tools`). The UI's **Update yt-dlp** button runs `yt-dlp -U`. |
| ffmpeg + ffprobe | [BtbN FFmpeg-Builds](https://github.com/BtbN/FFmpeg-Builds/releases/tag/latest) (`win64-gpl.zip` / `linux64-gpl.tar.xz`) | only when neither a managed copy nor a system ffmpeg on `PATH` exists; refreshed only with `--update-tools` (the daily build is ~190 MB) |

Every download is verified against the release's SHA-256 checksum file before it is used.
The Linux `yt-dlp` build is a Python zipapp and needs `python3` ≥ 3.9.

| Environment variable | Purpose |
|---|---|
| `YTA_YTDLP_BIN` | yt-dlp command instead of the managed one, e.g. a full path, or `php tests/fixtures/fake-yt-dlp.php` to work offline |
| `YTA_WORKER_LOG` | `1` enables `worker.log` outside local development |
| `YTA_DATA_DIR` | data directory (set by `dev/serve.php` from `--data`) |
| `TRUSTED_PROXIES`, `MIN_FREE_SPACE_PERCENT`, `LARGE_DOWNLOAD_WARNING_GB` | same as in Docker |

### Tests

```bash
php tests/unit.php            # PHP helpers, queue state machine
node tests/frontend.test.js   # frontend rendering and escaping
php tests/integration.php     # end to end over HTTP: setup, Google sign-in, approvals, downloads (~45 s, no network needed)
```

The integration test starts a **throwaway PostgreSQL cluster** (`initdb` in a temp directory on a free port; your own
PostgreSQL server and data are never touched) and a fake Google provider (`tests/fixtures/fake-google.php`).
It finds the PostgreSQL binaries on `PATH` or in the usual install locations, or via `YTA_TEST_PG_BIN`. Without them it
prints `SKIPPED` (set `YTA_TEST_REQUIRE_PG=1` to fail instead).

## Troubleshooting

### Downloads fail immediately
- Check if the YouTube URL is valid
- Ensure yt-dlp is up to date (use the update button)
- Check container logs: `docker logs yt-archiver`

### Videos don't appear in library
- Refresh the page
- Check if the download completed (watch the queue)
- Verify volume permissions

### Permission denied errors
```bash
# Fix permissions on host
sudo chown -R 82:82 /opt/yt-archiver/data
sudo chown -R 82:82 /opt/yt-archiver/videos
```

## License

MIT License - feel free to use and modify.

## Contributing

Pull requests are welcome! For major changes, please open an issue first.
