<?php

namespace DDTrace\Integrations\CodeIgniter\V2;

use DDTrace\Integrations\CodeIgniter\V2\CodeIgniterIntegration as V2CodeIgniterIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SpanTaxonomy;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

class CodeIgniterIntegration extends Integration
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
    public function init(\CI_Router $router = null)
    {
        $integration = $this;
        $rootSpan = \DDTrace\root_span();
        if (null === $rootSpan) {
            return Integration::NOT_LOADED;
        }

        if (!\defined('CI_VERSION') || !isset($router)) {
            return Integration::NOT_LOADED;
        }
        $majorVersion = \substr(\CI_VERSION, 0, 2);
        if ('2.' === $majorVersion) {
            /* After _set_routing has been called the class and method
             * are known, so now we can set up tracing on CodeIgniter.
             */
            $integration->registerIntegration($router, $rootSpan);
        }

        return parent::LOADED;
    }

    public function registerIntegration(\CI_Router $router, SpanData $rootSpan)
    {
        $this->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->name = 'codeigniter.request';
        SpanTaxonomy::instance()->handleServiceName($rootSpan, V2CodeIgniterIntegration::NAME);
        $rootSpan->type = Type::WEB_SERVLET;
        $rootSpan->meta[Tag::SPAN_KIND] = 'server';
        $rootSpan->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;

        $controller = $router->fetch_class();
        $method = $router->fetch_method();

        \DDTrace\trace_method(
            $controller,
            $method,
            function (SpanData $span) use ($rootSpan, $method) {
                $class = \get_class($this);
                $span->name = $span->resource = "{$class}.{$method}";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;

                // We took the assumption that all controllers will extend CI_Controller.
                // But we've at least seen one healthcheck controller not extending it.
                if ($this->load && \method_exists($this->load, 'helper')) {
                    $this->load->helper('url');

                    if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                        $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize(base_url(uri_string()))
                            . Normalizer::sanitizedQueryString();
                    }
                    $rootSpan->meta['app.endpoint'] = "{$class}::{$method}";
                }
            }
        );

        /* From https://codeigniter.com/userguide2/general/controllers.html:
         * If your controller contains a function named _remap(), it will
         * always get called regardless of what your URI contains. It
         * overrides the normal behavior in which the URI determines which
         * function is called, allowing you to define your own function
         * routing rules.
         */
        \DDTrace\trace_method(
            $controller,
            '_remap',
            function (SpanData $span, $args, $retval, $ex) use ($rootSpan) {
                $class = \get_class($this);

                $span->name = "{$class}._remap";
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;

                // We took the assumption that all controllers will extend CI_Controller.
                // But we've at least seen one healthcheck case where it wasn't the case.
                if ($this->load && \method_exists($this->load, 'helper')) {
                    $this->load->helper('url');

                    $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize(base_url(uri_string()))
                    . Normalizer::sanitizedQueryString();
                    $rootSpan->meta['app.endpoint'] = "{$class}::_remap";
                }
            }
        );

        \DDTrace\trace_method(
            'CI_Loader',
            'view',
            function (SpanData $span, $args, $retval, $ex) {
                $span->name = 'CI_Loader.view';
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
            }
        );

        /* I think tracing the CI_DB_driver's query method should catch usage
         * from all drivers. All drivers extend CI_DB and I *think* that CI_DB
         * extends either CI_DB_driver or CI_DB_active_rec which in turn
         * extends CI_DB_driver. */
        \DDTrace\trace_method(
            'CI_DB_driver',
            'query',
            function (SpanData $span, $args, $retval, $ex) {
                $class = \get_class($this);
                $span->name = "{$class}.query";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::SQL;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
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
        \DDTrace\trace_method(
            'CI_Cache',
            '__get',
            function (SpanData $span, $args, $retval, $ex) use (&$registered_cache_adapters) {
                if (!$ex && \is_object($retval)) {
                    $class = \get_class($retval);
                    if (!isset($registered_cache_adapters[$class])) {
                        CodeIgniterIntegration::registerCacheAdapter($class);
                        $registered_cache_adapters[$class] = true;
                    }
                }
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
                return false;
            }
        );
    }

    /**
     * @param string $adapter
     * @param string $service
     */
    public static function registerCacheAdapter($adapter)
    {
        \DDTrace\trace_method(
            $adapter,
            'get',
            function (SpanData $span, $args, $retval, $ex) {
                $class = \get_class($this);
                $span->name = "{$class}.get";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            $adapter,
            'save',
            function (SpanData $span, $args, $retval, $ex) {
                $class = \get_class($this);
                $span->name = "{$class}.save";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            $adapter,
            'delete',
            function (SpanData $span, $args, $retval, $ex) {
                $class = \get_class($this);
                $span->name = "{$class}.delete";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::CACHE;
                $span->resource = !$ex && isset($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            $adapter,
            'clean',
            function (SpanData $span, $args, $retval, $ex) {
                $class = \get_class($this);
                $span->name = "{$class}.clean";
                SpanTaxonomy::instance()->handleServiceName($span, V2CodeIgniterIntegration::NAME);
                $span->type = Type::CACHE;
                $span->resource = $span->name;
                $span->meta[Tag::COMPONENT] = CodeIgniterIntegration::NAME;
            }
        );
    }
}
