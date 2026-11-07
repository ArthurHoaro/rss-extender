FROM php:8.5-fpm-alpine3.24

RUN apk --no-cache add nginx s6

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY .docker/services.d /etc/services.d
COPY .docker/php-limits.ini /usr/local/etc/php/conf.d

WORKDIR /var/www/rssextender
COPY composer.json composer.lock ./
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer install --no-dev --no-autoloader --no-scripts --prefer-dist --no-interaction

COPY . .
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --no-dev --optimize \
    && chown -R nginx:nginx . \
    && ln -sf /dev/stdout /var/log/nginx/rssextender.access.log \
    && ln -sf /dev/stderr /var/log/nginx/rssextender.error.log

VOLUME /var/www/rssextender/cache
VOLUME /var/www/rssextender/data

EXPOSE 80

ENTRYPOINT ["/usr/bin/s6-svscan", "/etc/services.d"]
CMD []
