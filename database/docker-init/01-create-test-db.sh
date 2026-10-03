#!/bin/bash
# Database khusus integration test, dibuat sekali saat volume MySQL
# diinisialisasi pertama kali. Terpisah dari database aplikasi agar
# integration test tidak pernah menyentuh data demo.
#
# Ditulis sebagai shell script, bukan .sql, karena file .sql di
# docker-entrypoint-initdb.d tidak dapat membaca environment variable -
# nama database dan user akan ikut salah begitu .env diubah.
set -euo pipefail

test_database="${DB_DATABASE_TEST:-ioms_test}"

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${test_database}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${test_database}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
