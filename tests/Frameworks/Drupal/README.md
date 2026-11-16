# Drupal Test Framework Set-up Procedure

This guide provides step-by-step instructions to set up the Drupal Test Framework for your project. Follow these procedures to ensure a smooth testing environment and accurate test results.

## Adjust `REQUIREMENT_ERROR` in `*.install` Files

Replace instances of `REQUIREMENT_ERROR` with `REQUIREMENT_WARNING` in any `*.install` files that require the `gd` library. Alternatively, you can comment out the requirement altogether. Note that using `--ignore-platform-reqs` during composer update is crucial to prevent Drupal from mandating the `gd` library.

## Configure Default Database Driver

Modify the library `/Drupal/Core/Command/InstallCommand::install` for the `mysql` driver to be the default instead of `sqlite`. Insert the following code snippet into the `install_settings_form` key of your configuration:
```php
'install_settings_form' => [
    'driver' => 'mysql',
    'mysql' => [
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
        'host' => 'mysql-integration',
        'prefix' => '',
        ],
    ]
```

## Adjust MySQL Version

Modify the MySQL version in the `Drupal\mysql\Driver\Database\mysql\Install\Tasks::MYSQL_MINIMUM_VERSION` to `5.6.47`. This is the MySQL version that the CI is using.

## Utilize `erase_drupal_db.php` Script

Integrate the `erase_drupal_db.php` script by running it in the `post-update-cmd` section of the root `composer.json`.

## Remove Cache Dependencies

Remove cache dependencies from the `minimal.info.yml file.

## Integrate Custom Datadog Modules

Incorporate the custom Datadog modules located in the `modules/` directory. Add this module as dependency in the `text.info.yml` file (i.e., `- datadog`). This ensures proper loading and activation of the modules.

## Modify `Makefile`

The `test_web_drupal_XX` should perform a `composer update` on the root of the framework's directory and in the `/core` directory.

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
