#!/usr/bin/env bash
set -e

cd /var/www/html

mkdir -p storage/logs
chown -R www-data:www-data storage

echo "Waiting for database..."

until php -r '
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        getenv("DB_HOST"),
        getenv("DB_PORT"),
        getenv("DB_DATABASE")
    );
    new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
' 2>/dev/null; do
    sleep 2
done

php bin/migrate.php
php bin/seed.php

exec "$@"
