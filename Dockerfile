# Use the official PHP 8.2 with Apache image as a base
FROM php:8.2-apache

# Enable the Apache rewrite module, which is required for our config to work
RUN a2enmod rewrite

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    ffmpeg \
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

# CRITICAL: Copy our custom Apache config, overwriting the default.
# This ensures our rewrite rules are loaded correctly.
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Set the start command to run the Apache web server.
CMD ["apache2-foreground"]

