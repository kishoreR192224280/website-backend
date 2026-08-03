FROM php:8.3-apache

# Install PostgreSQL PDO extension
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    zip \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache rewrite
RUN a2enmod rewrite

# Allow .htaccess
RUN sed -ri \
    -e 's!/var/www/html!/var/www/html!g' \
    -e 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf

# Copy backend files
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80