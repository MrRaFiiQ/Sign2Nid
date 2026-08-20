FROM php:8.3-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive

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
# PHP EXTENSIONS
# ============================================================

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        zip


# ============================================================
# COMPOSER
# ============================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# ============================================================
# WORK DIRECTORY
# ============================================================

WORKDIR /var/www/html


# ============================================================
# COMPOSER
# ============================================================

COPY composer.json ./

RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction


# ============================================================
# APPLICATION
# ============================================================

COPY . /var/www/html/


# ============================================================
# DIRECTORY PERMISSIONS
# ============================================================

RUN chmod -R 777 \
        /var/www/html/uploads \
        /var/www/html/images


# ============================================================
# APACHE SERVER NAME
# ============================================================

RUN echo "ServerName localhost" \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername


# ============================================================
# PHP CONFIGURATION
# ============================================================

RUN printf '%s\n' \
    'file_uploads=On' \
    'upload_max_filesize=30M' \
    'post_max_size=35M' \
    'memory_limit=512M' \
    'max_execution_time=180' \
    'max_input_time=180' \
    'max_file_uploads=20' \
    > /usr/local/etc/php/conf.d/nid.ini


# ============================================================
# APACHE MODULES
# ============================================================

RUN a2enmod rewrite headers


# ============================================================
# START
# ============================================================

CMD ["apache2-foreground"]
