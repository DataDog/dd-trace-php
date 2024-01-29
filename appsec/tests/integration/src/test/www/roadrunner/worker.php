<?php

require 'vendor/autoload.php';

use Spiral\RoadRunner;
use Spiral\RoadRunner\Http\HttpWorker;
use function DDTrace\{active_span, set_distributed_tracing_context};

$worker = RoadRunner\Worker::create();
$httpWorker = new HttpWorker($worker);
// also an option, but not supported by the tracer
//$psrWorker = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

$router = new \App\Router();
$router->addRoute('/', new \App\HomePageHandler());

while ($req = $httpWorker->waitRequest()) {
    /** @var \Spiral\RoadRunner\Http\Request $req */

    // propagation for distributing tracing is not supported for Roadrunner,
    // so propagate manually x-datadog-trace-id ourselves
    if (isset($req->headers['X-Datadog-Trace-Id'])) {
        $span = active_span();
        set_distributed_tracing_context($req->headers['X-Datadog-Trace-Id'][0], "0");
    }
    try {
        $handler = $router->getHandler(parse_url($req->uri, PHP_URL_PATH));
        if (!$handler) {
            throw new \RuntimeException('No handler found for ' . parse_url($req->uri, PHP_URL_PATH));
        }

        /** @var \Nyholm\Psr7\Response $resp */
        $resp = $handler->handle($req);

        $httpWorker->respond($resp->getStatusCode(), $resp->getBody()->getContents(), $resp->getHeaders());
    } catch (\Throwable $e) {
        $httpWorker->respond(500, "handling threw: " .  $e->getMessage(),
            ['Content-type' => ['text/plain; charset=UTF-8']]);
    }
//    \dd_trace_close_all_spans_and_flush();
}
