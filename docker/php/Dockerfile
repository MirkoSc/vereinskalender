FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev \
 && docker-php-ext-install pdo_mysql zip gd \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# The docroot contains only the production-style shim (docker/web/);
# the release itself is mounted next to it as /var/www/html/current.
RUN sed -ri 's!/var/www/html!/var/www/html/web!g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/web>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/docroot-override.conf \
 && a2enconf docroot-override

COPY shared-hosting.ini /usr/local/etc/php/conf.d/zz-shared-hosting.ini
