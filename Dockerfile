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

# Set the correct file ownership for the application code
RUN chown -R www-data:www-data /var/www/html

# --- FINAL FIX ---
# This command runs every time the container starts.
# It first sets the correct permissions on the mounted /data disk,
# and then it starts the Apache web server.
CMD chown -R www-data:www-data /data && apache2-foreground

