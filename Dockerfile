# Stage 1: PHP deps — the Vite build imports vendor CSS
# (rayzenai/project-management workspace.css) and app.css `@source`s vendor
# views. Autoloader generated here (no --no-autoloader) because stage 2 runs
# artisan.
FROM composer:2 AS phpdeps
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --ignore-platform-reqs

# Stage 2: assets. The wayfinder Vite plugin shells out to
# `php artisan wayfinder:generate` during `vite build` (its output dirs are
# gitignored), so this stage needs PHP + the whole app, not just Node.
FROM serversideup/php:8.5-cli AS assets
USER root
RUN install-php-extensions intl && \
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y --no-install-recommends nodejs && \
    apt-get clean && rm -rf /var/lib/apt/lists/*
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=phpdeps /build/vendor ./vendor
RUN npm run build

# Stage 3: PHP app (nginx + fpm in one container, listens on 8080)
FROM serversideup/php:8.5-fpm-nginx

USER root
RUN install-php-extensions pdo_pgsql bcmath intl gd redis exif zip

# Autorun handles migrate --force, storage:link, and config/route/view caching on boot
ENV AUTORUN_ENABLED=true
ENV PHP_OPCACHE_ENABLE=1

COPY --chown=www-data:www-data . /var/www/html
COPY --from=assets --chown=www-data:www-data /build/public/build /var/www/html/public/build

USER www-data
RUN composer install --no-dev --optimize-autoloader --no-interaction
