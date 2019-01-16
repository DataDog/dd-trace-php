<?php

require __DIR__ . '/../../../../autoload.php';

use DDTrace\Configuration;
use DDTrace\Contracts\Scope;
use DDTrace\GlobalTracer;
use DDTrace\Http\Request;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\StartSpanOptions;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tag;
use DDTrace\Time;
use DDTrace\Tracer;
use DDTrace\Type;

class DDTrace_DDTrace extends Zend_Application_Resource_ResourceAbstract
{
    const NAME = 'zf1';

    /**
     * @var Tracer
     */
    private $tracer;

    public function init()
    {
        if (!$this->shouldLoad()) {
            return false;
        }

        // Init tracer with default options
        $this->tracer = new Tracer();
        GlobalTracer::set($this->tracer);

        $scope = $this->initRootSpan();

        // Enable other integrations
        IntegrationsLoader::load();
        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });

        return $this->tracer;
    }

    /**
     * @return bool
     */
    private function shouldLoad()
    {
        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return false;
        }
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Zend Framework 1 integration.', E_USER_WARNING);
            return false;
        }
        return true;
    }

    /**
     * @return Scope
     */
    private function initRootSpan()
    {
        $options = ['start_time' => Time::now()];
        $startSpanOptions = 'cli' === PHP_SAPI
            ? StartSpanOptions::create($options)
            : StartSpanOptionsFactory::createForWebRequest(
                $this->tracer,
                $options,
                self::getRequestHeaders()
            );
        return $this->startActiveSpan($startSpanOptions);
    }

    /**
     * @param StartSpanOptions $startSpanOptions
     * @return Scope
     */
    private function startActiveSpan(StartSpanOptions $startSpanOptions)
    {
        $scope = $this->tracer->startActiveSpan(
            self::getOperationName(),
            $startSpanOptions
        );
        $scope->getSpan()->setTag(Tag::SERVICE_NAME, $this->getAppName());
        $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
        if ('cli' !== PHP_SAPI) {
            $scope->getSpan()->setTag('http.method', Request::getMethod());
            $scope->getSpan()->setTag('http.url', Request::getUrl());
        }
        return $scope;
    }

    /**
     * @return array
     */
    private static function getRequestHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $key = substr($key, 5);
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
            $headers[$key] = $value;
        }
        return $headers;
    }

    /**
     * @return string
     */
    private static function getOperationName()
    {
        $contextName = 'cli' === PHP_SAPI ? 'command' : 'request';
        return self::NAME . '.' . $contextName;
    }

    /**
     * @return string
     */
    private function getAppName()
    {
        if (getenv('ddtrace_app_name')) {
            return getenv('ddtrace_app_name');
        }
        return self::NAME;
    }
}
