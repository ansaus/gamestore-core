FROM php:8.3-fpm-alpine

# System + build deps
RUN apk add --no-cache \
        postgresql-dev \
        postgresql-client \
        linux-headers \
        git \
        unzip \
        bash \
        shadow \
        $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pcntl \
        bcmath \
        opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Код проброшен bind-mount'ом с хоста. Чтобы php-fpm мог писать в storage/,
# а созданные им файлы не превращались в root-мусор на хосте,
# переносим www-data на uid/gid хостового пользователя.
ARG UID=1000
ARG GID=1000
RUN if [ "$(id -u www-data)" != "${UID}" ]; then \
        groupmod -o -g "${GID}" www-data && \
        usermod  -o -u "${UID}" -g "${GID}" www-data; \
    fi

# Sane FPM logging to stdout/stderr
RUN { \
        echo 'access.log = /proc/self/fd/2'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } >> /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html

CMD ["php-fpm"]
