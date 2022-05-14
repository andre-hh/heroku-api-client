FROM php:8.0-cli

# Install composer with composer-installer.sh
COPY .docker/composer-installer.sh /usr/local/bin/composer-installer
RUN apt-get -yqq update \
    && apt-get -yqq install --no-install-recommends unzip \
    && apt-get -yqq install --no-install-recommends ssh \
    && apt-get install -y git \
    && chmod +x /usr/local/bin/composer-installer \
    && composer-installer \
    && mv composer.phar /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer self-update \
    && composer --version

RUN echo "error_reporting=E_ALL" > /usr/local/etc/php/conf.d/php.ini && \
    echo "display_errors=1" >> /usr/local/etc/php/conf.d/php.ini  && \
    echo "log_errors=1" >> /usr/local/etc/php/conf.d/php.ini  && \
    echo "memory_limit=450M" >> /usr/local/etc/php/conf.d/php.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

# Install composer dependencies
RUN composer install

RUN date > container_created_at
