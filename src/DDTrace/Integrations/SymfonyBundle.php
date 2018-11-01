<?php

namespace DDTrace\Integrations;

use DDTrace\Encoders\Json;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use OpenTracing\GlobalTracer;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * DataDog Symfony tracing bundle. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then add the bundle in app/AppKernel.php:
 *
 *         $bundles = [
 *             // ...
 *             new DDTrace\Integrations\SymfonyBundle(),
 *             // ...
 *         ];
 */
class SymfonyBundle extends Bundle
{
    public function boot()
    {
        parent::boot();

        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return;
        }

        if (php_sapi_name() == 'cli') {
            return;
        }

        // Creates a tracer with default transport and default propagators
        $tracer = new Tracer(new Http(new Json()));

        // Sets a global tracer (singleton).
        GlobalTracer::set($tracer);

        // Create a span that starts from when Symfony first boots
        $scope = $tracer->startActiveSpan('symfony.request');
        $symfony_request_span = $scope->getSpan();
        $symfony_request_span->setTag(Tags\SERVICE_NAME, $this->getAppName());
        $symfony_request_span->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);

        // public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
        dd_trace(HttpKernel::class, 'handle', function ($request, ...$args) use ($symfony_request_span) {
            $scope = GlobalTracer::get()->startActiveSpan('symfony.kernel.handle');

            try {
                return $this->handle($request, ...$args);
            } catch (\Exception $e) {
                $span = $scope->getSpan();
                $span->setError($e);
                throw $e;
            } finally {
                $route = $request->get('_route');

                if ($symfony_request_span !== null && $route !== null) {
                    $symfony_request_span->setResource($route);
                }
                $scope->close();
            }
        });

        // public function dispatch($eventName, Event $event = null)
        dd_trace(EventDispatcher::class, 'dispatch', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('symfony.' . $args[0]);

            try {
                return $this->dispatch(...$args);
            } catch (\Exception $e) {
                $span = $scope->getSpan();
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // Enable extension integrations
        PDO::load();
        if (class_exists('Memcached')) {
            Memcached::load();
        }
        if (class_exists('Predis\Client')) {
            Predis::load();
        }

        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
    }

    private function getAppName()
    {
        if (isset($_ENV['ddtrace_app_name'])) {
            return $_ENV['ddtrace_app_name'];
        } else {
            return 'symfony';
        }
    }
}
