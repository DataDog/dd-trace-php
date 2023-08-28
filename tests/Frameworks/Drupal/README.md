- Replace `REQUIREMENT_ERROR` by `REQUIREMENT_WARNING` in any *.install file requiring `gd`, or comment out the requirement
  - The `--ignore-platform-reqs` matters when running `composer update` since Drupal otherwise requires `gd`
- Modify `Drupal\Core\Command\InstallCommand::install` (lib) for `mysql` to be used instead of `sqlite` by default with the snippet below on the `install_settings_form` key
```php
'install_settings_form' => [
    'driver' => 'mysql',
    'mysql' => [
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
        'host' => 'mysql_integration',
        'prefix' => '',
        ],
    ]
```
- Use the `erase_drupal_db.php` script and run it in the post-update-cmd section of the root `composer.json`
- Remove the cache dependencies from `minimal.info.yml`
- Use the custom datadog modules (`modules/`) and add it as a dependency in `text.info.yml` (`- datadog`) for it to be loaded and enabled
- In the Makefile, the `test_web_drupal_XX` should do a `composer update` on the root of the framework's dir + in the /core dir
- Modify `Drupal\mysql\Driver\Database\mysql\Install\Tasks::MYSQL_MINIMUM_VERSION` to `5.6.47` (the MySQL version the CI is using...)

**If generating the snapshots without the SQL spans, then this should be enough. Else, consider the following:**
- Modify all `cache.*` in `core.services.yml` to use `class: Drupal\Core\Cache\NullBackendFactory`
  - If still flaky, use the `development` environment in `index.php` and the other drupal test framework's `settings.local.php` & `services.yml` (disable caching)
- Uncomment the following block at the end of `default.settings.php`:
```php
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
include $app_root . '/' . $site_path . '/settings.local.php';
}
```
- Look at Version 8.9's test framework for `settings.local.php`, and `services.yml`
