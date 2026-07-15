FROM php:8.4-fpm

ARG WWW_UID=1000
ARG WWW_GID=1000

# Remap www-data to the host developer UID/GID so php-fpm writes to the
# bind-mounted project (storage/, bootstrap/cache/) as the host user — no chmod 0777.
RUN groupmod -o -g "${WWW_GID}" www-data && usermod -o -u "${WWW_UID}" www-data

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        intl \
        bcmath \
        zip \
        opcache \
        exif \
        pcntl \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Product-image upload limits (marketplace galleries). Application-level caps
# are enforced in Form Requests; these just keep PHP from truncating first.
RUN { \
        echo 'upload_max_filesize = 16M'; \
        echo 'post_max_size = 32M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
