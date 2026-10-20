<?php

require __DIR__ . '/vendor/autoload.php';

use Spiral\RoadRunner;
use Spiral\RoadRunner\Http\HttpWorker;

$worker = RoadRunner\Worker::create();
$httpWorker = new HttpWorker($worker);

$router = new \App\Router();
$router->addRoute('/', new \App\HomePageHandler());
// default path TelemetryHelpers uses to flush non-request-bound telemetry
$router->addRoute('/hello.php', new \App\HomePageHandler());
$router->addRoute('/json', new \App\JsonHandler());
$router->addRoute('/xml', new \App\XmlHandler());
$router->addRoute('/post-respond-track-user', new \App\PostRespondTrackUserHandler());
$router->addRoute('/post-respond-rasp', new \App\PostRespondRaspHandler());

while ($req = $httpWorker->waitRequest()) {
    try {
        $path = parse_url($req->uri, PHP_URL_PATH);

        // Tracer integration tests use /error to assert the error-span path.
        if ($path === '/error') {
            throw new \Exception('Error page');
        }

        $handler = $router->getHandler($path);
        if ($handler) {
            /** @var \Nyholm\Psr7\Response $resp */
            $psrReq = new \Adapters\Psr17RequestAdapter($req);
            $resp = $handler->handle($psrReq);
            $httpWorker->respond($resp->getStatusCode(), $resp->getBody()->getContents(), $resp->getHeaders());
        } else {
            // Fallback for tracer tests that hit arbitrary paths (/simple, /simple_view, ...).
            $httpWorker->respond(200, 'Hello RoadRunner!', ['Content-type' => ['text/plain; charset=UTF-8']]);
        }
    } catch (\Throwable $e) {
        $httpWorker->respond(
            500,
            "handling threw: " .  $e->getMessage(),
            ['Content-type' => ['text/plain; charset=UTF-8']]
        );
    }
    // Post-respond hook: fires after request_shutdown has been sent inside respond().
    if (isset($GLOBALS['_rr_post_respond'])) {
        ($GLOBALS['_rr_post_respond'])();
        unset($GLOBALS['_rr_post_respond']);
    }
}
