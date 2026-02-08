# Banking Bridge - FinTS to Home Assistant
FROM php:8.2-apache

# Install system dependencies including timezone data
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libsqlite3-dev \
    zip \
    unzip \
    cron \
    tzdata \
    && docker-php-ext-install pdo pdo_sqlite mbstring sockets \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set default timezone (can be overridden by TZ env variable)
ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure PHP settings
RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "date.timezone = Europe/Berlin" >> /usr/local/etc/php/conf.d/custom.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY app/ /var/www/html/

# Apache configuration
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/sites-available/000-default.conf

# Create .htaccess for URL rewriting
RUN echo 'RewriteEngine On\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteCond %{REQUEST_FILENAME} !-d\n\
RewriteRule ^ index.php [QSA,L]' > /var/www/html/public/.htaccess

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Apply phpFinTS patches (add support for HIWPDS v6 and extended MT535 parsing)
COPY app/patches/Fhp/Segment/WPD/*.php /var/www/html/vendor/nemiah/php-fints/lib/Fhp/Segment/WPD/
COPY app/patches/Fhp/Action/GetDepotAufstellung.php /var/www/html/vendor/nemiah/php-fints/lib/Fhp/Action/GetDepotAufstellung.php
COPY app/patches/Fhp/MT535/MT535.php /var/www/html/vendor/nemiah/php-fints/lib/Fhp/MT535/MT535.php

# Regenerate autoloader to include patched classes
RUN composer dump-autoload --optimize --no-interaction

# Create data directory with proper permissions
RUN mkdir -p /data && chown -R www-data:www-data /data && chmod 755 /data

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Copy entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Health check
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/ || exit 1

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
