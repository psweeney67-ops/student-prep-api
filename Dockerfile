# Use the official PHP 8.2 with Apache image as a base
FROM php:8.2-apache

# Install system dependencies needed by the worker (e.g., for podcast generation)
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory for the application
WORKDIR /var/www/html

# Copy all the application files into the server's directory
COPY . .

# Install the PHP libraries required by the application
RUN composer install --no-dev --optimize-autoloader

# Set the correct file ownership so the web server can access the files
RUN chown -R www-data:www-data /var/www/html

# The default command for this base image is to start the Apache web server,
# which is exactly what we need.

