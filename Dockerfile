FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    pkg-config \
    unzip \
    git \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        zip

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json /var/www/html/

RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction

COPY . /var/www/html/

# Remove conflicting files/directories and recreate them
RUN rm -rf /var/www/html/uploads \
           /var/www/html/images \
    && mkdir -p /var/www/html/uploads \
               /var/www/html/images \
    && chmod -R 777 /var/www/html/uploads \
                    /var/www/html/images

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]
