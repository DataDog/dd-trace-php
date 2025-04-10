<?php declare(strict_types=1);

// Composer is near ubiquitous, so we'll set up a composer-like app.
require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../src/functions.php";

function main()
{
    $app = App\HttpApp::new();

    $http_method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    if (false !== $pos = \strpos($uri, '?')) {
        $uri = \substr($uri, 0, $pos);
    }
    $uri = \rawurldecode($uri);

    $app->run($http_method, $uri);
}

main();
