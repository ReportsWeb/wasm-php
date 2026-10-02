FROM php:8.3-cli-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && pecl install xdebug-3.4.7 && docker-php-ext-enable xdebug
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY xdebug.ini /usr/local/etc/php/conf.d/99-sample-debug.ini
WORKDIR /opt/composer
COPY composer.json ./
RUN COMPOSER_VENDOR_DIR=/opt/composer/vendor composer install --no-dev --no-interaction --no-progress --prefer-dist
ENV COMPOSER_AUTOLOAD=/opt/composer/vendor/autoload.php
WORKDIR /app
COPY . .
USER www-data
CMD ["php", "-S", "0.0.0.0:8080", "router.php"]
