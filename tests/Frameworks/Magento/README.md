# Magento Test Framework Set-up Procedure

- After downloading the Magento release to be tested, add the following to the `composer.json`:
```json
"scripts": {
        "post-update-cmd": [
            "find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} +\n",
            "find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} +\n",
            "chmod u+x bin/magento",
            "chmod u+x install-magento",
            "sudo ./install-magento"
        ]
    }
```
- Create a `install-magento` file with the following content:
```sh
#!/usr/bin/env bash

php -d memory_limit=1G ./bin/magento setup:install \
    --base-url=http://localhost/ \
    --backend-frontname=admin \
     --language=en_US \
     --timezone=America/Los_Angeles \
     --currency=USD \
     --db-host=mysql-integration \
     --db-name=test \
     --db-user=test \
     --db-password=test \
     --use-secure=0 \
     --base-url-secure=0 \
     --use-secure-admin=0 \
     --admin-firstname=Admin \
     --admin-lastname=Admin \
     --admin-email=admin@admin.com \
     --admin-user=admin \
     --admin-password=Magento2 \
     --elasticsearch-host=elasticsearch7-integration \
     --search-engine=elasticsearch7 \
     --cleanup-database
```

Note: The last two elasticsearch lines are only required for Magento 2.4+, since from the latter the use of elasticsearch is required

- Use the custom datadog controllers. Copy the `app/code/CustomElement` directory of the other test frameworks and place it at the same location for the newly tested version. Directory structure matters.
