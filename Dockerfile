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
    && pip3 install --break-system-packages yt-dlp

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/max_execution_time = 30/max_execution_time = 3600/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/memory_limit = 128M/memory_limit = 512M/' "$PHP_INI_DIR/php.ini"

# Create directories
RUN mkdir -p /var/www/html /data/videos /run/nginx

# Copy application files
COPY public/ /var/www/html/
COPY nginx.conf /etc/nginx/http.d/default.conf

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisord.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html /data

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start services
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
