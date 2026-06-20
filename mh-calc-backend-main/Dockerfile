# IziGo backend (Laravel) — образ для Azure Container Apps. Фаза 0: baseline-деплой.
# Прод-уровень (php-fpm+nginx/Octane) — доработать в Фазе 1; сейчас artisan serve для smoke.
FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libonig-dev unzip git \
    && docker-php-ext-install pdo_pgsql bcmath mbstring zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/start.sh \
    && chmod -R ug+rw storage bootstrap/cache

ENV APP_ENV=production
EXPOSE 8080
CMD ["docker/start.sh"]
