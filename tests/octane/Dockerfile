# The dodgeprint runtime in miniature: Alpine 3.19 with php82 and
# php82-pecl-swoole (as in its image), a fresh Laravel 9 app with
# laravel/octane 1.5.6 and laravel/horizon 5, redis, and the SDK installed from
# a path repository. The `sdk` build context is the SDK's composer.json, src
# and config; run.sh mounts the live SDK over /sdk and this tests/ directory
# on /tests at run time, so SDK and scenario changes need no rebuild.
FROM alpine:3.19

RUN apk add --no-cache bash curl procps redis composer \
        php82 php82-phar php82-mbstring php82-openssl php82-curl php82-tokenizer php82-dom \
        php82-xml php82-xmlwriter php82-xmlreader php82-simplexml php82-fileinfo php82-ctype \
        php82-session php82-pdo php82-pdo_sqlite php82-sqlite3 php82-zlib php82-pcntl php82-posix \
        php82-iconv php82-sodium php82-intl php82-sockets php82-pecl-swoole php82-pecl-redis \
    && ln -sf /usr/bin/php82 /usr/bin/php \
    && php -m | grep -qx swoole

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

RUN composer create-project --prefer-dist --no-progress laravel/laravel:^9.0 . \
    && composer require --no-progress -W laravel/octane:1.5.6 'laravel/horizon:^5.47' \
    && composer clear-cache

COPY --from=sdk composer.json /sdk/composer.json
COPY --from=sdk src /sdk/src
COPY --from=sdk config /sdk/config
RUN composer config repositories.sdk '{"type": "path", "url": "/sdk", "options": {"symlink": true}}' \
    && composer require --no-progress 'songhieu/monitor-track-laravel:*@dev' \
    && composer clear-cache \
    && ls -l vendor/songhieu/

COPY app/ /app/
RUN php artisan horizon:install && php artisan --version
