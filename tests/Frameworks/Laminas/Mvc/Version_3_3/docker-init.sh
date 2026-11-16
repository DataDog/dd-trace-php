#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

composer install --no-interaction --no-dev
chown -R www-data:www-data vendor

mkdir -p data/cache /tmp/logs/laminas
rm -f data/cache/*
rm -f /tmp/laminas_appsec.sqlite
touch /tmp/laminas_appsec.sqlite

php -r '
$pdo = new PDO("sqlite:/tmp/laminas_appsec.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL)");
$hash = md5("password");
$stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
$stmt->execute(["Ci User", "ciuser@example.com", $hash]);
'

# Override the MySQL database configuration used by tracer tests with SQLite,
# since the AppSec container has no MySQL. Laminas' glob loader picks up
# *.local.php after *.global.php, so this takes precedence.
cat > config/autoload/database.local.php << 'PHPEOF'
<?php

return [
    'db' => [
        'driver'  => 'Pdo',
        'dsn'     => 'sqlite:/tmp/laminas_appsec.sqlite',
    ],
];
PHPEOF

chown www-data:www-data /tmp/laminas_appsec.sqlite
chown -R www-data:www-data /var/www/data
chown www-data:www-data /tmp/logs/laminas
