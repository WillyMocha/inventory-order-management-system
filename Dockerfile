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

# pcov hanya dipakai untuk laporan coverage SonarQube (`composer test:coverage`).
# Dimatikan secara default sehingga request web dan test biasa tidak menanggung
# overhead-nya; script coverage menyalakannya per proses dengan -d pcov.enabled=1.
RUN pecl install pcov-1.0.12     && docker-php-ext-enable pcov     && echo 'pcov.enabled=0' > "$PHP_INI_DIR/conf.d/zz-pcov.ini"

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

# Migration dan data seed diterapkan otomatis setiap container naik; hanya file
# yang belum tercatat di schema_migration yang dijalankan, jadi seed masuk sekali
# saat first boot. Script disalin ke luar /var/www/html agar tidak tertimpa bind
# mount di compose.yaml. sed membuang CR: checkout Windows tanpa .gitattributes
# menghasilkan CRLF, dan shebang dengan CR membuat container gagal start.
COPY docker/entrypoint.sh /usr/local/bin/ioms-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/ioms-entrypoint \
    && chmod +x /usr/local/bin/ioms-entrypoint

EXPOSE 80

ENTRYPOINT ["ioms-entrypoint"]
CMD ["apache2-foreground"]
