FROM php:7.4-fpm-alpine3.12

RUN apk --update --no-cache add \
        ca-certificates \
        nginx \
        curl \
        s6

COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/php-fpm.conf /etc/php7/php-fpm.conf
COPY .docker/services.d /etc/services.d

RUN rm -rf /etc/php7/php-fpm.d/www.conf

WORKDIR /var/www
COPY . rssextender

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
WORKDIR /var/www/rssextender
RUN /usr/bin/composer install --prefer-dist --no-dev

RUN chown -R nginx:nginx . \
    && ln -sf /dev/stdout /var/log/nginx/rssextender.access.log \
    && ln -sf /dev/stderr /var/log/nginx/rssextender.error.log

VOLUME /var/www/rssextender/cache
VOLUME /var/www/rssextender/data

EXPOSE 80

ENTRYPOINT ["/bin/s6-svscan", "/etc/services.d"]
CMD []
