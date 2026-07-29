# FrankenPHP - PHP 8.5 Alpine (Symfony 8.0 requires PHP >= 8.4)
FROM dunglas/frankenphp:1-php8.5-alpine

RUN apk add --no-cache git unzip bash
RUN install-php-extensions intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN git config --global --add safe.directory /app && \
    git config --global --add safe.directory /var/translation-yaml-tools-bundle

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
