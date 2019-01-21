<?php

require __DIR__ . '/../../../autoload.php';

use DDTrace\Configuration;
use DDTrace\Contracts\Scope;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\ZendFramework\V1\TraceRequest;
use DDTrace\StartSpanOptions;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tag;
use DDTrace\Time;
use DDTrace\Tracer;
use DDTrace\Type;

class DDTrace_Ddtrace extends Zend_Application_Resource_ResourceAbstract
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
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new TraceRequest());

        $this->tracer = GlobalTracer::get();
        $this->initRootSpan();

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
     * @return void
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
        $this->startRootSpan($startSpanOptions);
    }

    /**
     * @param StartSpanOptions $startSpanOptions
     * @return Scope
     */
    private function startRootSpan(StartSpanOptions $startSpanOptions)
    {
        $scope = $this->tracer->startRootSpan(
            self::getOperationName(),
            $startSpanOptions
        );
        $scope->getSpan()->setTag(Tag::SERVICE_NAME, $this->getAppName());
        $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
        return $scope;
    }

    /**
     * This should be refactored out into a helper of sorts
     *
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
