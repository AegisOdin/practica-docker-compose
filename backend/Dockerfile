FROM php:8.3-apache

# Extensiones para MariaDB (pdo_mysql) y Redis (phpredis)
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis

# Toda ruta que no sea un archivo se entrega a index.php, que hace de enrutador
RUN { \
        echo 'ServerName backend'; \
        echo 'ServerTokens Prod'; \
        echo 'ServerSignature Off'; \
        echo 'FallbackResource /index.php'; \
    } > /etc/apache2/conf-available/api.conf \
    && a2enconf api \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/api.ini"

COPY src/ /var/www/html/

EXPOSE 80
