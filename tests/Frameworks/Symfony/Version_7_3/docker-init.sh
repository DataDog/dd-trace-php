#!/bin/bash -ex

mkdir -p /tmp/logs
exec > >(tee -a /tmp/logs/docker-init.log) 2>&1

mark() { echo "::MARK:: $*" >> /tmp/logs/docker-init.log; echo "::MARK:: $*"; sync; }

cd /var/www

export DD_TRACE_CLI_ENABLED=false
export DATABASE_URL="sqlite:////var/www/var/app.db"
export APP_ENV=prod

# The committed config/packages/doctrine.yaml hard-codes a mysql URL (shared with
# trace integration tests that run against a mysql service). Drop a prod-env
# override so DATABASE_URL is honoured and the fixture can run on sqlite.
mkdir -p config/packages/prod
cat > config/packages/prod/doctrine_appsec.yaml << 'YAMLEOF'
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        server_version: ~
YAMLEOF
mark "wrote doctrine_appsec.yaml"

composer config optimize-autoloader false
mark "composer config done"
if [[ -f composer.lock ]]; then
    composer install --no-dev --no-scripts
else
    composer update --no-dev --no-scripts
fi
mark "composer install/update done"

mkdir -p var
# Nuke any residual state from a cached volume: Symfony prod cache AND the
# sqlite database. Starting from a clean DB file lets `doctrine:schema:create`
# succeed without needing a separate `doctrine:database:drop` step (which has
# been observed to hang silently under some SSI setups).
rm -rf var/cache/* var/app.db
mark "cleaned var/"

php bin/console doctrine:schema:create
mark "schema:create done"

php << 'PHPEOF'
<?php
// Symfony 7+ ships a `MakerBundle`-generated User with an extra NOT-NULL
// `is_verified` column. We seed it explicitly so SQLite doesn't reject the
// INSERT (which `OR IGNORE` would silently swallow).
$db = new PDO('sqlite:/var/www/var/app.db');
$stmt = $db->prepare('INSERT OR IGNORE INTO "user" (email, password, roles, is_verified) VALUES (?, ?, ?, ?)');
$stmt->execute(['test-user@email.com', '$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i', '[]', 1]);
PHPEOF
mark "seeded user"

chown -R www-data:www-data var
mark "chown done"

# .env.local wins over .env, so HTTP requests served by Apache also hit SQLite.
cat > /var/www/.env.local << 'ENVEOF'
APP_ENV=prod
DATABASE_URL="sqlite:////var/www/var/app.db"
ENVEOF
mark "DONE"
