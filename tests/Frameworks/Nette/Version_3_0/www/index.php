<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Debug stuff:
\DDTrace\hook_method('PDO', '__construct', function () {
    error_log((string)new \Exception("PDO constructor invoked"));
});

App\Bootstrap::boot()
    ->createContainer()
    ->getByType(Nette\Application\Application::class)
    ->run();
