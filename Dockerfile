FROM php:8.3-cli-bookworm

# System packages
RUN apt-get update && apt-get install -y \
    poppler-utils \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# PHP GD
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install gd

# PHP upload / execution configuration
RUN printf '%s\n' \
    'upload_max_filesize=20M' \
    'post_max_size=20M' \
    'memory_limit=512M' \
    'max_execution_time=120' \
    'max_input_time=120' \
    > /usr/local/etc/php/conf.d/nid.ini

WORKDIR /var/www/html

COPY . /var/www/html/

# Upload directory
RUN mkdir -p /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

# Render uses PORT; default to 10000
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /var/www/html"]
