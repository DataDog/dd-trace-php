<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-skeleton for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-skeleton/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-skeleton/blob/master/LICENSE.md New BSD License
 */

use Laminas\ApiTools\Application;
use Laminas\Stdlib\ArrayUtils;

/**
 * This makes our life easier when dealing with paths. Everything is relative
 * to the application root now.
 */
chdir(dirname(__DIR__));

// Redirect legacy requests to enable/disable development mode to new tool
if (php_sapi_name() === 'cli'
    && $argc > 2
    && 'development' == $argv[1]
    && in_array($argv[2], ['disable', 'enable'])
) {
    // Windows needs to execute the batch scripts that Composer generates,
    // and not the Unix shell version.
    $script = defined('PHP_WINDOWS_VERSION_BUILD') && constant('PHP_WINDOWS_VERSION_BUILD')
        ? '.\\vendor\\bin\\laminas-development-mode.bat'
        : './vendor/bin/laminas-development-mode';
    system(sprintf('%s %s', $script, $argv[2]), $return);
    exit($return);
}

// Decline static file requests back to the PHP built-in webserver
if (php_sapi_name() === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

if (! file_exists('vendor/autoload.php')) {
    throw new RuntimeException(
        'Unable to load application.' . PHP_EOL
        . '- Type `composer install` if you are developing locally.' . PHP_EOL
        . '- Type `vagrant ssh -c \'composer install\'` if you are using Vagrant.' . PHP_EOL
        . '- Type `docker-compose run api-tools composer install` if you are using Docker.'
    );
}

// Setup autoloading
include 'vendor/autoload.php';

$appConfig = include 'config/application.config.php';

if (file_exists('config/development.config.php')) {
    $appConfig = ArrayUtils::merge(
        $appConfig,
        include 'config/development.config.php'
    );
}

// Run the application!
Application::init($appConfig)->run();
