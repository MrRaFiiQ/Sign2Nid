# PHP 8.1 with Apache
FROM php:8.1-apache

# Install poppler-utils for flawless PDF text and image extraction
RUN apt-get update && apt-get install -y \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy all files to Apache document root
COPY . /var/www/html/

# Set appropriate permissions
RUN chown -R www-data:www-data /var/www/html/

# Expose port 80 for Render
EXPOSE 80
