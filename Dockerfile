# ============================================================
# NID PDF EXTRACTION SYSTEM
# Render + Docker + PHP 8.3 + Apache
# ============================================================

FROM php:8.3-apache

# ============================================================
# ENVIRONMENT
# ============================================================

ENV DEBIAN_FRONTEND=noninteractive

WORKDIR /var/www/html


# ============================================================
# SYSTEM PACKAGES
# ============================================================

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


# ============================================================
# PHP GD
# ============================================================

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        zip


# ============================================================
# APACHE
# ============================================================

RUN a2enmod rewrite


# ============================================================
# APACHE SERVER NAME
# Removes AH00558 warning
# ============================================================

RUN echo "ServerName localhost" \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername


# ============================================================
# PHP UPLOAD SETTINGS
# ============================================================

RUN printf '%s\n' \
    'upload_max_filesize=20M' \
    'post_max_size=25M' \
    'memory_limit=512M' \
    'max_execution_time=120' \
    'max_input_time=120' \
    'max_file_uploads=10' \
    > /usr/local/etc/php/conf.d/nid.ini


# ============================================================
# COMPOSER
# ============================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# ============================================================
# COPY COMPOSER FILE FIRST
# Better Docker cache
# ============================================================

COPY composer.json /var/www/html/


# ============================================================
# COMPOSER INSTALL
# ============================================================

RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist


# ============================================================
# COPY PROJECT
# ============================================================

COPY . /var/www/html/


# ============================================================
# IMPORTANT:
# Remove files/directories that may already exist
# Then recreate writable directories
#
# This fixes:
# mkdir: cannot create directory '/var/www/html/uploads':
# File exists
# ============================================================

RUN rm -rf \
        /var/www/html/uploads \
        /var/www/html/images \
    && mkdir -p \
        /var/www/html/uploads \
        /var/www/html/images \
    && chmod -R 777 \
        /var/www/html/uploads \
        /var/www/html/images


# ============================================================
# APACHE DOCUMENT ROOT
# ============================================================

RUN chown -R www-data:www-data \
        /var/www/html


# ============================================================
# KEEP UPLOAD DIRECTORIES WRITABLE
# ============================================================

RUN chmod -R 777 \
        /var/www/html/uploads \
        /var/www/html/images


# ============================================================
# APACHE PORT
# Render provides PORT dynamically.
# Apache listens on 10000.
# ============================================================

RUN sed -i \
    's/Listen 80/Listen 10000/g' \
    /etc/apache2/ports.conf


RUN sed -i \
    's/:80>/:10000>/g' \
    /etc/apache2/sites-available/000-default.conf


# ============================================================
# APACHE CONFIG
# ============================================================

RUN printf '%s\n' \
    '<VirtualHost *:10000>' \
    '    DocumentRoot /var/www/html' \
    '' \
    '    <Directory /var/www/html>' \
    '        Options FollowSymLinks' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf


# ============================================================
# HEALTH / DEFAULT
# ============================================================

EXPOSE 10000


# ============================================================
# START APACHE
# ============================================================

CMD ["apache2-foreground"]
