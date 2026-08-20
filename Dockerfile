FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    && docker-php-ext-install \
    gd \
    mbstring \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./

RUN if [ -f composer.json ]; then \
        composer install --no-dev --optimize-autoloader; \
    fi

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads \
    /var/www/html/images \
    && chmod -R 777 /var/www/html/uploads \
    /var/www/html/images

RUN printf "ServerName localhost\n" \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

RUN printf '%s\n' \
    'upload_max_filesize=30M' \
    'post_max_size=35M' \
    'memory_limit=512M' \
    'max_execution_time=180' \
    'max_input_time=180' \
    > /usr/local/etc/php/conf.d/nid.ini
