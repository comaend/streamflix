# syntax=docker/dockerfile:1
#
# StreamFlix — Dockerfile für UmbrelOS (Raspberry Pi ARM64 / aarch64)
#
# Enthaelt alle benötigten Abhaengigkeiten (PHP 8.2, Nginx, FFmpeg, SQLite-Extensions).
# Es wird zur Laufzeit KEIN Paket aus dem Internet nachinstalliert.

FROM --platform=linux/arm64 php:8.2-fpm

ENV DEBIAN_FRONTEND=noninteractive
ENV APP_DATA_DIR=/var/www/html/data
ENV MEDIA_ROOT=/media

# System-Abhängigkeiten installieren (nur beim Build)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        ffmpeg \
        curl \
        ca-certificates \
        sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Nginx-Konfiguration
COPY docker/nginx.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/sites-enabled/default

WORKDIR /var/www/html

# Anwendung kopieren
COPY app/ /var/www/html/app/
COPY health.php /var/www/html/health.php
COPY database/ /var/www/html/database/
COPY scripts/ /var/www/html/scripts/

# Rechte setzen (www-data, UID 1000 passt zu `user: "1000:1000"` im Compose)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/data \
    && chmod -R 777 /var/www/html/data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "file_exists('/var/www/html/health.php') ? exit(0) : exit(1);"

# Nginx (daemonisiert) + PHP-FPM (Foreground) starten
CMD ["sh", "-c", "nginx && php-fpm -F"]
