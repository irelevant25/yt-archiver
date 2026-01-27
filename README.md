# YT Archiver

A simple, self-hosted YouTube video/audio downloader with a beautiful web interface.

![YT Archiver](https://img.shields.io/badge/yt--dlp-powered-red)
![Docker](https://img.shields.io/badge/docker-ready-blue)

## Features

- 📥 Download YouTube videos in MP4 (best quality video + audio)
- 🎵 Extract audio as MP3 (best quality)
- 📋 Queue system - download multiple items one by one
- 📊 Real-time progress tracking
- 📚 Library management with search and filters
- 🔄 Built-in yt-dlp version checker and updater
- 🎨 Beautiful, responsive dark theme UI
- 🐳 Easy Docker deployment

## Quick Start

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

3. Access the web interface at `http://localhost:8080`

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
└─────────────────────────────────────────┘
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api.php?action=version` | GET | Get yt-dlp version info |
| `/api.php?action=update` | POST | Update yt-dlp |
| `/api.php?action=download` | POST | Add URL to download queue |
| `/api.php?action=status` | GET | Get queue status |
| `/api.php?action=videos` | GET | List all downloaded videos |
| `/api.php?action=videos&id=X` | DELETE | Delete a video |
| `/api.php?action=serve&file=X` | GET | Download a file |

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
