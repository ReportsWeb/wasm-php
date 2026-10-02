FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev git unzip \
 && docker-php-ext-install pdo_pgsql \
 && pecl install xdebug-3.4.7 && docker-php-ext-enable xdebug \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY xdebug.ini /usr/local/etc/php/conf.d/99-sample-debug.ini
WORKDIR /opt/reports/php
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist
COPY public/ /opt/reports/php/public/
COPY src/ /opt/reports/php/src/
COPY resources/ /opt/reports/php/resources/
COPY apache.conf /etc/apache2/conf-enabled/reports-sample.conf
EXPOSE 80
