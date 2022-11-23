<?php

// Debug stuff:
\DDTrace\hook_method('PDO', '__construct', function () {
    error_log((string)new \Exception("PDO constructor invoked"));
});

$container = require __DIR__ . '/../app/bootstrap.php';

$container->getByType(Nette\Application\Application::class)
    ->run();
