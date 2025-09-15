# Use the official PHP 8.2 with Apache image as a base
FROM php:8.2-apache

# Enable the Apache rewrite module, which is required for .htaccess to function
RUN a2enmod rewrite

# Install system dependencies
# - git, zip, unzip are needed for composer
# - ffmpeg is needed for audio conversion
# - poppler-utils contains pdftotext, which is a dependency for the PDF parser library
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    ffmpeg \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader

# Copy the rest of the application files
COPY . .

# Copy the .htaccess file to enable URL rewriting for the front controller pattern
COPY .htaccess /var/www/html/.htaccess

# Overwrite the default Apache virtual host config to enable AllowOverride All
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Expose port 80 for Apache
EXPOSE 80

