<?php

namespace DDTrace\Integrations\CodeIgniter\V2;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class CodeIgniterSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'codeigniter';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to CodeIgniter requests
     */
    public function init()
    {

        $tracer = GlobalTracer::get();

        $integration = $this;
        $rootScope = $tracer->getRootScope();
        if (!$rootScope) {
            return SandboxedIntegration::NOT_LOADED;
        }
        $service = Configuration::get()->appName(self::NAME);

        \dd_trace_method(
            'CI_Router',
            '_set_routing',
            function () use ($integration, $rootScope, $service) {
                if (!defined('CI_VERSION')) {
                    return false;
                }
                $majorVersion = \substr(\CI_VERSION, 0, 2);
                if ('2.' === $majorVersion) {
                    /* After _set_routing has been called the class and method
                     * are known, so now we can set up tracing on CodeIgniter.
                     */
                    $integration->registerIntegration($this, $rootScope->getSpan(), $service);
                    // at the time of this writing, dd_untrace does not work with methods
                    //\dd_untrace('CI_Router', '_set_routing');
                }
                return false;
            }
        );

        return parent::LOADED;
    }

    public function registerIntegration(\CI_Router $router, Span $root, $service)
    {
        $root->setIntegration($this);
        $root->setTraceAnalyticsCandidate();

        $root->overwriteOperationName('codeigniter.request');
        $root->setTag(Tag::SERVICE_NAME, $service);
        $root->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

        if ('cli' !== PHP_SAPI) {
            $normalizer = new Urls(\explode(',', \getenv('DD_TRACE_RESOURCE_URI_MAPPING')));
            $root->setTag(
                Tag::RESOURCE_NAME,
                "{$_SERVER['REQUEST_METHOD']} {$normalizer->normalize($_SERVER['REQUEST_URI'])}",
                true
            );
        }

        $controller = $router->fetch_class();
        $method = $router->fetch_method();

        \dd_trace_method(
            $controller,
            $method,
            function (SpanData $span) use ($root, $method, $service) {
                $class = \get_class($this);
                $span->name = $span->resource = "{$class}.{$method}";
                $span->service = $service;
                $span->type = Type::WEB_SERVLET;

                $this->load->helper('url');
                $root->setTag(Tag::HTTP_URL, base_url(uri_string()));
                $root->setTag('app.endpoint', "{$class}::{$method}");
            }
        );

        /* From https://codeigniter.com/userguide2/general/controllers.html:
         * If your controller contains a function named _remap(), it will
         * always get called regardless of what your URI contains. It
         * overrides the normal behavior in which the URI determines which
         * function is called, allowing you to define your own function
         * routing rules.
         */
        \dd_trace_method(
            $controller,
            '_remap',
            function (SpanData $span, $args, $retval, $ex) use ($root, $service) {
                $class = \get_class($this);

                $span->name = "{$class}._remap";
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->service = $service;
                $span->type = Type::WEB_SERVLET;

                $this->load->helper('url');
                $root->setTag(Tag::HTTP_URL, base_url(uri_string()));
                $root->setTag('app.endpoint', "{$class}::_remap");
            }
        );

        \dd_trace_method(
            'CI_Loader',
            'view',
            function (SpanData $span, $args, $retval, $ex) use ($service) {
                $span->name = 'CI_Loader.view';
                $span->service = $service;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->type = Type::WEB_SERVLET;
            }
        );

        /* I think tracing the CI_DB_driver's query method should catch usage
         * from all drivers. All drivers extend CI_DB and I *think* that CI_DB
         * extends either CI_DB_driver or CI_DB_active_rec which in turn
         * extends CI_DB_driver. */
        \dd_trace_method(
            'CI_DB_driver',
            'query',
            function (SpanData $span, $args, $retval, $ex) use ($service) {
                if (\dd_trace_tracer_is_limited()) {
                    return false;
                }
                $class = \get_class($this);
                $span->name = "{$class}.query";
                $span->service = $service;
                $span->type = Type::SQL;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
            }
        );

        /* We can't just trace CI_Cache's methods, unfortunately. This
         * pattern is provided in CodeIgniter's documentation:
         *     $this->load->driver('cache')
         *     $this->cache->memcached->save('foo', 'bar', 10);
         * Which avoids get, save, delete, etc, on CI_Cache. But CI_Cache
         * requires a driver, so we can intercept the driver at __get.
         */
        $registered_cache_adapters = array();
        \dd_trace_method(
            'CI_Cache',
            '__get',
            function (SpanData $span, $args, $retval, $ex) use ($service, &$registered_cache_adapters) {
                if (!$ex && is_object($retval)) {
                    $class = \get_class($retval);
                    if (!isset($registered_cache_adapters[$class])) {
                        CodeIgniterSandboxedIntegration::registerCacheAdapter($class, $service);
                        $registered_cache_adapters[$class] = true;
                    }
                }
                return false;
            }
        );
    }

    /**
     * @param string $adapter
     * @param string $service
     */
    public static function registerCacheAdapter($adapter, $service)
    {
        \dd_trace_method(
            $adapter,
            'get',
            function (SpanData $span, $args, $retval, $ex) use ($adapter, $service) {
                $class = \get_class($this);
                $span->name = "{$class}.get";
                $span->service = $service;
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
            }
        );

        \dd_trace_method(
            $adapter,
            'save',
            function (SpanData $span, $args, $retval, $ex) use ($adapter, $service) {
                $class = \get_class($this);
                $span->name = "{$class}.save";
                $span->service = $service;
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
            }
        );

        \dd_trace_method(
            $adapter,
            'delete',
            function (SpanData $span, $args, $retval, $ex) use ($adapter, $service) {
                $class = \get_class($this);
                $span->name = "{$class}.delete";
                $span->service = $service;
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
            }
        );

        \dd_trace_method(
            $adapter,
            'clean',
            function (SpanData $span, $args, $retval, $ex) use ($adapter, $service) {
                $class = \get_class($this);
                $span->name = "{$class}.clean";
                $span->service = $service;
                $span->type = Type::CACHE;
                $span->resource = $span->name;
            }
        );
    }
}
