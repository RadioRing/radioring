# ─── Stage 1: Vite build ───────────────────────────────────────────────────
FROM node:26-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build

# ─── Stage 2: Composer dependencies ────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-alpine AS vendor

RUN install-php-extensions @composer intl pdo_mysql pdo_sqlite zip

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader --no-progress

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev

# ─── Stage 3: Runtime (FrankenPHP + Supervisor) ────────────────────────────
FROM dunglas/frankenphp:1-php8.4-alpine AS app

# ffmpeg: Offline-Lautheitsmessung (EBU R128) durch den Queue-Worker.
RUN apk add --no-cache ffmpeg

RUN install-php-extensions \
        intl \
        opcache \
        pcntl \
        bcmath \
        pdo_mysql \
        pdo_sqlite \
        zip \
        gd \
        mbstring \
        redis

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-radioring.ini"

WORKDIR /app

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh
COPY docker/healthcheck.sh   /usr/local/bin/healthcheck.sh

# docs/ bleibt bewusst erhalten: die Hilfeseite rendert das Handbuch zur Laufzeit
# aus docs/. Die .dockerignore laesst ohnehin nur die beiden Handbuecher durch.
RUN rm -rf \
        tests \
        docker \
        node_modules \
        resources/js \
        resources/css \
        resources/views/vendor \
        vite.config.* \
        package.json package-lock.json \
        phpunit.xml phpunit.xml.dist \
        .env .env.example .dockerignore \
        .git .github \
    && mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/app/stations \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh

# TLS terminiert am Reverse-Proxy vor dem Container
ENV APP_ENV=production APP_DEBUG=false APP_MODE=all

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD healthcheck.sh

ENTRYPOINT ["entrypoint.sh"]
