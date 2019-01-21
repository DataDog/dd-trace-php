<?php

namespace DDTrace\Integrations\Symfony\V4;

use DDTrace\Configuration;
use DDTrace\Encoders\Json;
use DDTrace\Integrations\Symfony\SymfonyIntegration as DDSymfonyIntegration;
use DDTrace\Tag;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Bundle\Bundle;

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
    const NAME = 'symfony';

    /**
     * @var string Used by Bundle::getName() to identify this bundle among registered ones.
     */
    protected $name = DDSymfonyIntegration::BUNDLE_NAME;

    public function boot()
    {
        parent::boot();

        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return;
        }

        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Symfony integration.', E_USER_WARNING);
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
        $symfonyRequestSpan = $scope->getSpan();
        $symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $this->getAppName());
        $symfonyRequestSpan->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

        // public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function () use ($symfonyRequestSpan) {
                $args = func_get_args();
                $request = $args[0];
                $scope = GlobalTracer::get()->startActiveSpan('symfony.kernel.handle');
                $symfonyRequestSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $symfonyRequestSpan->setTag(Tag::HTTP_URL, $request->getUriForPath($request->getPathInfo()));

                $thrown = null;
                $response = null;

                try {
                    $response = call_user_func_array([$this, 'handle'], $args);
                } catch (\Exception $e) {
                    $span = $scope->getSpan();
                    $span->setError($e);
                    $thrown = $e;
                }
                $route = $request->get('_route');

                if ($symfonyRequestSpan !== null && $route !== null) {
                    $symfonyRequestSpan->setTag(Tag::RESOURCE_NAME, $route);
                }
                $scope->close();

                if ($thrown) {
                    throw $thrown;
                }

                return $response;
            }
        );

        // public function handleException(\Exception $e, Request $request, int $type): Response
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handleException',
            function (\Exception $e, Request $request, $type) use ($symfonyRequestSpan) {
                $scope = GlobalTracer::get()->startActiveSpan('symfony.kernel.handleException');
                $symfonyRequestSpan->setError($e);

                // PHP 5.4 compliant try-catch-finally block.
                // Note that 'handleException' is a private method.
                $thrown = null;
                $result = null;
                $span = $scope->getSpan();
                try {
                    $result = $this->handleException($e, $request, $type);
                } catch (\Exception $ex) {
                    $thrown = $ex;
                    $span->setError($ex);
                }

                $scope->close();
                if ($thrown) {
                    throw $thrown;
                }

                return $result;
            }
        );

        // public function dispatch($eventName, Event $event = null)
        dd_trace(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            function () {
                $args = func_get_args();
                $scope = GlobalTracer::get()->startActiveSpan('symfony.' . $args[0]);
                return TryCatchFinally::executePublicMethod($scope, $this, 'dispatch', $args);
            }
        );
    }

    private function getAppName()
    {
        if ($appName = getenv('ddtrace_app_name')) {
            return $appName;
        } else {
            return 'symfony';
        }
    }
}
