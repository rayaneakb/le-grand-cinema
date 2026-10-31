FROM node:24-bookworm-slim AS frontend-build

WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.4-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/app/backend
COPY backend/ ./
RUN printf 'APP_ENV=prod\nAPP_DEBUG=0\n' > .env \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY --from=frontend-build /app/frontend/dist/ public/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/start.sh /usr/local/bin/start-cinema

RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && chmod +x /usr/local/bin/start-cinema

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PORT=10000 \
    LOAD_DEMO_DATA=0

EXPOSE 10000
CMD ["start-cinema"]
