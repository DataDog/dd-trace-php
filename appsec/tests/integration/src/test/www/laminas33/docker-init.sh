#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

composer install --no-interaction --no-dev
chown -R www-data:www-data vendor

mkdir -p data/cache /tmp/logs/laminas
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

chown www-data:www-data /tmp/laminas_appsec.sqlite
chown -R www-data:www-data /var/www/data
mkdir -p /tmp/logs/laminas
chown www-data:www-data /tmp/logs/laminas
