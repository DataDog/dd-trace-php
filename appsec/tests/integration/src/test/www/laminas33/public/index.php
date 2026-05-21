<?php

declare(strict_types=1);

use Laminas\Mvc\Application;

chdir(dirname(__DIR__));

if (php_sapi_name() === 'cli-server') {
    $path = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_string($path) && __FILE__ !== $path && is_file($path)) {
        return false;
    }
    unset($path);
}

include __DIR__ . '/../vendor/autoload.php';

if (! class_exists(Application::class)) {
    throw new RuntimeException('Unable to load application. Run composer install.');
}

$container = require __DIR__ . '/../config/container.php';
$container->get('Application')->run();
