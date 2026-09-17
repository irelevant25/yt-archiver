#!/bin/bash
set -e

# Fix permissions for /data directory (handles mounted volumes)
chown -R www-data:www-data /data
chmod -R 755 /data

# Ensure subdirectories exist
mkdir -p /data/videos /data/sessions
chown -R www-data:www-data /data/videos /data/sessions

# Secrets: settings written by setup.php and login sessions are readable only by www-data
chmod 700 /data/sessions
if [ -f /data/config.php ]; then
    chmod 600 /data/config.php
fi

# Initialize database files if they don't exist
if [ ! -f /data/database.json ]; then
    echo '{"videos":[]}' > /data/database.json
    chown www-data:www-data /data/database.json
fi

if [ ! -f /data/queue.json ]; then
    echo '{"queue":[],"current":null}' > /data/queue.json
    chown www-data:www-data /data/queue.json
fi

# Apply new database migrations after an upgrade (no-op before the installation)
if [ -f /data/config.php ]; then
    php /var/www/html/setup.php --migrate || echo "WARNING: database migrations failed, sign-in may not work"
fi

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
