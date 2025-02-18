<?php

use Laminas\Mvc\Application;
use Laminas\Stdlib\ArrayUtils;

// Retrieve configuration
$appConfig = require __DIR__ . '/application.config.php';
if (file_exists(__DIR__ . '/development.config.php')) {
    $appConfig = ArrayUtils::merge($appConfig, require __DIR__ . '/development.config.php');
}

return Application::init($appConfig)
    ->getServiceManager();
