#!/bin/sh
# Entrypoint container app: terapkan migration, lalu jalankan Apache.
#
# Dijalankan setiap kali container naik, dan itu aman: database/migrate.php
# hanya menerapkan file .sql yang belum tercatat di tabel schema_migration.
# Akibatnya schema dan data seed masuk tepat SEKALI — saat first boot dengan
# volume database yang masih kosong — dan restart berikutnya tidak menyentuh
# data sama sekali.
#
# compose.yaml menahan container ini sampai healthcheck MySQL lulus
# (depends_on: service_healthy), sehingga database pasti sudah menerima
# koneksi saat migration dijalankan.
#
# Bila migration gagal, container berhenti dengan exit code bukan nol dan
# pesannya terlihat di `docker compose logs app`. Aplikasi tidak dijalankan
# di atas schema yang setengah jadi.
set -eu

php /var/www/html/database/migrate.php

# Serahkan ke entrypoint bawaan image php:8.4-apache dengan CMD aslinya
# (apache2-foreground), sehingga Apache tetap menjadi PID 1.
exec docker-php-entrypoint "$@"
