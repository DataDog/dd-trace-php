- Replace `REQUIREMENT_ERROR` by `REQUIREMENT_WARNING` in any *.install file requiring `gd`
  - The `--ignore-platform-reqs` matters when running `composer update` since Drupal otherwise requires `gd`
- Modify `Drupal\Core\Command\InstallCommand::install` for `mysql` to be used instead of `sqlite` by default with the snippet below on the `install_settings_form` key
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
- Change the following in `default.settings.php`:
```php
$databases['default']['default'] = [
    'driver' => 'mysql',
    'database' => 'test',
    'username' => 'test',
    'password' => 'test',
    'host' => 'mysql_integration',
    'prefix' => '',
];
# Uncomment the following block at the end
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
    include $app_root . '/' . $site_path . '/settings.local.php';
}
```
- Use the `erase_drupal_db.php` script (see the `composer.json` files)

- In the Makefile, the `test_web_drupal_XX` should do a `composer update` on the root of the framework's dir + in the /core dir
- To reliably recreate the snapshots for the CI, recreate a CI-like state
  - Delete the relevant snapshots
  - Stop and delete the mysql docker container, if any
  - Run the buster container
  - `make install && make composer_tests_update`
  - `make test_web_drupal_89`
  - `make test_web_drupal_95`
  - `...`
- Modify all `cache.*` in `core.services.yml` to use `class: Drupal\Core\Cache\NullBackendFactory`
  - If still flaky, use the `development` environment in `index.php` and the other drupal test framework's `settings.local.php` & `services.yml` (disable caching)
