FROM serversideup/php:8.4-fpm-nginx

USER root

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY --chown=www-data:www-data . .

RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

RUN composer dump-autoload --optimize

USER www-data

EXPOSE 8080
