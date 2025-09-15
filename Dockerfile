# Use the official PHP 8.2 image with an Apache web server
FROM php:8.2-apache

# Install system dependencies that Composer might need
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip

# Install Composer (the PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory in the container
WORKDIR /var/www/html

# Copy the composer files
COPY composer.json .

# Install PHP dependencies (like Guzzle)
# --no-interaction prevents it from asking questions
# --no-dev removes development-only packages
RUN composer install --no-interaction --no-dev

# Copy the rest of your application code
COPY . .

# Expose port 80 for the Apache server
EXPOSE 80
