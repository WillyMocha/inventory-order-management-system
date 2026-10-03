# Image di-pin ke PHP 8.4 secara eksplisit (spec C-001: tidak boleh di bawah
# maupun di atas 8.4). Tag "8.4-apache" tidak akan pernah naik ke 8.5.
FROM php:8.4-apache

# pdo_mysql adalah satu-satunya extension tambahan yang dibutuhkan.
# Tidak ada ORM, jadi tidak ada driver lain yang perlu dipasang.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite mengarahkan seluruh request ke front controller.
# mod_headers dibutuhkan agar blok Header pada public/.htaccess benar-benar
# aktif - tanpa modul ini, security header ditulis tetapi tidak pernah terkirim.
RUN a2enmod rewrite headers

# DocumentRoot diarahkan ke public/ sehingga hanya direktori itu yang
# terekspos ke web. storage/uploads/ berada di luar DocumentRoot (research R-006).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# display_errors dimatikan: stack trace tidak boleh sampai ke user (ERR-01).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'display_errors=Off'; \
        echo 'display_startup_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/dev/stderr'; \
        echo 'upload_max_filesize=4M'; \
        echo 'post_max_size=8M'; \
        echo 'expose_php=Off'; \
    } > "$PHP_INI_DIR/conf.d/99-app.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency dipasang lebih dulu agar layer cache tidak batal setiap kali
# source code berubah.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist || true

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/uploads \
    && chown -R www-data:www-data storage \
    && chmod -R 755 storage

EXPOSE 80
