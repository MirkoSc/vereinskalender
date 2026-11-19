# Local development and test image (CLAUDE.md section 11), taken over from
# the sister project Mitgliederverwaltung.
#
# Base: the official Drupal image. It is nothing but the official
# php:8.5-apache plus exactly the compiled extensions this application needs
# beyond the core image (gd, zip, pdo_mysql) - so building ours needs no
# package mirror and no compiler, only the image registry. That matters
# where the build runs without access to deb.debian.org (the sandbox of the
# coding agents, docs/VORGEHEN.md); everywhere else it simply makes the
# build fast. Drupal itself is removed below. Images come from
# mirror.gcr.io because Docker Hub throttles anonymous pulls.
FROM mirror.gcr.io/library/drupal:php8.5-apache

# Undo what the image adds for Drupal: its code, the docroot symlink and
# the PATH entry. What stays is the plain php:8.5-apache layout.
RUN rm -rf /opt/drupal \
 && rm -f /var/www/html \
 && mkdir -p /var/www/html \
 && a2enmod rewrite headers
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
WORKDIR /var/www/html

# The docroot contains only the production-style shim (docker/web/);
# the release itself is mounted next to it as /var/www/html/current.
RUN sed -ri 's!DocumentRoot /var/www/html$!DocumentRoot /var/www/html/web!' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/web>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/docroot-override.conf \
 && a2enconf docroot-override

# Composer for the developer, never for the application: the image forbids
# process calls (disable_functions in shared-hosting.ini) like the hoster
# does, and Composer needs proc_open. The wrapper lifts the restriction for
# Composer's own process only.
RUN mv /usr/local/bin/composer /usr/local/lib/composer.phar \
 && printf '#!/bin/sh\nexec php -d disable_functions= -d memory_limit=-1 /usr/local/lib/composer.phar "$@"\n' \
        > /usr/local/bin/composer \
 && chmod +x /usr/local/bin/composer

COPY shared-hosting.ini /usr/local/etc/php/conf.d/zz-shared-hosting.ini
