#!/bin/bash
set -e

# Railway dynamically assigns $PORT
TARGET_PORT=${PORT:-80}

sed -i "s/Listen [0-9]*/Listen $TARGET_PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:$TARGET_PORT>/g" /etc/apache2/sites-available/000-default.conf

# Ensure uploads directory is writable
mkdir -p /var/www/html/uploads/receipts /var/www/html/uploads/reviews
chmod -R 777 /var/www/html/uploads 2>/dev/null || true

exec apache2-foreground
