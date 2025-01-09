# Drupal Test Framework Set-up Procedure

This guide provides step-by-step instructions to set up the Drupal Test Framework for your project. Follow these procedures to ensure a smooth testing environment and accurate test results.

## Create the test framework

Adjust and run the following command to create the test framework:
```bash
composer create-project drupal/recommended-project:^<major_version> Version_<major_version>
```

## Adjust `REQUIREMENT_ERROR` in `*.install` Files

Replace all instances of `REQUIREMENT_ERROR` with `REQUIREMENT_WARNING` in any `*.install` files.

## Configure Default Database Driver

Modify the library `/Drupal/Core/Command/InstallCommand::install` for the `mysql` driver to be the default instead of `sqlite`. Insert the following code snippet into the `install_settings_form` key of your configuration:
```php
'install_settings_form' => [
    'driver' => 'mysql',
    'mysql' => [
        'database' => 'drupal<version>',
        'username' => 'test',
        'password' => 'test',
        'host' => 'mysql_integration',
        'prefix' => '',
        ],
    ]
```
Don't forget to modify the version. Additionally, newest versions of Drupal use the following format:
```php
'install_settings_form' => [
    'driver' => $mysqlDriverNamespace,
    $mysqlDriverNamespace => [
        'database' => 'drupal<version>',
        'username' => 'test',
        'password' => 'test',
        'host' => 'mysql_integration',
        'prefix' => '',
        ],
    ]
```

## Adjust MySQL Version

Modify the MySQL version in the `Drupal\mysql\Driver\Database\mysql\Install\Tasks::MYSQL_MINIMUM_VERSION` to `5.6.47`. This is the MySQL version that the CI is using.

## Utilize `erase_drupal_db.php` Script

Integrate the `erase_drupal_db.php` script by running it in the `post-update-cmd` section of the root `composer.json`.
Replace by the correct `drupal<version>` database.

```json
"scripts": {
  "post-update-cmd": [
    "chmod a+w web/sites/default && rm -rf web/sites/default/files && rm -f web/sites/default/settings.php && php scripts/erase_drupal_db.php && php web/core/scripts/drupal install minimal"
  ]
},
```

## Remove Cache Dependencies

Remove cache dependencies from the `minimal.info.yml file.

## Integrate Custom Datadog Modules

Incorporate the custom Datadog modules located in the `modules/` directory. Add this module as dependency in the `text.info.yml` file (i.e., `- datadog`). This ensures proper loading and activation of the modules.

## Modify `Makefile`

The `test_web_drupal_XX` should perform a `composer update` on the composer file located under the `/web` directory.

## (Optional) Additional Steps for Generating Snapshots with SQL Spans

If generating snapshots with SQL spans, consider the following additional steps:

- Update all instances of `cache.*` in the `core.services.yml` file to use class: `Drupal\Core\Cache\NullBackendFactory`
- If flakiness persists, use the `development` environment in `index.php` and copy the other Drupal test framework's `settings.local.php` and `services.yml` files (disable caching)
- Uncomment the following block at the end of `default.settings.php`:
```php
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
include $app_root . '/' . $site_path . '/settings.local.php';
}
```

Note that these final steps may not ensure it is completely flake-free, but it should help.
