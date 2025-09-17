# Use the official PHP 8.2 with Apache image as a base
FROM php:8.2-apache

# Enable the Apache rewrite module
RUN a2enmod rewrite

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy application files BEFORE installing dependencies
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# --- FINAL FIX: Set correct ownership and permissions ---
# This ensures the Apache user (www-data) can read and execute the files.
RUN chown -R www-data:www-data /var/www/html

# Copy our custom Apache config, overwriting the default.
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# (For debugging) List the files and their permissions in the build log.
RUN ls -la

# Set the start command to run the Apache web server.
CMD ["apache2-foreground"]

