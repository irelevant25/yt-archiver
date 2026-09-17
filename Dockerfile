FROM php:8.3-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    python3 \
    py3-pip \
    ffmpeg \
    curl \
    bash \
    procps \
    sudo \
    libzip \
    libpq \
    && pip3 install --break-system-packages yt-dlp

# PHP extensions: zip (ZipArchive) for playlist archives, pdo_pgsql for the user database
RUN apk add --no-cache --virtual .ext-build-deps libzip-dev postgresql-dev \
    && docker-php-ext-install zip pdo_pgsql \
    && apk del .ext-build-deps

# Allow www-data to run exactly the yt-dlp upgrade as root (must match updateYtDlp() in api.php)
RUN echo "www-data ALL=(root) NOPASSWD: /usr/bin/pip3 install --upgrade yt-dlp --break-system-packages" >> /etc/sudoers

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/max_execution_time = 30/max_execution_time = 3600/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/memory_limit = 128M/memory_limit = 512M/' "$PHP_INI_DIR/php.ini"

# Create directories
RUN mkdir -p /var/www/html /data/videos /run/nginx

# Copy application files
COPY public/ /var/www/html/
COPY nginx.conf /etc/nginx/http.d/default.conf
# Fail the build if the config is invalid (e.g. auth_request not available)
RUN nginx -t

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisord.conf

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chown -R www-data:www-data /data \
    && chmod -R 755 /data

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start services via entrypoint
ENTRYPOINT ["/entrypoint.sh"]
