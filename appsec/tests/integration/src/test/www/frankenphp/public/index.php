<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

require __DIR__ . '/../vendor/autoload.php';

use DDTrace\Integrations\Frankenphp\FrankenphpAppSecException;
use function DDTrace\active_span;
use function DDTrace\set_distributed_tracing_context;

$router = new \App\Router();
$router->addRoute('/', new \App\HomePageHandler());
$router->addRoute('/phpinfo', new \App\PhpInfoHandler());
$router->addRoute('/json', new \App\JsonHandler());
$router->addRoute('/xml', new \App\XmlHandler());

$req_handler = function () use ($router) {
    // Called when a request is received,
    // superglobals, php://input and the like are reset

    $uri = $_SERVER['REQUEST_URI'];
    $path = parse_url($uri, PHP_URL_PATH);

    if (isset($_SERVER['HTTP_X_DATADOG_TRACE_ID'])) {
        $span = active_span();
        set_distributed_tracing_context($_SERVER['HTTP_X_DATADOG_TRACE_ID'], "0");
    }

    try {
        $handler = $router->getHandler($path);

        if (!$handler) {
            http_response_code(404);
            echo "Not Found: No handler found for $path";
            exit;
        }

        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );

        $request = $creator->fromGlobals();

        $response = $handler->handle($request);

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        echo $response->getBody()->getContents();
    } catch (\Throwable $e) {
        if (strpos(get_class($e), 'FrankenphpAppSecException') !== false) {
            throw $e;
        }
        http_response_code(500);
        header('Content-type: text/plain; charset=UTF-8');
        echo "handling threw: " . $e->getMessage();
    }
};

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    $keepRunning = \frankenphp_handle_request($req_handler);

    // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
    gc_collect_cycles();

    if (!$keepRunning) {
        break;
    }
}
