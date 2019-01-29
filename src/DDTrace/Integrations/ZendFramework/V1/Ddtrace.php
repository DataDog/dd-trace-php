<?php

require __DIR__ . '/../../../autoload.php';

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\ZendFramework\V1\TraceRequest;
use DDTrace\Tag;
use DDTrace\Tracer;

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

        $tracer = GlobalTracer::get();
        $span = $tracer->getRootScope()->getSpan();
        $span->overwriteOperationName(self::getOperationName());
        $span->setTag(Tag::SERVICE_NAME, $this->getAppName());

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
        return Configuration::get()->appName(self::NAME);
    }
}
