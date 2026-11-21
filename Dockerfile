# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.13.5-php8.4.27-trixie AS base
RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql redis zip

# Wayfinder runs artisan during the Vite build, so this stage needs PHP and Node together.
FROM base AS build
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# The assets are built with APP_ENV=build, not production: in production
# AppServiceProvider forces the root URL to APP_URL, unset here
# (http://localhost), and Wayfinder would bake it into the login and other routes.
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && APP_ENV=build APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build:ssr \
    && rm -rf node_modules resources/js resources/css bootstrap/cache/*.php

FROM base AS runtime
WORKDIR /app
# Runs the server-side rendering bundle (the ssr service in deploy/compose.yaml).
# The bundle carries its own dependencies, so node_modules is not needed.
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-store.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/store-entrypoint
COPY --from=build /app /app

RUN chmod +x /usr/local/bin/store-entrypoint \
    && ln -s ../storage/app/public public/storage \
    && setcap CAP_NET_BIND_SERVICE=+ep /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /data/caddy /config/caddy bootstrap/cache storage

USER www-data
EXPOSE 80 443 443/udp
ENTRYPOINT ["store-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up -o /dev/null
