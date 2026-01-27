#!/bin/bash
set -e

# Fix permissions for /data directory (handles mounted volumes)
chown -R www-data:www-data /data
chmod -R 755 /data

# Ensure subdirectories exist
mkdir -p /data/videos
chown -R www-data:www-data /data/videos

# Initialize database files if they don't exist
if [ ! -f /data/database.json ]; then
    echo '{"videos":[]}' > /data/database.json
    chown www-data:www-data /data/database.json
fi

if [ ! -f /data/queue.json ]; then
    echo '{"queue":[],"current":null}' > /data/queue.json
    chown www-data:www-data /data/queue.json
fi

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
