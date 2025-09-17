# Use the official PHP 8.2 with Apache image as a base
FROM php:8.2-apache

# Enable the Apache rewrite module, which is required for .htaccess to function
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

# Copy the .htaccess file to enable URL rewriting
COPY .htaccess /var/w

