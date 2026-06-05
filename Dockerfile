FROM php:8.2-apache

# Install PHP extensions needed for MySQL/PDO
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite for URL routing
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy all project files
COPY . /var/www/html/

# Copy Apache virtual host config
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Ensure upload directories exist and are writable
RUN mkdir -p assets/uploads/bitacoras assets/uploads/documentos assets/uploads/galeria \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 assets/uploads

# Copy and enable entrypoint (needed for Render's dynamic PORT)
# sed removes Windows CRLF line endings that break bash on Linux
COPY entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
