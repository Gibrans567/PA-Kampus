FROM php:8.2-fpm AS php

USER root

RUN mkdir -p /var/lib/apt/lists && chmod -R 755 /var/lib/apt/lists

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    libssl-dev \
    libgd-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && docker-php-ext-install pdo_mysql bcmath sockets \
    && rm -rf /var/lib/apt/lists/*


COPY --from=composer:2.3.5 /usr/bin/composer /usr/bin/composer

COPY ./nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY ./netpro /usr/share/nginx/html

WORKDIR /var/www
COPY . /var/www
COPY . .

RUN composer install --optimize-autoloader --no-dev

RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www/storage \
    && chmod -R 775 /var/www/bootstrap/cache

RUN php artisan config:cache
RUN php artisan route:cache

EXPOSE 9000

USER www-data

CMD ["php-fpm"]
