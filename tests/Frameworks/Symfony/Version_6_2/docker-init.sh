#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false
export DATABASE_URL="sqlite:////var/www/var/app.db"

composer install --no-scripts
mkdir -p var
php bin/console cache:clear
php bin/console doctrine:database:drop --force 2>/dev/null || true
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
php << 'PHPEOF'
<?php
$db = new PDO('sqlite:/var/www/var/app.db');
$stmt = $db->prepare('INSERT OR IGNORE INTO "user" (email, password, roles) VALUES (?, ?, ?)');
$stmt->execute(['test-user@email.com', '$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i', '[]']);
PHPEOF
chown -R www-data:www-data var
