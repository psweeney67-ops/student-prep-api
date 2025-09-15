
# Use the official PHP 8.2 with Apache base image
FROM php:8.2-apache

# Install system dependencies
# - git, zip/unzip are needed for Composer
# - ffmpeg is required for audio conversion from raw PCM to MP3
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP package manager)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set the working directory in the container
WORKDIR /var/www/html

# Copy the application files into the container
COPY . /var/www/html/

# Run Composer to install PHP dependencies (like Guzzle)
RUN composer install --no-dev --optimize-autoloader

# Expose port 80 for the Apache web server
EXPOSE 80

