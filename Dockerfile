FROM php:8.2-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Allow .htaccess overrides in Apache document root
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Production PHP settings for file uploads and execution limits
RUN echo "upload_max_filesize = 64M\npost_max_size = 64M\nmemory_limit = 256M\nmax_execution_time = 120" > /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

# Copy application source code
COPY . /var/www/html/

# Create uploads directories and set permissions
RUN mkdir -p /var/www/html/uploads/receipts /var/www/html/uploads/reviews \
    && chmod -R 777 /var/www/html/uploads

# Copy and setup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT=80

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
