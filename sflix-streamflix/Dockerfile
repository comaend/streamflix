FROM alpine:3.20

# Alle Abhängigkeiten werden beim Image-Build installiert (kein Download beim Start).
RUN apk add --no-cache \
      nginx supervisor su-exec ffmpeg tzdata \
      php83 php83-fpm php83-opcache php83-pdo php83-pdo_sqlite php83-sqlite3 \
      php83-session php83-mbstring php83-ctype php83-fileinfo php83-zip \
      php83-simplexml php83-xml php83-openssl php83-phar \
    && ln -sf /usr/bin/php83 /usr/bin/php \
    && addgroup -g 1000 app && adduser -D -u 1000 -G app -h /app app \
    && mkdir -p /data /media /tmp \
    && chmod 1777 /tmp

COPY docker/php.ini /etc/php83/conf.d/99-streamflix.ini

# hls.js (Apache-2.0, Version 1.5.13) liegt fest gebündelt im Repository unter
# app/public/assets/hls.min.js – kein Download beim Build oder Start nötig.
COPY app/ /app/
COPY docker/ /app/docker/
RUN chmod +x /app/docker/entrypoint.sh && chown -R root:root /app && chmod -R a+rX /app

ENV SF_DATA=/data SF_MEDIA=/media
VOLUME ["/data"]
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/api/health || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["supervisord", "-c", "/app/docker/supervisord.conf"]
