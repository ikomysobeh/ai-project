FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

# Runtime libs (kept) + build toolchain (kept alive for the whole RUN so
# `pecl install redis` still has autoconf/gcc after docker-php-ext-install
# — which pulls its own build deps as a virtual pkg and purges them itself).
RUN apk add --no-cache \
        bash \
        curl \
        git \
        unzip \
        nodejs \
        npm \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        postgresql-dev \
        oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        gd \
        intl \
        zip \
        pcntl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
# Dev dependencies (pail, sail, pint, ...) are kept here on purpose: this is a
# dev image, and bootstrap/cache/*.php (bind-mounted from the host, which was
# composer-installed with dev deps) already references their providers —
# a --no-dev vendor/ would be missing classes those cached files expect.
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
